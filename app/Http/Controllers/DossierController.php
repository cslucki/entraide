<?php

namespace App\Http\Controllers;

use App\Models\ArticleSeries;
use App\Models\ArticleSeriesItem;
use App\Models\BlogPost;
use App\Models\Category;
use App\Models\Dossier;
use App\Models\Loop;
use App\Services\Dossiers\DossierSemanticSearchGate;
use App\Services\Dossiers\PersonalDocumentsRoot;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class DossierController extends Controller
{
    /**
     * L'entree du module Dossiers — trois espaces, une seule URL.
     *
     * `/dossiers` ouvre **directement « Mes documents »** : la vraie racine
     * personnelle, avec son contenu, jamais un catalogue de racines a traverser
     * avant d'arriver quelque part (TASK-1130, decision finale). Les deux
     * autres espaces sont des **vues** — `?espace=partages`, `?espace=boucles` —
     * qui n'ont aucune ligne `dossiers` derriere elles.
     *
     * L'espace est dans l'URL, pas dans un etat client : un partage de lien, un
     * rechargement et le bouton Retour rendent tous la meme page.
     */
    public function index(Request $request, DossierSemanticSearchGate $semanticSearchGate, PersonalDocumentsRoot $personalRoot): View
    {
        $organization = $this->currentOrganizationOrFail();
        $this->authorize('viewAny', Dossier::class);

        $user = $request->user();
        $userId = $user->id;
        $espace = in_array($request->query('espace'), ['partages', 'boucles'], true)
            ? $request->query('espace')
            : 'documents';

        if ($espace === 'documents') {
            // La racine nait ici, a la premiere visite — pas de backfill, pas
            // de ligne creee pour un compte qui n'ouvre jamais le module.
            $racine = $personalRoot->resolve($organization->id, $userId);

            // Les anciennes racines personnelles ne sont JAMAIS deplacees sous
            // la nouvelle : elles restent des racines a part entiere (leurs
            // partages CAS B en dependent). « Mes documents » les compose avec
            // son propre contenu, le temps qu'un rangement manuel les absorbe.
            $anciennesRacines = Dossier::query()
                ->where('organization_id', $organization->id)
                ->where('owner_id', $userId)
                ->whereNull('parent_id')
                ->whereKeyNot($racine->getKey())
                ->with(['sharedWithLoop:id,name,organization_id'])
                ->withCount(['dossierMembers', 'files', 'dossierBlogPosts', 'children'])
                ->orderBy('name')
                ->get();

            $surface = $this->driveSurface($request, $racine, $semanticSearchGate);

            // Elles se rangent parmi les dossiers de la surface, a leur place
            // alphabetique : pour l'utilisateur ce sont des dossiers, et le
            // fait qu'elles soient techniquement des racines n'a pas a devenir
            // une section a part avec ses propres regles d'affichage.
            $surface['driveFolders'] = $surface['driveFolders']
                ->merge($anciennesRacines)
                ->sortBy('name')
                ->values();

            return view('dossiers.show', $surface + [
                'espace' => 'documents',
                'legacyRoots' => $anciennesRacines,
            ]);
        }

        if ($espace === 'partages') {
            $vue = $request->query('vue') === 'par-moi' ? 'par-moi' : 'avec-moi';

            // Avec moi : ce que d'autres m'ont explicitement confie. La lecture
            // reste celle de la policy view() — une invitation nominative.
            $avecMoi = Dossier::query()
                ->where('organization_id', $organization->id)
                ->where('owner_id', '!=', $userId)
                ->whereHas('dossierMembers', fn ($q) => $q->where('user_id', $userId))
                ->with(['owner:id,first_name,name,email,banned_at,organization_id', 'dossierMembers' => fn ($q) => $q->where('user_id', $userId)])
                ->withCount(['files', 'dossierBlogPosts', 'children'])
                ->latest('updated_at')
                ->get();

            // Par moi : les deux seuls partages que le backend connaisse
            // reellement — des membres nominatifs, ou le partage avec une
            // Boucle (CAS B). Rien n'est invente ici : il n'existe aucun
            // partage de fichier ou d'Article isole.
            $parMoi = Dossier::query()
                ->where('organization_id', $organization->id)
                ->where('owner_id', $userId)
                ->whereNull('parent_id')
                ->where(fn ($q) => $q
                    ->whereHas('dossierMembers')
                    ->orWhereNotNull('shared_with_loop_id'))
                ->with(['sharedWithLoop:id,name,organization_id', 'dossierMembers'])
                ->withCount(['dossierMembers', 'files', 'dossierBlogPosts', 'children'])
                ->latest('updated_at')
                ->get();

            return view('dossiers.espaces', [
                'espace' => 'partages',
                'vue' => $vue,
                'avecMoi' => $avecMoi,
                'parMoi' => $parMoi,
                'organizationRouteParam' => $request->route('organization'),
            ]);
        }

        // Les Boucles dont je peux ouvrir le Drive : des Boucles, pas des
        // lignes « Type = Dossier ». Le role affiche vient de l'appartenance
        // reelle, la meme que la policy view() consulte.
        $dossiersDeBoucle = Dossier::query()
            ->where('organization_id', $organization->id)
            ->whereNotNull('loop_id')
            ->whereHas('loop.activeMembers', fn ($q) => $q->where('user_id', $userId))
            ->with(['loop:id,name,organization_id,status,slug', 'loop.activeMembers' => fn ($q) => $q->where('user_id', $userId)])
            ->withCount(['files', 'dossierBlogPosts', 'children'])
            ->latest('updated_at')
            ->get();

        return view('dossiers.espaces', [
            'espace' => 'boucles',
            'vue' => null,
            'loopDossiers' => $dossiersDeBoucle,
            'organizationRouteParam' => $request->route('organization'),
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
        $this->ensureDossierBelongsToCurrentOrganization($dossier);
        $this->authorize('view', $dossier);

        return view('dossiers.show', $this->driveSurface($request, $dossier, $semanticSearchGate) + [
            // L'espace qui reste allume dans la sidebar pendant qu'on navigue
            // en profondeur : un Dossier de Boucle appartient a « Boucles »,
            // tout le reste a « Mes documents ».
            'espace' => $dossier->governingDossier()->isLoopDossier() ? 'boucles' : 'documents',
            'legacyRoots' => collect(),
        ]);
    }

    /**
     * La surface documentaire d'un Dossier — le meme payload pour « Mes
     * documents » (entree du module) et pour n'importe quel Dossier ouvert.
     *
     * Une seule construction, donc une seule verite : le Drive de la racine
     * personnelle n'est pas une page a part qui reimplemente les fichiers, les
     * Articles, les Series et les droits — c'est le meme Drive, sur une autre
     * ligne.
     *
     * @return array<string, mixed>
     */
    private function driveSurface(Request $request, Dossier $dossier, DossierSemanticSearchGate $semanticSearchGate): array
    {
        $organization = $this->currentOrganizationOrFail();

        $user = $request->user();
        $userId = $user->id;

        // TASK-1130 passe 4 : un enfant n'a ni owner_id ni dossier_members a
        // lui — le role **affiche** se lit sur la racine qui le gouverne,
        // exactement comme les policies plus bas. Sans cette remontee, un
        // sous-dossier de Boucle affichait « Partage / Lecture seule » a son
        // propre proprietaire.
        $governingDossier = $dossier->governingDossier();
        $isOwner = $governingDossier->owner_id === $userId;

        // Le role **affiche**. Pour un Dossier racine, il derive de la Boucle :
        // owner_id est null par doctrine et dossier_members est vide par
        // construction — les lire rendait `role_none` au proprietaire de la
        // Boucle. La gouvernance, elle, ne se lit pas ici : elle se demande aux
        // policies, plus bas.
        if ($governingDossier->isLoopDossier()) {
            $loopRole = app(\App\Support\Loops\LoopRoleRegistry::class)->canonical(
                $governingDossier->loop?->activeMembers()->where('user_id', $userId)->value('role'),
            );

            $userRole = $governingDossier->loop?->activeMembers()->where('user_id', $userId)->exists()
                ? 'loop_'.$loopRole
                : 'none';
        } else {
            $userRole = $isOwner ? 'owner' : ($governingDossier->memberRoleFor($userId) ?? 'none');
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

        // Le panneau « Partager » lit toujours la racine gouvernante (owner,
        // dossier_members, loop) — jamais $dossier directement, qui n'a ni
        // l'un ni l'autre des lors qu'il s'agit d'un enfant. Sans ce second
        // eager-load, un sous-dossier declenchait une requete N+1 a chaque
        // affichage du panneau.
        if ($governingDossier->isNot($dossier)) {
            $governingDossier->load([
                'owner:id,first_name,name,banned_at,organization_id',
                'dossierMembers.user:id,first_name,name,email,organization_id,banned_at',
                'loop:id,name,organization_id,status',
                'loop.activeMembers.user:id,first_name,name,email,organization_id,banned_at',
            ]);
        }

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
        // Mode Serie (TASK-1130 addendum) : la meme porte que le moteur —
        // manageSeries — decide poignees, ajout, creation et suppression.
        $canManageSeries = $user->can('manageSeries', $dossier);
        $canManageFiles = $user->can('manageFiles', $dossier);
        $canDeleteFiles = $user->can('deleteFile', $dossier);

        $categories = Category::where('organization_id', $organization->id)
            ->orderBy('name_b2c')
            ->get(['id', 'name_b2c', 'name_b2b']);

        // ── Le Drive (TASK-1130) ─────────────────────────────────────────
        // Deux sources de dossiers-enfants, combinees :
        //
        //   1. legacy — des Dossiers PERSONNELS partages avec CETTE Boucle
        //      (`shared_with_loop_id`), uniquement visibles depuis le root de
        //      la Boucle elle-meme. Fonctionnement passe 1, jamais retire.
        //   2. reel — de vrais enfants (`parent_id`) de CE Dossier precis,
        //      Boucle ou prive, root ou deja enfant (passe 4).
        $driveFolders = collect();

        if ($dossier->isLoopDossier()) {
            $driveFolders = $driveFolders->merge(
                Dossier::where('organization_id', $organization->id)
                    ->where('shared_with_loop_id', $dossier->loop_id)
                    ->where('visibility', Dossier::VISIBILITY_LOOP)
                    // Le proprietaire reel, pour le marqueur « Partage par X »
                    // (TASK-1130 UX finale) : sans lui, un CAS B est
                    // indiscernable d'un vrai sous-dossier a l'ecran alors que
                    // ses menus different.
                    ->with('owner:id,first_name,name,email,banned_at,organization_id')
                    // `dossierMembers` : l'etat de partage affiche sur une
                    // ligne qui porte le sien (une racine) se lit sur elle,
                    // pas sur la gouvernance heritee du Dossier ouvert.
                    ->withCount(['files', 'dossierBlogPosts', 'children', 'dossierMembers'])
                    ->get()
            );
        }

        $driveFolders = $driveFolders->merge(
            Dossier::where('organization_id', $organization->id)
                ->where('parent_id', $dossier->id)
                ->withCount(['files', 'dossierBlogPosts', 'children'])
                ->get()
        )->sortBy('name')->values();

        // Cibles de deplacement d'un fichier (TASK-1130 passe 4) : les
        // sous-dossiers visibles ici, plus le parent reel s'il existe — pas le
        // Dossier partage historiquement via `shared_with_loop_id`, qui n'a
        // pas de parent_id et n'est donc pas une cible de "remonter". Filtrees
        // au droit reel (manageFiles), pas seulement a la visibilite : une
        // cible offerte sans pouvoir y ecrire serait un bouton qui ment.
        $moveTargets = $driveFolders
            ->filter(fn (Dossier $folder) => $user->can('manageFiles', $folder))
            ->map(fn (Dossier $folder) => ['id' => $folder->getKey(), 'name' => $folder->name, 'isParent' => false])
            ->values();

        if ($dossier->parent_id !== null && $user->can('manageFiles', $dossier->parent)) {
            $moveTargets->prepend(['id' => $dossier->parent->getKey(), 'name' => $dossier->parent->name, 'isParent' => true]);
        }

        // Le breadcrumb : la chaine reelle de `parent_id` (root-first, sans
        // $dossier lui-meme), precedee du root de la Boucle quand le sommet
        // de cette chaine est un Dossier personnel partage avec elle — les
        // deux mecanismes composent, ils ne se remplacent pas.
        $chain = $dossier->ancestryChain();
        $topOfRealChain = $chain->first();

        $driveRoot = null;
        if (! $topOfRealChain->isLoopDossier() && $topOfRealChain->shared_with_loop_id) {
            $driveRoot = Dossier::where('loop_id', $topOfRealChain->shared_with_loop_id)->first();
        }

        $breadcrumbAncestors = $chain->slice(0, -1)->values();
        if ($driveRoot) {
            $breadcrumbAncestors = collect([$driveRoot])->merge($breadcrumbAncestors);
        }

        return [
            'dossier' => $dossier,
            'governingDossier' => $governingDossier,
            'driveFolders' => $driveFolders,
            'moveTargets' => $moveTargets,
            'driveRoot' => $driveRoot,
            'breadcrumbAncestors' => $breadcrumbAncestors,
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
            'canManageSeries' => $canManageSeries,
            'canManageFiles' => $canManageFiles,
            'canDeleteFiles' => $canDeleteFiles,
            'canUseSemanticArticleSearch' => $semanticSearchGate->isEnabledFor($organization->id),
            'organizationRouteParam' => $request->route('organization'),
            'categories' => $categories,
        ];
    }

    public function store(Request $request): RedirectResponse
    {
        $organization = $this->currentOrganizationOrFail();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'owner_id' => ['prohibited'],
            // TASK-1130 : le Drive d'une Boucle cree des dossiers partages avec
            // elle. La regle est **celle d'update()**, a l'identique — pas un
            // second chemin de partage.
            'visibility' => ['nullable', Rule::in([Dossier::VISIBILITY_PRIVATE, Dossier::VISIBILITY_LOOP])],
            'shared_with_loop_id' => ['nullable', 'string'],
            // TASK-1130 passe 4 : un vrai sous-dossier. Present -> le nouveau
            // Dossier devient un enfant reel (parent_id), pas une racine.
            'parent_id' => ['nullable', 'string'],
        ]);

        // ── Un vrai sous-dossier, dans n'importe quel Dossier (Boucle ou
        //    prive) ─────────────────────────────────────────────────────────
        if (filled($data['parent_id'] ?? null)) {
            $parent = Dossier::where('id', $data['parent_id'])
                ->where('organization_id', $organization->id)
                ->first();

            if (! $parent) {
                abort(404);
            }

            // Creer un enfant est un geste d'ecriture sur le parent : memes
            // droits que d'y attacher un fichier ou un article.
            $this->authorize('update', $parent);

            $enfant = new Dossier([
                'organization_id' => $organization->id,
                'owner_id' => null,
                'loop_id' => null,
                'parent_id' => $parent->id,
                'name' => $data['name'],
                // Un enfant n'a pas d'audience propre (governingDossier() la
                // tranche) ; la colonne reste NOT NULL, elle recopie celle du
                // parent sans lui donner de sens metier ici.
                'visibility' => $parent->visibility,
            ]);
            $enfant->assertValidParent($parent);
            $enfant->save();

            return redirect()
                ->route('organization.dossiers.show', ['organization' => $organization, 'dossier' => $parent->getKey()])
                ->with('success', __('dossiers.created'));
        }

        // ── Chemin historique : une racine, privee ou partagee avec une
        //    Boucle (shared_with_loop_id, conserve tel quel) ────────────────
        $this->authorize('create', Dossier::class);

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
        // `rename` et non `update` : ecrire DANS un Dossier et changer son
        // identite sont deux droits distincts depuis TASK-1130. « Mes
        // documents » accepte tout le premier et refuse tout le second.
        $this->authorize('rename', $dossier);

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

    /**
     * Retirer un Dossier personnel de la Boucle avec qui il est partage
     * (TASK-1130 passe 4, CAS B) — sans jamais le supprimer.
     *
     * Meme effet que passer `visibility` a `private` depuis le formulaire
     * d'edition (`update()` efface deja `shared_with_loop_id` des que la
     * modalite n'est plus `loop`), mais en un seul geste depuis le Drive de
     * la Boucle, et sans quitter la page : le proprietaire reste sur son
     * Dossier, simplement retire de cette Boucle.
     */
    public function unshare(Request $request): RedirectResponse
    {
        $dossier = $this->resolveDossier($request->route('dossier'));
        $this->ensureDossierBelongsToCurrentOrganization($dossier);
        // Retirer un partage est un changement d'audience, pas une simple
        // edition de contenu : meme porte que choisir la visibilite
        // (proprietaire reel uniquement, jamais un editeur).
        $this->authorize('updateVisibility', $dossier);

        $dossier->update([
            'visibility' => Dossier::VISIBILITY_PRIVATE,
            'shared_with_loop_id' => null,
        ]);

        return redirect()->back()->with('success', __('dossiers.unshared'));
    }

    /**
     * TASK-1130 (etape A) : un Dossier ne se supprime que vide.
     *
     * Avant cette etape, un Dossier non vide etait « nettoye » a la volee —
     * ses enfants promus racines, ses fichiers detaches, ses Articles
     * retires, ses Series dissoutes — puis supprime. Sur un vrai
     * sous-dossier (`parent_id` non nul, `owner_id`/`loop_id` NULL par
     * contrat), la promotion d'un petit-enfant ecrivait une ligne
     * `parent_id = NULL, owner_id = NULL, loop_id = NULL` : ni un enfant, ni
     * une racine valide. Confirme empiriquement sur `bouclepro_test`
     * (PostgreSQL) : la contrainte `dossiers_holder_xor` refuse cette ligne
     * avec un 500 (`SQLSTATE[23514]`) des qu'un dossier a supprimer avait
     * lui-meme un enfant. Sur SQLite (pas de CHECK constraint), la meme
     * ecriture passait silencieusement et produisait un orphelin que plus
     * aucune policy ne reconnaissait — gouvernance perdue en pratique,
     * jamais promue.
     *
     * Plus aucun deplacement ni suppression implicite de contenu : un
     * Dossier non vide refuse la suppression avec un message actionnable.
     */
    public function destroy(Request $request): RedirectResponse|JsonResponse
    {
        $dossier = $this->resolveDossier($request->route('dossier'));
        $organization = $this->currentOrganizationOrFail();
        $this->ensureDossierBelongsToCurrentOrganization($dossier);
        $this->authorize('delete', $dossier);

        DB::transaction(function () use ($dossier) {
            // Verrou + re-verification dans la transaction : sans lui, un
            // upload ou une creation de sous-dossier simultanee entre le
            // controle et l'ecriture pourrait rendre du contenu inaccessible
            // sans jamais avoir ete compte. `dossier_files.dossier_id`,
            // `dossier_blog_posts.dossier_id` et `dossiers.parent_id`
            // pointent tous vers cette ligne par contrainte FK : PostgreSQL
            // prend deja un verrou de partage sur la ligne parente a chaque
            // ecriture qui la reference, donc ce seul verrou suffit a fermer
            // la fenetre — inutile de verrouiller chaque relation separement.
            $locked = Dossier::whereKey($dossier->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->hasContent()) {
                throw ValidationException::withMessages([
                    'dossier' => __('dossiers.delete_not_empty'),
                ]);
            }

            // Une Serie ne peut exister sur ce Dossier qu'a partir d'un
            // Article ou d'un fichier deja attache ici
            // (DossierSeriesService::assertBelongsToDossier) : hasContent()
            // etant faux, toute Serie presente est necessairement sans
            // racine ni item — la dissoudre ne perd aucun contenu, comme
            // DossierSeriesService::delete() le fait deja pour un geste
            // manuel.
            $seriesIds = $locked->articleSeries()->pluck('id');
            ArticleSeriesItem::whereIn('article_series_id', $seriesIds)->delete();
            ArticleSeries::whereIn('id', $seriesIds)->delete();

            $locked->dossierMembers()->delete();
            $locked->delete();
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
