@php
    $myReaction = $post->reactions->firstWhere('user_id', auth()->id())?->reaction_type;
    $reactionCounts = $post->reactions->groupBy('reaction_type')->map->count();
    $totalReactions = $reactionCounts->sum();
    $commentCount = $post->comments->count();
    $lastComment = $post->comments->first();
    $preview = $post->url_preview;
@endphp

<article id="feed-post-{{ $post->id }}" class="overflow-hidden rounded-3xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-900" x-data="{ showComments: false, showReactions: false }">
    @if($post->image_path)
        <img src="{{ $post->imageUrl() }}" alt="" class="h-56 w-full object-cover sm:h-72">
    @endif

    <div class="space-y-4 p-4 sm:p-5">
        <header class="flex items-start justify-between gap-3">
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="rounded-full bg-indigo-50 px-2.5 py-1 text-xs font-semibold text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-200">{{ __('feed.post_badge') }}</span>
                    @if($post->isPinned())
                        <span class="rounded-full bg-amber-50 px-2.5 py-1 text-xs font-semibold text-amber-700 dark:bg-amber-900/30 dark:text-amber-200">{{ __('feed.pinned_badge') }}</span>
                    @endif
                    @if($post->loops->isNotEmpty())
                        <span class="rounded-full bg-gray-100 px-2.5 py-1 text-xs font-medium text-gray-600 dark:bg-gray-800 dark:text-gray-300">
                            {{ trans_choice('feed.loop_count', $post->loops->count(), ['count' => $post->loops->count()]) }}
                        </span>
                    @endif
                </div>
                @if($post->title)
                    <h2 class="mt-3 text-lg font-bold text-gray-950 dark:text-gray-50">{{ $post->title }}</h2>
                @endif
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    {{ $post->user?->full_name ?? __('feed.member_fallback') }} · {{ $post->published_at?->isoFormat('lll') ?? $post->created_at->isoFormat('lll') }}
                </p>
            </div>
        </header>

        <div class="prose prose-sm max-w-none text-gray-800 dark:prose-invert dark:text-gray-100">
            {!! markdown($post->content) !!}
        </div>

        @if(is_array($preview))
            <a href="{{ $preview['url'] ?? '#' }}" target="_blank" rel="noopener noreferrer" class="flex gap-3 rounded-2xl border border-gray-200 bg-gray-50 p-3 transition hover:bg-gray-100 dark:border-gray-700 dark:bg-gray-800/70 dark:hover:bg-gray-800">
                @if(! empty($preview['image']))
                    <img src="{{ $preview['image'] }}" alt="" class="h-16 w-16 rounded-xl object-cover">
                @endif
                <span class="min-w-0 flex-1">
                    <span class="block truncate text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $preview['domain'] ?? parse_url($preview['url'] ?? '', PHP_URL_HOST) }}</span>
                    <span class="mt-1 block line-clamp-2 text-sm font-semibold text-gray-900 dark:text-gray-100">{{ $preview['title'] ?? $preview['url'] ?? __('feed.shared_link') }}</span>
                    @if(! empty($preview['description']))
                        <span class="mt-1 block line-clamp-2 text-xs text-gray-600 dark:text-gray-300">{{ $preview['description'] }}</span>
                    @endif
                </span>
            </a>
        @endif

        <div class="flex items-center gap-4 border-t border-gray-100 pt-3 text-sm dark:border-gray-800">
            <button type="button" @click="showComments = !showComments" class="inline-flex items-center gap-1.5 text-gray-500 hover:text-indigo-600 transition dark:text-gray-400 dark:hover:text-indigo-400">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>
                @if($commentCount > 0)
                    <span>{{ $commentCount }}</span>
                @else
                    <span>{{ __('feed.comment_cta') }}</span>
                @endif
            </button>

            <button type="button" @click="showReactions = !showReactions" class="inline-flex items-center gap-1.5 text-gray-500 hover:text-indigo-600 transition dark:text-gray-400 dark:hover:text-indigo-400">
                <span class="text-base">👍</span>
                @if($totalReactions > 0)
                    <span>{{ $totalReactions }}</span>
                @endif
            </button>
        </div>

        @if($lastComment)
            <div class="flex items-start gap-2 text-sm">
                <span class="font-semibold text-gray-800 dark:text-gray-100 shrink-0">{{ $lastComment->user?->full_name ?? __('feed.member_fallback') }}</span>
                <p class="text-gray-600 dark:text-gray-300 line-clamp-2">{{ $lastComment->content }}</p>
            </div>
            @if($commentCount > 1)
                <button type="button" @click="$dispatch('open-modal', 'comments-{{ $post->id }}')" class="text-xs text-indigo-600 hover:underline dark:text-indigo-400">
                    {{ __('feed.view_comments', ['count' => $commentCount]) }}
                </button>
            @endif
        @endif

        <div x-show="showComments" x-cloak class="space-y-3">
            <form wire:submit="addComment('{{ $post->id }}')" class="space-y-2">
                <textarea wire:model="commentForms.{{ $post->id }}" rows="2" class="w-full rounded-2xl border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100" placeholder="{{ __('feed.comment_placeholder') }}"></textarea>
                @error('commentForms.'.$post->id) <p class="text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                <button type="submit" class="rounded-full bg-gray-900 px-4 py-2 text-sm font-semibold text-white transition hover:bg-gray-700 dark:bg-gray-100 dark:text-gray-900 dark:hover:bg-white">{{ __('feed.comment_submit') }}</button>
            </form>

            @if($commentCount > 0)
                <div class="space-y-2">
                    @foreach($post->comments as $comment)
                        <div class="rounded-2xl bg-gray-50 p-3 text-sm dark:bg-gray-800/60">
                            <p class="font-semibold text-gray-800 dark:text-gray-100">{{ $comment->user?->full_name ?? __('feed.member_fallback') }}</p>
                            <p class="mt-0.5 whitespace-pre-line text-gray-600 dark:text-gray-300">{{ $comment->content }}</p>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        <div x-show="showReactions" x-cloak class="flex flex-wrap items-center gap-2">
            @foreach(array_slice($emojiMap, 0, 6, true) as $type => $emoji)
                <button type="button" wire:click="toggleReaction('{{ $post->id }}', '{{ $type }}')" class="rounded-full border px-2.5 py-1 text-sm transition {{ $myReaction === $type ? 'border-indigo-300 bg-indigo-50 text-indigo-700 dark:border-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-200' : 'border-gray-200 bg-white hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-900 dark:hover:bg-gray-800' }}" aria-label="{{ __('feed.react', ['type' => $type]) }}">
                    <span>{{ $emoji }}</span>
                    @if(($reactionCounts[$type] ?? 0) > 0)
                        <span class="ml-1 text-xs text-gray-500 dark:text-gray-400">{{ $reactionCounts[$type] }}</span>
                    @endif
                </button>
            @endforeach
        </div>

        @if($commentCount > 1)
            <x-modal name="comments-{{ $post->id }}" maxWidth="lg">
                <div class="p-6 space-y-4">
                    <h3 class="text-lg font-bold text-gray-900 dark:text-gray-100">{{ __('feed.comments_title', ['count' => $commentCount]) }}</h3>
                    @foreach($post->comments->sortBy('created_at') as $comment)
                        <div class="border-b border-gray-100 pb-3 last:border-0 dark:border-gray-700">
                            <p class="font-semibold text-gray-800 dark:text-gray-100">{{ $comment->user?->full_name ?? __('feed.member_fallback') }}</p>
                            <p class="mt-1 whitespace-pre-line text-sm text-gray-600 dark:text-gray-300">{{ $comment->content }}</p>
                            <p class="mt-0.5 text-xs text-gray-400">{{ $comment->created_at->isoFormat('lll') }}</p>
                        </div>
                    @endforeach
                </div>
            </x-modal>
        @endif
    </div>
</article>
