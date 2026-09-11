<?php

namespace App\Services\Knowledge;

use App\Ai\Agents\LoopConversationKnowledgeAgent;
use App\Ai\CapabilityRegistry;
use App\Ai\ContexteIa;
use App\Ai\PromptRepository;
use App\Ai\ProviderResolver;
use App\Models\AdminAiPrompt;
use App\Models\DerivedKnowledgeNote;
use App\Models\Loop;
use App\Models\LoopMessage;
use App\Models\Organization;
use App\Services\Ai\AiProviderInvocationLedger;
use App\Services\Dossiers\DerivedChunkEligibility;
use App\Support\Ai\AiCorrelation;
use App\Support\Ai\AiEconomicGuard;
use App\Support\Ai\AiMarkdownSanitizer;
use App\Support\Ai\AiUsage;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * TASK-1534 — le cote WRITE du systeme nerveux : compiler ce que des HUMAINS
 * se sont dit en connaissance durable et retrouvable.
 *
 * ## Le manque que ce service comble, et comment il a ete mesure
 *
 * `LoopAnswerCapitalizationService` (T1310) sait deja rendre durable une
 * reponse IA : un humain clique, elle devient un Article indexe. Sa table
 * d'eligibilite dit ou il s'arrete :
 *
 *     CAPITALIZABLE_AI_MODES = ['llm', 'rag', 'llm_rag']
 *     « Une bulle sans ai_mode connu — message humain, agent de membre,
 *       evenement — n'est jamais eligible. »
 *
 * BouclePro savait donc rendre durable ce que l'IA avait dit, et rien de ce
 * que les humains s'etaient dit. Or c'est exactement la phrase que le produit
 * vise : « le projet dont Roger PARLAIT mardi ».
 *
 * ## Ce que le service ne fait pas
 *
 * Il ne publie rien. La note derivee n'a pas d'auteur humain, ne parait dans
 * aucune liste d'articles, et ne pretend pas etre une Interaction. C'est de la
 * memoire : revisable, supersedable. L'histoire humaine — les messages — reste
 * intacte et n'est jamais reecrite.
 *
 * ## Idempotence, et pourquoi elle precede la garde economique
 *
 * L'empreinte des messages sources est calculee AVANT toute resolution de
 * provider : une conversation inchangee ne doit pas couter un appel pour
 * reproduire la note qui existe deja. Meme discipline que
 * `DossierArticleIndexer::alreadyIndexed()`, qui court-circuite lui aussi
 * avant la garde economique.
 *
 * ## Concurrence
 *
 * Une derivation ne modifie JAMAIS une note existante. Elle en cree une
 * nouvelle version et supersede l'ancienne, sous verrou de ligne. Une
 * derivation partie d'un etat plus ancien que la version courante echoue a
 * gagner — elle est refusee, pas appliquee en silence. C'est le seul
 * comportement compatible avec l'invariant du CDC : « un job perime peut
 * echouer ou devenir stale, il ne peut pas gagner silencieusement ».
 */
final class LoopConversationKnowledgeDeriver
{
    public const FEATURE = 'loop_conversation_knowledge';

    /** Le sujet unique de cette premiere tranche : la conversation de la Boucle. */
    public const SUBJECT_CONVERSATION_DIGEST = 'conversation_digest';

    /** Bornes dures : une derivation lit une fenetre, jamais tout l'historique. */
    private const MAX_SOURCE_MESSAGES = 60;

    private const MAX_SOURCE_CHARS = 12000;

    /**
     * Un fait humain trop court ne porte pas de connaissance.
     *
     * PUBLIQUE depuis T1539 : le balayeur doit selectionner EXACTEMENT la
     * population que ce service lira. Deux seuils qui divergeraient feraient
     * promettre au balayeur un travail que le deriver refuserait — une Boucle
     * eternellement « due », redispatchee tous les quarts d'heure sans fin.
     */
    public const MIN_MESSAGE_CHARS = 20;

    public function __construct(
        private readonly CapabilityRegistry $capabilities,
        private readonly PromptRepository $prompts,
        private readonly ProviderResolver $providers,
        private readonly AiEconomicGuard $economicGuard,
        private readonly AiProviderInvocationLedger $ledger,
        private readonly DerivedChunkEligibility $eligibility,
        private readonly DerivedKnowledgeNoteIndexer $indexer,
    ) {}

    /**
     * Compile la conversation humaine d'une Boucle en une note derivee.
     *
     * @return DerivedKnowledgeNote|null null quand il n'y a rien a apprendre,
     *                                   rien de neuf, ou quand une garde refuse.
     */
    public function derive(Loop $loop): ?DerivedKnowledgeNote
    {
        $organization = $loop->organization;

        if (! $organization instanceof Organization) {
            return null;
        }

        $messages = $this->sourceMessages($loop);

        if ($messages === []) {
            return null;
        }

        $fingerprint = $this->fingerprint($messages);
        $current = $this->currentNote($organization, $loop);

        // Rien n'a bouge : aucune depense, aucune ecriture. La garde vient
        // AVANT le provider, jamais apres.
        if ($current !== null && hash_equals($current->source_fingerprint, $fingerprint)) {
            return $current;
        }

        $dossierId = $this->eligibility->rootDossierIdFor($loop);

        if ($dossierId === null) {
            // Sans Dossier racine, la note n'aurait nulle part ou etre indexee
            // — et surtout aucun perimetre documentaire connu. On ne derive pas
            // une connaissance qu'on ne saurait pas borner.
            return null;
        }

        $capability = CapabilityRegistry::LOOP_CONVERSATION_KNOWLEDGE;
        $definition = $this->capabilities->get($capability);

        $contexte = new ContexteIa(
            organizationId: (string) $organization->id,
            userId: null,
            loopId: (string) $loop->id,
            locale: str_starts_with((string) app()->getLocale(), 'en') ? 'en' : 'fr',
            capability: $capability,
            correlationId: AiCorrelation::id(),
            source: self::FEATURE,
            feature: self::FEATURE,
        );

        try {
            $resolved = $this->providers->resolve($capability, $contexte);
        } catch (DomainException) {
            // Organization sans credential : la conversation reste intacte,
            // rien n'est derive, une reprise ulterieure est possible.
            return null;
        }

        $verdict = $this->economicGuard->authorize(
            $organization,
            $definition->process,
            $resolved->provider,
            $resolved->model,
            (float) config('ai.knowledge.economic_guard.monthly_budget_usd', 2.00),
            (int) config('ai.knowledge.economic_guard.monthly_unknown_limit', 10),
        );

        if (! $verdict->allowed) {
            return null;
        }

        $baseInstructions = $this->activeInstructions($definition->promptKey);

        if ($baseInstructions === null) {
            return null;
        }

        $instructions = $this->prompts->compose($capability, $baseInstructions, (string) $organization->id);
        $transcript = $this->transcript($messages, $current);

        $startedAt = microtime(true);

        try {
            $agent = new LoopConversationKnowledgeAgent(
                $instructions,
                $definition->maxOutput,
                (float) config('ai.knowledge.temperature', 0.2),
            );

            $response = $agent->prompt($transcript, provider: $resolved->instance, model: $resolved->model);
            $usage = AiUsage::fromSdkTextTokens($response->usage->promptTokens, $response->usage->completionTokens);
            $content = AiMarkdownSanitizer::sanitize((string) $response->text, 3000);
        } catch (\Throwable $exception) {
            $this->recordLedger($organization, $contexte, $definition, $resolved, AiUsage::notObserved(), null, 'failed', $startedAt, $exception::class);

            return null;
        }

        $cost = $this->economicGuard->finalize($resolved->provider, $resolved->model, $usage);
        $this->recordLedger($organization, $contexte, $definition, $resolved, $usage, $cost, 'success', $startedAt, null);

        if (trim($content) === '') {
            return null;
        }

        $note = $this->persist($organization, $loop, $dossierId, $messages, $fingerprint, $content,
            $contexte->correlationId, $current?->id === null ? null : (string) $current->id);

        if ($note !== null) {
            $this->indexer->synchronize($note);
        }

        return $note;
    }

    /**
     * Les messages HUMAINS de la Boucle, bornes et ordonnes.
     *
     * `type = 'user'` exclusivement : ni les reponses IA (elles ont deja leur
     * chemin de capitalisation), ni les evenements, ni les agents de profil.
     * Les messages supprimes sont ecartes — l'historique humain peut etre
     * moderé, et une note ne doit pas faire survivre ce qu'un humain a retire.
     *
     * @return list<LoopMessage>
     */
    private function sourceMessages(Loop $loop): array
    {
        return LoopMessage::query()
            // `sender`, et non `user` : `LoopMessage` porte `sender_id`. Une
            // premiere version lisait `$message->user?->name`, qui rend null
            // SANS erreur sur un modele depourvu de la relation — le
            // transcript aurait perdu tous ses auteurs en silence.
            ->with('sender:id,name')
            ->where('loop_id', $loop->id)
            ->where('organization_id', $loop->organization_id)
            ->where('type', 'user')
            ->whereNull('deleted_at')
            ->orderByDesc('created_at')
            ->limit(self::MAX_SOURCE_MESSAGES)
            ->get()
            ->filter(static fn (LoopMessage $m): bool => mb_strlen(trim((string) $m->body)) >= self::MIN_MESSAGE_CHARS)
            ->reverse()
            ->values()
            ->all();
    }

    /**
     * L'empreinte de l'etat source.
     *
     * Elle porte l'identifiant ET la derniere edition de chaque message : un
     * message corrige change l'empreinte, donc redonne lieu a derivation. Meme
     * idiome que `dossier_chunks.content_hash`.
     *
     * @param  list<LoopMessage>  $messages
     */
    private function fingerprint(array $messages): string
    {
        $parts = array_map(
            static fn (LoopMessage $m): string => $m->id.':'.($m->edited_at?->toIso8601String() ?? $m->created_at?->toIso8601String() ?? ''),
            $messages,
        );

        return hash('sha256', implode('|', $parts));
    }

    /**
     * @param  list<LoopMessage>  $messages
     */
    private function transcript(array $messages, ?DerivedKnowledgeNote $memoire = null): string
    {
        $lines = [];
        $used = 0;

        foreach ($messages as $message) {
            $author = trim((string) ($message->sender?->name ?? '')) ?: '?';
            $line = $author.' : '.trim((string) $message->body);

            if ($used + mb_strlen($line) > self::MAX_SOURCE_CHARS) {
                break;
            }

            $lines[] = $line;
            $used += mb_strlen($line);
        }

        $conversation = implode("\n", $lines);

        if ($memoire === null || trim((string) $memoire->content) === '') {
            return $conversation;
        }

        // TASK-1538 — la memoire deja compilee entre dans le tour.
        //
        // La derivation ne lit qu'une FENETRE des derniers messages. Sans ce
        // bloc, un fait dit il y a six mois sortait de la fenetre, disparaissait
        // de la source, et la recompilation produisait une note qui ne le
        // contenait plus — laquelle supersedait celle qui le contenait. Le fait
        // n'etait ni corrige ni contredit : il etait oublie, en silence.
        //
        // Agrandir la fenetre ne repare rien : une fenetre de LECTURE n'est pas
        // une duree de vie de MEMOIRE. La compilation devient donc additive —
        // elle repart de ce qui est deja su et l'amende.
        //
        // Le contrat est dicte DANS le tour, jamais dans le prompt partage.
        // C'est l'idiome du depot (`DossierInsightsService::answerInstruction()`)
        // et il a ici une raison de plus : la mesure de T1537 a montre qu'une
        // retouche du prompt canonique degrade l'ensemble pour corriger un point.
        return implode("\n", [
            '--- CONNAISSANCE DEJA COMPILEE SUR CETTE BOUCLE ---',
            trim((string) $memoire->content),
            '',
            '--- ECHANGES LES PLUS RECENTS ---',
            $conversation,
            '',
            'Rends la connaissance a jour de cette Boucle, pas seulement le resume des echanges ci-dessus.',
            'Reprends tels quels les faits de la connaissance deja compilee qui restent vrais : ils ne sont PAS repetes dans les echanges recents, et les oublier reviendrait a effacer la memoire.',
            'Ne garde pas un fait que les echanges recents corrigent, invalident ou rendent caduc : dans ce cas, ecris seulement sa version a jour.',
        ]);
    }

    private function currentNote(Organization $organization, Loop $loop): ?DerivedKnowledgeNote
    {
        return DerivedKnowledgeNote::query()
            ->where('organization_id', $organization->id)
            ->where('source_type', DerivedKnowledgeNote::SOURCE_LOOP_CONVERSATION)
            ->where('source_loop_id', $loop->id)
            ->where('subject_key', self::SUBJECT_CONVERSATION_DIGEST)
            ->active()
            ->first();
    }

    /**
     * Ecrit la nouvelle version et supersede l'ancienne, sous verrou.
     *
     * Aucune note n'est jamais MODIFIEE : une correction humaine posterieure
     * reste lisible dans sa propre version, et une derivation partie d'un etat
     * ancien ne peut pas l'ecraser.
     *
     * @param  list<LoopMessage>  $messages
     */
    private function persist(
        Organization $organization,
        Loop $loop,
        string $dossierId,
        array $messages,
        string $fingerprint,
        string $content,
        ?string $correlationId,
        ?string $basisNoteId,
    ): ?DerivedKnowledgeNote {
        return DB::transaction(function () use ($organization, $loop, $dossierId, $messages, $fingerprint, $content, $correlationId, $basisNoteId): ?DerivedKnowledgeNote {
            $current = DerivedKnowledgeNote::query()
                ->where('organization_id', $organization->id)
                ->where('source_type', DerivedKnowledgeNote::SOURCE_LOOP_CONVERSATION)
                ->where('source_loop_id', $loop->id)
                ->where('subject_key', self::SUBJECT_CONVERSATION_DIGEST)
                ->active()
                ->lockForUpdate()
                ->first();

            // Une autre derivation a gagne la course pendant que celle-ci
            // appelait le provider, et elle a produit la MEME lecture de la
            // source. Rien a faire : la note en base est deja celle-la.
            if ($current !== null && hash_equals($current->source_fingerprint, $fingerprint)) {
                return $current;
            }

            // TASK-1538 — LA garde de course, et l'empreinte ne la donne pas.
            //
            // Comparer les empreintes protege le REJEU IDENTIQUE : deux workers
            // partis du meme etat. Elle ne protege rien d'autre, parce que deux
            // etats DIFFERENTS ont par construction des empreintes differentes.
            // Une derivation partie de S0 voyait donc une note nee de S1 comme
            // « autre chose » et la supersedait tranquillement.
            //
            // Ce qu'il faut comparer, c'est le POINT DE DEPART : la note active
            // au moment ou cette derivation a lu sa source. Si ce n'est plus
            // elle qui est active, quelqu'un a ecrit entre-temps et cette
            // derivation est perimee. Elle ne gagne pas — un compare-and-swap
            // sur le pointeur de version, sous le meme verrou, sans rien de
            // neuf a introduire.
            $courantId = $current?->id === null ? null : (string) $current->id;

            if ($courantId !== $basisNoteId) {
                return $current;
            }

            $observedAt = null;

            foreach ($messages as $message) {
                $at = $message->edited_at ?? $message->created_at;

                if ($at !== null && ($observedAt === null || $at->greaterThan($observedAt))) {
                    $observedAt = $at;
                }
            }

            // L'ancienne version sort de l'etat `active` AVANT que la nouvelle
            // n'y entre. L'index unique partiel de PostgreSQL est verifie a
            // chaque instruction, pas a la fin de la transaction : inserer
            // d'abord faisait cohabiter deux actives le temps d'une ligne, et
            // la base le refusait. L'ordre inverse est le seul valide, et il
            // decrit mieux ce qui se passe — une version en remplace une autre.
            if ($current !== null) {
                $current->forceFill([
                    'status' => DerivedKnowledgeNote::STATUS_SUPERSEDED,
                    'superseded_at' => now(),
                ])->save();
            }

            $note = DerivedKnowledgeNote::create([
                'organization_id' => $organization->id,
                'source_type' => DerivedKnowledgeNote::SOURCE_LOOP_CONVERSATION,
                'source_loop_id' => $loop->id,
                'dossier_id' => $dossierId,
                'subject_key' => self::SUBJECT_CONVERSATION_DIGEST,
                'content' => $content,
                'source_fingerprint' => $fingerprint,
                'provenance' => [
                    'source_loop_message_ids' => array_map(static fn (LoopMessage $m): string => (string) $m->id, $messages),
                    'source_message_count' => count($messages),
                    'correlation_id' => $correlationId,
                    // Jamais d'auteur humain : cette note n'est l'oeuvre de
                    // personne, et le produit doit pouvoir le dire.
                    'derived_by' => self::FEATURE,
                ],
                'observed_at' => $observedAt ?? now(),
                'derived_at' => now(),
                // TASK-1537 : le numero se derive de TOUT l'historique du
                // sujet, pas de la seule note active. `$current === null ? 1`
                // repartait a 1 des qu'aucune note n'etait active — et
                // heurtait alors l'unicite (organization, source_type,
                // source_loop_id, subject_key, version) sur la v1 archivee.
                // Le chemin nominal ne produit jamais cet etat, puisque
                // supersede et creation vivent dans la meme transaction ; mais
                // toute purge ou reprise future y tombait.
                'version' => 1 + (int) DerivedKnowledgeNote::query()
                    ->where('organization_id', $organization->id)
                    ->where('source_type', DerivedKnowledgeNote::SOURCE_LOOP_CONVERSATION)
                    ->where('source_loop_id', $loop->id)
                    ->where('subject_key', self::SUBJECT_CONVERSATION_DIGEST)
                    ->max('version'),
                'status' => DerivedKnowledgeNote::STATUS_ACTIVE,
            ]);

            if ($current !== null) {
                // Le chainage ne peut se poser qu'ici : il designe une note qui
                // n'existait pas encore une instruction plus tot.
                $current->forceFill(['superseded_by_id' => $note->id])->save();

                // Les chunks de la version precedente disparaissent : une note
                // superseded ne doit pas rester candidate. La clause
                // d'eligibilite l'exclut deja par `status`, mais laisser des
                // vecteurs morts derriere soi serait une seconde verite.
                $this->indexer->forget($current);
            }

            return $note;
        });
    }

    private function activeInstructions(string $promptKey): ?string
    {
        $prompt = AdminAiPrompt::query()
            ->where('scenario_id', $promptKey)
            ->where('is_active', true)
            ->orderByDesc('version')
            ->first();

        if ($prompt === null || trim((string) $prompt->prompt_text) === '') {
            return null;
        }

        return (string) $prompt->prompt_text;
    }

    private function recordLedger(
        Organization $organization,
        ContexteIa $contexte,
        \App\Ai\CapabilityDefinition $definition,
        \App\Ai\ResolvedModel $resolved,
        AiUsage $usage,
        ?\App\Support\Ai\AiCost $cost,
        string $status,
        float $startedAt,
        ?string $failure,
    ): void {
        // Le ledger canonique, jamais une seconde comptabilite. Un tour de
        // derivation coute, et cette depense doit se voir la ou toutes les
        // autres se voient.
        $this->ledger->recordGeneration(
            organizationId: (string) $organization->id,
            userId: null,
            capability: $definition->id,
            process: $definition->process,
            resolved: $resolved,
            usage: $usage,
            cost: $cost,
            status: $status,
            correlationId: $contexte->correlationId,
            sdkInvocationId: null,
            failureReason: $failure,
            startedAtMicrotime: $startedAt,
            feature: self::FEATURE,
        );
    }
}
