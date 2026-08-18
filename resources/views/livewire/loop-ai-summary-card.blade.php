<div class="space-y-4">
    @if($autoGenerate)
        {{-- One-time automatic generation on the first real open of the card. --}}
        <div wire:init="generate" class="hidden"></div>
    @endif

    {{-- Header --}}
    <div class="rounded-2xl border border-gray-200 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-800/60">
        <p class="text-[11px] font-semibold uppercase tracking-wide text-violet-600 dark:text-violet-300">
            {{ __('loops.cards.ai_summary.label') }}
        </p>
        <p class="mt-1 text-sm leading-6 text-gray-600 dark:text-gray-300">
            {{ __('loops.cards.ai_summary.description') }}
        </p>
    </div>

    {{-- Non-blocking error --}}
    @if($errorMessage)
        <div class="rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:border-amber-700/50 dark:bg-amber-900/20 dark:text-amber-200" data-ai-summary-error @if($errorCode) data-ai-refusal-code="{{ $errorCode }}" @endif>
            {{ $errorMessage }}
            @if($errorCode === \App\Support\Ai\AiRefusedException::CODE_USER_CREDIT_EXHAUSTED)
                <a href="{{ aiOffersUrl($loop->organization) }}" class="ml-1 font-semibold underline underline-offset-2 hover:no-underline" data-ai-credit-offers-link>{{ __('ai.credit_see_offers') }}</a>
            @endif
        </div>
    @endif

    {{-- Skeleton while generating (first generation, no previous summary) --}}
    @unless($hasSummary)
        <div wire:loading.flex wire:target="generate" class="flex-col gap-3" style="display:none;">
            <div class="animate-pulse space-y-3 rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-700 dark:bg-gray-900">
                <div class="h-3 w-2/3 rounded bg-gray-200 dark:bg-gray-700"></div>
                <div class="h-3 w-full rounded bg-gray-200 dark:bg-gray-700"></div>
                <div class="h-3 w-11/12 rounded bg-gray-200 dark:bg-gray-700"></div>
                <div class="h-3 w-5/6 rounded bg-gray-200 dark:bg-gray-700"></div>
            </div>
            <p class="text-center text-xs text-gray-400 dark:text-gray-500">{{ __('loops.ai_summary_generating') }}</p>
        </div>
    @endunless

    {{-- Existing summary (kept visible during regeneration) --}}
    @if($hasSummary)
        <div class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
            <div
                class="prose prose-sm max-w-none text-gray-800 transition-opacity dark:prose-invert dark:text-gray-100"
                wire:loading.class="opacity-40"
                wire:target="generate"
            >
                {!! markdown($summaryBody ?? '') !!}
            </div>

            <div class="mt-4 flex flex-wrap items-center justify-between gap-2 border-t border-gray-100 pt-3 dark:border-gray-800">
                <p class="text-[11px] text-gray-400 dark:text-gray-500">
                    @if($summaryCreatedAtIso)
                        {{ __('loops.ai_summary_updated_at', ['date' => \Illuminate\Support\Carbon::parse($summaryCreatedAtIso)->isoFormat('LLL')]) }}
                    @endif
                    @if($summaryAuthor)
                        · {{ __('loops.ai_summary_requested_by', ['name' => $summaryAuthor]) }}
                    @endif
                </p>

                @if($canGenerate)
                    <button
                        type="button"
                        wire:click="generate"
                        wire:loading.attr="disabled"
                        wire:target="generate"
                        class="inline-flex items-center gap-2 rounded-xl border border-violet-100 bg-violet-50 px-3 py-2 text-xs font-semibold text-violet-800 transition hover:border-violet-200 hover:bg-violet-100 disabled:cursor-not-allowed disabled:opacity-50 dark:border-violet-800/50 dark:bg-violet-900/30 dark:text-violet-200 dark:hover:bg-violet-900/50"
                    >
                        <svg wire:loading.remove wire:target="generate" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992V4.356M3.985 19.644v-4.992h4.992m-.02-3.324a7.5 7.5 0 0 1 12.548-3.364l2.482 2.31M20.055 13.672a7.5 7.5 0 0 1-12.548 3.364l-2.482-2.31"/></svg>
                        <svg wire:loading wire:target="generate" class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                        <span wire:loading.remove wire:target="generate">{{ __('loops.ai_summary_regenerate') }}</span>
                        <span wire:loading wire:target="generate">{{ __('loops.ai_summary_generating') }}</span>
                    </button>
                @endif
            </div>
        </div>
    @else
        {{-- Empty state (hidden while the first generation is in flight) --}}
        <div wire:loading.remove wire:target="generate" class="rounded-2xl border border-dashed border-gray-300 bg-white p-5 text-center dark:border-gray-700 dark:bg-gray-900">
            <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-violet-50 text-violet-600 dark:bg-violet-900/30 dark:text-violet-200">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 11.18 18.55a.75.75 0 0 0 1.38-.031l1.745-3.83a.75.75 0 0 1 .322-.36l3.746-2.25a.75.75 0 0 0 0-1.27l-3.746-2.25a.75.75 0 0 1-.322-.36L12.56 5.48a.75.75 0 0 0-1.38-.031l-1.367 2.647a.75.75 0 0 1-.5.369L4.88 9.373a.75.75 0 0 0 0 1.463l3.432.92a.75.75 0 0 1 .5.368z"/></svg>
            </div>
            <h3 class="mt-4 text-sm font-semibold text-gray-900 dark:text-gray-100">{{ __('loops.cards.ai_summary.empty_title') }}</h3>
            <p class="mx-auto mt-2 max-w-sm text-sm leading-6 text-gray-500 dark:text-gray-400">{{ __('loops.cards.ai_summary.empty_body') }}</p>

            @if($canGenerate)
                <button
                    type="button"
                    wire:click="generate"
                    wire:loading.attr="disabled"
                    wire:target="generate"
                    class="mt-5 inline-flex items-center justify-center gap-2 rounded-xl bg-violet-600 px-4 py-2 text-xs font-semibold text-white transition hover:bg-violet-700 disabled:cursor-not-allowed disabled:opacity-50"
                >
                    {{ __('loops.ai_summary_generate') }}
                </button>
            @endif
        </div>
    @endif
</div>
