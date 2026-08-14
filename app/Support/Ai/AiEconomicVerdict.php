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
    ) {}

    public static function allow(float $knownMonthlyCostUsd, int $successfulUnknownCount, bool $pricingKnown): self
    {
        return new self(true, null, $knownMonthlyCostUsd, $successfulUnknownCount, $pricingKnown);
    }

    public static function refuse(
        string $reason,
        float $knownMonthlyCostUsd,
        int $successfulUnknownCount,
        bool $pricingKnown,
    ): self {
        return new self(false, $reason, $knownMonthlyCostUsd, $successfulUnknownCount, $pricingKnown);
    }
}
