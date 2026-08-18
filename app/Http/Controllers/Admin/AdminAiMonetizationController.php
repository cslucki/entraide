<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiCreditSettingChange;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Services\Ai\AiUserCreditSettings;
use App\Services\Ai\DTO\AiConsumptionFilters;
use App\Services\Ai\OrganizationAiEconomicUsage;
use App\Support\Ai\AiUserCreditPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * « Monetisation IA » — SuperAdmin (TASK-1229).
 *
 * Le parametre PLATEFORME du credit IA par utilisateur : IA gratuite activee
 * ou non, quota gratuit par utilisateur (utilisations / mois, vide =
 * illimite), seuil d'alerte (%), blocage a 100 % (fixe), a quota atteint :
 * proposer un abonnement. Puis, par Organization : l'override eventuel, la
 * politique effective, et combien de membres approchent ou ont atteint leur
 * credit ce mois — des comptes, jamais un nom ni un contenu tenant.
 *
 * Toute ecriture passe par `AiUserCreditSettings` et laisse une trace
 * (auteur, horodatage) : un changement de quota est un acte d'administration.
 */
class AdminAiMonetizationController extends Controller
{
    public function index(AiUserCreditSettings $settings, OrganizationAiEconomicUsage $usage): View
    {
        $platform = $settings->platform();
        $period = AiConsumptionFilters::currentMonth();

        $organizations = Organization::query()->orderBy('name')->get(['id', 'name', 'slug']);
        $overrides = OrganizationAiSetting::query()
            ->get(['organization_id', 'user_credit_mode', 'user_credit_monthly_uses'])
            ->keyBy('organization_id');
        $usesByOrganization = $usage->creditUsesByOrganizationAndUser($period->from, $period->to);

        $rows = [];

        foreach ($organizations as $organization) {
            $organization->setRelation('aiSetting', $overrides->get($organization->id));
            $policy = $settings->policyFor($organization);
            $uses = $usesByOrganization[(string) $organization->id] ?? [];

            $alerting = 0;
            $blocked = 0;

            if (! $policy->isUnlimited()) {
                $quota = (int) $policy->monthlyUses;
                $threshold = $quota > 0 ? $quota * $policy->alertPercent / 100 : 0;

                foreach ($uses as $count) {
                    if ($count >= $quota) {
                        $blocked++;
                    } elseif ($quota > 0 && $count >= $threshold) {
                        $alerting++;
                    }
                }
            }

            $rows[] = [
                'id' => (string) $organization->id,
                'name' => $organization->name,
                'slug' => $organization->slug,
                'override_mode' => $overrides->get($organization->id)?->user_credit_mode ?? OrganizationAiSetting::USER_CREDIT_MODE_PLATFORM,
                'override_uses' => $overrides->get($organization->id)?->user_credit_monthly_uses,
                'policy' => $policy,
                'ai_users_count' => count($uses),
                'alerting_count' => $alerting,
                'blocked_count' => $blocked,
            ];
        }

        return view('admin.ai-monetization.index', [
            'platform' => $platform,
            'period' => $period,
            'rows' => $rows,
            'lastChange' => $settings->lastChange(null),
            'history' => AiCreditSettingChange::query()
                ->with(['author:id,name', 'organization:id,name'])
                ->latest('created_at')
                ->limit(15)
                ->get(),
            'sourcePlatform' => AiUserCreditPolicy::SOURCE_PLATFORM,
        ]);
    }

    public function update(Request $request, AiUserCreditSettings $settings): RedirectResponse
    {
        $data = $request->validate([
            'free_enabled' => ['nullable', 'boolean'],
            'monthly_uses' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'alert_percent' => ['required', 'integer', 'min:1', 'max:99'],
            'offer_subscription' => ['nullable', 'boolean'],
        ]);

        $settings->updatePlatform([
            'free_enabled' => $request->boolean('free_enabled'),
            'monthly_uses' => $data['monthly_uses'] ?? null,
            'alert_percent' => (int) $data['alert_percent'],
            'offer_subscription' => $request->boolean('offer_subscription'),
        ], $request->user());

        return redirect()->route('admin.ai-monetization')
            ->with('success', __('admin.ai_monetization_saved'));
    }
}
