<?php

namespace App\Http\Controllers;

use App\Models\BlogPost;
use App\Models\Loop;
use App\Models\LoopMember;
use App\Services\LoopMessageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BlogPostLoopController extends Controller
{
    public function store(Request $request, BlogPost $post): JsonResponse
    {
        $organization = currentOrganization();
        if (! $organization || $post->organization_id !== $organization->id) {
            abort(404);
        }

        $this->authorize('update', $post);

        $validated = $request->validate([
            'loop_id' => ['required', 'string', 'uuid'],
        ]);

        $loop = Loop::where('id', $validated['loop_id'])
            ->where('organization_id', $organization->id)
            ->firstOrFail();

        $isMember = LoopMember::where('loop_id', $loop->id)
            ->where('user_id', $request->user()->id)
            ->where('status', 'active')
            ->exists();

        if (! $isMember) {
            return response()->json(['message' => __('blog.loop_not_member')], 403);
        }

        if ($post->loops()->where('loop_id', $loop->id)->exists()) {
            return response()->json(['message' => __('blog.loop_already_linked')], 422);
        }

        $post->loops()->attach($loop->id);

        $orgSlug = request()->route('organization');
        $loopUrl = $orgSlug
            ? route('organization.loops.show', ['organization' => $orgSlug, 'loop' => $loop])
            : route('loops.show', $loop);

        return response()->json([
            'message' => __('blog.loop_linked'),
            'loop' => [
                'id' => $loop->id,
                'name' => $loop->name,
                'slug' => $loop->slug,
                'discussionUrl' => $loopUrl,
            ],
        ]);
    }

    public function destroy(Request $request, BlogPost $post, Loop $loop): JsonResponse
    {
        $organization = currentOrganization();
        if (! $organization || $post->organization_id !== $organization->id) {
            abort(404);
        }

        $this->authorize('update', $post);

        $post->loops()->detach($loop->id);

        return response()->json(['message' => __('blog.loop_unlinked')]);
    }

    public function messages(Request $request, BlogPost $post): JsonResponse
    {
        $organization = currentOrganization();
        if (! $organization || $post->organization_id !== $organization->id) {
            abort(404);
        }

        $this->authorize('update', $post);

        $loops = $post->loops()->with(['messages' => function ($q) {
            $q->latest()->take(3);
        }, 'messages.sender'])->get();

        $orgSlug = $request->route('organization');

        $result = $loops->map(function ($loop) use ($request, $orgSlug) {
            $isMember = LoopMember::where('loop_id', $loop->id)
                ->where('user_id', $request->user()->id)
                ->where('status', 'active')
                ->exists();

            $loopUrl = $orgSlug
                ? route('organization.loops.show', ['organization' => $orgSlug, 'loop' => $loop])
                : route('loops.show', $loop);

            return [
                'id' => $loop->id,
                'name' => $loop->name,
                'slug' => $loop->slug,
                'discussionUrl' => $loopUrl,
                'is_member' => $isMember,
                'messages' => $loop->messages->map(function ($msg) {
                    return [
                        'id' => $msg->id,
                        'body' => $msg->body,
                        'created_at_human' => $msg->created_at->diffForHumans(),
                        'sender_name' => $msg->sender?->name ?? __('blog.loop_system'),
                    ];
                }),
            ];
        });

        return response()->json(['loops' => $result]);
    }

    public function orgStore(Request $request, string $org, BlogPost $post): JsonResponse
    {
        return $this->store($request, $post);
    }

    public function orgDestroy(Request $request, string $org, BlogPost $post, Loop $loop): JsonResponse
    {
        return $this->destroy($request, $post, $loop);
    }

    public function storeMessage(Request $request, BlogPost $post, Loop $loop): JsonResponse
    {
        $organization = currentOrganization();
        if (! $organization || $post->organization_id !== $organization->id) {
            abort(404);
        }

        $this->authorize('update', $post);

        if ($loop->organization_id !== $organization->id) {
            abort(404);
        }

        if (! $post->loops()->where('loop_id', $loop->id)->exists()) {
            return response()->json(['message' => 'Loop not linked to this article.'], 422);
        }

        $validated = $request->validate([
            'body' => 'required|string|max:5000',
        ]);

        try {
            $message = app(LoopMessageService::class)->sendUserMessage(
                $loop,
                $request->user(),
                $validated['body'],
                ['source' => 'blog_loop_card', 'blog_post_id' => $post->id],
            );

            return response()->json([
                'message' => [
                    'id' => $message->id,
                    'body' => $message->body,
                    'sender_name' => $message->sender?->name ?? __('blog.loop_system'),
                    'created_at_human' => $message->created_at->diffForHumans(),
                ],
            ], 201);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        }
    }

    public function orgStoreMessage(Request $request, string $org, BlogPost $post, Loop $loop): JsonResponse
    {
        return $this->storeMessage($request, $post, $loop);
    }

    public function orgMessages(Request $request, string $org, BlogPost $post): JsonResponse
    {
        return $this->messages($request, $post);
    }
}
