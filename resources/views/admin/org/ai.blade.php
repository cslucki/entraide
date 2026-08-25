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

        {{-- TASK-1229 : credit IA par utilisateur — override d'Organization.
             Trois notions distinctes : cout fournisseur, budget Organization
             (ci-dessus, en monnaie), credit utilisateur (ici, en utilisations). --}}
        @php
            $platformValueLabel = ! $creditPlatform['free_enabled']
                ? __('admin.organization_ai_user_credit_platform_disabled')
                : ($creditPlatform['monthly_uses'] === null
                    ? __('admin.organization_ai_user_credit_platform_unlimited')
                    : trans_choice('admin.organization_ai_user_credit_platform_uses', (int) $creditPlatform['monthly_uses'], ['count' => number_format((int) $creditPlatform['monthly_uses'])]));
            $effectiveLabel = $creditPolicy->isUnlimited()
                ? __('admin.organization_ai_user_credit_platform_unlimited')
                : trans_choice('admin.organization_ai_user_credit_platform_uses', (int) $creditPolicy->monthlyUses, ['count' => number_format((int) $creditPolicy->monthlyUses)]);
        @endphp
        <section class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-6 space-y-5" data-ai-user-credit data-ai-user-credit-mode="{{ $creditMode }}" data-ai-user-credit-effective="{{ $creditPolicy->isUnlimited() ? 'unlimited' : $creditPolicy->monthlyUses }}">
            <div>
                <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ __('admin.organization_ai_user_credit_title') }}</h3>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">{{ __('admin.organization_ai_user_credit_description') }}</p>
                <p class="text-xs text-gray-600 dark:text-gray-300 mt-2" data-ai-user-credit-platform>{{ __('admin.organization_ai_user_credit_platform_value', ['value' => $platformValueLabel, 'percent' => $creditPlatform['alert_percent']]) }}</p>
                <p class="text-xs font-medium text-indigo-700 dark:text-indigo-300 mt-1" data-ai-user-credit-effective-label>{{ __('admin.organization_ai_user_credit_effective', ['value' => $effectiveLabel]) }}</p>
            </div>

            <form method="POST" action="{{ route('organization.admin.ai.user-credit.update', $organization) }}" class="space-y-4" x-data="{ mode: @js(old('user_credit_mode', $creditMode)) }" data-ai-user-credit-form>
                @csrf
                @method('PUT')

                <div class="space-y-2">
                    @foreach([\App\Models\OrganizationAiSetting::USER_CREDIT_MODE_PLATFORM => __('admin.organization_ai_user_credit_mode_platform'), \App\Models\OrganizationAiSetting::USER_CREDIT_MODE_CUSTOM => __('admin.organization_ai_user_credit_mode_custom'), \App\Models\OrganizationAiSetting::USER_CREDIT_MODE_UNLIMITED => __('admin.organization_ai_user_credit_mode_unlimited')] as $mode => $label)
                        <label class="flex items-center gap-2 cursor-pointer text-sm text-gray-700 dark:text-gray-300">
                            <input type="radio" name="user_credit_mode" value="{{ $mode }}" x-model="mode" class="w-4 h-4 text-indigo-600 border-gray-300 focus:ring-indigo-500" data-ai-user-credit-mode-input="{{ $mode }}">
                            <span>{{ $label }}</span>
                        </label>
                    @endforeach
                </div>

                <div x-show="mode === 'custom'" x-cloak>
                    <label for="ai-user-credit-uses" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('admin.organization_ai_user_credit_custom_label') }}</label>
                    <input id="ai-user-credit-uses" type="number" min="0" step="1" name="user_credit_monthly_uses" value="{{ old('user_credit_monthly_uses', $creditCustomUses) }}"
                        class="w-full max-w-xs px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm @error('user_credit_monthly_uses') border-red-500 @enderror" data-ai-user-credit-uses-input>
                    @error('user_credit_monthly_uses')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                </div>
                @error('user_credit_mode')<p class="text-xs text-red-500">{{ $message }}</p>@enderror

                <div class="flex flex-wrap items-center gap-3">
                    <button type="submit" class="px-5 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium rounded-lg transition">
                        {{ __('admin.organization_ai_user_credit_save') }}
                    </button>
                    <span class="text-xs text-gray-500 dark:text-gray-400" data-ai-user-credit-last-change>
                        @if($creditLastChange)
                            {{ __('admin.organization_ai_user_credit_last_change', ['author' => $creditLastChange->author?->name ?? __('admin.ai_monetization_history_author_unknown'), 'date' => $creditLastChange->created_at->format('d/m/Y H:i')]) }}
                        @endif
                    </span>
                </div>
            </form>

            <div data-ai-user-credit-near-limit>
                <h4 class="text-sm font-medium text-gray-900 dark:text-gray-100 mb-2">{{ __('admin.organization_ai_user_credit_near_limit_title') }}</h4>
                @if($creditPolicy->isUnlimited())
                    <p class="text-sm text-gray-400">{{ __('admin.organization_ai_user_credit_near_limit_unlimited') }}</p>
                @elseif($creditMembersNearLimit === [])
                    <p class="text-sm text-gray-400">{{ __('admin.organization_ai_user_credit_near_limit_empty') }}</p>
                @else
                    <ul class="divide-y divide-gray-100 dark:divide-gray-700 text-sm">
                        @foreach($creditMembersNearLimit as $member)
                            <li class="flex items-center justify-between gap-2 py-2" data-ai-user-credit-member data-ai-user-credit-member-used="{{ $member['used'] }}" data-ai-user-credit-member-blocked="{{ $member['blocked'] ? 1 : 0 }}">
                                <span class="text-gray-900 dark:text-gray-100">{{ $member['name'] }}</span>
                                <span class="flex items-center gap-2">
                                    <span class="font-mono text-xs text-gray-700 dark:text-gray-300">{{ __('ai.credit_used_of_quota', ['used' => number_format($member['used']), 'quota' => number_format($member['quota'])]) }}</span>
                                    @if($member['blocked'])
                                        <span class="rounded-full bg-red-100 px-2 py-0.5 text-[11px] font-semibold text-red-700 dark:bg-red-900/30 dark:text-red-300">{{ __('admin.organization_ai_user_credit_blocked_badge') }}</span>
                                    @else
                                        <span class="rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-semibold text-amber-700 dark:bg-amber-900/30 dark:text-amber-300">{{ __('admin.organization_ai_user_credit_alert_badge') }}</span>
                                    @endif
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @endif
                <p class="text-[11px] text-gray-400 dark:text-gray-500 mt-3">{{ __('admin.organization_ai_user_credit_sandbox_note') }}</p>
            </div>
        </section>
    </div>
</x-org-admin-layout>
