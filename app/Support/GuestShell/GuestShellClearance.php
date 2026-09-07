<?php

namespace App\Support\GuestShell;

use App\Ai\ResolvedModel;

/**
 * TASK-1436 — SW-6 : le verdict de la garde Guest AVANT tout appel provider.
 *
 * Refuse = une raison bornee (exploitable par l'UI SW-8) et l'etape qui a
 * refuse ; RIEN n'est parti. Autorise = tout ce dont SW-7 a besoin pour
 * appeler ET comptabiliser : le modele resolu (credential de l'Organization),
 * la borne de sortie imposee cote serveur, l'identifiant de correlation.
 */
final class GuestShellClearance
{
    public const STEP_POLICY = 'policy';

    public const STEP_ORGANIZATION = 'organization';

    public const STEP_CREDENTIAL = 'credential';

    /** Shell Welcome V3 §13 (4) : le prompt d'accueil ACTIF en base — absent = ferme, avant tout appel. */
    public const STEP_PROMPT = 'prompt';

    public const STEP_CONVERSATION_LIMIT = 'conversation_limit';

    public const STEP_VISITOR_QUOTA = 'visitor_quota';

    public const STEP_ORGANIZATION_BUDGET = 'organization_budget';

    public const STEP_GUEST_BUDGET = 'guest_budget';

    public const STEP_PROCESS_BUDGET = 'process_budget';

    public const STEP_PLATFORM_CEILING = 'platform_ceiling';

    public const STEP_PRICING = 'pricing';

    public const STEP_INPUT_BOUND = 'input_bound';

    public const STEP_OUTPUT_BOUND = 'output_bound';

    public const STEP_LEDGER = 'ledger';

    /** L'ordre canonique (cadre Cyril §5) — le premier refus arrete tout. */
    public const STEPS = [
        self::STEP_POLICY, self::STEP_ORGANIZATION, self::STEP_CREDENTIAL, self::STEP_PROMPT, self::STEP_CONVERSATION_LIMIT,
        self::STEP_VISITOR_QUOTA, self::STEP_ORGANIZATION_BUDGET, self::STEP_GUEST_BUDGET, self::STEP_PROCESS_BUDGET,
        self::STEP_PLATFORM_CEILING, self::STEP_PRICING, self::STEP_INPUT_BOUND, self::STEP_OUTPUT_BOUND, self::STEP_LEDGER,
    ];

    private function __construct(
        public readonly bool $allowed,
        public readonly ?string $reason,
        public readonly ?string $step,
        public readonly ?ResolvedModel $resolved,
        public readonly ?GuestShellPrompt $prompt,
        public readonly ?int $maxOutputTokens,
        public readonly ?string $correlationId,
        public readonly array $facts,
    ) {}

    public static function refuse(string $step, string $reason, array $facts = []): self
    {
        return new self(false, $reason, $step, null, null, null, null, $facts);
    }

    public static function allow(ResolvedModel $resolved, GuestShellPrompt $prompt, int $maxOutputTokens, string $correlationId, array $facts = []): self
    {
        return new self(true, null, null, $resolved, $prompt, $maxOutputTokens, $correlationId, $facts);
    }

    public function isRefused(): bool
    {
        return ! $this->allowed;
    }
}
