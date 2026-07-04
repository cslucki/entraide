<x-admin-layout title="Blog — Articles">
    <!-- Filters -->
    <form method="GET" class="mb-5 flex flex-wrap gap-3">
        <input type="text" name="search" value="{{ request('search') }}" placeholder="Titre ou contenu..."
            class="flex-1 min-w-48 px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-indigo-500">
        <select name="status" class="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm">
            <option value="">Tous les statuts</option>
            <option value="draft"     {{ request('status') === 'draft'     ? 'selected' : '' }}>Brouillon</option>
            <option value="pending"   {{ request('status') === 'pending'   ? 'selected' : '' }}>En attente</option>
            <option value="published" {{ request('status') === 'published' ? 'selected' : '' }}>Publié</option>
            <option value="archived"  {{ request('status') === 'archived'  ? 'selected' : '' }}>Archivé</option>
        </select>
        <select name="organization_id" class="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm">
            <option value="all" {{ $selectedOrganizationId === 'all' ? 'selected' : '' }}>Toutes les organisations</option>
            @foreach($organizations as $org)
            <option value="{{ $org->id }}" {{ $selectedOrganizationId === $org->id ? 'selected' : '' }}>{{ $org->name }} {{ $org->is_default ? '(par défaut)' : '' }}</option>
            @endforeach
        </select>
        <button type="submit" class="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm hover:bg-indigo-700">Filtrer</button>
        @if(request()->hasAny(['search', 'status', 'organization_id']))
        <a href="{{ route('admin.blog') }}" class="px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg text-sm text-gray-600 dark:text-gray-400">Effacer</a>
        @endif
    </form>

    @if(session('success'))
    <div class="mb-4 px-4 py-3 bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-700 rounded-lg text-sm text-green-700 dark:text-green-300">
        {{ session('success') }}
    </div>
    @endif

    <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 dark:bg-gray-700">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Titre</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide hidden sm:table-cell">Auteur</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide hidden lg:table-cell">Organisation</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Statut</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide hidden md:table-cell">Vues</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide hidden md:table-cell">♥ / 💬</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide hidden lg:table-cell">Date</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                @forelse($posts as $post)
                @php
                    $statusColors = [
                        'draft'     => 'text-gray-500 bg-gray-100 dark:bg-gray-700',
                        'pending'   => 'text-yellow-700 bg-yellow-50 dark:bg-yellow-900/20',
                        'published' => 'text-green-700 bg-green-50 dark:bg-green-900/20',
                        'archived'  => 'text-red-600 bg-red-50 dark:bg-red-900/20',
                    ];
                    $statusLabels = ['draft' => 'Brouillon', 'pending' => 'En attente', 'published' => 'Publié', 'archived' => 'Archivé'];
                @endphp
                <tr class="hover:bg-gray-50 dark:hover:bg-gray-750">
                    <td class="px-4 py-3">
                        <a href="{{ route('blog.show', $post) }}" target="_blank"
                           class="font-medium text-gray-900 dark:text-gray-100 hover:text-indigo-600 dark:hover:text-indigo-400 transition line-clamp-1">
                            {{ $post->title }}
                        </a>
                    </td>
                    <td class="px-4 py-3 hidden sm:table-cell">
                        @if($post->user)
                        <span class="text-xs text-gray-500 dark:text-gray-400">{{ $post->user->fullName }}</span>
                        @else
                        <span class="text-xs text-gray-400">Supprimé</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 hidden lg:table-cell">
                        <span class="text-xs text-gray-500 dark:text-gray-400">{{ $post->organization?->name ?? '—' }}</span>
                    </td>
                    <td class="px-4 py-3">
                        <form action="{{ route('admin.blog.status', $post) }}" method="POST" class="inline">
                            @csrf @method('PATCH')
                            <select name="status" onchange="this.form.submit()"
                                class="text-xs px-2 py-1 rounded-full font-medium border-0 cursor-pointer focus:ring-1 focus:ring-indigo-500 {{ $statusColors[$post->status] ?? '' }}">
                                @foreach($statusLabels as $val => $label)
                                <option value="{{ $val }}" {{ $post->status === $val ? 'selected' : '' }}
                                    class="bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100">{{ $label }}</option>
                                @endforeach
                            </select>
                        </form>
                    </td>
                    <td class="px-4 py-3 text-gray-500 dark:text-gray-400 hidden md:table-cell">{{ number_format($post->views_count) }}</td>
                    <td class="px-4 py-3 text-gray-500 dark:text-gray-400 hidden md:table-cell">{{ $post->likes_count }} / {{ $post->comments_count }}</td>
                    <td class="px-4 py-3 text-gray-400 hidden lg:table-cell text-xs">
                        {{ $post->published_at?->format('d/m/Y') ?? $post->created_at->format('d/m/Y') }}
                    </td>
                    <td class="px-4 py-3 text-right whitespace-nowrap">
                        <a href="{{ route('admin.blog.edit', $post) }}"
                           class="text-xs font-medium text-indigo-600 dark:text-indigo-400 hover:underline mr-2">Modifier</a>
                        <a href="{{ route('blog.show', $post) }}" target="_blank"
                           class="text-xs text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 mr-2">Aperçu ⬀</a>
                        <form action="{{ route('admin.blog.destroy', $post) }}" method="POST" class="inline"
                              onsubmit="return confirm('Supprimer « {{ addslashes($post->title) }} » ?')">
                            @csrf @method('DELETE')
                            <button type="submit" class="text-xs text-red-600 dark:text-red-400 hover:underline">Supprimer</button>
                        </form>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="8" class="px-4 py-10 text-center text-gray-400 dark:text-gray-500">Aucun article trouvé.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $posts->links() }}</div>
</x-admin-layout>
