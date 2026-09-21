<?php

namespace App\Services\Ai;

use App\Ai\Agents\LoopMultiAiAgent;
use App\Ai\CapabilityDefinition;
use App\Ai\CapabilityRegistry;
use App\Ai\Context\ContextBuilder;
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
 * L'orchestration SEQUENTIELLE des trois assistants. (TASK-1618 / SLICE D)
 *
 * Un tour = UN build de preuves, puis TROIS generations qui le partagent, dans
 * l'ordre fixe Aperio -> Traverse -> Limen. Aucune UX ici : ce service rend
 * une structure, SLICE E decidera comment elle s'affiche.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * Les trois invariants que ce fichier doit tenir, et ou ils se lisent
 * ────────────────────────────────────────────────────────────────────────────
 *
 * 1. L'EMPREINTE NE REMPLACE AUCUN CONTROLE. `SharedEvidence::fingerprintFor()`
 *    repond a une seule question — « ces preuves-la peuvent-elles servir a
 *    cette demande-la ? ». Elle n'autorise rien. Les autorisations reelles
 *    sont, dans l'ordre : `assertCanRequest()` (appartenance active + meme
 *    Organization), `LoopPluginActivation::isEnabled()` (qui reconfronte la
 *    disponibilite de l'Organization DE LA BOUCLE a chaque lecture, garde
 *    TASK-1616), et enfin `LoopMessagesSource` qui refuse elle-meme une Boucle
 *    d'un autre tenant. Une empreinte identique sur deux demandes mal
 *    autorisees ne ferait donc rien passer : les trois gardes sont AVANT elle.
 *
 * 2. UN REFUS AVANT LE PROVIDER NE COUTE RIEN ET SE VOIT QUAND MEME. Modele
 *    ineligible, budget atteint : aucune `AiProviderInvocation` n'est ecrite —
 *    il n'y a rien a facturer. Mais une `AiInteraction` NON GENERATIVE l'est,
 *    portant `assistant_key`, `turn_id`, `correlation_id` et la raison exacte.
 *    C'est la difference entre « n'a rien coute » et « n'a pas eu lieu ».
 *
 * 3. UN ASSISTANT QUI TOMBE NE FAIT PAS TOMBER LES SUIVANTS. Chaque assistant
 *    est execute dans son propre `try`, et son echec devient un
 *    `AssistantOutcome` — jamais une exception qui remonte. Aperio SUCCESS /
 *    Traverse ERROR / Limen SUCCESS est un resultat NORMAL, pas un accident
 *    rattrape.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * Ce que ce service ne fait PAS, et pourquoi
 * ────────────────────────────────────────────────────────────────────────────
 *
 * Il n'appelle JAMAIS `LoopKnowledgeAnswerService`. La tentation etait forte —
 * ce moteur sait construire un contexte — mais `answer()` GENERE : l'appeler
 * pour ses preuves paierait une quatrieme generation invisible, hors ledger du
 * plugin, hors trace, et sur le modele de l'Organization. Les preuves se
 * construisent donc par `ContextBuilder::build()`, qui ne genere rien : c'est
 * precisement pour cela qu'il existe separement.
 *
 * Il ne publie rien dans le fil. Un assistant experimental qui ecrirait dans
 * ChatLoop sans qu'un humain l'ait demande serait une publication autonome.
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

    /** La raison portee par la metadonnee des trois tours d'assistant. */
    public const EVIDENCE_REUSED = 'evidence_shared';

    /** TASK-1619 — au plus trois suggestions, comme partout ailleurs (T1595). */
    public const FOLLOW_UP_LIMIT = 3;

    /**
     * L'assistant qui SYNTHETISE. Limen, et c'est sa posture de catalogue qui
     * le designe : « comparer les positions, distinguer accords et desaccords
     * [...] sans decider a la place du groupe » (TASK-1616). Le choix n'est
     * donc pas arbitraire — il aurait ete etrange de faire synthetiser celui
     * dont le role est de chercher les objections.
     */
    public const SYNTHESISER = 'limen';

    public function __construct(
        private CapabilityRegistry $capabilities,
        private ProviderResolver $providers,
        private ContextBuilder $contextBuilder,
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
    public function run(Loop $loop, User $requester, string $question, ?string $executionPath = null): MultiAssistantRun
    {
        return $this->execute($loop, $requester, $question, null, $executionPath);
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

    /**
     * La SYNTHESE de Limen. (TASK-1619)
     *
     * Limen relit ce que les autres ont repondu et en tire une comparaison.
     * C'est la SEULE lecture d'une IA par une autre dans tout le plugin, et
     * elle n'a lieu que parce qu'un humain a clique : rien ne la declenche a
     * sa place, ni la fin d'un tour, ni l'arrivee d'une reponse, ni un
     * compteur. « Demander aux 3 » ne la lance pas — les trois y repondent en
     * PAIRS, aucun ne lisant les autres (contrat SLICE D, inchange).
     *
     * La distinction tient a une chose : une IA qui relit une IA sans qu'on
     * le lui demande est une conversation entre machines, et personne ne l'a
     * decidee. Ici, quelqu'un l'a decidee.
     *
     * Les reponses relues sont passees comme MATIERE — delimitees, annoncees
     * comme des propos tenus. Elles n'ont pas rang d'instruction : une reponse
     * qui contiendrait « ignore tes regles » resterait du texte cite.
     *
     * @param  list<array{assistant: string, answer: string}>  $reponses
     */
    public function synthesise(Loop $loop, User $requester, string $question, array $reponses, ?string $executionPath = null): MultiAssistantRun
    {
        if ($reponses === []) {
            throw new RuntimeException(__('loops.plugins_multi_ai_nothing_to_synthesise'));
        }

        return $this->execute(
            $loop, $requester, $question, [self::SYNTHESISER], $executionPath,
            $this->matiereDeSynthese($reponses, $this->localeDeReference($loop->organization()->firstOrFail())),
        );
    }

    /**
     * Le bloc des reponses a comparer, delimite.
     *
     * Meme precaution que la persona : ce qui vient d'ailleurs est ANNONCE et
     * BORNE. La difference est que ce texte-ci a ete produit par un modele,
     * donc qu'il peut contenir n'importe quoi — y compris une phrase qui
     * ressemble a une consigne.
     *
     * @param  list<array{assistant: string, answer: string}>  $reponses
     */
    private function matiereDeSynthese(array $reponses, string $locale): string
    {
        $blocs = [];

        foreach ($reponses as $reponse) {
            $cle = (string) ($reponse['assistant'] ?? '');
            $texte = trim((string) ($reponse['answer'] ?? ''));

            if ($cle === '' || $texte === '') {
                continue;
            }

            $blocs[] = '### '.$this->assistants->label($cle)."\n".$texte;
        }

        return trans('ai.loop_multi_ai_synthesis_material', [], $locale)
            ."\n<<<REPONSES\n".implode("\n\n", $blocs)."\nREPONSES";
    }

    /**
     * @param  list<string>|null  $seulement  null = tous les assistants actifs
     */
    private function execute(Loop $loop, User $requester, string $question, ?array $seulement, ?string $executionPath, ?string $matiere = null): MultiAssistantRun
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
            fn (): MultiAssistantRun => $this->runUnderLock($loop, $requester, $question, $seulement, $executionPath, $matiere),
        );
    }

    /**
     * @param  list<string>|null  $seulement
     */
    private function runUnderLock(Loop $loop, User $requester, string $question, ?array $seulement, ?string $executionPath, ?string $matiere = null): MultiAssistantRun
    {
        $capability = CapabilityRegistry::LOOP_MULTI_AI;
        $definition = $this->capabilities->get($capability);
        $this->capabilities->assertScopeAllowed($capability, CapabilityRegistry::SCOPE_LOOP);

        $organization = $loop->organization()->firstOrFail();
        $locale = $this->localeDeReference($organization);

        // C1 — UNE correlation pour toute l'operation. C'est elle qui rend les
        // quatre tours (E1, A1, T1, L1) lisibles comme un seul acte.
        $correlationId = AiCorrelation::id();

        // ── Etape 1 : les preuves, UNE fois. ────────────────────────────────
        $evidence = $this->buildEvidence(
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
        $premier = true;

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
                $matiere,
                $evidence,
                $base,
                $organization,
                $loop,
                $requester,
                $definition,
                $locale,
                $doctrineVersion,
                $executionPath,
                $premier,
            );

            $premier = false;
        }

        return new MultiAssistantRun($correlationId, $evidence, $outcomes);
    }

    // ────────────────────────────────────────────────────────────────────────
    // Etape 1 — les preuves
    // ────────────────────────────────────────────────────────────────────────

    /**
     * E1 — le tour qui construit REELLEMENT les preuves.
     *
     * Il a sa propre identite parce qu'il fait un travail propre : la
     * collecte. Les trois assistants la reutiliseront en la CITANT
     * (`source_turn_id`), jamais en se l'attribuant.
     *
     * E1 n'ecrit AUCUNE `AiInteraction`. Ce n'est pas un oubli : la source
     * autorisee de cette capability (`loop.messages`) est une lecture SQL —
     * aucun provider n'est appele, aucun embedding n'est emis, il n'y a donc
     * rien a facturer ni a compter. Lui donner une ligne la ferait entrer dans
     * les sommes de `OrganizationAiConsumption`, qui compte les generations :
     * une ligne a zero y serait une generation de plus. Sa trace voyage donc
     * dans la metadonnee du PREMIER assistant, qui la reclame une fois.
     */
    private function buildEvidence(
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
            source: CapabilityRegistry::SOURCE_LOOP_MESSAGES,
            query: $question,
        );

        AiTurnTrace::identity($contexte->organizationId, $contexte->turnId, array_filter([
            'surface' => 'loop_chat',
            'mode' => 'multi_ai',
            'execution_path' => $executionPath,
            'capability' => $definition->id,
            'producer' => self::PRODUCER,
            'step' => 'evidence_build',
        ], static fn ($value): bool => $value !== null));

        // Le SEUL constructeur de preuves de ce chemin. `ContextBuilder` ne
        // genere rien : il collecte, borne et rend. C'est la raison pour
        // laquelle il est appele ici plutot que le moteur documentaire.
        $borne = $this->contextBuilder->build($contexte, $definition);

        AiTurnTrace::step($contexte->organizationId, $contexte->turnId, 'context_builder', 'executed', null, [
            'consulted' => count($borne->provenance),
        ]);

        return new SharedEvidence(
            borne: $borne,
            turnId: $contexte->turnId,
            correlationId: $correlationId,
            fingerprint: SharedEvidence::fingerprintFor(
                (string) $organization->id,
                (string) $loop->id,
                (string) $requester->id,
                $question,
                $borne->sourcesUsed,
            ),
            hasRetrieval: $borne->provenance !== [],
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
        ?string $matiere,
        SharedEvidence $evidence,
        ResolvedModel $base,
        Organization $organization,
        Loop $loop,
        User $requester,
        CapabilityDefinition $definition,
        string $locale,
        ?int $doctrineVersion,
        ?string $executionPath,
        bool $portelaTraceDuBuild,
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
                    'model_eligibility', AiTurnReason::REFUSED_UNAVAILABLE, (string) $raison, $doctrineVersion, $portelaTraceDuBuild);

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
                    'economic_check', $raisonEconomique, $raisonEconomique, $doctrineVersion, $portelaTraceDuBuild);

                return AssistantOutcome::refused($key, $raisonEconomique, $turnId);
            }

            AiTurnTrace::step($organizationId, $turnId, 'economic_check', 'executed', null, ['assistant_key' => $key]);

            return $this->generate($key, $persona, $socle, $question, $matiere, $evidence, $resolved, $organization, $loop,
                $requester, $definition, $locale, $doctrineVersion, $turnId, $portelaTraceDuBuild);
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
        ?string $matiere,
        SharedEvidence $evidence,
        ResolvedModel $resolved,
        Organization $organization,
        Loop $loop,
        User $requester,
        CapabilityDefinition $definition,
        string $locale,
        ?int $doctrineVersion,
        string $turnId,
        bool $portelaTraceDuBuild,
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

        $prompt = $this->prompt($evidence, $question, $matiere, $locale);
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
                $doctrineVersion, $portelaTraceDuBuild);

            return $sature
                ? AssistantOutcome::rateLimited($key, $turnId, $resolved->model)
                : AssistantOutcome::error($key, AiTurnReason::TERMINAL_PROVIDER_CALL_FAILED, $turnId, $resolved->model);
        }

        AiTurnTrace::step($organizationId, $turnId, 'provider_call', 'executed', null, ['assistant_key' => $key]);

        // TASK-1619 — la reponse ET ses questions d'approfondissement sortent
        // du MEME tour provider. Le precedent est TASK-1595 : une question
        // suggeree ne vaut pas une generation de plus, et deux appels
        // rendraient des suggestions qui ne parlent pas de la reponse rendue.
        [$texte, $followUps] = $this->separerLesFollowUps((string) $response->text, $locale);

        $answer = AiMarkdownSanitizer::sanitize(
            $texte,
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
                null, AiTurnReason::TERMINAL_EMPTY_MODEL_ANSWER, $doctrineVersion, $portelaTraceDuBuild);

            return AssistantOutcome::error($key, AiTurnReason::TERMINAL_EMPTY_MODEL_ANSWER, $turnId, $resolved->model);
        }

        AiTurnTrace::step($organizationId, $turnId, 'generation', 'executed', null, ['assistant_key' => $key]);

        $this->recordGenerativeTurn($loop, $requester, $evidence, $definition, $resolved, $turnId, $key,
            $prompt, $answer, $usage, $cost->traceAttributes(), 'completed', $startedAt, $response->invocationId,
            null, null, $doctrineVersion, $portelaTraceDuBuild);

        return AssistantOutcome::success($key, $answer, $evidence->borne->provenance, $turnId, $resolved->model, $followUps);
    }

    /**
     * Le prompt utilisateur : les preuves partagees, puis la question.
     *
     * Identique pour les trois — c'est tout le sens du partage. Ce qui les
     * distingue est en amont (la persona) et en aval (le modele).
     */
    private function prompt(SharedEvidence $evidence, string $question, ?string $matiere, string $locale): string
    {
        $sources = $evidence->borne->text !== ''
            ? $evidence->borne->text
            : trans('ai.loop_multi_ai_no_sources', [], $locale);

        // TASK-1619 — la matiere de SYNTHESE, quand un humain l'a demandee.
        // Elle s'insere entre les preuves et la question, au meme rang qu'un
        // extrait de conversation : ce sont des propos tenus, pas des
        // instructions. Le bloc est delimite et annonce comme tel.
        $sources .= $matiere === null ? '' : "\n\n".$matiere;

        return $sources."\n\n".trans('ai.loop_knowledge_member_question', [], $locale)."\n".$question
            ."\n\n".trans('ai.loop_multi_ai_follow_ups_instruction', [
                'heading' => trans('dossiers.answer_follow_ups_heading', [], $locale),
                'limit' => self::FOLLOW_UP_LIMIT,
            ], $locale);
    }

    /**
     * Separer la reponse de sa section « Pour aller plus loin ».
     *
     * Meme forme que `DossierInsightsService::splitAnswer()` — deliberement :
     * le blade de ChatLoop lit deja `follow_up_questions` avec cette forme-la
     * (TASK-1595), et une seconde convention obligerait l'affichage a en
     * connaitre deux.
     *
     * Le modele peut tres bien ne pas produire la section : c'est un cas
     * NOMINAL, pas une erreur. La reponse est alors rendue telle quelle, sans
     * suggestion — jamais un second appel pour en arracher.
     *
     * @return array{0: string, 1: list<string>}
     */
    private function separerLesFollowUps(string $markdown, string $locale): array
    {
        $titre = preg_quote((string) trans('dossiers.answer_follow_ups_heading', [], $locale), '/');

        if (! preg_match('/^##\s*'.$titre.'\s*$/mu', $markdown, $trouve, PREG_OFFSET_CAPTURE)) {
            return [trim($markdown), []];
        }

        $corps = trim(substr($markdown, 0, $trouve[0][1]));
        $reste = substr($markdown, $trouve[0][1] + strlen($trouve[0][0]));

        $questions = [];

        foreach (preg_split('/\r?\n/', $reste) ?: [] as $ligne) {
            $ligne = trim($ligne);

            if ($ligne === '' || ! str_starts_with($ligne, '-')) {
                continue;
            }

            $question = trim(ltrim($ligne, "- \t"));

            if ($question === '') {
                continue;
            }

            $questions[] = $question;

            if (count($questions) >= self::FOLLOW_UP_LIMIT) {
                break;
            }
        }

        return [$corps, $questions];
    }

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
        bool $portelaTraceDuBuild,
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
                'evidence' => $this->blocEvidence($evidence, (string) $loop->organization_id, $portelaTraceDuBuild),
                'sources_used' => $evidence->borne->sourcesUsed,
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
        bool $portelaTraceDuBuild,
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
                'evidence' => $this->blocEvidence($evidence, (string) $loop->organization_id, $portelaTraceDuBuild),
                'sources_used' => $evidence->borne->sourcesUsed,
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
     * Ce que CHAQUE tour d'assistant dit de ses preuves.
     *
     * Les trois disent `reused` — aucun d'eux ne les a construites. Le pointeur
     * `source_turn_id` est ce qui rend la reutilisation VERIFIABLE : sans lui,
     * trois tours affirmeraient chacun un retrieval qu'aucun n'a fait.
     *
     * Le PREMIER porte en plus le tour E1 complet, reclame une seule fois : la
     * trace du build existe une fois parce que le build a eu lieu une fois.
     *
     * @return array<string, mixed>
     */
    private function blocEvidence(SharedEvidence $evidence, string $organizationId, bool $portelaTraceDuBuild): array
    {
        $bloc = [
            'retrieval' => 'reused',
            'reason' => self::EVIDENCE_REUSED,
            'source_turn_id' => $evidence->turnId,
            'source_correlation_id' => $evidence->correlationId,
            'fingerprint' => $evidence->fingerprint,
            'has_retrieval' => $evidence->hasRetrieval,
            'sources_denied' => $evidence->borne->sourcesDenied,
        ];

        if ($portelaTraceDuBuild) {
            $bloc['build_turn'] = AiTurnTrace::compose(
                $evidence->turnId,
                AiTurnTrace::claim($organizationId, $evidence->turnId),
                [
                    'status' => AiTurnState::TURN_NON_INTERACTION,
                    'stage' => 'evidence_build',
                    'decided_by' => class_basename(self::class),
                ],
            );
        }

        return $bloc;
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
