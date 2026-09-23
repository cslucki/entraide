<?php

namespace App\Services\Ai;

use App\Ai\Agents\LoopMultiAiAgent;
use App\Ai\CapabilityDefinition;
use App\Ai\CapabilityRegistry;
use App\Ai\Context\ContextBuilder;
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
use App\Models\AiProviderInvocation;
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
use Laravel\Ai\Responses\Data\FinishReason;
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
 *   - **les connaissances generales D'ABORD, la Boucle ENSUITE.** La matiere
 *     de la reponse vient du modele. La conversation recente revient en
 *     CONTEXTE — dix derniers messages, bornes au declencheur — pour deux
 *     raisons seulement : comprendre de quoi les participants parlent, et ne
 *     pas redire ce qui vient d'etre dit. Consulter les Dossiers reste la
 *     fonctionnalite documentaire separee : `loop_multi_ai` ne declare que
 *     `loop.messages`, et rien ne l'elargit ;
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
 * 1. LA TRACE NE PRETEND RIEN. Un contexte n'est pas une preuve : la trace
 *    dit `consulted`, et n'ecrit ni `evidence`, ni `retrieval = reused`, ni
 *    `sources_used` — une source « utilisee » est une source CITEE, et
 *    aucune reponse d'ici n'en cite. Une cle absente se lit « rien ici » ;
 *    une cle presente et fausse se lit comme une mesure.
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

    /**
     * Le marqueur par lequel le modele dit « cette question ne se prete pas a
     * un pour / contre ». (TASK-1621)
     *
     * Un jeton EXACT, pas une analyse : reconnaitre l'intention dans une
     * phrase libre demanderait un parser, et un parser se trompe. Le socle
     * demande ce marqueur et RIEN d'autre ; s'il n'arrive pas, le tour suit
     * son cours normal — l'absence de marqueur n'invente jamais un verdict.
     */
    public const MARQUEUR_HORS_SUJET = '[[PAS_DE_PROPOSITION]]';

    /**
     * TASK-1622 — le marqueur qui INTRODUIT une reformulation proposee, dans
     * la meme reponse que l'abstention.
     *
     * Meme idiome que le marqueur ci-dessus, et c'est deliberE : c'est le
     * SEUL idiome de contrat de prompt du depot (la rubrique `## titre` de
     * `DossierInsightsService` est traduite, donc elle ferait dependre une
     * branche de code d'une langue). Surtout, l'extension est
     * RETRO-COMPATIBLE : `str_contains` sur le marqueur d'abstention reste
     * vrai, donc les socles v3 a v6 — qui ne connaissent pas celui-ci —
     * continuent de s'abstenir exactement comme avant, sans suggestion.
     */
    public const MARQUEUR_SUGGESTION = '[[SUGGESTION]]';

    public function __construct(
        private CapabilityRegistry $capabilities,
        private ProviderResolver $providers,
        private PromptRepository $prompts,
        private AiEconomicGuard $economicGuard,
        private AiProviderInvocationLedger $ledger,
        private LoopPluginModelGuard $modelGuard,
        private LoopAiAssistants $assistants,
        private LoopPluginActivation $activation,
        private ContextBuilder $contextBuilder,
    ) {}

    /**
     * Un tour complet. Rend TOUJOURS une structure : un echec d'assistant y
     * figure, il ne se propage pas.
     *
     * Les seules exceptions qui sortent d'ici sont celles qui empechent le
     * tour ENTIER d'exister — question vide, non-membre, plugin eteint,
     * Organization sans credential. Aucune ne concerne un assistant.
     */
    public function runPourContre(Loop $loop, User $requester, string $question, ?string $executionPath = null, ?string $questionMessageId = null): MultiAssistantRun
    {
        return $this->execute($loop, $requester, $question, self::ROLES, $executionPath, $questionMessageId);
    }

    /**
     * UN seul assistant. (TASK-1619)
     *
     * C'est ce que servent les boutons individuels, « demander a une autre IA »
     * et « Reessayer ». Le chemin est EXACTEMENT celui de `runPourContre()`
     * — memes gardes, meme contexte, memes traces — restreint a un assistant :
     * un second chemin aurait derive du premier au premier correctif applique
     * d'un seul cote.
     *
     * Le contexte est recollecte pour ce tour, et c'est voulu : un reessai
     * quelques minutes plus tard doit voir la conversation TELLE QU'ELLE EST,
     * pas telle qu'elle etait.
     *
     * Avec une reserve, qui est tout l'interet de `$questionMessageId` : tant
     * que l'appelant transmet le MEME message declencheur, la borne haute est
     * la meme, donc la fenetre aussi. POUR et CONTRE tournent dans deux
     * requetes differees separees, et voient pourtant le meme instantane —
     * y compris apres que POUR a publie sa bulle.
     */
    public function runOne(Loop $loop, User $requester, string $question, string $assistantKey, ?string $executionPath = null, ?string $questionMessageId = null): MultiAssistantRun
    {
        return $this->execute($loop, $requester, $question, [$assistantKey], $executionPath, $questionMessageId);
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
    private function execute(Loop $loop, User $requester, string $question, ?array $seulement, ?string $executionPath, ?string $questionMessageId = null): MultiAssistantRun
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
            fn (): MultiAssistantRun => $this->runUnderLock($loop, $requester, $question, $seulement, $executionPath, $questionMessageId),
        );
    }

    /**
     * @param  list<string>|null  $seulement
     */
    private function runUnderLock(Loop $loop, User $requester, string $question, ?array $seulement, ?string $executionPath, ?string $questionMessageId = null): MultiAssistantRun
    {
        $capability = CapabilityRegistry::LOOP_MULTI_AI;
        $definition = $this->capabilities->get($capability);
        $this->capabilities->assertScopeAllowed($capability, CapabilityRegistry::SCOPE_LOOP);

        $organization = $loop->organization()->firstOrFail();
        $locale = $this->localeDeReference($organization);

        // C1 — UNE correlation pour toute l'operation. C'est elle qui rend les
        // quatre tours (E1, A1, T1, L1) lisibles comme un seul acte.
        $correlationId = AiCorrelation::id();

        // ── Etape 1 : le CONTEXTE du tour. Court, recent, borne. ────────────
        $evidence = $this->ancrageContextuel(
            $organization, $loop, $requester, $question, $locale, $correlationId, $definition, $executionPath, $questionMessageId,
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
    // Etape 1 — le contexte
    // ────────────────────────────────────────────────────────────────────────

    /**
     * Le CONTEXTE du tour : court, recent, borne. (TASK-1621)
     *
     * « Pour / Contre » repond depuis les connaissances generales du modele.
     * C'est la MATIERE de la reponse, et ca reste une decision produit :
     * consulter les Dossiers demeure la fonctionnalite documentaire separee,
     * et `loop_multi_ai` ne declare toujours que `loop.messages`.
     *
     * Mais repondre sans RIEN savoir de la discussion a un cout visible : les
     * deux regards redisent ce qui vient d'etre dit, et ne savent pas de quoi
     * les participants parlent. La conversation revient donc — en CONTEXTE,
     * jamais en source :
     *
     *   - fenetre COURTE (`ai.multi_ai.context_messages`, 10), pas les 30 du
     *     plafond global : comprendre le sujet, pas fabriquer un mini-RAG ;
     *   - borne haute EXCLUSIVE sur le message declencheur, qui est deja
     *     publie quand la collecte a lieu. Sans elle la question figurerait
     *     DEUX fois dans le prompt, et CONTRE — qui tourne dans une requete
     *     ulterieure — lirait la reponse de POUR ;
     *   - aucune citation attendue, donc aucune provenance affichee : ce que
     *     la trace dit, c'est `consulted`, pas `sources_used`.
     *
     * Rien n'est genere ici. Une lecture SQL, bornee par la capability.
     */
    private function ancrageContextuel(
        Organization $organization,
        Loop $loop,
        User $requester,
        string $question,
        string $locale,
        string $correlationId,
        CapabilityDefinition $definition,
        ?string $executionPath,
        ?string $questionMessageId,
    ): SharedEvidence {
        $contexte = new ContexteIa(
            organizationId: (string) $organization->id,
            userId: (string) $requester->id,
            loopId: (string) $loop->id,
            locale: $locale,
            capability: $definition->id,
            correlationId: $correlationId,
            source: CapabilityRegistry::SOURCE_LOOP_MESSAGES,
            query: $question,
            maxMessages: (int) config('ai.multi_ai.context_messages', 10),
            beforeMessageId: $questionMessageId,
        );

        AiTurnTrace::identity($contexte->organizationId, $contexte->turnId, array_filter([
            'surface' => 'loop_chat',
            'mode' => 'pour_contre',
            'execution_path' => $executionPath,
            'capability' => $definition->id,
            'producer' => self::PRODUCER,
        ], static fn ($valeur): bool => $valeur !== null));

        $borne = $this->contextBuilder->build($contexte, $definition);

        AiTurnTrace::step($contexte->organizationId, $contexte->turnId, 'context_builder', 'executed', null, [
            'consulted' => count($borne->provenance),
        ]);

        return new SharedEvidence(
            borne: $borne,
            turnId: $contexte->turnId,
            correlationId: $correlationId,
            fingerprint: SharedEvidence::fingerprintFor(
                (string) $organization->id, (string) $loop->id, (string) $requester->id, $question, [],
            ),
            // Le module ne fait pas de retrieval : il lit la conversation en
            // clair. `hasRetrieval` commande l'affichage des sources chez le
            // membre — l'allumer ferait promettre des citations qu'aucune
            // reponse ne portera.
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

        // TASK-1621 — LA CONSIGNE DE LANGUE, EN DERNIER ET EN CODE.
        //
        // Elle manquait, et la recette l'a montre : question en francais,
        // reponse en anglais. Doctrine du depot (`LoopKnowledgeAnswerService`) :
        // en DERNIER, parce que le prompt administrable est redige dans une
        // langue et ne dit rien de la langue de SORTIE — place avant, la
        // consigne serait noyee sous des paragraphes qui la contredisent par
        // leur seule langue. Et EN CODE, jamais dans `admin_ai_prompts` :
        // reecrire le prompt actif changerait le comportement de tous les
        // tenants pour une regle manquante.
        //
        // Ecart assume avec TASK-1400 (la langue appartient a l'Organization) :
        // ici c'est la langue de la QUESTION qui commande, arbitrage produit du
        // 22/09. Un debat « pour / contre » est un echange avec une personne,
        // pas un contenu relu par tout le cercle.
        $instructions .= "\n\n".trans('ai.loop_multi_ai_answer_language', [], $locale);

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

        $prompt = $this->prompt($question, $evidence->borne, $locale);
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

        // ── Le modele a-t-il FINI, ou a-t-il ete coupe ? (TASK-1621) ────────
        //
        // Le SDK le sait : la passerelle OpenRouter mappe
        // `finish_reason: "length"` vers `FinishReason::Length` et peuple
        // `$response->steps`. Ne pas lire cette information, c'est confondre
        // « il n'a rien a dire » et « il n'avait plus de place » — deux
        // diagnostics opposes, et c'est le second qui se produisait :
        // 13 des 14 tours « vides » s'arretaient EXACTEMENT au plafond.
        //
        // ABSENT N'EST PAS `Length`. Une passerelle qui ne peuple pas `steps`,
        // ou une doublure de test qui n'en fabrique pas, ne doit jamais faire
        // conclure a une troncature : sans mesure, on garde le comportement
        // d'avant. Deduire une coupe d'une absence serait inventer une mesure.
        $coupeParLeProvider = $response->steps->last()?->finishReason === FinishReason::Length;

        $brut = (string) $response->text;
        $plafondCaracteres = (int) config('ai.multi_ai.max_answer_chars', 3000);

        $answer = AiMarkdownSanitizer::sanitize($brut, $plafondCaracteres);

        // La SECONDE source de coupe, et elle est chez nous :
        // `AiMarkdownSanitizer::truncate()` rogne a `max_answer_chars` sans
        // rien dire. Ne traiter que le budget du provider aurait laisse notre
        // propre plafond publier des reponses coupees en silence — le defaut
        // corrige, revenu par l'autre porte.
        $coupeParNotrePlafond = mb_strlen($answer) >= $plafondCaracteres
            && mb_strlen(AiMarkdownSanitizer::sanitize($brut)) > mb_strlen($answer);

        $coupe = $coupeParLeProvider || $coupeParNotrePlafond;

        // ── La question se prete-t-elle a un pour / contre ? (TASK-1621) ────
        //
        // Le modele repond par un marqueur exact. On le cherche dans le texte
        // BRUT : le sanitiseur pourrait avoir mange les crochets, et une
        // detection qui depend du nettoyage serait fragile la ou elle doit
        // etre sure.
        $horsSujet = str_contains($brut, self::MARQUEUR_HORS_SUJET);

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
            // Un tour hors sujet a ABOUTI : l'appel est parti, il a repondu,
            // il est paye. `failed` le compterait comme une panne dans toutes
            // les sommes de fiabilite.
            //
            // TASK-1622 — `success`, pas `completed`.
            //
            // `AiProviderInvocation::STATUS_SUCCESS = 'success'` est le
            // domaine declare par la migration CREATRICE du ledger
            // (`// success | failed`, TASK-1220, 17/08) — un mois avant que ce
            // moteur n'existe. 17 sites d'appel sur 18 l'emploient ; celui-ci
            // etait le seul a ecrire `completed`, un mot emprunte a l'AUTRE
            // table qu'il remplit dans la meme methode (`ai_interactions`).
            // La colonne est un `varchar(10)` sans enum : rien ne l'a arrete.
            //
            // Ce que cela cassait : les lignes de ce moteur n'entraient dans
            // AUCUN filtre `status = success` — quota des couts INCONNUS
            // (`AiEconomicGuard`) et compteur de la console. Le budget USD,
            // lui, n'a jamais filtre sur le statut : il etait juste.
            //
            // Les 132 lignes historiques `completed` restent telles quelles.
            // Mesure, pas supposition : 103 sont a cout CONNU 0 (aucune somme
            // n'en change) et 29 a cout INCONNU — dont 10 appels payants du
            // banc A/B de TASK-1621, qui n'ont donc jamais consomme le quota
            // des inconnus. Les admettre retroactivement AJOUTERAIT 3
            // operations au quota du mois en cours : ne rien reecrire est ici
            // le choix conservateur, pas l'inverse.
            status: ($answer === '' && ! $horsSujet) ? 'failed' : AiProviderInvocation::STATUS_SUCCESS,
            correlationId: $evidence->correlationId,
            sdkInvocationId: $response->invocationId,
            failureReason: ($answer === '' && ! $horsSujet)
                ? ($coupeParLeProvider
                    ? AiTurnReason::TERMINAL_OUTPUT_BUDGET_EXHAUSTED
                    : AiTurnReason::TERMINAL_EMPTY_MODEL_ANSWER)
                : null,
            startedAtMicrotime: $startedAt,
            feature: self::FEATURE_PREFIX.$key,
        );

        if ($horsSujet) {
            // Le tour a ABOUTI — il a sa ligne au ledger, l'appel est paye —
            // mais il n'y a aucun camp a distribuer. `abstained`, pas
            // `failed` : afficher « n'a pas pu repondre » a quelqu'un dont la
            // question etait simplement d'une autre nature serait faux.
            AiTurnTrace::step($organizationId, $turnId, 'generation', 'abstained',
                AiTurnReason::TERMINAL_NO_DEBATABLE_PROPOSITION, ['assistant_key' => $key]);

            $this->recordGenerativeTurn($loop, $requester, $evidence, $definition, $resolved, $turnId, $key,
                $prompt, null, $usage, $cost->traceAttributes(), 'completed', $startedAt, $response->invocationId,
                null, AiTurnReason::TERMINAL_NO_DEBATABLE_PROPOSITION, $doctrineVersion);

            return AssistantOutcome::notApplicable($key, $turnId, $resolved->model, $this->suggestionDeReformulation($brut));
        }

        if ($answer === '') {
            // Rien a lire. La raison dit LAQUELLE des deux : budget brule, ou
            // modele reellement muet. Elles n'appellent pas le meme remede.
            $raison = $coupeParLeProvider
                ? AiTurnReason::TERMINAL_OUTPUT_BUDGET_EXHAUSTED
                : AiTurnReason::TERMINAL_EMPTY_MODEL_ANSWER;

            AiTurnTrace::step($organizationId, $turnId, 'generation', 'failed', $raison, [
                'assistant_key' => $key,
            ]);

            $this->recordGenerativeTurn($loop, $requester, $evidence, $definition, $resolved, $turnId, $key,
                $prompt, null, $usage, $cost->traceAttributes(), 'failed', $startedAt, $response->invocationId,
                null, $raison, $doctrineVersion);

            return AssistantOutcome::error($key, $raison, $turnId, $resolved->model);
        }

        if ($coupe) {
            // Il y a du texte, et il est incomplet. L'etape est `executed` —
            // le modele a bien repondu — mais elle porte sa degradation : un
            // `executed` nu affirmerait que le tour s'est deroule entier.
            AiTurnTrace::step($organizationId, $turnId, 'generation', 'executed', AiTurnReason::DEGRADED_OUTPUT_TRUNCATED, [
                'assistant_key' => $key,
                'truncated_by' => $coupeParLeProvider ? 'output_budget' : 'answer_char_cap',
            ]);

            $this->recordGenerativeTurn($loop, $requester, $evidence, $definition, $resolved, $turnId, $key,
                $prompt, $answer, $usage, $cost->traceAttributes(), 'completed', $startedAt, $response->invocationId,
                null, AiTurnReason::DEGRADED_OUTPUT_TRUNCATED, $doctrineVersion);

            return AssistantOutcome::partial(
                $key, $answer, AiTurnReason::DEGRADED_OUTPUT_TRUNCATED, $turnId, $resolved->model,
            );
        }

        AiTurnTrace::step($organizationId, $turnId, 'generation', 'executed', null, ['assistant_key' => $key]);

        $this->recordGenerativeTurn($loop, $requester, $evidence, $definition, $resolved, $turnId, $key,
            $prompt, $answer, $usage, $cost->traceAttributes(), 'completed', $startedAt, $response->invocationId,
            null, null, $doctrineVersion);

        return AssistantOutcome::success($key, $answer, [], $turnId, $resolved->model);
    }

    /**
     * Le prompt utilisateur : LA QUESTION, puis le contexte. (TASK-1621)
     *
     * L'ORDRE est le livrable. La question vient en premier parce que c'est
     * a elle qu'on repond ; le contexte vient apres, explicitement etiquete
     * comme contexte, et precede du contrat qui dit a quoi il sert. Inverser
     * les deux suffirait a faire croire au modele que la Boucle est la
     * matiere — c'est exactement ce qui produisait, en recette reelle,
     * « the provided Loop material says nothing about… ».
     *
     * Une Boucle sans rien a dire n'a NI contrat NI intitule : un bloc vide,
     * ou une phrase annoncant qu'il n'y a pas de contexte, inviterait le
     * modele a commenter ce vide au lieu de repondre.
     *
     * Le role (defendre / contester) vit dans les instructions systeme, pas
     * ici : il ne doit pas pouvoir etre confondu avec une demande de
     * l'utilisateur.
     */
    private function prompt(string $question, ContexteBorne $borne, string $locale): string
    {
        $contexte = trim($borne->text);

        $bloc = trans('ai.loop_knowledge_member_question', [], $locale)."\n".$question;

        if ($contexte === '') {
            return $bloc;
        }

        return trans('ai.loop_multi_ai_context_contract', [], $locale)
            ."\n\n".$bloc
            ."\n\n".trans('ai.loop_multi_ai_context_heading', [], $locale)
            ."\n".$contexte;
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
    /**
     * TASK-1622 — la reformulation proposee, extraite du texte BRUT.
     *
     * DETERMINISTE, comme le marqueur d'abstention : on coupe apres un jeton
     * EXACT, on ne lit aucune intention. Pas de marqueur, ou rien de lisible
     * derriere : `null`, et l'ecran demandera une precision. Ne jamais
     * fabriquer une suggestion a partir d'une phrase libre — ce serait le
     * parser que TASK-1621 a refuse pour l'abstention elle-meme.
     *
     * La sortie tient sur UNE ligne : c'est une question a reposer, pas un
     * paragraphe. Tout autre jeton `[[...]]` est retire — un modele qui
     * refermerait sa balise ne doit pas polluer le composeur.
     */
    private function suggestionDeReformulation(string $brut): ?string
    {
        $position = mb_strpos($brut, self::MARQUEUR_SUGGESTION);

        if ($position === false) {
            return null;
        }

        $texte = mb_substr($brut, $position + mb_strlen(self::MARQUEUR_SUGGESTION));
        $texte = (string) preg_replace('/\[\[[^\]]*\]\]/u', ' ', $texte);
        $texte = trim((string) preg_replace('/\s+/u', ' ', $texte));
        $texte = trim($texte, "-*_ \t\n\r");

        if ($texte === '') {
            return null;
        }

        // Une borne, pas une coupe esthetique : ce texte part dans le
        // composeur du membre, et `body` est plafonne a 5 000 caracteres.
        return mb_substr($texte, 0, 300);
    }

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
     * Le module lit la conversation, mais il ne s'en sert pas comme d'une
     * source : la trace doit donc dire « j'ai regarde N messages pour me
     * situer », et surtout PAS le vocabulaire de la preuve documentaire.
     *
     * Restent donc ABSENTES, et c'est le contrat de cette methode :
     * `evidence`, `retrieval = reused`, `reason = evidence_shared`,
     * `source_turn_id`, `sources_used` — une source « utilisee » est une
     * source CITEE, et aucune reponse d'ici n'en cite — et `sources_denied`,
     * qui allume un bandeau chez le membre.
     *
     * Une cle ABSENTE se lit « rien ici » ; une cle presente et fausse se lit
     * comme une mesure. C'est toute la difference.
     *
     * @return array<string, mixed>
     */
    private function blocConnaissance(SharedEvidence $ancrage): array
    {
        return [
            'mode' => 'general_knowledge_first',
            'context_builder' => 'executed',
            'consulted' => count($ancrage->borne->provenance),
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
