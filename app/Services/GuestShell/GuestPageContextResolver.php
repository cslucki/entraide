<?php

namespace App\Services\GuestShell;

use App\Models\Organization;
use App\Models\Workshop;
use App\Support\GuestShell\GuestPageContext;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;

/**
 * TASK-1440 — Guest PageContext V1 (V3 §10, MASTER Q68) : le PageContext se
 * deduit d'une WHITELIST de routes nommees connues — jamais d'un parsing
 * generique de l'URL. Route inconnue ou non publique = NULL ; route portant
 * une autre Organization que celle deja determinee = NULL (fail-closed) ;
 * Organization inactive ou non publique = NULL.
 *
 * Aucune exploration : ni Loop, ni CRM, ni User, ni Dossier, ni messages.
 * Les seules lectures sont la route courante et l'Organization recue.
 */
final class GuestPageContextResolver
{
    /**
     * Les routes publiques REELLES → kind. TASK-1450 : la page d'atelier existe
     * (`organization.workshop.show`) → `workshop_page` est resolvable ; le kind
     * `workshop_session` n'a toujours pas de route et reste NULL (pas de faux contexte).
     */
    private const ROUTES = [
        'organization.home' => GuestPageContext::KIND_ORGANIZATION_HOME,
        'organization.register' => GuestPageContext::KIND_SIGNUP,
        'organization.workshop.show' => GuestPageContext::KIND_WORKSHOP_PAGE,
    ];

    public function fromRequest(Organization $organization, Request $request): ?GuestPageContext
    {
        return $this->fromRoute($organization, $request->route());
    }

    public function fromRoute(Organization $organization, ?Route $route): ?GuestPageContext
    {
        if ($route === null || ! $organization->is_active || ! $organization->is_public) {
            return null;
        }

        $name = (string) $route->getName();
        $kind = self::ROUTES[$name] ?? null;
        if ($kind === null) {
            return null;
        }

        // La route porte une Organization : ce DOIT etre celle deja determinee.
        $parameter = $route->parameter('organization') ?? $route->parameter('community');
        $slug = $parameter instanceof Organization ? (string) $parameter->slug : (is_string($parameter) ? $parameter : null);
        if ($slug === null || $slug !== (string) $organization->slug) {
            return null;
        }

        if ($kind === GuestPageContext::KIND_WORKSHOP_PAGE) {
            // L'atelier PUBLIE de CETTE Organization, par son slug de route — jamais un parsing d'URL, jamais un brouillon.
            $slugParameter = $route->parameter('workshop');
            $workshop = is_string($slugParameter) ? Workshop::query()->forOrganization($organization)->published()->where('slug', $slugParameter)->first() : null;

            return $workshop === null ? null : $this->workshopPage($organization, $workshop, $name);
        }

        return $this->make($organization, $kind, $name);
    }

    /** TASK-1450 — le PageContext d'un atelier publie : identifiant public = slug, libelle = titre, aucune CTA inventee avant B4. */
    private function workshopPage(Organization $organization, Workshop $workshop, string $name): GuestPageContext
    {
        return new GuestPageContext(
            organizationId: (string) $organization->id,
            kind: GuestPageContext::KIND_WORKSHOP_PAGE,
            publicId: $workshop->slug,
            publicLabel: (string) $workshop->title,
            publicCta: null,
            routeName: $name,
        );
    }

    /**
     * TASK-1441 — le PageContext de l'accueil public de CETTE Organization, sans
     * requete en cours (cockpits OrgAdmin/SuperAdmin) : exactement le DTO que
     * la route `organization.home` produirait, memes gardes fail-closed.
     */
    public function organizationHome(Organization $organization): ?GuestPageContext
    {
        if (! $organization->is_active || ! $organization->is_public) {
            return null;
        }

        return $this->make($organization, GuestPageContext::KIND_ORGANIZATION_HOME, 'organization.home');
    }

    private function make(Organization $organization, string $kind, string $name): GuestPageContext
    {
        return match ($kind) {
            GuestPageContext::KIND_ORGANIZATION_HOME => new GuestPageContext(
                organizationId: (string) $organization->id,
                kind: $kind,
                publicId: null,
                publicLabel: (string) $organization->name,
                publicCta: ['label' => __('guest_shell.page.cta_signup'), 'url' => route('organization.register', ['organization' => $organization->slug])],
                routeName: $name,
            ),
            GuestPageContext::KIND_SIGNUP => new GuestPageContext(
                organizationId: (string) $organization->id,
                kind: $kind,
                publicId: null,
                publicLabel: __('guest_shell.page.signup_label', ['name' => $organization->name]),
                publicCta: null,
                routeName: $name,
            ),
        };
    }
}
