<?php

namespace App\Http\Controllers;

use App\Models\BlogPost;
use App\Models\Dossier;
use App\Models\DossierBlogPost;
use App\Services\Dossiers\DossierArticleIndexingDispatcher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DossierArticleController extends Controller
{
    public function store(Request $request, DossierArticleIndexingDispatcher $indexing): RedirectResponse
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

        DossierBlogPost::create([
            'organization_id' => $organization->id,
            'dossier_id' => $dossier->id,
            'blog_post_id' => $post->id,
            'added_by' => $request->user()->id,
            'position' => $nextPosition,
        ]);

        $indexing->dispatch($organization->id, $dossier->id, $post->id);

        return redirect()
            ->route('organization.dossiers.show', ['organization' => $organization, 'dossier' => $dossier->getKey()])
            ->with('success', __('dossiers.article_attached'));
    }

    public function destroy(Request $request, DossierArticleIndexingDispatcher $indexing): RedirectResponse
    {
        $dossier = $this->resolveDossier($request->route('dossier'));
        $organization = $this->currentOrganizationOrFail();
        $this->ensureDossierBelongsToCurrentOrganization($dossier);
        $this->authorize('update', $dossier);

        $post = $this->resolveBlogPost($request->route('post'));
        $this->ensureBlogPostBelongsToCurrentOrganization($post);
        $this->ensureUserOwnsBlogPost($request, $post);

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

        return redirect()
            ->route('organization.dossiers.show', ['organization' => $organization, 'dossier' => $dossier->getKey()])
            ->with('success', __('dossiers.article_detached'));
    }

    public function reorder(Request $request): RedirectResponse
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

        return redirect()
            ->route('organization.dossiers.show', ['organization' => $organization, 'dossier' => $dossier->getKey()])
            ->with('success', __('dossiers.articles_reordered'));
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
