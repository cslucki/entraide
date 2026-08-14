<?php

namespace App\Ai;

use App\Support\Ai\AiProcess;
use DomainException;

final class CapabilityRegistry
{
    public const LOOP_SUMMARY = 'loop_summary';

    public const SCOPE_ORGANIZATION = 'organization';

    public const SCOPE_LOOP = 'loop';

    public const SOURCE_LOOP_MESSAGES = 'loop.messages';

    /**
     * Declaree pour TASK-1209, branchee a aucune capability : elle prepare la
     * suggestion de Boucle de TASK-1210. Une source existe avant d'etre
     * autorisee — c'est precisement ce que `allowedSources` permet de dire.
     */
    public const SOURCE_USER_LOOPS = 'user.loops';

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

        $this->definitions = [$loopSummary->id => $loopSummary];
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
