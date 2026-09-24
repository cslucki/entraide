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
        // (`/admin/homepage`).
        //
        // TASK-1628 — et ce choix est SOUVERAIN.
        //
        // `RootDestination::effective()` porte desormais seule la resolution :
        // choix explicite s'il y en a un, sinon le repli historique (gabarit
        // hero -> landing de l'Organization). Elle etait ecrite ici en clair,
        // et l'ecran d'administration, lui, lisait `normalize()` — les deux
        // divergeaient donc par construction. Une seule fonction, deux
        // appelants : l'ecran ne peut plus annoncer autre chose que ce que
        // cette methode sert.
        //
        // Le defaut mesure par Cyril tenait a cette divergence : « Accueil
        // traditionnel » enregistre et confirme a l'ecran, et le visiteur
        // arrive sur le Shell, parce que `normalize()` rabat NULL sur
        // `HOMEPAGE` et rendait « jamais choisi » indiscernable de « choisi ».
        $stored = $defaultOrganization?->root_destination;
        $destination = RootDestination::effective($stored, $defaultOrganization?->homepage_template);

        if ($destination === RootDestination::SHELL_WELCOME && $defaultOrganization !== null) {
            // La landing de l'Organization ; le mode d'affichage du Guest
            // Shell (TASK-1500) decide seul de sa forme. C'est aussi la sortie
            // du repli historique : un gabarit hero sans choix explicite.
            return redirect()->route('organization.home', $defaultOrganization);
        }

        if (($route = RootDestination::routeFor($destination)) !== null) {
            // `members.index` est derriere `auth` depuis TASK-1479 (P0
            // privacy) : un anonyme y rencontre la connexion, puis revient.
            return redirect()->route($route);
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
