<div class="space-y-4">
    <div class="rounded-2xl border border-gray-200 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-800/60">
        <p class="text-[11px] font-semibold uppercase tracking-wide text-violet-600 dark:text-violet-300">
            {{ __('loops.cards.ai_summary.label') }}
        </p>
        <p class="mt-1 text-sm leading-6 text-gray-600 dark:text-gray-300">
            {{ __('loops.cards.ai_summary.description') }}
        </p>
    </div>

    <div class="rounded-2xl border border-dashed border-gray-300 bg-white p-5 text-center dark:border-gray-700 dark:bg-gray-900">
        <div class="mx-auto flex h-12 w-12 animate-pulse items-center justify-center rounded-full bg-violet-50 text-violet-600 dark:bg-violet-900/30 dark:text-violet-200">
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6l4 2m6-2a10 10 0 1 1-20 0 10 10 0 0 1 20 0z"/></svg>
        </div>
        <h3 class="mt-4 text-sm font-semibold text-gray-900 dark:text-gray-100">{{ __('loops.cards.ai_summary.empty_title') }}</h3>
        <p class="mx-auto mt-2 max-w-sm text-sm leading-6 text-gray-500 dark:text-gray-400">{{ __('loops.cards.ai_summary.empty_body') }}</p>
    </div>
</div>
