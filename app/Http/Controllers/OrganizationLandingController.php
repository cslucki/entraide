<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Organization;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\Transaction;
use App\Services\GuestShell\GuestShellSurface;
use App\Support\GuestShell\GuestShellDisplayMode;
use App\Services\Workshops\PublicWorkshopListing;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class OrganizationLandingController extends Controller
{
    public function __invoke(Request $request, string $organization): View|RedirectResponse
    {
        $organization = Organization::findBySlug($organization);
        abort_if(! $organization || ! $organization->is_active, 404);

        if (! $organization->is_public && ! auth()->check()) {
            return redirect()->route('organization.login', ['organization' => $organization->slug]);
        }

        // TASK-1442 — SW-8a : lecture PURE du Shell Welcome (aucun cookie, aucune identite, aucun appel) ; l'overlay se monte selon la decision.
        $guestShell = $organization->is_public ? app(GuestShellSurface::class)->read($organization, $request) : null;

        // TASK-1463 (audit OPUS final P1-1) : les ateliers publies a session publiee a venir — la meme selection que
        // workshops.runtime ; lecture pure (aucun cookie), seulement pour une Organization active et publique.
        $publicWorkshops = $organization->is_public && $organization->is_active ? app(PublicWorkshopListing::class)->upcoming($organization) : collect();

        // TASK-1494 — le mode SHELL FIRST decide AVANT le gabarit de landing.
        //
        // TASK-1443 avait ecrit l'inverse, et l'assumait : « shell_first : le
        // Shell est l'experience principale, inclus en HAUT, le contenu public
        // classique reste ENTIER juste en dessous. » Mesure de Cyril : le grand
        // Shell s'affiche, et la landing marketing complete se deroule derriere.
        // C'etait donc le contrat, pas un accident — et MASTER l'a arbitre
        // autrement : en Shell First, rendre la landing entiere DEDOUBLE
        // l'experience au lieu de la remplacer.
        //
        // La decision est prise ici, une seule fois, plutot que par une
        // conditionnelle dans chacun des trois gabarits (`home`, `hero-v2`,
        // `artscilab-hero`, 518 lignes de balisage marketing) : aucun d'eux
        // n'est modifie, donc les modes `overlay` et OFF ne peuvent pas
        // regresser.
        //
        // On lit le mode EFFECTIF (`display.mode` quand le Shell est visible),
        // jamais la colonne : un Shell degrade ou refuse par la garde
        // economique doit continuer de rendre la landing normale, sans quoi une
        // Organization mal configuree n'aurait plus d'accueil du tout.
        if (($guestShell['display']['visible'] ?? false)
            && GuestShellDisplayMode::isShellFirst($guestShell['display']['mode'] ?? null)) {
            return view('organization.shell-first', compact('organization', 'guestShell'));
        }

        $stats = [
            'users' => $organization->users()->activeAccount()->count(),
            'services' => Service::where('organization_id', $organization->id)->active()->count(),
            'requests' => ServiceRequest::where('organization_id', $organization->id)->open()->count(),
            'exchanges' => Transaction::where('organization_id', $organization->id)->where('status', 'completed')->count(),
        ];

        $featuredServices = Service::where('organization_id', $organization->id)
            ->active()
            ->with('category', 'user')
            ->inRandomOrder()
            ->limit(6)
            ->get();

        $categories = Category::where('organization_id', $organization->id)->orderBy('name_b2c')->get();

        if ($organization->homepage_template === 'bouclepro_hero_v2') {
            $heroAvatars = $organization->users()
                ->activeAccount()
                ->whereNotNull('avatar')
                ->latest()
                ->limit(12)
                ->get(['id', 'name', 'avatar'])
                ->map(fn ($user) => $user->avatar_url)
                ->values();

            return view('organization.hero-v2', compact('organization', 'heroAvatars', 'guestShell', 'publicWorkshops'));
        }

        if ($organization->homepage_template === 'artscilab_hero') {
            $heroAvatars = $organization->users()
                ->activeAccount()
                ->whereNotNull('avatar')
                ->latest()
                ->limit(16)
                ->get(['id', 'name', 'avatar'])
                ->map(fn ($user) => $user->avatar_url)
                ->values();

            return view('organization.artscilab-hero', compact('organization', 'heroAvatars', 'guestShell', 'publicWorkshops'));
        }

        $defaultOrganization = $organization;

        return view('organization.home', compact('organization', 'stats', 'featuredServices', 'categories', 'defaultOrganization', 'guestShell', 'publicWorkshops'));
    }

    public function about(string $organization): View
    {
        $organization = Organization::findBySlug($organization);
        abort_if(! $organization || ! $organization->is_active, 404);

        return view('organization.about-launchpals', compact('organization'));
    }
}
