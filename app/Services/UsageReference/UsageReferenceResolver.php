<?php

namespace App\Services\UsageReference;

use App\Models\UsageReference;

/**
 * TASK-1439 — la resolution CANONIQUE d'une UsageReference (MASTER Q66) :
 * la derniere version `published` pour (surface, locale) ; sinon la locale
 * canonique de la plateforme ; sinon NULL. Jamais un brouillon, jamais une
 * version retiree, jamais une autre surface, jamais une version arbitraire.
 * Fail-closed : aucune reference vaut mieux qu'une reference incorrecte.
 */
final class UsageReferenceResolver
{
    public function resolve(string $surfaceKey, string $locale): ?UsageReference
    {
        $locale = strtolower(trim($locale));
        $found = $this->published($surfaceKey, $locale);

        if ($found !== null) {
            return $found;
        }

        $platform = UsageReference::platformLocale();

        return $platform !== $locale ? $this->published($surfaceKey, $platform) : null;
    }

    private function published(string $surfaceKey, string $locale): ?UsageReference
    {
        if (! in_array($surfaceKey, UsageReference::SURFACES, true)) {
            return null;
        }

        return UsageReference::query()
            ->forSurface($surfaceKey)
            ->forLocale($locale)
            ->published()
            ->orderByDesc('version')
            ->first();
    }
}
