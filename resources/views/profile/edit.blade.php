<x-app-layout>
    <x-slot name="title">{{ __('profile.edit_title') }}</x-slot>

    <x-page-container>
        <h1 class="text-3xl font-bold text-gray-900 dark:text-gray-100 mb-8">{{ __('profile.edit_title') }}</h1>

        @include('partials.profile-reminder')

        <div class="space-y-6">
            <div class="p-4 sm:p-8 bg-white dark:bg-gray-800 shadow sm:rounded-lg">
                <div class="max-w-xl">
                    @include('profile.partials.update-profile-information-form')
                </div>
            </div>

            <div class="p-4 sm:p-8 bg-white dark:bg-gray-800 shadow sm:rounded-lg">
                <div class="max-w-xl">
                    @include('profile.partials.update-password-form')
                </div>
            </div>

            <div class="p-4 sm:p-8 bg-white dark:bg-gray-800 shadow sm:rounded-lg">
                <div class="max-w-xl">
                    @include('profile.partials.delete-user-form')
                </div>
            </div>
        </div>
    </x-page-container>
</x-app-layout>
