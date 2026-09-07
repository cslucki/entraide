<x-admin-layout :title="__('crm.email.title', ['name' => $contact->fullName]).' · '.$organization->name">
    @include('admin.crm._tabs')
    @include('admin.org.crm._email')
</x-admin-layout>
