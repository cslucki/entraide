<?php

namespace App\Services\Dossiers;

use App\Models\Organization;

class DossierSemanticSearchGate
{
    public function isEnabledFor(string $organizationId): bool
    {
        if (! (bool) config('ai.dossiers.semantic_search.enabled', false)) {
            return false;
        }

        $organizationId = $this->normalize($organizationId);

        if ($organizationId === '') {
            return false;
        }

        foreach ($this->configuredOrganizationIds() as $allowedId) {
            if ($organizationId === $allowedId) {
                return true;
            }
        }

        $slugs = $this->configuredOrganizationSlugs();

        return $slugs !== [] && Organization::query()
            ->whereKey($organizationId)
            ->whereIn('slug', $slugs)
            ->exists();
    }

    /**
     * @return array<int, string>
     */
    private function configuredOrganizationIds(): array
    {
        $ids = config('ai.dossiers.semantic_search.organization_ids', []);

        if (is_string($ids)) {
            $ids = explode(',', $ids);
        }

        if (! is_array($ids)) {
            return [];
        }

        return array_values(array_filter(
            array_map(fn (mixed $id): string => $this->normalize((string) $id), $ids),
            fn (string $id): bool => $id !== ''
        ));
    }

    /**
     * @return array<int, string>
     */
    private function configuredOrganizationSlugs(): array
    {
        $slugs = config('ai.dossiers.semantic_search.organization_slugs', []);

        if (is_string($slugs)) {
            $slugs = explode(',', $slugs);
        }

        if (! is_array($slugs)) {
            return [];
        }

        return array_values(array_filter(
            array_map(fn (mixed $slug): string => $this->normalize((string) $slug), $slugs),
            fn (string $slug): bool => $slug !== ''
        ));
    }

    private function normalize(string $organizationId): string
    {
        return mb_strtolower(trim($organizationId));
    }
}
