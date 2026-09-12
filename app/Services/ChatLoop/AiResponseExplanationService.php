<?php

namespace App\Services\ChatLoop;

use App\Ai\CapabilityRegistry;
use App\Ai\Context\DossierAccessScope;
use App\Models\AiInteraction;
use App\Models\AiInteractionFeedback;
use App\Models\DerivedKnowledgeNote;
use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\LoopMessage;
use App\Models\User;
use App\Services\Knowledge\ClaimProvenanceReader;

/**
 * TASK-1328 — « Pourquoi cette réponse ? » (Premium-2 / AI Quality V1).
 *
 * Assemble, en LECTURE PURE, l'explication d'une bulle IA du ChatLoop :
 * ce que le pipeline a réellement enregistré au moment de la génération
 * (`AiInteraction.metadata`, jointure `LoopMessage.metadata['ai_interaction_id']`),
 * jamais une reconstruction a posteriori. Si la trace ne permet pas de
 * prouver une donnée, elle n'est pas affichée — le panneau dit le gap.
 *
 * La provenance n'a pas une forme unique dans le ledger : ce lecteur
 * dispatche par capability (`metadata['capability']`) sur les formes
 * CONNUES du ChatLoop, et rend un gap explicite pour toute forme
 * inconnue — jamais une lecture structurelle générique qui « croirait »
 * comprendre une trace qu'elle ne connaît pas.
 *
 * L'autorisation d'affichage passe par l'objet vivant (le LoopMessage que
 * le membre voit déjà), jamais par `AiInteraction.organization_id` — un
 * ledger sans FK qui survit à la suppression du tenant ne prouve aucun
 * droit courant. Les identifiants du ledger ne servent ici qu'à des
 * contrôles de COHÉRENCE : une trace incohérente est traitée comme
 * absente, pas réinterprétée.
 *
 * Les sources documentaires acceptées à la génération sont REVALIDÉES à
 * l'affichage, à la maille du Dossier gouvernant — la même autorité
 * (`DossierAccessScope`, donc `DossierPolicy::view`) qui gouverne le
 * retrieval loop-scoped. Une source devenue inaccessible est masquée et
 * comptée en agrégat, sans titre ni identifiant.
 */
final class AiResponseExplanationService
{
    /**
     * Les seules capabilities dont ce lecteur connaît la forme de trace.
     * Le ledger d'une autre capability est déclaré indisponible (gap),
     * jamais deviné.
     */
    private const LLM_CAPABILITIES = [
        CapabilityRegistry::LOOP_ASK,
        CapabilityRegistry::LOOP_ANSWER,
    ];

    private const RAG_CAPABILITIES = [
        CapabilityRegistry::LOOP_KNOWLEDGE_ANSWER,
        CapabilityRegistry::LOOP_HYBRID_ANSWER,
    ];

    public function __construct(
        private readonly DossierAccessScope $scope,
        private readonly ClaimProvenanceReader $claims,
    ) {}

    /**
     * L'explication bornée d'une bulle IA, ou `null` si ce spectateur n'a
     * pas à la voir. Toutes les gardes vivent ici : l'appelant Livewire
     * transmet, il ne décide pas — c'est aussi ce qu'atteint une requête
     * forgée.
     *
     * @return array<string, mixed>|null
     */
    public function explain(Loop $loop, LoopMessage $message, User $viewer): ?array
    {
        if (! $this->canView($loop, $message, $viewer)) {
            return null;
        }

        $metadata = $message->metadata ?? [];
        $interaction = $this->trustedInteraction($loop, $message);

        return [
            'message_id' => (string) $message->id,
            'organization_name' => (string) ($loop->organization?->name ?? ''),
            'loop_name' => (string) $loop->name,
            'ai_mode' => is_string($metadata['ai_mode'] ?? null) ? $metadata['ai_mode'] : null,
            'question' => is_string($metadata['question'] ?? null) ? $metadata['question'] : null,
            'requested_by_name' => $this->requesterName($loop, $metadata),
            'generated_at' => $message->created_at?->diffForHumans(),
            'ledger' => $interaction === null ? null : $this->ledgerPanel($loop, $message, $interaction, $viewer),
            'my_verdict' => $interaction === null ? null : AiInteractionFeedback::query()
                ->where('ai_interaction_id', $interaction->id)
                ->where('user_id', $viewer->id)
                ->value('verdict'),
            'can_feedback' => $interaction !== null,
        ];
    }

    /**
     * Un verdict humain EXPLICITE (clic) sur la réponse — la seule écriture
     * de toute la feature, sur la primitive TASK-1256, un jugement par
     * personne et par interaction. L'ouverture du panneau n'écrit jamais.
     */
    public function submitFeedback(Loop $loop, LoopMessage $message, User $viewer, string $verdict): bool
    {
        if (! in_array($verdict, AiInteractionFeedback::VERDICTS, true)) {
            return false;
        }

        if (! $this->canView($loop, $message, $viewer)) {
            return false;
        }

        $interaction = $this->trustedInteraction($loop, $message);

        if ($interaction === null) {
            return false;
        }

        AiInteractionFeedback::query()->updateOrCreate(
            [
                'ai_interaction_id' => $interaction->id,
                'user_id' => $viewer->id,
            ],
            [
                'organization_id' => $interaction->organization_id,
                'verdict' => $verdict,
            ],
        );

        return true;
    }

    /**
     * Qui peut ouvrir le panneau : un membre ACTIF de la Boucle, dans la
     * même Organization, sur une bulle IA vivante de CETTE Boucle. Être sur
     * la page n'accorde rien — tout est revérifié ici, à chaque appel.
     */
    private function canView(Loop $loop, LoopMessage $message, User $viewer): bool
    {
        return $viewer->organization_id === $loop->organization_id
            && ! $viewer->isDeactivated()
            && LoopMember::query()
                ->where('loop_id', $loop->id)
                ->where('user_id', $viewer->id)
                ->where('status', 'active')
                ->exists()
            && $message->type === 'ai'
            && $message->loop_id === $loop->id
            && $message->organization_id === $loop->organization_id
            && ! $message->isDeleted();
    }

    /**
     * La ligne `AiInteraction` de cette bulle, si la trace est COHÉRENTE :
     * la jointure canonique (`metadata['ai_interaction_id']`, écrite par le
     * pipeline à la création du message), puis les ancres que le pipeline a
     * lui-même posées (`organization_id`, `metadata['loop_id']`). Un
     * identifiant absent, introuvable ou incohérent rend `null` : une trace
     * dont on ne peut pas prouver qu'elle appartient à cette bulle n'est
     * jamais affichée.
     */
    private function trustedInteraction(Loop $loop, LoopMessage $message): ?AiInteraction
    {
        $interactionId = $message->metadata['ai_interaction_id'] ?? null;

        if (! is_string($interactionId) || $interactionId === '') {
            return null;
        }

        $interaction = AiInteraction::query()->find($interactionId);

        if ($interaction === null) {
            return null;
        }

        $traceLoopId = $interaction->metadata['loop_id'] ?? null;

        if ((string) $interaction->organization_id !== (string) $loop->organization_id
            || (string) $traceLoopId !== (string) $loop->id) {
            return null;
        }

        return $interaction;
    }

    /**
     * La partie du panneau qui vient du ledger, dispatchée par capability.
     * Capability absente ou inconnue de ce lecteur => `null` : le panneau
     * affichera « trace non exploitable » plutôt qu'une interprétation.
     *
     * @return array<string, mixed>|null
     */
    private function ledgerPanel(Loop $loop, LoopMessage $message, AiInteraction $interaction, User $viewer): ?array
    {
        $imeta = $interaction->metadata ?? [];
        $capability = $imeta['capability'] ?? null;

        if (in_array($capability, self::LLM_CAPABILITIES, true)) {
            return $this->llmPanel($loop, $capability, $imeta);
        }

        if (in_array($capability, self::RAG_CAPABILITIES, true)) {
            return $this->ragPanel($loop, $capability, $imeta, $message, $viewer);
        }

        return null;
    }

    /**
     * Chemins LLM purs (`loop_ask` / `loop_answer`) : le contexte est le fil
     * de la Boucle, sous DEUX formes canoniques selon le site d'écriture —
     * `provenance['conversation.thread']` (réponse dans un fil,
     * `respondInThread`) ou `provenance['loop.messages']` (Context Builder,
     * `generateDirectAnswer`). Aucune source documentaire n'existe sur ces
     * chemins : la section documentaire dit « aucune », jamais une liste
     * vide ambiguë — l'AC « LLM sans RAG => pas de fausse source RAG ».
     *
     * @param  array<string, mixed>  $imeta
     * @return array<string, mixed>
     */
    private function llmPanel(Loop $loop, string $capability, array $imeta): array
    {
        $provenance = is_array($imeta['provenance'] ?? null) ? $imeta['provenance'] : [];

        $messageIds = $provenance['conversation.thread']
            ?? $provenance[CapabilityRegistry::SOURCE_LOOP_MESSAGES]
            ?? null;

        return [
            'capability' => (string) $capability,
            'capability_label' => $this->capabilityLabel($capability),
            'doctrine_version' => is_int($imeta['doctrine_version'] ?? null) ? $imeta['doctrine_version'] : null,
            'conversation' => is_array($messageIds) ? $this->conversationPanel($loop, $messageIds) : null,
            'documents' => ['applies' => false],
            // TASK-1549 : un chemin LLM pur ne cite aucun chunk — la section
            // mémoire ne s'applique pas, et ne simule jamais une absence.
            'memory' => null,
            'unreachable_count' => 0,
            'denied_count' => is_array($imeta['sources_denied'] ?? null) ? count($imeta['sources_denied']) : 0,
        ];
    }

    /**
     * Chemins documentaires (`loop_knowledge_answer` / `loop_hybrid_answer`).
     * La vérité de génération est `metadata['retrieval']` (cited/consulted,
     * identifiants de Dossiers) ; les titres viennent de la forme publique
     * `LoopMessage.metadata['sources']` — écrite dans la MÊME transaction
     * depuis le MÊME tableau que `retrieval.cited`, position par position
     * (`$sources = $cited`, LoopKnowledgeAnswerService). L'appariement
     * positionnel n'est donc pas une reconstruction — et si les longueurs
     * divergent (trace inattendue), aucun titre n'est apparié : comptes
     * seuls, gap dit.
     *
     * @param  array<string, mixed>  $imeta
     * @return array<string, mixed>
     */
    private function ragPanel(Loop $loop, string $capability, array $imeta, LoopMessage $message, User $viewer): array
    {
        $contextIds = $message->metadata['context_message_ids'] ?? null;
        $retrieval = is_array($imeta['retrieval'] ?? null) ? $imeta['retrieval'] : null;

        return [
            'capability' => (string) $capability,
            'capability_label' => $this->capabilityLabel($capability),
            'doctrine_version' => is_int($imeta['doctrine_version'] ?? null) ? $imeta['doctrine_version'] : null,
            'conversation' => is_array($contextIds) ? $this->conversationPanel($loop, $contextIds) : null,
            'documents' => $retrieval === null ? null : $this->documentsPanel($loop, $retrieval, $message, $viewer),
            'memory' => $retrieval === null ? null : $this->memoryPanel($loop, $retrieval, $message, $viewer),
            // TASK-1549 (remédiation) — le TROISIÈME état, au niveau du ledger
            // et non de la section mémoire : une source citée dont la ligne a
            // disparu n'a plus d'origine prouvable, donc elle se dit SANS
            // nommer de famille. La placer dans « Mémoire de BouclePro »
            // revenait à affirmer une mémoire qu'on ne peut plus établir.
            'unreachable_count' => $retrieval === null ? 0 : $this->unreachableCitedCount($loop, $retrieval),
            'denied_count' => is_array($imeta['sources_denied'] ?? null) ? count($imeta['sources_denied']) : 0,
        ];
    }

    /**
     * Les sources citées dont la ligne `dossier_chunks` n'existe plus —
     * réindexation d'un Article, fichier supprimé, supersession d'une note
     * dérivée. Comptées TOUTES FAMILLES CONFONDUES, et c'est délibéré : la FK
     * qui aurait permis de discriminer est partie avec la ligne.
     *
     * C'est la dette W5/TRACE-0 rendue honnêtement : tant que la trace ne
     * portera pas l'identité de la note, ce compte ne peut pas dire s'il
     * s'agissait d'un document ou d'une mémoire — alors il ne le dit pas.
     *
     * @param  array<string, mixed>  $retrieval
     */
    private function unreachableCitedCount(Loop $loop, array $retrieval): int
    {
        $organization = $loop->organization;

        if ($organization === null) {
            return 0;
        }

        $cited = is_array($retrieval['cited'] ?? null) ? array_values($retrieval['cited']) : [];
        $count = 0;

        foreach ($cited as $entry) {
            $chunkId = is_array($entry) ? ($entry['chunk_id'] ?? null) : null;

            if (! is_string($chunkId) || $chunkId === '') {
                continue;
            }

            if (! $this->claims->citedChunkStillExists((string) $organization->id, $chunkId)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * TASK-1549 — la section « Mémoire de BouclePro » du panneau : parmi les
     * sources citées, celles qui sont une MÉMOIRE DURABLE — reconnues côté
     * lecture par la FK `derived_knowledge_note_id` de leur chunk, jamais par
     * la forme publique (`type = 'retrieval'` ne distingue rien, dette CDC).
     *
     * ## Le discriminateur est POSITIF (remédiation TASK-1549)
     *
     * Une citation n'entre ici QUE si `noteFromChunk()` la reconnaît : chunk
     * vivant, portant la FK, pointant un claim. Tout le reste — document
     * ordinaire, digest non adressable, et surtout **chunk disparu** — sort en
     * silence de cette section.
     *
     * Le cas du chunk disparu était le défaut : sa ligne emporte sa FK, donc
     * son origine n'est plus établissable, et le compter ici faisait apparaître
     * « Mémoire de BouclePro » sur une réponse n'ayant cité que des documents.
     * Il est désormais compté au niveau du ledger
     * ({@see self::unreachableCitedCount()}), sans nommer de famille.
     *
     * Deux issues seulement subsistent donc ici :
     *  - mémoire résolue : provenance complète du lecteur standard ;
     *  - mémoire résolue mais ACL de la Boucle source tombée : refus GÉNÉRIQUE,
     *    compté, sans auteur ni titre ni contenu.
     *
     * `null` quand aucune mémoire n'est prouvée : la section n'apparaît pas, et
     * ne prétend jamais montrer « tout ce que BouclePro sait ».
     *
     * @param  array<string, mixed>  $retrieval
     * @return array{entries: list<array<string, mixed>>, denied_count: int}|null
     */
    private function memoryPanel(Loop $loop, array $retrieval, LoopMessage $message, User $viewer): ?array
    {
        $organization = $loop->organization;

        if ($organization === null) {
            return null;
        }

        $cited = is_array($retrieval['cited'] ?? null) ? array_values($retrieval['cited']) : [];
        $publicSources = $message->metadata['sources'] ?? null;
        $publicSources = is_array($publicSources) ? array_values($publicSources) : [];
        $pairable = $cited !== [] && count($publicSources) === count($cited);

        $entries = [];
        $deniedCount = 0;

        foreach ($cited as $index => $entry) {
            $chunkId = is_array($entry) ? ($entry['chunk_id'] ?? null) : null;

            if (! is_string($chunkId) || $chunkId === '') {
                continue;
            }

            // LA porte d'entrée, et la seule. Un `null` ici veut dire « rien ne
            // prouve que cette source soit une mémoire durable adressable » —
            // qu'elle soit un document, un digest, ou une ligne disparue. Dans
            // les trois cas la section se tait.
            $note = $this->claims->noteFromChunk((string) $organization->id, $chunkId);

            if ($note === null) {
                continue;
            }

            $provenance = $this->claims->provenance($organization, $loop, $note, $viewer);

            if ($provenance['state'] === 'denied') {
                $deniedCount++;

                continue;
            }

            $public = $pairable ? ($publicSources[$index] ?? null) : null;
            $ref = is_array($public) && is_string($public['ref'] ?? null) && $public['ref'] !== ''
                ? $public['ref']
                : null;

            $entries[] = ['ref' => $ref, ...$provenance];
        }

        if ($entries === [] && $deniedCount === 0) {
            return null;
        }

        return [
            'entries' => $entries,
            'denied_count' => $deniedCount,
        ];
    }

    /**
     * TASK-1549 — résolution serveur d'un geste `Corriger` : la référence
     * AFFICHÉE (`S1`…) redevient la note dérivée citée, toutes gardes
     * refaites MAINTENANT — spectateur légitime, trace cohérente, appariement
     * prouvable, chunk encore vivant, ACL de la Boucle source. `null` sinon :
     * le composant traduit, il ne décide pas. `subject_key` reste dans la
     * note, côté serveur — il n'entre jamais dans le snapshot Livewire.
     */
    public function citedMemoryNote(Loop $loop, LoopMessage $message, User $viewer, string $ref): ?DerivedKnowledgeNote
    {
        if ($ref === '' || ! $this->canView($loop, $message, $viewer)) {
            return null;
        }

        $organization = $loop->organization;
        $interaction = $this->trustedInteraction($loop, $message);

        if ($organization === null || $interaction === null) {
            return null;
        }

        $retrieval = $interaction->metadata['retrieval'] ?? null;
        $cited = is_array($retrieval) && is_array($retrieval['cited'] ?? null) ? array_values($retrieval['cited']) : [];
        $publicSources = $message->metadata['sources'] ?? null;
        $publicSources = is_array($publicSources) ? array_values($publicSources) : [];

        if ($cited === [] || count($publicSources) !== count($cited)) {
            return null;
        }

        foreach ($cited as $index => $entry) {
            $public = $publicSources[$index] ?? null;

            if (! is_array($public) || ($public['ref'] ?? null) !== $ref) {
                continue;
            }

            $chunkId = is_array($entry) ? ($entry['chunk_id'] ?? null) : null;

            if (! is_string($chunkId) || $chunkId === '') {
                return null;
            }

            $note = $this->claims->noteFromChunk((string) $organization->id, $chunkId);

            if ($note === null) {
                return null;
            }

            $provenance = $this->claims->provenance($organization, $loop, $note, $viewer);

            return $provenance['state'] === 'denied' ? null : $note;
        }

        return null;
    }

    /**
     * Les messages du fil que le pipeline a réellement lus, REVALIDÉS à
     * l'affichage : seuls comptent comme visibles ceux qui existent encore,
     * dans CETTE Boucle, non supprimés. Le reste est un agrégat — jamais un
     * identifiant, jamais un extrait.
     *
     * @param  list<mixed>  $messageIds
     * @return array{used_count: int, hidden_count: int}
     */
    private function conversationPanel(Loop $loop, array $messageIds): array
    {
        $ids = array_values(array_filter(array_map(
            static fn ($id): string => is_scalar($id) ? (string) $id : '',
            $messageIds,
        ), static fn (string $id): bool => $id !== ''));

        $visible = $ids === [] ? 0 : LoopMessage::query()
            ->whereIn('id', $ids)
            ->where('loop_id', $loop->id)
            ->whereNull('deleted_at')
            ->count();

        return [
            'used_count' => count($ids),
            'hidden_count' => max(0, count($ids) - $visible),
        ];
    }

    /**
     * Sources documentaires : comptes depuis le ledger (la vérité de
     * génération), entrées nommées seulement si (a) l'appariement
     * positionnel public<->ledger est prouvable (mêmes longueurs) et (b) le
     * Dossier gouvernant est ENCORE accessible à CE spectateur — la même
     * autorité que le retrieval (`DossierAccessScope` => DossierPolicy).
     * Tout le reste est masqué et compté, sans titre ni identifiant.
     *
     * @param  array<string, mixed>  $retrieval
     * @return array<string, mixed>
     */
    private function documentsPanel(Loop $loop, array $retrieval, LoopMessage $message, User $viewer): array
    {
        $cited = is_array($retrieval['cited'] ?? null) ? array_values($retrieval['cited']) : [];
        $consulted = is_array($retrieval['consulted'] ?? null) ? $retrieval['consulted'] : [];
        $publicSources = $message->metadata['sources'] ?? null;
        $publicSources = is_array($publicSources) ? array_values($publicSources) : [];

        $pairable = $cited !== [] && count($publicSources) === count($cited);

        $accessibleDossierIds = $cited === [] ? [] : $this->scope->accessibleDossierIds(
            (string) $loop->organization_id,
            $viewer,
            (string) $loop->id,
        );

        $entries = [];
        $maskedCount = 0;

        foreach ($cited as $index => $entry) {
            $dossierId = is_array($entry) ? ($entry['dossier_id'] ?? null) : null;
            $public = $pairable ? ($publicSources[$index] ?? null) : null;

            if (! is_string($dossierId)
                || ! in_array($dossierId, $accessibleDossierIds, true)
                || ! is_array($public)) {
                $maskedCount++;

                continue;
            }

            $entries[] = [
                'ref' => is_string($public['ref'] ?? null) ? $public['ref'] : null,
                'title' => is_string($public['title'] ?? null) ? $public['title'] : null,
                'dossier_name' => is_string($public['dossier_name'] ?? null) ? $public['dossier_name'] : null,
            ];
        }

        return [
            'applies' => true,
            'cited_count' => count($cited),
            'consulted_count' => count($consulted),
            'entries' => $entries,
            'masked_count' => $maskedCount,
        ];
    }

    /**
     * Le libellé produit de la capability — celui, traduit, que TASK-1227
     * impose à toute capability canonique. À défaut (trace d'une capability
     * disparue), l'identifiant technique : borné et honnête, jamais vide.
     */
    private function capabilityLabel(string $capability): string
    {
        $key = 'ai.capability_label.'.$capability;
        $label = __($key);

        return $label === $key ? $capability : $label;
    }

    /**
     * Le nom public du demandeur, seulement s'il appartient encore à
     * l'Organization de la Boucle — même lecture que la bulle (T1316),
     * bornée au tenant.
     *
     * @param  array<string, mixed>  $metadata
     */
    private function requesterName(Loop $loop, array $metadata): ?string
    {
        $requesterId = $metadata['requested_by'] ?? null;

        if (! is_scalar($requesterId)) {
            return null;
        }

        $requester = User::query()
            ->whereKey($requesterId)
            ->where('organization_id', $loop->organization_id)
            ->first();

        return $requester?->publicDisplayName();
    }
}
