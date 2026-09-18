<x-admin-layout :title="($contact->fullName !== '' ? $contact->fullName : __('crm.show.untitled')).' · '.$organization->name">
    {{-- TASK-1431 — la fiche d'un Contact de N'IMPORTE quelle Organization, actions via /admin/relations/{organization}/… --}}
    @include('admin.crm._tabs')
    <p class="text-xs text-gray-500 dark:text-gray-400 mb-3" data-crm-admin-org="{{ $organization->slug }}">{{ __('admin.crm_overview_organization') }} : <span class="font-semibold text-gray-700 dark:text-gray-200">{{ $organization->name }}</span></p>
    @include('admin.org.crm._contact')
</x-admin-layout>
