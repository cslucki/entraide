<x-org-admin-layout :title="__('navigation.org_admin_translations')" :organization="$organization">
    <div class="mb-6">
        <h2 class="text-2xl font-bold text-gray-900 dark:text-gray-100">{{ __('navigation.org_admin_translations') }}</h2>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">{{ __('navigation.org_admin_translations_description') }}</p>
    </div>

    @if(session('success'))
        <div class="mb-4 rounded-lg bg-green-50 px-4 py-3 text-sm text-green-800 dark:bg-green-900/20 dark:text-green-200 border border-green-200 dark:border-green-900">
            {{ session('success') }}
        </div>
    @endif

    @if($errors->any())
        <div class="mb-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-800 dark:bg-red-900/20 dark:text-red-200 border border-red-200 dark:border-red-900">
            <ul class="list-disc list-inside">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800 p-6 mb-6">
        <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">{{ __('navigation.org_admin_translation_new') }}</h3>
        <form method="POST" action="{{ route('organization.admin.translations.store', ['organization' => $organization->slug]) }}" class="grid grid-cols-1 sm:grid-cols-6 gap-4">
            @csrf
            <div class="sm:col-span-1">
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('navigation.org_admin_translation_locale') }}</label>
                <select name="locale" required class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-300">
                    <option value="fr">FR</option>
                    <option value="en">EN</option>
                </select>
            </div>
            <div class="sm:col-span-1">
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('navigation.org_admin_translation_group') }}</label>
                <select name="group" required class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-300">
                    @foreach($groups as $group)
                        <option value="{{ $group }}">{{ $group }}</option>
                    @endforeach
                </select>
            </div>
            <div class="sm:col-span-1">
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('navigation.org_admin_translation_key') }}</label>
                <input type="text" name="key" required placeholder="my.key"
                       class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-300">
            </div>
            <div class="sm:col-span-2">
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('navigation.org_admin_translation_value') }}</label>
                <input type="text" name="value" required placeholder="Override value"
                       class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-300">
            </div>
            <div class="sm:col-span-1 flex items-end">
                <button type="submit" class="w-full rounded-lg bg-purple-600 px-4 py-2 text-sm font-medium text-white hover:bg-purple-700 transition">
                    {{ __('navigation.org_admin_translation_create') }}
                </button>
            </div>
        </form>
    </div>

    <div class="overflow-hidden rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 dark:bg-gray-700">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('navigation.org_admin_translation_locale') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('navigation.org_admin_translation_group') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('navigation.org_admin_translation_key') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('navigation.org_admin_translation_value') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('navigation.org_admin_translation_status') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('navigation.org_admin_translation_created_by') }}</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    @forelse($overrides as $override)
                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-750 {{ !$override->is_active ? 'opacity-60' : '' }}">
                        <td class="px-4 py-3 font-mono text-xs text-gray-700 dark:text-gray-300">{{ $override->locale }}</td>
                        <td class="px-4 py-3 font-mono text-xs text-gray-600 dark:text-gray-400">{{ $override->group }}</td>
                        <td class="px-4 py-3 font-mono text-xs text-gray-900 dark:text-gray-100">{{ $override->key }}</td>
                        <td class="max-w-xs truncate px-4 py-3 text-sm text-gray-700 dark:text-gray-300" title="{{ $override->value }}">{{ $override->value }}</td>
                        <td class="px-4 py-3">
                            @if($override->is_active)
                                <span class="inline-flex items-center rounded-full bg-green-100 px-2 py-0.5 text-xs font-medium text-green-800 dark:bg-green-900 dark:text-green-200">{{ __('navigation.org_admin_translation_active') }}</span>
                            @else
                                <span class="inline-flex items-center rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-600 dark:bg-gray-700 dark:text-gray-400">{{ __('navigation.org_admin_translation_inactive') }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-xs text-gray-500 dark:text-gray-400">
                            {{ $override->createdBy?->name ?? '—' }}
                        </td>
                        <td class="px-4 py-3 text-right">
                            @if($override->is_active)
                                <form method="POST" action="{{ route('organization.admin.translations.deactivate', ['organization' => $organization->slug, 'translationOverride' => $override]) }}" class="inline">
                                    @csrf
                                    @method('PATCH')
                                    <button type="submit" onclick="return confirm('{{ __('navigation.org_admin_translation_deactivate_confirm') }}')"
                                            class="text-sm font-medium text-red-600 hover:text-red-800 dark:text-red-400 dark:hover:text-red-300 transition">
                                        {{ __('navigation.org_admin_translation_deactivate') }}
                                    </button>
                                </form>
                            @endif
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="7" class="px-4 py-8 text-center text-gray-500 dark:text-gray-400">{{ __('navigation.org_admin_translation_no_overrides') }}</td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
        {{ trans_choice('navigation.org_admin_translation_count', count($overrides)) }}
    </p>
</x-org-admin-layout>