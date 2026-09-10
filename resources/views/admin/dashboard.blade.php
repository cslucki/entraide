<x-admin-layout :title="__('dashboard.platform_title')">
    {{-- TASK-1507 — le tableau de bord SuperAdmin, toutes Organizations
         confondues. Sept compteurs plats et deux listes devenaient huit tuiles
         qui portent CE QUE Cyril a demande : depense IA, Shell Welcome,
         connexions jour/semaine/mois, comptes qui se connectent le plus,
         interactions IA.

         Pas de `var(--bp-*)` : `layouts/admin` n'emet aucun jeton de theme
         (TASK-1506, mesure). La palette du superadmin, c'est `indigo`. --}}
    @php
        $ai = $metrics['ai'];
        $shell = $metrics['shell'];
        $logins = $metrics['logins'];
        $counts = $metrics['counts'];

        $money = fn (?float $v) => $v === null ? null : number_format($v, $v < 1 ? 4 : 2, ',', ' ').' $';

        $tiles = [
            ['key' => 'organizations', 'value' => $counts['organizations'], 'label' => __('dashboard.platform_stat_organizations'), 'sub' => __('dashboard.platform_stat_organizations_sub', ['active' => $counts['organizations_active']]), 'tone' => 'text-indigo-600 dark:text-indigo-400', 'url' => route('admin.organizations')],
            ['key' => 'users', 'value' => number_format($counts['users'], 0, ',', ' '), 'label' => __('dashboard.platform_stat_users'), 'sub' => null, 'tone' => 'text-blue-600 dark:text-blue-400', 'url' => route('admin.users')],
            ['key' => 'services', 'value' => number_format($counts['services'], 0, ',', ' '), 'label' => __('dashboard.platform_stat_services'), 'sub' => null, 'tone' => 'text-emerald-600 dark:text-emerald-400', 'url' => route('admin.users')],
            ['key' => 'transactions', 'value' => number_format($counts['transactions'], 0, ',', ' '), 'label' => __('dashboard.platform_stat_transactions'), 'sub' => $counts['transactions_pending'] > 0 ? __('dashboard.platform_stat_transactions_sub', ['pending' => $counts['transactions_pending']]) : null, 'tone' => 'text-teal-600 dark:text-teal-400', 'url' => null],
            ['key' => 'ai_spend', 'value' => $money($ai['spend_usd']) ?? '—', 'label' => __('dashboard.platform_stat_ai_spend'), 'tone' => 'text-rose-600 dark:text-rose-400', 'url' => route('admin.ia-usage'),
             'sub' => $ai['spend_usd'] === null
                ? __('dashboard.platform_stat_ai_spend_none')
                : trim(($ai['spend_unattributed_usd'] !== null ? __('dashboard.platform_stat_ai_spend_unattributed', ['amount' => $money($ai['spend_unattributed_usd'])]) : '')
                    .($ai['spend_unknown'] > 0 ? ($ai['spend_unattributed_usd'] !== null ? ' · ' : '').__('dashboard.platform_stat_ai_spend_unknown', ['count' => $ai['spend_unknown']]) : ''))],
            ['key' => 'ai_interactions', 'value' => number_format($ai['interactions'], 0, ',', ' '), 'label' => __('dashboard.platform_stat_ai_interactions'), 'sub' => $metrics['month_label'], 'tone' => 'text-violet-600 dark:text-violet-400', 'url' => route('admin.ai-interactions')],
            ['key' => 'shell_visitors', 'value' => number_format($shell['visitors'], 0, ',', ' '), 'label' => __('dashboard.platform_stat_shell_visitors'), 'sub' => __('dashboard.platform_stat_shell_visitors_sub', ['conversations' => $shell['conversations'], 'accounts' => $shell['accounts_claimed']]), 'tone' => 'text-cyan-600 dark:text-cyan-400', 'url' => route('admin.guest-shell')],
            ['key' => 'logins', 'value' => number_format($logins['today'], 0, ',', ' '), 'label' => __('dashboard.platform_stat_logins'), 'sub' => __('dashboard.platform_stat_logins_sub', ['week' => $logins['week'], 'month' => $logins['month']]), 'tone' => 'text-amber-600 dark:text-amber-400', 'url' => route('admin.stats.login-history')],
        ];

        $attentionItems = collect([
            ['key' => 'pending_reports', 'label' => __('dashboard.platform_attention_pending_reports'), 'url' => route('admin.reports')],
            ['key' => 'banned_users', 'label' => __('dashboard.platform_attention_banned_users'), 'url' => route('admin.users', ['status' => 'banned'])],
            ['key' => 'inactive_organizations', 'label' => __('dashboard.platform_attention_inactive_organizations'), 'url' => route('admin.organizations')],
        ])->filter(fn ($i) => ($metrics['attention'][$i['key']] ?? 0) > 0);
    @endphp

    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">{{ __('dashboard.platform_title') }}</h1>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">{{ __('dashboard.platform_subtitle') }}</p>
    </div>

    @if($attentionItems->isNotEmpty())
        <div class="mb-6 rounded-2xl border border-amber-200 dark:border-amber-500/30 bg-amber-50 dark:bg-amber-500/10 p-4" data-platform-attention>
            <p class="text-[11px] font-semibold uppercase tracking-wider text-amber-800 dark:text-amber-200">{{ __('dashboard.platform_attention') }}</p>
            <div class="mt-2 grid grid-cols-1 gap-2 md:grid-cols-3">
                @foreach($attentionItems as $item)
                    <a href="{{ $item['url'] }}"
                       class="flex items-center justify-between gap-3 min-h-[56px] rounded-xl bg-white/70 dark:bg-gray-800/70 border border-amber-200 dark:border-amber-500/30 px-3 py-2 hover:bg-white dark:hover:bg-gray-800 transition"
                       data-platform-attention-item="{{ $item['key'] }}" data-count="{{ $metrics['attention'][$item['key']] }}">
                        <span class="text-sm text-gray-800 dark:text-gray-100 leading-snug">{{ $item['label'] }}</span>
                        <span class="text-lg font-bold text-amber-700 dark:text-amber-300">{{ $metrics['attention'][$item['key']] }}</span>
                    </a>
                @endforeach
            </div>
        </div>
    @else
        <p class="mb-6 text-sm text-gray-500 dark:text-gray-400 rounded-2xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 px-4 py-3" data-platform-attention-none>{{ __('dashboard.platform_attention_none') }}</p>
    @endif

    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 sm:gap-4 mb-6" data-platform-tiles>
        @foreach($tiles as $tile)
            @php $inner = 'block rounded-2xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-4 min-h-[92px]'; @endphp
            <{{ $tile['url'] ? 'a' : 'div' }} @if($tile['url']) href="{{ $tile['url'] }}" @endif
                class="{{ $inner }} {{ $tile['url'] ? 'hover:border-gray-300 dark:hover:border-gray-600 transition' : '' }}"
                data-platform-tile="{{ $tile['key'] }}">
                <p class="text-2xl font-bold {{ $tile['tone'] }}">{{ $tile['value'] }}</p>
                <p class="text-xs text-gray-600 dark:text-gray-300 mt-1 leading-snug">{{ $tile['label'] }}</p>
                @if(!empty($tile['sub']))<p class="text-[11px] leading-snug text-gray-400 dark:text-gray-500 mt-0.5">{{ $tile['sub'] }}</p>@endif
            </{{ $tile['url'] ? 'a' : 'div' }}>
        @endforeach
    </div>

    <div class="grid lg:grid-cols-2 gap-6 mb-6">
        <div class="bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 overflow-hidden" data-platform-top-logins>
            <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700 flex items-center justify-between gap-3">
                <h2 class="font-semibold text-gray-900 dark:text-gray-100">{{ __('dashboard.platform_top_logins') }}</h2>
                <a href="{{ route('admin.stats.login-history') }}" class="text-xs text-indigo-600 dark:text-indigo-400 hover:underline flex-shrink-0">{{ __('dashboard.platform_see_all') }}</a>
            </div>
            <div class="divide-y divide-gray-100 dark:divide-gray-700">
                @forelse($metrics['top_logins'] as $row)
                    <div class="px-5 py-3 flex items-center gap-3" data-platform-top-login>
                        <img src="{{ $row['user']->avatar_url }}" class="w-8 h-8 rounded-full flex-shrink-0" alt="">
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-medium text-gray-900 dark:text-gray-100 truncate">{{ $row['user']->full_name }}</p>
                            {{-- Toutes organisations confondues : sans le tenant, un nom ne situe rien. --}}
                            <p class="text-xs text-gray-500 dark:text-gray-400 truncate">{{ $row['organization']?->name ?? __('dashboard.platform_no_organization') }}</p>
                        </div>
                        <span class="text-sm font-bold text-gray-700 dark:text-gray-200 flex-shrink-0">{{ $row['logins'] }}</span>
                    </div>
                @empty
                    <p class="px-5 py-8 text-sm text-gray-400 text-center">{{ __('dashboard.platform_top_logins_none') }}</p>
                @endforelse
            </div>
        </div>

        <div class="bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 overflow-hidden" data-platform-top-organizations>
            <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700 flex items-center justify-between gap-3">
                <h2 class="font-semibold text-gray-900 dark:text-gray-100">{{ __('dashboard.platform_top_organizations') }}</h2>
                <a href="{{ route('admin.ia-usage') }}" class="text-xs text-indigo-600 dark:text-indigo-400 hover:underline flex-shrink-0">{{ __('dashboard.platform_see_all') }}</a>
            </div>
            <div class="divide-y divide-gray-100 dark:divide-gray-700">
                @forelse($metrics['top_organizations'] as $row)
                    <div class="px-5 py-3 flex items-center gap-3" data-platform-top-organization>
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-medium text-gray-900 dark:text-gray-100 truncate">{{ $row['organization']->name }}</p>
                            <p class="text-xs text-gray-500 dark:text-gray-400">{{ $row['interactions'] }} · {{ $row['organization']->slug }}</p>
                        </div>
                        <span class="text-sm font-bold text-rose-600 dark:text-rose-400 flex-shrink-0">{{ $money($row['spend_usd']) }}</span>
                    </div>
                @empty
                    <p class="px-5 py-8 text-sm text-gray-400 text-center">{{ __('dashboard.platform_top_organizations_none') }}</p>
                @endforelse
            </div>
        </div>
    </div>

    <div class="grid lg:grid-cols-2 gap-6">
        <div class="bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 overflow-hidden">
            <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700 flex items-center justify-between gap-3">
                <h2 class="font-semibold text-gray-900 dark:text-gray-100">{{ __('dashboard.platform_recent_users') }}</h2>
                <a href="{{ route('admin.users') }}" class="text-xs text-indigo-600 dark:text-indigo-400 hover:underline flex-shrink-0">{{ __('dashboard.platform_see_all') }}</a>
            </div>
            <div class="divide-y divide-gray-100 dark:divide-gray-700">
                @foreach($recentUsers as $u)
                    <div class="px-5 py-3 flex items-center gap-3">
                        <img src="{{ $u->avatar_url }}" class="w-8 h-8 rounded-full flex-shrink-0" alt="">
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-medium text-gray-900 dark:text-gray-100 truncate">{{ $u->full_name }}</p>
                            <p class="text-xs text-gray-500 dark:text-gray-400 truncate">{{ $u->organization?->name ?? __('dashboard.platform_no_organization') }}</p>
                        </div>
                        @if($u->banned_at)
                            <span class="text-xs bg-red-100 dark:bg-red-900 text-red-600 dark:text-red-300 px-2 py-0.5 rounded flex-shrink-0">{{ __('dashboard.platform_attention_banned_users') }}</span>
                        @endif
                        <span class="text-xs text-gray-400 flex-shrink-0">{{ $u->created_at->diffForHumans() }}</span>
                    </div>
                @endforeach
            </div>
        </div>

        <div class="bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 overflow-hidden">
            <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700 flex items-center justify-between gap-3">
                <h2 class="font-semibold text-gray-900 dark:text-gray-100">{{ __('dashboard.platform_pending_reports') }}</h2>
                <a href="{{ route('admin.reports') }}" class="text-xs text-indigo-600 dark:text-indigo-400 hover:underline flex-shrink-0">{{ __('dashboard.platform_see_all') }}</a>
            </div>
            <div class="divide-y divide-gray-100 dark:divide-gray-700">
                @forelse($pendingReports as $report)
                    <div class="px-5 py-3">
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <p class="text-sm text-gray-900 dark:text-gray-100">
                                    <span class="font-medium">{{ $report->reporter->full_name }}</span> · {{ $report->reason }}
                                </p>
                                <p class="text-xs text-gray-500 truncate">{{ $report->reportable_type === 'App\Models\Service' ? 'Service' : 'Utilisateur' }}</p>
                            </div>
                            <div class="flex gap-2 flex-shrink-0">
                                <form method="POST" action="{{ route('admin.reports.review', $report) }}">
                                    @csrf @method('PATCH')
                                    <button class="text-xs text-green-600 hover:underline min-h-[40px]">Traité</button>
                                </form>
                                <form method="POST" action="{{ route('admin.reports.dismiss', $report) }}">
                                    @csrf @method('PATCH')
                                    <button class="text-xs text-gray-400 hover:text-red-500 min-h-[40px]">Ignorer</button>
                                </form>
                            </div>
                        </div>
                    </div>
                @empty
                    <p class="px-5 py-8 text-sm text-gray-400 text-center">Aucun signalement en attente.</p>
                @endforelse
            </div>
        </div>
    </div>
</x-admin-layout>
