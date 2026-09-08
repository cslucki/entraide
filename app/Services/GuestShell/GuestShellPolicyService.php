<?php

namespace App\Services\GuestShell;

use App\Models\AiProviderInvocation;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\OrganizationGuestShellPolicy;
use App\Support\Ai\AiEconomicGuard;
use App\Support\GuestShell\GuestShellDisplayMode;
use App\Support\GuestShell\GuestShellState;
use Carbon\CarbonInterface;

/**
 * TASK-1429 — SW-1 : politique Shell Welcome par Organization.
 *
 * - `policyFor()` lit sans ecrire (defaut DISABLED) ;
 * - `update()` est la seule ecriture, reservee au SuperAdmin par la route ;
 * - `state()` calcule l'etat Addendum V2 §2 a partir de la politique, de
 *   l'autorite IA existante (`OrganizationAiSetting`, jamais dupliquee) et du
 *   ledger provider (`ai_provider_invocations`, process `guest_shell`).
 *
 * Ordre des verdicts (le premier qui s'applique gagne) : DISABLED →
 * MISCONFIGURED (Organization non publique ou inactive) → NO_CREDENTIAL
 * (aucune autorite IA utilisable) → BUDGET_BLOCKED (budget Guest du mois
 * atteint) → ACTIVE.
 */
final class GuestShellPolicyService
{
    /** Le process du ledger pour tout appel provider du Shell Welcome (SW-7 le declarera dans AiProcess). */
    public const PROCESS = 'guest_shell';

    public function policyFor(Organization $organization): OrganizationGuestShellPolicy
    {
        return OrganizationGuestShellPolicy::forOrganization($organization);
    }

    /**
     * @param  array{enabled?: bool, display_mode?: string, max_messages?: int, retention_days?: int, guest_monthly_budget_usd?: float|string|null}  $attributes
     */
    public function update(Organization $organization, array $attributes): OrganizationGuestShellPolicy
    {
        $policy = $this->policyFor($organization);

        // TASK-1441 (MASTER Q69) : overlay | shell_first seulement — `off` n'existe pas, `enabled = false` est l'unique autorite OFF.
        if (array_key_exists('display_mode', $attributes) && $attributes['display_mode'] !== null && ! GuestShellDisplayMode::isValid($attributes['display_mode'])) {
            throw new \InvalidArgumentException('A guest shell display mode is overlay or shell_first; OFF is enabled=false.');
        }

        $policy->fill([
            'enabled' => (bool) ($attributes['enabled'] ?? $policy->enabled),
            'display_mode' => ($attributes['display_mode'] ?? null) ?: ($policy->display_mode ?: GuestShellDisplayMode::DEFAULT),
            'max_messages' => $this->bounded((int) ($attributes['max_messages'] ?? $policy->max_messages), 1, OrganizationGuestShellPolicy::MAX_MESSAGES_LIMIT),
            'retention_days' => $this->bounded((int) ($attributes['retention_days'] ?? $policy->retention_days), 1, OrganizationGuestShellPolicy::RETENTION_DAYS_LIMIT),
            'guest_monthly_budget_usd' => array_key_exists('guest_monthly_budget_usd', $attributes)
                ? ($attributes['guest_monthly_budget_usd'] === null || $attributes['guest_monthly_budget_usd'] === '' ? null : round((float) $attributes['guest_monthly_budget_usd'], 2))
                : $policy->guest_monthly_budget_usd,
        ]);
        $policy->save();

        return $policy;
    }

    public function state(Organization $organization, ?CarbonInterface $now = null): GuestShellState
    {
        $policy = $this->policyFor($organization);
        $setting = OrganizationAiSetting::where('organization_id', $organization->getKey())->first();
        $usage = $this->monthlyUsage($organization, $now ?? now());
        $reasons = [];

        if (! $policy->enabled) {
            return new GuestShellState(GuestShellState::DISABLED, ['disabled'], $policy, $setting, $usage);
        }

        if (! $organization->is_active) {
            $reasons[] = 'organization_inactive';
        }
        if (! $organization->is_public) {
            $reasons[] = 'organization_not_public';
        }
        if ($reasons !== []) {
            return new GuestShellState(GuestShellState::MISCONFIGURED, $reasons, $policy, $setting, $usage);
        }

        if ($setting === null) {
            $reasons[] = 'no_ai_setting';
        } elseif (! $setting->isUsable()) {
            $reasons[] = 'ai_setting_unusable';
        } elseif ($setting->provider !== 'ollama' && trim((string) $setting->api_key) === '') {
            $reasons[] = 'api_key_missing';
        }
        if ($reasons !== []) {
            return new GuestShellState(GuestShellState::NO_CREDENTIAL, $reasons, $policy, $setting, $usage);
        }

        // MASTER Q48 : jamais « illimite ». Sans budget Organization, le plafond
        // du process (config) s'applique ; sans plafond plateforme configure,
        // fail-closed : l'etat ne peut pas etre ACTIVE (aucun appel payant
        // avant que Cyril ait fixe l'exposition globale).
        $ceiling = self::platformCeilingUsd();
        if ($ceiling === null) {
            return new GuestShellState(GuestShellState::MISCONFIGURED, ['platform_ceiling_unset'], $policy, $setting, $usage);
        }

        $platformCost = $this->platformMonthlyCostUsd($now ?? now());
        if ($platformCost >= $ceiling) {
            return new GuestShellState(GuestShellState::BUDGET_BLOCKED, ['platform_ceiling_reached'], $policy, $setting, $usage);
        }

        // TASK-1460 (V3 §3, audit F2) : le budget IA GLOBAL de l'Organization (toutes capabilities) passe avant le budget Guest —
        // la promesse publique dit la meme verite que la garde economique.
        $organizationBudget = $setting?->monthly_budget_usd;
        if ($organizationBudget !== null && app(AiEconomicGuard::class)->organizationMonthlyCostUsd($organization, $now ?? now()) >= (float) $organizationBudget) {
            return new GuestShellState(GuestShellState::BUDGET_BLOCKED, ['organization_budget_reached'], $policy, $setting, $usage);
        }

        $budget = $this->effectiveMonthlyBudgetUsd($policy);
        if ($usage['cost_usd'] >= $budget) {
            return new GuestShellState(GuestShellState::BUDGET_BLOCKED, [$policy->guest_monthly_budget_usd !== null ? 'guest_monthly_budget_reached' : 'process_budget_reached'], $policy, $setting, $usage);
        }

        return new GuestShellState(GuestShellState::ACTIVE, [], $policy, $setting, $usage);
    }

    /**
     * Le mois courant, lu dans le ledger provider : nombre d'appels reussis
     * (= messages repondus), nombre d'appels et d'echecs, cout connu cumule
     * (quel que soit le statut), nombre d'appels au cout inconnu — quel que
     * soit le statut (TASK-1448, Growth V3 §3 P0 : une tentative qui a
     * atteint le provider et echoue n'est jamais supposee gratuite).
     *
     * @return array{messages: int, invocations: int, failed: int, cost_usd: float, cost_unknown: int}
     */
    public function monthlyUsage(Organization $organization, CarbonInterface $now): array
    {
        $base = AiProviderInvocation::query()
            ->where('organization_id', $organization->getKey())
            ->where('process', self::PROCESS)
            ->where('operation', AiProviderInvocation::OPERATION_GENERATION)
            ->whereBetween('created_at', [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()]);

        return [
            'messages' => (int) (clone $base)->where('status', AiProviderInvocation::STATUS_SUCCESS)->count(),
            'invocations' => (int) (clone $base)->count(),
            'failed' => (int) (clone $base)->where('status', AiProviderInvocation::STATUS_FAILED)->count(),
            // TASK-1438 (Shell Welcome V3 §14, MASTER Q64) — la MEME doctrine que AiEconomicGuard :
            // un cout CONNU est compte quel que soit le statut (un appel qui a echoue apres avoir
            // consomme des tokens a coute). TASK-1448 (Growth V3 §3 P0) : le compteur « inconnu »
            // compte aussi les tentatives en ECHEC — exactement ce que la garde compte pour
            // `guest_shell` (`AiEconomicGuard::UNKNOWN_QUOTA_COUNTS_FAILED_ATTEMPTS`).
            'cost_usd' => (float) (clone $base)->where('cost_status', AiProviderInvocation::COST_KNOWN)->sum('provider_cost'),
            'cost_unknown' => (int) (clone $base)->where('cost_status', '!=', AiProviderInvocation::COST_KNOWN)->count(),
        ];
    }

    /** Le budget Guest mensuel effectif : celui de l'Organization, sinon le plafond du process (config), jamais illimite. */
    public function effectiveMonthlyBudgetUsd(OrganizationGuestShellPolicy $policy): float
    {
        return $policy->guest_monthly_budget_usd !== null
            ? (float) $policy->guest_monthly_budget_usd
            : (float) config('ai.guest_shell.economic_guard.monthly_budget_usd', 2.00);
    }

    /** Le plafond plateforme mensuel, ou null s'il n'est PAS configure (fail-closed en amont). */
    public static function platformCeilingUsd(): ?float
    {
        $value = config('ai.guest_shell.platform_monthly_ceiling_usd');

        return $value === null || $value === '' ? null : (float) $value;
    }

    /**
     * Cout connu cumule de TOUTES les Organizations sur le process guest_shell
     * ce mois-ci — quel que soit le statut (TASK-1438, V3 §14) : un appel qui a
     * echoue apres avoir consomme a coute a la plateforme aussi.
     */
    public function platformMonthlyCostUsd(CarbonInterface $now): float
    {
        return (float) AiProviderInvocation::query()
            ->where('process', self::PROCESS)
            ->where('operation', AiProviderInvocation::OPERATION_GENERATION)
            ->where('cost_status', AiProviderInvocation::COST_KNOWN)
            ->whereBetween('created_at', [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()])
            ->sum('provider_cost');
    }

    private function bounded(int $value, int $min, int $max): int
    {
        return max($min, min($max, $value));
    }
}
