<?php

namespace App\Http\Controllers;

use App\Models\BlogPost;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class BlogCoAuthorController extends Controller
{
    public function index(BlogPost $post): JsonResponse
    {
        $organization = currentOrganization();
        if (! $organization || $post->organization_id !== $organization->id) {
            abort(404);
        }

        Gate::authorize('update', $post);

        $coAuthors = $post->coAuthors()
            ->get(['users.id', 'users.name', 'users.first_name', 'users.email', 'users.avatar'])
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->fullName,
                'first_name' => $user->first_name,
                'email' => $user->email,
                'avatar_url' => $user->avatar_url,
            ]);

        return response()->json(['co_authors' => $coAuthors]);
    }

    public function store(Request $request, BlogPost $post): JsonResponse
    {
        $organization = currentOrganization();
        if (! $organization || $post->organization_id !== $organization->id) {
            abort(404);
        }

        Gate::authorize('manageCoAuthors', $post);

        $data = $request->validate([
            'user_id' => 'required|uuid|exists:users,id',
        ]);

        $user = User::findOrFail($data['user_id']);

        if ($user->organization_id !== $post->organization_id) {
            return response()->json(['message' => __('blog.co_author_cross_org')], 422);
        }

        if ($user->id === $post->user_id) {
            return response()->json(['message' => __('blog.co_author_is_owner')], 422);
        }

        $exists = $post->coAuthors()->where('user_id', $user->id)->exists();
        if ($exists) {
            return response()->json(['message' => __('blog.co_author_already')], 422);
        }

        $post->coAuthors()->attach($user->id, [
            'role' => 'coauthor',
            'added_by' => $request->user()->id,
        ]);

        return response()->json([
            'co_author' => [
                'id' => $user->id,
                'name' => $user->fullName,
                'first_name' => $user->first_name,
                'email' => $user->email,
                'avatar_url' => $user->avatar_url,
            ],
            'message' => __('blog.co_author_added'),
        ]);
    }

    public function destroy(BlogPost $post, User $user): JsonResponse
    {
        $organization = currentOrganization();
        if (! $organization || $post->organization_id !== $organization->id) {
            abort(404);
        }

        Gate::authorize('manageCoAuthors', $post);

        $post->coAuthors()->detach($user->id);

        return response()->json(['message' => __('blog.co_author_removed')]);
    }

    public function search(Request $request, BlogPost $post): JsonResponse
    {
        $organization = currentOrganization();
        if (! $organization || $post->organization_id !== $organization->id) {
            abort(404);
        }

        Gate::authorize('update', $post);

        $query = $request->input('q', '');

        $users = User::where('organization_id', $post->organization_id)
            ->where(function ($q) use ($query) {
                $q->where('name', 'like', "%{$query}%")
                    ->orWhere('first_name', 'like', "%{$query}%")
                    ->orWhere('email', 'like', "%{$query}%");
            })
            ->limit(10)
            ->get(['id', 'name', 'first_name', 'email', 'avatar'])
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->fullName,
                'avatar_url' => $user->avatar_url,
            ]);

        return response()->json(['users' => $users]);
    }

    public function orgIndex(string $org, BlogPost $post): JsonResponse
    {
        return $this->index($post);
    }

    public function orgStore(Request $request, string $org, BlogPost $post): JsonResponse
    {
        return $this->store($request, $post);
    }

    public function orgDestroy(string $org, BlogPost $post, User $user): JsonResponse
    {
        return $this->destroy($post, $user);
    }

    public function orgSearch(Request $request, string $org, BlogPost $post): JsonResponse
    {
        return $this->search($request, $post);
    }
}
