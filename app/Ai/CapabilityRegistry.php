<?php

namespace App\Ai;

use App\Support\Ai\AiProcess;
use DomainException;

final class CapabilityRegistry
{
    public const LOOP_SUMMARY = 'loop_summary';

    public const CLARIFY_HELP_REQUEST = 'clarify_help_request';

    public const SCOPE_ORGANIZATION = 'organization';

    public const SCOPE_LOOP = 'loop';

    public const SOURCE_LOOP_MESSAGES = 'loop.messages';

    /**
     * Declaree pour TASK-1209, branchee a aucune capability : elle prepare la
     * suggestion de Boucle de TASK-1210. Une source existe avant d'etre
     * autorisee — c'est precisement ce que `allowedSources` permet de dire.
     */
    public const SOURCE_USER_LOOPS = 'user.loops';

    public const SOURCE_ORGANIZATION_CATEGORIES = 'organization.categories';

    /**
     * TASK-1213 : recherche documentaire (chunks pgvector des Dossiers
     * accessibles). Reservee aux capabilities qui repondent a une question.
     */
    public const SOURCE_DOSSIER_RETRIEVAL = 'dossier.retrieval';

    /**
     * TASK-1307 : inventaire deterministe (metadonnees, sans recherche ni
     * embedding) des Articles/DossierFiles des Dossiers de la Boucle du
     * contexte. Repond aux questions structurelles (« quels fichiers ? »)
     * qu'une recherche semantique sur des chunks ne peut pas atteindre.
     */
    public const SOURCE_DOSSIER_MANIFEST = 'dossier.manifest';

    /**
     * TASK-1213 : reponse documentaire sourcee depuis une Boucle. Read-only :
     * elle n'ecrit ni message ni objet metier.
     */
    public const LOOP_KNOWLEDGE_ANSWER = 'loop_knowledge_answer';

    /**
     * TASK-1309 : « IA + Dossiers » — reponse CROISEE entre les connaissances
     * generales du modele et les connaissances documentaires de la Boucle.
     *
     * Pourquoi une capability distincte de `loop_knowledge_answer` alors que
     * les sources autorisees sont les MEMES : ce qui change est le CONTRAT de
     * reponse, et il est incompatible. Le mode Dossiers est un grounding
     * STRICT — sans source, il refuse, et c'est sa valeur. Le mode
     * IA + Dossiers doit pouvoir repondre depuis la connaissance generale du
     * modele quand les Dossiers n'ont rien a dire, en disant clairement que
     * les Dossiers n'ont rien apporte. Une seule capability porterait donc
     * deux instructions contradictoires — exactement la faute que TASK-1309
     * corrige par ailleurs dans le prompt v2 de `loop_knowledge_answer`.
     */
    public const LOOP_HYBRID_ANSWER = 'loop_hybrid_answer';

    /**
     * TASK-1233 : « Demander a l'IA » dans une Boucle — intervention spontanee
     * (`loop_answer`, prompt `chatloop_ai_answer`) et question d'un membre
     * (`loop_ask`, prompt `chatloop_ai_ask`). Ex-chemin herite
     * `chatloop_direct_answer` : memes prompts administrables, meme process de
     * releve (`chatloop.answer` / `chatloop.ask`), meme surface (la reponse est
     * PUBLIEE dans la Boucle comme message `ai` : `canWrite=true`, declare tel
     * quel — c'est le comportement historique, pas une nouveaute).
     */
    public const LOOP_ANSWER = 'loop_answer';

    public const LOOP_ASK = 'loop_ask';

    /**
     * TASK-1284 (BLOC E) : les deux fonctions IA de l'editeur de Blog,
     * ex-chemin herite `blog_ai` (BlogAiService::generate / ::correct).
     * Memes identifiants de feature et de process qu'avant la migration :
     * la garde economique et le ledger ne bougent pas d'un octet.
     */
    public const BLOG_GENERATE = 'blog_generate';

    public const BLOG_CORRECT = 'blog_correct';

    /**
     * TASK-1284 : le materiau de l'article de Blog — l'etat vivant de
     * l'editeur, fourni par l'appelant via `ContexteIa::$material` (jamais
     * relu en base : un article en cours de correction peut ne pas etre
     * persiste). Reservee aux capabilities du Blog.
     */
    public const SOURCE_BLOG_POST = 'blog.post';

    /**
     * TASK-1285 (BLOC E) : les deux surfaces de REPONSE de l'agent de profil,
     * ex-chemin herite `member_profile_agent` (MemberProfileAgentResponder) —
     * la reponse automatique dans une Boucle agent (job GenerateAiAgentResponse)
     * et le chat visiteur (AiAgentChat). Ids = les features historiques du
     * ledger (meme geste que blog_generate/blog_correct : un seul nom par
     * notion) ; processes INCHANGES : la garde et le ledger relevent les memes
     * cles qu'avant la migration. La configuration conversationnelle
     * (`chatWithSetupPrompt`) reste HERITEE, declaree dans
     * `NervousSystemCoverage::INHERITED` (`member_profile_agent_setup`).
     */
    public const MEMBER_PROFILE_AGENT_LOOP_REPLY = 'member_profile_agent_loop_reply';

    public const MEMBER_PROFILE_AGENT_VISITOR_CHAT = 'member_profile_agent_visitor_chat';

    /**
     * TASK-1285 : le profil IA publie du membre — fourni par l'appelant via
     * `ContexteIa::$material` (mecanisme TASK-1284), jamais relu en base par
     * la source. Reservee aux capabilities de l'agent de profil.
     */
    public const SOURCE_MEMBER_PROFILE = 'member.profile';

    /** @var array<string, CapabilityDefinition> */
    private array $definitions;

    public function __construct()
    {
        $loopSummary = new CapabilityDefinition(
            id: self::LOOP_SUMMARY,
            process: AiProcess::fromFeature('chatloop_ai_summarize'),
            requiresHumanConfirmation: false,
            canWrite: false,
            allowedScopes: [self::SCOPE_ORGANIZATION, self::SCOPE_LOOP],
            allowedSources: [self::SOURCE_LOOP_MESSAGES],
            maxOutput: 8000,
            promptKey: 'chatloop_ai_summarize',
            // Budget de contexte inchange : la meme valeur que celle que
            // `buildContext()` lisait avant TASK-1209.
            contextCharBudget: self::loopSummaryContextBudget(),
        );

        $clarifyHelpRequest = new CapabilityDefinition(
            id: self::CLARIFY_HELP_REQUEST,
            process: AiProcess::fromScenarioId('clarify_help_request'),
            // L'humain valide AVANT toute publication : la capability propose une
            // demande et une Boucle, elle n'en publie aucune.
            requiresHumanConfirmation: true,
            canWrite: false,
            allowedScopes: [self::SCOPE_ORGANIZATION, self::SCOPE_LOOP],
            // Les categories du tenant et les Boucles actives dont
            // l'utilisateur est membre. Les deux suggestions restent bornees
            // aux identifiants exactement fournis par ces sources.
            allowedSources: [self::SOURCE_ORGANIZATION_CATEGORIES, self::SOURCE_USER_LOOPS],
            maxOutput: 2000,
            promptKey: 'clarify_help_request',
            contextCharBudget: self::clarifyContextBudget(),
        );

        $loopKnowledgeAnswer = new CapabilityDefinition(
            id: self::LOOP_KNOWLEDGE_ANSWER,
            process: AiProcess::fromScenarioId('loop_knowledge_answer'),
            // Rien a confirmer : la capability ne produit aucune action metier.
            requiresHumanConfirmation: false,
            canWrite: false,
            allowedScopes: [self::SCOPE_ORGANIZATION, self::SCOPE_LOOP],
            // Uniquement le corpus documentaire autorise : ni messages de
            // Boucle, ni catalogue, ni profil — la question porte sur ce que
            // les Dossiers savent. TASK-1307 : le manifest structurel est
            // declare EN PREMIER (petit, deterministe, prioritaire sur le
            // budget) — le retrieval semantique consomme le reste.
            allowedSources: [self::SOURCE_DOSSIER_MANIFEST, self::SOURCE_DOSSIER_RETRIEVAL],
            maxOutput: 4000,
            promptKey: 'loop_knowledge_answer',
            contextCharBudget: self::knowledgeContextBudget(),
        );

        // TASK-1309 : « IA + Dossiers ». MEMES sources autorisees, MEME
        // perimetre tenant/Boucle, MEME budget de contexte que le mode
        // Dossiers — seule l'instruction change (prompt `loop_hybrid_answer`).
        //
        // PROCESS VOLONTAIREMENT IDENTIQUE a `loop_knowledge.answer` : c'est
        // le meme acte economique (recherche documentaire + generation depuis
        // une Boucle), donc le meme seau de credit, le meme plafond
        // Organization et la meme fenetre d'autorite du ledger
        // (`LEDGER_AUTHORITY_SINCE_BY_PROCESS`, cutover 2026-08-18 deja
        // franchi). Creer un 15e process rouvrirait la convergence economique
        // fermee par TASK-1286/1291 pour zero gain — ce serait precisement
        // l'« economie parallele » que le brief interdit. Ce qui trace le
        // mode reste EXPLICITE : la capability (`loop_hybrid_answer`) est une
        // colonne de premier rang du ledger et de `metadata.capability`.
        $loopHybridAnswer = new CapabilityDefinition(
            id: self::LOOP_HYBRID_ANSWER,
            process: AiProcess::fromScenarioId('loop_knowledge_answer'),
            requiresHumanConfirmation: false,
            canWrite: false,
            allowedScopes: [self::SCOPE_ORGANIZATION, self::SCOPE_LOOP],
            allowedSources: [self::SOURCE_DOSSIER_MANIFEST, self::SOURCE_DOSSIER_RETRIEVAL],
            maxOutput: 4000,
            promptKey: 'loop_hybrid_answer',
            contextCharBudget: self::knowledgeContextBudget(),
        );

        // TASK-1233 : les deux faces de « Demander a l'IA », desormais
        // canoniques. Meme source que le resume (les messages de la Boucle),
        // meme budget de contexte que `buildContext()` lisait avant.
        $loopAnswer = new CapabilityDefinition(
            id: self::LOOP_ANSWER,
            process: AiProcess::fromFeature('chatloop_ai_answer'),
            requiresHumanConfirmation: false,
            canWrite: true,
            allowedScopes: [self::SCOPE_ORGANIZATION, self::SCOPE_LOOP],
            allowedSources: [self::SOURCE_LOOP_MESSAGES],
            maxOutput: 8000,
            promptKey: 'chatloop_ai_answer',
            contextCharBudget: self::loopSummaryContextBudget(),
        );

        $loopAsk = new CapabilityDefinition(
            id: self::LOOP_ASK,
            process: AiProcess::fromFeature('chatloop_ai_ask'),
            requiresHumanConfirmation: false,
            canWrite: true,
            allowedScopes: [self::SCOPE_ORGANIZATION, self::SCOPE_LOOP],
            allowedSources: [self::SOURCE_LOOP_MESSAGES],
            maxOutput: 8000,
            promptKey: 'chatloop_ai_ask',
            contextCharBudget: self::loopSummaryContextBudget(),
        );

        // TASK-1284 : la generation ecrit l'article en brouillon dans le flux
        // de creation (`BlogController::handleAi()`, sans validation humaine
        // supplementaire) : `canWrite = true`, declare tel quel — comportement
        // historique. La correction ne fait que proposer un texte que
        // l'utilisateur applique lui-meme : `canWrite = false`.
        $blogGenerate = new CapabilityDefinition(
            id: self::BLOG_GENERATE,
            process: AiProcess::fromFeature('blog_generate'),
            requiresHumanConfirmation: false,
            canWrite: true,
            allowedScopes: [self::SCOPE_ORGANIZATION],
            allowedSources: [self::SOURCE_BLOG_POST],
            maxOutput: 8000,
            promptKey: 'blog_generate',
            contextCharBudget: self::blogContextBudget(),
        );

        $blogCorrect = new CapabilityDefinition(
            id: self::BLOG_CORRECT,
            process: AiProcess::fromFeature('blog_correct'),
            requiresHumanConfirmation: false,
            canWrite: false,
            allowedScopes: [self::SCOPE_ORGANIZATION],
            allowedSources: [self::SOURCE_BLOG_POST],
            maxOutput: 8000,
            promptKey: 'blog_correct',
            contextCharBudget: self::blogContextBudget(),
        );

        // TASK-1285 : la reponse du job est PUBLIEE dans la Boucle agent comme
        // LoopMessage, sans validation humaine supplementaire : `canWrite =
        // true`, declare tel quel — comportement historique. La reponse du
        // chat visiteur n'est rendue qu'au visiteur dans sa propre session de
        // dialogue (la conversation est sa propre trace) : `canWrite = false`.
        $memberProfileLoopReply = new CapabilityDefinition(
            id: self::MEMBER_PROFILE_AGENT_LOOP_REPLY,
            process: AiProcess::MEMBER_PROFILE_LOOP_AGENT_REPLY,
            requiresHumanConfirmation: false,
            canWrite: true,
            allowedScopes: [self::SCOPE_ORGANIZATION],
            allowedSources: [self::SOURCE_MEMBER_PROFILE],
            // La borne de sortie historique du responder (num_predict /
            // max_tokens 650), declaree telle quelle.
            maxOutput: 650,
            promptKey: 'profile_agent_master',
            contextCharBudget: self::memberProfileContextBudget(),
        );

        $memberProfileVisitorChat = new CapabilityDefinition(
            id: self::MEMBER_PROFILE_AGENT_VISITOR_CHAT,
            process: AiProcess::MEMBER_PROFILE_AGENT_VISITOR_CHAT,
            requiresHumanConfirmation: false,
            canWrite: false,
            allowedScopes: [self::SCOPE_ORGANIZATION],
            allowedSources: [self::SOURCE_MEMBER_PROFILE],
            maxOutput: 650,
            promptKey: 'profile_agent_visitor_chat',
            contextCharBudget: self::memberProfileContextBudget(),
        );

        $this->definitions = [
            $loopSummary->id => $loopSummary,
            $clarifyHelpRequest->id => $clarifyHelpRequest,
            $loopKnowledgeAnswer->id => $loopKnowledgeAnswer,
            $loopHybridAnswer->id => $loopHybridAnswer,
            $loopAnswer->id => $loopAnswer,
            $loopAsk->id => $loopAsk,
            $blogGenerate->id => $blogGenerate,
            $blogCorrect->id => $blogCorrect,
            $memberProfileLoopReply->id => $memberProfileLoopReply,
            $memberProfileVisitorChat->id => $memberProfileVisitorChat,
        ];
    }

    /**
     * Budget de contexte de `loop_summary`.
     *
     * Le registre doit rester constructible SANS framework booté — c'est un
     * objet de domaine, et ses tests unitaires n'ont pas de conteneur. On lit
     * donc la config quand elle existe, et on retombe sinon sur la valeur par
     * defaut de `config/ai.php` elle-meme : les deux ne peuvent pas diverger
     * en silence, puisque c'est le meme nombre ecrit au meme endroit logique.
     */
    private static function loopSummaryContextBudget(): int
    {
        $default = 12000;

        if (! function_exists('app') || ! app()->bound('config')) {
            return $default;
        }

        return (int) config('ai.chatloop.max_context_chars', $default);
    }

    /**
     * Budget de contexte de `clarify_help_request`.
     *
     * Le contexte se limite a la liste des Boucles autorisees : quelques
     * dizaines de lignes courtes. 4000 caracteres laissent de la marge a une
     * Organization tres fournie sans jamais deverser un catalogue entier.
     */
    private static function clarifyContextBudget(): int
    {
        $default = 8000;

        if (! function_exists('app') || ! app()->bound('config')) {
            return $default;
        }

        return (int) config('ai.clarify.max_context_chars', $default);
    }

    /**
     * Budget de contexte de `loop_knowledge_answer` : au plus cinq extraits
     * courts et leurs en-tetes.
     */
    private static function knowledgeContextBudget(): int
    {
        $default = 6000;

        if (! function_exists('app') || ! app()->bound('config')) {
            return $default;
        }

        return (int) config('ai.knowledge.max_context_chars', $default);
    }

    /**
     * Budget de contexte des capabilities Blog : le materiau est l'article
     * lui-meme, et la source `blog.post` laisse toujours passer sa premiere
     * unite en entier — ce plafond ne borne que les unites suivantes.
     */
    private static function blogContextBudget(): int
    {
        $default = 60000;

        if (! function_exists('app') || ! app()->bound('config')) {
            return $default;
        }

        return (int) config('ai.blog.max_context_chars', $default);
    }

    /**
     * Budget de contexte des capabilities de l'agent de profil : le materiau
     * est le profil publie, dont les champs sont bornes par le produit — le
     * bloc reste tres en deca de ce plafond, et la source `member.profile`
     * laisse passer son unite unique EN ENTIER (regle de la premiere unite).
     */
    private static function memberProfileContextBudget(): int
    {
        $default = 30000;

        if (! function_exists('app') || ! app()->bound('config')) {
            return $default;
        }

        return (int) config('ai.member_profile.max_context_chars', $default);
    }

    public function has(string $capability): bool
    {
        return isset($this->definitions[$capability]);
    }

    /**
     * TASK-1227 : toutes les capabilities canoniques, dans l'ordre de
     * declaration. C'est L'AUTORITE dont derive la liste « suit la doctrine de
     * votre Organization » : une capability declaree ici passe par
     * PromptRepository::compose(), donc par la Constitution et la doctrine.
     *
     * @return list<CapabilityDefinition>
     */
    public function all(): array
    {
        return array_values($this->definitions);
    }

    public function get(string $capability): CapabilityDefinition
    {
        return $this->definitions[$capability]
            ?? throw new DomainException("Unknown AI capability [{$capability}].");
    }

    public function assertScopeAllowed(string $capability, string $scope): void
    {
        if (! $this->get($capability)->allowsScope($scope)) {
            throw new DomainException("Scope [{$scope}] is not allowed for AI capability [{$capability}].");
        }
    }
}
