<x-org-admin-layout :title="__('crm.email.title', ['name' => $contact->fullName])" :organization="$organization">
    @include('admin.org.crm._email')
</x-org-admin-layout>
