<?php

namespace App\Services\Ai;

use App\Models\AiConfig;
use App\Models\AiCreditSettingChange;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\User;
use App\Support\Ai\AiUserCreditPolicy;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Reglages du credit IA par utilisateur (TASK-1229) — la CASCADE et ses
 * ecritures tracees.
 *
 *   parametre plateforme (`ai_configs`, cles `user_credit.*`, mecanisme
 *   existant des reglages IA de la plateforme)
 *     -> override Organization (`organization_ai_settings.user_credit_*`,
 *        la meme ligne que le budget provider TASK-1212)
 *        -> politique effective appliquee a l'utilisateur.
 *
 * « Illimite / inclus » est une valeur possible a chaque niveau : plateforme
 * (quota vide) comme Organization (`unlimited`). « IA gratuite desactivee »
 * (plateforme) = AUCUNE utilisation incluse (quota effectif 0) tant qu'une
 * Organization n'a pas son propre override — c'est le comportement defini,
 * teste, jamais deduit.
 *
 * Toute ecriture passe ici et laisse une trace (auteur, horodatage,
 * avant/apres) dans `ai_credit_setting_changes` : un changement de quota est
 * un acte d'administration, comme la doctrine (TASK-1227).
 */
final class AiUserCreditSettings
{
    public const KEY_FREE_ENABLED = 'user_credit.free_enabled';

    public const KEY_MONTHLY_USES = 'user_credit.monthly_uses';

    public const KEY_ALERT_PERCENT = 'user_credit.alert_percent';

    public const KEY_OFFER_SUBSCRIPTION = 'user_credit.offer_subscription';

    public const KEYS = [
        self::KEY_FREE_ENABLED,
        self::KEY_MONTHLY_USES,
        self::KEY_ALERT_PERCENT,
        self::KEY_OFFER_SUBSCRIPTION,
    ];

    /**
     * Le reglage plateforme brut, tel que le SuperAdmin le voit.
     *
     * @return array{free_enabled: bool, monthly_uses: ?int, alert_percent: int, offer_subscription: bool}
     */
    public function platform(): array
    {
        $stored = AiConfig::query()->whereIn('key', self::KEYS)->pluck('value', 'key')->all();

        $freeEnabled = array_key_exists(self::KEY_FREE_ENABLED, $stored)
            ? self::truthy($stored[self::KEY_FREE_ENABLED])
            : (bool) config('ai.user_credit.free_enabled', true);

        $monthlyUses = array_key_exists(self::KEY_MONTHLY_USES, $stored)
            ? self::nullableInt($stored[self::KEY_MONTHLY_USES])
            : self::nullableInt(config('ai.user_credit.monthly_uses'));

        $alertPercent = array_key_exists(self::KEY_ALERT_PERCENT, $stored)
            ? (int) $stored[self::KEY_ALERT_PERCENT]
            : (int) config('ai.user_credit.alert_percent', 80);

        // Un seuil absent ou invalide ne signifie jamais « alerte a 0 % » ni
        // « jamais d'alerte » : on retombe sur le defaut.
        if ($alertPercent < 1 || $alertPercent > 99) {
            $alertPercent = 80;
        }

        $offerSubscription = array_key_exists(self::KEY_OFFER_SUBSCRIPTION, $stored)
            ? self::truthy($stored[self::KEY_OFFER_SUBSCRIPTION])
            : (bool) config('ai.user_credit.offer_subscription', true);

        return [
            'free_enabled' => $freeEnabled,
            'monthly_uses' => $monthlyUses,
            'alert_percent' => $alertPercent,
            'offer_subscription' => $offerSubscription,
        ];
    }

    /**
     * La politique EFFECTIVE pour un utilisateur de cette Organization :
     * override d'Organization s'il existe, sinon la plateforme.
     */
    public function policyFor(Organization $organization): AiUserCreditPolicy
    {
        $platform = $this->platform();
        $platformQuota = $platform['free_enabled'] ? $platform['monthly_uses'] : 0;

        $setting = $organization->aiSetting;
        $mode = $setting?->user_credit_mode ?? OrganizationAiSetting::USER_CREDIT_MODE_PLATFORM;

        [$quota, $source] = match ($mode) {
            OrganizationAiSetting::USER_CREDIT_MODE_UNLIMITED => [null, AiUserCreditPolicy::SOURCE_ORGANIZATION],
            OrganizationAiSetting::USER_CREDIT_MODE_CUSTOM => [
                // Un mode `custom` sans valeur n'est pas « illimite » : il
                // retombe sur la plateforme, jamais sur un droit sans borne.
                $setting?->user_credit_monthly_uses !== null ? (int) $setting->user_credit_monthly_uses : $platformQuota,
                $setting?->user_credit_monthly_uses !== null ? AiUserCreditPolicy::SOURCE_ORGANIZATION : AiUserCreditPolicy::SOURCE_PLATFORM,
            ],
            default => [$platformQuota, AiUserCreditPolicy::SOURCE_PLATFORM],
        };

        return new AiUserCreditPolicy(
            monthlyUses: $quota,
            source: $source,
            freeEnabled: $platform['free_enabled'],
            platformMonthlyUses: $platform['monthly_uses'],
            alertPercent: $platform['alert_percent'],
            offerSubscription: $platform['offer_subscription'],
        );
    }

    /**
     * Ecrit le reglage plateforme et trace ce qui a change.
     *
     * @param  array{free_enabled: bool, monthly_uses: ?int, alert_percent: int, offer_subscription: bool}  $values
     */
    public function updatePlatform(array $values, ?User $author): ?AiCreditSettingChange
    {
        $before = $this->platform();
        $after = [
            'free_enabled' => (bool) $values['free_enabled'],
            'monthly_uses' => self::nullableInt($values['monthly_uses'] ?? null),
            'alert_percent' => (int) $values['alert_percent'],
            'offer_subscription' => (bool) $values['offer_subscription'],
        ];

        if ($after['alert_percent'] < 1 || $after['alert_percent'] > 99) {
            throw new InvalidArgumentException('alert_percent must be between 1 and 99.');
        }

        if ($after['monthly_uses'] !== null && $after['monthly_uses'] < 0) {
            throw new InvalidArgumentException('monthly_uses must be null or >= 0.');
        }

        return DB::transaction(function () use ($before, $after, $author): ?AiCreditSettingChange {
            AiConfig::set(self::KEY_FREE_ENABLED, $after['free_enabled'] ? '1' : '0');
            AiConfig::set(self::KEY_MONTHLY_USES, $after['monthly_uses'] === null ? '' : (string) $after['monthly_uses']);
            AiConfig::set(self::KEY_ALERT_PERCENT, (string) $after['alert_percent']);
            AiConfig::set(self::KEY_OFFER_SUBSCRIPTION, $after['offer_subscription'] ? '1' : '0');

            return $this->trace(AiCreditSettingChange::SCOPE_PLATFORM, null, $before, $after, $author);
        });
    }

    /**
     * Ecrit l'override d'Organization et trace ce qui a change.
     */
    public function updateOrganization(Organization $organization, string $mode, ?int $monthlyUses, ?User $author): ?AiCreditSettingChange
    {
        if (! in_array($mode, OrganizationAiSetting::USER_CREDIT_MODES, true)) {
            throw new InvalidArgumentException('Unknown user credit mode: '.$mode);
        }

        if ($mode === OrganizationAiSetting::USER_CREDIT_MODE_CUSTOM && ($monthlyUses === null || $monthlyUses < 0)) {
            throw new InvalidArgumentException('A custom user credit requires a monthly uses value >= 0.');
        }

        if ($mode !== OrganizationAiSetting::USER_CREDIT_MODE_CUSTOM) {
            $monthlyUses = null;
        }

        return DB::transaction(function () use ($organization, $mode, $monthlyUses, $author): ?AiCreditSettingChange {
            $setting = OrganizationAiSetting::query()->firstOrNew(['organization_id' => $organization->id]);

            // Une Organization sans configuration IA peut porter un override
            // (etat commercial) : la ligne est creee avec les memes defauts
            // que le formulaire de configuration, sans credential.
            if (! $setting->exists) {
                $setting->provider = 'openrouter';
                $setting->model = (string) (config('ai.default_model') ?: config('ai.openrouter.model', 'openai/gpt-4o-mini'));
                $setting->is_enabled = true;
            }

            $before = [
                'user_credit_mode' => $setting->user_credit_mode ?? OrganizationAiSetting::USER_CREDIT_MODE_PLATFORM,
                'user_credit_monthly_uses' => $setting->user_credit_monthly_uses !== null ? (int) $setting->user_credit_monthly_uses : null,
            ];
            $after = [
                'user_credit_mode' => $mode,
                'user_credit_monthly_uses' => $monthlyUses,
            ];

            $setting->user_credit_mode = $mode;
            $setting->user_credit_monthly_uses = $monthlyUses;
            $setting->save();

            $organization->unsetRelation('aiSetting');

            return $this->trace(AiCreditSettingChange::SCOPE_ORGANIZATION, $organization, $before, $after, $author);
        });
    }

    /**
     * Dernier changement trace pour un perimetre (affiche a l'ecran).
     */
    public function lastChange(?Organization $organization): ?AiCreditSettingChange
    {
        return AiCreditSettingChange::query()
            ->with('author:id,name')
            ->when(
                $organization === null,
                static fn ($query) => $query->where('scope', AiCreditSettingChange::SCOPE_PLATFORM),
                static fn ($query) => $query->where('scope', AiCreditSettingChange::SCOPE_ORGANIZATION)->where('organization_id', $organization->id),
            )
            ->latest('created_at')
            ->first();
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    private function trace(string $scope, ?Organization $organization, array $before, array $after, ?User $author): ?AiCreditSettingChange
    {
        $changes = [];

        foreach ($after as $field => $value) {
            if (($before[$field] ?? null) !== $value) {
                $changes[$field] = ['from' => $before[$field] ?? null, 'to' => $value];
            }
        }

        if ($changes === []) {
            return null;
        }

        return AiCreditSettingChange::create([
            'scope' => $scope,
            'organization_id' => $organization?->id,
            'changes' => $changes,
            'changed_by' => $author?->id,
        ]);
    }

    private static function truthy(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 'true', 'on', 'yes'], true);
    }

    private static function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '' || $value === false) {
            return null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        return max(0, (int) $value);
    }
}
