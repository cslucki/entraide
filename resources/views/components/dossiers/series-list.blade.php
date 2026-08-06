{{--
    Une Serie entiere : son nom, et ses contenus numerotes dans l'ordre.

    Le classement se fait de **deux** manieres, et les deux sont de premiere
    classe : le glissement a la souris, et les boutons « Monter » / «
    Descendre » au clavier. Un classement qui n'existe qu'au glissement est
    inaccessible a qui navigue au clavier, et impraticable sur un ecran
    tactile etroit — la ou ces Series seront surtout lues.

    Les deux chemins appellent la meme route et envoient la meme chose : la
    liste complete des items dans l'ordre voulu. Il n'y a donc pas deux
    mecaniques de reordonnancement a garder d'accord.

    Ecrit une fois pour Dossiers, l'Article et le Support de cours.
--}}
@props([
    'series',
    'canManage' => false,
    'organizationParam' => null,
    'dossierId' => null,
])

@php
    $contents = $series->numberedContents();
    // Seuls les items sont deplacables : la racine est le numero 1 par
    // definition, elle ne se classe pas, elle se remplace.
    $movable = $contents->where('type', '!=', 'root')->values();
@endphp

<section
    class="rounded-2xl border border-gray-200 bg-gray-50/60 p-4 dark:border-gray-700 dark:bg-gray-900/40"
    aria-labelledby="series-{{ $series->id }}-title"
    @if ($canManage)
        x-data="dossierSeriesReorder({
            seriesId: '{{ $series->id }}',
            dossierId: '{{ $dossierId }}',
            orgParam: '{{ $organizationParam }}',
            itemIds: @js($movable->pluck('item.id')->all()),
            csrfToken: '{{ csrf_token() }}',
            i18n: @js([
                'reorderFailed' => __('dossiers.series_reorder_failed'),
            ]),
        })"
    @endif
>
    <header class="mb-3 flex items-center justify-between gap-3">
        <h3 id="series-{{ $series->id }}-title" class="truncate text-sm font-semibold text-gray-900 dark:text-gray-100">
            {{ $series->displayName() ?: __('dossiers.series_untitled') }}
        </h3>
        <span class="shrink-0 text-xs text-gray-500 dark:text-gray-400">
            {{ trans_choice('dossiers.series_count_items', $contents->count(), ['count' => $contents->count()]) }}
        </span>
    </header>

    @if ($contents->isEmpty())
        <p class="px-3 py-6 text-center text-sm text-gray-500 dark:text-gray-400">
            {{ __('dossiers.series_annexes_empty') }}
        </p>
    @else
        <ol class="space-y-2" @if ($canManage) data-series-zone="{{ $series->id }}" @endif>
            @foreach ($contents as $entry)
                @php $itemId = $entry['item']?->id; @endphp
                <li @if ($itemId) data-item-id="{{ $itemId }}" @endif>
                    <x-dossiers.series-item
                        :number="$entry['number']"
                        :type="$entry['type']"
                        :name="$entry['name']"
                        :draggable="$canManage && $itemId !== null"
                    >
                        @if ($canManage && $itemId)
                            @php $rangDeplacable = $movable->search(fn ($m) => $m['item']->id === $itemId); @endphp
                            <div class="flex shrink-0 items-center gap-1">
                                <button
                                    type="button"
                                    @click="move('{{ $itemId }}', -1)"
                                    @disabled($rangDeplacable === 0)
                                    class="rounded-lg p-1.5 text-gray-400 transition-colors hover:bg-gray-100 hover:text-gray-700 disabled:cursor-not-allowed disabled:opacity-30 dark:hover:bg-gray-700 dark:hover:text-gray-200"
                                    aria-label="{{ __('dossiers.series_move_up_label', ['name' => $entry['name']]) }}"
                                >
                                    <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 15.75l7.5-7.5 7.5 7.5"/></svg>
                                </button>
                                <button
                                    type="button"
                                    @click="move('{{ $itemId }}', 1)"
                                    @disabled($rangDeplacable === $movable->count() - 1)
                                    class="rounded-lg p-1.5 text-gray-400 transition-colors hover:bg-gray-100 hover:text-gray-700 disabled:cursor-not-allowed disabled:opacity-30 dark:hover:bg-gray-700 dark:hover:text-gray-200"
                                    aria-label="{{ __('dossiers.series_move_down_label', ['name' => $entry['name']]) }}"
                                >
                                    <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5"/></svg>
                                </button>
                            </div>
                        @endif
                    </x-dossiers.series-item>
                </li>
            @endforeach
        </ol>
    @endif

    @if ($canManage)
        {{-- Une region polie : le nouvel ordre est annonce sans interrompre. --}}
        <p class="sr-only" role="status" aria-live="polite" x-text="announcement"></p>
    @endif
</section>
