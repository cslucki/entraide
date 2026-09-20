<?php

namespace App\Services\Workshops;

use App\Models\Organization;
use App\Models\Workshop;
use App\Models\WorkshopSession;
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

    /** TASK-1611 — le HERO en montre au plus deux : au-dela, il concurrence le slogan. */
    public const HERO_MAX = 2;

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

    /**
     * TASK-1611 — LES MEMES ateliers, vus a la maille de la DATE.
     *
     * Le HERO ne demande pas « quels ateliers sont ouverts » mais « qu'est-ce
     * qui se passe ensuite » : l'unite y est la SESSION publiee a venir, pas
     * l'atelier. Aucune regle metier nouvelle n'est prise ici — la selection
     * reste EXACTEMENT celle de `upcoming()` (atelier publie de CETTE
     * Organization, session publiee et a venir) ; on l'aplatit et on la trie
     * par date. Fonction PURE : elle ne requete rien, elle lit les sessions
     * deja chargees par `upcoming()`, donc le HERO ne coute aucune requete de
     * plus que le bloc qu'il remplace.
     *
     * Borne : `upcoming()` rend les `MAX` ateliers les plus proches, et les
     * `HERO_MAX` sessions les plus proches de l'Organization sont
     * necessairement portees par ces ateliers-la (l'atelier N+1 commence apres
     * l'atelier N) tant que `HERO_MAX <= MAX`.
     *
     * @param  Collection<int, Workshop>  $workshops  le resultat de `upcoming()`
     * @return Collection<int, WorkshopSession> sessions triees, relation `workshop` posee
     */
    public static function heroSessions(Collection $workshops, int $max = self::HERO_MAX): Collection
    {
        return $workshops
            ->flatMap(fn (Workshop $workshop) => $workshop->sessions
                // `withoutRelations()` et pas `$workshop` : poser l'atelier
                // ENTIER sur sa propre session refermerait le graphe sur
                // lui-meme (atelier -> sessions -> atelier -> sessions...), et
                // toute serialisation de cet objet boucleraient jusqu'a
                // epuisement memoire. La vue n'a besoin que des ATTRIBUTS de
                // l'atelier ; le clone les porte tous.
                ->map(fn (WorkshopSession $session) => $session->setRelation('workshop', $workshop->withoutRelations())))
            ->sortBy(fn (WorkshopSession $session) => $session->starts_at)
            ->take($max)
            ->values();
    }
}
