<x-admin-layout title="Organisations">
    <div class="flex justify-end mb-4">
        <a href="{{ route('admin.organizations.create') }}"
           class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium rounded-lg transition">
            + Créer une organisation
        </a>
    </div>

    <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 dark:bg-gray-700">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Organisation</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Slug</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Responsable</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Membres</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Services</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Visibilité</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Boucles</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Plateforme</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                @forelse($organizations as $c)
                <tr class="hover:bg-gray-50 dark:hover:bg-gray-750 {{ !$c->is_active ? 'opacity-60' : '' }}">
                    <td class="px-4 py-3">
                        <div class="flex items-center gap-3">
                            <div class="w-3 h-3 rounded-full flex-shrink-0" style="background-color: {{ $c->accent_color }}"></div>
                            <div class="min-w-0">
                                <a href="{{ route('organization.home', ['organization' => $c->slug]) }}" target="_blank" rel="noopener"
                                   class="font-medium text-gray-900 dark:text-gray-100 truncate hover:text-indigo-600 dark:hover:text-indigo-400 transition">
                                    {{ $c->name }} ↗
                                </a>
                                @if($c->hero_title)
                                <p class="text-xs text-gray-500 truncate">{{ $c->hero_title }}</p>
                                @endif
                            </div>
                        </div>
                    </td>
                    <td class="px-4 py-3 font-mono text-xs text-gray-600 dark:text-gray-400">{{ $c->slug }}</td>
                    <td class="px-4 py-3 text-gray-700 dark:text-gray-300">{{ $c->admin?->name ?? '—' }}</td>
                    <td class="px-4 py-3 text-gray-700 dark:text-gray-300">{{ $c->users_count }}</td>
                    <td class="px-4 py-3 text-gray-700 dark:text-gray-300">{{ $c->services_count }}</td>
                    <td class="px-4 py-3">
                        <div class="flex flex-col gap-1">
                            @if($c->is_active)
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200">Active</span>
                            @else
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300">Inactive</span>
                            @endif
                            @if($c->is_public)
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200">Publique</span>
                            @else
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200">Privée</span>
                            @endif
                            @if($c->is_default)
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-purple-100 text-purple-800 dark:bg-purple-900 dark:text-purple-200">Défaut</span>
                            @endif
                        </div>
                    </td>
                    <td class="px-4 py-3">
                        <div class="flex flex-col items-start gap-1">
                            @if($c->loops_enabled)
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200">Activées</span>
                            @else
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300">Désactivées</span>
                            @endif
                            @if($c->isMonoLoop())
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-purple-100 text-purple-800 dark:bg-purple-900 dark:text-purple-200">Mono</span>
                            @else
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200">Multi</span>
                            @endif
                            <div class="max-w-[180px] text-xs text-gray-600 dark:text-gray-400">
                                <span class="text-gray-500 dark:text-gray-500">Principale :</span>
                                @if($c->primaryLoop)
                                    <a href="{{ route('admin.loops.edit', $c->primaryLoop) }}" class="font-medium text-indigo-600 dark:text-indigo-400 hover:underline">
                                        {{ $c->primaryLoop->name }}
                                    </a>
                                @else
                                    <span class="text-gray-400 dark:text-gray-500">— Aucune —</span>
                                @endif
                            </div>
                        </div>
                    </td>
                    <td class="px-4 py-3 text-xs text-gray-700 dark:text-gray-300">
                        <div class="truncate max-w-[140px]">{{ $c->platform_name }}</div>
                        @if($c->maintenance_mode)
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-amber-100 text-amber-800 dark:bg-amber-900 dark:text-amber-200">Maintenance</span>
                        @endif
                    </td>
                    <td class="px-4 py-3">
                        <div class="flex items-center gap-2">
                            <a href="{{ route('admin.organizations.edit', $c) }}" class="text-indigo-600 hover:text-indigo-800 text-xs font-medium">Éditer</a>
                            <form method="POST" action="{{ route('admin.organizations.toggle-active', $c) }}" class="inline">
                                @csrf
                                <button type="submit" class="text-xs font-medium {{ $c->is_active ? 'text-amber-600 hover:text-amber-800' : 'text-green-600 hover:text-green-800' }}">
                                    {{ $c->is_active ? 'Désactiver' : 'Activer' }}
                                </button>
                            </form>
                            <form method="POST" action="{{ route('admin.organizations.destroy', $c) }}" class="inline"
                                  onsubmit="return confirm('Supprimer cette organisation ? Les utilisateurs et services associés seront remis dans l\'organisation globale.');">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="text-xs font-medium text-red-600 hover:text-red-800">Supprimer</button>
                            </form>
                        </div>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="9" class="px-4 py-8 text-center text-gray-500 dark:text-gray-400">Aucune organisation pour le moment.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if($organizations->hasPages())
    <div class="mt-4">
        {{ $organizations->links() }}
    </div>
    @endif
</x-admin-layout>
