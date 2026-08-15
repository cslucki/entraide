<x-org-admin-layout :title="__('admin.organization_ai')" :organization="$organization">
    @php
        $hasKey = $setting?->hasCredential() ?? false;
        $keyless = ($setting?->provider ?? 'openrouter') === 'ollama';
        $ready = $setting !== null && $setting->isUsable() && ($hasKey || $keyless);
    @endphp

    <div class="mb-6">
        <h2 class="text-2xl font-bold text-gray-900 dark:text-gray-100">{{ __('admin.organization_ai') }}</h2>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">{{ __('admin.organization_ai_description') }}</p>
    </div>

    @if(session('success'))
        <div class="mb-4 rounded-lg bg-green-50 px-4 py-3 text-sm text-green-800 dark:bg-green-900/20 dark:text-green-200 border border-green-200 dark:border-green-900" data-ai-settings-saved>
            {{ session('success') }}
        </div>
    @endif

    @if ($errors->any())
        <div class="mb-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-800 dark:bg-red-900/20 dark:text-red-200 border border-red-200 dark:border-red-900">
            <ul class="list-disc list-inside">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="max-w-xl space-y-6">
        {{-- Etat : jamais la cle elle-meme, seulement son existence. --}}
        <div class="rounded-xl border p-4 text-sm {{ $ready ? 'border-green-200 bg-green-50 text-green-800 dark:border-green-900 dark:bg-green-900/20 dark:text-green-200' : 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-900 dark:bg-amber-900/20 dark:text-amber-200' }}" data-ai-settings-status="{{ $ready ? 'ready' : 'not-ready' }}">
            <p class="font-semibold">{{ __('admin.organization_ai_status') }}</p>
            <p class="mt-1">{{ $ready ? __('admin.organization_ai_status_ready') : __('admin.organization_ai_status_not_ready') }}</p>
            <p class="mt-2 text-xs opacity-80">
                {{ __('admin.organization_ai_monthly_cost') }} : {{ number_format($monthlyCost, 4, ',', ' ') }} USD
                @if($setting?->monthly_budget_usd !== null)
                    / {{ number_format((float) $setting->monthly_budget_usd, 2, ',', ' ') }} USD
                @endif
            </p>
        </div>

        <form method="POST" action="{{ route('organization.admin.ai.update', $organization) }}" class="space-y-6" autocomplete="off">
            @csrf
            @method('PUT')

            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-6 space-y-5">
                <div>
                    <label for="ai-provider" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('admin.organization_ai_provider') }}</label>
                    <select id="ai-provider" name="provider" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm">
                        @foreach($providers as $provider)
                            <option value="{{ $provider }}" @selected(old('provider', $setting?->provider ?? 'openrouter') === $provider)>{{ $provider }}</option>
                        @endforeach
                    </select>
                    @error('provider')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label for="ai-model" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('admin.organization_ai_model') }}</label>
                    <input id="ai-model" type="text" name="model" value="{{ old('model', $setting?->model ?? $defaultModel) }}" required maxlength="150"
                        class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm @error('model') border-red-500 @enderror">
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">{{ __('admin.organization_ai_model_help', ['model' => $defaultModel]) }}</p>
                    @error('model')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label for="ai-api-key" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('admin.organization_ai_api_key') }}</label>
                    <p class="text-xs mb-2 {{ $hasKey ? 'text-green-700 dark:text-green-300' : 'text-amber-700 dark:text-amber-300' }}" data-ai-api-key-state="{{ $hasKey ? 'set' : 'not-set' }}">
                        @if($hasKey)
                            {{ __('admin.organization_ai_api_key_set') }}
                            @if($setting?->api_key_updated_at)
                                · {{ __('admin.organization_ai_api_key_updated_at', ['date' => $setting->api_key_updated_at->isoFormat('LLL')]) }}
                            @endif
                        @else
                            {{ __('admin.organization_ai_api_key_not_set') }}
                        @endif
                    </p>
                    {{-- Ecriture seule : jamais de value, jamais old(). --}}
                    <input id="ai-api-key" type="password" name="api_key" value="" maxlength="500" autocomplete="new-password"
                        class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm @error('api_key') border-red-500 @enderror">
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">{{ __('admin.organization_ai_api_key_help') }} {{ __('admin.organization_ai_keyless_provider') }}</p>
                    @error('api_key')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                    @if($hasKey)
                        <label class="flex items-center gap-2 mt-3 cursor-pointer">
                            <input type="checkbox" name="clear_api_key" value="1" class="w-4 h-4 text-red-600 rounded border-gray-300 focus:ring-red-500">
                            <span class="text-xs text-red-600 dark:text-red-400 font-medium">{{ __('admin.organization_ai_clear_api_key') }}</span>
                        </label>
                    @endif
                </div>

                <div>
                    <label for="ai-monthly-budget" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('admin.organization_ai_monthly_budget') }}</label>
                    <input id="ai-monthly-budget" type="number" step="0.01" min="0" name="monthly_budget_usd" value="{{ old('monthly_budget_usd', $setting?->monthly_budget_usd) }}"
                        class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm @error('monthly_budget_usd') border-red-500 @enderror">
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">{{ __('admin.organization_ai_monthly_budget_help') }}</p>
                    @error('monthly_budget_usd')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                </div>

                <label class="flex items-center gap-2 cursor-pointer">
                    <input type="hidden" name="is_enabled" value="0">
                    <input type="checkbox" name="is_enabled" value="1" @checked(old('is_enabled', $setting?->is_enabled ?? true)) class="w-4 h-4 text-indigo-600 rounded border-gray-300 focus:ring-indigo-500">
                    <span class="text-sm text-gray-700 dark:text-gray-300">{{ __('admin.organization_ai_enabled') }}</span>
                </label>
            </div>

            <div class="flex items-center gap-3">
                <button type="submit" class="px-5 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium rounded-lg transition">
                    {{ __('admin.organization_save') }}
                </button>
                <a href="{{ route('organization.admin.dashboard', $organization) }}" class="px-5 py-2 border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 text-sm font-medium rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition">
                    {{ __('admin.organization_cancel') }}
                </a>
            </div>
        </form>
    </div>
</x-org-admin-layout>
