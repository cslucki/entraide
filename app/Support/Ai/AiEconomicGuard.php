<?php

namespace App\Support\Ai;

use App\Models\AiInteraction;
use App\Models\Organization;

final class AiEconomicGuard
{
    public const REASON_MONTHLY_BUDGET_REACHED = 'monthly_budget_reached';

    public const REASON_UNKNOWN_QUOTA_REACHED = 'unknown_quota_reached';

    public function authorize(
        Organization $organization,
        string $process,
        string $provider,
        string $model,
        float $monthlyBudgetUsd,
        int $monthlyUnknownLimit,
    ): AiEconomicVerdict {
        $monthStart = now()->startOfMonth();
        $nextMonthStart = $monthStart->copy()->addMonth();

        $monthly = AiInteraction::query()
            ->where('organization_id', $organization->id)
            ->where('process', $process)
            ->where('created_at', '>=', $monthStart)
            ->where('created_at', '<', $nextMonthStart);

        $knownMonthlyCost = (float) (clone $monthly)
            ->where('cost_unknown', false)
            ->sum('cost_usd');

        $successfulUnknownCount = (clone $monthly)
            ->where('cost_unknown', true)
            ->count();

        $pricingKnown = AiPricingCatalog::hasRate($provider, $model);

        if ($knownMonthlyCost >= $monthlyBudgetUsd) {
            return AiEconomicVerdict::refuse(
                self::REASON_MONTHLY_BUDGET_REACHED,
                $knownMonthlyCost,
                $successfulUnknownCount,
                $pricingKnown,
            );
        }

        if ($successfulUnknownCount >= $monthlyUnknownLimit) {
            return AiEconomicVerdict::refuse(
                self::REASON_UNKNOWN_QUOTA_REACHED,
                $knownMonthlyCost,
                $successfulUnknownCount,
                $pricingKnown,
            );
        }

        return AiEconomicVerdict::allow($knownMonthlyCost, $successfulUnknownCount, $pricingKnown);
    }

    public function finalize(
        string $provider,
        string $model,
        AiUsage $usage,
        mixed $providerReportedCost = null,
    ): AiCost {
        $reported = $this->normalizeProviderReportedCost($providerReportedCost);

        return $reported === null
            ? AiPricingCatalog::cost($provider, $model, $usage)
            : AiCost::known($reported);
    }

    private function normalizeProviderReportedCost(mixed $cost): ?float
    {
        if (! is_numeric($cost)) {
            return null;
        }

        $normalized = (float) $cost;

        return is_finite($normalized) && $normalized >= 0.0 ? $normalized : null;
    }
}
