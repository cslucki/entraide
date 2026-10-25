<?php

namespace App\Http\Controllers;

use App\Models\BlogPost;
use App\Models\Category;
use App\Models\LoopMember;
use App\Models\Organization;
use App\Models\Scopes\BelongsToOrganizationScope;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Homepage\RootDestination;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
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

        // TASK-1697 correctif 15 — recherche simple sur le prenom et le nom.
        //
        // `users.name` porte le nom de FAMILLE depuis TASK-1399 et `first_name`
        // est NULLABLE : chercher sur l'un des deux seulement laisserait la
        // moitie des gens introuvables. La troisieme clause couvre la saisie
        // complete (« cyril s »), que ni l'un ni l'autre des champs ne contient.
        //
        // `ilike` en PostgreSQL, `like` en SQLite : meme precedent que
        // `DossierFileController`. `like` est deja insensible a la casse en
        // SQLite, il ne l'est pas en PostgreSQL.
        $recherche = trim((string) request()->query('q', ''));

        $members = User::where('organization_id', $organizationId)
            ->activeAccount()
            ->when($recherche !== '', function ($q) use ($recherche) {
                $like = DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
                $motif = '%'.$recherche.'%';

                $q->where(function ($w) use ($like, $motif) {
                    $w->where('first_name', $like, $motif)
                        ->orWhere('name', $like, $motif)
                        ->orWhereRaw("COALESCE(first_name, '') || ' ' || COALESCE(name, '') ".$like.' ?', [$motif]);
                });
            })
            ->withCount([
                'services as active_services_count' => fn ($q) => $q->withoutGlobalScope(BelongsToOrganizationScope::class)->active()->where('organization_id', $organizationId),
                'serviceRequests as open_requests_count' => fn ($q) => $q->withoutGlobalScope(BelongsToOrganizationScope::class)->open()->where('organization_id', $organizationId),
            ])
            ->with(['services' => fn ($q) => $q->withoutGlobalScope(BelongsToOrganizationScope::class)->active()->where('organization_id', $organizationId)->with('skills', 'category')])
            ->orderByDesc('created_at')
            ->paginate(16)
            ->withQueryString();

        $identifiants = $members->getCollection()->pluck('id');

        // TASK-1697 correctif 15 — le compteur d'articles.
        //
        // MEME predicat que `ProfileController::show()` (lignes 100-108), pour
        // que le compteur de l'annuaire ne contredise pas la liste du profil.
        // Deliberement PAS `scopePublished()`, qui ajoute `listed_in_blog` : le
        // profil l'ignore, l'annuaire doit l'ignorer aussi.
        $articleCounts = $identifiants->isEmpty() ? collect() : BlogPost::query()
            ->whereIn('user_id', $identifiants)
            ->where('organization_id', $organizationId)
            ->where('status', 'published')
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now())
            ->selectRaw('user_id, COUNT(*) as total')
            ->groupBy('user_id')
            ->pluck('total', 'user_id');

        // TASK-1697 correctif 15 — les Boucles de chaque membre.
        //
        // Le perimetre reprend celui de `VisibleLoops` : Organization courante,
        // Boucles `active` (donc jamais les archivees). Nommer une Boucle ne
        // revele rien de plus que le catalogue : depuis TASK-1075, « privee »
        // veut dire « contenu reserve aux membres », pas « cachee ».
        //
        // Le filtre porte sur `loops.organization_id`, JAMAIS sur
        // `loop_members.organization_id` : cette colonne est nullable et 3
        // lignes sur 24 y sont NULL en base — le filtre les perdrait en
        // silence. L'organisation de la Boucle est l'autorite.
        //
        // Aucun filtre de role : `LoopRoleRegistry` en connait trois (owner,
        // facilitator, member) plus l'alias historique `moderator`. « Membre,
        // animateur ou proprietaire » les couvre tous — c'est l'appartenance
        // ACTIVE qui est le critere, pas le role.
        $loopsByUser = $identifiants->isEmpty() ? collect() : LoopMember::query()
            ->whereIn('user_id', $identifiants)
            ->where('status', 'active')
            ->whereHas('loop', fn ($q) => $q->where('organization_id', $organizationId)->where('status', 'active'))
            ->with(['loop' => fn ($q) => $q->select('id', 'name', 'slug', 'organization_id', 'status')])
            ->get()
            ->groupBy('user_id')
            ->map(fn ($lignes) => $lignes->pluck('loop')->filter()->unique('id')->sortBy('name')->values());

        return view('members.index', compact('members', 'recherche', 'articleCounts', 'loopsByUser'));
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
