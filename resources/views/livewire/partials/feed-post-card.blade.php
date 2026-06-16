@php
    $myReaction = $post->reactions->firstWhere('user_id', auth()->id())?->reaction_type;
    $reactionCounts = $post->reactions->groupBy('reaction_type')->map->count();
    $preview = $post->url_preview;
@endphp

<article class="overflow-hidden rounded-3xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-900">
    @if($post->image_path)
        <img src="{{ $post->imageUrl() }}" alt="" class="h-56 w-full object-cover sm:h-72">
    @endif

    <div class="space-y-4 p-4 sm:p-5">
        <header class="flex items-start justify-between gap-3">
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="rounded-full bg-indigo-50 px-2.5 py-1 text-xs font-semibold text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-200">Annonce</span>
                    @if($post->isPinned())
                        <span class="rounded-full bg-amber-50 px-2.5 py-1 text-xs font-semibold text-amber-700 dark:bg-amber-900/30 dark:text-amber-200">Épinglée</span>
                    @endif
                    @if($post->loops->isNotEmpty())
                        <span class="rounded-full bg-gray-100 px-2.5 py-1 text-xs font-medium text-gray-600 dark:bg-gray-800 dark:text-gray-300">
                            {{ $post->loops->count() }} boucle{{ $post->loops->count() > 1 ? 's' : '' }}
                        </span>
                    @endif
                </div>
                @if($post->title)
                    <h2 class="mt-3 text-lg font-bold text-gray-950 dark:text-gray-50">{{ $post->title }}</h2>
                @endif
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    {{ $post->user?->name ?? 'Membre' }} · {{ $post->created_at->diffForHumans() }}
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
                    <span class="mt-1 block line-clamp-2 text-sm font-semibold text-gray-900 dark:text-gray-100">{{ $preview['title'] ?? $preview['url'] ?? 'Lien partagé' }}</span>
                    @if(! empty($preview['description']))
                        <span class="mt-1 block line-clamp-2 text-xs text-gray-600 dark:text-gray-300">{{ $preview['description'] }}</span>
                    @endif
                </span>
            </a>
        @endif

        <div class="flex flex-wrap items-center gap-2 border-t border-gray-100 pt-3 dark:border-gray-800">
            @foreach(array_slice($emojiMap, 0, 6, true) as $type => $emoji)
                <button type="button" wire:click="toggleReaction('{{ $post->id }}', '{{ $type }}')" class="rounded-full border px-2.5 py-1 text-sm transition {{ $myReaction === $type ? 'border-indigo-300 bg-indigo-50 text-indigo-700 dark:border-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-200' : 'border-gray-200 bg-white hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-900 dark:hover:bg-gray-800' }}" aria-label="Réagir {{ $type }}">
                    <span>{{ $emoji }}</span>
                    @if(($reactionCounts[$type] ?? 0) > 0)
                        <span class="ml-1 text-xs text-gray-500 dark:text-gray-400">{{ $reactionCounts[$type] }}</span>
                    @endif
                </button>
            @endforeach
        </div>

        <section id="commentaires-{{ $post->id }}" class="space-y-3 rounded-2xl bg-gray-50 p-3 dark:bg-gray-800/60">
            <h3 class="text-sm font-semibold text-gray-800 dark:text-gray-100">Commentaires</h3>
            @forelse($post->comments as $comment)
                <div class="rounded-2xl bg-white p-3 text-sm shadow-sm dark:bg-gray-900">
                    <p class="font-semibold text-gray-800 dark:text-gray-100">{{ $comment->user?->name ?? 'Membre' }}</p>
                    <p class="mt-1 whitespace-pre-line text-gray-700 dark:text-gray-200">{{ $comment->content }}</p>
                </div>
            @empty
                <p class="text-sm text-gray-500 dark:text-gray-400">Aucun commentaire pour le moment.</p>
            @endforelse

            <form wire:submit="addComment('{{ $post->id }}')" class="space-y-2">
                <textarea wire:model="commentForms.{{ $post->id }}" rows="2" class="w-full rounded-2xl border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100" placeholder="Ajouter un commentaire utile"></textarea>
                @error('commentForms.'.$post->id) <p class="text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                <button type="submit" class="rounded-full bg-gray-900 px-4 py-2 text-sm font-semibold text-white transition hover:bg-gray-700 dark:bg-gray-100 dark:text-gray-900 dark:hover:bg-white">Commenter</button>
            </form>
        </section>
    </div>
</article>
