<x-admin-layout :title="__('admin.crm_overview_title')">
    {{-- TASK-1425 — CRM-15 : agregats par Organization (MASTER Q37, option a). Aucun contenu de tenant. --}}
    <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">{{ __('admin.crm_overview_hint', ['days' => $idleDays, 'recent' => $recentDays]) }}</p>

    <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-7 gap-3 mb-6" data-crm-global-totals>
        @foreach(['contacts' => 'indigo', 'contactable' => 'emerald', 'blocked' => 'orange', 'today' => 'sky', 'overdue' => 'red', 'idle' => 'amber', 'recent_facts' => 'violet'] as $key => $tone)
        <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-4" data-crm-total="{{ $key }}" data-crm-value="{{ $totals[$key] }}">
            <div class="text-xs text-gray-500 dark:text-gray-400">{{ __('admin.crm_overview_'.$key) }}</div>
            <div class="text-2xl font-bold text-gray-900 dark:text-gray-100">{{ $totals[$key] }}</div>
        </div>
        @endforeach
    </div>

    <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 dark:bg-gray-700">
                <tr>
                    @foreach(['organization', 'contacts', 'contactable', 'blocked', 'today', 'overdue', 'idle', 'by_status', 'recent_facts', 'last_activity'] as $col)
                    <th class="px-3 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide whitespace-nowrap">{{ __('admin.crm_overview_'.$col) }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                @foreach($rows as $row)
                <tr data-crm-org="{{ $row['organization']->slug }}" data-crm-contacts="{{ $row['contacts'] }}" data-crm-contactable="{{ $row['contactable'] }}" data-crm-blocked="{{ $row['blocked'] }}" data-crm-today="{{ $row['today'] }}" data-crm-overdue="{{ $row['overdue'] }}" data-crm-idle="{{ $row['idle'] }}" data-crm-recent="{{ $row['recent_facts'] }}" class="{{ $row['contacts'] === 0 ? 'text-gray-400' : '' }}">
                    <td class="px-3 py-2 whitespace-nowrap">
                        <div class="font-medium text-gray-900 dark:text-gray-100">{{ $row['organization']->name }}</div>
                        {{-- Le SuperAdmin bascule vers le cockpit de l'Organization : c'est LA que se lit le detail. --}}
                        <a href="{{ route('organization.admin.crm.today', ['organization' => $row['organization']->slug]) }}" class="text-xs text-indigo-600 dark:text-indigo-400 hover:underline">{{ __('admin.crm_overview_open_cockpit') }}</a>
                    </td>
                    <td class="px-3 py-2 font-semibold">{{ $row['contacts'] }}</td>
                    <td class="px-3 py-2">{{ $row['contactable'] }}</td>
                    <td class="px-3 py-2 {{ $row['blocked'] > 0 ? 'text-orange-600 dark:text-orange-400' : '' }}">{{ $row['blocked'] }}</td>
                    <td class="px-3 py-2">{{ $row['today'] }}</td>
                    <td class="px-3 py-2 {{ $row['overdue'] > 0 ? 'text-red-600 dark:text-red-400 font-semibold' : '' }}">{{ $row['overdue'] }}</td>
                    <td class="px-3 py-2">{{ $row['idle'] }}</td>
                    <td class="px-3 py-2">
                        @if($row['by_status']->isEmpty() && $row['unassigned'] === 0)
                            <span class="text-gray-400">—</span>
                        @else
                        <div class="flex flex-wrap gap-1">
                            @foreach($row['by_status'] as $status)
                            <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-xs bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-200 {{ $status['is_active'] ? '' : 'opacity-60' }}" data-crm-status-label="{{ $status['label'] }}" data-crm-status-count="{{ $status['count'] }}">
                                <span class="w-2 h-2 rounded-full" style="background-color: {{ $status['color'] ?: '#9ca3af' }}"></span>{{ $status['label'] }} <strong>{{ $status['count'] }}</strong>
                            </span>
                            @endforeach
                            @if($row['unassigned'] > 0)<span class="px-1.5 py-0.5 rounded text-xs bg-gray-100 dark:bg-gray-700 text-gray-500">{{ __('admin.crm_overview_unassigned') }} <strong>{{ $row['unassigned'] }}</strong></span>@endif
                        </div>
                        @endif
                    </td>
                    <td class="px-3 py-2">{{ $row['recent_facts'] }}</td>
                    <td class="px-3 py-2 whitespace-nowrap text-xs text-gray-500 dark:text-gray-400">{{ $row['last_activity_at']?->format('d/m/Y H:i') ?? '—' }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</x-admin-layout>
