<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\TranslationOverride;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Lang;

class TranslationOverrideService
{
    public function get(
        string $group,
        string $key,
        string $locale,
        ?Organization $organization = null,
        array $replace = [],
    ): string {
        $value = $this->resolveOverride($group, $key, $locale, $organization);

        if ($value !== null) {
            return $this->applyReplacements($value, $replace);
        }

        return Lang::get("{$group}.{$key}", $replace, $locale);
    }

    public function has(
        string $group,
        string $key,
        string $locale,
        ?Organization $organization = null,
    ): bool {
        return $this->resolveOverride($group, $key, $locale, $organization) !== null;
    }

    public function set(
        string $group,
        string $key,
        string $locale,
        string $value,
        ?Organization $organization = null,
        ?string $userId = null,
    ): TranslationOverride {
        $attributes = [
            'organization_id' => $organization?->id,
            'locale' => $locale,
            'group' => $group,
            'key' => $key,
        ];

        return TranslationOverride::updateOrCreate(
            $attributes,
            [
                'value' => $value,
                'is_active' => true,
                'updated_by' => $userId,
                'created_by' => $userId,
            ],
        );
    }

    public function deactivate(
        string $group,
        string $key,
        string $locale,
        ?Organization $organization = null,
    ): bool {
        return TranslationOverride::query()
            ->forOrganization($organization?->id)
            ->forLocale($locale)
            ->forKey($group, $key)
            ->update(['is_active' => false]) > 0;
    }

    public function allForOrganization(?string $organizationId, string $locale): array
    {
        return $this->loadOverrides($organizationId, $locale);
    }

    protected function resolveOverride(
        string $group,
        string $key,
        string $locale,
        ?Organization $organization = null,
    ): ?string {
        $lookup = "{$group}.{$key}";

        if ($organization !== null) {
            $orgOverrides = $this->loadOverrides($organization->id, $locale);

            if (array_key_exists($lookup, $orgOverrides)) {
                return $orgOverrides[$lookup];
            }
        }

        $globalOverrides = $this->loadOverrides(null, $locale);

        return $globalOverrides[$lookup] ?? null;
    }

    protected function loadOverrides(?string $organizationId, string $locale): array
    {
        $orgKey = $organizationId ?? 'global';
        $cacheKey = "translation_overrides:{$orgKey}:{$locale}";

        return Cache::remember($cacheKey, 3600, function () use ($organizationId, $locale) {
            return TranslationOverride::query()
                ->active()
                ->forOrganization($organizationId)
                ->forLocale($locale)
                ->get()
                ->mapWithKeys(fn (TranslationOverride $o) => ["{$o->group}.{$o->key}" => $o->value])
                ->all();
        });
    }

    protected function applyReplacements(string $value, array $replace): string
    {
        if (empty($replace)) {
            return $value;
        }

        foreach ($replace as $key => $replacement) {
            $value = str_replace(":{$key}", (string) $replacement, $value);
        }

        return $value;
    }
}
