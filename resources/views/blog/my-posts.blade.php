<x-app-layout>
    <x-slot name="title">Mes articles — Blog BouclePro</x-slot>

    <x-page-container>
        <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 mb-8">
            <div>
                <h1 class="hidden sm:block text-3xl font-bold text-gray-900 dark:text-gray-100">Mes articles</h1>
                <p class="mt-1 text-sm sm:text-base text-gray-500 dark:text-gray-400">Gérez vos brouillons, articles publiés et commentaires</p>
            </div>
            <a href="{{ route('blog.create') }}" class="w-full sm:w-auto inline-flex justify-center items-center gap-2 px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold rounded-lg transition">
                <svg class="w-4 h-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                Nouvel article
            </a>
        </div>

        <div x-data="{ tab: 'drafts' }">
        <div class="flex gap-1 mb-6 border-b border-gray-200 dark:border-gray-700">
            <button @click="tab = 'drafts'" :class="tab === 'drafts' ? 'border-indigo-600 text-indigo-600 dark:text-indigo-400' : 'border-transparent text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300'"
                    class="px-4 py-3 text-sm font-medium border-b-2 transition -mb-px">
                Brouillons ({{ $drafts->total() }})
            </button>
            <button @click="tab = 'published'" :class="tab === 'published' ? 'border-indigo-600 text-indigo-600 dark:text-indigo-400' : 'border-transparent text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300'"
                    class="px-4 py-3 text-sm font-medium border-b-2 transition -mb-px">
                Publiés ({{ $publishedPosts->total() }})
            </button>
            <button @click="tab = 'comments'" :class="tab === 'comments' ? 'border-indigo-600 text-indigo-600 dark:text-indigo-400' : 'border-transparent text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300'"
                    class="px-4 py-3 text-sm font-medium border-b-2 transition -mb-px">
                Commentaires ({{ $comments->total() }})
            </button>
        </div>

        {{-- DRAFTS --}}
        <div x-show="tab === 'drafts'">
            @if($drafts->isEmpty())
            <div class="text-center py-16 text-gray-500 dark:text-gray-400 bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700">
                <svg class="w-12 h-12 mx-auto mb-4 opacity-30" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                <p>Aucun brouillon.</p>
                <a href="{{ route('blog.create') }}" class="mt-3 inline-block text-indigo-600 dark:text-indigo-400 hover:underline">Écrire un article</a>
            </div>
            @else
            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-700/50">
                        <tr>
                            <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Titre</th>
                            <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide hidden sm:table-cell">Statut</th>
                            <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide hidden md:table-cell">Date</th>
                            <th class="px-5 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        @foreach($drafts as $post)
                        <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/30 transition">
                            <td class="px-5 py-4">
                                <a href="{{ route('blog.show', $post) }}" class="font-medium text-gray-900 dark:text-gray-100 hover:text-indigo-600 dark:hover:text-indigo-400 transition line-clamp-1">
                                    {{ $post->title }}
                                </a>
                            </td>
                            <td class="px-5 py-4 hidden sm:table-cell">
                                @php $colors = ['draft' => 'text-gray-500 bg-gray-100 dark:bg-gray-700', 'pending' => 'text-yellow-700 bg-yellow-50 dark:bg-yellow-900/20']; @endphp
                                <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium {{ $colors[$post->status] ?? '' }}">
                                    {{ ['draft' => 'Brouillon', 'pending' => 'En attente'][$post->status] ?? $post->status }}
                                </span>
                            </td>
                            <td class="px-5 py-4 text-gray-400 hidden md:table-cell text-xs">{{ $post->created_at->format('d/m/Y') }}</td>
                            <td class="px-5 py-4 text-right">
                                <a href="{{ route('blog.edit', $post) }}" class="text-xs text-indigo-600 dark:text-indigo-400 hover:underline">Modifier</a>
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $drafts->links() }}</div>
            @endif
        </div>

        {{-- PUBLISHED --}}
        <div x-show="tab === 'published'">
            @if($publishedPosts->isEmpty())
            <div class="text-center py-16 text-gray-500 dark:text-gray-400 bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700">
                <svg class="w-12 h-12 mx-auto mb-4 opacity-30" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19 20H5a2 2 0 01-2-2V6a2 2 0 012-2h10a2 2 0 012 2v1m2 13a2 2 0 01-2-2V7m2 13a2 2 0 002-2V9a2 2 0 00-2-2h-2m-4-3H9M7 16h6M7 8h6v4H7V8z"/></svg>
                <p>Aucun article publié.</p>
                <a href="{{ route('blog.create') }}" class="mt-3 inline-block text-indigo-600 dark:text-indigo-400 hover:underline">Écrire un article</a>
            </div>
            @else
            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-700/50">
                        <tr>
                            <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Titre</th>
                            <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide hidden md:table-cell">Vues</th>
                            <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide hidden md:table-cell">♥ Likes</th>
                            <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide hidden md:table-cell">Commentaires</th>
                            <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide hidden lg:table-cell">Date</th>
                            <th class="px-5 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        @foreach($publishedPosts as $post)
                        <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/30 transition">
                            <td class="px-5 py-4">
                                <a href="{{ route('blog.show', $post) }}" class="font-medium text-gray-900 dark:text-gray-100 hover:text-indigo-600 dark:hover:text-indigo-400 transition line-clamp-1">
                                    {{ $post->title }}
                                </a>
                            </td>
                            <td class="px-5 py-4 text-gray-500 dark:text-gray-400 hidden md:table-cell">{{ number_format($post->views_count) }}</td>
                            <td class="px-5 py-4 text-gray-500 dark:text-gray-400 hidden md:table-cell">{{ $post->likes_count }}</td>
                            <td class="px-5 py-4 text-gray-500 dark:text-gray-400 hidden md:table-cell">{{ $post->comments_count }}</td>
                            <td class="px-5 py-4 text-gray-400 hidden lg:table-cell text-xs">{{ $post->published_at?->format('d/m/Y') ?? $post->created_at->format('d/m/Y') }}</td>
                            <td class="px-5 py-4 text-right">
                                <a href="{{ route('blog.edit', $post) }}" class="text-xs text-indigo-600 dark:text-indigo-400 hover:underline">Modifier</a>
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $publishedPosts->links() }}</div>
            @endif
        </div>

        {{-- COMMENTS --}}
        <div x-show="tab === 'comments'">
            @if($comments->isEmpty())
            <div class="text-center py-16 text-gray-500 dark:text-gray-400 bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700">
                <svg class="w-12 h-12 mx-auto mb-4 opacity-30" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>
                <p>Aucun commentaire.</p>
            </div>
            @else
            <div class="space-y-3">
                @foreach($comments as $comment)
                <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-5">
                    <div class="flex items-start justify-between gap-4">
                        <div class="min-w-0">
                            <p class="text-sm text-gray-800 dark:text-gray-200">{{ Str::limit($comment->content, 200) }}</p>
                            <p class="mt-2 text-xs text-gray-400">
                                Sur <a href="{{ route('blog.show', $comment->post) }}" class="text-indigo-600 dark:text-indigo-400 hover:underline">{{ $comment->post->title }}</a>
                                · {{ $comment->created_at->diffForHumans() }}
                            </p>
                        </div>
                    </div>
                </div>
                @endforeach
            </div>
            <div class="mt-4">{{ $comments->links() }}</div>
            @endif
        </div>
    </div>
    </x-page-container>
</x-app-layout>