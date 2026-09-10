<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Organization;
use App\Models\Scopes\BelongsToOrganizationScope;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Homepage\RootDestination;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class HomeController extends Controller
{
    public function index(): RedirectResponse|View
    {
        $defaultOrganization = Organization::where('is_default', true)->first()
            ?? Organization::where('slug', 'main')->where('is_active', true)->first();

        // TASK-1506 — le superadmin choisit ce que sert la racine
        // (`/admin/homepage`). NULL, ou une valeur inconnue laissee en base,
        // vaut le comportement historique : cette branche ne s'ouvre que sur
        // un choix explicite et valide.
        $destination = RootDestination::normalize($defaultOrganization?->root_destination);

        if ($destination === RootDestination::SHELL_WELCOME && $defaultOrganization !== null) {
            // La landing de l'Organization ; le mode d'affichage du Guest
            // Shell (TASK-1500) decide seul de sa forme.
            return redirect()->route('organization.home', $defaultOrganization);
        }

        if (($route = RootDestination::routeFor($destination)) !== null) {
            // `members.index` est derriere `auth` depuis TASK-1479 (P0
            // privacy) : un anonyme y rencontre la connexion, puis revient.
            return redirect()->route($route);
        }

        if ($defaultOrganization?->homepage_template === 'bouclepro_hero_v2' || $defaultOrganization?->homepage_template === 'artscilab_hero') {
            return redirect()->route('organization.home', $defaultOrganization);
        }

        $stats = [
            'users' => User::activeAccount()->count(),
            'services' => Service::active()->count(),
            'requests' => ServiceRequest::open()->count(),
            'exchanges' => Transaction::where('status', 'completed')->count(),
        ];

        $featuredServices = Service::active()
            ->with('category', 'user')
            ->inRandomOrder()
            ->limit(6)
            ->get();

        $categories = Category::orderBy('name_b2c')->get();

        return view('home', compact('stats', 'featuredServices', 'categories', 'defaultOrganization'));
    }

    public function members(): View
    {
        $organization = currentOrganization();

        if (! $organization) {
            return view('members.setup-required');
        }

        $organizationId = $organization->id;

        $members = User::where('organization_id', $organizationId)
            ->activeAccount()
            ->withCount([
                'services as active_services_count' => fn ($q) => $q->withoutGlobalScope(BelongsToOrganizationScope::class)->active()->where('organization_id', $organizationId),
                'serviceRequests as open_requests_count' => fn ($q) => $q->withoutGlobalScope(BelongsToOrganizationScope::class)->open()->where('organization_id', $organizationId),
            ])
            ->with(['services' => fn ($q) => $q->withoutGlobalScope(BelongsToOrganizationScope::class)->active()->where('organization_id', $organizationId)->with('skills', 'category')])
            ->orderByDesc('created_at')
            ->paginate(16);

        return view('members.index', compact('members'));
    }

    public function boucles(): View
    {
        return view('boucles.index');
    }

    public function partners(): View
    {
        return view('partenaires.index');
    }

    public function exchanges(): View
    {
        $organization = currentOrganization();

        if (! $organization) {
            abort(404);
        }

        $organizationId = $organization->id;

        $exchanges = Transaction::withoutGlobalScope(BelongsToOrganizationScope::class)
            ->where('status', 'completed')
            ->where('organization_id', $organizationId)
            ->with(['buyer', 'seller', 'service.category', 'serviceRequest', 'reviews'])
            ->latest('updated_at')
            ->paginate(20);

        return view('exchanges.index', compact('exchanges'));
    }
}
