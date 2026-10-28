<x-admin-layout :title="__('admin.roadmap_title')">
    {{-- TASK-1718 volet 2 — les taches de Roadmap de TOUTES les Organizations.

         Aucun second systeme de taches : `LoopRoadmapItem` est lu tel quel.
         L'ecran repond a trois besoins et pas un de plus — voir, filtrer,
         corriger. L'enrichissement (libelles, assignations, commentaires) reste
         dans la Boucle, ou il a son contexte. --}}

    @if(session('success'))
        <div class="mb-5 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700 dark:border-emerald-800 dark:bg-emerald-900/20 dark:text-emerald-300">
            {{ session('success') }}
        </div>
    @endif

    {{-- Filtres : Organization ET Boucle, independants l'un de l'autre. --}}
    <form method="GET" class="mb-5 flex flex-wrap items-end gap-3">
        <div>
            <label for="organization_id" class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">{{ __('admin.roadmap_filter_organization') }}</label>
            <select name="organization_id" id="organization_id" data-marqueur="filtre-organisation"
                    class="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm">
                <option value="">{{ __('admin.roadmap_all_organizations') }}</option>
                @foreach($organizations as $org)
                    <option value="{{ $org->id }}" {{ $organizationId === $org->id ? 'selected' : '' }}>{{ $org->name }}</option>
                @endforeach
            </select>
        </div>

        <div>
            <label for="loop_id" class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">{{ __('admin.roadmap_filter_loop') }}</label>
            <select name="loop_id" id="loop_id" data-marqueur="filtre-boucle"
                    class="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm">
                <option value="">{{ __('admin.roadmap_all_loops') }}</option>
                @foreach($loops as $boucle)
                    <option value="{{ $boucle->id }}" {{ $loopId === $boucle->id ? 'selected' : '' }}>{{ $boucle->name }}</option>
                @endforeach
            </select>
        </div>

        <button type="submit" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold rounded-lg transition">{{ __('admin.roadmap_filter') }}</button>
        <a href="{{ route('admin.roadmap') }}" class="px-4 py-2 text-sm text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200">{{ __('admin.roadmap_clear') }}</a>
    </form>

    <p class="mb-3 text-sm text-gray-500 dark:text-gray-400" data-marqueur="compte-taches">
        {{ trans_choice('admin.roadmap_count', $items->total(), ['count' => $items->total()]) }}
    </p>

    <div class="overflow-x-auto rounded-xl border border-gray-200 dark:border-gray-700">
        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 bg-white dark:bg-gray-800">
            <thead class="bg-gray-50 dark:bg-gray-900/40">
                <tr class="text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    <th class="px-4 py-3">{{ __('admin.roadmap_col_task') }}</th>
                    <th class="px-4 py-3">{{ __('admin.roadmap_col_loop') }}</th>
                    <th class="px-4 py-3">{{ __('admin.roadmap_col_organization') }}</th>
                    <th class="px-4 py-3">{{ __('admin.roadmap_col_author') }}</th>
                    <th class="px-4 py-3">{{ __('admin.roadmap_col_actions') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                @forelse($items as $item)
                    <tr data-roadmap-row="{{ $item->id }}" class="align-top">
                        {{-- Le titre et le statut s'editent SUR PLACE : ouvrir une
                             page par tache pour changer un mot couterait un aller
                             et un retour a chaque correction. --}}
                        <td class="px-4 py-3">
                            <form method="POST" action="{{ route('admin.roadmap.update', $item->id) }}" class="flex flex-wrap items-center gap-2">
                                @csrf @method('PUT')
                                <input type="text" name="title" value="{{ $item->title }}" maxlength="255" required
                                       data-roadmap-title="{{ $item->id }}"
                                       class="min-w-[18rem] flex-1 px-2 py-1 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 text-sm">
                                <select name="status" class="px-2 py-1 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 text-xs">
                                    @foreach($statuses as $st)
                                        <option value="{{ $st }}" {{ $item->status === $st ? 'selected' : '' }}>{{ __('loops.roadmap_status_'.$st) }}</option>
                                    @endforeach
                                </select>
                                <button class="text-xs font-semibold text-indigo-600 hover:underline whitespace-nowrap" data-roadmap-save="{{ $item->id }}">{{ __('admin.roadmap_save') }}</button>
                            </form>
                        </td>
                        <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-300">{{ $item->loop?->name ?? '—' }}</td>
                        <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-300">{{ $item->loop?->organization?->name ?? '—' }}</td>
                        <td class="px-4 py-3 text-sm text-gray-500 dark:text-gray-400">{{ $item->creator?->publicDisplayName() ?? '—' }}</td>
                        <td class="px-4 py-3">
                            <form method="POST" action="{{ route('admin.roadmap.destroy', $item->id) }}"
                                  onsubmit="return confirm(@js(__('admin.roadmap_delete_confirm')))">
                                @csrf @method('DELETE')
                                <button class="text-xs font-medium text-red-600 hover:underline whitespace-nowrap" data-roadmap-delete="{{ $item->id }}">{{ __('admin.roadmap_delete') }}</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" data-marqueur="roadmap-vide" class="px-4 py-8 text-center text-sm text-gray-400">{{ __('admin.roadmap_empty') }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $items->links() }}</div>
</x-admin-layout>
