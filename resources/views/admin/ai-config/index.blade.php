@php $title = __('admin.ai_config_title'); @endphp

<x-admin-layout>
    <div class="max-w-3xl space-y-6">
        <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ __('admin.ai_config') }}</h2>

        {{-- État actuel --}}
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-5 space-y-4">
            <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300 uppercase tracking-wider">{{ __('admin.ai_current_status') }}</h3>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div class="bg-gray-50 dark:bg-gray-700/50 rounded-lg p-4">
                    <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">{{ __('admin.ai_provider_default') }}</p>
                    <p class="text-sm font-medium text-gray-900 dark:text-gray-100">
                        @if($defaultProvider && isset($providers[$defaultProvider]))
                            {{ $providers[$defaultProvider]['label'] }}
                        @elseif($defaultProvider)
                            {{ ucfirst($defaultProvider) }}
                        @else
                            <span class="text-amber-600 dark:text-amber-400">{{ __('admin.ai_none') }}</span>
                        @endif
                    </p>
                </div>

                <div class="bg-gray-50 dark:bg-gray-700/50 rounded-lg p-4">
                    <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">{{ __('admin.ai_environment') }}</p>
                    <p class="text-sm font-medium text-gray-900 dark:text-gray-100">
                        {{ $isProduction ? __('admin.ai_production') : __('admin.ai_development') }}
                    </p>
                </div>

                @if($currentProviderConfig)
                    <div class="bg-gray-50 dark:bg-gray-700/50 rounded-lg p-4">
                        <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">{{ __('admin.ai_model_optional') }}</p>
                        <p class="text-sm font-medium text-gray-900 dark:text-gray-100 font-mono">{{ $currentProviderConfig['model'] }}</p>
                    </div>

                    <div class="bg-gray-50 dark:bg-gray-700/50 rounded-lg p-4">
                        <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">Base URL</p>
                        <p class="text-sm font-medium text-gray-900 dark:text-gray-100 font-mono truncate">{{ $currentProviderConfig['base_url'] }}</p>
                    </div>
                @endif
            </div>
        </div>

        {{-- Formulaire de configuration --}}
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-5">
            <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300 uppercase tracking-wider mb-4">{{ __('admin.ai_edit_config') }}</h3>

            <form method="POST" action="{{ route('admin.ai-config.update') }}" class="space-y-4">
                @csrf

                <div>
                    <label for="default_provider" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('admin.ai_provider_default') }}</label>
                    <select name="default_provider" id="default_provider"
                            class="w-full px-3 py-2 text-sm border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-indigo-500 focus:border-transparent">
                        <option value="">— {{ __('admin.ai_inherit_first_available') }} —</option>
                        @foreach($providers as $key => $info)
                            <option value="{{ $key }}" @selected($defaultProvider === $key)>{{ $info['label'] }}</option>
                        @endforeach
                    </select>
                    @error('default_provider')<p class="text-red-500 text-xs mt-1">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label for="default_model" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('admin.ai_model_optional') }}</label>
                    <input type="text" name="default_model" id="default_model" value="{{ old('default_model', $defaultModel) }}"
                           placeholder="{{ __('admin.ai_model_placeholder') }}"
                           class="w-full px-3 py-2 text-sm border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 placeholder-gray-400 focus:ring-2 focus:ring-indigo-500 focus:border-transparent">
                    <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">{{ __('admin.ai_model_leave_empty') }}</p>
                    @error('default_model')<p class="text-red-500 text-xs mt-1">{{ $message }}</p>@enderror
                </div>

                <div class="border-t border-gray-200 dark:border-gray-700 pt-4">
                    <h4 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">{{ __('admin.ai_features_title') }}</h4>
                    <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                        <input type="checkbox" name="clarification_enabled" value="1" @checked($clarificationEnabled) class="rounded border-gray-300 dark:border-gray-600 text-indigo-600 focus:ring-indigo-500">
                        {{ __('admin.ai_clarification') }}
                    </label>
                    <p class="text-xs text-gray-400 dark:text-gray-500 mt-1 ml-6">{{ __('admin.ai_clarification_desc') }}</p>
                </div>

                <div class="flex items-center gap-3 pt-2">
                    <button type="submit"
                            class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold rounded-lg transition">
                        {{ __('admin.save') }}
                    </button>
                    <a href="{{ route('admin.ai-config') }}"
                       class="px-4 py-2 text-sm font-medium text-gray-700 dark:text-gray-300 bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 rounded-lg transition">
                        {{ __('admin.ai_reset') }}
                    </a>
                </div>
            </form>
        </div>

        {{-- Configuration IA Blog par organisation --}}
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-5">
            <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300 uppercase tracking-wider mb-4">{{ __('admin.ai_blog_config') }}</h3>

            <div class="space-y-4">
                @foreach($organizations as $org)
                    @php $cfg = $blogConfigs[$org->id] ?? null; @endphp
                    <form method="POST" action="{{ route('admin.ai-config.blog') }}" class="border border-gray-200 dark:border-gray-700 rounded-lg p-4 space-y-3">
                        @csrf
                        <input type="hidden" name="organization_id" value="{{ $org->id }}">

                        <div class="flex items-center justify-between">
                            <h4 class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ $org->name }} <span class="text-gray-400 font-mono text-xs">({{ $org->slug }})</span></h4>
                        </div>

                        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                            <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                                <input type="checkbox" name="generate_enabled" value="1" @checked($cfg?->generate_enabled ?? true) class="rounded border-gray-300 dark:border-gray-600 text-indigo-600 focus:ring-indigo-500">
                                {{ __('admin.ai_blog_generation') }}
                            </label>

                            <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                                <input type="checkbox" name="correct_enabled" value="1" @checked($cfg?->correct_enabled ?? true) class="rounded border-gray-300 dark:border-gray-600 text-indigo-600 focus:ring-indigo-500">
                                {{ __('admin.ai_blog_correction') }}
                            </label>

                            <div>
                                <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">{{ __('admin.ai_blog_generate_limit') }}</label>
                                <input type="number" name="generate_limit" value="{{ old('generate_limit', $cfg?->generate_limit ?? 3) }}" min="1" max="100"
                                       class="w-full px-2 py-1.5 text-sm border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-indigo-500">
                            </div>

                            <div>
                                <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">{{ __('admin.ai_blog_correct_limit') }}</label>
                                <input type="number" name="correct_limit" value="{{ old('correct_limit', $cfg?->correct_limit ?? 3) }}" min="1" max="100"
                                       class="w-full px-2 py-1.5 text-sm border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-indigo-500">
                            </div>
                        </div>

                        <div class="flex justify-end">
                            <button type="submit"
                                    class="px-3 py-1.5 bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-semibold rounded-lg transition">
                                {{ __('admin.ai_save_for', ['name' => $org->name]) }}
                            </button>
                        </div>
                    </form>
                @endforeach
            </div>
        </div>

        {{-- Providers disponibles --}}
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-5">
            <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300 uppercase tracking-wider mb-4">{{ __('admin.ai_available_providers') }}</h3>

            @if(empty($providers))
                <div class="text-sm text-gray-500 dark:text-gray-400 bg-gray-50 dark:bg-gray-700/50 rounded-lg px-4 py-3">
                    {{ __('admin.ai_no_providers') }}
                </div>
            @else
                <div class="space-y-3">
                    @foreach($providers as $key => $info)
                        <div class="flex items-center justify-between bg-gray-50 dark:bg-gray-700/50 rounded-lg px-4 py-3">
                            <div class="flex items-center gap-3">
                                <span class="w-2 h-2 rounded-full {{ $defaultProvider === $key ? 'bg-green-500' : 'bg-gray-400' }}"></span>
                                <div>
                                    <p class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ $info['label'] }}</p>
                                    <p class="text-xs text-gray-500 dark:text-gray-400 font-mono">{{ __('admin.ai_model_colon') }} {{ $info['models'] ? implode(', ', array_values($info['models'])) : 'N/A' }}</p>
                                </div>
                            </div>
                            <span class="text-xs {{ $info['type'] === 'local' ? 'text-amber-600 dark:text-amber-400' : 'text-indigo-600 dark:text-indigo-400' }}">{{ $info['type'] === 'local' ? __('admin.ai_local') : __('admin.ai_cloud') }}</span>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- Note --}}
        <div class="text-xs text-gray-400 dark:text-gray-500 bg-gray-50 dark:bg-gray-800/50 rounded-lg px-4 py-3">
            <p class="font-medium mb-1">{{ __('admin.ai_how_it_works') }}</p>
            <p>{{ __('admin.ai_how_it_works_desc') }}</p>
            <p class="mt-1">{{ __('admin.ai_provider_priority') }}</p>
            <p class="mt-1">{{ __('admin.ai_blog_config_note') }}</p>
        </div>
    </div>
</x-admin-layout>
