<x-admin-layout title="File de modération IA">
    <div class="max-w-7xl mx-auto space-y-6">

        {{-- Header --}}
        <div class="flex items-start justify-between gap-4">
            <div>
                <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">
                    File de modération IA
                </h2>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                    Contenus flaggés par l'IA en attente de validation humaine.
                </p>
            </div>
            <div class="text-sm text-gray-500 dark:text-gray-400">
                {{ $interactions->total() }} élément(s) en attente
            </div>
        </div>

        {{-- Table --}}
        <div class="bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 overflow-hidden">
            @if($interactions->count() === 0)
                <div class="p-8 text-center text-gray-500 dark:text-gray-400">
                    <svg class="w-12 h-12 mx-auto text-gray-400 mb-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                    </svg>
                    <p class="text-sm">Aucun contenu en attente de modération.</p>
                    <p class="text-xs mt-1">Tous les contenus flaggés par l'IA ont été traités.</p>
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm text-left">
                        <thead class="bg-gray-50 dark:bg-gray-700 text-gray-500 dark:text-gray-300 uppercase text-xs">
                            <tr>
                                <th class="px-4 py-3">Date</th>
                                <th class="px-4 py-3">Scénario</th>
                                <th class="px-4 py-3">Provider</th>
                                <th class="px-4 py-3">Utilisateur</th>
                                <th class="px-4 py-3">Extrait</th>
                                <th class="px-4 py-3">Statut</th>
                                <th class="px-4 py-3">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                            @foreach($interactions as $interaction)
                            <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/50 transition">
                                <td class="px-4 py-3 whitespace-nowrap">
                                    <a href="{{ route('admin.ai-interactions.show', $interaction) }}" class="text-indigo-600 dark:text-indigo-400 hover:underline">
                                        {{ $interaction->created_at->format('d/m/Y H:i') }}
                                    </a>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="inline-block px-2 py-0.5 rounded text-xs font-medium bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300">
                                        {{ $interaction->scenario_id }}
                                    </span>
                                </td>
                                <td class="px-4 py-3">{{ $interaction->provider ?? '—' }}</td>
                                <td class="px-4 py-3">{{ $interaction->user?->name ?? '—' }}</td>
                                <td class="px-4 py-3 max-w-xs truncate" title="{{ $interaction->input_excerpt }}">
                                    {{ Str::limit($interaction->input_excerpt, 60) }}
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap">
                                    @php
                                        $payload = $interaction->result_payload;
                                        $flags = [];
                                        if (($payload['moderation_flag'] ?? false)) $flags[] = 'Modéré';
                                        if (($payload['risk_level'] ?? 'low') === 'high') $flags[] = 'Risque élevé';
                                        if (($payload['needs_human_category_review'] ?? false)) $flags[] = 'Catégorie incertaine';
                                    @endphp
                                    <div class="flex flex-wrap gap-1">
                                        @foreach($flags as $flag)
                                            <span class="inline-block px-2 py-0.5 rounded text-xs font-medium bg-yellow-100 dark:bg-yellow-900 text-yellow-700 dark:text-yellow-300">
                                                {{ $flag }}
                                            </span>
                                        @endforeach
                                    </div>
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap">
                                    <div class="flex flex-col gap-2">
                                        {{-- Approve form --}}
                                        <form method="POST" action="{{ route('admin.ai-review-queue.update', $interaction->id) }}" id="approve-{{ $interaction->id }}">
                                            @csrf
                                            @method('PATCH')
                                            <input type="hidden" name="review_status" value="approved">
                                            <input type="hidden" name="review_notes" value="">
                                            <button type="submit" class="px-3 py-1.5 bg-green-600 text-white text-xs font-medium rounded-lg hover:bg-green-700 transition">
                                                Approuver
                                            </button>
                                        </form>

                                        {{-- Reject form with notes --}}
                                        <form method="POST" action="{{ route('admin.ai-review-queue.update', $interaction->id) }}" id="reject-{{ $interaction->id }}">
                                            @csrf
                                            @method('PATCH')
                                            <input type="hidden" name="review_status" value="rejected">
                                            <textarea name="review_notes" rows="1" placeholder="Notes (optionnel)" class="w-full text-xs border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded focus:ring-indigo-500 focus:border-indigo-500 mb-1"></textarea>
                                            <button type="submit" class="px-3 py-1.5 bg-red-600 text-white text-xs font-medium rounded-lg hover:bg-red-700 transition">
                                                Rejeter
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="px-4 py-3 border-t border-gray-200 dark:border-gray-700">
                    {{ $interactions->links() }}
                </div>
            @endif
        </div>
    </div>
</x-admin-layout>
