<?php

namespace App\Support\Ai;

use App\Models\AiInteraction;
use App\Models\AiProviderInvocation;
use App\Models\Organization;
use Illuminate\Support\Carbon;

final class AiEconomicGuard
{
    public const REASON_MONTHLY_BUDGET_REACHED = 'monthly_budget_reached';

    public const REASON_UNKNOWN_QUOTA_REACHED = 'unknown_quota_reached';

    /**
     * TASK-1212 : plafond mensuel porte par l'Organization elle-meme, toutes
     * capabilities confondues. Verifie AVANT le plafond par process.
     *
     * TASK-1222 : ce plafond couvre desormais generation + embeddings, en
     * additionnant DEUX registres SANS overlap : les generations gardees
     * vivent dans `ai_interactions` (aucun embedding n'y est ecrit), les
     * embeddings vivent dans le ledger canonique `ai_provider_invocations`
     * (`operation = embedding`). Les generations du ledger ne sont JAMAIS
     * sommees ici : une generation moderne presente dans les deux registres
     * ne compte qu'une fois.
     */
    public const REASON_ORGANIZATION_BUDGET_REACHED = 'organization_monthly_budget_reached';

    /**
     * TASK-1222 : trop d'invocations embeddings au cout INCONNU ce mois-ci.
     * `unknown` n'est pas 0 — et il n'est pas non plus un droit illimite.
     */
    public const REASON_EMBEDDING_UNKNOWN_QUOTA_REACHED = 'embedding_unknown_quota_reached';

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

        $organizationBudget = $organization->aiSetting?->monthly_budget_usd;

        if ($organizationBudget !== null) {
            $organizationMonthlyCost = $this->organizationMonthlyKnownCost($organization, $monthStart, $nextMonthStart);

            if ($organizationMonthlyCost >= (float) $organizationBudget) {
                return AiEconomicVerdict::refuse(
                    self::REASON_ORGANIZATION_BUDGET_REACHED,
                    $organizationMonthlyCost,
                    0,
                    AiPricingCatalog::hasRate($provider, $model),
                );
            }
        }

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

    /**
     * Garde economique d'une INGESTION d'embeddings (TASK-1222), a executer
     * AVANT l'appel provider. Deux verrous seulement :
     *
     *  1. le plafond mensuel de l'Organization (generation gardee +
     *     embeddings connus, memes registres et meme fenetre que
     *     `authorize()`) ;
     *  2. le quota mensuel d'invocations embeddings au cout INCONNU.
     *
     * Un refus n'ecrit RIEN : ni trace, ni ligne de ledger — un appel qui
     * n'est pas parti n'est pas une consommation. Et un refus budgetaire ne
     * detruit pas l'index existant : l'appelant conserve les chunks en place
     * (contrainte temporaire de budget != credential disparu).
     */
    public function authorizeEmbeddings(Organization $organization): AiEconomicVerdict
    {
        $monthStart = now()->startOfMonth();
        $nextMonthStart = $monthStart->copy()->addMonth();

        $knownCost = $this->organizationMonthlyKnownCost($organization, $monthStart, $nextMonthStart);

        // Comme le quota historique par process : seuls les appels REUSSIS au
        // cout non mesurable comptent. Un echec provider a `cost_status
        // unknown` aussi, mais une panne (multipliee par les retries de job)
        // ne doit pas fermer l'ingestion du mois — l'echec a son propre
        // compteur, ailleurs.
        $unknownCount = AiProviderInvocation::query()
            ->where('organization_id', $organization->id)
            ->where('operation', AiProviderInvocation::OPERATION_EMBEDDING)
            ->where('status', AiProviderInvocation::STATUS_SUCCESS)
            ->where('cost_status', AiProviderInvocation::COST_UNKNOWN)
            ->where('created_at', '>=', $monthStart)
            ->where('created_at', '<', $nextMonthStart)
            ->count();

        // Verite du diagnostic : le tarif de la famille d'embedding configuree
        // est-il reellement au catalogue ? (Jamais un `true` de complaisance.)
        $family = trim((string) config('ai.default_for_embeddings', 'openai'));
        $model = trim((string) config("ai.providers.{$family}.models.embeddings.default", ''));
        $pricingKnown = AiPricingCatalog::hasRate($family, $model);

        $organizationBudget = $organization->aiSetting?->monthly_budget_usd;

        if ($organizationBudget !== null && $knownCost >= (float) $organizationBudget) {
            return AiEconomicVerdict::refuse(
                self::REASON_ORGANIZATION_BUDGET_REACHED,
                $knownCost,
                $unknownCount,
                $pricingKnown,
            );
        }

        $unknownLimit = (int) config('ai.embeddings.economic_guard.monthly_unknown_limit', 50);

        // Une limite absente, vide ou invalide ne signifie jamais « zero
        // appel autorise » : on retombe sur le defaut.
        if ($unknownLimit <= 0) {
            $unknownLimit = 50;
        }

        if ($unknownCount >= $unknownLimit) {
            return AiEconomicVerdict::refuse(
                self::REASON_EMBEDDING_UNKNOWN_QUOTA_REACHED,
                $knownCost,
                $unknownCount,
                $pricingKnown,
            );
        }

        return AiEconomicVerdict::allow($knownCost, $unknownCount, $pricingKnown);
    }

    /**
     * Cout mensuel CONNU de l'Organization : generations gardees
     * (`ai_interactions`, ou aucun embedding n'est ecrit) + embeddings du
     * ledger canonique. Deux registres disjoints, zero double comptage —
     * les generations du ledger sont volontairement exclues tant que
     * l'autorite generation n'a pas migre.
     */
    private function organizationMonthlyKnownCost(
        Organization $organization,
        Carbon $monthStart,
        Carbon $nextMonthStart,
    ): float {
        $generationKnown = (float) AiInteraction::query()
            ->where('organization_id', $organization->id)
            ->where('created_at', '>=', $monthStart)
            ->where('created_at', '<', $nextMonthStart)
            ->where('cost_unknown', false)
            ->sum('cost_usd');

        $embeddingKnown = (float) AiProviderInvocation::query()
            ->where('organization_id', $organization->id)
            ->where('operation', AiProviderInvocation::OPERATION_EMBEDDING)
            ->where('cost_status', AiProviderInvocation::COST_KNOWN)
            ->where('created_at', '>=', $monthStart)
            ->where('created_at', '<', $nextMonthStart)
            ->sum('provider_cost');

        return $generationKnown + $embeddingKnown;
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
            : AiCost::known($reported, AiCost::SOURCE_PROVIDER_REPORTED);
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
