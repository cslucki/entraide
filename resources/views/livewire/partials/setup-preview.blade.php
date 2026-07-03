<div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 overflow-hidden">
    <div class="p-4 sm:p-6 border-b border-gray-200 dark:border-gray-700">
        <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">
            {{ __('ai.setup_preview_title') }}
        </h3>
        <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
            {{ __('ai.setup_preview_body') }}
        </p>
    </div>

    <div class="p-4 sm:p-6 space-y-4">
        @if(isset($previewData['summary']))
            <div>
                <h4 class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400 mb-1">{{ __('member_ai_profile.field_summary') }}</h4>
                <p class="text-sm text-gray-800 dark:text-gray-200">{{ $previewData['summary'] }}</p>
            </div>
        @endif

        @if(!empty($previewData['skills']))
            <div>
                <h4 class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400 mb-1">{{ __('member_ai_profile.field_skills') }}</h4>
                <div class="flex flex-wrap gap-1.5">
                    @foreach((array) $previewData['skills'] as $skill)
                        <span class="px-2.5 py-1 bg-purple-100 dark:bg-purple-900/40 text-purple-700 dark:text-purple-300 rounded-full text-xs font-medium">{{ $skill }}</span>
                    @endforeach
                </div>
            </div>
        @endif

        @if(isset($previewData['service_scope']))
            <div>
                <h4 class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400 mb-1">{{ __('member_ai_profile.field_service_scope') }}</h4>
                <p class="text-sm text-gray-800 dark:text-gray-200">{{ $previewData['service_scope'] }}</p>
            </div>
        @endif

        @if(isset($previewData['experience_context']))
            <div>
                <h4 class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400 mb-1">{{ __('member_ai_profile.field_experience') }}</h4>
                <p class="text-sm text-gray-800 dark:text-gray-200">{{ $previewData['experience_context'] }}</p>
            </div>
        @endif

        @if(!empty($previewData['help_types']))
            <div>
                <h4 class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400 mb-1">{{ __('member_ai_profile.field_help_types') }}</h4>
                <div class="flex flex-wrap gap-1.5">
                    @foreach((array) $previewData['help_types'] as $type)
                        <span class="px-2.5 py-1 bg-teal-100 dark:bg-teal-900/40 text-teal-700 dark:text-teal-300 rounded-full text-xs font-medium">{{ $type }}</span>
                    @endforeach
                </div>
            </div>
        @endif

        @if(isset($previewData['target_audience']))
            <div>
                <h4 class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400 mb-1">{{ __('member_ai_profile.field_target_audience') }}</h4>
                <p class="text-sm text-gray-800 dark:text-gray-200">{{ is_array($previewData['target_audience']) ? implode(', ', $previewData['target_audience']) : $previewData['target_audience'] }}</p>
            </div>
        @endif

        @if(isset($previewData['problems_helped']))
            <div>
                <h4 class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400 mb-1">{{ __('member_ai_profile.field_problems_helped') }}</h4>
                <p class="text-sm text-gray-800 dark:text-gray-200">{{ $previewData['problems_helped'] }}</p>
            </div>
        @endif

        @if(!empty($previewData['boundaries']))
            <div>
                <h4 class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400 mb-1">{{ __('member_ai_profile.field_boundaries') }}</h4>
                <div class="flex flex-wrap gap-1.5">
                    @foreach((array) $previewData['boundaries'] as $boundary)
                        <span class="px-2.5 py-1 bg-rose-100 dark:bg-rose-900/40 text-rose-700 dark:text-rose-300 rounded-full text-xs font-medium">{{ $boundary }}</span>
                    @endforeach
                </div>
            </div>
        @endif

        @if(isset($previewData['preferred_contact_action']))
            <div>
                <h4 class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400 mb-1">{{ __('member_ai_profile.field_preferred_contact') }}</h4>
                <p class="text-sm text-gray-800 dark:text-gray-200">{{ $previewData['preferred_contact_action'] }}</p>
            </div>
        @endif

        @if(isset($previewData['tone']))
            <div>
                <h4 class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400 mb-1">{{ __('member_ai_profile.field_tone') }}</h4>
                <p class="text-sm text-gray-800 dark:text-gray-200">{{ $previewData['tone'] }}</p>
            </div>
        @endif

        @if(isset($previewData['note']))
            <div class="p-3 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 rounded-xl text-sm text-amber-700 dark:text-amber-300">
                {{ $previewData['note'] }}
            </div>
        @endif
    </div>

    <div class="p-4 sm:p-6 border-t border-gray-200 dark:border-gray-700 flex flex-col sm:flex-row items-center justify-between gap-3">
        <div class="flex gap-3">
            <button wire:click="validateAndSave" wire:loading.attr="disabled"
                    class="inline-flex items-center gap-2 px-5 py-2.5 bg-indigo-600 text-white text-sm font-semibold rounded-xl hover:bg-indigo-700 transition disabled:opacity-50 shadow-sm">
                <span wire:loading.remove wire:target="validateAndSave">{{ __('ai.setup_save') }}</span>
                <span wire:loading wire:target="validateAndSave">{{ __('ai.setup_saving') }}</span>
            </button>
            <button wire:click="restart"
                    class="inline-flex items-center gap-2 px-5 py-2.5 bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 text-sm font-semibold rounded-xl border border-gray-300 dark:border-gray-600 hover:bg-gray-50 dark:hover:bg-gray-700 transition shadow-sm">
                {{ __('ai.setup_restart_small') }}
            </button>
        </div>
        <a href="{{ route('agent-ia.wizard') }}"
           class="text-xs text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200 underline">
            {{ __('ai.setup_use_form_instead') }}
        </a>
    </div>
</div>
