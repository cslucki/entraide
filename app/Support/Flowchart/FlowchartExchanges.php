<?php

namespace App\Support\Flowchart;

use App\Models\Organization;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * TASK-1608 — les Propositions et les Demandes servies sous le logigramme.
 *
 * ## Les memes regles metier qu'Explorer, pas une seconde verite
 *
 * `App\Livewire\Explorer` est la surface canonique de ces cards. Ses deux
 * requetes, telles qu'elles y sont ecrites :
 *
 * - Propositions — `Service::withoutGlobalScopes()->active()->where('organization_id', …)`
 *   (`Explorer.php:143`) ;
 * - Demandes — `ServiceRequest::withoutGlobalScopes()->open()->where('organization_id', …)`
 *   (`Explorer.php:199`).
 *
 * Les scopes `active()` et `open()` ne sont pas de simples filtres de statut :
 * ils excluent aussi les auteurs dont le compte n'est plus actif
 * (`whereHas('user', fn ($q) => $q->activeAccount())`). Les reecrire a la main
 * aurait donc ressuscite, sous le logigramme, des annonces que l'Explorer
 * cache. On les appelle.
 *
 * ## MEMBER-ONLY, et ce n'est pas une precaution decorative
 *
 * Arbitrage MASTER : les NOEUDS « Propositions » et « Demandes » sont publics —
 * ils disent ou mene BouclePro — mais les DONNEES reelles ne le sont pas.
 *
 * TASK-1479 puis TASK-1488 (P0 privacy) ont ferme `/explorer`,
 * `services.show`, `requests.show` et `profile.show` derriere
 * `auth` + `organization.member`. La mesure qui l'a motive est citee dans
 * `routes/web.php:457` : « sans aucun cookie, sur une Organization
 * `is_public = false`, ces routes rendaient 200 avec le NOM REEL de la
 * personne, le titre et le contenu metier ».
 *
 * Le logigramme etant PUBLIC, y verser ces cards sans garde rouvrirait
 * exactement cette fuite. La garde est donc posee ici, en premiere ligne de
 * chaque lecture : sans membre du tenant VISITE, la requete n'est jamais
 * emise.
 */
final class FlowchartExchanges
{
    /** Assez pour montrer que la communaute vit, pas assez pour en faire un catalogue. */
    private const MAX_CARTES = 6;

    private const MAX_EXTRAIT = 140;

    /**
     * Les Propositions de CETTE Organization, pour un membre de CETTE Organization.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function proposals(Organization $organization, ?User $user): Collection
    {
        if (! $this->autorise($organization, $user)) {
            return collect();
        }

        return Service::withoutGlobalScopes()
            ->with(['user', 'category'])
            ->active()
            ->where('organization_id', $organization->id)
            ->latest()
            ->limit(self::MAX_CARTES)
            ->get()
            ->map(fn (Service $service): array => $this->carte(
                $service->id,
                $service->title,
                $service->description,
                $service->user?->name,
                $service->category?->displayName('explorer'),
                $this->url('organization.services.show', 'services.show', $organization, 'service', $service->id),
            ))
            ->values();
    }

    /**
     * Les Demandes de CETTE Organization, pour un membre de CETTE Organization.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function requests(Organization $organization, ?User $user): Collection
    {
        if (! $this->autorise($organization, $user)) {
            return collect();
        }

        return ServiceRequest::withoutGlobalScopes()
            ->with(['user', 'category'])
            ->open()
            ->where('organization_id', $organization->id)
            ->latest()
            ->limit(self::MAX_CARTES)
            ->get()
            ->map(fn (ServiceRequest $demande): array => $this->carte(
                $demande->id,
                $demande->title,
                $demande->description,
                $demande->user?->name,
                $demande->category?->displayName('explorer'),
                $this->url('organization.requests.show', 'requests.show', $organization, 'request', $demande->id),
            ))
            ->values();
    }

    /**
     * Qui a le droit de voir ces donnees.
     *
     * Appartenir a l'Organization VISITEE, et rien d'autre. Un visiteur
     * connecte venu d'ailleurs est traite comme un anonyme : son identite ne
     * lui ouvre pas le contenu d'un tenant dont il n'est pas membre.
     */
    private function autorise(Organization $organization, ?User $user): bool
    {
        return $user !== null && $user->organization_id === $organization->id;
    }

    /**
     * La fiche existante, jamais une page inventee.
     *
     * Ces deux routes sont `auth` + `organization.member` : le CTA mene donc a
     * une surface qui revalide l'appartenance au moment du clic. Le logigramme
     * n'accorde rien.
     */
    private function url(string $scoped, string $global, Organization $organization, string $parametre, string $id): string
    {
        if (Route::has($scoped)) {
            return route($scoped, ['organization' => $organization->slug, $parametre => $id]);
        }

        return route($global, [$parametre => $id]);
    }

    /** @return array<string, mixed> */
    private function carte(string $id, ?string $titre, ?string $description, ?string $auteur, ?string $categorie, string $url): array
    {
        return [
            'id' => $id,
            'title' => trim((string) $titre),
            'excerpt' => Str::limit(trim(strip_tags((string) $description)), self::MAX_EXTRAIT, '…'),
            'author' => trim((string) $auteur),
            'category' => $categorie ? trim($categorie) : null,
            'url' => $url,
        ];
    }
}
