<x-org-admin-layout :title="__('crm.templates.preview_title', ['name' => $template->name])" :organization="$organization">
    {{-- TASK-1420 — CRM-7a : apercu en lecture seule, avec un Contact d'exemple. Rien n'est ecrit, rien n'est envoye. --}}
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <a href="{{ route('organization.admin.crm.templates.edit', ['organization' => $organization->slug, 'template' => $template->id]) }}" class="text-sm text-indigo-600 dark:text-indigo-400 hover:underline">&larr; {{ __('crm.templates.edit') }}</a>
        <span class="text-xs text-gray-500 dark:text-gray-400" data-crm-template-preview-notice>{{ __('crm.templates.preview_notice') }}</span>
    </div>
    <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-5" data-crm-template-preview-body>
        <p class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('crm.templates.subject') }}</p>
        <p class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4" data-crm-template-preview-subject>{{ $subject }}</p>
        <div class="prose prose-sm dark:prose-invert max-w-none border-t border-gray-200 dark:border-gray-700 pt-4">{!! $html !!}</div>
    </div>
</x-org-admin-layout>
