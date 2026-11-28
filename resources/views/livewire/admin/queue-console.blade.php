{{-- TASK-1909 — console SuperAdmin « Files d'attente ».

     Supervise le worker planifie `queue:work database` (AGENTS.md section 16).
     Lecture d'abord (etat, alerte), actions ensuite, une a la fois. Le contenu
     des jobs n'est jamais affiche. --}}
@php
    $card = 'rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800';
    $age = fn (?int $s) => $s === null ? '—' : ($s < 90 ? __('admin.queue_console.age_seconds', ['n' => $s]) : ($s < 5400 ? __('admin.queue_console.age_minutes', ['n' => (int) round($s / 60)]) : __('admin.queue_console.age_hours', ['n' => (int) round($s / 3600)])));
    $btn = 'inline-flex min-h-[36px] items-center rounded-lg px-3 text-xs font-semibold transition disabled:opacity-50';
@endphp
<div wire:poll.30s>
    <x-slot:title>{{ __('admin.queue_console.title') }}</x-slot:title>

    <div class="mb-5 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('admin.queue_console.subtitle') }}</p>
        <span @class([
                'inline-flex shrink-0 items-center gap-1.5 self-start rounded-full px-3 py-1 text-xs font-semibold',
                'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-300' => $workerAlive,
                'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-300' => ! $workerAlive,
              ]) data-marqueur="etat-worker" data-worker-alive="{{ $workerAlive ? '1' : '0' }}">
            <span @class(['h-2 w-2 rounded-full', 'bg-green-500' => $workerAlive, 'bg-red-500' => ! $workerAlive])></span>
            {{ $workerAlive ? __('admin.queue_console.worker_alive') : __('admin.queue_console.worker_down') }}
            <span class="font-normal">· {{ $workerSeen ? __('admin.queue_console.worker_seen', ['when' => $workerSeen->diffForHumans()]) : __('admin.queue_console.worker_never') }}</span>
        </span>
    </div>

    @if($notice)
        <div class="mb-4 rounded-lg border border-green-200 bg-green-50 px-4 py-2 text-sm text-green-800 dark:border-green-800 dark:bg-green-900/30 dark:text-green-200" data-marqueur="notice">{{ $notice }}</div>
    @endif
    @if($error)
        <div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-2 text-sm text-red-800 dark:border-red-800 dark:bg-red-900/30 dark:text-red-200" data-marqueur="erreur">{{ $error }}</div>
    @endif

    {{-- Alerte --}}
    <div @class([
            'mb-5 rounded-xl border p-4 text-sm',
            'border-green-200 bg-green-50 text-green-800 dark:border-green-800 dark:bg-green-900/30 dark:text-green-200' => $alert['level'] === 'green',
            'border-red-200 bg-red-50 text-red-800 dark:border-red-800 dark:bg-red-900/30 dark:text-red-200' => $alert['level'] !== 'green',
         ]) data-marqueur="alerte" data-niveau="{{ $alert['level'] }}">
        <p class="font-semibold">
            {{ $alert['level'] === 'green' ? __('admin.queue_console.alert_ok', ['count' => $pendingTotal]) : __('admin.queue_console.alert_'.$alert['reason']) }}
        </p>
        @if($alert['level'] !== 'green')
            <p class="mt-1 text-xs">{{ __('admin.queue_console.alert_hint') }}</p>
        @endif
    </div>

    <div class="grid gap-5 lg:grid-cols-2">
        {{-- Connexion de chaque traitement --}}
        <section class="{{ $card }}" data-marqueur="traitements">
            <h2 class="mb-1 text-sm font-semibold text-gray-900 dark:text-gray-100">{{ __('admin.queue_console.flows_title') }}</h2>
            <p class="mb-3 text-xs text-gray-500 dark:text-gray-400">{{ __('admin.queue_console.flows_hint') }}</p>
            <ul class="divide-y divide-gray-100 text-sm dark:divide-gray-700">
                @foreach($flows as $flow)
                    <li class="flex items-center justify-between gap-3 py-2">
                        <span class="text-gray-700 dark:text-gray-200">{{ __('admin.queue_console.flow_'.$flow['key']) }}</span>
                        <span @class([
                                'shrink-0 rounded-full px-2 py-0.5 text-xs font-semibold',
                                'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-300' => $flow['async'],
                                'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300' => ! $flow['async'],
                              ]) data-flow="{{ $flow['key'] }}" data-connection="{{ $flow['connection'] }}">
                            {{ $flow['connection'] }} · {{ $flow['async'] ? __('admin.queue_console.flow_async') : __('admin.queue_console.flow_sync') }}
                        </span>
                    </li>
                @endforeach
            </ul>
        </section>

        {{-- Files du worker --}}
        <section class="{{ $card }}" data-marqueur="files">
            <h2 class="mb-1 text-sm font-semibold text-gray-900 dark:text-gray-100">{{ __('admin.queue_console.queues_title') }}</h2>
            <p class="mb-3 text-xs text-gray-500 dark:text-gray-400">{{ __('admin.queue_console.queues_hint') }}</p>
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs text-gray-500 dark:text-gray-400">
                        <th class="py-1 font-medium">{{ __('admin.queue_console.col_queue') }}</th>
                        <th class="py-1 text-right font-medium">{{ __('admin.queue_console.col_pending') }}</th>
                        <th class="py-1 text-right font-medium">{{ __('admin.queue_console.col_running') }}</th>
                        <th class="py-1 text-right font-medium">{{ __('admin.queue_console.col_oldest') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    @foreach($queues as $q)
                        <tr data-queue="{{ $q['queue'] }}" data-pending="{{ $q['pending'] }}">
                            <td class="py-1.5 font-mono text-xs text-gray-700 dark:text-gray-200">{{ $q['queue'] }}</td>
                            <td class="py-1.5 text-right text-gray-900 dark:text-gray-100">{{ $q['pending'] }}</td>
                            <td class="py-1.5 text-right text-gray-900 dark:text-gray-100">{{ $q['running'] }}</td>
                            <td @class(['py-1.5 text-right', 'font-semibold text-red-600 dark:text-red-400' => ($q['oldest_seconds'] ?? 0) > \App\Support\Queues\QueueSupervision::ALERT_AFTER_SECONDS, 'text-gray-500 dark:text-gray-400' => ($q['oldest_seconds'] ?? 0) <= \App\Support\Queues\QueueSupervision::ALERT_AFTER_SECONDS])>{{ $age($q['oldest_seconds']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </section>
    </div>

    {{-- Mode manuel --}}
    <section class="{{ $card }} mt-5" data-marqueur="mode-manuel"
             x-data="{ running: false, left: {{ $pendingTotal }}, async start() { this.running = true; while (this.running) { this.left = await $wire.processOne(); if (this.left <= 0) break; } this.running = false; } }">
        <h2 class="mb-1 text-sm font-semibold text-gray-900 dark:text-gray-100">{{ __('admin.queue_console.manual_title') }}</h2>
        <p class="mb-3 text-xs text-gray-500 dark:text-gray-400">{{ __('admin.queue_console.manual_hint') }}</p>
        <div class="flex flex-wrap items-center gap-3">
            <button type="button" x-show="!running" @click="start()" :disabled="left <= 0"
                    class="{{ $btn }} bg-indigo-600 text-white hover:bg-indigo-700" data-marqueur="manuel-demarrer">
                {{ __('admin.queue_console.manual_start') }}
            </button>
            <button type="button" x-show="running" x-cloak @click="running = false"
                    class="{{ $btn }} border border-gray-300 text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">
                {{ __('admin.queue_console.manual_stop') }}
            </button>
            <span class="text-xs text-gray-600 dark:text-gray-300" data-marqueur="manuel-restants">
                {{ __('admin.queue_console.manual_left') }} <span x-text="left"></span>
            </span>
        </div>
    </section>

    {{-- Jobs en echec --}}
    <section class="{{ $card }} mt-5" data-marqueur="echecs">
        <h2 class="mb-1 text-sm font-semibold text-gray-900 dark:text-gray-100">{{ __('admin.queue_console.failed_title', ['count' => $failedTotal]) }}</h2>
        <p class="mb-3 text-xs text-gray-500 dark:text-gray-400">{{ __('admin.queue_console.failed_hint') }}</p>
        @forelse($failed as $f)
            <div class="flex flex-col gap-2 border-t border-gray-100 py-2 first:border-t-0 sm:flex-row sm:items-start sm:justify-between dark:border-gray-700" data-failed="{{ $f['uuid'] }}">
                <div class="min-w-0 text-sm">
                    <p class="text-gray-900 dark:text-gray-100"><span class="font-semibold">{{ $f['job'] }}</span> · <span class="font-mono text-xs">{{ $f['queue'] }}</span> · <span class="text-xs text-gray-500 dark:text-gray-400">{{ $f['failed_at'] }}</span></p>
                    <p class="mt-0.5 break-words text-xs text-red-700 dark:text-red-300">{{ $f['error'] }}</p>
                </div>
                <div class="flex shrink-0 gap-2">
                    <button type="button" wire:click="retryFailed('{{ $f['uuid'] }}')" wire:loading.attr="disabled" class="{{ $btn }} bg-indigo-600 text-white hover:bg-indigo-700">{{ __('admin.queue_console.failed_retry') }}</button>
                    <button type="button" wire:click="forgetFailed('{{ $f['uuid'] }}')" wire:loading.attr="disabled" class="{{ $btn }} border border-gray-300 text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">{{ __('admin.queue_console.failed_forget') }}</button>
                </div>
            </div>
        @empty
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('admin.queue_console.failed_none') }}</p>
        @endforelse
    </section>

    {{-- Articles --}}
    <section class="{{ $card }} mt-5" data-marqueur="articles">
        <h2 class="mb-3 text-sm font-semibold text-gray-900 dark:text-gray-100">{{ __('admin.queue_console.articles_title') }}</h2>

        <h3 class="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('admin.queue_console.pdf_title', ['count' => $pdfTotal]) }}</h3>
        @forelse($pdfs as $pdf)
            <div class="flex items-center justify-between gap-3 border-t border-gray-100 py-2 first:border-t-0 dark:border-gray-700" data-pdf="{{ $pdf['id'] }}">
                <div class="min-w-0 text-sm">
                    <p class="truncate text-gray-900 dark:text-gray-100">{{ $pdf['name'] }}</p>
                    <p class="truncate text-xs text-gray-500 dark:text-gray-400">{{ $pdf['article'] ?? __('admin.queue_console.pdf_not_attached') }} · {{ $age($pdf['age_seconds']) }}</p>
                </div>
                <button type="button" wire:click="extractNow('{{ $pdf['id'] }}')" wire:loading.attr="disabled" class="{{ $btn }} shrink-0 border border-gray-300 text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">{{ __('admin.queue_console.pdf_extract_now') }}</button>
            </div>
        @empty
            <p class="mb-2 text-sm text-gray-500 dark:text-gray-400">{{ __('admin.queue_console.pdf_none') }}</p>
        @endforelse

        <h3 class="mb-1 mt-4 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('admin.queue_console.articles_pending_title') }}</h3>
        @forelse($articles as $a)
            <p class="border-t border-gray-100 py-1.5 text-sm text-gray-900 first:border-t-0 dark:border-gray-700 dark:text-gray-100" data-marqueur="article-en-attente">
                {{ $a['title'] }} <span class="text-xs text-gray-500 dark:text-gray-400">· {{ $a['running'] ? __('admin.queue_console.article_running') : $age($a['age_seconds']) }}</span>
            </p>
        @empty
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('admin.queue_console.articles_none') }}</p>
        @endforelse

        <form wire:submit="reindexArticle" class="mt-4 flex flex-col gap-2 sm:flex-row sm:items-center">
            <label for="queue-console-article" class="text-xs font-medium text-gray-700 dark:text-gray-300">{{ __('admin.queue_console.reindex_label') }}</label>
            <input id="queue-console-article" type="text" wire:model="articleRef" placeholder="{{ __('admin.queue_console.reindex_placeholder') }}"
                   class="min-h-[36px] flex-1 rounded-lg border border-gray-300 bg-white px-3 text-sm text-gray-900 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
            <button type="submit" wire:loading.attr="disabled" class="{{ $btn }} bg-indigo-600 text-white hover:bg-indigo-700">{{ __('admin.queue_console.reindex_submit') }}</button>
        </form>
    </section>

    {{-- Note : lancer le worker en local --}}
    <section class="mt-5 rounded-xl border border-gray-200 bg-gray-50 p-4 text-sm dark:border-gray-700 dark:bg-gray-900/40" data-marqueur="note-wsl">
        <h2 class="mb-1 text-sm font-semibold text-gray-900 dark:text-gray-100">{{ __('admin.queue_console.wsl_title') }}</h2>
        <p class="mb-2 text-xs text-gray-600 dark:text-gray-300">{{ __('admin.queue_console.wsl_intro') }}</p>
        <ol class="list-decimal space-y-2 pl-5 text-xs text-gray-700 dark:text-gray-300">
            <li>{{ __('admin.queue_console.wsl_step_project') }}
                <pre class="mt-1 overflow-x-auto rounded bg-gray-900 p-2 font-mono text-[11px] text-gray-100">cd /home/cyril/claude-code/sites/test.laravel</pre></li>
            <li>{{ __('admin.queue_console.wsl_step_cron') }}
                <pre class="mt-1 overflow-x-auto rounded bg-gray-900 p-2 font-mono text-[11px] text-gray-100">crontab -l | grep test.laravel
grep "queue:work" storage/logs/scheduler.log | tail -3</pre></li>
            <li>{{ __('admin.queue_console.wsl_step_now') }}
                <pre class="mt-1 overflow-x-auto rounded bg-gray-900 p-2 font-mono text-[11px] text-gray-100">php artisan queue:work database --queue='{{ implode(',', $workerQueues) }}' --stop-when-empty</pre></li>
            <li>{{ __('admin.queue_console.wsl_step_pdf') }}
                <pre class="mt-1 overflow-x-auto rounded bg-gray-900 p-2 font-mono text-[11px] text-gray-100">php artisan blog:extract-file-texts</pre></li>
        </ol>
        <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ __('admin.queue_console.wsl_footer') }}</p>
    </section>
</div>
