<?php

namespace App\Services\Acquisition;

use App\Models\AcquisitionJourney;
use App\Models\Organization;

/**
 * TASK-1446 — la resolution CANONIQUE d'une Journey (MASTER Q74) : la version
 * `published` de (Organization, key), ou NULL. Jamais un brouillon, jamais une
 * version retiree, jamais une autre Organization.
 */
final class AcquisitionJourneyResolver
{
    public function published(Organization $organization, string $key): ?AcquisitionJourney
    {
        return AcquisitionJourney::query()
            ->forOrganization($organization)
            ->forKey($key)
            ->published()
            ->orderByDesc('version')
            ->first();
    }
}
