<x-org-admin-layout :title="__('crm.templates.title')" :organization="$organization">
    {{-- TASK-1420 — CRM-7a : les modeles d'email de l'Organization. --}}
    <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">{{ __('crm.templates.title') }}</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">{{ __('crm.templates.subtitle') }}</p>
        </div>
        <a href="{{ route('organization.admin.crm.templates.create', ['organization' => $organization->slug]) }}" data-crm-template-new class="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm hover:bg-indigo-700">{{ __('crm.templates.new') }}</a>
    </div>

    <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-x-auto">
        <table class="w-full text-sm" data-crm-templates-table>
            <thead class="bg-gray-50 dark:bg-gray-700">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('crm.templates.name') }}</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('crm.templates.subject') }}</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('crm.templates.sent_count') }}</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('crm.column.actions') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                @forelse($templates as $template)
                <tr data-crm-template="{{ $template->id }}">
                    <td class="px-4 py-3"><div class="font-medium text-gray-900 dark:text-gray-100">{{ $template->name }}</div><div class="text-xs text-gray-400 font-mono">{{ $template->slug }}</div></td>
                    <td class="px-4 py-3 text-xs text-gray-500">{{ $template->subject }}</td>
                    <td class="px-4 py-3 text-xs text-gray-500">{{ $template->logs_count }}</td>
                    <td class="px-4 py-3 whitespace-nowrap">
                        <a href="{{ route('organization.admin.crm.templates.edit', ['organization' => $organization->slug, 'template' => $template->id]) }}" class="px-2 py-1 text-xs rounded border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 mr-1">{{ __('crm.templates.edit') }}</a>
                        <a href="{{ route('organization.admin.crm.templates.preview', ['organization' => $organization->slug, 'template' => $template->id]) }}" data-crm-template-preview class="px-2 py-1 text-xs rounded border border-indigo-300 dark:border-indigo-700 text-indigo-700 dark:text-indigo-300 hover:bg-indigo-50 dark:hover:bg-indigo-900/30">{{ __('crm.templates.preview') }}</a>
                    </td>
                </tr>
                @empty
                <tr><td colspan="4" class="px-4 py-8 text-center text-sm text-gray-400" data-crm-templates-empty>{{ __('crm.templates.empty') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-org-admin-layout>
