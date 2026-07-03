<x-app-layout title="{{ __('ai.my_agent_title') }}">
    <x-page-container>
        <div class="mb-8">
            <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">{{ __('ai.my_agent_title') }}</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">{{ __('ai.my_agent_subtitle') }}</p>
        </div>

        @if(! $profile)
            <div class="rounded-2xl border border-dashed border-gray-300 bg-white dark:bg-gray-800 p-8 text-center">
                <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ __('ai.no_profile_title') }}</h2>
                <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">{{ __('ai.my_agent_no_profile_body') }}</p>
                <a href="{{ route('agent-ia.wizard') }}" class="mt-5 inline-flex items-center rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">{{ __('ai.create_profile') }}</a>
            </div>
        @else
            @php
                $tones = __('member_ai_profile.tones');
                $targetAudienceOptions = __('member_ai_profile.target_audience_options');
                $helpTypeOptions = __('member_ai_profile.help_type_options');
                $contactOptions = __('member_ai_profile.contact_options');
                $boundaryOptions = __('member_ai_profile.boundary_options');
            @endphp

            <!-- Actions -->
            <div class="flex flex-wrap gap-3 mb-6">
                <a href="{{ route('agent-ia.setup') }}"
                   class="inline-flex items-center gap-2 px-5 py-2.5 bg-indigo-600 text-white text-sm font-semibold rounded-xl hover:bg-indigo-700 transition shadow-sm">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8.625 12a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H8.25m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H12m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0h-.375M21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 01-2.555-.337A5.972 5.972 0 015.41 20.97a5.969 5.969 0 01-.474-.065 4.48 4.48 0 00.978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25z"/></svg>
                    {{ __('ai.setup_start_btn') }}
                </a>
                <a href="{{ route('agent-ia.wizard') }}"
                   class="inline-flex items-center gap-2 px-5 py-2.5 bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 text-sm font-semibold rounded-xl border border-gray-300 dark:border-gray-600 hover:bg-gray-50 dark:hover:bg-gray-700 transition shadow-sm">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L6.832 19.82a4.5 4.5 0 01-1.897 1.13l-2.685.8.8-2.685a4.5 4.5 0 011.13-1.897L16.863 4.487zm0 0L19.5 7.125"/></svg>
                    {{ __('ai.edit_profile') }}
                </a>
                <a href="{{ route('agent-ia.test') }}"
                   class="inline-flex items-center gap-2 px-5 py-2.5 bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 text-sm font-semibold rounded-xl border border-gray-300 dark:border-gray-600 hover:bg-gray-50 dark:hover:bg-gray-700 transition shadow-sm">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09zM18.259 8.715L18 9.75l-.259-1.035a3.375 3.375 0 00-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 002.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 002.455 2.456L21.75 6l-1.036.259a3.375 3.375 0 00-2.455 2.456zM16.894 20.567L16.5 21.75l-.394-1.183a2.25 2.25 0 00-1.423-1.423L13.5 18.75l1.183-.394a2.25 2.25 0 001.423-1.423l.394-1.183.394 1.183a2.25 2.25 0 001.423 1.423l1.183.394-1.183.394a2.25 2.25 0 00-1.423 1.423z"/></svg>
                    {{ __('ai.test_agent') }}
                </a>
                <a href="{{ route('agent-ia.interactions') }}"
                   class="inline-flex items-center gap-2 px-5 py-2.5 bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 text-sm font-semibold rounded-xl border border-gray-300 dark:border-gray-600 hover:bg-gray-50 dark:hover:bg-gray-700 transition shadow-sm">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M20.25 8.511c.884.284 1.5 1.128 1.5 2.097v4.286c0 1.136-.847 2.1-1.98 2.193-.34.027-.68.052-1.02.072v3.091l-3-3c-1.354 0-2.694-.055-4.02-.163a2.115 2.115 0 01-.825-.242m9.345-8.334a2.126 2.126 0 00-.476-.095 48.64 48.64 0 00-8.048 0c-1.131.094-1.976 1.057-1.976 2.192v4.286c0 .837.46 1.58 1.155 1.951m9.345-8.334V6.637c0-1.621-1.152-3.026-2.76-3.235A48.455 48.455 0 0011.25 3c-2.115 0-4.198.137-6.24.402-1.608.209-2.76 1.614-2.76 3.235v6.226c0 1.621 1.152 3.026 2.76 3.235.577.075 1.157.14 1.74.194V21l4.155-4.155"/></svg>
                    {{ __('ai.view_interactions') }}
                </a>
            </div>

            <!-- Profile summary -->
            <div class="space-y-4">
                @if($profile->member_profile_summary)
                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-5 sm:p-6">
                    <h3 class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400 mb-2">{{ __('member_ai_profile.field_summary') }}</h3>
                    <p class="text-sm text-gray-800 dark:text-gray-200">{{ $profile->member_profile_summary }}</p>
                </div>
                @endif

                @if($profile->target_audience || $profile->problems_helped)
                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-5 sm:p-6">
                    <h3 class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400 mb-2">{{ __('member_ai_profile.field_target_audience') }}</h3>
                    <div class="flex flex-wrap gap-1.5">
                        @foreach($profile->target_audience ?? [] as $audience)
                        <span class="px-2.5 py-1 bg-indigo-100 dark:bg-indigo-900/40 text-indigo-700 dark:text-indigo-300 rounded-full text-xs font-medium">{{ $targetAudienceOptions[$audience] ?? $audience }}</span>
                        @endforeach
                    </div>
                </div>
                @endif

                @if($profile->service_scope)
                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-5 sm:p-6">
                    <h3 class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400 mb-2">{{ __('member_ai_profile.field_service_scope') }}</h3>
                    <p class="text-sm text-gray-800 dark:text-gray-200">{{ $profile->service_scope }}</p>
                </div>
                @endif

                @if($profile->skills)
                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-5 sm:p-6">
                    <h3 class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400 mb-2">{{ __('member_ai_profile.field_skills') }}</h3>
                    <div class="flex flex-wrap gap-1.5">
                        @foreach($profile->skills as $skill)
                        <span class="px-2.5 py-1 bg-purple-100 dark:bg-purple-900/40 text-purple-700 dark:text-purple-300 rounded-full text-xs font-medium">{{ $skill }}</span>
                        @endforeach
                    </div>
                </div>
                @endif

                @if($profile->experience_context)
                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-5 sm:p-6">
                    <h3 class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400 mb-2">{{ __('member_ai_profile.field_experience') }}</h3>
                    <p class="text-sm text-gray-800 dark:text-gray-200">{{ $profile->experience_context }}</p>
                </div>
                @endif

                @if($profile->help_types)
                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-5 sm:p-6">
                    <h3 class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400 mb-2">{{ __('member_ai_profile.field_help_types') }}</h3>
                    <div class="flex flex-wrap gap-1.5">
                        @foreach($profile->help_types as $type)
                        <span class="px-2.5 py-1 bg-teal-100 dark:bg-teal-900/40 text-teal-700 dark:text-teal-300 rounded-full text-xs font-medium">{{ $helpTypeOptions[$type] ?? $type }}</span>
                        @endforeach
                    </div>
                </div>
                @endif

                @if($profile->boundaries)
                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-5 sm:p-6">
                    <h3 class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400 mb-2">{{ __('member_ai_profile.field_boundaries') }}</h3>
                    <div class="flex flex-wrap gap-1.5">
                        @foreach($profile->boundaries as $boundary)
                        <span class="px-2.5 py-1 bg-rose-100 dark:bg-rose-900/40 text-rose-700 dark:text-rose-300 rounded-full text-xs font-medium">{{ $boundaryOptions[$boundary] ?? $boundary }}</span>
                        @endforeach
                    </div>
                </div>
                @endif

                @if($profile->preferred_contact_action)
                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-5 sm:p-6">
                    <h3 class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400 mb-2">{{ __('member_ai_profile.field_preferred_contact') }}</h3>
                    <p class="text-sm text-gray-800 dark:text-gray-200">{{ $contactOptions[$profile->preferred_contact_action] ?? $profile->preferred_contact_action }}</p>
                </div>
                @endif

                @if($profile->tone)
                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-5 sm:p-6">
                    <h3 class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400 mb-2">{{ __('member_ai_profile.field_tone') }}</h3>
                    <p class="text-sm text-gray-800 dark:text-gray-200">{{ $tones[$profile->tone] ?? $profile->tone }}</p>
                </div>
                @endif

                @if($profile->status)
                <div class="text-center p-4 bg-gray-50 dark:bg-gray-900/40 rounded-xl border border-gray-200 dark:border-gray-700">
                    <p class="text-sm text-gray-600 dark:text-gray-400">
                        {{ __('ai.profile_status') }} :
                        <span class="font-semibold text-gray-900 dark:text-gray-100">
                            {{ __('ai.status_' . $profile->status) }}
                        </span>
                    </p>
                </div>
                @endif
            </div>
        @endif
    </x-page-container>
</x-app-layout>
