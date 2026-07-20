<?php

namespace App\Http\Controllers;

use App\Models\BlogPost;
use App\Models\Dossier;
use App\Models\DossierBlogPost;
use App\Services\Dossiers\DossierArticleIndexingDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
            'dossier' => [
                'id' => $entry->dossier->id,
                'name' => $entry->dossier->name,
                'position' => $entry->position,
            ],
        ]);
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
