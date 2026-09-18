<?php

namespace App\Services\Workshops;

use App\Models\Organization;
use App\Models\Workshop;
use Illuminate\Support\Collection;

/**
 * TASK-1463 (audit OPUS final P1-1, Growth V3 §8/§17) — LA selection publique
 * des ateliers, une seule fois : les ateliers PUBLIES de l'Organization ayant
 * au moins une session PUBLIEE a venir, tries par prochaine session, bornes.
 * Deja prouvee par `workshops.runtime` (T1461) ; reutilisee par le bloc
 * « Ateliers » de l'accueil public. Lecture pure : aucun cookie, aucune
 * identite, aucune donnee privee (meeting_url, inscrits, capacite) — chaque
 * atelier porte ses sessions publiees a venir, la premiere est la prochaine.
 */
final class PublicWorkshopListing
{
    public const MAX = 3;

    /** @return Collection<int, Workshop> */
    public function upcoming(Organization $organization, int $max = self::MAX): Collection
    {
        return Workshop::query()
            ->forOrganization($organization)
            ->published()
            ->with(['sessions' => fn ($q) => $q->published()->upcoming()->orderBy('starts_at')])
            ->get()
            ->filter(fn (Workshop $workshop) => $workshop->sessions->isNotEmpty())
            ->sortBy(fn (Workshop $workshop) => $workshop->sessions->first()->starts_at)
            ->take($max)
            ->values();
    }
}
