<x-admin-layout :title="__('admin.guest_shell_observability_title')">
    {{-- TASK-1438 — SW-10 : observabilite plateforme du Shell Welcome (Shell Welcome V3 §17). Jamais un contenu de conversation. --}}
    @php
        $cost = static fn (?float $value): string => $value === null ? '—' : '$'.number_format($value, 4);
    @endphp
    <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">{{ __('admin.guest_shell_observability_title') }}</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">{{ __('admin.guest_shell_observability_hint') }}</p>
        </div>
        <form method="GET" action="{{ route('admin.guest-shell') }}" class="flex flex-wrap items-end gap-2 text-sm" data-guest-shell-filters>
            <label class="block"><span class="block text-xs text-gray-500 dark:text-gray-400 mb-1">{{ __('ai.consumption_console_from') }}</span><input type="date" name="from" value="{{ $filters->from->format('Y-m-d') }}" class="px-3 py-1.5 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100"></label>
            <label class="block"><span class="block text-xs text-gray-500 dark:text-gray-400 mb-1">{{ __('ai.consumption_console_to') }}</span><input type="date" name="to" value="{{ $filters->to->subSecond()->format('Y-m-d') }}" class="px-3 py-1.5 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100"></label>
            <button type="submit" class="px-4 py-1.5 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700">{{ __('ai.consumption_console_filter') }}</button>
            <a href="{{ route('admin.ai-config') }}" class="px-3 py-1.5 text-indigo-600 dark:text-indigo-400 hover:underline">{{ __('admin.guest_shell_observability_policies') }}</a>
        </form>
    </div>

    <section class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-3 mb-6" data-guest-shell-totals>
        @foreach([
            'known_cost_usd' => $cost($totals['known_cost_usd']),
            'cost_unknown' => $totals['cost_unknown'],
            'invocations' => $totals['invocations'],
            'failed' => $totals['failed'],
            'visitors' => $totals['visitors'],
            'conversations' => $totals['conversations'],
            'visitor_messages' => $totals['visitor_messages'],
            'accounts_claimed' => $totals['accounts_claimed'],
            'organizations_active' => $totals['organizations_active'].' / '.$totals['organizations_enabled'],
        ] as $key => $value)
        <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-4" data-guest-shell-total="{{ $key }}" data-guest-shell-value="{{ is_numeric($totals[$key] ?? null) ? $totals[$key] : '' }}">
            <div class="text-xs text-gray-500 dark:text-gray-400">{{ __('admin.guest_shell_metric_'.$key) }}</div>
            <div class="text-2xl font-bold tabular-nums text-gray-900 dark:text-gray-100">{{ $value }}</div>
        </div>
        @endforeach
    </section>
    <p class="text-xs text-gray-500 dark:text-gray-400 mb-4" data-guest-shell-period>{{ __('ai.economy_period_label', ['from' => $filters->from->format('d/m/Y'), 'to' => $filters->to->subSecond()->format('d/m/Y')]) }} — {{ __('admin.guest_shell_units_hint') }}</p>

    <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 dark:bg-gray-700">
                <tr>
                    @foreach(['organization', 'state', 'visitors', 'conversations', 'visitor_messages', 'invocations', 'failed', 'known_cost_usd', 'cost_unknown', 'cost_per_conversation', 'accounts_claimed'] as $col)
                    <th class="px-3 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide whitespace-nowrap">{{ __('admin.guest_shell_col_'.$col) }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                @forelse($organizations as $row)
                @php $u = $row['usage']; $st = strtolower($row['state']); @endphp
                <tr data-guest-shell-org="{{ $row['organization']->slug }}" data-guest-shell-state="{{ $st }}">
                    <td class="px-3 py-2 whitespace-nowrap font-medium text-gray-900 dark:text-gray-100">{{ $row['organization']->name }}</td>
                    <td class="px-3 py-2 whitespace-nowrap">
                        <span class="px-2 py-0.5 rounded text-xs font-semibold {{ match($st) { 'active' => 'bg-emerald-100 dark:bg-emerald-900/40 text-emerald-800 dark:text-emerald-300', 'disabled' => 'bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300', default => 'bg-amber-100 dark:bg-amber-900/40 text-amber-800 dark:text-amber-300' } }}">{{ __('admin.guest_shell_state_'.$st) }}</span>
                        @if($row['reasons'] !== [] && $st !== 'active')<div class="text-[11px] text-gray-500 dark:text-gray-400 mt-0.5">{{ implode(', ', array_map(fn ($r) => __('admin.guest_shell_reason_'.$r), $row['reasons'])) }}</div>@endif
                    </td>
                    <td class="px-3 py-2 tabular-nums" data-guest-shell-cell="visitors">{{ $u['visitors'] }}</td>
                    <td class="px-3 py-2 tabular-nums" data-guest-shell-cell="conversations">{{ $u['conversations'] }}</td>
                    <td class="px-3 py-2 tabular-nums" data-guest-shell-cell="visitor_messages">{{ $u['visitor_messages'] }}</td>
                    <td class="px-3 py-2 tabular-nums" data-guest-shell-cell="invocations">{{ $u['invocations'] }} <span class="text-xs text-gray-400">({{ $u['success'] }} ✓)</span></td>
                    <td class="px-3 py-2 tabular-nums {{ $u['failed'] > 0 ? 'text-red-600 dark:text-red-400 font-semibold' : '' }}" data-guest-shell-cell="failed">{{ $u['failed'] }}</td>
                    <td class="px-3 py-2 tabular-nums" data-guest-shell-cell="known_cost_usd">{{ $cost($u['known_cost_usd']) }}</td>
                    <td class="px-3 py-2 tabular-nums {{ $u['cost_unknown'] > 0 ? 'text-amber-600 dark:text-amber-400 font-semibold' : '' }}" data-guest-shell-cell="cost_unknown">{{ $u['cost_unknown'] }}</td>
                    <td class="px-3 py-2 tabular-nums" data-guest-shell-cell="cost_per_conversation">{{ $cost($row['cost_per_conversation']) }}</td>
                    <td class="px-3 py-2 tabular-nums" data-guest-shell-cell="accounts_claimed">{{ $u['accounts_claimed'] }}</td>
                </tr>
                @empty
                <tr><td colspan="11" class="px-3 py-6 text-center text-sm text-gray-500" data-guest-shell-empty>{{ __('admin.guest_shell_observability_empty') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-admin-layout>
