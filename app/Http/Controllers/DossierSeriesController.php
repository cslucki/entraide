<?php

namespace App\Http\Controllers;

use App\Models\ArticleSeries;
use App\Models\ArticleSeriesItem;
use App\Models\BlogPost;
use App\Models\Dossier;
use App\Models\DossierFile;
use App\Models\User;
use App\Services\Dossiers\DossierSeriesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Les Series d'un Dossier, vues depuis HTTP.
 *
 * Ce controleur ne tient plus les regles : elles sont dans
 * `DossierSeriesService`, en un seul endroit. Il lui reste ce qui est
 * proprement HTTP — resoudre la requete, autoriser, valider la forme des
 * entrees, nommer les erreurs comme les champs du formulaire, repondre.
 *
 * Les controles de cloisonnement restent **ici aussi**, et ce n'est pas un
 * doublon inutile : ils repondent 404, la ou le service souleve une erreur de
 * validation. Un identifiant d'une autre Organization ne doit rien apprendre a
 * qui le forge, pas meme qu'il designe quelque chose.
 */
class DossierSeriesController extends Controller
{
    public function __construct(private readonly DossierSeriesService $series) {}

    public function show(Request $request): JsonResponse
    {
        $dossier = $this->resolveDossier($request->route('dossier'));
        $organization = $this->currentOrganizationOrFail();
        $this->ensureDossierBelongsToCurrentOrganization($dossier);
        $this->authorize('viewSeries', $dossier);

        $all = ArticleSeries::where('dossier_id', $dossier->id)
            ->where('organization_id', $organization->id)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        return response()->json([
            // `series` reste la Serie **la plus ancienne** du Dossier : le
            // pop-up actuel n'en connait qu'une, et lui envoyer soudain une
            // liste l'aurait casse. L'ordre est explicite, donc stable — pas
            // un `->first()` dont le resultat depend du plan d'execution.
            'series' => $all->isEmpty() ? null : $this->seriesPayload($all->first()),
            // Et la verite complete a cote, pour l'onglet Series de TASK-1096.
            'series_list' => $all->map(fn (ArticleSeries $s) => $this->seriesPayload($s))->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $dossier = $this->resolveDossier($request->route('dossier'));
        $this->currentOrganizationOrFail();
        $this->ensureDossierBelongsToCurrentOrganization($dossier);
        $this->authorize('manageSeries', $dossier);

        $data = $request->validate([
            // La racine devient facultative : une Serie de fichiers n'en a pas.
            'root_blog_post_id' => ['nullable', 'uuid'],
            'name' => ['nullable', 'string', 'max:255'],
            // Ouvrir le mode Serie sur un Dossier qui n'en a aucune ne doit
            // rien demander : la sequence porte le nom du Dossier et contient
            // ce qu'il contient. `remplir` dit exactement cela.
            'remplir' => ['sometimes', 'boolean'],
        ]);

        $post = null;

        if (! empty($data['root_blog_post_id'])) {
            $post = $this->resolveBlogPost($data['root_blog_post_id']);
            $this->ensureBlogPostBelongsToCurrentOrganization($post);
            $this->ensureBlogPostBelongsToDossier($post, $dossier);
        }

        try {
            $series = $this->series->create($dossier, $request->user(), $post, $data['name'] ?? null);
        } catch (ValidationException $e) {
            $this->rethrowUnder($e, ['blog_post_id' => 'root_blog_post_id']);
        }

        if ($request->boolean('remplir')) {
            $series = $this->remplirAvecLeContenuDuDossier($series, $dossier, $request->user());
        }

        return response()->json([
            'series' => $series->load(['rootBlogPost', 'items.blogPost', 'items.dossierFile']),
            'message' => __('dossiers.series_created'),
        ]);
    }

    /**
     * Verse dans la Serie tout ce que le Dossier contient deja.
     *
     * Ordre d'ecran : les Articles dans leur ordre d'attachement, puis les
     * fichiers du plus recent au plus ancien — la sequence part de ce que la
     * personne voit, elle ne reinvente pas un classement.
     *
     * Un contenu deja pris par une autre Serie est saute sans bruit : le
     * moteur interdit l'appartenance double, et ce n'est pas une erreur de
     * l'utilisateur — il n'a rien demande d'autre que « ouvre le mode Serie ».
     */
    private function remplirAvecLeContenuDuDossier(ArticleSeries $series, Dossier $dossier, User $acteur): ArticleSeries
    {
        $contenus = $dossier->dossierBlogPosts()
            ->with('blogPost')
            ->get()
            ->map(fn ($entree) => $entree->blogPost)
            ->filter()
            ->concat($dossier->files()->orderByDesc('created_at')->get());

        foreach ($contenus as $contenu) {
            try {
                $this->series->addItem($series, $contenu, $acteur);
            } catch (ValidationException) {
                continue;
            }
        }

        return $series->fresh();
    }

    public function update(Request $request): JsonResponse
    {
        $dossier = $this->resolveDossier($request->route('dossier'));
        $organization = $this->currentOrganizationOrFail();
        $this->ensureDossierBelongsToCurrentOrganization($dossier);
        $this->authorize('manageSeries', $dossier);

        $series = $this->resolveSeries($dossier, $organization, $this->requestedSeriesId($request));

        $data = $request->validate([
            'root_blog_post_id' => ['required', 'uuid'],
        ]);

        $post = $this->resolveBlogPost($data['root_blog_post_id']);
        $this->ensureBlogPostBelongsToCurrentOrganization($post);
        $this->ensureBlogPostBelongsToDossier($post, $dossier);

        try {
            $series = $this->series->setRoot($series, $post, $request->user());
        } catch (ValidationException $e) {
            $this->rethrowUnder($e, ['blog_post_id' => 'root_blog_post_id']);
        }

        return response()->json([
            // La charge complete, pas seulement la racine : c'est ce qui permet
            // au client d'appliquer l'etat au lieu de recharger la page. Le
            // serveur vient de deplacer deux Articles et de renumeroter ; lui
            // faire dire l'etat resultant coute une requete deja faite, et
            // supprime toute reconstruction approximative cote navigateur.
            'series' => $this->seriesPayload($series),
            'message' => __('dossiers.series_root_updated'),
        ]);
    }

    public function addAnnex(Request $request): JsonResponse
    {
        $dossier = $this->resolveDossier($request->route('dossier'));
        $organization = $this->currentOrganizationOrFail();
        $this->ensureDossierBelongsToCurrentOrganization($dossier);
        $this->authorize('manageSeries', $dossier);

        $series = $this->resolveSeries($dossier, $organization, $this->requestedSeriesId($request));

        $data = $request->validate([
            // Un Article **ou** un fichier, jamais les deux, jamais aucun.
            // `required_without` de chaque cote pose l'obligation, `missing_with`
            // interdit le cumul : une requete qui porte les deux est refusee
            // plutot que silencieusement tranchee en faveur de l'un.
            'blog_post_id' => ['required_without:dossier_file_id', 'missing_with:dossier_file_id', 'uuid'],
            'dossier_file_id' => ['required_without:blog_post_id', 'uuid'],
        ]);

        if (! empty($data['blog_post_id'])) {
            $content = $this->resolveBlogPost($data['blog_post_id']);
            $this->ensureBlogPostBelongsToCurrentOrganization($content);
            $this->ensureBlogPostBelongsToDossier($content, $dossier);
        } else {
            $content = $this->resolveDossierFile($data['dossier_file_id']);
            $this->ensureDossierFileBelongsToDossier($content, $dossier);
        }

        $item = $this->series->addItem($series, $content, $request->user());

        return response()->json([
            'item' => $item->load(['blogPost', 'dossierFile']),
            'message' => __('dossiers.annex_added'),
        ]);
    }

    public function removeAnnex(Request $request): JsonResponse
    {
        $dossier = $this->resolveDossier($request->route('dossier'));
        $organization = $this->currentOrganizationOrFail();
        $this->ensureDossierBelongsToCurrentOrganization($dossier);
        $this->authorize('manageSeries', $dossier);

        $series = $this->resolveSeries($dossier, $organization, $this->requestedSeriesId($request));

        $this->series->removeItem($series, $this->resolveItem($series, $request->route('item')));

        return response()->json([
            'message' => __('dossiers.annex_removed'),
        ]);
    }

    public function reorderAnnexes(Request $request): JsonResponse
    {
        $dossier = $this->resolveDossier($request->route('dossier'));
        $organization = $this->currentOrganizationOrFail();
        $this->ensureDossierBelongsToCurrentOrganization($dossier);
        $this->authorize('manageSeries', $dossier);

        $series = $this->resolveSeries($dossier, $organization, $this->requestedSeriesId($request));

        $data = $request->validate([
            'items' => ['required', 'array'],
            'items.*' => ['required', 'uuid'],
        ]);

        // Traduction indulgente, a dessein : un identifiant qui ne designe rien
        // est laisse tel quel plutot que de repondre 404. Un classement perime
        // n'est pas une URL introuvable — c'est une liste devenue fausse, et
        // c'est le service qui la refuse **en entier**, en 422. Repondre 404
        // pour un element d'un lot dirait que la route n'existe pas.
        $this->series->reorder($series, $this->translateItemIds($series, array_values($data['items'])));

        return response()->json([
            'message' => __('dossiers.annexes_reordered'),
        ]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $dossier = $this->resolveDossier($request->route('dossier'));
        $organization = $this->currentOrganizationOrFail();
        $this->ensureDossierBelongsToCurrentOrganization($dossier);
        $this->authorize('manageSeries', $dossier);

        $this->series->delete(
            $this->resolveSeries($dossier, $organization, $this->requestedSeriesId($request))
        );

        return response()->json([
            'message' => __('dossiers.series_deleted'),
        ]);
    }

    // --- Org-scoped delegates ---

    public function orgShow(Request $request): JsonResponse
    {
        return $this->show($request);
    }

    public function orgStore(Request $request): JsonResponse
    {
        return $this->store($request);
    }

    public function orgUpdate(Request $request): JsonResponse
    {
        return $this->update($request);
    }

    public function orgAddAnnex(Request $request): JsonResponse
    {
        return $this->addAnnex($request);
    }

    public function orgRemoveAnnex(Request $request): JsonResponse
    {
        return $this->removeAnnex($request);
    }

    public function orgReorderAnnexes(Request $request): JsonResponse
    {
        return $this->reorderAnnexes($request);
    }

    public function orgDestroy(Request $request): JsonResponse
    {
        return $this->destroy($request);
    }

    // --- Private helpers ---

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

    /**
     * L'etat canonique d'une Serie : sa racine et ses contenus, dans l'ordre.
     *
     * Une seule construction, partagee par `show()` et par `update()`. Deux
     * charges differentes pour le meme objet auraient fini par diverger, et le
     * client aurait alors applique un etat incomplet en croyant l'avoir recu
     * entier.
     */
    private function seriesPayload(ArticleSeries $series): ArticleSeries
    {
        return $series->load([
            'rootBlogPost:id,organization_id,user_id,title,slug,status,updated_at',
            'items.blogPost:id,organization_id,user_id,title,slug,status,updated_at',
            'items.dossierFile:id,organization_id,dossier_id,original_name,mime_type,size_bytes,updated_at',
        ]);
    }

    /**
     * L'identifiant de Serie que porte la requete, s'il y en a un.
     *
     * Un Dossier peut desormais porter plusieurs Series (TASK-1095). Les routes
     * historiques n'en designent aucune ; celles qui le font passent `series_id`.
     * Le champ reste facultatif : un Dossier a une seule Serie n'a rien a dire
     * de plus, et les appels existants continuent de fonctionner tels quels.
     */
    private function requestedSeriesId(Request $request): ?string
    {
        $id = $request->route('series') ?? $request->input('series_id');

        if (! is_string($id) || $id === '') {
            return null;
        }

        // La forme est verifiee **ici**, avant que la valeur n'atteigne
        // `whereKey()`. En PostgreSQL la colonne est un `uuid` natif : un
        // `series_id=abc` y provoque un `SQLSTATE 22P02` et donc un **500**, la
        // ou SQLite se contente de ne rien trouver. La suite de tests tourne en
        // SQLite : elle ne pouvait pas voir ce defaut.
        if (! Str::isUuid($id)) {
            throw ValidationException::withMessages([
                'series_id' => __('validation.uuid', ['attribute' => 'series_id']),
            ]);
        }

        return $id;
    }

    /**
     * La Serie visee par la requete.
     *
     * Sans identifiant explicite, cette methode resout la Serie **unique** du
     * Dossier, et **refuse** quand il y en a plusieurs plutot que d'en choisir
     * une au hasard. Un `->first()` silencieux aurait agi sur une Serie
     * arbitraire, et le defaut n'aurait ete visible qu'apres coup, sur les
     * donnees de quelqu'un.
     */
    private function resolveSeries(Dossier $dossier, $organization, mixed $seriesId = null): ArticleSeries
    {
        $query = ArticleSeries::where('dossier_id', $dossier->id)
            ->where('organization_id', $organization->id);

        if ($seriesId !== null) {
            // Le controle de tenant reste porte par la requete elle-meme : un
            // identifiant forge ne traverse ni le Dossier ni l'Organization.
            return $query->whereKey($seriesId)->firstOrFail();
        }

        $series = $query->get();

        if ($series->isEmpty()) {
            abort(404);
        }

        if ($series->count() > 1) {
            throw ValidationException::withMessages([
                'series_id' => __('dossiers.series_ambiguous'),
            ]);
        }

        return $series->first();
    }

    /**
     * L'item designe par un identifiant, dans cette Serie.
     *
     * Les appels historiques designent une annexe par l'identifiant de son
     * **Article** : c'etait sans ambiguite tant qu'une Serie ne contenait que
     * des Articles. Depuis qu'elle peut contenir des fichiers, seul
     * l'identifiant de l'item lui-meme est universel — un item de fichier n'a
     * pas de `blog_post_id`.
     *
     * Les deux sont donc acceptes, l'item d'abord. Ce sont des UUID tires de
     * deux tables differentes : la collision n'est pas un cas a traiter.
     */
    private function resolveItem(ArticleSeries $series, mixed $id): ArticleSeriesItem
    {
        $item = ArticleSeriesItem::where('article_series_id', $series->id)
            ->where(fn ($q) => $q->whereKey($id)->orWhere('blog_post_id', $id))
            ->first();

        if (! $item) {
            abort(404);
        }

        return $item;
    }

    /**
     * Traduire une liste d'identifiants en identifiants d'items.
     *
     * Meme double lecture que `resolveItem()` — identifiant d'item ou d'Article
     * — mais sans refus unitaire : ce qui ne se traduit pas passe tel quel, et
     * le service juge la liste dans son ensemble.
     *
     * @param  array<int, string>  $ids
     * @return array<int, string>
     */
    private function translateItemIds(ArticleSeries $series, array $ids): array
    {
        $items = ArticleSeriesItem::where('article_series_id', $series->id)->get();

        $parArticle = $items->whereNotNull('blog_post_id')->keyBy('blog_post_id');
        $connus = $items->pluck('id')->flip();

        return array_map(
            fn (string $id) => $connus->has($id) ? $id : ($parArticle[$id]->id ?? $id),
            $ids
        );
    }

    private function resolveBlogPost(mixed $post): BlogPost
    {
        if ($post instanceof BlogPost) {
            return $post;
        }

        return BlogPost::query()->whereKey($post)->firstOrFail();
    }

    private function resolveDossierFile(mixed $file): DossierFile
    {
        if ($file instanceof DossierFile) {
            return $file;
        }

        return DossierFile::query()->whereKey($file)->firstOrFail();
    }

    private function ensureDossierBelongsToCurrentOrganization(Dossier $dossier): void
    {
        $organization = $this->currentOrganizationOrFail();

        if ($dossier->organization_id !== $organization->id) {
            abort(404);
        }
    }

    private function ensureBlogPostBelongsToCurrentOrganization(BlogPost $post): void
    {
        $organization = $this->currentOrganizationOrFail();

        if ($post->organization_id !== $organization->id) {
            abort(404);
        }
    }

    private function ensureBlogPostBelongsToDossier(BlogPost $post, Dossier $dossier): void
    {
        if (! $dossier->articles()->where('blog_post_id', $post->id)->exists()) {
            abort(404, 'Blog post is not attached to this dossier.');
        }
    }

    private function ensureDossierFileBelongsToDossier(DossierFile $file, Dossier $dossier): void
    {
        $organization = $this->currentOrganizationOrFail();

        if ($file->organization_id !== $organization->id || $file->dossier_id !== $dossier->id) {
            abort(404);
        }
    }

    /**
     * Relancer une erreur du service sous le nom du champ HTTP.
     *
     * Le service parle contenus : `blog_post_id`, `dossier_file_id`. Le
     * formulaire, lui, parle de ce qu'il affiche : `root_blog_post_id` quand il
     * s'agit d'une racine. Le message reste celui du service — seule la cle
     * change, pour que l'erreur se pose sous le bon champ.
     *
     * @param  array<string, string>  $map
     */
    private function rethrowUnder(ValidationException $e, array $map): never
    {
        $errors = [];

        foreach ($e->errors() as $key => $messages) {
            $errors[$map[$key] ?? $key] = $messages;
        }

        throw ValidationException::withMessages($errors);
    }
}
