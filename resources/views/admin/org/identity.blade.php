<x-org-admin-layout :title="__('admin.organization_identity')" :organization="$organization">
    <div class="mb-6">
        <h2 class="text-2xl font-bold text-gray-900 dark:text-gray-100">{{ __('admin.organization_identity') }}</h2>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">{{ __('admin.organization_identity_description') }}</p>
    </div>

    @if(session('success'))
        <div class="mb-4 rounded-lg bg-green-50 px-4 py-3 text-sm text-green-800 dark:bg-green-900/20 dark:text-green-200 border border-green-200 dark:border-green-900">
            {{ session('success') }}
        </div>
    @endif

    @if(session('info'))
        <div class="mb-4 rounded-lg bg-blue-50 px-4 py-3 text-sm text-blue-800 dark:bg-blue-900/20 dark:text-blue-200 border border-blue-200 dark:border-blue-900">
            {{ session('info') }}
        </div>
    @endif

    @if ($errors->any())
        <div class="mb-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-800 dark:bg-red-900/20 dark:text-red-200 border border-red-200 dark:border-red-900">
            <ul class="list-disc list-inside">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="max-w-xl">
        <form method="POST" action="{{ route('organization.admin.identity.update', $organization) }}" enctype="multipart/form-data" class="space-y-6">
            @csrf

            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-6 space-y-4">
                <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300 uppercase tracking-wide">{{ __('admin.organization_logo') }}</h3>

                <div>
                    @if($organization->logo_url)
                    <div class="mb-3">
                        <img src="{{ $organization->logo_url }}" alt="{{ $organization->name }}" class="h-20 w-20 rounded-xl object-cover border border-gray-200 dark:border-gray-600">
                    </div>
                    @endif
                    <input type="file" name="logo" accept="image/png,image/jpg,image/jpeg,image/webp"
                        class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm file:mr-3 file:py-1 file:px-3 file:rounded-lg file:border-0 file:text-sm file:font-medium file:bg-indigo-50 dark:file:bg-indigo-900/30 file:text-indigo-700 dark:file:text-indigo-300 @error('logo') border-red-500 @enderror">
                    @error('logo')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                    @if($organization->logo_path)
                    <label class="flex items-center gap-2 mt-3 cursor-pointer">
                        <input type="checkbox" name="remove_logo" value="1" class="w-4 h-4 text-red-600 rounded border-gray-300 focus:ring-red-500">
                        <span class="text-xs text-red-600 dark:text-red-400 font-medium">{{ __('admin.organization_remove_logo') }}</span>
                    </label>
                    @endif
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">{{ __('admin.organization_logo_hint') }}</p>
                </div>
            </div>

            <div class="flex items-center gap-3">
                <button type="submit" class="px-5 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium rounded-lg transition">
                    {{ __('admin.organization_save') }}
                </button>
                <a href="{{ route('organization.admin.dashboard', $organization) }}" class="px-5 py-2 border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 text-sm font-medium rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition">
                    {{ __('admin.organization_cancel') }}
                </a>
            </div>
        </form>
    </div>
</x-org-admin-layout>
