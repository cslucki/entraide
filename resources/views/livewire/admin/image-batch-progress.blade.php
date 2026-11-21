{{-- TASK-1873 (phase 2) — carte de progression d'un lot. Rafraichie toutes
     les 2,5 s tant que le lot avance ; immobile une fois termine. --}}
@php
    $size = fn (?int $bytes) => $bytes === null ? '—' : \Illuminate\Support\Number::fileSize($bytes, precision: 1);
    $title = match ($batch->status) {
        'paused' => __('admin.image_optimizer.progress_paused'),
        'completed' => __('admin.image_optimizer.progress_done'),
        'completed_with_errors' => __('admin.image_optimizer.progress_done_errors'),
        default => __('admin.image_optimizer.progress_title'),
    };
    $remaining = $progress['pending'] + $progress['processing'];
    $saving = $progress['served_source_bytes'] > 0 ? (int) round(100 * (1 - $progress['served_bytes'] / $progress['served_source_bytes'])) : null;
    $duration = $progress['duration_seconds'] === null ? '—' : \Carbon\CarbonInterval::seconds($progress['duration_seconds'])->cascade()->forHumans(['short' => true]);
@endphp
<section @if($polling) wire:poll.2500ms @endif
         class="rounded-xl border border-indigo-200 bg-white p-4 shadow-sm dark:border-indigo-800 dark:bg-gray-800 sm:p-5"
         data-marqueur="progression" data-batch-status="{{ $batch->status }}">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h2 class="text-base font-semibold text-gray-900 dark:text-gray-100">{{ $title }}</h2>
            <p class="mt-0.5 text-sm text-gray-600 dark:text-gray-300" data-marqueur="compteur">
                {{ __('admin.image_optimizer.progress_count', ['done' => $progress['done'], 'total' => $progress['total']]) }}
                · {{ $progress['percent'] }} %
            </p>
        </div>

        <div class="flex gap-2">
            @if(in_array($batch->status, ['pending', 'running'], true))
                <button type="button" wire:click="pause" class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">
                    {{ __('admin.image_optimizer.pause') }}
                </button>
            @elseif($batch->status === 'paused')
                <button type="button" wire:click="resume" @disabled(! $queueReady) class="disabled:cursor-not-allowed disabled:opacity-50 "rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">
                    {{ __('admin.image_optimizer.resume') }}
                </button>
            @elseif($progress['failed'] > 0)
                <button type="button" wire:click="retryFailed" @disabled(! $queueReady) class="disabled:cursor-not-allowed disabled:opacity-50 "rounded-lg border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">
                    {{ __('admin.image_optimizer.retry_failed') }}
                </button>
            @endif
        </div>
    </div>

    <div class="mt-3 h-3 w-full overflow-hidden rounded-full bg-gray-100 dark:bg-gray-700" role="progressbar"
         aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $progress['percent'] }}">
        <div @class([
                'h-full rounded-full transition-all duration-500',
                'bg-amber-500' => $batch->status === 'paused',
                'bg-red-500' => $batch->status === 'completed_with_errors',
                'bg-indigo-600' => ! in_array($batch->status, ['paused', 'completed_with_errors'], true),
             ]) style="width: {{ $progress['percent'] }}%"></div>
    </div>

    <dl class="mt-3 grid grid-cols-3 gap-2 text-center text-sm">
        <div><dt class="text-xs text-gray-500 dark:text-gray-400">{{ __('admin.image_optimizer.progress_succeeded') }}</dt><dd class="font-semibold text-green-700 dark:text-green-400">{{ $progress['succeeded'] }}</dd></div>
        <div><dt class="text-xs text-gray-500 dark:text-gray-400">{{ __('admin.image_optimizer.progress_failed') }}</dt><dd class="font-semibold {{ $progress['failed'] > 0 ? 'text-red-600 dark:text-red-400' : 'text-gray-700 dark:text-gray-300' }}">{{ $progress['failed'] }}</dd></div>
        <div><dt class="text-xs text-gray-500 dark:text-gray-400">{{ __('admin.image_optimizer.progress_remaining') }}</dt><dd class="font-semibold text-gray-700 dark:text-gray-300">{{ $remaining }}</dd></div>
    </dl>

    @if($batch->isPaused() && $progress['processing'] > 0)
        <p class="mt-3 text-sm text-amber-700 dark:text-amber-300">{{ __('admin.image_optimizer.pause_pending') }}</p>
    @elseif($batch->isActive() && ! $workerAlive)
        <p class="mt-3 text-sm font-medium text-red-700 dark:text-red-300" data-marqueur="worker-indisponible">{{ __('admin.image_optimizer.worker_down_batch') }}</p>
    @elseif($progress['waiting_for_worker'])
        <p class="mt-3 text-sm text-amber-700 dark:text-amber-300" data-marqueur="attente-worker">{{ __('admin.image_optimizer.waiting_worker') }}</p>
    @elseif($batch->isActive())
        <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">{{ __('admin.image_optimizer.navigate_hint') }}</p>
    @endif

    @error('retry')<p class="mt-3 text-sm text-amber-700 dark:text-amber-300">{{ $message }}</p>@enderror

    {{-- Bilan, une fois le lot termine. --}}
    @unless($batch->isActive())
        <div class="mt-4 rounded-lg bg-gray-50 p-3 dark:bg-gray-900/50" data-marqueur="bilan">
            <h3 class="mb-2 text-sm font-semibold text-gray-800 dark:text-gray-200">{{ __('admin.image_optimizer.report_title') }}</h3>
            <dl class="grid grid-cols-2 gap-x-4 gap-y-1 text-sm sm:grid-cols-3">
                <div class="flex justify-between gap-2"><dt class="text-gray-500 dark:text-gray-400">{{ __('admin.image_optimizer.report_processed') }}</dt><dd class="font-semibold">{{ $progress['succeeded'] }}</dd></div>
                <div class="flex justify-between gap-2"><dt class="text-gray-500 dark:text-gray-400">{{ __('admin.image_optimizer.report_skipped') }}</dt><dd class="font-semibold">{{ $progress['skipped'] }}</dd></div>
                <div class="flex justify-between gap-2"><dt class="text-gray-500 dark:text-gray-400">{{ __('admin.image_optimizer.report_missing') }}</dt><dd class="font-semibold">{{ $progress['missing'] }}</dd></div>
                <div class="flex justify-between gap-2"><dt class="text-gray-500 dark:text-gray-400">{{ __('admin.image_optimizer.report_failed') }}</dt><dd class="font-semibold">{{ $progress['failed'] }}</dd></div>
                <div class="flex justify-between gap-2"><dt class="text-gray-500 dark:text-gray-400">{{ __('admin.image_optimizer.report_weight') }}</dt><dd class="font-semibold">{{ $size($progress['served_source_bytes']) }} → {{ $size($progress['served_bytes']) }}</dd></div>
                <div class="flex justify-between gap-2"><dt class="text-gray-500 dark:text-gray-400">{{ __('admin.image_optimizer.report_saving') }}</dt><dd class="font-semibold">{{ $saving !== null ? $saving.' %' : '—' }}</dd></div>
                <div class="flex justify-between gap-2"><dt class="text-gray-500 dark:text-gray-400">{{ __('admin.image_optimizer.report_duration') }}</dt><dd class="font-semibold">{{ $duration }}</dd></div>
            </dl>
        </div>
    @endunless

    <details class="mt-3" data-marqueur="details">
        <summary class="cursor-pointer text-sm text-indigo-700 dark:text-indigo-300">{{ __('admin.image_optimizer.details') }}</summary>
        <div class="mt-2 space-y-2 text-xs">
            <p class="text-gray-500 dark:text-gray-400">
                {{ __('admin.image_optimizer.details_batch', ['id' => $batch->id, 'status' => __('admin.image_optimizer.status_'.$batch->status)]) }}
                · {{ __('admin.image_optimizer.progress_skipped') }} {{ $progress['skipped'] }}
                · {{ __('admin.image_optimizer.progress_missing') }} {{ $progress['missing'] }}
            </p>
            @if($problems->isEmpty())
                <p class="text-gray-500 dark:text-gray-400">{{ __('admin.image_optimizer.details_none') }}</p>
            @else
                <ul class="space-y-1">
                    @foreach($problems as $problem)
                        <li>
                            <span class="{{ $problem->status === 'failed' ? 'text-red-600 dark:text-red-400' : 'text-amber-700 dark:text-amber-300' }}">{{ __('admin.image_optimizer.item_'.$problem->status) }}</span>
                            <span class="break-all font-mono text-gray-500 dark:text-gray-400">{{ $problem->source_path }}</span>
                            @if($problem->error && $problem->status === 'failed')<span class="block text-gray-400">{{ \Illuminate\Support\Str::limit($problem->error, 160) }}</span>@endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </details>
</section>
