<?php

namespace App\Http\Controllers;

use App\Models\ArticleSeries;
use App\Models\ArticleSeriesItem;
use App\Models\BlogPost;
use App\Models\Dossier;
use App\Models\DossierBlogPost;
use App\Services\Dossiers\DossierArticleIndexingDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DossierArticleController extends Controller
{
    public function store(Request $request, DossierArticleIndexingDispatcher $indexing): RedirectResponse|JsonResponse
    {
        $dossier = $this->resolveDossier($request->route('dossier'));
        $organization = $this->currentOrganizationOrFail();
        $this->ensureDossierBelongsToCurrentOrganization($dossier);
        $this->authorize('update', $dossier);

        $data = $request->validate([
            'blog_post_id' => ['required', 'uuid'],
        ]);

        $post = $this->resolveBlogPost($data['blog_post_id']);
        $this->ensureBlogPostBelongsToCurrentOrganization($post);
        $this->ensureUserOwnsBlogPost($request, $post);

        if ($post->dossierEntry()->exists()) {
            throw ValidationException::withMessages([
                'blog_post_id' => __('dossiers.article_already_attached'),
            ]);
        }

        $nextPosition = ((int) $dossier->dossierBlogPosts()->max('position')) + 1;

        $entry = DossierBlogPost::create([
            'organization_id' => $organization->id,
            'dossier_id' => $dossier->id,
            'blog_post_id' => $post->id,
            'added_by' => $request->user()->id,
            'position' => $nextPosition,
        ]);

        $indexing->dispatch($organization->id, $dossier->id, $post->id);

        if ($request->expectsJson()) {
            $entry->load('blogPost:id,organization_id,user_id,title,slug,status,updated_at');

            return response()->json([
                'message' => __('dossiers.article_attached'),
                'entry' => $entry,
            ], 201);
        }

        return redirect()
            ->route('organization.dossiers.show', ['organization' => $organization, 'dossier' => $dossier->getKey()])
            ->with('success', __('dossiers.article_attached'));
    }

    public function createAndAttach(Request $request, DossierArticleIndexingDispatcher $indexing): JsonResponse
    {
        $dossier = $this->resolveDossier($request->route('dossier'));
        $organization = $this->currentOrganizationOrFail();
        $this->ensureDossierBelongsToCurrentOrganization($dossier);
        $this->authorize('update', $dossier);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'category_id' => ['nullable', 'uuid', 'exists:categories,id'],
        ]);

        return DB::transaction(function () use ($request, $dossier, $organization, $data, $indexing) {
            if (filled($data['category_id'] ?? null)) {
                $categoryId = $data['category_id'];
                if (! \App\Models\Category::where('id', $categoryId)->where('organization_id', $organization->id)->exists()) {
                    throw ValidationException::withMessages([
                        'category_id' => __('dossiers.category_not_found'),
                    ]);
                }
            }

            $post = BlogPost::create([
                'user_id' => $request->user()->id,
                'organization_id' => $organization->id,
                'title' => $data['title'],
                // Le slug appartient au modele : lui seul sait le rendre
                // unique, et tous les chemins de creation passent par lui.
                'slug' => null,
                'content' => '<p></p>',
                'status' => 'draft',
                'category_id' => $data['category_id'] ?? null,
            ]);

            $nextPosition = ((int) $dossier->dossierBlogPosts()->max('position')) + 1;

            $entry = DossierBlogPost::create([
                'organization_id' => $organization->id,
                'dossier_id' => $dossier->id,
                'blog_post_id' => $post->id,
                'added_by' => $request->user()->id,
                'position' => $nextPosition,
            ]);

            $indexing->dispatch($organization->id, $dossier->id, $post->id);

            $entry->load('blogPost:id,organization_id,user_id,title,slug,status,updated_at');

            return response()->json([
                'message' => __('dossiers.article_created_attached'),
                'post' => [
                    'id' => $post->id,
                    'slug' => $post->slug,
                    'title' => $post->title,
                ],
                'entry' => $entry,
                // L'URL vient du routeur, jamais d'une chaine ecrite a la
                // main : la route d'edition est `/blog/rediger/{slug}/modifier`,
                // et l'URL fabriquee ici envoyait tout le monde sur un 404.
                'redirect_url' => route('organization.blog.edit', [
                    'organization' => $organization->slug,
                    'post' => $post->slug,
                ]),
            ], 201);
        });
    }

    /**
     * Deplacer un Article d'un Dossier vers un autre.
     *
     * Un fichier se glissait deja d'un dossier a l'autre ; un Article, non —
     * il fallait le detacher puis le rattacher a la main (TASK-1130, signale
     * a l'usage). Le miroir exact de `files/{file}/move`, aux memes refus.
     *
     * L'ecriture reste UN update sur la ligne pivot : `dossier_blog_posts`
     * porte un index unique sur `blog_post_id`, donc un detach + attach
     * aurait ouvert une fenetre ou l'Article n'appartient a rien — et perdu
     * `added_by` au passage.
     */
    public function move(Request $request, DossierArticleIndexingDispatcher $indexing): JsonResponse
    {
        $dossier = $this->resolveDossier($request->route('dossier'));
        $organization = $this->currentOrganizationOrFail();
        $this->ensureDossierBelongsToCurrentOrganization($dossier);
        // Un deplacement est un retrait PLUS un ajout : les deux droits sont
        // exiges, sur la source comme sur la cible.
        $this->authorize('detachArticle', $dossier);

        $post = $this->resolveBlogPost($request->route('post'));
        $this->ensureBlogPostBelongsToCurrentOrganization($post);
        $this->ensureUserOwnsBlogPost($request, $post);

        $data = $request->validate([
            'target_dossier_id' => ['required', 'string'],
        ]);

        $entry = DossierBlogPost::query()
            ->where('organization_id', $organization->id)
            ->where('dossier_id', $dossier->id)
            ->where('blog_post_id', $post->id)
            ->first();

        if (! $entry) {
            abort(404);
        }

        $target = Dossier::where('id', $data['target_dossier_id'])
            ->where('organization_id', $organization->id)
            ->first();

        if (! $target) {
            // Hors du tenant courant ou inexistant : meme reponse, aucune fuite
            // d'information sur ce qui existe ailleurs.
            return response()->json(['message' => __('dossiers.move_cross_organization_refused')], 404);
        }

        $this->authorize('attachArticle', $target);

        if ($target->id === $dossier->id) {
            return response()->json(['message' => __('dossiers.article_move_same_dossier')], 422);
        }

        // Une Serie impose que son contenu vive dans SON Dossier
        // (DossierSeriesService::assertBelongsToDossier). Deplacer l'Article
        // amputerait donc la sequence de quelqu'un — on refuse en le disant,
        // plutot que de dissoudre en silence.
        $seriesIds = ArticleSeries::where('dossier_id', $dossier->id)
            ->where('organization_id', $organization->id)
            ->pluck('id');

        if ($seriesIds->isNotEmpty()) {
            $lieAUneSerie = ArticleSeries::whereIn('id', $seriesIds)
                ->where('root_blog_post_id', $post->id)
                ->exists()
                || ArticleSeriesItem::whereIn('article_series_id', $seriesIds)
                    ->where('blog_post_id', $post->id)
                    ->exists();

            if ($lieAUneSerie) {
                return response()->json(['message' => __('dossiers.article_move_series_refused')], 422);
            }
        }

        DB::transaction(function () use ($entry, $target) {
            $entry->update([
                'dossier_id' => $target->id,
                'position' => ((int) $target->dossierBlogPosts()->max('position')) + 1,
            ]);
        });

        // Les deux Dossiers changent de contenu : leur index respectif doit
        // etre reconstruit, pas seulement celui de la destination.
        $indexing->dispatch($organization->id, $dossier->id, $post->id);
        $indexing->dispatch($organization->id, $target->id, $post->id);

        return response()->json([
            'message' => __('dossiers.article_moved'),
            'article' => ['id' => $post->id, 'dossier_id' => $target->id],
        ]);
    }

    public function destroy(Request $request, DossierArticleIndexingDispatcher $indexing): RedirectResponse|JsonResponse
    {
        $dossier = $this->resolveDossier($request->route('dossier'));
        $organization = $this->currentOrganizationOrFail();
        $this->ensureDossierBelongsToCurrentOrganization($dossier);
        $this->authorize('update', $dossier);

        $post = $this->resolveBlogPost($request->route('post'));
        $this->ensureBlogPostBelongsToCurrentOrganization($post);
        $this->ensureUserOwnsBlogPost($request, $post);

        // Toutes les Series du Dossier, pas la premiere venue : depuis
        // TASK-1095 un Dossier peut en porter plusieurs, et un `->first()`
        // laissait detacher un Article qui etait la racine d'une **autre**
        // Serie que celle retenue au hasard.
        $seriesIds = ArticleSeries::where('dossier_id', $dossier->id)
            ->where('organization_id', $organization->id)
            ->pluck('id');

        $estRacine = ArticleSeries::whereIn('id', $seriesIds)
            ->where('root_blog_post_id', $post->id)
            ->exists();

        if ($estRacine) {
            throw ValidationException::withMessages([
                'blog_post_id' => __('dossiers.cannot_detach_series_root'),
            ]);
        }

        if ($seriesIds->isNotEmpty()) {
            $seriesItem = ArticleSeriesItem::whereIn('article_series_id', $seriesIds)
                ->where('blog_post_id', $post->id)
                ->first();

            if ($seriesItem) {
                return DB::transaction(function () use ($dossier, $organization, $seriesItem, $post, $request, $indexing) {
                    $seriesItem->delete();

                    $entry = DossierBlogPost::query()
                        ->where('organization_id', $organization->id)
                        ->where('dossier_id', $dossier->id)
                        ->where('blog_post_id', $post->id)
                        ->first(['organization_id', 'dossier_id', 'blog_post_id']);

                    DossierBlogPost::query()
                        ->where('organization_id', $organization->id)
                        ->where('dossier_id', $dossier->id)
                        ->where('blog_post_id', $post->id)
                        ->delete();

                    if ($entry) {
                        $indexing->dispatch($entry->organization_id, $entry->dossier_id, $entry->blog_post_id);
                    }

                    if ($request->expectsJson()) {
                        return response()->json([
                            'message' => __('dossiers.article_detached'),
                        ]);
                    }

                    return redirect()
                        ->route('organization.dossiers.show', ['organization' => $organization, 'dossier' => $dossier->getKey()])
                        ->with('success', __('dossiers.article_detached'));
                });
            }
        }

        $entry = DossierBlogPost::query()
            ->where('organization_id', $organization->id)
            ->where('dossier_id', $dossier->id)
            ->where('blog_post_id', $post->id)
            ->first(['organization_id', 'dossier_id', 'blog_post_id']);

        $deleted = DossierBlogPost::query()
            ->where('organization_id', $organization->id)
            ->where('dossier_id', $dossier->id)
            ->where('blog_post_id', $post->id)
            ->delete();

        if ($entry && $deleted) {
            $indexing->dispatch($entry->organization_id, $entry->dossier_id, $entry->blog_post_id);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'message' => __('dossiers.article_detached'),
            ]);
        }

        return redirect()
            ->route('organization.dossiers.show', ['organization' => $organization, 'dossier' => $dossier->getKey()])
            ->with('success', __('dossiers.article_detached'));
    }

    public function reorder(Request $request): RedirectResponse|JsonResponse
    {
        $dossier = $this->resolveDossier($request->route('dossier'));
        $organization = $this->currentOrganizationOrFail();
        $this->ensureDossierBelongsToCurrentOrganization($dossier);
        $this->authorize('update', $dossier);

        $data = $request->validate([
            'articles' => ['required', 'array'],
            'articles.*' => ['required', 'uuid'],
        ]);

        $articleIds = array_values($data['articles']);

        $entries = DossierBlogPost::query()
            ->where('dossier_id', $dossier->id)
            ->whereIn('blog_post_id', $articleIds)
            ->get()
            ->keyBy('blog_post_id');

        if ($entries->count() !== count(array_unique($articleIds))) {
            throw ValidationException::withMessages([
                'articles' => __('dossiers.reorder_invalid'),
            ]);
        }

        DB::transaction(function () use ($articleIds, $entries) {
            foreach ($articleIds as $index => $articleId) {
                $entries[$articleId]->update(['position' => $index + 1]);
            }
        });

        if ($request->expectsJson()) {
            $entries->each(function ($entry) {
                $entry->load('blogPost:id,organization_id,user_id,title,slug,status,updated_at');
            });

            return response()->json([
                'message' => __('dossiers.articles_reordered'),
                'articles' => $entries->sortBy('position')->values(),
            ]);
        }

        return redirect()
            ->route('organization.dossiers.show', ['organization' => $organization, 'dossier' => $dossier->getKey()])
            ->with('success', __('dossiers.articles_reordered'));
    }

    public function search(Request $request): JsonResponse
    {
        $dossier = $this->resolveDossier($request->route('dossier'));
        $organization = $this->currentOrganizationOrFail();
        $this->ensureDossierBelongsToCurrentOrganization($dossier);
        $this->authorize('update', $dossier);

        $query = $request->string('q', '')->toString();

        $posts = BlogPost::query()
            ->where('organization_id', $organization->id)
            ->where('user_id', $request->user()->id)
            ->whereDoesntHave('dossierEntry')
            ->when($query, fn ($q) => $q->where('title', 'ilike', '%'.$query.'%'))
            ->latest('updated_at')
            ->limit(20)
            ->get(['id', 'organization_id', 'user_id', 'title', 'slug', 'status', 'updated_at']);

        return response()->json(['articles' => $posts]);
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

    private function resolveBlogPost(mixed $post): BlogPost
    {
        if ($post instanceof BlogPost) {
            return $post;
        }

        return BlogPost::query()->whereKey($post)->firstOrFail();
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

    private function ensureUserOwnsBlogPost(Request $request, BlogPost $post): void
    {
        if ($post->user_id !== $request->user()->id) {
            abort(403);
        }
    }
}
