<?php

namespace App\Services\Ai;

use App\Ai\Agents\LoopKnowledgeAgent;
use App\Ai\CapabilityDefinition;
use App\Ai\CapabilityRegistry;
use App\Ai\Context\ContextBuilder;
use App\Ai\Context\DossierManifestSource;
use App\Ai\Context\DossierRetrievalSource;
use App\Ai\ContexteIa;
use App\Ai\PromptRepository;
use App\Ai\ProviderResolver;
use App\Ai\ResolvedModel;
use App\Events\LoopMessageCreated;
use App\Models\AdminAiPrompt;
use App\Models\AiInteraction;
use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\LoopMessage;
use App\Models\User;
use App\Services\Ai\DTO\KnowledgeAnswer;
use App\Support\Ai\AiCorrelation;
use App\Support\Ai\AiCost;
use App\Support\Ai\AiEconomicGuard;
use App\Support\Ai\AiMarkdownSanitizer;
use App\Support\Ai\AiRefusedException;
use App\Support\Ai\AiUsage;
use DomainException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * `loop_knowledge_answer` — reponse documentaire sourcee (TASK-1213 / RAG V1).
 *
 * Un membre pose une question depuis une Boucle. Le service :
 * 1. verifie l'appartenance (Boucle active, meme Organization) ;
 * 2. resout provider/modele/credential de l'Organization (P4, jamais de repli) ;
 * 3. applique la garde economique ;
 * 4. fait construire le contexte par le Context Builder — deux sources
 *    autorisees, tenant- et permission-safe, la MEME `DossierAccessScope` :
 *    `dossier.manifest` (inventaire deterministe, references [Mn], AUCUN
 *    contenu de document) et `dossier.retrieval` (extraits pgvector,
 *    references [Sn]) ;
 * 5. sans AUCUNE provenance — ni manifest ni extrait — : repond « pas trouve
 *    dans mes sources » SANS appeler le modele (aucune hallucination
 *    possible, aucun cout). Des que l'une des deux fournit quelque chose, le
 *    modele est appele : le manifest seul peut repondre a une question
 *    d'inventaire (TASK-1307, revue) ;
 * 6. sinon interroge le SDK (Constitution → capability → AdminAiPrompt), borne
 *    la reponse et ne retient comme sources citees que les references [Mn]
 *    ou [Sn] REELLEMENT presentes dans la provenance fournie — jamais une
 *    reference inventee par le modele, quel que soit son prefixe.
 *
 * TASK-1297 : le chemin n'est PLUS read-only. L'echange est publie dans le
 * fil de la Boucle sur le modele exact de ChatLoopAiService::ask() : la
 * question du membre (type `user`, si `ai.knowledge.publish_question` —
 * reversible en une ligne) puis la reponse documentaire liee (type `ai`,
 * `reply_to_id`, sources publiques en metadata). Rien n'est publie quand rien
 * n'a coute : pas de sources -> aucun appel, aucune interaction, aucun
 * message ; echec provider ou reponse vide -> aucun message, la trace seule.
 * Aucun autre objet metier, une seule trace P1.
 *
 * TASK-1309 : ce service porte DEUX moteurs documentaires, et un seul corps.
 * `answer()` = mode Dossiers (grounding strict, refus possible) ;
 * `answerHybrid()` = mode « IA + Dossiers » (capability `loop_hybrid_answer`,
 * prompt dedie) qui peut repondre depuis la connaissance generale du modele
 * quand les Dossiers ne fournissent rien, en le disant. Tout le reste — garde
 * d'appartenance, resolution P4, garde economique, Context Builder,
 * validation des citations, ledger, trace, publication — est le MEME code :
 * deux services separes auraient laisse ces regles diverger.
 */
class LoopKnowledgeAnswerService
{
    /**
     * TASK-1309 : les deux moteurs documentaires de ce service. Le mode n'est
     * jamais lu depuis une requete : il est choisi par l'appelant, en dur, a
     * l'entree publique correspondante.
     */
    private const MODE_DOSSIERS = 'dossiers';

    private const MODE_HYBRID = 'ia_dossiers';

    /**
     * TASK-1309 : ce que le mode IA + Dossiers met a la place du bloc de
     * sources quand les Dossiers accessibles n'ont RIEN fourni. Ce n'est pas
     * une source : c'est le constat de leur silence, dit au modele pour qu'il
     * le dise a l'utilisateur au lieu de le masquer.
     */
    private const HYBRID_NO_DOCUMENTARY_SOURCE_NOTICE =
        "--- SOURCES DOCUMENTAIRES ---\n"
        ."Aucun element des Dossiers accessibles de cette Boucle ne correspond a cette question : "
        .'il n\'y a AUCUNE reference [Mn] ni [Sn] disponible pour cette reponse.';

    public function __construct(
        private readonly CapabilityRegistry $capabilities,
        private readonly PromptRepository $prompts,
        private readonly ProviderResolver $providers,
        private readonly ContextBuilder $contextBuilder,
        private readonly AiEconomicGuard $economicGuard,
        private readonly AiProviderInvocationLedger $ledger,
        private readonly AiConversationContextBuilder $conversationContext,
    ) {}

    /**
     * TASK-1309 : mode Dossiers — grounding documentaire STRICT. Sans aucune
     * provenance, il refuse SANS appeler le modele : c'est sa valeur, pas une
     * limite.
     *
     * TASK-1299 : `$inThreadTrigger` est le message HUMAIN deja persiste par
     * le composeur (`LoopChat::sendMessage()`). Fourni, la question n'est PAS
     * re-publiee — elle existe deja dans le fil, la re-ecrire est le piege de
     * la double persistance — et la reponse lui est liee par `reply_to_id`. A
     * null, le chemin T-1 est inchange octet pour octet (modal knowledge,
     * flag `ai.knowledge.publish_question` gouvernant).
     */
    public function answer(Loop $loop, User $requester, string $question, ?LoopMessage $inThreadTrigger = null): KnowledgeAnswer
    {
        return $this->respond(self::MODE_DOSSIERS, $loop, $requester, $question, $inThreadTrigger);
    }

    /**
     * TASK-1309 : mode « IA + Dossiers » — reponse CROISEE.
     *
     * Meme chaine, meme perimetre, meme garde economique, meme ledger que
     * `answer()`. UNE seule difference de comportement, et elle est le coeur
     * du mode : l'absence de provenance documentaire n'est PAS un refus. Le
     * modele repond alors depuis sa connaissance generale, en disant que les
     * Dossiers accessibles n'ont rien apporte — jamais en habillant cette
     * connaissance generale d'une reference [Mn]/[Sn].
     */
    public function answerHybrid(Loop $loop, User $requester, string $question, ?LoopMessage $inThreadTrigger = null): KnowledgeAnswer
    {
        return $this->respond(self::MODE_HYBRID, $loop, $requester, $question, $inThreadTrigger);
    }

    /**
     * Le chemin PARTAGE des deux modes (TASK-1309). Un seul corps : la garde
     * d'appartenance, la resolution P4, la garde economique, le Context
     * Builder, la validation de citations, le ledger, la trace et la
     * publication ne peuvent pas diverger entre Dossiers et IA + Dossiers.
     */
    private function respond(string $mode, Loop $loop, User $requester, string $question, ?LoopMessage $inThreadTrigger): KnowledgeAnswer
    {
        $question = trim($question);

        if ($question === '') {
            throw new RuntimeException(__('loops.knowledge_question_required'));
        }

        $this->assertCanRequest($loop, $requester);

        $capability = $mode === self::MODE_HYBRID
            ? CapabilityRegistry::LOOP_HYBRID_ANSWER
            : CapabilityRegistry::LOOP_KNOWLEDGE_ANSWER;
        $definition = $this->capabilities->get($capability);
        $this->capabilities->assertScopeAllowed($capability, CapabilityRegistry::SCOPE_LOOP);

        $organization = $loop->organization()->firstOrFail();

        $contexte = new ContexteIa(
            organizationId: (string) $organization->id,
            userId: (string) $requester->id,
            loopId: (string) $loop->id,
            locale: str_starts_with((string) app()->getLocale(), 'en') ? 'en' : 'fr',
            capability: $capability,
            correlationId: AiCorrelation::id(),
            source: CapabilityRegistry::SOURCE_DOSSIER_RETRIEVAL,
            query: $question,
        );

        // P4 : sans configuration IA d'Organization, aucun appel, aucun repli.
        // TASK-1229 : etat « credential absent », code stable, distinct des
        // deux refus economiques ci-dessous.
        try {
            $resolved = $this->providers->resolve($capability, $contexte);
        } catch (DomainException $exception) {
            throw AiRefusedException::notConfigured($exception);
        }

        // TASK-1229 : le demandeur est passe a la garde — son credit IA du
        // mois (utilisations) s'applique ICI, dans l'autorite existante, avant
        // toute recherche documentaire et toute generation.
        $verdict = $this->economicGuard->authorize(
            $organization,
            $definition->process,
            $resolved->provider,
            $resolved->model,
            (float) config('ai.knowledge.economic_guard.monthly_budget_usd', 2.00),
            (int) config('ai.knowledge.economic_guard.monthly_unknown_limit', 10),
            $requester,
        );

        if (! $verdict->allowed) {
            // Trois etats, trois messages, trois codes : credit utilisateur
            // epuise / budget Organization atteint / autre indisponibilite.
            throw AiRefusedException::fromVerdict($verdict);
        }

        // Le prompt administrable est requis AVANT toute depense (embedding
        // compris) : sans lui, indisponibilite explicite.
        // TASK-1227 : doctrine active de l'Organization composee sous la
        // Constitution ; la regle « repondre depuis les sources [S] » reste
        // appliquee en code (citations revalidees), la doctrine ne peut pas
        // autoriser d'inventer.
        $instructions = $this->prompts->compose($capability, $this->capabilityInstructions($definition->promptKey), (string) $organization->id);
        // TASK-1236 : version de doctrine reellement composee ci-dessus, tracee
        // sur l'interaction enregistree plutot que reconstituee a posteriori.
        $doctrineVersion = $this->prompts->activeDoctrineVersion((string) $organization->id);

        $borne = $this->contextBuilder->build($contexte, $definition);
        // TASK-1307 (revue) : la connaissance disponible est la provenance des
        // DEUX sources autorisees de cette capability — le manifest
        // (existence des elements du Dossier, [Mn]) ET le retrieval (contenu
        // documentaire, [Sn]). Ni l'un ni l'autre n'est privilegie a priori :
        // le manifest seul suffit a une question d'inventaire, le retrieval
        // seul a une question de contenu, les deux ensemble a une question
        // mixte. Le refus ci-dessous ne se declenche que si AUCUNE des deux
        // n'a fourni quoi que ce soit.
        $consulted = [
            ...$borne->provenanceFor(DossierManifestSource::NAME),
            ...$borne->provenanceFor(DossierRetrievalSource::NAME),
        ];

        // TASK-1309 : le refus « aucune source » n'appartient QU'au mode
        // Dossiers. En mode IA + Dossiers, l'absence de provenance
        // documentaire est une information a transmettre au modele, pas une
        // raison de se taire : c'est tout l'interet du mode.
        if ($consulted === [] && $mode !== self::MODE_HYBRID) {
            // Rien de pertinent dans les Dossiers accessibles : on le dit, sans
            // inventer et sans appeler le modele.
            return new KnowledgeAnswer(
                answer: __('loops.knowledge_no_sources'),
                sources: [],
                consulted: [],
                grounded: false,
                interactionId: null,
                // TASK-1229 : la recherche documentaire a pu etre emise (une
                // utilisation reelle) : le credit se lit ici aussi.
                credit: $this->economicGuard->userCreditStatus($organization, $requester),
            );
        }

        $agent = new LoopKnowledgeAgent(
            $instructions,
            (int) config('ai.knowledge.max_tokens', 700),
            (float) config('ai.knowledge.temperature', 0.2),
        );

        $startedAt = microtime(true);
        // TASK-1300 / TASK-1308 : sur un reply (a une bulle IA OU humaine
        // depuis TASK-1308), l'echange precedent — borne, agnostique du
        // moteur — s'insere entre les sources et la question ; partout
        // ailleurs le prompt T-3 est inchange octet pour octet.
        $conversation = $this->conversationContext->build($inThreadTrigger);
        $thread = $conversation->text;
        // TASK-1309 : en mode IA + Dossiers sans AUCUNE provenance, le bloc
        // de sources est vide. Le laisser vide, c'est laisser le modele
        // deviner ce que valent les Dossiers ; on le lui DIT, explicitement,
        // pour qu'il puisse repondre depuis sa connaissance generale tout en
        // signalant que les Dossiers n'ont rien apporte (brief section 16).
        $sourcesBlock = $borne->text !== ''
            ? $borne->text
            : ($mode === self::MODE_HYBRID ? self::HYBRID_NO_DOCUMENTARY_SOURCE_NOTICE : '');
        $prompt = $sourcesBlock
            .($thread === '' ? '' : "\n\n".$thread)
            ."\n\nQuestion du membre :\n".$question;

        try {
            $response = $agent->prompt(
                $prompt,
                provider: $resolved->instance,
                model: $resolved->model,
            );
        } catch (\Throwable $exception) {
            $this->recordInteraction($loop, $requester, $contexte, $definition, $resolved, $prompt, null,
                AiUsage::notObserved(), ['cost_usd' => null, 'cost_unknown' => null], null, 'failed', $startedAt, null,
                $exception::class, $consulted, [], $doctrineVersion);

            throw new RuntimeException(__('loops.ai_error'), 0, $exception);
        }

        $answer = AiMarkdownSanitizer::sanitize(
            (string) $response->text,
            (int) config('ai.knowledge.max_answer_chars', 3000),
        );

        if ($answer === '') {
            throw new RuntimeException(__('loops.ai_empty_response'));
        }

        $usage = AiUsage::fromSdkTextTokens($response->usage->promptTokens, $response->usage->completionTokens);
        $cost = $this->economicGuard->finalize($resolved->provider, $resolved->model, $usage);

        // Citations : uniquement les references ([Mn] ou [Sn]) presentes
        // dans la provenance REELLEMENT fournie. Une reference inventee est
        // ignoree.
        $cited = $this->citedSources($answer, $consulted);

        $interaction = $this->recordInteraction($loop, $requester, $contexte, $definition, $resolved, $prompt,
            $answer, $usage, $cost->traceAttributes(), $cost, 'success', $startedAt, $response->invocationId, null,
            $consulted, $cited, $doctrineVersion);

        // TASK-1309 : « Sources utilisées » = sources REELLEMENT CITEES.
        // Jusqu'ici, faute de citation valide, on retombait sur TOUT ce qui
        // avait ete consulte — une reponse qui refusait de repondre affichait
        // alors dix « sources utilisees » qui n'avaient soutenu aucune
        // affirmation. En mode IA + Dossiers, ce repli aurait ete pire
        // encore : une reponse 100 % connaissance generale se serait parée de
        // sources documentaires.
        $sources = $cited;

        $this->publishExchange($mode, $loop, $requester, $question, $answer, $resolved, $interaction, $cited,
            $sources, $this->consultedForDisplay($cited, $consulted), $inThreadTrigger, $conversation->messageIds);

        return new KnowledgeAnswer(
            answer: $answer,
            sources: $sources,
            consulted: $consulted,
            grounded: $cited !== [],
            interactionId: $interaction->id,
            // TASK-1229 : le credit APRES cette reponse (recherche + generation
            // decomptees) — l'alerte de seuil se lit ici, l'action n'a pas
            // ete bloquee.
            credit: $this->economicGuard->userCreditStatus($organization, $requester),
        );
    }

    /**
     * TASK-1297 : publication de l'echange dans le fil, calquee sur
     * ChatLoopAiService::ask() — la question du membre (type `user`) puis la
     * reponse (type `ai`) liee par `reply_to_id`, sender_id null,
     * organization_id de la Boucle, sources publiques et provenance en
     * metadata. N'est appelee QU'APRES une generation reussie et sa trace :
     * un refus, un echec provider ou une reponse vide n'arrivent jamais ici.
     *
     * @param  list<array<string, mixed>>  $cited
     * @param  list<array<string, mixed>>  $sources
     * @param  list<string>  $contextMessageIds
     */
    private function publishExchange(
        string $mode,
        Loop $loop,
        User $requester,
        string $question,
        string $answer,
        ResolvedModel $resolved,
        AiInteraction $interaction,
        array $cited,
        array $sources,
        array $consultedForDisplay = [],
        ?LoopMessage $inThreadTrigger = null,
        array $contextMessageIds = [],
    ): void {
        DB::transaction(function () use ($mode, $loop, $requester, $question, $answer, $resolved, $interaction, $cited, $sources, $consultedForDisplay, $inThreadTrigger, $contextMessageIds): void {
            $questionMessage = null;

            // La ligne de reversibilite (gouvernance 24/08) : false = seule la
            // reponse est publiee, la question restant en metadata. Un
            // declencheur dans le fil (TASK-1299) rend la question deja
            // publiee PAR SON AUTEUR : rien a re-ecrire, le flag est sans
            // objet sur ce chemin.
            if ($inThreadTrigger === null && (bool) config('ai.knowledge.publish_question', true)) {
                $questionMessage = LoopMessage::create([
                    'loop_id' => $loop->id,
                    'sender_id' => $requester->id,
                    'reply_to_id' => null,
                    'body' => $question,
                    'image_path' => null,
                    'type' => 'user',
                    'metadata' => [
                        'asked_knowledge_question' => true,
                    ],
                    'organization_id' => $loop->organization_id,
                ]);
            }

            $message = LoopMessage::create([
                'loop_id' => $loop->id,
                'sender_id' => null,
                'reply_to_id' => $inThreadTrigger?->id ?? $questionMessage?->id,
                'body' => $answer,
                'image_path' => null,
                'type' => 'ai',
                'metadata' => [
                    'requested_by' => $requester->id,
                    // TASK-1308 : `ai_mode` est le discriminant canonique de
                    // l'identite de bulle (Organization · Dossiers) — le seul
                    // moteur qui ecrit `rag`. `action` reste pour l'audit
                    // historique : `knowledge` sans declencheur (chemin JSON
                    // T-1), `dossiers` pour tout reply explicite au mode
                    // Dossiers — remplace l'ancienne distinction
                    // slash_ia/continuation, retiree avec `/ia` (TASK-1308).
                    // TASK-1309 : le troisieme moteur ecrit son propre
                    // discriminant `llm_rag` — l'identite de bulle
                    // « {Organization} · IA + Dossiers » en decoule, sans
                    // migration de donnees ni relecture des messages existants.
                    'ai_mode' => $mode === self::MODE_HYBRID ? 'llm_rag' : 'rag',
                    'action' => $mode === self::MODE_HYBRID
                        ? 'ia_dossiers'
                        : ($inThreadTrigger === null ? 'knowledge' : 'dossiers'),
                    'question' => $question,
                    'grounded' => $cited !== [],
                    'sources' => array_map(KnowledgeAnswer::publicSource(...), $sources),
                    // TASK-1309 (recette E2E) : les documents dont le CONTENU
                    // a ete lu sans qu'aucune citation valide n'en sorte.
                    // Presents SOUS LEUR VRAI TITRE (« Sources consultées »),
                    // jamais comme un appui — et seulement quand il n'y a
                    // aucune source utilisee a montrer, sinon la cle est
                    // absente et la metadata reste identique a avant.
                    ...($consultedForDisplay === [] ? [] : [
                        'consulted' => array_map(KnowledgeAnswer::publicSource(...), $consultedForDisplay),
                    ]),
                    'provider' => $resolved->provider,
                    'model' => $resolved->model,
                    'ai_interaction_id' => $interaction->id,
                    'context_message_ids' => $contextMessageIds,
                ],
                'organization_id' => $loop->organization_id,
            ]);

            event(new LoopMessageCreated($message));

            $loop->touch();
        });
    }

    /**
     * TASK-1307 (revue) : ne suppose JAMAIS la forme d'une reference — pas de
     * regex `[S\d+]` qui « croirait » que toute source commence par S. Pour
     * chaque entree REELLEMENT fournie (manifest [Mn] ou retrieval [Sn]), on
     * verifie si son propre marqueur `[ref]` apparait litteralement dans la
     * reponse. La propriete de securite est la meme qu'avant, garantie
     * autrement : une reference que le modele invente ne correspond a AUCUNE
     * entree de `$consulted`, donc ne peut jamais devenir une source publique
     * — quel que soit son prefixe.
     *
     * @param  list<array<string, mixed>>  $consulted
     * @return list<array<string, mixed>>
     */
    /**
     * TASK-1309 (recette E2E reelle) : ce qu'on montre quand RIEN n'est cite.
     *
     * Decouvert en recette : un modele peut produire une reponse parfaitement
     * fondee sur un extrait fourni SANS ecrire son marqueur `[Sn]` (constate
     * sur le banc ai-validation, run 2b66b90e). La regle « sources utilisees
     * = sources citees » est juste, mais appliquee seule elle faisait alors
     * disparaitre TOUTE provenance : le membre n'avait plus rien a verifier.
     *
     * On montre donc, sous le titre « Sources consultées » et jamais sous
     * « Sources utilisées », les documents dont le CONTENU a reellement ete
     * lu — les entrees `dossier.retrieval` uniquement. Le manifest en est
     * exclu volontairement : « j'ai regarde la liste des fichiers » n'est pas
     * une provenance a offrir a la verification, c'est du bruit (et jusqu'a
     * 30 lignes). Aucune de ces entrees n'est presentee comme ayant soutenu
     * une affirmation.
     *
     * Vide des qu'une citation valide existe : dans ce cas la bulle montre
     * les sources utilisees, et la metadata reste identique a avant.
     *
     * @param  list<array<string, mixed>>  $cited
     * @param  list<array<string, mixed>>  $consulted
     * @return list<array<string, mixed>>
     */
    private function consultedForDisplay(array $cited, array $consulted): array
    {
        if ($cited !== []) {
            return [];
        }

        return array_values(array_filter(
            $consulted,
            static fn (array $source): bool => ($source['source'] ?? null) === DossierRetrievalSource::NAME,
        ));
    }

    private function citedSources(string $answer, array $consulted): array
    {
        return array_values(array_filter(
            $consulted,
            static function (array $source) use ($answer): bool {
                $ref = $source['ref'] ?? null;

                return $ref !== null && str_contains($answer, '['.$ref.']');
            },
        ));
    }

    private function assertCanRequest(Loop $loop, User $requester): void
    {
        $membership = LoopMember::where('loop_id', $loop->id)
            ->where('user_id', $requester->id)
            ->where('status', 'active')
            ->exists();

        if (! $membership) {
            throw new RuntimeException(__('loops.not_an_active_member'));
        }

        if ($loop->organization_id !== $requester->organization_id) {
            throw new RuntimeException(__('loops.cross_organization'));
        }
    }

    /**
     * Instruction capability chargee depuis la source editable ; son absence
     * est une indisponibilite explicite (aucun prompt metier hardcode).
     *
     * TASK-1309 : le scenario vient de `CapabilityDefinition::$promptKey` —
     * `loop_knowledge_answer` ou `loop_hybrid_answer`. Deux capabilities, deux
     * lignes administrables, une seule regle de chargement.
     */
    private function capabilityInstructions(string $scenarioId): string
    {
        $prompt = AdminAiPrompt::query()
            ->where('scenario_id', $scenarioId)
            ->where('is_active', true)
            ->orderByDesc('version')
            ->first();

        if ($prompt === null || trim((string) $prompt->prompt_text) === '') {
            throw new RuntimeException(__('loops.knowledge_prompt_missing'));
        }

        return (string) $prompt->prompt_text;
    }

    /**
     * @param  array{cost_usd: ?float, cost_unknown: ?bool}  $costAttributes
     * @param  list<array<string, mixed>>  $consulted
     * @param  list<array<string, mixed>>  $cited
     */
    private function recordInteraction(
        Loop $loop,
        User $requester,
        ContexteIa $contexte,
        CapabilityDefinition $definition,
        ResolvedModel $resolved,
        string $prompt,
        ?string $response,
        AiUsage $usage,
        array $costAttributes,
        ?AiCost $cost,
        string $status,
        float $startedAt,
        ?string $sdkInvocationId,
        ?string $failure,
        array $consulted,
        array $cited,
        ?int $doctrineVersion,
    ): AiInteraction {
        // TASK-1220 : ligne canonique du ledger, memes points que la trace P1
        // (succes ET echec) ; les refus pre-provider n'arrivent jamais ici.
        $this->ledger->recordGeneration(
            organizationId: $contexte->organizationId,
            userId: (string) $requester->id,
            capability: $definition->id,
            process: $definition->process,
            resolved: $resolved,
            usage: $usage,
            cost: $cost,
            status: $status,
            correlationId: $contexte->correlationId,
            sdkInvocationId: $sdkInvocationId,
            failureReason: $failure,
            startedAtMicrotime: $startedAt,
        );

        $ids = static fn (array $sources): array => array_values(array_map(
            static fn (array $s): array => ['chunk_id' => $s['chunk_id'] ?? null, 'dossier_id' => $s['dossier_id'] ?? null, 'blog_post_id' => $s['blog_post_id'] ?? null],
            $sources,
        ));

        return AiInteraction::create([
            'user_id' => $requester->id,
            'organization_id' => $contexte->organizationId,
            'correlation_id' => $contexte->correlationId,
            'process' => $definition->process,
            // TASK-1309 : la capability EST la feature de cette trace —
            // `loop_knowledge_answer` (valeur historique, inchangee) ou
            // `loop_hybrid_answer`. Le `process` economique, lui, reste
            // commun aux deux (voir CapabilityRegistry).
            'feature' => $definition->id,
            'model' => $resolved->trace(),
            'prompt' => $prompt,
            'response' => $response,
            'input_tokens' => $usage->inputTokensOrZero(),
            'output_tokens' => $usage->outputTokensOrZero(),
            ...$costAttributes,
            'metadata' => array_filter([
                'loop_id' => $loop->id,
                'requested_by' => $requester->id,
                'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'provider' => $resolved->provider,
                'capability' => $definition->id,
                'status' => $status,
                'sdk_invocation_id' => $sdkInvocationId,
                'failure' => $failure,
                'retrieval' => ['consulted' => $ids($consulted), 'cited' => $ids($cited)],
            ], static fn ($value): bool => $value !== null)
                // TASK-1236 : cle toujours presente, meme a null (aucune doctrine
                // active) — sa PRESENCE distingue une interaction tracee d'une
                // ligne anterieure au mecanisme, ce qu'un array_filter effacerait.
                + ['doctrine_version' => $doctrineVersion],
        ]);
    }
}
