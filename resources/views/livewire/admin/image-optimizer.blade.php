{{-- TASK-1873 (phase 2) — console d'optimisation des images.

     Mobile-first et sobre : quatre chiffres, un formulaire, la carte du lot
     en cours (composant enfant, seul a se rafraichir), un historique court.
     Les details techniques (chemins, erreurs, detail par type) sont replies. --}}
@php
    $size = fn (?int $bytes) => $bytes === null ? '—' : \Illuminate\Support\Number::fileSize($bytes, precision: 1);
    $label = fn (string $surface) => __('admin.image_optimizer.surface_'.$surface);
    $card = 'rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800';
    $saving = $totals['source_bytes'] > 0 ? (int) round(100 * (1 - $totals['variant_bytes'] / $totals['source_bytes'])) : null;
@endphp
<div>
    <x-slot:title>{{ __('admin.image_optimizer.title') }}</x-slot:title>

    <p class="mb-5 text-sm text-gray-500 dark:text-gray-400">{{ __('admin.image_optimizer.subtitle') }}</p>

    {{-- Synthese : quatre chiffres au plus. --}}
    <section class="mb-2 grid grid-cols-2 gap-3 lg:grid-cols-4" data-marqueur="synthese">
        @foreach([
            ['kpi_total', number_format($totals['total'], 0, ',', ' '), null],
            ['kpi_ready', number_format($totals['ready'], 0, ',', ' '), null],
            ['kpi_pending', number_format($totals['pending'], 0, ',', ' '), null],
            ['kpi_gain', $saving !== null ? $saving.' %' : __('admin.image_optimizer.kpi_none'),
                $saving !== null ? __('admin.image_optimizer.kpi_gain_detail', ['before' => $size($totals['source_bytes']), 'after' => $size($totals['variant_bytes'])]) : null],
        ] as [$key, $value, $hint])
            <div class="{{ $card }} p-4">
                <p class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('admin.image_optimizer.'.$key) }}</p>
                <p class="mt-1 text-2xl font-semibold text-gray-900 dark:text-gray-100">{{ $value }}</p>
                @if($hint)<p class="mt-0.5 text-xs text-gray-400 dark:text-gray-500">{{ $hint }}</p>@endif
            </div>
        @endforeach
    </section>
    <p class="mb-6 text-xs text-gray-500 dark:text-gray-400" data-marqueur="absents-et-echecs">
        {{ trans_choice('admin.image_optimizer.missing_count', $totals['missing'], ['count' => $totals['missing']]) }}
        · {{ trans_choice('admin.image_optimizer.failed_count', $totals['failed'], ['count' => $totals['failed']]) }}
    </p>

    {{-- Lot en cours ou tout juste termine. --}}
    @if($current)
        <div class="mb-6">
            <livewire:admin.image-batch-progress :batch-id="$current->id" :key="'image-batch-'.$current->id" />
        </div>
    @endif

    {{-- Lancer un traitement. --}}
    <section class="mb-6 {{ $card }} p-4 sm:p-5" data-marqueur="lancer">
        <h2 class="mb-4 text-base font-semibold text-gray-900 dark:text-gray-100">{{ __('admin.image_optimizer.launch_title') }}</h2>

        <div class="grid gap-4 sm:grid-cols-2">
            <label class="block text-sm text-gray-700 dark:text-gray-300">
                {{ __('admin.image_optimizer.organization') }}
                <select wire:model.live="organizationId" class="mt-1 block w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                    <option value="">{{ __('admin.image_optimizer.all_organizations') }}</option>
                    @foreach($organizations as $organization)
                        <option value="{{ $organization->id }}">{{ $organization->name }}</option>
                    @endforeach
                </select>
            </label>
            <label class="block text-sm text-gray-700 dark:text-gray-300">
                {{ __('admin.image_optimizer.surface') }}
                <select wire:model.live="surface" class="mt-1 block w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                    <option value="">{{ __('admin.image_optimizer.all_surfaces') }}</option>
                    @foreach($surfaces as $value)
                        <option value="{{ $value }}">{{ $label($value) }}</option>
                    @endforeach
                </select>
            </label>
        </div>

        <fieldset class="mt-4">
            <legend class="text-sm text-gray-700 dark:text-gray-300">{{ __('admin.image_optimizer.limit') }}</legend>
            <div class="mt-1 grid grid-cols-4 gap-2 sm:inline-grid sm:w-80">
                @foreach($limits as $value)
                    <label @class([
                        'cursor-pointer rounded-lg border px-3 py-2 text-center text-sm font-semibold transition',
                        'border-indigo-600 bg-indigo-50 text-indigo-700 dark:border-indigo-400 dark:bg-indigo-900/40 dark:text-indigo-200' => $limit === $value,
                        'border-gray-300 text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-300 dark:hover:bg-gray-700' => $limit !== $value,
                    ])>
                        <input type="radio" wire:model.live="limit" value="{{ $value }}" class="sr-only">{{ $value }}
                    </label>
                @endforeach
            </div>
        </fieldset>

        @if($simulation !== null)
            <div class="mt-4 rounded-lg bg-gray-50 p-3 text-sm dark:bg-gray-900/50" data-marqueur="simulation">
                <p class="text-gray-700 dark:text-gray-300">
                    {{ trans_choice('admin.image_optimizer.simulation_result', $simulation['count'], ['count' => $simulation['count'], 'weight' => $size($simulation['bytes'])]) }}
                </p>
                @if($simulation['items'])
                    <details class="mt-2">
                        <summary class="cursor-pointer text-xs text-gray-500 dark:text-gray-400">{{ __('admin.image_optimizer.simulation_list') }}</summary>
                        <ul class="mt-2 space-y-1 text-xs">
                            @foreach($simulation['items'] as $item)
                                <li class="flex justify-between gap-2">
                                    <span class="min-w-0 break-all font-mono text-gray-500 dark:text-gray-400">{{ $item['path'] }}</span>
                                    <span class="shrink-0 font-mono {{ $item['bytes'] === null ? 'text-red-600' : 'text-gray-500' }}">{{ $item['bytes'] === null ? __('admin.image_optimizer.absent') : $size($item['bytes']) }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </details>
                @endif
            </div>
        @endif

        @if($notice)
            <p class="mt-4 rounded-lg bg-green-50 p-3 text-sm text-green-800 dark:bg-green-900/30 dark:text-green-200">{{ $notice }}</p>
        @endif
        @error('launch')
            <p class="mt-4 rounded-lg bg-amber-50 p-3 text-sm text-amber-800 dark:bg-amber-900/30 dark:text-amber-200">{{ $message }}</p>
        @enderror

        <label class="mt-4 flex items-start gap-2 text-sm text-gray-700 dark:text-gray-300">
            <input type="checkbox" wire:model="confirmed" class="mt-0.5 rounded border-gray-300 text-indigo-600 dark:border-gray-600">
            <span>{{ __('admin.image_optimizer.confirm', ['count' => $limit]) }}</span>
        </label>
        @error('confirmed')<p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>@enderror

        <div class="mt-4 flex flex-col gap-2 sm:flex-row">
            <button type="button" wire:click="simulate" wire:loading.attr="disabled"
                    class="rounded-lg border border-gray-300 px-4 py-2.5 text-sm font-semibold text-gray-700 hover:bg-gray-50 disabled:opacity-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">
                {{ __('admin.image_optimizer.simulate') }}
            </button>
            <button type="button" wire:click="optimize" wire:loading.attr="disabled" data-marqueur="optimiser"
                    class="rounded-lg bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-indigo-700 disabled:opacity-50">
                {{ __('admin.image_optimizer.optimize') }}
            </button>
        </div>
    </section>

    {{-- Historique court. --}}
    <section class="mb-6 {{ $card }} p-4 sm:p-5" data-marqueur="historique">
        <h2 class="mb-3 text-base font-semibold text-gray-900 dark:text-gray-100">{{ __('admin.image_optimizer.history_title') }}</h2>
        @if($history->isEmpty())
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('admin.image_optimizer.history_none') }}</p>
        @else
            <ul class="divide-y divide-gray-100 text-sm dark:divide-gray-700">
                @foreach($history as $entry)
                    @php($b = $entry['batch'])
                    @php($p = $entry['progress'])
                    <li class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 py-2">
                        <span class="text-gray-800 dark:text-gray-200">
                            {{ $b->created_at->translatedFormat('d/m H:i') }}
                            · {{ $b->surface ? $label($b->surface) : __('admin.image_optimizer.all_surfaces') }}
                            · {{ $b->organization?->name ?? __('admin.image_optimizer.all_organizations') }}
                        </span>
                        <span class="text-xs text-gray-500 dark:text-gray-400">
                            {{ __('admin.image_optimizer.history_line', ['done' => $p['done'], 'total' => $p['total']]) }}
                            · {{ __('admin.image_optimizer.status_'.$b->status) }}
                            @if($p['served_source_bytes'] > 0)
                                · −{{ (int) round(100 * (1 - $p['served_bytes'] / $p['served_source_bytes'])) }} %
                            @endif
                        </span>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    {{-- Detail technique, replie. --}}
    <details class="{{ $card }} p-4 sm:p-5" data-marqueur="detail-par-type">
        <summary class="cursor-pointer text-sm font-semibold text-gray-700 dark:text-gray-200">{{ __('admin.image_optimizer.by_type') }}</summary>
        <div class="mt-3 overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        <th class="py-2 pr-3">{{ __('admin.image_optimizer.col_surface') }}</th>
                        <th class="py-2 pr-3 text-right">{{ __('admin.image_optimizer.col_total') }}</th>
                        <th class="py-2 pr-3 text-right">{{ __('admin.image_optimizer.col_ready') }}</th>
                        <th class="py-2 pr-3 text-right">{{ __('admin.image_optimizer.col_pending') }}</th>
                        <th class="py-2 pr-3 text-right">{{ __('admin.image_optimizer.col_missing') }}</th>
                        <th class="py-2 text-right">{{ __('admin.image_optimizer.col_failed') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 font-mono dark:divide-gray-700">
                    @foreach($summary as $surfaceKey => $line)
                        <tr>
                            <td class="py-2 pr-3 font-sans text-gray-800 dark:text-gray-200">{{ $label($surfaceKey) }}</td>
                            <td class="py-2 pr-3 text-right">{{ $line['total'] }}</td>
                            <td class="py-2 pr-3 text-right">{{ $line['ready'] }}</td>
                            <td class="py-2 pr-3 text-right">{{ $line['pending'] }}</td>
                            <td class="py-2 pr-3 text-right">{{ $line['missing'] }}</td>
                            <td class="py-2 text-right">{{ $line['failed'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <p class="mt-3 font-mono text-xs text-gray-400 dark:text-gray-500">{{ __('admin.image_optimizer.cli_hint') }}</p>
    </details>
</div>
