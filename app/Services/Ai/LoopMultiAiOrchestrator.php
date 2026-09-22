<?php

namespace App\Services\Ai;

use App\Ai\Agents\LoopMultiAiAgent;
use App\Ai\CapabilityDefinition;
use App\Ai\CapabilityRegistry;
use App\Ai\Context\ContexteBorne;
use App\Ai\ContexteIa;
use App\Ai\MultiAssistant\AssistantInstructions;
use App\Ai\MultiAssistant\AssistantOutcome;
use App\Ai\MultiAssistant\MultiAssistantRun;
use App\Ai\MultiAssistant\SharedEvidence;
use App\Ai\PromptRepository;
use App\Ai\ProviderResolver;
use App\Ai\ResolvedModel;
use App\Models\AdminAiPrompt;
use App\Models\AiInteraction;
use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\Organization;
use App\Models\User;
use App\Services\Loops\LoopAiAssistants;
use App\Services\Loops\LoopPluginActivation;
use App\Support\Ai\AiCorrelation;
use App\Support\Ai\AiEconomicGuard;
use App\Support\Ai\AiMarkdownSanitizer;
use App\Support\Ai\AiRefusedException;
use App\Support\Ai\AiTurnLock;
use App\Support\Ai\AiTurnReason;
use App\Support\Ai\AiTurnState;
use App\Support\Ai\AiTurnTrace;
use App\Support\Ai\AiUsage;
use DomainException;
use Illuminate\Support\Str;
use Laravel\Ai\Exceptions\RateLimitedException;
use RuntimeException;

/**
 * Le moteur du module « Pour / Contre ». (TASK-1621, ex-TASK-1618)
 *
 * Un tour = DEUX generations sequentielles, POUR puis CONTRE, sur la question
 * soumise. Aucune UX ici : ce service rend une structure.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * Ce qui a change, et pourquoi c'est ecrit ici
 * ────────────────────────────────────────────────────────────────────────────
 *
 * Ce fichier a d'abord orchestre TROIS assistants sur un Evidence partage tire
 * de la Boucle. La campagne humaine de TASK-1620 a tranche autrement :
 *
 *   - **plus de RAG.** Le module repond depuis les connaissances generales du
 *     modele. Consulter les Dossiers reste la fonctionnalite documentaire
 *     separee ; les melanger rendait le module lent et son resultat difficile
 *     a expliquer. `ContextBuilder` n'est donc PAS appele, et l'etape le dit
 *     (`bypassed` + `LLM_PATH_NO_CONTEXT_BUILDER`, le code que le mode `ia`
 *     emploie deja pour la meme raison) ;
 *   - **deux roles, pas trois postures.** `aperio` porte POUR, `traverse`
 *     porte CONTRE. `limen` reste declare et ses donnees restent en place,
 *     mais il n'est plus lance ;
 *   - **plus de synthese, plus de follow-ups.** Deux regards, et l'humain
 *     tranche.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * Les invariants que ce fichier tient
 * ────────────────────────────────────────────────────────────────────────────
 *
 * 1. LA TRACE NE PRETEND RIEN. Il n'y a plus d'Evidence : il n'y a donc plus
 *    de `retrieval = reused`, plus de `reason = evidence_shared`, plus de
 *    `source_turn_id`. Une cle absente se lit « rien ici » ; une cle presente
 *    et fausse se lit comme une mesure.
 * 2. UN REFUS AVANT LE PROVIDER NE COUTE RIEN ET SE VOIT QUAND MEME. Aucune
 *    `AiProviderInvocation`, mais une `AiInteraction` non generative portant
 *    l'assistant, le tour, la correlation et la raison exacte.
 * 3. UN ROLE QUI TOMBE NE FAIT PAS TOMBER L'AUTRE. POUR en erreur laisse
 *    CONTRE s'executer, et reciproquement.
 *
 * Il ne publie rien dans le fil : ecrire dans la conversation de tout le monde
 * est un acte de l'interface, declenche par un humain qui a clique.
 */
final class LoopMultiAiOrchestrator
{
    public const PRODUCER = 'loop_multi_ai_orchestrator';

    /**
     * Le prefixe de `ai_provider_invocations.feature`. La COLONNE `capability`
     * reste `loop_multi_ai` — canonique, verifiee par le ledger — et c'est
     * `feature` qui porte l'identite produit de l'assistant (semantique
     * TASK-1229). Les trois assistants partagent donc une capability, un
     * process et un budget, et restent distinguables ligne par ligne.
     */
    public const FEATURE_PREFIX = CapabilityRegistry::LOOP_MULTI_AI.':';

    /**
     * Les DEUX roles du module « Pour / Contre ». (TASK-1621)
     *
     * Ce sont des CLES TECHNIQUES, pas des libelles : `aperio` porte le role
     * POUR, `traverse` le role CONTRE. Les renommer aurait casse les lignes
     * deja ecrites dans `loop_ai_assistants` et `loop_plugin_ai_models` pour
     * un changement d'affichage — meme arbitrage que `key = training` (T1116).
     *
     * `limen` n'y figure pas : il reste declare au catalogue et ses donnees
     * restent en place, mais il n'est plus lance. Aucune migration.
     */
    public const ROLE_POUR = 'aperio';

    public const ROLE_CONTRE = 'traverse';

    /** @var list<string> */
    public const ROLES = [self::ROLE_POUR, self::ROLE_CONTRE];

    public function __construct(
        private CapabilityRegistry $capabilities,
        private ProviderResolver $providers,
        private PromptRepository $prompts,
        private AiEconomicGuard $economicGuard,
        private AiProviderInvocationLedger $ledger,
        private LoopPluginModelGuard $modelGuard,
        private LoopAiAssistants $assistants,
        private LoopPluginActivation $activation,
    ) {}

    /**
     * Un tour complet. Rend TOUJOURS une structure : un echec d'assistant y
     * figure, il ne se propage pas.
     *
     * Les seules exceptions qui sortent d'ici sont celles qui empechent le
     * tour ENTIER d'exister — question vide, non-membre, plugin eteint,
     * Organization sans credential. Aucune ne concerne un assistant.
     */
    public function runPourContre(Loop $loop, User $requester, string $question, ?string $executionPath = null): MultiAssistantRun
    {
        return $this->execute($loop, $requester, $question, self::ROLES, $executionPath);
    }

    /**
     * UN seul assistant. (TASK-1619)
     *
     * C'est ce que servent les boutons individuels, « demander a une autre IA »
     * et « Reessayer ». Le chemin est EXACTEMENT celui de `run()` — memes
     * gardes, meme Evidence, memes traces — restreint a un assistant : un
     * second chemin aurait derive du premier au premier correctif applique
     * d'un seul cote.
     *
     * L'Evidence est reconstruit pour ce tour, et c'est voulu : un reessai
     * quelques minutes plus tard doit voir la conversation TELLE QU'ELLE EST,
     * pas telle qu'elle etait. Le partage vaut a l'interieur d'un tour, pas
     * entre deux demandes separees par un geste humain.
     */
    public function runOne(Loop $loop, User $requester, string $question, string $assistantKey, ?string $executionPath = null): MultiAssistantRun
    {
        return $this->execute($loop, $requester, $question, [$assistantKey], $executionPath);
    }

    // TASK-1621 — `synthesise()` et `matiereDeSynthese()` ont ete RETIREES.
    //
    // Le module « Pour / Contre » ne propose plus de synthese : deux regards,
    // et c'est l'humain qui tranche. Garder une entree generative qu'aucune
    // surface n'appelle aurait laisse du code capable de depenser sans que
    // personne puisse le declencher — une porte sans poignee, du mauvais cote.
    //
    // La seule lecture d'une IA par une IA du plugin disparait donc avec elle.

    /**
     * @param  list<string>|null  $seulement  null = tous les assistants actifs
     */
    private function execute(Loop $loop, User $requester, string $question, ?array $seulement, ?string $executionPath): MultiAssistantRun
    {
        $question = trim($question);

        if ($question === '') {
            throw new RuntimeException(__('loops.knowledge_question_required'));
        }

        // Garde 1 — appartenance et tenant. AVANT tout, et sans rapport avec
        // l'empreinte des preuves (invariant 1).
        $this->assertCanRequest($loop, $requester);

        // Garde 2 — le plugin. `isEnabled()` reconfronte la disponibilite de
        // l'Organization a chaque lecture : une Organization dont la
        // disponibilite a ete retiree ne peut pas continuer a servir parce
        // qu'une Boucle avait dit oui autrefois.
        if (! $this->activation->isEnabled(LoopAiAssistants::PLUGIN, $loop)) {
            throw new RuntimeException(__('loops.plugins_loop_not_enabled'));
        }

        // Le verrou englobe l'acte economique ENTIER — trois generations, pas
        // une. Reentrant dans la requete (TASK-1311) : un appelant qui l'a
        // deja pris le garde, il n'est pas repris ici.
        return AiTurnLock::run(
            $loop,
            $requester,
            fn (): MultiAssistantRun => $this->runUnderLock($loop, $requester, $question, $seulement, $executionPath),
        );
    }

    /**
     * @param  list<string>|null  $seulement
     */
    private function runUnderLock(Loop $loop, User $requester, string $question, ?array $seulement, ?string $executionPath): MultiAssistantRun
    {
        $capability = CapabilityRegistry::LOOP_MULTI_AI;
        $definition = $this->capabilities->get($capability);
        $this->capabilities->assertScopeAllowed($capability, CapabilityRegistry::SCOPE_LOOP);

        $organization = $loop->organization()->firstOrFail();
        $locale = $this->localeDeReference($organization);

        // C1 — UNE correlation pour toute l'operation. C'est elle qui rend les
        // quatre tours (E1, A1, T1, L1) lisibles comme un seul acte.
        $correlationId = AiCorrelation::id();

        // ── Etape 1 : l'ancrage du tour. AUCUNE lecture de la Boucle. ───────
        $evidence = $this->ancrageSansRetrieval(
            $organization, $loop, $requester, $question, $locale, $correlationId, $definition, $executionPath,
        );

        // Le modele de l'Organization, resolu UNE fois. Il n'est pas celui qui
        // repondra — voir `effectiveModel()` — mais c'est lui qui porte le
        // provider, l'instance SDK et donc le credential du tenant.
        $base = $this->resolveBaseModel($capability, $evidence, $organization, $loop, $requester, $definition);

        // Le socle de prompt administrable, compose UNE fois : il est identique
        // pour les trois. Seule la persona, ajoutee en dernier rang, differe.
        $socle = $this->prompts->compose($capability, $this->capabilityInstructions($definition->promptKey), (string) $organization->id);
        $doctrineVersion = $this->prompts->activeDoctrineVersion((string) $organization->id);

        // ── Etape 2 : les trois assistants, DANS L'ORDRE. ───────────────────
        //
        // `describeFor()` rend le catalogue trie par `order` — Aperio (1),
        // Traverse (2), Limen (3). L'ordre n'est pas un detail d'affichage :
        // c'est le contrat V0 du CDC, et SLICE E fera dependre Limen des deux
        // precedents. Le trier ici, et pas a l'ecran, est ce qui rendra cette
        // dependance possible sans rien deplacer.
        $outcomes = [];

        foreach ($this->assistants->describeFor($loop) as $assistant) {
            // Un assistant eteint n'est pas un assistant en echec : la Boucle a
            // decide qu'il ne participe pas. Il n'a donc ni tour, ni ligne, ni
            // resultat — l'inclure avec un statut donnerait a lire une panne la
            // ou il y a un reglage.
            if (! ($assistant['enabled'] ?? true)) {
                continue;
            }

            // TASK-1619 — la restriction s'applique APRES `enabled` : demander
            // nommement un assistant que la Boucle a eteint ne le rallume pas.
            // Un bouton ne contourne pas un reglage.
            if ($seulement !== null && ! in_array((string) $assistant['key'], $seulement, true)) {
                continue;
            }

            $outcomes[] = $this->runAssistant(
                (string) $assistant['key'],
                (string) $assistant['instruction'],
                $socle,
                $question,
                $evidence,
                $base,
                $organization,
                $loop,
                $requester,
                $definition,
                $locale,
                $doctrineVersion,
                $executionPath,
            );
        }

        return new MultiAssistantRun($correlationId, $evidence, $outcomes);
    }

    // ────────────────────────────────────────────────────────────────────────
    // Etape 1 — les preuves
    // ────────────────────────────────────────────────────────────────────────

    /**
     * Le tour d'ancrage — SANS AUCUNE LECTURE DE LA BOUCLE. (TASK-1621)
     *
     * « Pour / Contre » repond depuis les connaissances generales du modele.
     * C'est une DECISION PRODUIT, pas une limitation : consulter les Dossiers
     * reste la fonctionnalite documentaire separee, et la melanger ici aurait
     * rendu le module lent et son resultat difficile a expliquer.
     *
     * Le `ContextBuilder` n'est donc pas appele. L'etape le DIT, avec le code
     * qui existe deja et que le mode `ia` emploie pour la meme raison
     * (`ChatLoopAiService`) : `LLM_PATH_NO_CONTEXT_BUILDER`. Aucun vocabulaire
     * nouveau, et surtout aucune trace qui laisserait croire a un retrieval.
     *
     * La borne est VIDE, et l'objet ne pretend rien : `hasRetrieval = false`.
     */
    private function ancrageSansRetrieval(
        Organization $organization,
        Loop $loop,
        User $requester,
        string $question,
        string $locale,
        string $correlationId,
        CapabilityDefinition $definition,
        ?string $executionPath,
    ): SharedEvidence {
        $contexte = new ContexteIa(
            organizationId: (string) $organization->id,
            userId: (string) $requester->id,
            loopId: (string) $loop->id,
            locale: $locale,
            capability: $definition->id,
            correlationId: $correlationId,
            query: $question,
        );

        AiTurnTrace::identity($contexte->organizationId, $contexte->turnId, array_filter([
            'surface' => 'loop_chat',
            'mode' => 'pour_contre',
            'execution_path' => $executionPath,
            'capability' => $definition->id,
            'producer' => self::PRODUCER,
        ], static fn ($valeur): bool => $valeur !== null));

        AiTurnTrace::step(
            $contexte->organizationId,
            $contexte->turnId,
            'context_builder',
            'bypassed',
            AiTurnReason::CONTEXT_BUILDER_LLM_PATH_NO_CONTEXT_BUILDER,
        );

        return new SharedEvidence(
            borne: new ContexteBorne(text: '', provenance: [], charBudget: 0, sourcesUsed: [], sourcesDenied: []),
            turnId: $contexte->turnId,
            correlationId: $correlationId,
            fingerprint: SharedEvidence::fingerprintFor(
                (string) $organization->id, (string) $loop->id, (string) $requester->id, $question, [],
            ),
            hasRetrieval: false,
        );
    }

    /**
     * Le couple provider/instance du TENANT, resolu une fois.
     *
     * Un echec ici arrete le tour ENTIER, et c'est juste : sans credential
     * d'Organization, aucun des trois assistants ne peut rien faire. Ce n'est
     * pas une panne d'assistant, c'est une absence de configuration.
     */
    private function resolveBaseModel(
        string $capability,
        SharedEvidence $evidence,
        Organization $organization,
        Loop $loop,
        User $requester,
        CapabilityDefinition $definition,
    ): ResolvedModel {
        $contexte = new ContexteIa(
            organizationId: (string) $organization->id,
            userId: (string) $requester->id,
            loopId: (string) $loop->id,
            locale: $this->localeDeReference($organization),
            capability: $capability,
            correlationId: $evidence->correlationId,
            turnId: $evidence->turnId,
        );

        try {
            return $this->providers->resolve($capability, $contexte);
        } catch (DomainException $exception) {
            AiTurnTrace::step($contexte->organizationId, $evidence->turnId, 'provider_resolution', 'denied', AiTurnReason::REFUSED_NOT_CONFIGURED);

            throw AiRefusedException::notConfigured($exception);
        }
    }

    // ────────────────────────────────────────────────────────────────────────
    // Etape 2 — un assistant
    // ────────────────────────────────────────────────────────────────────────

    /**
     * UN assistant, du choix de son modele a sa ligne de trace.
     *
     * Ne leve jamais : tout ce qui tourne mal devient un `AssistantOutcome`
     * (invariant 3). Le `catch (\Throwable)` final est volontairement large —
     * ce qui doit etre garanti, c'est que le voisin suivant s'execute, quelle
     * que soit la nature de la panne.
     */
    private function runAssistant(
        string $key,
        string $persona,
        string $socle,
        string $question,
        SharedEvidence $evidence,
        ResolvedModel $base,
        Organization $organization,
        Loop $loop,
        User $requester,
        CapabilityDefinition $definition,
        string $locale,
        ?int $doctrineVersion,
        ?string $executionPath,
    ): AssistantOutcome {
        // Le tour existe AVANT tout refus : un refus est un tour, pas un vide.
        $turnId = (string) Str::uuid();
        $organizationId = (string) $organization->id;

        AiTurnTrace::identity($organizationId, $turnId, array_filter([
            'surface' => 'loop_chat',
            'mode' => 'multi_ai',
            'execution_path' => $executionPath,
            'capability' => $definition->id,
            'producer' => self::PRODUCER,
            'assistant_key' => $key,
        ], static fn ($value): bool => $value !== null));

        try {
            // ── Le modele de CET assistant. ──────────────────────────────────
            $raison = null;
            $slug = $this->modelGuard->eligibleSlug($key, $raison);

            if ($slug === null) {
                AiTurnTrace::step($organizationId, $turnId, 'model_eligibility', 'denied', (string) $raison, ['assistant_key' => $key]);

                $this->recordNonGenerativeTurn($loop, $requester, $evidence, $definition, $turnId, $key, null,
                    'model_eligibility', AiTurnReason::REFUSED_UNAVAILABLE, (string) $raison, $doctrineVersion);

                return AssistantOutcome::refused($key, (string) $raison, $turnId);
            }

            // L'ecart est ICI, et il est total : le provider, l'instance SDK et
            // donc le credential restent ceux de l'Organization ; seul le
            // MODELE devient celui que la plateforme a prouve gratuit. Tout ce
            // qui suit — garde economique, appel SDK, ledger, trace — lit ce
            // `ResolvedModel`-la et aucun autre. Le modele par defaut de
            // l'Organization ne doit reapparaitre nulle part en aval.
            $resolved = $this->effectiveModel($base, $slug);

            AiTurnTrace::step($organizationId, $turnId, 'model_eligibility', 'executed', null, [
                'assistant_key' => $key,
            ]);

            // ── La garde economique, par assistant. ─────────────────────────
            //
            // Trois assistants = trois generations = trois autorisations. Une
            // seule autorisation pour le tour laisserait passer la deuxieme et
            // la troisieme apres que le budget a ete atteint par la premiere.
            $verdict = $this->economicGuard->authorize(
                $organization,
                $definition->process,
                $resolved->provider,
                $resolved->model,
                (float) config('ai.multi_ai.economic_guard.monthly_budget_usd', 2.00),
                (int) config('ai.multi_ai.economic_guard.monthly_unknown_limit', 10),
                $requester,
            );

            if (! $verdict->allowed) {
                // `reason` est nullable au type ; un refus sans code serait un
                // refus qu'on ne saurait pas nommer. On retombe sur le code
                // d'indisponibilite du registre plutot que d'ecrire `null` la
                // ou un lecteur attend une raison.
                $raisonEconomique = $verdict->reason ?? AiTurnReason::REFUSED_UNAVAILABLE;

                AiTurnTrace::step($organizationId, $turnId, 'economic_check', 'denied', $raisonEconomique, ['assistant_key' => $key]);

                $this->recordNonGenerativeTurn($loop, $requester, $evidence, $definition, $turnId, $key, $resolved,
                    'economic_check', $raisonEconomique, $raisonEconomique, $doctrineVersion);

                return AssistantOutcome::refused($key, $raisonEconomique, $turnId);
            }

            AiTurnTrace::step($organizationId, $turnId, 'economic_check', 'executed', null, ['assistant_key' => $key]);

            return $this->generate($key, $persona, $socle, $question, $evidence, $resolved, $organization, $loop,
                $requester, $definition, $locale, $doctrineVersion, $turnId);
        } catch (\Throwable $exception) {
            // Invariant 3. Rien de ce qui arrive a un assistant ne doit
            // empecher le suivant de s'executer — y compris une panne qu'on
            // n'avait pas prevue. C'est la raison de la largeur de ce catch.
            AiTurnTrace::step($organizationId, $turnId, 'assistant', 'failed', AiTurnReason::TERMINAL_PROVIDER_CALL_FAILED, [
                'assistant_key' => $key,
            ]);

            return AssistantOutcome::error($key, AiTurnReason::TERMINAL_PROVIDER_CALL_FAILED, $turnId);
        }
    }

    /**
     * Le modele EFFECTIF de l'assistant.
     *
     * `provider` et `instance` sont recopies tels quels — ce sont eux qui
     * portent le credential du tenant, et les changer serait changer de
     * facturation. Seul `model` bascule sur le slug prouve gratuit.
     */
    private function effectiveModel(ResolvedModel $base, string $slug): ResolvedModel
    {
        return new ResolvedModel($base->provider, $slug, $base->instance);
    }

    /**
     * L'appel reel, sa trace et sa ligne de ledger.
     */
    private function generate(
        string $key,
        string $persona,
        string $socle,
        string $question,
        SharedEvidence $evidence,
        ResolvedModel $resolved,
        Organization $organization,
        Loop $loop,
        User $requester,
        CapabilityDefinition $definition,
        string $locale,
        ?int $doctrineVersion,
        string $turnId,
    ): AssistantOutcome {
        $organizationId = (string) $organization->id;

        // ── La hierarchie de prompt, et son dernier rang. ───────────────────
        //
        // `compose()` a deja empile, dans cet ordre : gardes en code >
        // Constitution plateforme > Constitution de l'Organization > doctrine >
        // instructions de capability. La PERSONA vient apres, et seulement
        // apres : c'est un texte redige dans une Boucle, par un animateur, et
        // il ne doit pouvoir ni remplacer ni annuler ce qui le precede. Le
        // placer en dernier rang n'est pas un detail de mise en page — c'est
        // ce qui fait qu'une persona hostile ne devient qu'un ton.
        $instructions = AssistantInstructions::compose(
            $socle,
            trans('ai.loop_multi_ai_persona_header', ['assistant' => $this->assistants->label($key)]),
            $persona,
        );

        // Le rang de la persona, MESURE et non affirme. Les deux tailles se
        // lisent en production : le socle ne doit jamais retrecir quand une
        // Boucle ecrit une posture — s'il retrecissait, c'est qu'elle aurait
        // remplace quelque chose au lieu de s'y ajouter.
        AiTurnTrace::step($organizationId, $turnId, 'prompt_composition', 'executed', null, [
            'assistant_key' => $key,
            'socle_chars' => mb_strlen($socle),
            'instructions_chars' => mb_strlen($instructions),
        ]);

        $agent = new LoopMultiAiAgent(
            $instructions,
            (int) config('ai.multi_ai.max_tokens', 900),
            (float) config('ai.multi_ai.temperature', 0.3),
        );

        $prompt = $this->prompt($question, $locale);
        $startedAt = microtime(true);

        try {
            $response = $agent->prompt($prompt, provider: $resolved->instance, model: $resolved->model);
        } catch (\Throwable $exception) {
            // TASK-1619 — la SATURATION se distingue de la panne. Le pool
            // gratuit partage d'OpenRouter rend 429 regulierement : c'est le
            // cas nominal d'un palier gratuit, pas un incident. Et c'est le
            // SEUL echec dont le remede soit « reessayer dans un instant ».
            $sature = $exception instanceof RateLimitedException;

            AiTurnTrace::step($organizationId, $turnId, 'provider_call', 'failed', AiTurnReason::TERMINAL_PROVIDER_CALL_FAILED, [
                'assistant_key' => $key,
                'rate_limited' => $sature,
            ]);

            // L'appel EST parti : il a sa ligne au ledger, comme partout
            // ailleurs dans le depot. Un echec provider n'est pas un refus.
            $this->ledger->recordGeneration(
                organizationId: $organizationId,
                userId: (string) $requester->id,
                capability: $definition->id,
                process: $definition->process,
                resolved: $resolved,
                usage: AiUsage::notObserved(),
                cost: null,
                status: 'failed',
                correlationId: $evidence->correlationId,
                sdkInvocationId: null,
                failureReason: $exception::class,
                startedAtMicrotime: $startedAt,
                feature: self::FEATURE_PREFIX.$key,
            );

            $this->recordGenerativeTurn($loop, $requester, $evidence, $definition, $resolved, $turnId, $key,
                $prompt, null, AiUsage::notObserved(), ['cost_usd' => null, 'cost_unknown' => null],
                'failed', $startedAt, null, $exception::class, AiTurnReason::TERMINAL_PROVIDER_CALL_FAILED,
                $doctrineVersion);

            return $sature
                ? AssistantOutcome::rateLimited($key, $turnId, $resolved->model)
                : AssistantOutcome::error($key, AiTurnReason::TERMINAL_PROVIDER_CALL_FAILED, $turnId, $resolved->model);
        }

        AiTurnTrace::step($organizationId, $turnId, 'provider_call', 'executed', null, ['assistant_key' => $key]);

        $answer = AiMarkdownSanitizer::sanitize(
            (string) $response->text,
            (int) config('ai.multi_ai.max_answer_chars', 3000),
        );

        $usage = AiUsage::fromSdkTextTokens($response->usage->promptTokens, $response->usage->completionTokens);

        // Un modele prouve gratuit rend un cout CONNU a 0.0 — jamais un cout
        // inconnu. La distinction est celle de TASK-1617 : « gratuit » est une
        // mesure, « inconnu » est une absence de mesure, et elles ne se
        // comptent pas dans les memes colonnes.
        $cost = $this->economicGuard->finalize($resolved->provider, $resolved->model, $usage);

        $this->ledger->recordGeneration(
            organizationId: $organizationId,
            userId: (string) $requester->id,
            capability: $definition->id,
            process: $definition->process,
            resolved: $resolved,
            usage: $usage,
            cost: $cost,
            status: $answer === '' ? 'failed' : 'completed',
            correlationId: $evidence->correlationId,
            sdkInvocationId: $response->invocationId,
            failureReason: $answer === '' ? AiTurnReason::TERMINAL_EMPTY_MODEL_ANSWER : null,
            startedAtMicrotime: $startedAt,
            feature: self::FEATURE_PREFIX.$key,
        );

        if ($answer === '') {
            AiTurnTrace::step($organizationId, $turnId, 'generation', 'failed', AiTurnReason::TERMINAL_EMPTY_MODEL_ANSWER, [
                'assistant_key' => $key,
            ]);

            $this->recordGenerativeTurn($loop, $requester, $evidence, $definition, $resolved, $turnId, $key,
                $prompt, null, $usage, $cost->traceAttributes(), 'failed', $startedAt, $response->invocationId,
                null, AiTurnReason::TERMINAL_EMPTY_MODEL_ANSWER, $doctrineVersion);

            return AssistantOutcome::error($key, AiTurnReason::TERMINAL_EMPTY_MODEL_ANSWER, $turnId, $resolved->model);
        }

        AiTurnTrace::step($organizationId, $turnId, 'generation', 'executed', null, ['assistant_key' => $key]);

        $this->recordGenerativeTurn($loop, $requester, $evidence, $definition, $resolved, $turnId, $key,
            $prompt, $answer, $usage, $cost->traceAttributes(), 'completed', $startedAt, $response->invocationId,
            null, null, $doctrineVersion);

        return AssistantOutcome::success($key, $answer, [], $turnId, $resolved->model);
    }

    /**
     * Le prompt utilisateur : LA QUESTION. (TASK-1621)
     *
     * Rien d'autre. Pas de bloc de sources, pas meme la phrase qui annoncait
     * leur absence : le module « Pour / Contre » a DECIDE de ne pas lire la
     * Boucle, et un prompt qui parlerait de sources — meme pour dire qu'il
     * n'y en a pas — laisserait croire qu'on a cherche.
     *
     * Le role (defendre / contester) vit dans les instructions systeme, pas
     * ici : il ne doit pas pouvoir etre confondu avec une demande de
     * l'utilisateur.
     */
    private function prompt(string $question, string $locale): string
    {
        return trans('ai.loop_knowledge_member_question', [], $locale)."\n".$question;
    }

    // TASK-1621 — `separerLesFollowUps()` a ete RETIREE avec la
    // fonctionnalite. « Pour / Contre » rend deux regards courts ; des
    // questions d'approfondissement sous chacun d'eux auraient rallonge la
    // reponse (donc le budget de sortie, deja juste) pour une action que la
    // V0 ne propose pas.

    // ────────────────────────────────────────────────────────────────────────
    // Les traces
    // ────────────────────────────────────────────────────────────────────────

    /**
     * Le tour d'un assistant qui a REELLEMENT appele le provider.
     *
     * @param  array<string, mixed>  $costAttributes
     */
    private function recordGenerativeTurn(
        Loop $loop,
        User $requester,
        SharedEvidence $evidence,
        CapabilityDefinition $definition,
        ResolvedModel $resolved,
        string $turnId,
        string $key,
        string $prompt,
        ?string $response,
        AiUsage $usage,
        array $costAttributes,
        string $status,
        float $startedAt,
        ?string $sdkInvocationId,
        ?string $failure,
        ?string $reasonCode,
        ?int $doctrineVersion,
    ): AiInteraction {
        $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);

        return AiInteraction::create([
            'user_id' => $requester->id,
            'organization_id' => $loop->organization_id,
            'correlation_id' => $evidence->correlationId,
            'process' => $definition->process,
            // La capability, canonique — pas `loop_multi_ai:aperio`. Cette
            // colonne est lue par des compteurs qui ne connaissent que les
            // identifiants du registre ; l'identite de l'assistant vit dans
            // `metadata.assistant_key` et dans `feature` au ledger.
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
                'latency_ms' => $latencyMs,
                'provider' => $resolved->provider,
                'capability' => $definition->id,
                'plugin' => LoopAiAssistants::PLUGIN,
                'assistant_key' => $key,
                'status' => $status,
                'sdk_invocation_id' => $sdkInvocationId,
                'turn_id' => $turnId,
                'failure' => $failure,
                'knowledge' => $this->blocConnaissance($evidence),
                AiTurnTrace::TURN_METADATA_KEY => AiTurnTrace::compose(
                    $turnId,
                    AiTurnTrace::claim((string) $loop->organization_id, $turnId),
                    [
                        'status' => $status === 'failed' ? AiTurnState::TURN_FAILED : AiTurnState::TURN_ANSWERED,
                        'stage' => $status === 'failed' ? 'generation' : null,
                        'reason_code' => $reasonCode,
                        'decided_by' => class_basename(self::class),
                        'latency_ms' => $latencyMs,
                    ],
                ),
            ], static fn ($value): bool => $value !== null)
                + ['doctrine_version' => $doctrineVersion],
        ]);
    }

    /**
     * Le tour d'un assistant qui n'a RIEN appele — invariant 2.
     *
     * `metadata.status` vaut `refused`, qui appartient a
     * `AiTurnState::NON_GENERATIVE_STATUSES` : cette ligne est donc exclue par
     * tous les lecteurs economiques. Elle ne coute rien et ne compte pour
     * rien — mais elle existe, et elle nomme sa raison.
     */
    private function recordNonGenerativeTurn(
        Loop $loop,
        User $requester,
        SharedEvidence $evidence,
        CapabilityDefinition $definition,
        string $turnId,
        string $key,
        ?ResolvedModel $resolved,
        string $stage,
        string $reasonCode,
        string $refusalReason,
        ?int $doctrineVersion,
    ): AiInteraction {
        return AiInteraction::create([
            'user_id' => $requester->id,
            'organization_id' => $loop->organization_id,
            'correlation_id' => $evidence->correlationId,
            'process' => $definition->process,
            'feature' => $definition->id,
            'model' => $resolved?->trace() ?? '',
            'prompt' => '',
            'response' => null,
            'input_tokens' => 0,
            'output_tokens' => 0,
            'cost_usd' => 0,
            'cost_unknown' => false,
            'metadata' => array_filter([
                'loop_id' => $loop->id,
                'requested_by' => $requester->id,
                'provider' => $resolved?->provider,
                'capability' => $definition->id,
                'plugin' => LoopAiAssistants::PLUGIN,
                'assistant_key' => $key,
                'status' => AiTurnState::TURN_REFUSED,
                'turn_id' => $turnId,
                // Le code PRODUIT exact, qui n'est pas celui du registre des
                // tours : `MODEL_UNAVAILABLE_OR_NOT_FREE` dit POURQUOI, la ou
                // `REFUSED_UNAVAILABLE` dit seulement que c'est indisponible.
                // Les deux voyagent, a leur place respective.
                'refusal_reason' => $refusalReason,
                'knowledge' => $this->blocConnaissance($evidence),
                AiTurnTrace::TURN_METADATA_KEY => AiTurnTrace::compose(
                    $turnId,
                    AiTurnTrace::claim((string) $loop->organization_id, $turnId),
                    [
                        'status' => AiTurnState::TURN_REFUSED,
                        'stage' => $stage,
                        'reason_code' => $reasonCode,
                        'decided_by' => class_basename(self::class),
                    ],
                ),
            ], static fn ($value): bool => $value !== null)
                + ['doctrine_version' => $doctrineVersion],
        ]);
    }

    /**
     * Ce que chaque tour dit de SA CONNAISSANCE. (TASK-1621)
     *
     * Il n'y a plus d'Evidence, donc plus de bloc `evidence` : ni
     * `retrieval = reused`, ni `reason = evidence_shared`, ni `source_turn_id`.
     * Ecrire ces cles alors que le produit a explicitement renonce au RAG
     * aurait fait mentir la trace a l'endroit meme ou elle sert a verifier.
     *
     * Une cle ABSENTE se lit « rien ici » ; une cle presente et fausse se lit
     * comme une mesure. C'est toute la difference.
     *
     * @return array<string, mixed>
     */
    private function blocConnaissance(SharedEvidence $ancrage): array
    {
        return [
            'mode' => 'general_knowledge',
            'context_builder' => 'bypassed',
            'reason' => AiTurnReason::CONTEXT_BUILDER_LLM_PATH_NO_CONTEXT_BUILDER,
            'correlation_turn_id' => $ancrage->turnId,
        ];
    }

    // ────────────────────────────────────────────────────────────────────────
    // Gardes et lectures
    // ────────────────────────────────────────────────────────────────────────

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

    private function localeDeReference(Organization $organization): string
    {
        $locale = trim((string) $organization->locale);

        return $locale !== '' ? $locale : (string) config('app.fallback_locale', 'fr');
    }
}
