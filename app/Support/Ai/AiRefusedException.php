<?php

namespace App\Support\Ai;

use RuntimeException;

/**
 * Refus d'un appel IA AVANT tout appel provider (TASK-1229), avec un CODE
 * STABLE par etat — jamais un « IA indisponible » generique :
 *
 *   CODE_USER_CREDIT_EXHAUSTED        -> le credit IA du mois de l'utilisateur
 *   CODE_ORGANIZATION_BUDGET_REACHED  -> le budget IA de l'Organization
 *   CODE_NOT_CONFIGURED               -> aucun credential pour l'Organization
 *   CODE_UNAVAILABLE                  -> autre indisponibilite (quota d'inconnus…)
 *
 * Herite de RuntimeException : les appelants historiques qui attrapent
 * RuntimeException continuent de fonctionner ; ceux qui veulent le code
 * l'attrapent en premier.
 */
class AiRefusedException extends RuntimeException
{
    public const CODE_USER_CREDIT_EXHAUSTED = 'user_credit_exhausted';

    public const CODE_ORGANIZATION_BUDGET_REACHED = 'organization_budget_reached';

    public const CODE_NOT_CONFIGURED = 'ai_not_configured';

    public const CODE_UNAVAILABLE = 'ai_unavailable';

    public function __construct(
        public readonly string $refusalCode,
        string $message,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * Le message produit d'un verdict economique refuse, et son code — UNE
     * seule table de correspondance pour tous les chemins de generation.
     */
    public static function fromVerdict(AiEconomicVerdict $verdict): self
    {
        return match ($verdict->reason) {
            AiEconomicGuard::REASON_USER_CREDIT_EXHAUSTED => new self(
                self::CODE_USER_CREDIT_EXHAUSTED,
                $verdict->userCredit !== null
                    ? trans_choice('ai.credit_refusal_user_exhausted', (int) $verdict->userCredit->quota(), [
                        'used' => $verdict->userCredit->used,
                        'quota' => (int) $verdict->userCredit->quota(),
                        'date' => $verdict->userCredit->renewsAt->format('d/m/Y'),
                    ])
                    : __('ai.credit_refusal_user_exhausted_short'),
            ),
            AiEconomicGuard::REASON_ORGANIZATION_BUDGET_REACHED,
            AiEconomicGuard::REASON_MONTHLY_BUDGET_REACHED => new self(
                self::CODE_ORGANIZATION_BUDGET_REACHED,
                __('loops.ai_summary_monthly_budget_reached'),
            ),
            default => new self(self::CODE_UNAVAILABLE, __('loops.ai_summary_temporarily_unavailable')),
        };
    }

    public static function notConfigured(?\Throwable $previous = null): self
    {
        return new self(self::CODE_NOT_CONFIGURED, __('loops.ai_not_configured_for_organization'), $previous);
    }
}
