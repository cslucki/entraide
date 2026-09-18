{{--
    TASK-1500 — UNE ligne de politique Shell Welcome, pliable, editable.

    Rendue par /admin/ai-config (section « Shell Welcome par organisation ») ET
    par /admin/shell-welcome (decision Cyril 10/09 : le SuperAdmin regle depuis
    le cockpit aussi). Un seul formulaire, un seul endpoint
    (`admin.ai-config.guest-shell`), une seule autorite economique ; la page
    d'origine est rendue par `redirect_to`, verifie interne cote controleur.

    Entrees : $org (Organization), $state (GuestShellState), $redirectTo (?string).

    Tous les crochets `data-guest-shell-*` de TASK-1468/1470/1474 sont conserves
    a l'identique : c'est eux que les tests mesurent, pas les classes.
--}}
@php
    $policy = $state->policy;
    $diag = \App\Support\GuestShell\GuestShellDiagnosis::for($state);
    $diagTone = \App\Support\GuestShell\GuestShellDiagnosis::badgeClasses($diag['tone']);
    $needsAction = $diag['tone'] === \App\Support\GuestShell\GuestShellDiagnosis::TONE_ACTION;
    $isReady = $diag['tone'] === \App\Support\GuestShell\GuestShellDiagnosis::TONE_READY;
    $cost = (float) $state->monthlyUsage['cost_usd'];
    $ring = $needsAction
        ? 'border-amber-300/80 dark:border-amber-500/40 bg-amber-50/40 dark:bg-amber-500/5'
        : ($isReady ? 'border-emerald-200 dark:border-emerald-500/30' : 'border-gray-200 dark:border-gray-700');
@endphp
<details id="guest-shell-{{ $org->slug }}" class="group scroll-mt-24 rounded-xl border {{ $ring }} bg-white dark:bg-gray-800/60 open:shadow-sm transition" data-guest-shell-row="{{ $org->slug }}" @if($needsAction) open @endif>
    <summary class="flex flex-wrap items-center gap-x-3 gap-y-1.5 px-4 py-3 cursor-pointer select-none list-none [&::-webkit-details-marker]:hidden">
        <svg class="h-3.5 w-3.5 shrink-0 text-gray-400 transition group-open:rotate-90" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 5l7 7-7 7"/></svg>
        <span class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ $org->name }}</span>
        <span class="rounded-md bg-gray-100 dark:bg-gray-700/70 px-1.5 py-0.5 font-mono text-[11px] text-gray-500 dark:text-gray-400">{{ $org->slug }}</span>
        <span class="px-2 py-0.5 rounded-full text-[11px] font-semibold {{ $diagTone }}" data-guest-shell-diag="{{ $diag['key'] }}">{{ $diag['label'] }}</span>

        <span class="ml-auto flex flex-wrap items-center gap-x-2 gap-y-1 text-xs">
            <span class="inline-flex items-center gap-1.5 rounded-lg border border-gray-200 dark:border-gray-700 px-2 py-0.5 text-gray-600 dark:text-gray-300" data-guest-shell-summary-provider>{{ $state->providerLabel() ?? '—' }}</span>
            <span class="inline-flex items-center gap-1.5 rounded-lg border border-gray-200 dark:border-gray-700 px-2 py-0.5 text-gray-600 dark:text-gray-300" data-guest-shell-summary-mode>{{ __('admin.guest_shell_display_mode_'.$policy->display_mode) }}</span>
            <span class="min-w-[6.5rem] text-right font-semibold tabular-nums {{ $cost > 0 ? 'text-gray-900 dark:text-gray-100' : 'text-gray-400 dark:text-gray-500' }}" data-guest-shell-summary-cost>{{ number_format($cost, 4) }} USD</span>
        </span>
    </summary>

    <form method="POST" action="{{ route('admin.ai-config.guest-shell') }}" class="border-t border-gray-100 dark:border-gray-700/70 px-4 py-4 grid gap-5 lg:grid-cols-5" data-guest-shell-org="{{ $org->slug }}" data-guest-shell-status="{{ $state->status }}">
        @csrf
        <input type="hidden" name="organization_id" value="{{ $org->id }}">
        @if(! empty($redirectTo))<input type="hidden" name="redirect_to" value="{{ $redirectTo }}">@endif

        {{-- Colonne 1 : ce qu'il faut savoir avant de toucher — cause, geste, usage. --}}
        {{-- `lg:col-span-*` et non une grille arbitraire `[minmax…]` : celle-ci est
             absente du build (0 occurrence), donc une seule colonne, sans erreur. --}}
        <div class="space-y-4 min-w-0 lg:col-span-2">
            @if($diag['cause'] !== null || $diag['action'] !== null)
            <div class="rounded-lg {{ $needsAction ? 'bg-amber-50 dark:bg-amber-500/10' : 'bg-gray-50 dark:bg-gray-700/40' }} px-3 py-2.5 text-xs space-y-1" data-guest-shell-reasons>
                @if($diag['cause'] !== null)<p class="text-gray-600 dark:text-gray-300" data-guest-shell-diag-cause>{{ $diag['cause'] }}</p>@endif
                @if($diag['action'] !== null)<p class="font-semibold text-gray-900 dark:text-gray-100" data-guest-shell-diag-action>→ {{ $diag['action'] }}</p>@endif
                @if($diag['technical'] !== null)<p class="font-mono text-[11px] text-gray-400" data-guest-shell-diag-technical>{{ __('admin.guest_shell_diag_technical') }} {{ $diag['technical'] }}</p>@endif
            </div>
            @endif

            <dl class="grid grid-cols-2 gap-x-4 gap-y-3 text-xs" data-guest-shell-usage>
                <div><dt class="text-[11px] uppercase tracking-wide text-gray-400 dark:text-gray-500">{{ __('admin.guest_shell_provider') }}</dt><dd class="mt-0.5 text-gray-900 dark:text-gray-100">{{ $state->providerLabel() ?? '—' }}@if($state->setting) <span class="text-gray-400">· {{ $state->setting->credential_management_mode }}</span>@endif</dd></div>
                <div><dt class="text-[11px] uppercase tracking-wide text-gray-400 dark:text-gray-500">{{ __('admin.guest_shell_month_messages') }}</dt><dd class="mt-0.5 text-gray-900 dark:text-gray-100 tabular-nums">{{ $state->monthlyUsage['messages'] }}</dd></div>
                <div><dt class="text-[11px] uppercase tracking-wide text-gray-400 dark:text-gray-500">{{ __('admin.guest_shell_month_cost') }}</dt><dd class="mt-0.5 text-gray-900 dark:text-gray-100 tabular-nums">{{ number_format($cost, 4) }} USD @if($state->monthlyUsage['cost_unknown'] > 0) <span class="text-amber-600">(+{{ $state->monthlyUsage['cost_unknown'] }} {{ __('admin.guest_shell_unknown_cost') }})</span>@endif</dd></div>
                <div><dt class="text-[11px] uppercase tracking-wide text-gray-400 dark:text-gray-500">{{ __('admin.guest_shell_avg_cost') }}</dt><dd class="mt-0.5 text-gray-900 dark:text-gray-100 tabular-nums">{{ $state->averageCostPerMessage() === null ? '—' : number_format($state->averageCostPerMessage(), 4).' USD' }}</dd></div>
            </dl>
        </div>

        {{-- Colonne 2 : les reglages, l'activation en tete parce qu'elle commande tout le reste. --}}
        <div class="space-y-3 min-w-0 lg:col-span-3">
            <label class="flex items-center justify-between gap-3 rounded-lg border border-gray-200 dark:border-gray-700 px-3 py-2.5 cursor-pointer">
                <span class="text-sm font-medium text-gray-800 dark:text-gray-200">{{ __('admin.guest_shell_enabled') }}</span>
                <input type="hidden" name="enabled" value="0">
                <input type="checkbox" name="enabled" value="1" @checked($policy->enabled) class="h-4 w-4 rounded border-gray-300 dark:border-gray-600 text-indigo-600 focus:ring-indigo-500">
            </label>

            <div data-guest-shell-display-mode="{{ $policy->display_mode }}">
                <label class="block text-[11px] uppercase tracking-wide text-gray-400 dark:text-gray-500 mb-1">{{ __('admin.guest_shell_display_mode') }}</label>
                <select name="display_mode" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm">
                    @foreach(\App\Support\GuestShell\GuestShellDisplayMode::MODES as $mode)<option value="{{ $mode }}" @selected(old('display_mode', $policy->display_mode) === $mode)>{{ __('admin.guest_shell_display_mode_'.$mode) }}</option>@endforeach
                </select>
                <p class="text-[11px] text-gray-400 mt-1">{{ __('admin.guest_shell_display_mode_hint') }}</p>
            </div>

            <div class="grid grid-cols-3 gap-3">
                <div>
                    <label class="block text-[11px] uppercase tracking-wide text-gray-400 dark:text-gray-500 mb-1">{{ __('admin.guest_shell_max_messages') }}</label>
                    <input type="number" name="max_messages" min="1" max="{{ \App\Models\OrganizationGuestShellPolicy::MAX_MESSAGES_LIMIT }}" value="{{ old('max_messages', $policy->max_messages) }}" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm tabular-nums">
                </div>
                <div>
                    <label class="block text-[11px] uppercase tracking-wide text-gray-400 dark:text-gray-500 mb-1">{{ __('admin.guest_shell_retention_days') }}</label>
                    <input type="number" name="retention_days" min="1" max="{{ \App\Models\OrganizationGuestShellPolicy::RETENTION_DAYS_LIMIT }}" value="{{ old('retention_days', $policy->retention_days) }}" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm tabular-nums">
                </div>
                <div>
                    <label class="block text-[11px] uppercase tracking-wide text-gray-400 dark:text-gray-500 mb-1">{{ __('admin.guest_shell_budget') }}</label>
                    <input type="number" step="0.01" min="0" name="guest_monthly_budget_usd" value="{{ old('guest_monthly_budget_usd', $policy->guest_monthly_budget_usd) }}" placeholder="{{ __('admin.guest_shell_budget_placeholder') }}" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm tabular-nums">
                </div>
            </div>

            <div class="flex justify-end pt-1">
                <button type="submit" class="px-3.5 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-semibold rounded-lg shadow-sm shadow-indigo-600/20 transition">{{ __('admin.ai_save_for', ['name' => $org->name]) }}</button>
            </div>
        </div>
    </form>
</details>
