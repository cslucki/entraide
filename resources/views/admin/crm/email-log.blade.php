<x-admin-layout :title="__('crm.email_history.reread_title').' · '.$organization->name">
    @include('admin.crm._tabs')
    @include('admin.org.crm._email-log')
</x-admin-layout>
