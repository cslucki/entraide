<x-org-admin-layout :title="__('dashboard.org_admin_dashboard', ['organization' => $organization->name])" :organization="$organization">
    {{-- TASK-1504 — le tableau de bord qui dit quoi faire.
         Audit TASK-1501 : quatre chiffres sans lien, une liste sans action, aucune
         cible interactive. Ici chaque tuile est un lien vers sa page, le bandeau
         « A traiter » n'apparait que s'il y a quelque chose, et tout vient de
         `OrganizationDashboardMetrics` — la vue n'additionne rien. --}}
    @php
        $org = $organization->slug;
        $r = fn (string $name, array $extra = []) => route('organization.admin.'.$name, ['organization' => $org] + $extra);
        $m = $metrics;
        $tiles = [
            ['key' => 'users',        'value' => $m['counts']['users'],    'label' => __('dashboard.org_admin_stat_users'),    'url' => $r('users'),    'tone' => 'text-indigo-600 dark:text-indigo-400'],
            ['key' => 'loops',        'value' => $m['counts']['loops'],    'label' => __('dashboard.org_admin_stat_loops'),    'url' => $r('loops'),    'tone' => 'text-blue-600 dark:text-blue-400'],
            ['key' => 'services',     'value' => $m['counts']['services'], 'label' => __('dashboard.org_admin_stat_services'), 'url' => $r('services'), 'tone' => 'text-green-600 dark:text-green-400'],
            ['key' => 'requests',     'value' => $m['counts']['requests'], 'label' => __('dashboard.org_admin_stat_requests'), 'url' => $r('requests'), 'tone' => 'text-orange-500 dark:text-orange-400'],
            // « Aucune mesure » n'est pas « 0,00 $ » : un tiret, et la raison en sous-titre.
            ['key' => 'ai_spend',     'value' => $m['ai']['spend_usd'] === null ? '—' : number_format($m['ai']['spend_usd'], 2).' $', 'label' => __('dashboard.org_admin_stat_ai_spend'), 'sub' => $m['ai']['spend_usd'] === null ? __('dashboard.org_admin_stat_ai_spend_none') : ($m['ai']['spend_unknown'] > 0 ? __('dashboard.org_admin_stat_ai_spend_unknown', ['count' => $m['ai']['spend_unknown']]) : $m['month_label']), 'url' => $r('ai-consumption'), 'tone' => 'text-rose-600 dark:text-rose-400'],
            ['key' => 'ai_interactions', 'value' => $m['ai']['interactions'], 'label' => __('dashboard.org_admin_stat_ai_interactions'), 'sub' => $m['month_label'], 'url' => $r('ai-quality'), 'tone' => 'text-violet-600 dark:text-violet-400'],
            ['key' => 'shell_visitors', 'value' => $m['shell']['visitors'], 'label' => __('dashboard.org_admin_stat_shell_visitors'), 'sub' => __('dashboard.org_admin_stat_shell_sub', ['conversations' => $m['shell']['conversations'], 'accounts' => $m['shell']['accounts_claimed']]), 'url' => $r('ai-cockpit'), 'tone' => 'text-teal-600 dark:text-teal-400'],
            ['key' => 'logins',       'value' => $m['logins']['today'], 'label' => __('dashboard.org_admin_stat_logins'), 'sub' => __('dashboard.org_admin_stat_logins_sub', ['week' => $m['logins']['week'], 'month' => $m['logins']['month']]), 'url' => $r('stats.login-history'), 'tone' => 'text-sky-600 dark:text-sky-400'],
        ];
        $attention = [
            'open_requests'       => ['label' => __('dashboard.org_admin_attention_open_requests'),       'url' => $r('requests', ['status' => 'open'])],
            'pending_invitations' => ['label' => __('dashboard.org_admin_attention_pending_invitations'), 'url' => $r('invitations')],
            'pending_reports'     => ['label' => __('dashboard.org_admin_attention_pending_reports'),     'url' => $r('reports')],
            'upcoming_sessions'   => ['label' => __('dashboard.org_admin_attention_upcoming_sessions'),   'url' => $r('workshops')],
        ];
    @endphp

    <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="text-xl font-bold text-gray-900 dark:text-gray-100">{{ __('dashboard.org_admin_header', ['organization' => $organization->name]) }}</h2>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">{{ __('dashboard.org_admin_subtitle') }}</p>
        </div>
        <div class="flex flex-wrap gap-2" data-dashboard-actions>
            <a href="{{ $r('invitations') }}" class="inline-flex items-center gap-2 min-h-[40px] px-4 rounded-lg bg-indigo-600 text-white text-sm font-semibold hover:bg-indigo-700 shadow-sm">{{ __('dashboard.org_admin_action_invite') }}</a>
            <a href="{{ $r('ai-cockpit') }}" class="inline-flex items-center gap-2 min-h-[40px] px-4 rounded-lg border border-gray-300 dark:border-gray-600 text-sm font-medium text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-800">{{ __('dashboard.org_admin_action_ai_cockpit') }}</a>
        </div>
    </div>

    {{-- A traiter : masque quand il n'y a rien — un bandeau de zeros n'est pas une information. --}}
    @if($m['attention']['total'] > 0)
    <section class="mb-6 rounded-xl border border-amber-300 dark:border-amber-500/40 bg-amber-50 dark:bg-amber-500/10 p-4" data-dashboard-attention data-dashboard-attention-total="{{ $m['attention']['total'] }}">
        <h3 class="text-xs font-semibold uppercase tracking-wide text-amber-800 dark:text-amber-200 mb-3">{{ __('dashboard.org_admin_attention_title') }}</h3>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-2">
            @foreach($attention as $key => $item)
                @if($m['attention'][$key] > 0)
                <a href="{{ $item['url'] }}" class="flex items-center justify-between gap-3 min-h-[44px] rounded-lg bg-white dark:bg-gray-800 border border-amber-200 dark:border-amber-500/30 px-3 py-2 hover:border-amber-400" data-dashboard-attention-item="{{ $key }}" data-count="{{ $m['attention'][$key] }}">
                    <span class="text-sm text-gray-800 dark:text-gray-100">{{ $item['label'] }}</span>
                    <span class="text-lg font-bold tabular-nums text-amber-700 dark:text-amber-300">{{ $m['attention'][$key] }}</span>
                </a>
                @endif
            @endforeach
        </div>
    </section>
    @else
    <p class="mb-6 rounded-xl border border-dashed border-gray-200 dark:border-gray-700 px-4 py-3 text-sm text-gray-500 dark:text-gray-400" data-dashboard-attention-none>{{ __('dashboard.org_admin_attention_none') }}</p>
    @endif

    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 sm:gap-4 mb-8" data-dashboard-tiles>
        @foreach($tiles as $tile)
        <a href="{{ $tile['url'] }}" class="block bg-white dark:bg-gray-800 rounded-xl p-4 border border-gray-200 dark:border-gray-700 hover:border-indigo-300 dark:hover:border-indigo-500/50 hover:shadow-sm transition min-h-[92px]" data-dashboard-tile="{{ $tile['key'] }}">
            <p class="text-2xl font-bold tabular-nums leading-none {{ $tile['tone'] }}">{{ $tile['value'] }}</p>
            <p class="text-xs font-medium text-gray-700 dark:text-gray-200 mt-2">{{ $tile['label'] }}</p>
            {{-- Le sous-titre porte une information (7 j / 30 j, conversations) : il se replie, il ne se coupe pas. Mesure a 390 : deux sous-titres tronques avec `truncate`. --}}
            @if(!empty($tile['sub']))<p class="text-[11px] leading-snug text-gray-400 dark:text-gray-500 mt-0.5">{{ $tile['sub'] }}</p>@endif
        </a>
        @endforeach
    </div>

    <div class="grid lg:grid-cols-2 gap-6">
        <section class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden" data-dashboard-top-logins>
            <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700 flex items-center justify-between">
                <h2 class="font-semibold text-gray-900 dark:text-gray-100">{{ __('dashboard.org_admin_top_logins') }}</h2>
                <a href="{{ $r('stats.login-history') }}" class="text-xs text-indigo-600 dark:text-indigo-400 hover:underline">{{ __('dashboard.org_admin_see_all') }}</a>
            </div>
            <div class="divide-y divide-gray-100 dark:divide-gray-700">
                @forelse($m['top_logins'] as $row)
                <div class="px-5 py-3 flex items-center gap-3 min-h-[56px]" data-dashboard-top-login="{{ $row['user']->id }}">
                    <img src="{{ $row['user']->avatar_url }}" class="w-9 h-9 rounded-full flex-shrink-0" alt="">
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-medium text-gray-900 dark:text-gray-100 truncate">{{ $row['user']->full_name }}</p>
                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('dashboard.org_admin_last_login', ['when' => $row['last_login_at']->diffForHumans()]) }}</p>
                    </div>
                    <span class="text-sm font-bold tabular-nums text-gray-900 dark:text-gray-100">{{ $row['logins'] }}</span>
                </div>
                @empty
                <p class="px-5 py-8 text-sm text-gray-400 text-center">{{ __('dashboard.org_admin_top_logins_none') }}</p>
                @endforelse
            </div>
        </section>

        <section class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden" data-dashboard-activity>
            <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700">
                <h2 class="font-semibold text-gray-900 dark:text-gray-100">{{ __('dashboard.org_admin_recent_activity') }}</h2>
            </div>
            <div class="divide-y divide-gray-100 dark:divide-gray-700">
                @forelse($m['activity'] as $item)
                <div class="px-5 py-3 flex items-center gap-3 min-h-[56px]" data-dashboard-activity-item="{{ $item['kind'] }}">
                    @if($item['user'])<img src="{{ $item['user']->avatar_url }}" class="w-9 h-9 rounded-full flex-shrink-0" alt="">@else<span class="w-9 h-9 rounded-full bg-gray-100 dark:bg-gray-700 flex-shrink-0"></span>@endif
                    <div class="min-w-0 flex-1">
                        <p class="text-sm text-gray-900 dark:text-gray-100 truncate">
                            @if($item['kind'] === 'member')
                                {{ __('dashboard.org_admin_activity_member_joined', ['name' => $item['title']]) }}
                            @else
                                <a href="{{ $item['url'] }}" class="hover:underline">{{ __('dashboard.org_admin_activity_request_opened', ['title' => $item['title']]) }}</a>
                            @endif
                        </p>
                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ $item['at']->diffForHumans() }}</p>
                    </div>
                </div>
                @empty
                <p class="px-5 py-8 text-sm text-gray-400 text-center">{{ __('dashboard.org_admin_no_users') }}</p>
                @endforelse
            </div>
        </section>
    </div>
</x-org-admin-layout>
