<x-app-layout>
    @php
        $_dashOrgSlug = $organization?->slug;
        $_dashRoute = function (string $name, array $params = []) use ($_dashOrgSlug): string {
            $orgRoute = 'organization.' . $name;
            return $_dashOrgSlug && Route::has($orgRoute)
                ? route($orgRoute, ['organization' => $_dashOrgSlug] + $params)
                : route($name, $params);
        };
    @endphp
    <div class="max-w-7xl mx-auto px-4 py-8">
        <div class="flex items-center justify-between mb-6">
            <div>
                <a href="{{ $_dashRoute('dashboard') }}" class="text-sm text-indigo-600 hover:underline">&larr; {{ __('dashboard.back_to_dashboard') }}</a>
                <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100 mt-2">{{ __('dashboard.my_services_page_title') }}</h1>
            </div>
            <a href="{{ $_dashRoute('services.create') }}" class="inline-flex items-center px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-lg hover:bg-indigo-700 transition">+ {{ __('dashboard.new') }}</a>
        </div>

        @if($services->isEmpty())
            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-12 text-center">
                <p class="text-gray-500 dark:text-gray-400">{{ __('dashboard.no_services') }}</p>
                <a href="{{ $_dashRoute('services.create') }}" class="mt-3 inline-block text-indigo-600 hover:underline text-sm font-medium">{{ __('dashboard.create_service_link') }}</a>
            </div>
        @else
            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-100 dark:border-gray-700 bg-gray-50 dark:bg-gray-900/50">
                                <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('dashboard.table_date') }}</th>
                                <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('dashboard.table_title') }}</th>
                                <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('dashboard.table_category') }}</th>
                                <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('dashboard.table_points') }}</th>
                                <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('dashboard.table_delivery_mode') }}</th>
                                <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('dashboard.table_attachments') }}</th>
                                <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('dashboard.table_respondents') }}</th>
                                <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ __('dashboard.table_action') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                            @foreach($services as $service)
                            <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/50 transition">
                                <td class="px-5 py-4 whitespace-nowrap text-gray-600 dark:text-gray-400">{{ $service->created_at->isoFormat('D MMM YYYY') }}</td>
                                <td class="px-5 py-4">
                                    <a href="{{ $_dashRoute('dashboard.services.detail', ['service' => $service]) }}" class="font-medium text-gray-900 dark:text-gray-100 hover:text-indigo-600">{{ $service->title }}</a>
                                </td>
                                <td class="px-5 py-4 whitespace-nowrap">
                                    @if($service->category)
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium" style="background-color: {{ $service->category->color ?? '#e5e7eb' }}20; color: {{ $service->category->color ?? '#6b7280' }}">{{ $service->category->name }}</span>
                                    @endif
                                </td>
                                <td class="px-5 py-4 whitespace-nowrap text-gray-600 dark:text-gray-400">{{ $service->points_cost }} pts</td>
                                <td class="px-5 py-4 whitespace-nowrap text-gray-600 dark:text-gray-400">{{ __("dashboard.delivery_{$service->delivery_mode}") }}</td>
                                <td class="px-5 py-4 whitespace-nowrap text-gray-600 dark:text-gray-400">{{ $service->images->isNotEmpty() ? __('dashboard.has_attachments') : __('dashboard.no_attachments') }}</td>
                                <td class="px-5 py-4 whitespace-nowrap text-gray-600 dark:text-gray-400">{{ trans_choice('dashboard.respondent_count', $service->transactions->count()) }}</td>
                                <td class="px-5 py-4 whitespace-nowrap">
                                    <div class="flex items-center gap-2">
                                        <a href="{{ $_dashRoute('dashboard.services.detail', ['service' => $service]) }}" class="text-indigo-600 hover:underline text-xs font-medium">{{ __('dashboard.view_all') }}</a>
                                        @can('update', $service)
                                        <a href="{{ $_dashRoute('services.edit', ['service' => $service]) }}" class="text-indigo-600 hover:underline text-xs font-medium">{{ __('dashboard.edit_service') }}</a>
                                        @endcan
                                    </div>
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="mt-6">
                {{ $services->links() }}
            </div>
        @endif
    </div>
</x-app-layout>