<?php

namespace App\Http\Controllers;

use App\Models\ArticleSeries;
use App\Models\ArticleSeriesItem;
use App\Models\BlogPost;
use App\Models\Dossier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DossierSeriesController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $dossier = $this->resolveDossier($request->route('dossier'));
        $organization = $this->currentOrganizationOrFail();
        $this->ensureDossierBelongsToCurrentOrganization($dossier);
        $this->authorize('viewSeries', $dossier);

        $series = ArticleSeries::where('dossier_id', $dossier->id)
            ->where('organization_id', $organization->id)
            ->with(['rootBlogPost:id,organization_id,user_id,title,slug,status,updated_at', 'items.blogPost:id,organization_id,user_id,title,slug,status,updated_at'])
            ->first();

        if (! $series) {
            return response()->json(['series' => null]);
        }

        return response()->json(['series' => $series]);
    }

    public function store(Request $request): JsonResponse
    {
        $dossier = $this->resolveDossier($request->route('dossier'));
        $organization = $this->currentOrganizationOrFail();
        $this->ensureDossierBelongsToCurrentOrganization($dossier);
        $this->authorize('manageSeries', $dossier);

        $data = $request->validate([
            'root_blog_post_id' => ['required', 'uuid'],
        ]);

        $post = $this->resolveBlogPost($data['root_blog_post_id']);
        $this->ensureBlogPostBelongsToCurrentOrganization($post);
        $this->ensureBlogPostBelongsToDossier($post, $dossier);

        if (ArticleSeries::where('root_blog_post_id', $post->id)->exists()) {
            throw ValidationException::withMessages([
                'root_blog_post_id' => __('dossiers.article_is_series_root'),
            ]);
        }

        if (ArticleSeriesItem::where('blog_post_id', $post->id)->exists()) {
            throw ValidationException::withMessages([
                'root_blog_post_id' => __('dossiers.article_is_series_annex'),
            ]);
        }

        // Sous transaction et relu sous verrou : deux creations simultanees sur
        // la meme racine se seraient sinon toutes deux crues seules.
        $series = DB::transaction(function () use ($organization, $dossier, $post, $request) {
            if (ArticleSeries::where('root_blog_post_id', $post->id)->lockForUpdate()->exists()) {
                throw ValidationException::withMessages([
                    'root_blog_post_id' => __('dossiers.article_is_series_root'),
                ]);
            }

            return ArticleSeries::create([
                'organization_id' => $organization->id,
                'dossier_id' => $dossier->id,
                'root_blog_post_id' => $post->id,
                'created_by' => $request->user()->id,
            ]);
        });

        return response()->json([
            'series' => $series->load('rootBlogPost'),
            'message' => __('dossiers.series_created'),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $dossier = $this->resolveDossier($request->route('dossier'));
        $organization = $this->currentOrganizationOrFail();
        $this->ensureDossierBelongsToCurrentOrganization($dossier);
        $this->authorize('manageSeries', $dossier);

        $series = $this->resolveSeries($dossier, $organization);

        $data = $request->validate([
            'root_blog_post_id' => ['required', 'uuid'],
        ]);

        $post = $this->resolveBlogPost($data['root_blog_post_id']);
        $this->ensureBlogPostBelongsToCurrentOrganization($post);
        $this->ensureBlogPostBelongsToDossier($post, $dossier);

        if ($series->root_blog_post_id !== $post->id) {
            // Root of ANOTHER series: taking it would empty that one.
            if (ArticleSeries::where('root_blog_post_id', $post->id)->where('id', '!=', $series->id)->exists()) {
                throw ValidationException::withMessages([
                    'root_blog_post_id' => __('dossiers.article_is_series_root'),
                ]);
            }

            // An annexe of ANOTHER series is still refused — but an annexe of
            // *this* one is exactly what promoting a root means, and refusing
            // it was the reason "change root" could never do anything.
            $foreignAnnex = ArticleSeriesItem::where('blog_post_id', $post->id)
                ->where('article_series_id', '!=', $series->id)
                ->exists();

            if ($foreignAnnex) {
                throw ValidationException::withMessages([
                    'root_blog_post_id' => __('dossiers.article_is_series_annex'),
                ]);
            }
        }

        $formerRootId = $series->root_blog_post_id;

        DB::transaction(function () use ($series, $post, $formerRootId) {
            // The promoted article stops being an annexe of its own series.
            ArticleSeriesItem::where('article_series_id', $series->id)
                ->where('blog_post_id', $post->id)
                ->delete();

            $series->update(['root_blog_post_id' => $post->id]);

            // The former root becomes the first annexe rather than falling out
            // of the series: nothing a human put there is ever dropped.
            if ($formerRootId && $formerRootId !== $post->id) {
                ArticleSeriesItem::where('article_series_id', $series->id)->increment('position');

                ArticleSeriesItem::create([
                    'organization_id' => $series->organization_id,
                    'article_series_id' => $series->id,
                    'blog_post_id' => $formerRootId,
                    'position' => 0,
                    'added_by' => auth()->id(),
                ]);
            }
        });

        return response()->json([
            'series' => $series->fresh()->load('rootBlogPost'),
            'message' => __('dossiers.series_root_updated'),
        ]);
    }

    public function addAnnex(Request $request): JsonResponse
    {
        $dossier = $this->resolveDossier($request->route('dossier'));
        $organization = $this->currentOrganizationOrFail();
        $this->ensureDossierBelongsToCurrentOrganization($dossier);
        $this->authorize('manageSeries', $dossier);

        $series = $this->resolveSeries($dossier, $organization);

        $data = $request->validate([
            'blog_post_id' => ['required', 'uuid'],
        ]);

        $post = $this->resolveBlogPost($data['blog_post_id']);
        $this->ensureBlogPostBelongsToCurrentOrganization($post);
        $this->ensureBlogPostBelongsToDossier($post, $dossier);

        if ($post->id === $series->root_blog_post_id) {
            throw ValidationException::withMessages([
                'blog_post_id' => __('dossiers.article_is_series_root'),
            ]);
        }

        if (ArticleSeriesItem::where('blog_post_id', $post->id)->exists()) {
            throw ValidationException::withMessages([
                'blog_post_id' => __('dossiers.article_already_in_series'),
            ]);
        }

        // La position se lit et s'ecrit sous verrou, dans la meme transaction.
        //
        // Sans cela, `max('position') + 1` etait calcule avant l'insertion :
        // deux ajouts simultanes lisaient le meme maximum et posaient deux
        // annexes au meme rang. Le verrou sur la ligne de la Serie serialise
        // les ajouts a cette Serie, et a elle seule.
        $item = DB::transaction(function () use ($series, $organization, $post, $request) {
            $locked = ArticleSeries::whereKey($series->id)->lockForUpdate()->firstOrFail();

            // Relu sous verrou : entre la verification et ici, quelqu'un a pu
            // poser le meme article.
            if (ArticleSeriesItem::where('blog_post_id', $post->id)->exists()) {
                throw ValidationException::withMessages([
                    'blog_post_id' => __('dossiers.article_already_in_series'),
                ]);
            }

            return ArticleSeriesItem::create([
                'organization_id' => $organization->id,
                'article_series_id' => $locked->id,
                'blog_post_id' => $post->id,
                'position' => ((int) $locked->items()->max('position')) + 1,
                'added_by' => $request->user()->id,
            ]);
        });

        return response()->json([
            'item' => $item->load('blogPost'),
            'message' => __('dossiers.annex_added'),
        ]);
    }

    public function removeAnnex(Request $request): JsonResponse
    {
        $dossier = $this->resolveDossier($request->route('dossier'));
        $organization = $this->currentOrganizationOrFail();
        $this->ensureDossierBelongsToCurrentOrganization($dossier);
        $this->authorize('manageSeries', $dossier);

        $series = $this->resolveSeries($dossier, $organization);

        // Retrait et renumerotation d'un seul tenant : un retrait concurrent
        // laissait sinon des rangs troues, et deux retraits simultanes
        // pouvaient se croiser.
        DB::transaction(function () use ($series, $request) {
            $locked = ArticleSeries::whereKey($series->id)->lockForUpdate()->firstOrFail();

            $item = ArticleSeriesItem::where('article_series_id', $locked->id)
                ->where('blog_post_id', $request->route('item'))
                ->lockForUpdate()
                ->first();

            if (! $item) {
                abort(404);
            }

            // L'Article n'est jamais supprime, ni detache du Dossier : seule
            // son appartenance a la Serie disparait.
            $item->delete();

            ArticleSeriesItem::where('article_series_id', $locked->id)
                ->orderBy('position')
                ->get()
                ->each(fn ($row, $i) => $row->forceFill(['position' => $i])->save());
        });

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

        $series = $this->resolveSeries($dossier, $organization);

        $data = $request->validate([
            'items' => ['required', 'array'],
            'items.*' => ['required', 'uuid'],
        ]);

        $itemIds = array_values($data['items']);

        $entries = ArticleSeriesItem::query()
            ->where('article_series_id', $series->id)
            ->whereIn('blog_post_id', $itemIds)
            ->get()
            ->keyBy('blog_post_id');

        if ($entries->count() !== count(array_unique($itemIds))) {
            throw ValidationException::withMessages([
                'items' => __('dossiers.reorder_invalid'),
            ]);
        }

        DB::transaction(function () use ($itemIds, $entries) {
            foreach ($itemIds as $index => $itemId) {
                $entries[$itemId]->update(['position' => $index + 1]);
            }
        });

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

        $series = $this->resolveSeries($dossier, $organization);

        DB::transaction(function () use ($series) {
            $series->items()->delete();
            $series->delete();
        });

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

    private function resolveSeries(Dossier $dossier, $organization): ArticleSeries
    {
        $series = ArticleSeries::where('dossier_id', $dossier->id)
            ->where('organization_id', $organization->id)
            ->first();

        if (! $series) {
            abort(404);
        }

        return $series;
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

    private function ensureBlogPostBelongsToDossier(BlogPost $post, Dossier $dossier): void
    {
        if (! $dossier->articles()->where('blog_post_id', $post->id)->exists()) {
            abort(404, 'Blog post is not attached to this dossier.');
        }
    }
}
