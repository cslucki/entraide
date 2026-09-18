<x-org-admin-layout :title="$contact->fullName !== '' ? $contact->fullName : __('crm.show.untitled')" :organization="$organization">
    {{-- TASK-1431 — la fiche vit dans le partial `_contact` (partage avec la plateforme). --}}
    @include('admin.org.crm._contact')
</x-org-admin-layout>
