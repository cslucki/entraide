<div>
    <div class="max-w-3xl mx-auto">
        @unless($started)
            <div class="rounded-2xl border border-dashed border-gray-300 bg-white dark:bg-gray-800 p-8 text-center">
                <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">
                    {{ __('ai.setup_start_title') }}
                </h2>
                <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">
                    {{ __('ai.setup_start_body') }}
                </p>
                <div class="mt-6 flex flex-col sm:flex-row items-center justify-center gap-3">
                    <button wire:click="start" wire:loading.attr="disabled"
                            class="inline-flex items-center gap-2 px-6 py-3 bg-indigo-600 text-white text-sm font-semibold rounded-xl hover:bg-indigo-700 transition shadow-sm disabled:opacity-50">
                        <span wire:loading.remove wire:target="start">{{ __('ai.setup_start_btn') }}</span>
                        <span wire:loading wire:target="start">{{ __('ai.setup_starting') }}</span>
                    </button>
                    <a href="{{ route('agent-ia.wizard') }}"
                       class="inline-flex items-center gap-2 px-6 py-3 bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 text-sm font-semibold rounded-xl border border-gray-300 dark:border-gray-600 hover:bg-gray-50 dark:hover:bg-gray-700 transition shadow-sm">
                        {{ __('ai.setup_use_form') }}
                    </a>
                </div>
            </div>
        @elseif($showPreview && $previewData)
            @include('livewire.partials.setup-preview')
        @else
            @include('livewire.partials.setup-chat')
        @endif
    </div>

    @if($error)
        <div class="mt-4 max-w-3xl mx-auto p-4 bg-red-50 dark:bg-red-900/30 border border-red-200 dark:border-red-800 rounded-xl text-sm text-red-700 dark:text-red-300">
            {{ $error }}
        </div>
    @endif
</div>
