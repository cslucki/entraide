{{-- TASK-1427 — onglets du panneau CRM SuperAdmin. --}}
<nav class="mb-5 flex flex-wrap gap-2 text-sm" data-crm-admin-tabs>
    @foreach([['admin.crm.overview', __('admin.crm_tab_overview')], ['admin.crm.overview.today', __('admin.crm_tab_today')], ['admin.crm.overview.facts', __('admin.crm_tab_facts')]] as [$route, $label])
    <a href="{{ route($route) }}" class="px-3 py-1.5 rounded-lg border {{ request()->routeIs($route) ? 'bg-indigo-600 border-indigo-600 text-white' : 'border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700' }}">{{ $label }}</a>
    @endforeach
</nav>
