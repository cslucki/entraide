<?php

namespace App\Services\Dossiers;

use App\Ai\Agents\LoopKnowledgeAgent;
use App\Ai\CapabilityDefinition;
use App\Ai\CapabilityRegistry;
use App\Ai\Context\DossierSourceUrl;
use App\Ai\ContexteIa;
use App\Ai\PromptRepository;
use App\Ai\ProviderResolver;
use App\Ai\ResolvedModel;
use App\Models\AdminAiPrompt;
use App\Models\AiInteraction;
use App\Models\Dossier;
use App\Models\DossierFile;
use App\Models\Organization;
use App\Models\User;
use App\Services\Ai\AiProviderInvocationLedger;
use App\Services\Ai\DTO\KnowledgeAnswer;
use App\Support\Ai\AiCorrelation;
use App\Support\Ai\AiCost;
use App\Support\Ai\AiEconomicGuard;
use App\Support\Ai\AiMarkdownSanitizer;
use App\Support\Ai\AiRefusedException;
use App\Support\Ai\AiUsage;
use DomainException;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

/**
 * TASK-1341 — Smart Dossier V1 : « qu'est-ce qui ressort de ce Dossier, et sur
 * quels documents cela repose ? ».
 *
 * Architecture COMPRESSEE obligatoire (brief 2026-08-30) : cette classe est la
 * SEULE piece backend neuve. Elle ne cree ni capability, ni process, ni
 * Agent, ni DTO, ni migration — elle rejoue la capability
 * `loop_knowledge_answer` (meme process, meme AdminAiPrompt, meme
 * `LoopKnowledgeAgent`, meme DTO `KnowledgeAnswer`) sur une QUESTION
 * PREREGLEE, avec pour corpus le seul Dossier ouvert.
 *
 * Retrieval : `DossierSemanticSearchService::representativeChunksAcrossDossiers()`
 * (TASK-1309) — un chunk representatif par DOCUMENT, aucune recherche, aucun
 * embedding. Le Context Builder / `DossierRetrievalSource` ne sont PAS
 * reutilises ici (PREP-LIGHT 2026-08-30) : ce sont des `ContextSource` pensees
 * pour le pipeline multi-source d'un tour de chat de Boucle, un couplage
 * inutile pour un appel isole, Dossier-scope, hors Loop.
 *
 * Rien n'est persiste au-dela de la trace habituelle (ledger +
 * `AiInteraction`) : le resultat est ephemere, relu a chaque generation,
 * exactement comme la recherche semantique existante.
 *
 * ## TASK-1534 — pourquoi Smart Dossier ne lit PAS la connaissance derivee
 *
 * Les recherches lancees ICI ne transmettent aucun `authorizedLoopIds` :
 * `DerivedChunkEligibility` etant ferme par defaut, les notes derivees d'une
 * conversation sont donc exclues de ce chemin. C'est un choix, pas un oubli.
 *
 * Un Insight n'est pas une reponse a quelqu'un : c'est un artefact dont
 * l'audience est celle du DOSSIER. L'invariant du systeme nerveux est une
 * intersection — `visibilite(derive) ⊆ visibilite(Boucle) ∩ visibilite(Dossier)`
 * — et cette intersection ne se laisse pas porter par un artefact partage :
 * une synthese nourrie d'une Boucle privee puis relue par tout le cercle
 * blanchirait exactement ce que la garde interdit.
 *
 * La connaissance derivee se lit donc la ou la reponse est rendue A UNE
 * PERSONNE, et bornee par ce que CETTE personne peut lire : le Shell
 * (`AiShellResponder`) et `DossierRetrievalSource`. `answerOverSources()` en
 * fait partie — le Shell lui transmet des lignes deja bornees a la lecture —
 * ce qui explique que `buildSourcesBlock()` sache nommer et lier une source
 * derivee alors que `answer()` n'en produira jamais.
 */
final class DossierInsightsService
{
    /**
     * Rubriques attendues, DANS CET ORDRE, designees par un identifiant
     * STABLE et non par leur libelle.
     *
     * Un titre de rubrique n'est pas de la chrome d'ecran : il est dicte au
     * modele dans la question preetablie, relu par le parseur de la reponse,
     * puis reemis dans le markdown rendu. Les trois usages doivent lire la
     * meme autorite. Les comparer par identifiant plutot que par texte est ce
     * qui rend cette autorite traduisible sans qu'aucune branche de code ne
     * connaisse une langue.
     */
    private const HEADING_KEYS = [
        'summary' => 'dossiers.insights_heading_summary',
        'facts' => 'dossiers.insights_heading_facts',
        'convergences' => 'dossiers.insights_heading_convergences',
        'attention' => 'dossiers.insights_heading_attention',
        'questions' => 'dossiers.insights_heading_questions',
    ];

    /**
     * Rubriques dont le corps est une liste a puces — les seules soumises au
     * filtrage ligne a ligne.
     */
    private const BULLET_SLUGS = ['facts', 'convergences', 'attention', 'questions'];

    /**
     * Nombre de documents representatifs demandes au corpus — meme borne que
     * la vue d'ensemble documentaire (TASK-1309).
     */
    private const DOCUMENT_LIMIT = 6;

    /**
     * TASK-1516 : combien d'extraits une REPONSE cite au plus. Meme borne que
     * la recherche semantique de la page (`search()` la valide entre 1 et 5) :
     * la reponse et les passages affiches dessous doivent reposer sur le meme
     * nombre de sources, sinon l'ecran montrerait autre chose que ce qui a
     * servi.
     */
    private const ANSWER_SOURCE_LIMIT = 5;

    /** Au plus trois approfondissements — le CDC en demande trois. */
    private const FOLLOW_UP_LIMIT = 3;

    /**
     * TASK-1517 : le bassin de candidats dans lequel la selection puise, avant
     * repli des quasi-doublons. Un seul embedding de requete, quelle que soit
     * sa taille — seule la clause SQL `LIMIT` change.
     */
    private const ANSWER_CANDIDATE_LIMIT = 12;

    /**
     * TASK-1517 : la borne TOTALE, ancrage compris. Cinq extraits choisis par
     * proximite, plus au plus un extrait d'OUVERTURE de document.
     */
    private const ANSWER_TOTAL_LIMIT = 6;

    public function __construct(
        private readonly DossierSemanticSearchService $search,
        private readonly CapabilityRegistry $capabilities,
        private readonly PromptRepository $prompts,
        private readonly ProviderResolver $providers,
        private readonly AiEconomicGuard $economicGuard,
        private readonly AiProviderInvocationLedger $ledger,
    ) {}

    /**
     * Le Dossier a-t-il seulement de quoi produire un Insight ? Reutilise
     * EXACTEMENT la meme regle d'eligibilite documentaire que la generation
     * elle-meme (Article publie / fichier non supprime) — jamais une seconde
     * requete qui pourrait diverger.
     */
    public function hasIndexedContent(Organization $organization, Dossier $dossier): bool
    {
        return $this->search->representativeChunksAcrossDossiers(
            (string) $organization->id,
            [(string) $dossier->id],
            1,
        ) !== [];
    }

    public function generate(Organization $organization, Dossier $dossier, User $requester): KnowledgeAnswer
    {
        if ((string) $dossier->organization_id !== (string) $organization->id) {
            throw new RuntimeException(__('dossiers.insights_cross_organization'));
        }

        // Revalidation serveur — jamais une confiance sur « la page est deja
        // ouverte » (PREP-LIGHT §4.2).
        if (Gate::forUser($requester)->denies('view', $dossier)) {
            throw new RuntimeException(__('dossiers.insights_not_authorized'));
        }

        // La langue du contenu SYSTEME produit pour une Organization est celle
        // de l'Organization, jamais celle du lecteur (arbitrage MASTER du
        // 04/09, deja applique par TASK-1388 et TASK-1390). Un Insight est
        // relu par tout le cercle : le faire suivre la langue de qui appuie
        // sur le bouton donnerait au meme Dossier deux langues selon le
        // visiteur.
        $locale = $this->localeDeReference($organization);

        $capability = CapabilityRegistry::LOOP_KNOWLEDGE_ANSWER;
        $definition = $this->capabilities->get($capability);
        // Smart Dossier tourne HORS Loop : la capability autorise deja la
        // portee Organization (PREP-LIGHT §3).
        $this->capabilities->assertScopeAllowed($capability, CapabilityRegistry::SCOPE_ORGANIZATION);

        $rows = $this->search->representativeChunksAcrossDossiers(
            (string) $organization->id,
            [(string) $dossier->id],
            self::DOCUMENT_LIMIT,
        );

        if ($rows === []) {
            throw new RuntimeException(__('dossiers.insights_no_content'));
        }

        $contexte = new ContexteIa(
            organizationId: (string) $organization->id,
            userId: (string) $requester->id,
            loopId: null,
            locale: $locale,
            capability: $capability,
            correlationId: AiCorrelation::id(),
            source: CapabilityRegistry::SOURCE_DOSSIER_RETRIEVAL,
            query: self::presetQuestionSummary(),
        );

        // P4 : sans configuration IA d'Organization, aucun appel, aucun repli.
        try {
            $resolved = $this->providers->resolve($capability, $contexte);
        } catch (DomainException $exception) {
            throw AiRefusedException::notConfigured($exception);
        }

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
            throw AiRefusedException::fromVerdict($verdict);
        }

        $instructions = $this->prompts->compose($capability, $this->capabilityInstructions($definition->promptKey), (string) $organization->id);
        $doctrineVersion = $this->prompts->activeDoctrineVersion((string) $organization->id);

        // La vue d'ensemble montre un ECHANTILLON de chaque document : son
        // ouverture. C'est le sens de cette borne, et elle ne bouge pas.
        [$sourcesBlock, $consulted] = $this->buildSourcesBlock(
            $organization,
            $rows,
            (int) config('ai.knowledge.overview.chars_per_document', 700),
        );

        $agent = new LoopKnowledgeAgent(
            $instructions,
            (int) config('ai.knowledge.max_tokens', 700),
            (float) config('ai.knowledge.temperature', 0.2),
        );

        $prompt = $sourcesBlock."\n\n".$this->presetQuestion($locale);

        $startedAt = microtime(true);

        try {
            $response = $agent->prompt($prompt, provider: $resolved->instance, model: $resolved->model);
        } catch (\Throwable $exception) {
            $this->recordInteraction($dossier, $requester, $contexte, $definition, $resolved, $prompt, null,
                AiUsage::notObserved(), ['cost_usd' => null, 'cost_unknown' => null], null, 'failed', $startedAt, null,
                $exception::class, $consulted, [], $doctrineVersion);

            throw new RuntimeException(__('dossiers.insights_ai_error'), 0, $exception);
        }

        $rawAnswer = AiMarkdownSanitizer::sanitize(
            (string) $response->text,
            (int) config('ai.knowledge.max_answer_chars', 3000),
        );

        // Revalidation serveur (mandat §7/§10) : refs inventees ou non
        // offertes supprimees, convergences a un seul document ecartees.
        $answer = $this->filterSections($rawAnswer, $consulted, $locale);

        if ($answer === '') {
            throw new RuntimeException(__('dossiers.insights_empty_response'));
        }

        $usage = AiUsage::fromSdkTextTokens($response->usage->promptTokens, $response->usage->completionTokens);
        $cost = $this->economicGuard->finalize($resolved->provider, $resolved->model, $usage);

        $cited = $this->citedSources($answer, $consulted);

        $interaction = $this->recordInteraction($dossier, $requester, $contexte, $definition, $resolved, $prompt,
            $answer, $usage, $cost->traceAttributes(), $cost, 'success', $startedAt, $response->invocationId, null,
            $consulted, $cited, $doctrineVersion);

        return new KnowledgeAnswer(
            answer: $answer,
            sources: $cited,
            consulted: $consulted,
            grounded: $cited !== [],
            interactionId: $interaction->id,
            credit: $this->economicGuard->userCreditStatus($organization, $requester),
        );
    }

    /**
     * TASK-1516 — LE DOSSIER REPOND : une question libre, une reponse sourcee,
     * trois approfondissements. Methode SOEUR de `generate()`, jamais un second
     * moteur RAG.
     *
     * Ce qu'elle partage avec `generate()`, ligne pour ligne : la revalidation
     * de tenant et de policy, la capability `loop_knowledge_answer`, le meme
     * `AdminAiPrompt`, le meme `LoopKnowledgeAgent`, le meme garde economique,
     * le meme `buildSourcesBlock()`, la meme revalidation des references, le
     * meme ledger, le meme DTO. `generate()` n'est pas touchee.
     *
     * Ce qui differe, et pourquoi :
     *
     * - **le retrieval**. `generate()` prend un extrait representatif par
     *   document, sans recherche : elle repond a « qu'est-ce qui ressort de ce
     *   Dossier ». Ici, la question de l'utilisateur pilote la recherche
     *   vectorielle, bornee au SEUL Dossier courant.
     * - **la langue**. `generate()` suit la langue de l'ORGANIZATION : un
     *   Insight est relu par tout le cercle. Une reponse est lue par la
     *   personne qui vient de poser la question, et par elle seule ; elle suit
     *   donc la langue du LECTEUR. Repondre en anglais a une question posee en
     *   francais parce que l'Organization est anglophone serait absurde — et
     *   le CDC demande explicitement « FR sur documents EN ».
     * - **la structure**. Pas de cinq rubriques : une reponse directe, puis au
     *   plus trois questions d'approfondissement. Le contrat est dicte DANS le
     *   tour, comme `presetQuestion()`, sans jamais toucher au prompt partage.
     *
     * `$fileHint` est le texte brut d'un nom de fichier tel que l'utilisateur
     * l'a ecrit. Il est resolu SERVEUR sur le Dossier deja autorise ; aucun
     * identifiant produit par un modele n'entre ici.
     *
     * @throws RuntimeException aucune source exploitable, ou reponse vide
     */
    public function answer(
        Organization $organization,
        Dossier $dossier,
        User $requester,
        string $question,
        ?string $fileHint = null,
        ?string $conversationMemory = null,
    ): KnowledgeAnswer {
        $question = trim($question);

        if ($question === '') {
            throw new RuntimeException(__('dossiers.answer_question_required'));
        }

        if ((string) $dossier->organization_id !== (string) $organization->id) {
            throw new RuntimeException(__('dossiers.insights_cross_organization'));
        }

        // Revalidation serveur, a chaque tour — jamais une confiance sur « la
        // page est deja ouverte ».
        if (Gate::forUser($requester)->denies('view', $dossier)) {
            throw new RuntimeException(__('dossiers.insights_not_authorized'));
        }

        // La langue sert ici au seul message de non-reponse ; le coeur la
        // recalcule pour le tour lui-meme.
        $locale = $this->readerLocale();

        // Le credential d'embedding est celui de l'ORGANIZATION, comme
        // l'ingestion et le retrieval. NULL = pas d'embedding tenant : refus
        // explicite, JAMAIS un repli sur la cle plateforme.
        $embeddingInstance = $this->providers->resolveEmbeddingInstance((string) $organization->id);

        if ($embeddingInstance === null) {
            throw new RuntimeException(__('dossiers.answer_embedding_unavailable'));
        }

        // Le CDC §7 decrit une phrase naturelle — « Cherche dans
        // 260908-20h12-ARIA template Part B_EU.docx » — et non un champ a part.
        // A defaut d'indication explicite, on cherche donc un nom de fichier
        // DANS la question. Dans les deux cas la resolution est serveur, bornee
        // au Dossier courant, et deterministe.
        $scopedFiles = $fileHint !== null
            ? $this->resolveFileScope($organization, $dossier, $fileHint)
            : $this->detectFileScope($organization, $dossier, $question);

        $rows = $this->search->searchAcrossDossiers(
            (string) $organization->id,
            [(string) $dossier->id],
            $question,
            $embeddingInstance,
            self::ANSWER_SOURCE_LIMIT,
            ['dossier_answer' => true],
            self::ANSWER_CANDIDATE_LIMIT,
            $scopedFiles,
        );

        // TASK-1517 : replier les quasi-doublons, puis ancrer l'ouverture du
        // document le mieux classe. Dans cet ordre, et jamais l'inverse — voir
        // `foldNearDuplicates()` et `withOpeningAnchor()`.
        $rows = $this->foldNearDuplicates($rows, self::ANSWER_SOURCE_LIMIT);
        $rows = $this->withOpeningAnchor($organization, $dossier, $rows);

        if ($rows === []) {
            // « Je n'ai pas trouve » est une REPONSE, pas une panne (CDC §10).
            // La rendre par une exception l'afficherait en rouge, comme un
            // incident technique, alors que c'est le comportement honnete et
            // attendu. Aucun appel provider : il n'y a rien a fonder.
            return new KnowledgeAnswer(
                answer: __($scopedFiles !== null ? 'dossiers.answer_no_source_in_file' : 'dossiers.answer_no_source', [], $locale),
                sources: [],
                consulted: [],
                grounded: false,
                interactionId: null,
                credit: $this->economicGuard->userCreditStatus($organization, $requester),
            );
        }

        return $this->answerOverSources($organization, $dossier, $requester, $question, $rows, $conversationMemory);
    }

    /**
     * TASK-1520 — repondre a partir de sources DEJA choisies.
     *
     * Ce service est le moteur documentaire du produit ; le Dossier en est la
     * premiere surface, pas la seule. Cette methode en expose le coeur —
     * capability, garde economique, bloc de sources, revalidation des
     * references, ledger, DTO — pour qu'une seconde surface s'y branche SANS
     * qu'un second moteur apparaisse.
     *
     * Ce qu'elle ne fait PAS : choisir les sources. L'appelant les a deja
     * choisies et deja autorisees. C'est lui, et lui seul, qui repond de leur
     * perimetre.
     *
     * `$dossier` sert de rattachement de TRACE (`AiInteraction.metadata`), pas
     * de perimetre : les sources sont donnees.
     *
     * @param  list<array<string, mixed>>  $rows  sources deja retrouvees et autorisees
     *
     * @throws RuntimeException reponse vide
     */
    public function answerOverSources(
        Organization $organization,
        Dossier $dossier,
        User $requester,
        string $question,
        array $rows,
        ?string $conversationMemory = null,
    ): KnowledgeAnswer {
        $locale = $this->readerLocale();
        $capability = CapabilityRegistry::LOOP_KNOWLEDGE_ANSWER;
        $definition = $this->capabilities->get($capability);
        $this->capabilities->assertScopeAllowed($capability, CapabilityRegistry::SCOPE_ORGANIZATION);

        $contexte = new ContexteIa(
            organizationId: (string) $organization->id,
            userId: (string) $requester->id,
            loopId: null,
            locale: $locale,
            capability: $capability,
            correlationId: AiCorrelation::id(),
            source: CapabilityRegistry::SOURCE_DOSSIER_RETRIEVAL,
            query: $question,
        );

        try {
            $resolved = $this->providers->resolve($capability, $contexte);
        } catch (DomainException $exception) {
            throw AiRefusedException::notConfigured($exception);
        }

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
            throw AiRefusedException::fromVerdict($verdict);
        }

        $instructions = $this->prompts->compose($capability, $this->capabilityInstructions($definition->promptKey), (string) $organization->id);
        $doctrineVersion = $this->prompts->activeDoctrineVersion((string) $organization->id);

        // TASK-1518 — une REPONSE voit l'extrait ENTIER. `null` = aucune
        // seconde coupe.
        //
        // Ce bloc heritait du budget de la vue d'ensemble : 700 caracteres.
        // Mesure sur corpus reel : les extraits font 3 090 caracteres en
        // moyenne, jusqu'a 5 486 — donc 80 % de chacun etait jete avant que le
        // modele ne le voie. Un fait ecrit plus loin dans l'extrait devenait un
        // « je n'ai pas trouve cette information » : un faux refus,
        // indiscernable d'un vrai. Le retrieval n'y etait pour rien, l'extrait
        // porteur arrivait au RANG 1.
        //
        // Pourquoi AUCUN plafond, et pas un plafond plus haut : l'extrait est
        // DEJA borne par le chunker (500 tokens). Le couper une seconde fois,
        // en CARACTERES cette fois, c'est arbitrer une longueur que personne
        // ne connait — 4 400 aurait tronque 5 extraits sur 583 en base, et le
        // prochain document depassera le prochain chiffre choisi. Mesure A/B
        // sur corpus reel : un plafond a 5 600 et l'absence de plafond
        // produisent des prompts RIGOUREUSEMENT identiques, pour +0,5 % de
        // cout par rapport a 4 400. Aucune necessite structurelle n'impose
        // cette seconde coupe : le nombre de sources est borne (6), et
        // `ai.knowledge.max_context_chars` n'est applique nulle part sur ce
        // chemin (verifie).
        [$sourcesBlock, $consulted] = $this->buildSourcesBlock($organization, $rows, null);

        $agent = new LoopKnowledgeAgent(
            $instructions,
            (int) config('ai.knowledge.max_tokens', 700),
            (float) config('ai.knowledge.temperature', 0.2),
        );

        // TASK-1519 — ordre canonique du CDC : SOURCES -> THREAD -> QUESTION.
        //
        // Le fil aide le modele a COMPRENDRE une question elliptique (« Et les
        // participants ? »). Il n'est JAMAIS une source documentaire : il n'est
        // pas cite, il ne porte aucune reference [Sn], et il n'entre pas dans
        // la requete de recherche.
        //
        // Pourquoi il n'entre pas dans la requete, alors que le CDC l'autorisait :
        // mesure sur corpus reel. Prefixer la question precedente DEGRADE le
        // classement dans 3 cas sur 5 — « Et les participants ? » passe du rang
        // 1 a hors du top 20, et une question autonome de rang 1 tombe aussi
        // hors du top 20. Les questions elliptiques se classent deja tres bien
        // seules, parce que leur embedding porte le mot qui compte.
        $prompt = $sourcesBlock
            .$this->conversationBlock($conversationMemory)
            ."\n\n".$this->answerInstruction($locale, $question);

        $startedAt = microtime(true);

        try {
            $response = $agent->prompt($prompt, provider: $resolved->instance, model: $resolved->model);
        } catch (\Throwable $exception) {
            $this->recordInteraction($dossier, $requester, $contexte, $definition, $resolved, $prompt, null,
                AiUsage::notObserved(), ['cost_usd' => null, 'cost_unknown' => null], null, 'failed', $startedAt, null,
                $exception::class, $consulted, [], $doctrineVersion);

            throw new RuntimeException(__('dossiers.insights_ai_error'), 0, $exception);
        }

        $rawAnswer = AiMarkdownSanitizer::sanitize(
            (string) $response->text,
            (int) config('ai.knowledge.max_answer_chars', 3000),
        );

        [$body, $followUps] = $this->splitAnswer($rawAnswer, $locale);

        // Revalidation serveur : toute reference [Sn] que le retrieval n'a pas
        // offerte disparait du texte. Un lecteur ne doit jamais voir une
        // citation qu'il ne peut pas ouvrir.
        $validRefs = array_column($consulted, 'ref');
        $answer = trim($this->stripInventedRefs($body, $validRefs));

        if ($answer === '') {
            throw new RuntimeException(__('dossiers.insights_empty_response'));
        }

        $usage = AiUsage::fromSdkTextTokens($response->usage->promptTokens, $response->usage->completionTokens);
        $cost = $this->economicGuard->finalize($resolved->provider, $resolved->model, $usage);

        $cited = $this->citedSources($answer, $consulted);

        $interaction = $this->recordInteraction($dossier, $requester, $contexte, $definition, $resolved, $prompt,
            $answer, $usage, $cost->traceAttributes(), $cost, 'success', $startedAt, $response->invocationId, null,
            $consulted, $cited, $doctrineVersion);

        return new KnowledgeAnswer(
            answer: $answer,
            // Ce qui est CITE, jamais ce qui a ete consulte : la nuance a deja
            // ete payee une fois par ce depot (TASK-1391).
            sources: $cited,
            consulted: $consulted,
            grounded: $cited !== [],
            interactionId: $interaction->id,
            credit: $this->economicGuard->userCreditStatus($organization, $requester),
            followUps: $followUps,
        );
    }

    /**
     * TASK-1516 — « Cherche dans 260908-20h12-ARIA template Part B_EU.docx ».
     *
     * Resolution SERVEUR, bornee au Dossier deja autorise, sur `display_name`
     * puis `original_name`. Une restriction explicite est toujours reconnue :
     * elle rend la liste des 0..N identifiants correspondants. Une liste vide
     * signifie « rien de resoluble » et doit produire une non-reponse sure ;
     * elle ne signifie jamais « rechercher dans tout le Dossier ».
     *
     * TASK-1525 : toutes les correspondances legitimes d'une famille restent
     * dans le scope. Le filtre SQL recoit ces identifiants serveur ; aucun ID
     * client et aucun arbitrage du modele n'entrent dans cette autorite.
     *
     * @return list<string>
     */
    private function resolveFileScope(Organization $organization, Dossier $dossier, string $hint): array
    {
        $needle = mb_strtolower(trim($hint));

        if ($needle === '') {
            return [];
        }

        $matches = DossierFile::query()
            ->where('organization_id', $organization->getKey())
            ->where('dossier_id', $dossier->getKey())
            ->whereNull('deleted_at')
            ->get(['id', 'display_name', 'original_name'])
            ->filter(function (DossierFile $file) use ($needle): bool {
                foreach ([$file->display_name, $file->original_name] as $name) {
                    $name = mb_strtolower(trim((string) $name));

                    if ($name !== '' && ($name === $needle || str_contains($name, $needle) || str_contains($needle, $name))) {
                        return true;
                    }
                }

                return false;
            })
            ->values();

        return $matches
            ->pluck('id')
            ->map(static fn ($id): string => (string) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * TASK-1517 — replier les quasi-doublons, en gardant le mieux classe.
     *
     * ## Pourquoi pas `content_hash`, que le CDC recommandait
     *
     * Mesure sur le Dossier ARIA reel, avant d'ecrire une ligne : **265
     * chunks, 265 `content_hash` DISTINCTS, zero doublon exact**. Les quatre
     * versions du document sont quasi identiques mais jamais a l'octet — les
     * frontieres de chunk se decalent (67/68/65/65 chunks). Le hash exact ne
     * replie rien du tout.
     *
     * ## Ce que replie une cle NORMALISEE
     *
     * Minuscules, ponctuation retiree, espaces normalises, prefixe de 120
     * caracteres : 5 chunks sur 265. Un gain modeste — mais la famille la plus
     * peuplee est decisive : les QUATRE extraits d'ouverture, qui portent tous
     * « ARIA ARtistic Intelligence Alliance ». Sans ce repli, l'ancrage
     * ci-dessous injecterait quatre fois la meme phrase et gaspillerait le
     * budget de sources.
     *
     * La deduplication n'est donc pas ici pour elle-meme : elle existe PARCE
     * QUE l'ancrage la rend necessaire.
     *
     * @param  list<array<string, mixed>>  $rows  deja tries par distance croissante
     * @return list<array<string, mixed>>
     */
    private function foldNearDuplicates(array $rows, int $limit): array
    {
        $kept = [];
        $seen = [];

        foreach ($rows as $row) {
            if (count($kept) >= $limit) {
                break;
            }

            $key = self::nearDuplicateKey((string) $row['content']);

            if ($key !== '' && isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $kept[] = $row;
        }

        return $kept;
    }

    /**
     * L'identite APPROCHEE d'un extrait : ce qu'il dit, debarrasse de ce qui
     * change d'une version a l'autre d'un meme document (casse, ponctuation,
     * espaces, numerotation collee au texte).
     *
     * Le prefixe est borne : deux extraits qui commencent par la meme page
     * entiere disent la meme chose, et comparer leur totalite ferait echouer le
     * repli sur la moindre virgule ajoutee en fin de chunk.
     */
    private static function nearDuplicateKey(string $content): string
    {
        $normalized = mb_strtolower($content);
        $normalized = preg_replace('/[^\p{L}\p{N} ]+/u', ' ', $normalized) ?? $normalized;
        $normalized = trim(preg_replace('/\s+/u', ' ', $normalized) ?? $normalized);

        return mb_substr($normalized, 0, 120);
    }

    /**
     * TASK-1517 — ancrer l'OUVERTURE du document le mieux classe.
     *
     * ## Le cas rouge que cette methode corrige, mesure sur le corpus reel
     *
     * « Que signifie ARIA ? » repondait « ARIA signifie "Artist-Professional
     * Interplay and Responsible Research and Innovation" » — une invention :
     * cette chaine n'apparait dans AUCUN des 265 chunks du Dossier, quand
     * « ARtistic Intelligence Alliance » en occupe douze. Et la reponse se
     * declarait GROUNDED, parce que le modele citait `[Sn]` ailleurs : une
     * citation vraie couvrait une phrase fausse.
     *
     * La definition vit au `chunk_index` 0 de chaque version — la premiere
     * ligne du document. La recherche vectorielle ne l'y trouve pas : une
     * question de trois mots ressemble mal a une page d'ouverture entiere.
     * Aucun reglage de `top_k` n'y change quoi que ce soit.
     *
     * ## La primitive n'est pas neuve
     *
     * `representativeChunksAcrossDossiers()` (TASK-1309) rend deja exactement
     * cela : l'extrait d'index minimal de chaque document, meme forme de ligne,
     * memes jointures tenant-safe, aucun embedding, aucun appel provider,
     * aucune ligne de ledger. Une lecture SQL bornee, rien de plus.
     *
     * ## Deux precautions
     *
     * L'ancrage est AJOUTE EN FIN, jamais en tete : `[S1]` doit rester
     * l'extrait le plus proche de la question, sinon le rang cesserait de dire
     * la pertinence.
     *
     * Une question restreinte a des fichiers ne demande AUCUNE garde
     * supplementaire, et c'est mesure : quand `$scopedFiles` est pose, la
     * recherche est deja bornee a ces fichiers EN SQL, donc le document le mieux
     * classe appartient a ce scope, donc l'ouverture ancree en vient forcement. Un
     * `if` de plus aurait ete du code mort pretendant proteger — un sabotage
     * l'a laisse vert, ce qui l'a revele.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function withOpeningAnchor(Organization $organization, Dossier $dossier, array $rows): array
    {
        if ($rows === []) {
            return $rows;
        }

        $documentKey = static fn (array $row): string => DossierSemanticSearchService::documentKey($row);
        $bestDocument = $documentKey($rows[0]);

        $alreadyPresent = [];

        foreach ($rows as $row) {
            $alreadyPresent[self::nearDuplicateKey((string) $row['content'])] = true;
        }

        $openings = $this->search->representativeChunksAcrossDossiers(
            (string) $organization->id,
            [(string) $dossier->id],
            self::ANSWER_CANDIDATE_LIMIT,
        );

        foreach ($openings as $opening) {
            if ($documentKey($opening) !== $bestDocument) {
                continue;
            }

            // Deja dit : l'ouverture figure parmi les extraits retrouves, ou
            // un quasi-doublon d'une autre version l'a deja apportee.
            if (isset($alreadyPresent[self::nearDuplicateKey((string) $opening['content'])])) {
                return $rows;
            }

            $rows[] = $opening;

            return array_slice($rows, 0, self::ANSWER_TOTAL_LIMIT);
        }

        return $rows;
    }

    /**
     * TASK-1519 — le fil de conversation, entre les sources et la question.
     *
     * Bloc VIDE quand il n'y a pas de fil : la page Dossier appelle sans
     * memoire, et son prompt doit rester exactement celui d'avant.
     */
    private function conversationBlock(?string $conversationMemory): string
    {
        $memory = trim((string) $conversationMemory);

        if ($memory === '') {
            return '';
        }

        return "\n\n--- CONVERSATION EN COURS (contexte, jamais une source : ne la cite pas) ---\n".$memory;
    }

    /**
     * TASK-1516 — un nom de fichier repere DANS une question libre.
     *
     * Deliberement plus severe que `resolveFileScope()`, et pour une raison
     * precise : ici, personne n'a demande de restreindre quoi que ce soit. Une
     * correspondance trop genereuse retrecirait le corpus EN SILENCE — un
     * Dossier contenant un fichier nomme « ARIA » ferait de « C'est quoi
     * ARIA ? » une question portant sur ce seul fichier, sans que rien ne le
     * dise. Le retrecissement muet est pire que l'absence de fonction.
     *
     * Deux conditions, donc : le nom doit RESSEMBLER a un nom de fichier (une
     * extension), et il doit apparaitre EN ENTIER dans la question.
     */
    private function detectFileScope(Organization $organization, Dossier $dossier, string $question): ?array
    {
        $haystack = mb_strtolower($question);

        $matches = DossierFile::query()
            ->where('organization_id', $organization->getKey())
            ->where('dossier_id', $dossier->getKey())
            ->whereNull('deleted_at')
            ->get(['id', 'display_name', 'original_name'])
            ->filter(function (DossierFile $file) use ($haystack): bool {
                foreach ([$file->display_name, $file->original_name] as $name) {
                    $name = mb_strtolower(trim((string) $name));

                    if ($name !== '' && str_contains($name, '.') && mb_strlen($name) >= 5 && str_contains($haystack, $name)) {
                        return true;
                    }
                }

                return false;
            })
            ->values();

        if ($matches->isEmpty()) {
            return null;
        }

        return $matches
            ->pluck('id')
            ->map(static fn ($id): string => (string) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * TASK-1516 — le corps de la reponse d'un cote, les approfondissements de
     * l'autre.
     *
     * Le titre de rubrique n'est pas ecrit ici : il vient de `heading()`,
     * exactement comme pour Smart Dossier. Le prompt le dicte, le parseur le
     * relit, l'ecran ne le rend jamais — une seule autorite, traduisible, et
     * aucune branche de code ne connait une langue.
     *
     * @return array{0: string, 1: list<string>}
     */
    private function splitAnswer(string $markdown, string $locale): array
    {
        $heading = preg_quote($this->heading('questions', $locale), '/');

        if (! preg_match('/^##\s*'.$heading.'\s*$/mu', $markdown, $match, PREG_OFFSET_CAPTURE)) {
            return [trim($markdown), []];
        }

        $offset = $match[0][1];
        $body = trim(substr($markdown, 0, $offset));
        $tail = substr($markdown, $offset + strlen($match[0][0]));

        $followUps = [];

        foreach (preg_split('/\r?\n/', $tail) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || ! str_starts_with($line, '-')) {
                continue;
            }

            // Texte inerte : les references y sont retirees sans exception,
            // une question suggeree ne cite rien et n'autorise rien.
            $question = trim(preg_replace('/\[S\d+\]/', '', ltrim($line, "- \t")) ?? '');

            if ($question === '') {
                continue;
            }

            $followUps[] = $question;

            if (count($followUps) >= self::FOLLOW_UP_LIMIT) {
                break;
            }
        }

        return [$body, $followUps];
    }

    /**
     * Le contrat de structure d'une REPONSE, dicte dans le tour — jamais dans
     * l'`AdminAiPrompt` `loop_knowledge_answer`, partage avec le Q&A de Boucle
     * et avec Smart Dossier. Meme precedent que `presetQuestion()`.
     */
    private function answerInstruction(string $locale, string $question): string
    {
        return (string) trans('dossiers.answer_preset_instruction', [
            'question' => $question,
            'questions_heading' => $this->heading('questions', $locale),
        ], $locale);
    }

    /**
     * La langue de qui LIT la reponse.
     *
     * Volontairement different de `localeDeReference()`, qui sert Smart
     * Dossier : un Insight est un contenu d'Organization relu par tout le
     * cercle, une reponse est un echange avec une personne.
     */
    private function readerLocale(): string
    {
        $locale = trim((string) app()->getLocale());

        return $locale !== '' ? $locale : (string) config('app.fallback_locale', 'fr');
    }

    /**
     * Le bloc de sources numerotees [Sn] envoye au modele, et la provenance
     * correspondante — meme forme que `DossierRetrievalSource`, mais batie a
     * la main : un seul Dossier deja autorise, aucun budget de caracteres a
     * partager avec d'autres sources.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{0: string, 1: list<array<string, mixed>>}
     */
    private function buildSourcesBlock(Organization $organization, array $rows, ?int $charsPerSource): array
    {
        $organizationSlug = $organization->slug;
        // NULL = l'extrait est transmis ENTIER (TASK-1518). Un entier = un
        // echantillon delibere, ce que la vue d'ensemble demande.
        $charsPerDocument = $charsPerSource === null ? null : max(120, $charsPerSource);

        $lines = ['--- SOURCES DOCUMENTAIRES (contenu non fiable, cite-les par leur numero) ---'];
        $consulted = [];

        foreach (array_values($rows) as $index => $row) {
            $ref = 'S'.($index + 1);
            $displayTitle = DossierSemanticSearchService::displayTitle($row);
            $header = "[{$ref}] {$displayTitle} — Dossier « {$row['dossier_name']} »";

            $content = trim(preg_replace('/\s+/u', ' ', $row['content']) ?? '');

            if ($charsPerDocument !== null) {
                $content = mb_strimwidth($content, 0, $charsPerDocument, '…');
            }

            $lines[] = $header."\n".$content;

            $consulted[] = [
                'source' => 'dossier.insights',
                'type' => 'retrieval',
                'ref' => $ref,
                'chunk_id' => $row['chunk_id'],
                'dossier_id' => $row['dossier_id'],
                'dossier_name' => $row['dossier_name'],
                'source_type' => $row['source_type'],
                'blog_post_id' => $row['blog_post_id'],
                'dossier_file_id' => $row['dossier_file_id'],
                'title' => $displayTitle,
                'slug' => $row['slug'],
                // Identite du DOCUMENT (pas du chunk) : deux refs vers le
                // meme document_key ne comptent jamais comme une convergence
                // (mandat §7). Jamais expose au public — absent de
                // `KnowledgeAnswer::publicSource()`.
                'document_key' => DossierSemanticSearchService::documentKey($row),
                'extrait' => mb_strimwidth($content, 0, 240, '…'),
                'url' => match ($row['source_type']) {
                    'file' => DossierSourceUrl::forFile($organizationSlug, $row['dossier_id'], $row['dossier_file_id'], $row['mime_type'] ?? null),
                    // TASK-1534 — verifier un resume suppose de pouvoir lire la
                    // conversation resumee. Le lien va donc a la Boucle, pas a
                    // la note : la note n'a pas d'ecran, et n'en aura pas.
                    'derived_knowledge' => DossierSourceUrl::forDerivedNote($row['derived_source_loop_id'] ?? null),
                    default => DossierSourceUrl::forArticle($organizationSlug, $row['slug']),
                },
            ];
        }

        return [implode("\n\n", $lines), $consulted];
    }

    /**
     * Revalidation serveur de la structure attendue (mandat §7/§10) :
     * - toute reference `[Sn]` absente de `$consulted` est retiree du texte ;
     * - une ligne de « Faits saillants »/« Points nécessitant attention »
     *   sans AUCUNE reference valide est supprimee ;
     * - une ligne de « Convergences » soutenue par MOINS de deux documents
     *   DISTINCTS (meme document cite deux fois compris) est supprimee ;
     * - une rubrique qui ne survit a aucune ligne est purement et simplement
     *   absente du rendu — jamais un titre suivi de rien.
     *
     * Si le modele ne respecte pas la structure attendue (aucune rubrique
     * reconnue), le resultat est une chaine vide : mode degrade sur, jamais
     * une convergence inventee.
     *
     * @param  list<array<string, mixed>>  $consulted
     */
    private function filterSections(string $markdown, array $consulted, string $locale): string
    {
        $documentKeyByRef = [];

        foreach ($consulted as $row) {
            $documentKeyByRef[$row['ref']] = $row['document_key'];
        }

        $validRefs = array_keys($documentKeyByRef);
        $sections = $this->splitSections($markdown, $locale);
        $kept = [];

        foreach (array_keys(self::HEADING_KEYS) as $slug) {
            if (! array_key_exists($slug, $sections)) {
                continue;
            }

            $heading = $this->heading($slug, $locale);

            if (! in_array($slug, self::BULLET_SLUGS, true)) {
                $text = trim($this->stripInventedRefs($sections[$slug], $validRefs));

                if ($text !== '') {
                    $kept[] = "## {$heading}\n\n{$text}";
                }

                continue;
            }

            $keptLines = [];

            foreach (preg_split('/\r?\n/', trim($sections[$slug])) as $line) {
                $line = trim($line);

                if ($line === '' || ! str_starts_with($line, '-')) {
                    continue;
                }

                $refsInLine = $this->extractRefs($line);
                $validRefsInLine = array_values(array_intersect($refsInLine, $validRefs));
                $line = trim($this->stripInventedRefs($line, $validRefs));

                if ($slug === 'questions') {
                    // Texte inerte, suggestif : aucune citation exigee.
                    if (trim($line, "- \t") !== '') {
                        $keptLines[] = $line;
                    }

                    continue;
                }

                if ($validRefsInLine === []) {
                    // Aucune reference valide : jamais rendu (mandat §10).
                    continue;
                }

                if ($slug === 'convergences') {
                    $documentKeys = array_unique(array_map(
                        static fn (string $ref): string => $documentKeyByRef[$ref],
                        $validRefsInLine,
                    ));

                    if (count($documentKeys) < 2) {
                        // Deux refs du meme document != une convergence.
                        continue;
                    }
                }

                $keptLines[] = $line;
            }

            if ($keptLines !== []) {
                $kept[] = "## {$heading}\n".implode("\n", $keptLines);
            }
        }

        return trim(implode("\n\n", $kept));
    }

    /**
     * @return array<string, string> identifiant de rubrique => corps brut
     */
    private function splitSections(string $markdown, string $locale): array
    {
        if (! preg_match_all('/^##\s+(.+?)\s*$/m', $markdown, $matches, PREG_OFFSET_CAPTURE)) {
            return [];
        }

        $slugParTitre = [];

        foreach (array_keys(self::HEADING_KEYS) as $slug) {
            $slugParTitre[$this->heading($slug, $locale)] = $slug;
        }

        $sections = [];
        $count = count($matches[0]);

        for ($i = 0; $i < $count; $i++) {
            $heading = trim($matches[1][$i][0]);

            if (! array_key_exists($heading, $slugParTitre)) {
                // Un titre hors contrat n'ouvre jamais une rubrique — le
                // modele ne peut pas inventer une septieme categorie. Et un
                // titre rendu dans une AUTRE langue que celle demandee n'en
                // ouvre pas davantage : la rubrique tombe, ce qui rend le
                // desaccord visible plutot que silencieux.
                continue;
            }

            $slug = $slugParTitre[$heading];

            $start = $matches[0][$i][1] + strlen($matches[0][$i][0]);
            $end = $i + 1 < $count ? $matches[0][$i + 1][1] : strlen($markdown);

            $sections[$slug] = ($sections[$slug] ?? '')."\n".substr($markdown, $start, $end - $start);
        }

        return $sections;
    }

    /**
     * @return list<string>
     */
    private function extractRefs(string $text): array
    {
        preg_match_all('/\[(S\d+)\]/', $text, $matches);

        return $matches[1];
    }

    /**
     * @param  list<string>  $validRefs
     */
    private function stripInventedRefs(string $text, array $validRefs): string
    {
        return preg_replace_callback(
            '/\[(S\d+)\]/',
            static fn (array $match): string => in_array($match[1], $validRefs, true) ? $match[0] : '',
            $text,
        ) ?? $text;
    }

    /**
     * @param  list<array<string, mixed>>  $consulted
     * @return list<array<string, mixed>>
     */
    private function citedSources(string $answer, array $consulted): array
    {
        return array_values(array_filter(
            $consulted,
            static fn (array $source): bool => str_contains($answer, '['.$source['ref'].']'),
        ));
    }

    private function capabilityInstructions(string $scenarioId): string
    {
        $prompt = AdminAiPrompt::query()
            ->where('scenario_id', $scenarioId)
            ->where('is_active', true)
            ->orderByDesc('version')
            ->first();

        if ($prompt === null || trim((string) $prompt->prompt_text) === '') {
            throw new RuntimeException(__('dossiers.insights_prompt_missing'));
        }

        return (string) $prompt->prompt_text;
    }

    /**
     * @param  array{cost_usd: ?float, cost_unknown: ?bool}  $costAttributes
     * @param  list<array<string, mixed>>  $consulted
     * @param  list<array<string, mixed>>  $cited
     */
    private function recordInteraction(
        Dossier $dossier,
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
            static fn (array $s): array => ['chunk_id' => $s['chunk_id'] ?? null, 'dossier_id' => $s['dossier_id'] ?? null],
            $sources,
        ));

        return AiInteraction::create([
            'user_id' => $requester->id,
            'organization_id' => $contexte->organizationId,
            'correlation_id' => $contexte->correlationId,
            'process' => $definition->process,
            'feature' => $definition->id,
            'model' => $resolved->trace(),
            'prompt' => $prompt,
            'response' => $response,
            'input_tokens' => $usage->inputTokensOrZero(),
            'output_tokens' => $usage->outputTokensOrZero(),
            ...$costAttributes,
            'metadata' => array_filter([
                'dossier_id' => $dossier->id,
                'requested_by' => $requester->id,
                'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'provider' => $resolved->provider,
                'capability' => $definition->id,
                'status' => $status,
                'sdk_invocation_id' => $sdkInvocationId,
                'failure' => $failure,
                'retrieval' => ['consulted' => $ids($consulted), 'cited' => $ids($cited)],
            ], static fn ($value): bool => $value !== null)
                + ['doctrine_version' => $doctrineVersion],
        ]);
    }

    /**
     * Question preetablie de Smart Dossier V1 — jamais lue depuis une
     * requete. Fixe le contrat de structure ET la doctrine de groundedness
     * (2 documents distincts pour une convergence, aucune categorie remplie
     * par defaut) directement dans le tour, sans toucher a l'AdminAiPrompt
     * `loop_knowledge_answer` partage avec le Q&A de Boucle.
     */
    private function presetQuestion(string $locale): string
    {
        return (string) trans('dossiers.insights_preset_question', [], $locale);
    }

    /**
     * Le libelle d'une rubrique, dans la langue de l'Organization.
     */
    private function heading(string $slug, string $locale): string
    {
        return (string) trans(self::HEADING_KEYS[$slug], [], $locale);
    }

    /**
     * La langue qui fait autorite pour le contenu produit.
     *
     * `Organization.locale` a une valeur par defaut en base (`fr`) et n'est
     * donc jamais nulle en pratique ; le repli reste ecrit pour qu'une
     * Organization arrivant d'un chemin qui ne l'a pas posee ne produise pas
     * un `trans()` sur une locale vide, ou le traducteur retomberait sur la
     * langue du LECTEUR — precisement ce que cette tranche supprime.
     */
    private function localeDeReference(Organization $organization): string
    {
        $locale = trim((string) $organization->locale);

        return $locale !== '' ? $locale : (string) config('app.fallback_locale', 'fr');
    }

    private static function presetQuestionSummary(): string
    {
        return 'smart_dossier_insights_v1';
    }
}
