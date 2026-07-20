<?php

namespace App\Services\Dossiers;

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

        return false;
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

    private function normalize(string $organizationId): string
    {
        return mb_strtolower(trim($organizationId));
    }
}
