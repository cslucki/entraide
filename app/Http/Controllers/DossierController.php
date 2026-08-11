<?php

namespace App\Http\Controllers;

use App\Models\ArticleSeries;
use App\Models\ArticleSeriesItem;
use App\Models\BlogPost;
use App\Models\Category;
use App\Models\Dossier;
use App\Models\Loop;
use App\Services\Dossiers\DossierArticleIndexingDispatcher;
use App\Services\Dossiers\DossierSemanticSearchGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class DossierController extends Controller
{
    public function index(Request $request): View
    {
        $organization = $this->currentOrganizationOrFail();
        $this->authorize('viewAny', Dossier::class);

        $userId = $request->user()->id;

        $ownedDossiers = Dossier::query()
            ->where('organization_id', $organization->id)
            ->where('owner_id', $userId)
            ->withCount('dossierMembers')
            ->latest('updated_at')
            ->paginate(12)
            ->withQueryString();

        $sharedDossiers = Dossier::query()
            ->where('organization_id', $organization->id)
            ->where('owner_id', '!=', $userId)
            ->whereHas('dossierMembers', function ($q) use ($userId) {
                $q->where('user_id', $userId);
            })
            ->with(['owner:id,first_name,name,email,banned_at,organization_id', 'dossierMembers' => function ($q) use ($userId) {
                $q->where('user_id', $userId);
            }])
            ->latest('updated_at')
            ->get();

        // Les Dossiers racines de mes Boucles. Ils n'ont ni owner ni lignes
        // dossier_members — les deux requetes ci-dessus ne peuvent pas les
        // voir, et un membre ne retrouvait son Dossier de Boucle par aucune
        // navigation. L'acces derive du meme critere que la policy view() :
        // membre actif de la Boucle, dans l'Organization courante.
        $loopDossiers = Dossier::query()
            ->where('organization_id', $organization->id)
            ->whereNotNull('loop_id')
            ->whereHas('loop.activeMembers', fn ($q) => $q->where('user_id', $userId))
            ->with('loop:id,name,organization_id,status')
            ->latest('updated_at')
            ->get();

        return view('dossiers.index', [
            'dossiers' => $ownedDossiers,
            'sharedDossiers' => $sharedDossiers,
            'loopDossiers' => $loopDossiers,
        ]);
    }

    public function create(): View
    {
        $this->currentOrganizationOrFail();
        $this->authorize('create', Dossier::class);

        return view('dossiers.create');
    }

    public function show(Request $request, DossierSemanticSearchGate $semanticSearchGate): View
    {
        $dossier = $this->resolveDossier($request->route('dossier'));
        $organization = $this->currentOrganizationOrFail();
        $this->ensureDossierBelongsToCurrentOrganization($dossier);
        $this->authorize('view', $dossier);

        $user = $request->user();
        $userId = $user->id;
        $isOwner = $dossier->owner_id === $userId;

        // Le role **affiche**. Pour un Dossier racine, il derive de la Boucle :
        // owner_id est null par doctrine et dossier_members est vide par
        // construction — les lire rendait `role_none` au proprietaire de la
        // Boucle. La gouvernance, elle, ne se lit pas ici : elle se demande aux
        // policies, plus bas.
        if ($dossier->isLoopDossier()) {
            $loopRole = app(\App\Support\Loops\LoopRoleRegistry::class)->canonical(
                $dossier->loop?->activeMembers()->where('user_id', $userId)->value('role'),
            );

            $userRole = $dossier->loop?->activeMembers()->where('user_id', $userId)->exists()
                ? 'loop_'.$loopRole
                : 'none';
        } else {
            $userRole = $isOwner ? 'owner' : ($dossier->memberRoleFor($userId) ?? 'none');
        }

        // Les capacites viennent des policies — la meme verite que le serveur
        // au moment d'agir. Les recalculer ici depuis owner_id/dossier_members
        // laissait le Dossier racine sans aucun bouton : la policy autorisait,
        // l'ecran cachait.
        $canManageArticles = $user->can('attachArticle', $dossier);

        $dossier->load([
            'owner:id,first_name,name,banned_at,organization_id',
            'dossierBlogPosts.blogPost.user:id,first_name,name,email,organization_id,banned_at',
            'dossierBlogPosts.blogPost.coAuthors:id,first_name,name,email,organization_id,banned_at',
            'dossierMembers.user:id,first_name,name,email,organization_id,banned_at',
            'loop:id,name,organization_id,status',
            'loop.activeMembers.user:id,first_name,name,email,organization_id,banned_at',
        ]);

        // Toutes les Series du Dossier — il peut en porter plusieurs depuis
        // TASK-1095 — dans un ordre **explicite**, donc stable. Un `->first()`
        // sans `orderBy` rendait une Serie dont le choix dependait du plan
        // d'execution.
        $seriesList = ArticleSeries::where('dossier_id', $dossier->id)
            ->where('organization_id', $organization->id)
            ->with([
                'rootBlogPost:id,organization_id,user_id,title,slug,status,updated_at,published_at',
                'rootBlogPost.user:id,first_name,name,email,organization_id,banned_at',
                'rootBlogPost.coAuthors:id,first_name,name,email,organization_id,banned_at',
                'items.blogPost:id,organization_id,user_id,title,slug,status,updated_at,published_at',
                'items.blogPost.user:id,first_name,name,email,organization_id,banned_at',
                'items.blogPost.coAuthors:id,first_name,name,email,organization_id,banned_at',
                'items.dossierFile:id,organization_id,dossier_id,original_name,display_name,mime_type,size_bytes,updated_at',
            ])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        // L'onglet « contenus » n'en connait qu'une, et ce n'est pas a cette
        // tache de le changer : il garde la plus ancienne.
        $series = $seriesList->first();

        $eligibleArticles = collect();
        if ($canManageArticles) {
            $eligibleArticles = BlogPost::query()
                ->with('user:id,first_name,name,email,organization_id')
                ->where('organization_id', $organization->id)
                ->where('user_id', $userId)
                ->whereDoesntHave('dossierEntry')
                ->latest('updated_at')
                ->get(['id', 'organization_id', 'user_id', 'title', 'slug', 'status', 'updated_at']);
        }

        $seriesEligibleArticles = collect();
        if ($canManageArticles && $series) {
            $seriesEligibleArticles = $dossier->articles()
                ->whereNotIn('blog_posts.id', array_merge(
                    [$series->root_blog_post_id],
                    $series->items->pluck('blog_post_id')->toArray()
                ))
                ->get(['blog_posts.id', 'blog_posts.organization_id', 'user_id', 'title', 'slug', 'status', 'blog_posts.updated_at']);
        } elseif ($canManageArticles && ! $series) {
            $seriesEligibleArticles = $dossier->articles()
                ->get(['blog_posts.id', 'blog_posts.organization_id', 'user_id', 'title', 'slug', 'status', 'blog_posts.updated_at']);
        }

        $canViewFiles = $user->can('viewFiles', $dossier);
        $canManageFiles = $user->can('manageFiles', $dossier);
        $canDeleteFiles = $user->can('deleteFile', $dossier);

        $categories = Category::where('organization_id', $organization->id)
            ->orderBy('name_b2c')
            ->get(['id', 'name_b2c', 'name_b2b']);

        // ── Le Drive (TASK-1130) ─────────────────────────────────────────
        // Les dossiers du Drive racine d'une Boucle sont les Dossiers
        // reellement partages avec elle — objets reels, policies reelles,
        // aucune hierarchie simulee. Un Dossier partage remonte, lui, vers le
        // racine de sa Boucle : deux niveaux, parce que le modele n'en a que
        // deux (l'imbrication reelle exigerait `parent_id` — TASK-1131).
        $driveFolders = $dossier->isLoopDossier()
            ? Dossier::where('organization_id', $organization->id)
                ->where('shared_with_loop_id', $dossier->loop_id)
                ->where('visibility', Dossier::VISIBILITY_LOOP)
                ->withCount(['files', 'dossierBlogPosts'])
                ->orderBy('name')
                ->get()
            : collect();

        $driveRoot = (! $dossier->isLoopDossier() && $dossier->shared_with_loop_id)
            ? Dossier::where('loop_id', $dossier->shared_with_loop_id)->first()
            : null;

        return view('dossiers.show', [
            'dossier' => $dossier,
            'driveFolders' => $driveFolders,
            'driveRoot' => $driveRoot,
            'eligibleArticles' => $eligibleArticles,
            'series' => $series,
            'seriesList' => $seriesList,
            'seriesEligibleArticles' => $seriesEligibleArticles,
            'userRole' => $userRole,
            'canManageArticles' => $canManageArticles,
            // La policy refuse manageMembers sur un Dossier racine : les acces
            // s'administrent depuis la Boucle, et l'ecran n'offre donc aucune
            // gestion parallele. Pour un Dossier personnel : le proprietaire.
            'canManageMembers' => $user->can('manageMembers', $dossier),
            'canViewFiles' => $canViewFiles,
            'canManageFiles' => $canManageFiles,
            'canDeleteFiles' => $canDeleteFiles,
            'canUseSemanticArticleSearch' => $semanticSearchGate->isEnabledFor($organization->id),
            'organizationRouteParam' => $request->route('organization'),
            'categories' => $categories,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $organization = $this->currentOrganizationOrFail();
        $this->authorize('create', Dossier::class);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'owner_id' => ['prohibited'],
            // TASK-1130 : le Drive d'une Boucle cree des dossiers partages avec
            // elle. La regle est **celle d'update()**, a l'identique — pas un
            // second chemin de partage.
            'visibility' => ['nullable', Rule::in([Dossier::VISIBILITY_PRIVATE, Dossier::VISIBILITY_LOOP])],
            'shared_with_loop_id' => ['nullable', 'string'],
        ]);

        $visibility = $data['visibility'] ?? Dossier::VISIBILITY_PRIVATE;
        $sharedLoopId = null;

        if ($visibility === Dossier::VISIBILITY_LOOP) {
            // Sharing with a Loop requires a Loop, and it must belong to the
            // same Organization — a Dossier never reaches across a tenant.
            // Same guard as update(), same error, same phrasing.
            $loop = Loop::where('id', $data['shared_with_loop_id'] ?? null)
                ->where('organization_id', $organization->id)
                ->first();

            if (! $loop) {
                return back()->withErrors(['shared_with_loop_id' => __('dossiers.visibility_loop_required')]);
            }

            $sharedLoopId = $loop->id;
        }

        $dossier = Dossier::create([
            'organization_id' => $organization->id,
            'owner_id' => $request->user()->id,
            'name' => $data['name'],
            'visibility' => $visibility,
            'shared_with_loop_id' => $sharedLoopId,
        ]);

        // Cree depuis le Drive d'une Boucle, on y retourne : c'est la que le
        // dossier vient d'apparaitre.
        if ($sharedLoopId !== null && $request->input('return_to_dossier')) {
            $retour = Dossier::where('id', $request->input('return_to_dossier'))
                ->where('organization_id', $organization->id)
                ->first();

            if ($retour) {
                return redirect()
                    ->route('organization.dossiers.show', ['organization' => $organization, 'dossier' => $retour->getKey()])
                    ->with('success', __('dossiers.created'));
            }
        }

        return redirect()
            ->route('organization.dossiers.index', ['organization' => $organization])
            ->with('success', __('dossiers.created'));
    }

    public function edit(Request $request): View
    {
        $dossier = $this->resolveDossier($request->route('dossier'));
        $this->ensureDossierBelongsToCurrentOrganization($dossier);
        $this->authorize('update', $dossier);

        return view('dossiers.edit', [
            'dossier' => $dossier,
            // Only Loops of this Organization, and only those the user can
            // actually enter — offering a Loop they cannot reach would share a
            // Dossier into a room they cannot see.
            'shareableLoops' => $dossier->isLoopDossier() ? collect() : Loop::query()
                ->where('organization_id', $dossier->organization_id)
                ->where('status', 'active')
                ->orderBy('name')
                ->get(['id', 'name', 'visibility'])
                ->filter(fn ($loop) => $request->user()->can('viewWorkspace', $loop))
                ->values(),
        ]);
    }

    public function update(Request $request): RedirectResponse|JsonResponse
    {
        $dossier = $this->resolveDossier($request->route('dossier'));
        $organization = $this->currentOrganizationOrFail();
        $this->ensureDossierBelongsToCurrentOrganization($dossier);
        $this->authorize('update', $dossier);

        // A root Dossier's audience belongs to its Loop. Refused on the server,
        // not merely hidden in the form.
        if ($dossier->isLoopDossier() && $request->filled('visibility')) {
            abort(403, __('dossiers.visibility_inherited_readonly'));
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'owner_id' => ['prohibited'],
            'visibility' => ['nullable', Rule::in(Dossier::VISIBILITIES)],
            'shared_with_loop_id' => ['nullable', 'string'],
        ]);

        $visibility = $data['visibility'] ?? $dossier->visibility;
        $sharedLoopId = null;

        if ($visibility === Dossier::VISIBILITY_LOOP) {
            // Sharing with a Loop requires a Loop, and it must belong to the
            // same Organization — a Dossier never reaches across a tenant.
            $loop = Loop::where('id', $data['shared_with_loop_id'] ?? null)
                ->where('organization_id', $dossier->organization_id)
                ->first();

            if (! $loop) {
                return back()->withErrors(['shared_with_loop_id' => __('dossiers.visibility_loop_required')]);
            }

            $sharedLoopId = $loop->id;
        }

        $dossier->update([
            'name' => $data['name'],
            'visibility' => $visibility,
            // Any modality other than `loop` clears the sharing outright, so a
            // stale reference can never grant access.
            'shared_with_loop_id' => $sharedLoopId,
        ]);

        if ($request->expectsJson()) {
            return response()->json(['message' => __('dossiers.updated'), 'dossier' => $dossier->only('id', 'name')]);
        }

        return redirect()
            ->route('organization.dossiers.index', ['organization' => $organization])
            ->with('success', __('dossiers.updated'));
    }

    public function destroy(Request $request, DossierArticleIndexingDispatcher $indexing): RedirectResponse|JsonResponse
    {
        $dossier = $this->resolveDossier($request->route('dossier'));
        $organization = $this->currentOrganizationOrFail();
        $this->ensureDossierBelongsToCurrentOrganization($dossier);
        $this->authorize('delete', $dossier);

        DB::transaction(function () use ($dossier, $indexing) {
            $indexEntries = $dossier->dossierBlogPosts()
                ->where('organization_id', $dossier->organization_id)
                ->get(['organization_id', 'dossier_id', 'blog_post_id']);

            $seriesIds = $dossier->articleSeries()->pluck('id');
            ArticleSeriesItem::whereIn('article_series_id', $seriesIds)->delete();
            ArticleSeries::whereIn('id', $seriesIds)->delete();
            $dossier->files()->update(['dossier_id' => null]);
            $dossier->dossierMembers()->delete();
            $dossier->dossierBlogPosts()
                ->where('organization_id', $dossier->organization_id)
                ->delete();
            $dossier->delete();

            $indexing->dispatchForEntries($indexEntries);
        });

        if ($request->expectsJson()) {
            return response()->json(['message' => __('dossiers.deleted')]);
        }

        return redirect()
            ->route('organization.dossiers.index', ['organization' => $organization])
            ->with('success', __('dossiers.deleted'));
    }

    private function currentOrganizationOrFail()
    {
        $organization = currentOrganization();

        if (! $organization) {
            abort(404);
        }

        return $organization;
    }

    private function resolveDossier(mixed $dossier): Dossier
    {
        if ($dossier instanceof Dossier) {
            return $dossier;
        }

        return Dossier::query()->whereKey($dossier)->firstOrFail();
    }

    private function ensureDossierBelongsToCurrentOrganization(Dossier $dossier): void
    {
        $organization = $this->currentOrganizationOrFail();

        if ($dossier->organization_id !== $organization->id) {
            abort(404);
        }
    }
}
