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
     * TASK-1213 : reponse documentaire sourcee depuis une Boucle. Read-only :
     * elle n'ecrit ni message ni objet metier.
     */
    public const LOOP_KNOWLEDGE_ANSWER = 'loop_knowledge_answer';

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
            // les Dossiers savent.
            allowedSources: [self::SOURCE_DOSSIER_RETRIEVAL],
            maxOutput: 4000,
            promptKey: 'loop_knowledge_answer',
            contextCharBudget: self::knowledgeContextBudget(),
        );

        $this->definitions = [
            $loopSummary->id => $loopSummary,
            $clarifyHelpRequest->id => $clarifyHelpRequest,
            $loopKnowledgeAnswer->id => $loopKnowledgeAnswer,
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

    public function has(string $capability): bool
    {
        return isset($this->definitions[$capability]);
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
