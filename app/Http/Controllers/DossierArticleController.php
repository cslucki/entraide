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
                'slug' => Str::slug($data['title']),
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
                'redirect_url' => "/org/{$organization->slug}/blog/{$post->slug}/edit",
            ], 201);
        });
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

        $series = ArticleSeries::where('dossier_id', $dossier->id)
            ->where('organization_id', $organization->id)
            ->first();

        if ($series && $series->root_blog_post_id === $post->id) {
            throw ValidationException::withMessages([
                'blog_post_id' => __('dossiers.cannot_detach_series_root'),
            ]);
        }

        if ($series) {
            $seriesItem = ArticleSeriesItem::where('article_series_id', $series->id)
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
