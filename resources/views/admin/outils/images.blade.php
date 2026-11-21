{{-- TASK-1873 — console d'optimisation des images (SuperAdmin).

     Sobre, sur le modele des autres cockpits : un etat en tete (pause, file),
     un tableau par type d'image, puis les deux gestes — simuler (GET, aucune
     ecriture) et lancer un lot (POST, borne, confirme, MIS EN FILE). Le meme
     inventaire et le meme executeur que `php artisan images:optimize`. --}}
@php
    $size = fn (?int $bytes) => $bytes === null ? '—' : \Illuminate\Support\Number::fileSize($bytes, precision: 1);
    $gain = fn (int $source, int $served) => $source > 0 ? round(100 * (1 - $served / $source)).' %' : '—';
    $label = fn (string $surface) => __('admin.image_optimizer.surface_'.$surface);
    $th = 'px-3 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400';
    $thR = 'px-3 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400';
    $card = 'rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800';
@endphp
<x-admin-layout :title="__('admin.image_optimizer.title')">
    <div class="mb-6">
        <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('admin.image_optimizer.subtitle') }}</p>
        <p class="mt-1 font-mono text-xs text-gray-400 dark:text-gray-500">{{ __('admin.image_optimizer.cli_hint') }}</p>
    </div>

    {{-- Etat : pause et file d'attente, avant tout le reste. --}}
    <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between {{ $card }} p-4" data-marqueur="etat-rattrapage">
        <div class="space-y-1 text-sm">
            <span @class([
                'inline-flex items-center rounded-full px-3 py-1 text-sm font-semibold',
                'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300' => $paused,
                'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-300' => ! $paused,
            ])>
                {{ $paused ? __('admin.image_optimizer.status_paused') : __('admin.image_optimizer.status_active') }}
            </span>
            <p class="text-xs text-gray-500 dark:text-gray-400">
                {{ __('admin.image_optimizer.queue_label') }} « {{ $queue['connection'] }} » —
                @if($queue['pending'] !== null)
                    {{ __('admin.image_optimizer.queue_pending', ['count' => $queue['pending']]) }}
                @else
                    {{ __('admin.image_optimizer.queue_unknown') }}
                @endif
            </p>
            @if($queue['connection'] === 'sync')
                <p class="text-xs text-amber-700 dark:text-amber-300">{{ __('admin.image_optimizer.queue_sync', ['max' => $maxBatch]) }}</p>
            @endif
        </div>

        <form method="POST" action="{{ $paused ? route('admin.images.resume') : route('admin.images.pause') }}">
            @csrf
            <button type="submit" class="inline-flex items-center rounded-lg border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 transition hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">
                {{ $paused ? __('admin.image_optimizer.resume') : __('admin.image_optimizer.pause') }}
            </button>
        </form>
    </div>

    {{-- Filtres (GET) : partages par le tableau, la simulation et le lot. --}}
    <form method="GET" action="{{ route('admin.images') }}" class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-end">
        <label class="block text-xs font-medium text-gray-600 dark:text-gray-300">
            {{ __('admin.image_optimizer.col_surface') }}
            <select name="surface" class="mt-1 block w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 sm:w-56">
                <option value="">{{ __('admin.image_optimizer.all_surfaces') }}</option>
                @foreach($surfaces as $surface)
                    <option value="{{ $surface }}" @selected($filters['surface'] === $surface)>{{ $label($surface) }}</option>
                @endforeach
            </select>
        </label>
        <label class="block text-xs font-medium text-gray-600 dark:text-gray-300">
            {{ __('admin.image_optimizer.col_organization') }}
            <select name="organization_id" class="mt-1 block w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 sm:w-64">
                <option value="">{{ __('admin.image_optimizer.all_organizations') }}</option>
                @foreach($organizations as $organization)
                    <option value="{{ $organization->id }}" @selected($filters['organization_id'] === $organization->id)>{{ $organization->name }}</option>
                @endforeach
            </select>
        </label>
        <div class="flex gap-2">
            <button type="submit" class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">
                {{ __('admin.image_optimizer.apply') }}
            </button>
            <button type="submit" name="simulate" value="1" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">
                {{ __('admin.image_optimizer.simulate') }}
            </button>
        </div>
    </form>

    {{-- Volumetrie par type. --}}
    <section class="mb-6">
        <div class="{{ $card }} overflow-x-auto">
            <table class="w-full text-sm" data-marqueur="volumetrie">
                <thead class="bg-gray-50 dark:bg-gray-700">
                    <tr>
                        <th class="{{ $th }}">{{ __('admin.image_optimizer.col_surface') }}</th>
                        <th class="{{ $thR }}">{{ __('admin.image_optimizer.col_total') }}</th>
                        <th class="{{ $thR }}">{{ __('admin.image_optimizer.col_pending') }}</th>
                        <th class="{{ $thR }}">{{ __('admin.image_optimizer.col_ready') }}</th>
                        <th class="{{ $thR }}">{{ __('admin.image_optimizer.col_skipped') }}</th>
                        <th class="{{ $thR }}">{{ __('admin.image_optimizer.col_failed') }}</th>
                        <th class="{{ $thR }}">{{ __('admin.image_optimizer.col_missing') }}</th>
                        <th class="{{ $thR }}">{{ __('admin.image_optimizer.col_original') }}</th>
                        <th class="{{ $thR }}">{{ __('admin.image_optimizer.col_served') }}</th>
                        <th class="{{ $thR }}">{{ __('admin.image_optimizer.col_gain') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 font-mono dark:divide-gray-700">
                    @foreach($summary as $surface => $line)
                        <tr @class(['opacity-50' => $filters['surface'] && $filters['surface'] !== $surface])>
                            <td class="px-3 py-2 font-sans font-medium text-gray-900 dark:text-gray-100">{{ $label($surface) }}</td>
                            <td class="px-3 py-2 text-right">{{ $line['total'] }}</td>
                            <td class="px-3 py-2 text-right {{ $line['pending'] > 0 ? 'font-semibold text-indigo-700 dark:text-indigo-300' : '' }}">{{ $line['pending'] }}</td>
                            <td class="px-3 py-2 text-right">{{ $line['ready'] }}</td>
                            <td class="px-3 py-2 text-right">{{ $line['skipped'] }}</td>
                            <td class="px-3 py-2 text-right {{ $line['failed'] > 0 ? 'text-red-600 dark:text-red-400' : '' }}">{{ $line['failed'] }}</td>
                            <td class="px-3 py-2 text-right">{{ $line['missing'] }}</td>
                            <td class="px-3 py-2 text-right">{{ $size($line['source_bytes']) }}</td>
                            <td class="px-3 py-2 text-right">{{ $size($line['variant_bytes']) }}</td>
                            <td class="px-3 py-2 text-right">{{ $gain($line['source_bytes'], $line['variant_bytes']) }}</td>
                        </tr>
                    @endforeach
                    <tr class="bg-gray-50 font-semibold dark:bg-gray-700/50">
                        <td class="px-3 py-2 font-sans">{{ __('admin.image_optimizer.total') }}</td>
                        <td class="px-3 py-2 text-right">{{ $totals['total'] ?? 0 }}</td>
                        <td class="px-3 py-2 text-right">{{ $totals['pending'] ?? 0 }}</td>
                        <td class="px-3 py-2 text-right">{{ $totals['ready'] ?? 0 }}</td>
                        <td class="px-3 py-2 text-right">{{ $totals['skipped'] ?? 0 }}</td>
                        <td class="px-3 py-2 text-right">{{ $totals['failed'] ?? 0 }}</td>
                        <td class="px-3 py-2 text-right">{{ $totals['missing'] ?? 0 }}</td>
                        <td class="px-3 py-2 text-right">{{ $size($totals['source_bytes'] ?? 0) }}</td>
                        <td class="px-3 py-2 text-right">{{ $size($totals['variant_bytes'] ?? 0) }}</td>
                        <td class="px-3 py-2 text-right">{{ $gain($totals['source_bytes'] ?? 0, $totals['variant_bytes'] ?? 0) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <p class="mt-2 text-xs text-gray-400 dark:text-gray-500">{{ __('admin.image_optimizer.weights_hint') }}</p>
    </section>

    {{-- Simulation : lecture seule. --}}
    @if($simulation !== null)
        <section class="mb-6 {{ $card }} p-4" data-marqueur="simulation">
            <h2 class="mb-3 text-sm font-semibold text-gray-700 dark:text-gray-200">{{ __('admin.image_optimizer.simulation_title') }}</h2>
            @if($simulation->isEmpty())
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('admin.image_optimizer.simulation_empty') }}</p>
            @else
                <ul class="divide-y divide-gray-100 text-sm dark:divide-gray-700">
                    @foreach($simulation as $item)
                        <li class="flex flex-wrap items-baseline justify-between gap-2 py-2">
                            <span class="min-w-0 break-all font-mono text-xs text-gray-700 dark:text-gray-300">
                                <span class="font-sans font-medium text-gray-500">{{ $label($item['source']->surface) }}</span> · {{ $item['source']->path }}
                            </span>
                            <span class="shrink-0 font-mono text-xs {{ $item['bytes'] === null ? 'text-red-600' : 'text-gray-500' }}">
                                {{ $item['bytes'] === null ? __('admin.image_optimizer.simulation_absent') : $size($item['bytes']) }}
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    @endif

    {{-- Lancer un lot : borne, confirme, mis en file. --}}
    <section class="mb-6 {{ $card }} p-4" data-marqueur="lancer-un-lot">
        <h2 class="text-sm font-semibold text-gray-700 dark:text-gray-200">{{ __('admin.image_optimizer.launch_title') }}</h2>
        <p class="mb-3 mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('admin.image_optimizer.launch_hint') }}</p>

        @if($errors->any())
            <div class="mb-3 rounded-lg bg-red-50 p-3 text-sm text-red-700 dark:bg-red-900/30 dark:text-red-300">
                @foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach
            </div>
        @endif

        <form method="POST" action="{{ route('admin.images.launch') }}" class="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-end">
            @csrf
            <input type="hidden" name="surface" value="{{ $filters['surface'] }}">
            <input type="hidden" name="organization_id" value="{{ $filters['organization_id'] }}">
            <label class="block text-xs font-medium text-gray-600 dark:text-gray-300">
                {{ __('admin.image_optimizer.limit', ['max' => $maxBatch]) }}
                <input type="number" name="limit" min="1" max="{{ $maxBatch }}" value="{{ old('limit', min(3, $maxBatch)) }}"
                       class="mt-1 block w-28 rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100">
            </label>
            <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                <input type="checkbox" name="confirm" value="1" class="rounded border-gray-300 text-indigo-600 dark:border-gray-600">
                {{ __('admin.image_optimizer.confirm') }}
            </label>
            <button type="submit" @disabled($paused)
                    class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-50">
                {{ __('admin.image_optimizer.launch') }}
            </button>
        </form>
        <p class="mt-2 text-xs text-gray-400 dark:text-gray-500">
            {{ $filters['surface'] ? $label($filters['surface']) : __('admin.image_optimizer.all_surfaces') }}
            · {{ $filters['organization_id'] ? $organizations->firstWhere('id', $filters['organization_id'])?->name : __('admin.image_optimizer.all_organizations') }}
        </p>
    </section>

    {{-- Suivi : derniers traitements, ecarts et erreurs. --}}
    <div class="grid gap-6 lg:grid-cols-2">
        @foreach(['recent' => $recent, 'failures' => $failures] as $kind => $rows)
            <section class="{{ $card }} p-4" data-marqueur="{{ $kind === 'recent' ? 'derniers-traitements' : 'ecarts-et-erreurs' }}">
                <h2 class="mb-3 text-sm font-semibold text-gray-700 dark:text-gray-200">
                    {{ $kind === 'recent' ? __('admin.image_optimizer.recent_title') : __('admin.image_optimizer.errors_title') }}
                </h2>
                @if($rows->isEmpty())
                    <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('admin.image_optimizer.none') }}</p>
                @else
                    <ul class="divide-y divide-gray-100 text-xs dark:divide-gray-700">
                        @foreach($rows as $row)
                            <li class="py-2">
                                <div class="flex flex-wrap items-baseline justify-between gap-2">
                                    <span class="font-medium text-gray-800 dark:text-gray-200">{{ $label($row->surface) }} · {{ $row->preset }}</span>
                                    <span class="text-gray-400">{{ $row->processed_at?->diffForHumans() }}</span>
                                </div>
                                <div class="break-all font-mono text-gray-500 dark:text-gray-400">{{ $row->source_path }}</div>
                                @if($kind === 'recent')
                                    <div class="font-mono text-gray-500 dark:text-gray-400">
                                        {{ $row->width }}×{{ $row->height }} · {{ $size($row->source_bytes) }} → {{ $size($row->bytes) }}
                                        @if($row->organization) · {{ $row->organization->name }} @endif
                                    </div>
                                @else
                                    <div class="text-amber-700 dark:text-amber-300">{{ $row->status }} — {{ \Illuminate\Support\Str::limit((string) $row->error, 140) }}</div>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        @endforeach
    </div>
</x-admin-layout>
