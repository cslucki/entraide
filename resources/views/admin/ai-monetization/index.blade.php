{{--
    « Monetisation IA » — SuperAdmin (TASK-1229).

    Le parametre PLATEFORME du credit IA par utilisateur : IA gratuite,
    quota gratuit par utilisateur (UTILISATIONS / mois — jamais des dollars :
    un credit commercial n'est pas un cout, et un appel au cout non mesurable
    reste une utilisation), alerte a N %, blocage a 100 %, a quota atteint :
    proposer un abonnement (page d'information, sans paiement).

    Puis, par Organization : override eventuel, credit effectif (cascade),
    utilisateurs IA du mois, combien approchent leur seuil, combien sont au
    plafond. Des COMPTES — jamais un nom de membre, jamais un contenu tenant.

    Toute ecriture est tracee (auteur, horodatage) : la derniere modification
    et l'historique sont affiches.
--}}
@php
    $effectiveLabel = static function (\App\Support\Ai\AiUserCreditPolicy $policy): string {
        return $policy->isUnlimited()
            ? __('admin.ai_monetization_effective_unlimited')
            : trans_choice('admin.ai_monetization_effective_uses', (int) $policy->monthlyUses, ['count' => number_format((int) $policy->monthlyUses)]);
    };
    $overrideLabel = static function (string $mode, ?int $uses): string {
        return match ($mode) {
            \App\Models\OrganizationAiSetting::USER_CREDIT_MODE_UNLIMITED => __('admin.ai_monetization_override_unlimited'),
            \App\Models\OrganizationAiSetting::USER_CREDIT_MODE_CUSTOM => __('admin.ai_monetization_override_custom', ['count' => number_format((int) $uses)]),
            default => __('admin.ai_monetization_override_platform'),
        };
    };
    $changeLabel = static function (array $changes): string {
        $parts = [];
        foreach ($changes as $field => $delta) {
            // « ∞ » = illimite, seulement pour le quota plateforme ; pour la
            // valeur propre d'Organization, null = « pas de valeur » (—).
            $format = static fn ($v): string => $v === null
                ? ($field === 'monthly_uses' ? '∞' : '—')
                : (is_bool($v) ? ($v ? 'on' : 'off') : (string) $v);
            $parts[] = $field.' : '.$format($delta['from'] ?? null).' → '.$format($delta['to'] ?? null);
        }

        return implode(' · ', $parts);
    };
@endphp

<x-admin-layout>
    <x-slot name="title">{{ __('admin.ai_monetization_title') }}</x-slot>

    <div class="space-y-6" data-ai-monetization>
        <div>
            <h1 class="text-2xl font-bold dark:text-white">{{ __('admin.ai_monetization_title') }}</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1 max-w-3xl">{{ __('admin.ai_monetization_description') }}</p>
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">{{ __('ai.economy_period_label', ['from' => $period->from->format('d/m/Y'), 'to' => $period->to->subSecond()->format('d/m/Y')]) }}</p>
        </div>

        @if(session('success'))
            <div class="rounded-lg bg-green-50 px-4 py-3 text-sm text-green-800 dark:bg-green-900/20 dark:text-green-200 border border-green-200 dark:border-green-900" data-ai-monetization-saved>
                {{ session('success') }}
            </div>
        @endif

        @if ($errors->any())
            <div class="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-800 dark:bg-red-900/20 dark:text-red-200 border border-red-200 dark:border-red-900">
                <ul class="list-disc list-inside">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            {{-- Formulaire plateforme --}}
            <form method="POST" action="{{ route('admin.ai-monetization.update') }}" class="lg:col-span-2 bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-6 space-y-5" data-ai-monetization-form>
                @csrf

                <label class="flex items-start gap-3 cursor-pointer">
                    <input type="hidden" name="free_enabled" value="0">
                    <input type="checkbox" name="free_enabled" value="1" @checked(old('free_enabled', $platform['free_enabled'])) class="mt-1 w-4 h-4 text-indigo-600 rounded border-gray-300 focus:ring-indigo-500" data-ai-monetization-free-enabled>
                    <span>
                        <span class="block text-sm font-medium text-gray-900 dark:text-gray-100">{{ __('admin.ai_monetization_free_enabled') }}</span>
                        <span class="block text-xs text-gray-500 dark:text-gray-400">{{ __('admin.ai_monetization_free_enabled_help') }}</span>
                    </span>
                </label>

                <div>
                    <label for="monetization-monthly-uses" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('admin.ai_monetization_monthly_uses') }}</label>
                    <input id="monetization-monthly-uses" type="number" min="0" step="1" name="monthly_uses" value="{{ old('monthly_uses', $platform['monthly_uses']) }}"
                        class="w-full max-w-xs px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm @error('monthly_uses') border-red-500 @enderror" data-ai-monetization-monthly-uses>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">{{ __('admin.ai_monetization_monthly_uses_help') }}</p>
                    @error('monthly_uses')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label for="monetization-alert-percent" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('admin.ai_monetization_alert_percent') }}</label>
                    <input id="monetization-alert-percent" type="number" min="1" max="99" step="1" name="alert_percent" value="{{ old('alert_percent', $platform['alert_percent']) }}" required
                        class="w-full max-w-xs px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm @error('alert_percent') border-red-500 @enderror" data-ai-monetization-alert-percent>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">{{ __('admin.ai_monetization_alert_percent_help') }}</p>
                    @error('alert_percent')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                </div>

                <div>
                    <p class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('admin.ai_monetization_block_at') }}</p>
                    <p class="text-sm text-gray-900 dark:text-gray-100" data-ai-monetization-block-at>{{ __('admin.ai_monetization_block_at_value') }}</p>
                </div>

                <label class="flex items-start gap-3 cursor-pointer">
                    <input type="hidden" name="offer_subscription" value="0">
                    <input type="checkbox" name="offer_subscription" value="1" @checked(old('offer_subscription', $platform['offer_subscription'])) class="mt-1 w-4 h-4 text-indigo-600 rounded border-gray-300 focus:ring-indigo-500" data-ai-monetization-offer-subscription>
                    <span>
                        <span class="block text-sm font-medium text-gray-900 dark:text-gray-100">{{ __('admin.ai_monetization_offer_subscription') }}</span>
                        <span class="block text-xs text-gray-500 dark:text-gray-400">{{ __('admin.ai_monetization_offer_subscription_help') }}</span>
                    </span>
                </label>

                <div class="flex items-center gap-4 pt-2">
                    <button type="submit" class="px-5 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium rounded-lg transition">
                        {{ __('admin.organization_save') }}
                    </button>
                    <span class="text-xs text-gray-500 dark:text-gray-400" data-ai-monetization-last-change>
                        @if($lastChange)
                            {{ __('admin.ai_monetization_last_change', ['author' => $lastChange->author?->name ?? __('admin.ai_monetization_history_author_unknown'), 'date' => $lastChange->created_at->format('d/m/Y H:i')]) }}
                        @else
                            {{ __('admin.ai_monetization_no_change') }}
                        @endif
                    </span>
                </div>
            </form>

            {{-- Historique --}}
            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-6" data-ai-monetization-history>
                <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100 mb-3">{{ __('admin.ai_monetization_history_title') }}</h2>
                @if($history->isEmpty())
                    <p class="text-sm text-gray-400">{{ __('admin.ai_monetization_history_empty') }}</p>
                @else
                    <ul class="space-y-3 text-xs">
                        @foreach($history as $change)
                            <li class="border-l-2 border-indigo-200 dark:border-indigo-800 pl-3" data-ai-monetization-history-row>
                                <div class="text-gray-900 dark:text-gray-100 font-medium">
                                    {{ $change->scope === \App\Models\AiCreditSettingChange::SCOPE_PLATFORM ? __('admin.ai_monetization_history_platform') : ($change->organization?->name ?? '—') }}
                                </div>
                                <div class="text-gray-600 dark:text-gray-300 font-mono break-words">{{ $changeLabel($change->changes ?? []) }}</div>
                                <div class="text-gray-400 mt-0.5">{{ $change->author?->name ?? __('admin.ai_monetization_history_author_unknown') }} · {{ $change->created_at->format('d/m/Y H:i') }}</div>
                            </li>
                        @endforeach
                    </ul>
                @endif
                <p class="text-[11px] text-gray-400 dark:text-gray-500 mt-4">{{ __('admin.ai_monetization_sandbox_note') }}</p>
            </div>
        </div>

        {{-- Par Organization --}}
        <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden" data-ai-monetization-organizations>
            <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ __('admin.ai_monetization_organizations_title') }}</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-900/40 text-xs uppercase text-gray-500 dark:text-gray-400">
                        <tr>
                            <th class="px-4 py-3 text-left">{{ __('admin.ai_monetization_col_organization') }}</th>
                            <th class="px-4 py-3 text-left">{{ __('admin.ai_monetization_col_override') }}</th>
                            <th class="px-4 py-3 text-left">{{ __('admin.ai_monetization_col_effective') }}</th>
                            <th class="px-4 py-3 text-right">{{ __('admin.ai_monetization_col_ai_users') }}</th>
                            <th class="px-4 py-3 text-right">{{ __('admin.ai_monetization_col_alerting') }}</th>
                            <th class="px-4 py-3 text-right">{{ __('admin.ai_monetization_col_blocked') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        @foreach($rows as $row)
                            <tr data-ai-monetization-organization="{{ $row['slug'] }}" data-ai-monetization-alerting="{{ $row['alerting_count'] }}" data-ai-monetization-blocked="{{ $row['blocked_count'] }}" data-ai-monetization-effective="{{ $row['policy']->isUnlimited() ? 'unlimited' : $row['policy']->monthlyUses }}">
                                <td class="px-4 py-3 text-gray-900 dark:text-gray-100 font-medium">{{ $row['name'] }}</td>
                                <td class="px-4 py-3 text-gray-600 dark:text-gray-400">{{ $overrideLabel($row['override_mode'], $row['override_uses']) }}</td>
                                <td class="px-4 py-3 text-gray-900 dark:text-gray-100">
                                    {{ $effectiveLabel($row['policy']) }}
                                    <span class="text-xs text-gray-400">· {{ $row['policy']->source === $sourcePlatform ? __('ai.credit_source_platform') : __('admin.ai_monetization_col_override') }}</span>
                                </td>
                                <td class="px-4 py-3 text-right font-mono text-xs">{{ number_format($row['ai_users_count']) }}</td>
                                <td class="px-4 py-3 text-right font-mono text-xs {{ $row['alerting_count'] > 0 ? 'text-amber-700 dark:text-amber-300' : '' }}">{{ number_format($row['alerting_count']) }}</td>
                                <td class="px-4 py-3 text-right font-mono text-xs {{ $row['blocked_count'] > 0 ? 'text-red-600 dark:text-red-400' : '' }}">{{ number_format($row['blocked_count']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-admin-layout>
