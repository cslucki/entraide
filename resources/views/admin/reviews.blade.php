<x-admin-layout title="Évaluations">
    {{-- TASK-1808 §2 — meme patron que admin/transactions.blade.php : filtres
         GET, table, pagination. Rien de nouveau n'est invente ici. --}}
    <form method="GET" class="mb-5 flex flex-wrap gap-3">
        <input type="text" name="search" value="{{ request('search') }}" placeholder="Nom aidé ou aidant..."
            class="flex-1 min-w-48 px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-indigo-500">
        <select name="rating" class="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm">
            <option value="">Toutes les notes</option>
            @for($i = 1; $i <= 5; $i++)
            <option value="{{ $i }}" {{ request('rating') == $i ? 'selected' : '' }}>{{ $i }} étoile{{ $i > 1 ? 's' : '' }}</option>
            @endfor
        </select>
        <select name="visibility" class="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm">
            <option value="">Tous les statuts</option>
            <option value="visible" {{ request('visibility') === 'visible' ? 'selected' : '' }}>Visibles</option>
            <option value="hidden" {{ request('visibility') === 'hidden' ? 'selected' : '' }}>Masquées</option>
            <option value="deleted" {{ request('visibility') === 'deleted' ? 'selected' : '' }}>Supprimées</option>
        </select>
        <select name="organization_id" class="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm">
            <option value="all" {{ $selectedOrganizationId === 'all' ? 'selected' : '' }}>Toutes les organisations</option>
            @foreach($organizations as $org)
            <option value="{{ $org->id }}" {{ $selectedOrganizationId === $org->id ? 'selected' : '' }}>{{ $org->name }} {{ $org->is_default ? '(par défaut)' : '' }}</option>
            @endforeach
        </select>
        <button type="submit" class="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm hover:bg-indigo-700">Filtrer</button>
        @if(request()->hasAny(['search', 'rating', 'visibility', 'organization_id']))
        <a href="{{ route('admin.reviews') }}" class="px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg text-sm text-gray-600 dark:text-gray-400">Effacer</a>
        @endif
    </form>

    <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 dark:bg-gray-700">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Aidé</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Aidant</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide hidden md:table-cell">Organisation</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Note</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Commentaire</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Statut</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Date</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                @forelse($reviews as $avis)
                <tr class="hover:bg-gray-50 dark:hover:bg-gray-750" data-review-row="{{ $avis->id }}">
                    <td class="px-4 py-3">
                        <span class="text-gray-900 dark:text-gray-100">{{ $avis->reviewer?->full_name ?? '—' }}</span>
                    </td>
                    <td class="px-4 py-3">
                        <span class="text-gray-900 dark:text-gray-100">{{ $avis->reviewed?->full_name ?? '—' }}</span>
                    </td>
                    <td class="px-4 py-3 hidden md:table-cell">
                        <span class="text-xs text-gray-600 dark:text-gray-400">{{ $avis->organization?->name ?? '—' }}</span>
                    </td>
                    <td class="px-4 py-3">
                        <span class="font-medium text-indigo-600 dark:text-indigo-400">{{ $avis->rating }}/5</span>
                    </td>
                    <td class="px-4 py-3 max-w-xs">
                        <p class="truncate text-gray-700 dark:text-gray-300">{{ $avis->comment ?: '—' }}</p>
                    </td>
                    <td class="px-4 py-3">
                        @if($avis->trashed())
                        <span class="px-2 py-0.5 rounded text-xs bg-red-100 dark:bg-red-900/40 text-red-700 dark:text-red-300" data-review-statut="supprime">Supprimée</span>
                        @elseif($avis->hidden_at)
                        <span class="px-2 py-0.5 rounded text-xs bg-yellow-100 dark:bg-yellow-900/40 text-yellow-700 dark:text-yellow-300" data-review-statut="masque">Masquée</span>
                        @else
                        <span class="px-2 py-0.5 rounded text-xs bg-green-100 dark:bg-green-900/40 text-green-700 dark:text-green-300" data-review-statut="visible">Visible</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-xs text-gray-500">{{ $avis->created_at->format('d/m/Y H:i') }}</td>
                    <td class="px-4 py-3">
                        <div class="flex flex-wrap items-center gap-2">
                            <a href="{{ route('admin.reviews.history', $avis->id) }}" class="text-xs text-indigo-600 hover:underline" data-review-historique>Historique</a>

                            @unless($avis->trashed())
                                <button type="button" class="text-xs text-indigo-600 hover:underline"
                                        @click="$dispatch('open-review-edit', { id: '{{ $avis->id }}', rating: {{ $avis->rating }}, comment: @js($avis->comment ?? '') })"
                                        data-review-modifier>Modifier</button>

                                @if($avis->hidden_at)
                                <form method="POST" action="{{ route('admin.reviews.unhide', $avis->id) }}">
                                    @csrf @method('POST')
                                    <button class="text-xs text-indigo-600 hover:underline" data-review-demasquer>Démasquer</button>
                                </form>
                                @else
                                <form method="POST" action="{{ route('admin.reviews.hide', $avis->id) }}">
                                    @csrf @method('POST')
                                    <button class="text-xs text-yellow-700 dark:text-yellow-400 hover:underline" data-review-masquer>Masquer</button>
                                </form>
                                @endif

                                <form method="POST" action="{{ route('admin.reviews.destroy', $avis->id) }}"
                                      onsubmit="return confirm('Supprimer cette évaluation ?')">
                                    @csrf @method('DELETE')
                                    <button class="text-xs text-red-600 hover:underline" data-review-supprimer>Supprimer</button>
                                </form>
                            @else
                                <form method="POST" action="{{ route('admin.reviews.restore', $avis->id) }}">
                                    @csrf @method('POST')
                                    <button class="text-xs text-indigo-600 hover:underline" data-review-restaurer>Restaurer</button>
                                </form>
                            @endunless
                        </div>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="8" class="px-4 py-8 text-center text-sm text-gray-400">Aucune évaluation trouvée.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if($reviews->hasPages())
    <div class="mt-4">{{ $reviews->withQueryString()->links() }}</div>
    @endif

    {{-- Modale de modification, minimale : note + mot + motif. --}}
    <div x-data="{ open: false, id: null, rating: 0, comment: '' }"
         x-on:open-review-edit.window="open = true; id = $event.detail.id; rating = $event.detail.rating; comment = $event.detail.comment"
         x-show="open" x-cloak
         class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" data-review-edit-modal>
        <div class="w-full max-w-md rounded-xl bg-white dark:bg-gray-800 p-6" @click.outside="open = false">
            <h3 class="mb-4 text-sm font-semibold text-gray-900 dark:text-gray-100">Modifier l'évaluation</h3>
            <form method="POST" :action="`/admin/reviews/${id}`">
                @csrf @method('PATCH')
                <label class="mb-1 block text-xs font-medium text-gray-700 dark:text-gray-300">Note (1 à 5)</label>
                <input type="number" name="rating" min="1" max="5" x-model="rating"
                       class="mb-3 w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 px-3 py-2 text-sm text-gray-900 dark:text-gray-100">
                <label class="mb-1 block text-xs font-medium text-gray-700 dark:text-gray-300">Commentaire</label>
                <textarea name="comment" rows="3" x-model="comment"
                          class="mb-3 w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 px-3 py-2 text-sm text-gray-900 dark:text-gray-100"></textarea>
                <label class="mb-1 block text-xs font-medium text-gray-700 dark:text-gray-300">Motif (consigné au journal)</label>
                <textarea name="reason" rows="2"
                          class="mb-4 w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 px-3 py-2 text-sm text-gray-900 dark:text-gray-100"></textarea>
                <div class="flex justify-end gap-2">
                    <button type="button" @click="open = false" class="px-4 py-2 text-sm text-gray-600 dark:text-gray-400">Annuler</button>
                    <button type="submit" class="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm hover:bg-indigo-700">Enregistrer</button>
                </div>
            </form>
        </div>
    </div>
</x-admin-layout>
