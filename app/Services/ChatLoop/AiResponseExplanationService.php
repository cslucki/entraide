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

    /**
     * Les trois familles EXCLUSIVES d'une source citée (REMÉDIATION R2, F4).
     * Une citation en occupe exactement une : le panneau ne peut plus affirmer
     * deux origines pour la même référence.
     */
    private const FAMILLE_MEMOIRE = 'memoire';

    /**
     * Une memoire durable que CE spectateur n'a pas le droit de lire. Famille
     * a part entiere : elle se compte, elle ne se nomme pas, et elle ne glisse
     * JAMAIS dans la voie documentaire — qui, elle, nomme ses entrees.
     */
    private const FAMILLE_MEMOIRE_REFUSEE = 'memoire_refusee';

    private const FAMILLE_DOCUMENT = 'document';

    private const FAMILLE_INJOIGNABLE = 'injoignable';

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

        // REMÉDIATION R2 (audit Codex F4) — les sources citées sont classées
        // UNE FOIS, ici, et les trois sections consomment ce classement.
        //
        // Chacune lisait auparavant la même liste `cited` pour son compte, avec
        // son propre critère : le panneau pouvait donc afficher la même
        // référence comme document ordinaire NOMMÉ et comme mémoire durable,
        // ou la nommer document accessible tout en la comptant injoignable.
        // Trois lectures indépendantes de la même liste ne peuvent pas se
        // contredire si elles n'en font qu'une.
        $classees = $retrieval === null ? [] : $this->classerCitations($loop, $retrieval, $viewer);

        return [
            'capability' => (string) $capability,
            'capability_label' => $this->capabilityLabel($capability),
            'doctrine_version' => is_int($imeta['doctrine_version'] ?? null) ? $imeta['doctrine_version'] : null,
            'conversation' => is_array($contextIds) ? $this->conversationPanel($loop, $contextIds) : null,
            'documents' => $retrieval === null ? null : $this->documentsPanel($loop, $retrieval, $classees, $message, $viewer),
            'memory' => $retrieval === null ? null : $this->memoryPanel($loop, $classees, $message, $viewer),
            // TASK-1549 (remédiation) — le TROISIÈME état, au niveau du ledger
            // et non de la section mémoire : une source citée dont la ligne a
            // disparu n'a plus d'origine prouvable, donc elle se dit SANS
            // nommer de famille. La placer dans « Mémoire de BouclePro »
            // revenait à affirmer une mémoire qu'on ne peut plus établir.
            'unreachable_count' => count(array_filter(
                $classees,
                static fn (array $c): bool => $c['famille'] === self::FAMILLE_INJOIGNABLE,
            )),
            'denied_count' => is_array($imeta['sources_denied'] ?? null) ? count($imeta['sources_denied']) : 0,
        ];
    }

    /**
     * REMÉDIATION R2 (F4) — à quelle famille appartient chaque source citée,
     * décidé une seule fois et pour tout le panneau.
     *
     * Le discriminateur reste celui de TASK-1549, et il reste POSITIF : la
     * référence de rattachement du chunk vers un claim, jamais la forme
     * publique (`type = 'retrieval'` ne distingue rien), jamais une heuristique
     * de contenu. Ce qui change est la PORTÉE : trois familles exclusives.
     *
     * REMÉDIATION AUTORITÉ — ce service ne lit plus ce rattachement : il
     * demande son verdict à `DerivedChunkEligibility`, qui décide sous sa
     * propre clause d'ACL. Une mémoire refusée à CE spectateur ne devient donc
     * pas un document : elle reste de la mémoire, comptée sans rien divulguer.
     *
     *  - `memoire`     : chunk vivant, rattaché à un claim AUTORISÉ ;
     *  - `memoire_refusee` : chunk vivant, rattaché à un claim que ce
     *                    spectateur n'a pas le droit de lire — verdict seul ;
     *  - `document`    : chunk vivant sans rattachement — ou citation sans `chunk_id`
     *                    exploitable, que la trace seule doit pouvoir compter ;
     *  - `injoignable` : la ligne `dossier_chunks` n'existe plus. Son
     *                    rattachement est parti avec elle : l'origine n'est
     *                    plus établissable, donc la source ne se range dans
     *                    aucune des autres. Elle se dit au ledger, sans nommer
     *                    de famille (dette W5/TRACE-0 rendue telle quelle).
     *
     * La note résolue est transportée avec le classement : la section mémoire
     * n'a pas à refaire la requête qui l'a produite.
     *
     * @param  array<string, mixed>  $retrieval
     * @return list<array{index: int, famille: string, note: ?DerivedKnowledgeNote}>
     */
    private function classerCitations(Loop $loop, array $retrieval, User $viewer): array
    {
        $organization = $loop->organization;
        $cited = is_array($retrieval['cited'] ?? null) ? array_values($retrieval['cited']) : [];

        $classees = [];

        foreach ($cited as $index => $entry) {
            $chunkId = is_array($entry) ? ($entry['chunk_id'] ?? null) : null;

            if ($organization === null || ! is_string($chunkId) || $chunkId === '') {
                // Sans Organization ou sans identifiant de chunk, rien ne peut
                // être prouvé : la citation reste dans la voie documentaire,
                // où l'accès au Dossier gouvernant décidera seul — c'est le
                // comportement d'avant TASK-1549, inchangé.
                $classees[] = ['index' => $index, 'famille' => self::FAMILLE_DOCUMENT, 'note' => null];

                continue;
            }

            if (! $this->claims->citedChunkStillExists((string) $organization->id, $chunkId)) {
                $classees[] = ['index' => $index, 'famille' => self::FAMILLE_INJOIGNABLE, 'note' => null];

                continue;
            }

            // LE verdict, rendu par l'autorité. `denied` reste de la MÉMOIRE :
            // la faire glisser dans la voie documentaire la ferait nommer sous
            // son titre par une autre section, ce que le refus d'ACL interdit.
            $verdict = $this->claims->citedClaimForViewer((string) $organization->id, $viewer, $chunkId);

            $famille = match ($verdict['state']) {
                'granted' => self::FAMILLE_MEMOIRE,
                'denied' => self::FAMILLE_MEMOIRE_REFUSEE,
                default => self::FAMILLE_DOCUMENT,
            };

            $classees[] = ['index' => $index, 'famille' => $famille, 'note' => $verdict['note']];
        }

        return $classees;
    }

    /**
     * TASK-1549 — la section « Mémoire de BouclePro » du panneau : parmi les
     * sources citées, celles qui sont une MÉMOIRE DURABLE — reconnues côté
     * lecture par la référence de rattachement de leur chunk, jamais par la
     * forme publique (`type = 'retrieval'` ne distingue rien, dette CDC).
     *
     * ## Le discriminateur est POSITIF (remédiation TASK-1549)
     *
     * Une citation n'entre ici QUE si l'autorité d'éligibilité la reconnaît :
     * chunk vivant, rattaché à un claim. Tout le reste — document ordinaire,
     * digest non adressable, et surtout **chunk disparu** — sort en silence de
     * cette section.
     *
     * Le cas du chunk disparu était le défaut : sa ligne emporte son
     * rattachement, donc son origine n'est plus établissable, et le compter ici
     * faisait apparaître « Mémoire de BouclePro » sur une réponse n'ayant cité
     * que des documents. Il est désormais classé `injoignable` et compté au
     * niveau du ledger, sans nommer de famille
     * ({@see self::classerCitations()}).
     *
     * Deux issues seulement subsistent donc ici :
     *  - mémoire AUTORISÉE : provenance complète du lecteur standard ;
     *  - mémoire refusée à ce spectateur : refus GÉNÉRIQUE, compté, sans
     *    auteur ni titre ni contenu — la note n'est jamais même chargée.
     *
     * `null` quand aucune mémoire n'est prouvée : la section n'apparaît pas, et
     * ne prétend jamais montrer « tout ce que BouclePro sait ».
     *
     * REMÉDIATION R2 (F4) : la section ne relit plus `cited` — elle consomme le
     * classement, note déjà résolue comprise. Ce qui entre ici ne peut donc pas
     * figurer ailleurs.
     *
     * @param  list<array{index: int, famille: string, note: ?DerivedKnowledgeNote}>  $classees
     * @return array{entries: list<array<string, mixed>>, denied_count: int}|null
     */
    private function memoryPanel(Loop $loop, array $classees, LoopMessage $message, User $viewer): ?array
    {
        $organization = $loop->organization;

        if ($organization === null) {
            return null;
        }

        $publicSources = $message->metadata['sources'] ?? null;
        $publicSources = is_array($publicSources) ? array_values($publicSources) : [];
        $pairable = $classees !== [] && count($publicSources) === count($classees);

        $entries = [];
        $deniedCount = 0;

        foreach ($classees as $classee) {
            // Le refus vient de l'AUTORITÉ, en amont : aucune note n'a été
            // chargée, donc il n'y a rien à filtrer ici — seulement à compter.
            if ($classee['famille'] === self::FAMILLE_MEMOIRE_REFUSEE) {
                $deniedCount++;

                continue;
            }

            if ($classee['famille'] !== self::FAMILLE_MEMOIRE || $classee['note'] === null) {
                continue;
            }

            $index = $classee['index'];
            $provenance = $this->claims->provenance($organization, $loop, $classee['note'], $viewer);

            // Défense en profondeur : le lecteur standard repose la même
            // question à la même autorité. Un désaccord ne s'affiche pas.
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

            // L'autorité décide. `null` couvre désormais AUSSI le refus d'ACL :
            // aucune note n'est rendue à qui n'a pas le droit de la lire, et
            // cela ne dépend plus d'un second appel que l'on pourrait oublier.
            $note = $this->claims->noteFromChunk((string) $organization->id, $viewer, $chunkId);

            if ($note === null) {
                return null;
            }

            // Défense en profondeur : le lecteur standard repose la question.
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
     * REMÉDIATION R2 (audit Codex F4) — cette section ne parle QUE des
     * citations classées `document`.
     *
     * Elle comptait et nommait auparavant toute source citée dont le Dossier
     * gouvernant restait accessible. Or le chunk d'une note dérivée vit dans le
     * Dossier racine de la Boucle, accessible à tout membre : une mémoire
     * durable s'affichait donc ICI, sous son titre, EN MÊME TEMPS que la
     * section « Mémoire de BouclePro » l'annonçait comme mémoire. Et une source
     * dont la ligne avait disparu restait nommée comme document accessible
     * alors que le ledger la comptait injoignable.
     *
     * `cited_count` dérive désormais des citations RETENUES, pas de la
     * longueur brute de `cited` : le compte et la liste parlent de la même
     * chose.
     *
     * @param  array<string, mixed>  $retrieval
     * @param  list<array{index: int, famille: string, note: ?DerivedKnowledgeNote}>  $classees
     * @return array<string, mixed>
     */
    private function documentsPanel(Loop $loop, array $retrieval, array $classees, LoopMessage $message, User $viewer): array
    {
        $cited = is_array($retrieval['cited'] ?? null) ? array_values($retrieval['cited']) : [];
        $consulted = is_array($retrieval['consulted'] ?? null) ? $retrieval['consulted'] : [];
        $publicSources = $message->metadata['sources'] ?? null;
        $publicSources = is_array($publicSources) ? array_values($publicSources) : [];

        // L'appariement positionnel se prouve sur la trace ENTIÈRE — c'est la
        // longueur brute qui l'établit, pas le sous-ensemble documentaire.
        $pairable = $cited !== [] && count($publicSources) === count($cited);

        $documentaires = array_values(array_filter(
            $classees,
            static fn (array $c): bool => $c['famille'] === self::FAMILLE_DOCUMENT,
        ));

        $accessibleDossierIds = $documentaires === [] ? [] : $this->scope->accessibleDossierIds(
            (string) $loop->organization_id,
            $viewer,
            (string) $loop->id,
        );

        $entries = [];
        $maskedCount = 0;

        foreach ($documentaires as $classee) {
            $index = $classee['index'];
            $entry = $cited[$index] ?? null;
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
            'cited_count' => count($documentaires),
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
