<?php

namespace App\Support\Ai;

final class AiEconomicVerdict
{
    private function __construct(
        public readonly bool $allowed,
        public readonly ?string $reason,
        public readonly float $knownMonthlyCostUsd,
        public readonly int $successfulUnknownCount,
        public readonly bool $pricingKnown,
        /**
         * TASK-1229 : etat du credit IA de l'utilisateur au moment du verdict
         * (present des qu'un utilisateur est passe a la garde, allow comme
         * refuse) — les appelants y lisent l'alerte de seuil et le refus
         * « credit epuise ». NULL = aucun credit evalue (appel sans utilisateur,
         * ex. ingestion, bac a sable).
         */
        public readonly ?AiUserCreditStatus $userCredit = null,
        /**
         * TASK-1819 : secondes avant la prochaine tentative, pour un refus de
         * DEBIT uniquement (`AiEconomicGuard::REASON_RATE_LIMITED`). NULL pour
         * tout autre verdict — un refus mensuel n'a pas de delai court a
         * proposer, un `allow()` n'a pas de refus a expliquer.
         */
        public readonly ?int $retryAfterSeconds = null,
    ) {}

    public static function allow(
        float $knownMonthlyCostUsd,
        int $successfulUnknownCount,
        bool $pricingKnown,
        ?AiUserCreditStatus $userCredit = null,
    ): self {
        return new self(true, null, $knownMonthlyCostUsd, $successfulUnknownCount, $pricingKnown, $userCredit);
    }

    public static function refuse(
        string $reason,
        float $knownMonthlyCostUsd,
        int $successfulUnknownCount,
        bool $pricingKnown,
        ?AiUserCreditStatus $userCredit = null,
        ?int $retryAfterSeconds = null,
    ): self {
        return new self(false, $reason, $knownMonthlyCostUsd, $successfulUnknownCount, $pricingKnown, $userCredit, $retryAfterSeconds);
    }
}
