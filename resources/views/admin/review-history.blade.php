<x-admin-layout title="Historique de l'évaluation">
    <a href="{{ route('admin.reviews') }}" class="mb-4 inline-block text-sm text-indigo-600 hover:underline">&larr; Retour aux évaluations</a>

    <div class="mb-6 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-5">
        <h2 class="mb-3 text-sm font-semibold text-gray-900 dark:text-gray-100">État actuel</h2>
        <dl class="grid grid-cols-2 gap-3 text-sm md:grid-cols-4">
            <div>
                <dt class="text-xs text-gray-500 dark:text-gray-400">Aidé</dt>
                <dd class="text-gray-900 dark:text-gray-100">{{ $avis->reviewer?->full_name ?? '—' }}</dd>
            </div>
            <div>
                <dt class="text-xs text-gray-500 dark:text-gray-400">Aidant</dt>
                <dd class="text-gray-900 dark:text-gray-100">{{ $avis->reviewed?->full_name ?? '—' }}</dd>
            </div>
            <div>
                <dt class="text-xs text-gray-500 dark:text-gray-400">Note actuelle</dt>
                <dd class="font-medium text-indigo-600 dark:text-indigo-400">{{ $avis->rating }}/5</dd>
            </div>
            <div>
                <dt class="text-xs text-gray-500 dark:text-gray-400">Statut</dt>
                <dd>
                    @if($avis->trashed())
                    <span class="px-2 py-0.5 rounded text-xs bg-red-100 dark:bg-red-900/40 text-red-700 dark:text-red-300">Supprimée</span>
                    @elseif($avis->hidden_at)
                    <span class="px-2 py-0.5 rounded text-xs bg-yellow-100 dark:bg-yellow-900/40 text-yellow-700 dark:text-yellow-300">Masquée</span>
                    @else
                    <span class="px-2 py-0.5 rounded text-xs bg-green-100 dark:bg-green-900/40 text-green-700 dark:text-green-300">Visible</span>
                    @endif
                </dd>
            </div>
            <div class="col-span-2 md:col-span-4">
                <dt class="text-xs text-gray-500 dark:text-gray-400">Commentaire actuel</dt>
                <dd class="text-gray-900 dark:text-gray-100">{{ $avis->comment ?: '—' }}</dd>
            </div>
        </dl>
    </div>

    <h2 class="mb-3 text-sm font-semibold text-gray-900 dark:text-gray-100">Journal des interventions</h2>
    <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 dark:bg-gray-700">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Date</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Action</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Acteur</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Avant (note)</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Avant (commentaire)</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Motif</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                @forelse($journal as $entree)
                <tr>
                    <td class="px-4 py-3 text-xs text-gray-500">{{ $entree->created_at->format('d/m/Y H:i') }}</td>
                    <td class="px-4 py-3">
                        <span class="px-2 py-0.5 rounded text-xs bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300">
                            {{ match($entree->action) {
                                'updated' => 'Modifiée',
                                'hidden' => 'Masquée',
                                'unhidden' => 'Démasquée',
                                'deleted' => 'Supprimée',
                                'restored' => 'Restaurée',
                                default => $entree->action,
                            } }}
                        </span>
                    </td>
                    <td class="px-4 py-3 text-gray-900 dark:text-gray-100">{{ $entree->actor?->full_name ?? '—' }}</td>
                    <td class="px-4 py-3 text-gray-700 dark:text-gray-300">{{ $entree->original_rating ?? '—' }}</td>
                    <td class="px-4 py-3 max-w-xs truncate text-gray-700 dark:text-gray-300">{{ $entree->original_comment ?: '—' }}</td>
                    <td class="px-4 py-3 max-w-xs truncate text-gray-700 dark:text-gray-300">{{ $entree->reason ?: '—' }}</td>
                </tr>
                @empty
                <tr>
                    <td colspan="6" class="px-4 py-8 text-center text-sm text-gray-400">Aucune intervention enregistrée sur cette évaluation.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-admin-layout>
