<x-admin-layout title="Tags">
    <div class="flex items-center justify-between mb-6 gap-4">
        <div>
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ $tags->total() }} tags</p>
            @if($selectedOrganizationId !== 'all')
                @php $org = $organizations->firstWhere('id', $selectedOrganizationId); @endphp
                @if($org)
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    Organisation : <span class="font-medium text-gray-800 dark:text-gray-200">{{ $org->name }}</span>
                </p>
                @endif
            @endif
        </div>
        <form method="GET" class="flex items-center gap-3" id="filter-form">
            <input type="text" name="search" value="{{ request('search') }}" placeholder="Rechercher un tag..."
                   class="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-indigo-500 w-48">
            <select name="organization_id" onchange="document.getElementById('filter-form').submit()"
                    class="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-indigo-500">
                <option value="all" {{ $selectedOrganizationId === 'all' ? 'selected' : '' }}>Toutes les org.</option>
                @foreach($organizations as $org)
                <option value="{{ $org->id }}" {{ $selectedOrganizationId === $org->id ? 'selected' : '' }}>{{ $org->name }}</option>
                @endforeach
            </select>
            <button type="submit" class="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm hover:bg-indigo-700">Filtrer</button>
            @if(request()->hasAny(['search', 'organization_id']))
            <a href="{{ route('admin.tags') }}" class="px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg text-sm text-gray-600 dark:text-gray-400">Effacer</a>
            @endif
        </form>
    </div>

    <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
        @php
            $sortUrl = fn ($column) => request()->fullUrlWithQuery([
                'sort' => $column,
                'direction' => $sort === $column && $direction === 'asc' ? 'desc' : 'asc',
            ]);
            $sortIndicator = fn ($column) => $sort === $column ? ($direction === 'asc' ? ' &#9650;' : ' &#9660;') : '';
        @endphp
        <table class="w-full text-sm">
            <thead class="bg-gray-50 dark:bg-gray-700">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">
                        <a href="{{ $sortUrl('name') }}" class="hover:text-gray-700 dark:hover:text-gray-200">Nom<span class="text-indigo-500">{!! $sortIndicator('name') !!}</span></a>
                    </th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">
                        <a href="{{ $sortUrl('slug') }}" class="hover:text-gray-700 dark:hover:text-gray-200">Slug<span class="text-indigo-500">{!! $sortIndicator('slug') !!}</span></a>
                    </th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Organisation</th>
                    <th class="px-4 py-3 text-center text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">
                        <a href="{{ $sortUrl('blog_posts_count') }}" class="hover:text-gray-700 dark:hover:text-gray-200">Blog<span class="text-indigo-500">{!! $sortIndicator('blog_posts_count') !!}</span></a>
                    </th>
                    <th class="px-4 py-3 text-center text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">
                        <a href="{{ $sortUrl('services_count') }}" class="hover:text-gray-700 dark:hover:text-gray-200">Services<span class="text-indigo-500">{!! $sortIndicator('services_count') !!}</span></a>
                    </th>
                    <th class="px-4 py-3 text-center text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Total</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                @forelse($tags as $tag)
                <tr class="hover:bg-gray-50 dark:hover:bg-gray-750">
                    <td class="px-4 py-3 font-medium text-gray-900 dark:text-gray-100">{{ $tag->name }}</td>
                    <td class="px-4 py-3 text-xs text-gray-500 dark:text-gray-400 font-mono">{{ $tag->slug }}</td>
                    <td class="px-4 py-3 text-xs text-gray-500 dark:text-gray-400">{{ $tag->organization?->name ?? '—' }}</td>
                    <td class="px-4 py-3 text-center text-sm {{ $tag->blog_posts_count > 0 ? 'text-indigo-600 dark:text-indigo-400 font-medium' : 'text-gray-400' }}">{{ $tag->blog_posts_count }}</td>
                    <td class="px-4 py-3 text-center text-sm {{ $tag->services_count > 0 ? 'text-indigo-600 dark:text-indigo-400 font-medium' : 'text-gray-400' }}">{{ $tag->services_count }}</td>
                    <td class="px-4 py-3 text-center text-sm font-medium {{ ($tag->blog_posts_count + $tag->services_count) > 0 ? 'text-gray-900 dark:text-gray-100' : 'text-gray-400' }}">{{ $tag->blog_posts_count + $tag->services_count }}</td>
                    <td class="px-4 py-3">
                        <div class="flex gap-2 items-center">
                            <a href="{{ route('admin.tags.edit', $tag) }}" class="text-xs text-amber-600 dark:text-amber-400 hover:underline">Modifier</a>
                            @if($tag->blog_posts_count + $tag->services_count === 0)
                            <form method="POST" action="{{ route('admin.tags.destroy', $tag) }}"
                                  onsubmit="return confirm('Supprimer ce tag ?')">
                                @csrf @method('DELETE')
                                <button class="text-xs text-red-500 hover:underline">Supprimer</button>
                            </form>
                            @endif
                        </div>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="7" class="px-4 py-8 text-center text-sm text-gray-400">Aucun tag trouvé.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if($tags->hasPages())
    <div class="mt-4">{{ $tags->withQueryString()->links() }}</div>
    @endif
</x-admin-layout>
