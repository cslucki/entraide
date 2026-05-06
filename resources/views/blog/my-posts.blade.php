<x-app-layout>
    <x-slot name="title">Mes articles — Blog BouclePro</x-slot>

    <div class="max-w-4xl mx-auto px-4 py-8">

        <div class="flex items-center justify-between mb-6">
            <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">Mes articles</h1>
            <a href="{{ route('blog.create') }}" class="inline-flex items-center gap-2 px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold rounded-lg transition">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                Nouvel article
            </a>
        </div>

        @if($posts->isEmpty())
        <div class="text-center py-16 text-gray-500 dark:text-gray-400 bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700">
            <svg class="w-12 h-12 mx-auto mb-4 opacity-30" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
            <p>Vous n'avez pas encore d'articles.</p>
            <a href="{{ route('blog.create') }}" class="mt-3 inline-block text-indigo-600 dark:text-indigo-400 hover:underline">Écrire votre premier article</a>
        </div>
        @else
        <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 dark:bg-gray-700/50">
                    <tr>
                        <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Titre</th>
                        <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide hidden sm:table-cell">Statut</th>
                        <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide hidden md:table-cell">Vues</th>
                        <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide hidden md:table-cell">♥ Likes</th>
                        <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide hidden md:table-cell">Commentaires</th>
                        <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide hidden lg:table-cell">Date</th>
                        <th class="px-5 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    @foreach($posts as $post)
                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/30 transition">
                        <td class="px-5 py-4">
                            <a href="{{ route('blog.show', $post) }}" class="font-medium text-gray-900 dark:text-gray-100 hover:text-indigo-600 dark:hover:text-indigo-400 transition line-clamp-1">
                                {{ $post->title }}
                            </a>
                        </td>
                        <td class="px-5 py-4 hidden sm:table-cell">
                            @php $statusColors = ['draft' => 'text-gray-500 bg-gray-100 dark:bg-gray-700', 'pending' => 'text-yellow-700 bg-yellow-50 dark:bg-yellow-900/20', 'published' => 'text-green-700 bg-green-50 dark:bg-green-900/20', 'archived' => 'text-red-600 bg-red-50 dark:bg-red-900/20']; @endphp
                            <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium {{ $statusColors[$post->status] ?? '' }}">
                                {{ ['draft' => 'Brouillon', 'pending' => 'En attente', 'published' => 'Publié', 'archived' => 'Archivé'][$post->status] }}
                            </span>
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
        <div class="mt-4">{{ $posts->links() }}</div>
        @endif
    </div>
</x-app-layout>
