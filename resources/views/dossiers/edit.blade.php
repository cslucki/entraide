<x-app-layout>
    @php $organizationRouteParam = request()->route('organization'); @endphp

    <x-slot name="title">{{ __('dossiers.edit_title') }} — {{ $brandOrganizationName ?? 'BouclePro' }}</x-slot>

    <x-page-container>
        <div class="mx-auto max-w-2xl">
            <a href="{{ route('organization.dossiers.index', ['organization' => $organizationRouteParam]) }}" class="text-sm font-medium text-indigo-600 hover:underline dark:text-indigo-400">{{ __('dossiers.back') }}</a>

            <div class="mt-5 rounded-3xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-800 sm:p-8">
                <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">{{ __('dossiers.edit_title') }}</h1>
                <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">{{ __('dossiers.edit_help') }}</p>

                <form method="POST" action="{{ route('organization.dossiers.update', ['organization' => $organizationRouteParam, 'dossier' => $dossier->getKey()]) }}" class="mt-6 space-y-5">
                    @csrf
                    @method('PATCH')
                    <div>
                        <x-input-label for="name" :value="__('dossiers.name_label')" />
                        <x-text-input id="name" name="name" type="text" class="mt-1 block w-full" :value="old('name', $dossier->name)" required autofocus maxlength="120" />
                        <x-input-error :messages="$errors->get('name')" class="mt-2" />
                        <x-input-error :messages="$errors->get('owner_id')" class="mt-2" />
                    </div>

                    <div class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                        <a href="{{ route('organization.dossiers.index', ['organization' => $organizationRouteParam]) }}" class="inline-flex justify-center rounded-lg border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">{{ __('dossiers.cancel') }}</a>
                        <x-primary-button>{{ __('dossiers.save') }}</x-primary-button>
                    </div>
                </form>
            </div>
        </div>
    </x-page-container>
</x-app-layout>
