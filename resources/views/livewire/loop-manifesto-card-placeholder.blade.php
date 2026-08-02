<div class="space-y-4">
    <div class="rounded-2xl border border-gray-200 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-800/60">
        <p class="text-[11px] font-semibold uppercase tracking-wide text-sky-600 dark:text-sky-300">
            {{ __('loops.cards.manifesto.label') }}
        </p>
        <p class="mt-1 text-sm leading-6 text-gray-600 dark:text-gray-300">
            {{ __('loops.cards.manifesto.description') }}
        </p>
    </div>

    <div class="rounded-2xl border border-dashed border-gray-300 bg-white p-5 text-center dark:border-gray-700 dark:bg-gray-900">
        <div class="mx-auto flex h-12 w-12 animate-pulse items-center justify-center rounded-full bg-sky-50 text-sky-600 dark:bg-sky-900/30 dark:text-sky-200">
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6.5v11m0-11a3 3 0 0 0-3-3H4.5v11H9a3 3 0 0 1 3 3m0-14a3 3 0 0 1 3-3h4.5v11H15a3 3 0 0 0-3 3"/></svg>
        </div>
        <h3 class="mt-4 text-sm font-semibold text-gray-900 dark:text-gray-100">{{ __('loops.cards.manifesto.empty_title') }}</h3>
        <p class="mx-auto mt-2 max-w-sm text-sm leading-6 text-gray-500 dark:text-gray-400">{{ __('loops.manifesto_pitch') }}</p>
    </div>
</div>
