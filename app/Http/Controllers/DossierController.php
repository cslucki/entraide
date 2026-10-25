<?php

namespace App\Http\Controllers;

use App\Models\ArticleSeries;
use App\Models\ArticleSeriesItem;
use App\Models\BlogPost;
use App\Models\Category;
use App\Models\Dossier;
use App\Models\DossierFile;
use App\Models\DossierMember;
use App\Models\Loop;
use App\Services\Dossiers\DossierInsightsService;
use App\Services\Dossiers\DossierSemanticSearchGate;
use App\Services\Dossiers\DossierTreePurger;
use App\Services\Dossiers\PersonalDocumentsRoot;
use App\Support\Loops\LoopRoleRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
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
    public function index(Request $request, DossierSemanticSearchGate $semanticSearchGate, PersonalDocumentsRoot $personalRoot, DossierInsightsService $insights): View
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
                // `owner` : la colonne Proprietaire montre un visage, et une
                // racine porte le sien — sans cet eager-load, la ligne restait
                // sans avatar (et declenchait une requete par ligne).
                ->with(['sharedWithLoop:id,name,organization_id', 'owner:id,first_name,avatar,name,email,banned_at,organization_id'])
                ->withCount(['dossierMembers', 'files', 'dossierBlogPosts', 'children'])
                ->orderBy('name')
                ->get();

            $surface = $this->driveSurface($request, $racine, $semanticSearchGate, $insights);

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
            // `owner_id != $userId` excluait les sous-dossiers : un enfant n'a
            // pas d'`owner_id`, et `NULL != x` vaut NULL en SQL, donc faux. Le
            // dossier qu'on venait de partager n'apparaissait jamais ici
            // (TASK-1136). On ecarte ce qu'on possede par une comparaison qui
            // accepte le NULL, et le tri se fait ensuite sur la gouvernance.
            $avecMoi = Dossier::query()
                ->where('organization_id', $organization->id)
                ->where(fn ($q) => $q->whereNull('owner_id')->orWhere('owner_id', '!=', $userId))
                ->whereHas('dossierMembers', fn ($q) => $q->where('user_id', $userId))
                ->with(['owner:id,first_name,avatar,name,email,banned_at,organization_id', 'dossierMembers' => fn ($q) => $q->where('user_id', $userId)])
                ->withCount(['files', 'dossierBlogPosts', 'children'])
                ->latest('updated_at')
                ->get();

            // Par moi : des membres nominatifs, ou le partage avec une Boucle
            // (CAS B). Cette requete ne concerne QUE les Dossiers — les
            // fichiers partages a l'unite sont construits plus bas, dans leurs
            // propres collections (TASK-1695 geste E). Le commentaire qui
            // tenait ici depuis TASK-1136 (« il n'existe aucun partage de
            // fichier isole ») etait vrai jusqu'a cette TASK ; il ne l'est
            // plus, et c'est tout ce qui change pour les Dossiers.
            //
            // `owner_id = $userId AND parent_id IS NULL` ne decrivait pas la
            // gouvernance mais « la racine que je possede ». Un Dossier range
            // sous « Mes documents » n'a ni l'un ni l'autre — seule la racine
            // porte `owner_id` — donc les deux filtres l'excluaient chacun
            // separement : le dossier qu'on venait de partager n'apparaissait
            // jamais ici (TASK-1142). La possession se lit sur la gouvernance,
            // comme la policy et la vue le font deja.
            //
            // L'ancre, elle, ne bouge pas : un partage vit sur le Dossier
            // explicitement partage. Un descendant qui ne fait qu'heriter n'a
            // aucune ligne `dossier_members` et n'entre donc pas dans la liste.
            $parMoi = Dossier::query()
                ->where('organization_id', $organization->id)
                ->where(fn ($q) => $q
                    ->whereHas('dossierMembers')
                    ->orWhereNotNull('shared_with_loop_id'))
                // `personal_documents` n'est jamais une ancre de partage
                // (TASK-1136) : « Mes documents » ne devient pas une ligne
                // partagee, meme si une adhesion y atterrissait un jour.
                ->where(fn ($q) => $q
                    ->whereNull('system_role')
                    ->orWhere('system_role', '!=', Dossier::SYSTEM_ROLE_PERSONAL_DOCUMENTS))
                // Les personnes, pas seulement leur nombre : « Par moi » doit
                // montrer AVEC QUI on partage, sinon la vue n'apprend rien que
                // le proprietaire ne sache deja.
                //
                // `parent` et son `owner` : la gouvernance d'un enfant se lit
                // un cran au-dessus, et la vue y relit le proprietaire. Les
                // precharger rend le cas courant — un Dossier pose sous « Mes
                // documents » — gratuit, ici comme au rendu.
                ->with([
                    'sharedWithLoop:id,name,organization_id',
                    'dossierMembers.user:id,first_name,avatar,name,email,banned_at,organization_id',
                    'owner:id,first_name,avatar,name,email,banned_at,organization_id',
                    'parent.owner:id,first_name,avatar,name,email,banned_at,organization_id',
                ])
                ->withCount(['dossierMembers', 'files', 'dossierBlogPosts', 'children'])
                ->latest('updated_at')
                ->get()
                // La gouvernance n'est pas exprimable en SQL sans recursion :
                // on la demande a la primitive canonique, sur le seul ensemble
                // des ancres de partage de l'Organization.
                ->filter(fn (Dossier $dossier) => $dossier->governingDossier()->owner_id === $userId)
                ->values();

            // TASK-1695 geste E — les fichiers partages A L'UNITE.
            //
            // Deliberement une collection A PART, et non fusionnee dans
            // `$avecMoi` / `$parMoi` : ces deux-la portent des Dossiers, et la
            // vue leur demande `governingDossier()`, `dossierMembers`,
            // `children_count`. Y glisser un `DossierFile` casserait le rendu a
            // la premiere ligne. Deux listes distinctes, c'est aussi ce que
            // l'ecran doit montrer : un fichier partage seul n'est pas un
            // Dossier, et le lire comme tel induirait en erreur sur ce a quoi
            // on a acces.
            //
            // `withoutTrashed()` est EXPLICITE, pas necessaire : `DossierFile`
            // porte `SoftDeletes`, donc la corbeille est deja hors requete.
            // Mesure du 24/10 : retirer l'appel ne fait rougir aucun test —
            // verifie, pas suppose. Il reste parce qu'il dit l'intention a qui
            // lira, et qu'un `withTrashed()` ajoute plus tard entrerait alors
            // en contradiction visible avec lui.
            //
            // Ce qui compte vraiment est ailleurs : une ligne de partage SURVIT
            // au `SoftDeletes` du fichier (la cascade SQL n'agit qu'a la
            // suppression DURE). La policy le sait — `sharedWith()` refuse un
            // fichier `trashed()`. L'ecran et la policy disent donc la meme
            // chose, et le test ci-dessous epingle ce resultat quelle que soit
            // la mecanique qui l'obtient.
            // TASK-1695 (5a) — « Avec moi » recoit AUSSI les fichiers partages
            // vers une de mes Boucles : sans cet ecran, un membre n'aurait
            // aucun chemin vers le fichier — la capacite serait absente (meme
            // lecon qu'au geste E). Le `orWhereHas` reste borne au tenant par
            // le `where` qui le precede.
            $mesBouclesActives = fn ($q) => $q
                ->where('organization_id', $organization->id)
                ->whereHas('activeMembers', fn ($m) => $m->where('user_id', $userId));

            // « Recu » exclut ce que J'AI partage : etant membre de la Boucle
            // cible, le partageur est destinataire de son propre geste — vu au
            // navigateur le 24/10, user2 « recevait » son propre LevantinDoc.
            // `shared_by` NULL (auteur disparu) reste recu : le partage, lui,
            // est toujours un partage de quelqu'un d'autre.
            $recuDUnAutre = fn ($q) => $q
                ->where(fn ($w) => $w->whereNull('shared_by')->orWhere('shared_by', '!=', $userId));

            $fichiersAvecMoi = DossierFile::query()
                ->withoutTrashed()
                ->where('organization_id', $organization->id)
                ->where(fn ($q) => $q
                    ->whereHas('shares', fn ($s) => $s->where('user_id', $userId))
                    ->orWhereHas('loopShares', fn ($s) => $recuDUnAutre($s)
                        ->whereHas('loop', $mesBouclesActives)))
                ->with([
                    'shares' => fn ($q) => $q->where('user_id', $userId),
                    'shares.sharedBy:id,first_name,avatar,name,email,banned_at,organization_id',
                    'loopShares' => fn ($q) => $recuDUnAutre($q)->whereHas('loop', $mesBouclesActives),
                    'loopShares.loop:id,name,organization_id',
                    'loopShares.sharedBy:id,first_name,avatar,name,email,banned_at,organization_id',
                ])
                ->latest('updated_at')
                ->get();

            // Par moi : ce que J'AI partage. L'auteur du geste est
            // `shared_by` — pas le televerseur du fichier. Prendre
            // `uploaded_by` ferait apparaitre, sous « Par moi », des partages
            // poses par quelqu'un d'autre sur un fichier que j'ai televerse.
            $fichiersParMoi = DossierFile::query()
                ->withoutTrashed()
                ->where('organization_id', $organization->id)
                ->where(fn ($q) => $q
                    ->whereHas('shares', fn ($s) => $s->where('shared_by', $userId))
                    ->orWhereHas('loopShares', fn ($s) => $s->where('shared_by', $userId)))
                ->with([
                    'shares' => fn ($q) => $q->where('shared_by', $userId),
                    'shares.user:id,first_name,avatar,name,email,banned_at,organization_id',
                    'loopShares' => fn ($q) => $q->where('shared_by', $userId),
                    'loopShares.loop:id,name,organization_id',
                ])
                ->withCount(['shares' => fn ($q) => $q->where('shared_by', $userId)])
                ->latest('updated_at')
                ->get();

            // TASK-1695 (points 2 et 3 de Cyril) — un vrai TABLEAU, donc des
            // en-tetes TRIABLES.
            //
            // Le tri est fait au SERVEUR, pas en Alpine : les lignes sont
            // rendues par `@foreach`, et trier en JS un balisage deja pose
            // aurait demande de le re-rendre en `x-for` depuis du JSON. Un tri
            // par parametre d'URL se teste, se partage par lien, et survit au
            // rechargement.
            //
            // La cle est VALIDEE contre une liste blanche : `$request` nomme
            // une colonne, il ne la choisit pas. Sans cela, `?ftri=` deviendrait
            // une injection dans un `sortBy()`.
            $triage = function ($fichiers) use ($request, $userId) {
                $cles = [
                    'nom' => fn (DossierFile $f) => mb_strtolower($f->nomLisible()),
                    'type' => fn (DossierFile $f) => $f->typeLisible(),
                    'taille' => fn (DossierFile $f) => (int) $f->size_bytes,
                    // « Qui » designe l'autre partie : le destinataire sous
                    // « Par moi », l'auteur du partage sous « Avec moi ».
                    'qui' => fn (DossierFile $f) => mb_strtolower(
                        (string) ($f->shares->first()?->user?->publicDisplayName()
                            ?: $f->shares->first()?->sharedBy?->publicDisplayName()
                            ?: $f->loopShares->first()?->loop?->name),
                    ),
                    'date' => fn (DossierFile $f) => $f->shares->first()?->created_at?->timestamp
                        ?? $f->loopShares->first()?->created_at?->timestamp
                        ?? 0,
                ];

                $cle = $request->query('ftri');
                $cle = is_string($cle) && isset($cles[$cle]) ? $cle : 'nom';
                $descendant = $request->query('fsens') === 'desc';

                return $fichiers->sortBy($cles[$cle], SORT_REGULAR, $descendant)->values();
            };

            return view('dossiers.espaces', [
                'espace' => 'partages',
                'vue' => $vue,
                'fichiersTri' => $request->query('ftri') ?: 'nom',
                'fichiersSens' => $request->query('fsens') === 'desc' ? 'desc' : 'asc',
                'avecMoi' => $avecMoi,
                'parMoi' => $parMoi,
                'fichiersAvecMoi' => $triage($fichiersAvecMoi),
                'fichiersParMoi' => $triage($fichiersParMoi),
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

    public function show(Request $request, DossierSemanticSearchGate $semanticSearchGate, DossierInsightsService $insights): View|Response
    {
        $dossier = $this->resolveDossier($request->route('dossier'));
        $this->ensureDossierBelongsToCurrentOrganization($dossier);

        // Le refus devient une REPONSE, pas une exception generique. Un lecteur
        // dont le partage vient d'etre retire rechargeait son ancienne URL et
        // tombait sur la page « Forbidden » nue du framework : pas de marque,
        // pas un mot de francais, aucune sortie (TASK-1145).
        //
        // Le statut ne bouge pas — c'est bien 403, et il le reste. Seul le
        // rendu change. La vue ne recoit PAS `$dossier` : elle ne peut donc
        // rien divulguer de ce qui a ete demande, et `driveSurface()` n'est
        // jamais atteint, donc rien du Dossier n'est meme charge pour l'ecran.
        if ($request->user()->cannot('view', $dossier)) {
            return response()->view('dossiers.acces-refuse', [
                'organizationRouteParam' => $request->route('organization'),
            ], 403);
        }

        return view('dossiers.show', $this->driveSurface($request, $dossier, $semanticSearchGate, $insights) + [
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
    private function driveSurface(Request $request, Dossier $dossier, DossierSemanticSearchGate $semanticSearchGate, DossierInsightsService $insights): array
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
            $loopRole = app(LoopRoleRegistry::class)->canonical(
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
            'owner:id,first_name,avatar,name,banned_at,organization_id',
            'dossierBlogPosts.blogPost.user:id,first_name,avatar,name,email,organization_id,banned_at',
            'dossierBlogPosts.blogPost.coAuthors:id,first_name,avatar,name,email,organization_id,banned_at',
            // Qui a range CET Article dans CE Dossier. `blogPost.user` dit
            // l'auteur du contenu, ce qui n'est pas la meme question des lors
            // que plusieurs personnes deposent dans un Dossier partage.
            'dossierBlogPosts.addedBy:id,first_name,avatar,name,email,organization_id,banned_at',
            'dossierMembers.user:id,first_name,avatar,name,email,organization_id,banned_at',
            // TASK-1697 (1) — `slug` est CHARGE : le lien « Voir la Boucle »
            // le prefere a l'uuid, et sans la colonne il retombait en silence
            // sur `getKey()`. Une URL en uuid fonctionne, mais ce n'est pas
            // celle que le reste du produit publie — `?espace=boucles` et le
            // fil d'Ariane visent le slug.
            'loop:id,name,organization_id,status,slug',
            'loop.activeMembers.user:id,first_name,avatar,name,email,organization_id,banned_at',
        ]);

        // Le panneau « Partager » lit toujours la racine gouvernante (owner,
        // dossier_members, loop) — jamais $dossier directement, qui n'a ni
        // l'un ni l'autre des lors qu'il s'agit d'un enfant. Sans ce second
        // eager-load, un sous-dossier declenchait une requete N+1 a chaque
        // affichage du panneau.
        if ($governingDossier->isNot($dossier)) {
            $governingDossier->load([
                'owner:id,first_name,avatar,name,banned_at,organization_id',
                'dossierMembers.user:id,first_name,avatar,name,email,organization_id,banned_at',
                // TASK-1697 (1) — `slug` est CHARGE : le lien « Voir la Boucle »
            // le prefere a l'uuid, et sans la colonne il retombait en silence
            // sur `getKey()`. Une URL en uuid fonctionne, mais ce n'est pas
            // celle que le reste du produit publie — `?espace=boucles` et le
            // fil d'Ariane visent le slug.
            'loop:id,name,organization_id,status,slug',
                'loop.activeMembers.user:id,first_name,avatar,name,email,organization_id,banned_at',
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
                'rootBlogPost.user:id,first_name,avatar,name,email,organization_id,banned_at',
                'rootBlogPost.coAuthors:id,first_name,avatar,name,email,organization_id,banned_at',
                'items.blogPost:id,organization_id,user_id,title,slug,status,updated_at,published_at',
                'items.blogPost.user:id,first_name,avatar,name,email,organization_id,banned_at',
                'items.blogPost.coAuthors:id,first_name,avatar,name,email,organization_id,banned_at',
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
                ->with('user:id,first_name,avatar,name,email,organization_id')
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
        // TASK-1695 (point 5b de Cyril) — DEPOSER n'est pas administrer.
        //
        // `uploadFiles` (T1684) ouvre le depot au membre ACTIF d'une Boucle
        // vivante, selon la permission `dossiers.upload_file` de la matrice —
        // et `StoreDossierFileRequest` l'autorise deja : le serveur acceptait
        // le depot d'un membre, mais cette page gardait son FAB « Nouveau »
        // derriere `manageFiles`. La porte etait ouverte, sans poignee —
        // l'exact inverse du cas T1656.
        $canUploadFiles = $user->can('uploadFiles', $dossier);

        // TASK-1695 (5a, recette de Cyril) — les fichiers PARTAGES AVEC CETTE
        // BOUCLE.
        //
        // Jusqu'ici, partager un fichier « dans une Boucle » ouvrait la lecture
        // a ses membres sans que la Boucle elle-meme en montre rien : pour qui
        // ouvrait cet ecran, le partage n'existait pas. Cyril l'a dit en une
        // phrase — « il n'est pas partage dans la boucle ».
        //
        // Lecture SEULE, et une section a part : ces fichiers ne sont PAS dans
        // le Dossier (ils vivent chez leur proprietaire, qui peut les retirer
        // a tout moment). Les melanger aux fichiers deposes laisserait croire
        // qu'on peut les renommer, les deplacer ou les supprimer ici.
        $fichiersPartagesAvecLaBoucle = collect();

        if ($dossier->isLoopDossier() && $dossier->loop !== null) {
            $fichiersPartagesAvecLaBoucle = DossierFile::query()
                ->withoutTrashed()
                ->where('organization_id', $dossier->organization_id)
                ->whereHas('loopShares', fn ($q) => $q
                    ->where('loop_id', $dossier->loop_id)
                    ->where('organization_id', $dossier->organization_id))
                ->with([
                    'loopShares' => fn ($q) => $q->where('loop_id', $dossier->loop_id),
                    'loopShares.sharedBy:id,first_name,avatar,name,email,banned_at,organization_id',
                ])
                ->latest('updated_at')
                ->get();
        }
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
                    ->with('owner:id,first_name,avatar,name,email,banned_at,organization_id')
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
                // `dossierMembers` : sans ce compte, une ligne enfant ne pouvait
                // pas dire SON partage et retombait sur l'etat de la racine
                // gouvernante — un Dossier explicitement partage s'affichait
                // « Prive » dans « Mes documents » (TASK-1143).
                ->withCount(['files', 'dossierBlogPosts', 'children', 'dossierMembers'])
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

        // Cette projection ne donne aucun droit : la policy `view()` a deja
        // autorise la requete. Elle choisit seulement le premier maillon que le
        // lecteur connait par un partage nominatif. Root-first garantit que si
        // A et B sont tous deux partages, le chemin reste A > B > ... ; si seul
        // B l'est, aucun parent prive n'est rendu.
        $sharedAnchorIds = DossierMember::query()
            ->where('organization_id', $organization->id)
            ->where('user_id', $userId)
            ->whereIn('dossier_id', $chain->pluck('id'))
            ->pluck('dossier_id')
            ->all();
        $firstSharedIndex = $chain->search(fn (Dossier $ancestor) => in_array($ancestor->getKey(), $sharedAnchorIds, true));
        $isSharedSurface = ! $governingDossier->isLoopDossier()
            && ! $isOwner
            && $firstSharedIndex !== false;

        // Le Dossier ouvert est-il couvert par une ancre de partage explicite —
        // lui-meme, ou l'un de ses ancetres ?
        //
        // Sans cette lecture, la colonne « Partage » repetait l'etat de la
        // racine gouvernante : « Mes documents » n'ayant jamais de membre, tout
        // ce qui vivait sous un Dossier partage s'affichait « Prive » alors que
        // l'acces existait bel et bien. `sharingAnchorIds()` donne la portee et
        // n'est ici que LUE — aucune ligne `dossier_members` n'est ecrite.
        $ancres = $dossier->sharingAnchorIds();
        $couvertParUnPartage = $ancres !== [] && (
            DossierMember::query()
                ->where('organization_id', $organization->id)
                ->whereIn('dossier_id', $ancres)
                ->exists()
            || Dossier::query()
                ->whereIn('id', $ancres)
                ->whereNotNull('shared_with_loop_id')
                ->exists()
        );

        if ($isSharedSurface) {
            $chain = $chain->slice($firstSharedIndex)->values();
        }

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
            'espace' => $governingDossier->isLoopDossier()
                ? 'boucles'
                : ($isSharedSurface ? 'partages' : 'documents'),
            'governingDossier' => $governingDossier,
            // Le lecteur est-il arrive ici PAR un partage — et ce Dossier
            // porte-t-il un acces explicite quelque part sur sa chaine ? Les
            // deux nourrissent l'affichage du partage, jamais un droit.
            'isSharedSurface' => $isSharedSurface,
            'couvertParUnPartage' => $couvertParUnPartage,
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
            'canUploadFiles' => $canUploadFiles,
            'fichiersPartagesAvecLaBoucle' => $fichiersPartagesAvecLaBoucle,
            'canDeleteFiles' => $canDeleteFiles,
            'canUseSemanticArticleSearch' => $semanticSearchGate->isEnabledFor($organization->id),
            // TASK-1341 — Smart Dossier V1 : bouton desactive + raison
            // honnete sans document indexe, jamais d'appel provider tente.
            'dossierHasIndexedContent' => $insights->hasIndexedContent($organization, $dossier),
            'organizationRouteParam' => $request->route('organization'),
            'categories' => $categories,
        ];
    }

    // TASK-1629 — `create()` et `store()` supprimees.
    //
    // Elles portaient les DEUX seuls chemins de creation manuelle : une racine
    // (privee ou partagee avec une Boucle) et, via `parent_id`, un vrai
    // sous-dossier. BouclePro n'est pas un Drive : l'utilisateur ne batit plus
    // d'arborescence, il depose des fichiers et des Articles dans les racines
    // que le produit provisionne lui-meme.
    //
    // Rien d'interne ne les appelait — aucun service, aucune commande, aucun
    // job — donc les routes partent avec, et un POST direct rend 404. Les
    // racines continuent de naitre par `LoopRootDocumentService` et
    // `PersonalDocumentsRoot`, qui ecrivent le modele sans passer ici.
    //
    // `update()`, `unshare()` et `destroy()` restent : renommer, cesser de
    // partager et supprimer portent sur un Dossier qui existe deja, y compris
    // les arborescences legacy que TASK-1630 traitera.

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
                // `organization_id` est indispensable : `viewWorkspace` compare
                // le tenant de la Boucle a celui de l'utilisateur. Absent du
                // SELECT, il valait NULL sur chaque modele hydrate — la
                // comparaison echouait donc toujours et le filtre ci-dessous
                // vidait la liste entiere. C'est ce qui rendait « Boucle de
                // destination » vide pour tout le monde.
                ->get(['id', 'organization_id', 'name', 'visibility'])
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
     * Supprimer un Dossier emporte ce qu'il contient — comme n'importe quel
     * Drive (decision Cyril du 13/08, qui remplace la regle « vide seulement »
     * de l'etape A).
     *
     * Ce qui part : le Dossier, ses sous-dossiers a toute profondeur, leurs
     * fichiers, leurs Series. Tout en suppression douce — `deleted_at` — donc
     * rien n'est efface du disque et une restauration reste possible en base.
     *
     * Ce qui ne part PAS : les **Articles**. Un Article est un objet du blog,
     * avec sa page et son adresse publique ; le ranger dans un dossier ne le
     * rend pas proprietaire de son existence. Le lien est retire, l'Article
     * reste. C'est la seule difference avec un Drive de fichiers, et elle est
     * dite a l'utilisateur dans la confirmation.
     *
     * La racine d'une Boucle et « Mes documents » restent hors de portee :
     * la policy `delete` les refuse avant meme d'arriver ici.
     */
    public function destroy(Request $request, DossierTreePurger $purger): RedirectResponse|JsonResponse
    {
        $dossier = $this->resolveDossier($request->route('dossier'));
        $organization = $this->currentOrganizationOrFail();
        $this->ensureDossierBelongsToCurrentOrganization($dossier);
        $this->authorize('delete', $dossier);

        DB::transaction(function () use ($dossier, $purger) {
            // Verrou sur la ligne visee : `dossier_files.dossier_id`,
            // `dossier_blog_posts.dossier_id` et `dossiers.parent_id` la
            // referencent tous par FK, donc PostgreSQL prend deja un verrou
            // de partage a chaque ecriture qui l'accroche — celui-ci ferme la
            // fenetre entre le recensement et la suppression.
            $verrouille = Dossier::whereKey($dossier->getKey())->lockForUpdate()->firstOrFail();

            // TASK-1630 : le parcours de branche vit desormais dans
            // `DossierTreePurger` — le meme que l'outil SuperAdmin et que la
            // suppression d'une Boucle. Un algorithme recopie a trois
            // endroits aurait diverge au premier correctif. Ici la
            // suppression reste DOUCE : c'est l'ordre du parcours qui est
            // partage, pas la destruction physique.
            foreach ($purger->branch($verrouille) as $noeud) {
                $seriesIds = $noeud->articleSeries()->pluck('id');
                ArticleSeriesItem::whereIn('article_series_id', $seriesIds)->delete();
                ArticleSeries::whereIn('id', $seriesIds)->delete();

                // Les Articles sont detaches, jamais detruits.
                $noeud->dossierBlogPosts()->delete();
                $noeud->files()->delete();
                $noeud->dossierMembers()->delete();
                $noeud->delete();
            }
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

        // Un segment qui n'est pas un UUID n'est pas un Dossier : 404, tout
        // de suite (TASK-1629).
        //
        // `/dossiers/create` a servi de formulaire de creation jusqu'a cette
        // TASK. La route supprimee, l'URL retombe sur `/dossiers/{dossier}` —
        // et `whereKey('create')` comparait un mot a une colonne `uuid`.
        // SQLite l'accepte et ne rend rien (404 fortuit) ; PostgreSQL REFUSE
        // la comparaison et leve `22P02`, donc 500. Un ancien marque-page
        // rendait une erreur serveur en production, jamais en local.
        //
        // La forme est verifiee avant la requete : c'est la seule maniere de
        // rendre le meme 404 sur les deux moteurs.
        if (! is_string($dossier) || ! Str::isUuid($dossier)) {
            abort(404);
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
