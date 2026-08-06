<?php

namespace App\Http\Controllers;

use App\Models\ArticleSeries;
use App\Models\ArticleSeriesItem;
use App\Models\BlogPost;
use App\Models\Dossier;
use App\Models\DossierBlogPost;
use App\Services\Dossiers\DossierArticleIndexingDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BlogDossierApiController extends Controller
{
    public function currentDossier(BlogPost $post): JsonResponse
    {
        $organization = currentOrganization();
        if (! $organization || $post->organization_id !== $organization->id) {
            abort(404);
        }

        $this->authorize('update', $post);

        $entry = $post->dossierEntry()->with('dossier')->first();

        if (! $entry) {
            return response()->json(['dossier' => null]);
        }

        return response()->json([
            'dossier' => $this->dossierPayload($entry, $organization),
        ]);
    }

    /**
     * Remove every trace of an article from a Dossier's Series.
     *
     * Promoting the first annexe rather than deleting the Series: a Series that
     * loses its root still holds the articles someone put in it, and dropping
     * them would destroy work. Only a Series left with nothing at all goes.
     */
    private function detachFromSeries(string $dossierId, string $blogPostId): void
    {
        $series = ArticleSeries::where('dossier_id', $dossierId)->first();

        if (! $series) {
            return;
        }

        DB::transaction(function () use ($series, $blogPostId) {
            ArticleSeriesItem::where('article_series_id', $series->id)
                ->where('blog_post_id', $blogPostId)
                ->delete();

            if ($series->root_blog_post_id !== $blogPostId) {
                return;
            }

            $next = ArticleSeriesItem::where('article_series_id', $series->id)
                ->orderBy('position')
                ->first();

            if (! $next) {
                $series->delete();

                return;
            }

            $series->update(['root_blog_post_id' => $next->blog_post_id]);
            $next->delete();
        });
    }

    /**
     * What the editor's Dossier card needs to be useful.
     *
     * Beyond the name, it now carries the URL of the Dossier itself — reaching
     * it meant going back to "Mes dossiers" and hunting — and the Series this
     * article belongs to, if any, since a Series is a feature of the Dossier
     * and the writer has no other way of knowing they are inside one.
     *
     * @return array<string, mixed>
     */
    /**
     * The Series as a reader meets it: root first, then annexes in order.
     *
     * `slug` can be missing on a draft that has never been saved; such an
     * article gets no link rather than a broken one.
     */
    private function seriesArticles(ArticleSeries $series, $annexes, $currentBlogPostId, $organization): array
    {
        $rows = collect();

        if ($series->rootBlogPost) {
            $rows->push([$series->rootBlogPost, true]);
        }

        foreach ($annexes as $annexe) {
            if ($annexe->blogPost) {
                $rows->push([$annexe->blogPost, false]);
            }
        }

        return $rows->map(fn (array $row) => [
            'id' => $row[0]->id,
            'title' => $row[0]->title,
            'is_root' => $row[1],
            'is_current' => $row[0]->id === $currentBlogPostId,
            'url' => $row[0]->slug ? route('organization.blog.edit', [
                'organization' => $organization->slug,
                'post' => $row[0]->slug,
            ]) : null,
        ])->values()->all();
    }

    private function dossierPayload(DossierBlogPost $entry, $organization): array
    {
        $series = ArticleSeries::where('dossier_id', $entry->dossier_id)
            ->with('rootBlogPost:id,title,slug')
            ->first();

        $annexes = $series === null ? collect() : ArticleSeriesItem::where('article_series_id', $series->id)
            ->with('blogPost:id,title,slug')
            ->orderBy('position')
            ->get();

        $inSeries = $series !== null && (
            $series->root_blog_post_id === $entry->blog_post_id
            || $annexes->contains('blog_post_id', $entry->blog_post_id)
        );

        return [
            'id' => $entry->dossier->id,
            'name' => $entry->dossier->name,
            'position' => $entry->position,
            'url' => route('organization.dossiers.show', [
                'organization' => $organization->slug,
                'dossier' => $entry->dossier->id,
            ]),
            'series' => $inSeries ? $this->seriesPayload(
                $series, $annexes, $entry->blog_post_id, $organization, $entry->dossier->id
            ) : null,
        ];
    }

    /**
     * Ce que l'editeur sait de la Serie a laquelle appartient l'Article.
     *
     * Le precedent et le suivant se **deduisent** de la liste ordonnee, ils ne
     * sont pas recalcules : l'ordre — racine d'abord, puis les annexes a leur
     * position persistee — n'existe qu'a un seul endroit, et deux calculs
     * auraient fini par diverger.
     */
    private function seriesPayload(
        ArticleSeries $series,
        $annexes,
        string $currentBlogPostId,
        $organization,
        string $dossierId,
    ): array {
        $articles = $this->seriesArticles($series, $annexes, $currentBlogPostId, $organization);

        $index = collect($articles)->search(fn (array $a) => $a['is_current'] === true);

        $neighbour = function ($offset) use ($articles, $index) {
            if ($index === false) {
                return null;
            }

            $row = $articles[$index + $offset] ?? null;

            return $row === null ? null : [
                'id' => $row['id'],
                'title' => $row['title'],
                'url' => $row['url'],
            ];
        };

        $isRoot = $series->root_blog_post_id === $currentBlogPostId;

        return [
            'root_title' => $series->rootBlogPost?->title,
            'is_root' => $isRoot,
            // Le rang d'une annexe, tel qu'on le lit : la racine est le
            // premier Article, la premiere annexe le deuxieme.
            'position' => $index === false ? null : $index + 1,
            'total' => count($articles),
            'previous' => $neighbour(-1),
            'next' => $neighbour(1),
            // Pas de route de Serie dediee dans ce produit : la Serie se lit
            // dans l'onglet « contenus » de son Dossier.
            'url' => route('organization.dossiers.show', [
                'organization' => $organization->slug,
                'dossier' => $dossierId,
            ]).'?tab=contenus',
            // La serie entiere, pour que l'auteur voie de quoi son texte fait
            // partie sans quitter l'editeur.
            'articles' => $articles,
        ];
    }

    public function listDossiers(Request $request): JsonResponse
    {
        $organization = currentOrganization();
        if (! $organization) {
            abort(404);
        }

        $dossiers = Dossier::where('organization_id', $organization->id)
            ->where('owner_id', $request->user()->id)
            ->orderBy('name')
            ->get(['id', 'name']);

        return response()->json(['dossiers' => $dossiers]);
    }

    public function attach(Request $request, BlogPost $post, DossierArticleIndexingDispatcher $indexing): JsonResponse
    {
        $organization = currentOrganization();
        if (! $organization || $post->organization_id !== $organization->id) {
            abort(404);
        }

        $this->authorize('update', $post);

        $validated = $request->validate([
            'dossier_id' => ['required', 'string', 'uuid'],
        ]);

        if ($post->user_id !== $request->user()->id) {
            return response()->json(['message' => __('dossiers.only_author_can_classify')], 403);
        }

        if ($post->dossierEntry()->exists()) {
            return response()->json(['message' => __('dossiers.article_already_attached')], 422);
        }

        $dossier = Dossier::where('id', $validated['dossier_id'])
            ->where('organization_id', $organization->id)
            ->where('owner_id', $request->user()->id)
            ->firstOrFail();

        $nextPosition = ((int) $dossier->dossierBlogPosts()->max('position')) + 1;

        DossierBlogPost::create([
            'organization_id' => $organization->id,
            'dossier_id' => $dossier->id,
            'blog_post_id' => $post->id,
            'added_by' => $request->user()->id,
            'position' => $nextPosition,
        ]);

        $indexing->dispatch($organization->id, $dossier->id, $post->id);

        return response()->json([
            'message' => __('dossiers.article_attached'),
            'dossier' => [
                'id' => $dossier->id,
                'name' => $dossier->name,
                'position' => $nextPosition,
            ],
        ]);
    }

    public function detach(Request $request, BlogPost $post, DossierArticleIndexingDispatcher $indexing): JsonResponse
    {
        $organization = currentOrganization();
        if (! $organization || $post->organization_id !== $organization->id) {
            abort(404);
        }

        $this->authorize('update', $post);

        if ($post->user_id !== $request->user()->id) {
            return response()->json(['message' => __('dossiers.only_author_can_classify')], 403);
        }

        $entry = DossierBlogPost::query()
            ->where('organization_id', $organization->id)
            ->where('blog_post_id', $post->id)
            ->first(['organization_id', 'dossier_id', 'blog_post_id']);

        $deleted = DossierBlogPost::query()
            ->where('organization_id', $organization->id)
            ->where('blog_post_id', $post->id)
            ->delete();

        if (! $deleted) {
            return response()->json(['message' => __('dossiers.article_not_attached')], 422);
        }

        // Leaving a Dossier also leaves its Series.
        //
        // Without this, an article moved to another Dossier stayed the root or
        // an annexe of the Series it came from — a Series whose root was no
        // longer even in its own Dossier. The visible symptom was a refusal
        // that made no sense: "this article is already the root of a series"
        // on an article sitting alone in a brand-new Dossier.
        if ($entry) {
            $this->detachFromSeries($entry->dossier_id, $post->id);
        }

        if ($entry) {
            $indexing->dispatch($entry->organization_id, $entry->dossier_id, $entry->blog_post_id);
        }

        return response()->json(['message' => __('dossiers.article_detached')]);
    }

    public function quickCreate(Request $request): JsonResponse
    {
        $organization = currentOrganization();
        if (! $organization) {
            abort(404);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $dossier = Dossier::create([
            'organization_id' => $organization->id,
            'owner_id' => $request->user()->id,
            'name' => $validated['name'],
            'visibility' => Dossier::VISIBILITY_PRIVATE,
        ]);

        return response()->json([
            'message' => __('dossiers.created'),
            'dossier' => [
                'id' => $dossier->id,
                'name' => $dossier->name,
            ],
        ], 201);
    }

    public function orgCurrentDossier(string $org, BlogPost $post): JsonResponse
    {
        return $this->currentDossier($post);
    }

    public function orgListDossiers(Request $request, string $org): JsonResponse
    {
        return $this->listDossiers($request);
    }

    public function orgAttach(Request $request, string $org, BlogPost $post, DossierArticleIndexingDispatcher $indexing): JsonResponse
    {
        return $this->attach($request, $post, $indexing);
    }

    public function orgDetach(Request $request, string $org, BlogPost $post, DossierArticleIndexingDispatcher $indexing): JsonResponse
    {
        return $this->detach($request, $post, $indexing);
    }

    public function orgQuickCreate(Request $request, string $org): JsonResponse
    {
        return $this->quickCreate($request);
    }
}
