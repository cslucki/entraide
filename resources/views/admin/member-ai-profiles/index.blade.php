<x-admin-layout title="Agents profil IA">
    @php
        $statusLabels = [
            'draft' => 'Brouillon',
            'ready_for_generation' => 'Prêt génération',
            'generated' => 'Généré',
            'pending_validation' => 'En validation',
            'published' => 'Publié',
            'disabled' => 'Désactivé',
        ];
        $statusClasses = [
            'draft' => 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-200',
            'ready_for_generation' => 'bg-blue-100 text-blue-700 dark:bg-blue-900 dark:text-blue-200',
            'generated' => 'bg-indigo-100 text-indigo-700 dark:bg-indigo-900 dark:text-indigo-200',
            'pending_validation' => 'bg-amber-100 text-amber-800 dark:bg-amber-900 dark:text-amber-200',
            'published' => 'bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-200',
            'disabled' => 'bg-red-100 text-red-700 dark:bg-red-900 dark:text-red-200',
        ];
    @endphp

    <div class="max-w-7xl mx-auto space-y-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Agents profil IA</h2>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    Modérer, modifier, valider ou désactiver les agents IA publiés sur les fiches membres.
                </p>
            </div>
            <div class="text-sm text-gray-500 dark:text-gray-400">
                {{ $profiles->total() }} profil(s)
            </div>
        </div>

        <div class="grid grid-cols-2 gap-3 md:grid-cols-6">
            @foreach($statuses as $status)
                <a href="{{ route('admin.member-ai-profiles', ['status' => $status]) }}"
                   class="rounded-xl border border-gray-200 bg-white p-3 text-sm transition hover:border-indigo-300 hover:shadow-sm dark:border-gray-700 dark:bg-gray-800 dark:hover:border-indigo-600">
                    <div class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ $statusLabels[$status] ?? $status }}</div>
                    <div class="mt-1 text-xl font-semibold text-gray-900 dark:text-gray-100">{{ $statusCounts[$status] ?? 0 }}</div>
                </a>
            @endforeach
        </div>

        <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
            <form method="GET" action="{{ route('admin.member-ai-profiles') }}" class="flex flex-wrap items-end gap-3">
                <div class="min-w-[180px] flex-1">
                    <label for="status" class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400">Statut</label>
                    <select id="status" name="status" class="w-full rounded-lg border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
                        <option value="">Tous</option>
                        @foreach($statuses as $status)
                            <option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ $statusLabels[$status] ?? $status }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="min-w-[220px] flex-[2]">
                    <label for="search" class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400">Recherche</label>
                    <input id="search" type="text" name="search" value="{{ $filters['search'] ?? '' }}" placeholder="Membre, email, résumé..."
                           class="w-full rounded-lg border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
                </div>

                <div class="flex items-center gap-2">
                    <button type="submit" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-indigo-700">Filtrer</button>
                    <a href="{{ route('admin.member-ai-profiles') }}" class="rounded-lg bg-gray-200 px-4 py-2 text-sm font-medium text-gray-700 transition hover:bg-gray-300 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600">Réinit.</a>
                </div>
            </form>
        </div>

        <div class="overflow-hidden rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800">
            @if($profiles->count() === 0)
                <div class="p-8 text-center text-sm text-gray-500 dark:text-gray-400">Aucun agent profil IA trouvé.</div>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-gray-50 text-xs uppercase tracking-wide text-gray-500 dark:bg-gray-700 dark:text-gray-300">
                            <tr>
                                <th class="px-4 py-3">Membre</th>
                                <th class="px-4 py-3">Organisation</th>
                                <th class="px-4 py-3">Statut</th>
                                <th class="px-4 py-3">Résumé</th>
                                <th class="px-4 py-3">Mis à jour</th>
                                <th class="px-4 py-3 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                            @foreach($profiles as $profile)
                                <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/50">
                                    <td class="px-4 py-3">
                                        <div class="font-medium text-gray-900 dark:text-gray-100">{{ $profile->user?->name ?? 'Utilisateur supprimé' }}</div>
                                        <div class="text-xs text-gray-500 dark:text-gray-400">{{ $profile->user?->email ?? '—' }}</div>
                                    </td>
                                    <td class="px-4 py-3 text-gray-700 dark:text-gray-300">{{ $profile->organization?->name ?? '—' }}</td>
                                    <td class="px-4 py-3">
                                        <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium {{ $statusClasses[$profile->status] ?? $statusClasses['draft'] }}">
                                            {{ $statusLabels[$profile->status] ?? $profile->status }}
                                        </span>
                                    </td>
                                    <td class="max-w-md px-4 py-3 text-gray-700 dark:text-gray-300">
                                        {{ Str::limit($profile->member_profile_summary ?: $profile->service_scope ?: '—', 110) }}
                                    </td>
                                    <td class="px-4 py-3 whitespace-nowrap text-gray-500 dark:text-gray-400">
                                        {{ $profile->updated_at?->format('d/m/Y H:i') ?? '—' }}
                                    </td>
                                    <td class="px-4 py-3">
                                        <div class="flex items-center justify-end gap-2">
                                            @if($profile->user)
                                                <a href="{{ route('profile.show', $profile->user) }}" target="_blank" rel="noopener" class="text-xs font-medium text-gray-500 hover:text-indigo-600 dark:text-gray-400 dark:hover:text-indigo-300">Voir</a>
                                            @endif
                                            <a href="{{ route('admin.member-ai-profiles.edit', $profile) }}" class="text-xs font-medium text-indigo-600 hover:text-indigo-800 dark:text-indigo-400 dark:hover:text-indigo-300">Modifier</a>
                                            @if($profile->status !== 'published')
                                                <form method="POST" action="{{ route('admin.member-ai-profiles.publish', $profile) }}" class="inline">
                                                    @csrf
                                                    @method('PATCH')
                                                    <button type="submit" class="text-xs font-medium text-green-600 hover:text-green-800 dark:text-green-400 dark:hover:text-green-300">Publier</button>
                                                </form>
                                            @endif
                                            @if($profile->status !== 'disabled')
                                                <form method="POST" action="{{ route('admin.member-ai-profiles.disable', $profile) }}" class="inline">
                                                    @csrf
                                                    @method('PATCH')
                                                    <button type="submit" class="text-xs font-medium text-red-600 hover:text-red-800 dark:text-red-400 dark:hover:text-red-300">Désactiver</button>
                                                </form>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="border-t border-gray-200 px-4 py-3 dark:border-gray-700">
                    {{ $profiles->links() }}
                </div>
            @endif
        </div>
    </div>
</x-admin-layout>
