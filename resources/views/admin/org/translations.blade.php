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

    {{-- Stats --}}
    <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-6">
        <a href="{{ route('organization.admin.translations', ['organization' => $organization->slug]) }}" class="block rounded-xl border border-gray-200 bg-white px-4 py-3 hover:border-gray-400 transition dark:border-gray-700 dark:bg-gray-800 dark:hover:border-gray-500">
            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('navigation.org_admin_translation_total') }}</p>
            <p class="mt-1 text-2xl font-bold text-gray-900 dark:text-gray-100">{{ $stats['total'] }}</p>
        </a>
        <a href="{{ route('organization.admin.translations', ['organization' => $organization->slug, 'status' => 'OK']) }}" class="block rounded-xl border border-green-200 bg-green-50 px-4 py-3 hover:border-green-400 transition dark:border-green-900 dark:bg-green-900/20 dark:hover:border-green-700">
            <p class="text-xs font-semibold uppercase tracking-wide text-green-700 dark:text-green-400">OK</p>
            <p class="mt-1 text-2xl font-bold text-green-800 dark:text-green-200">{{ $stats['ok'] }}</p>
        </a>
        <a href="{{ route('organization.admin.translations', ['organization' => $organization->slug, 'status' => 'MISSING_FR']) }}" class="block rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 hover:border-amber-400 transition dark:border-amber-900 dark:bg-amber-900/20 dark:hover:border-amber-700">
            <p class="text-xs font-semibold uppercase tracking-wide text-amber-700 dark:text-amber-400">FR {{ __('navigation.org_admin_translation_missing') }}</p>
            <p class="mt-1 text-2xl font-bold text-amber-800 dark:text-amber-200">{{ $stats['missing_fr'] }}</p>
        </a>
        <a href="{{ route('organization.admin.translations', ['organization' => $organization->slug, 'status' => 'MISSING_EN']) }}" class="block rounded-xl border border-red-200 bg-red-50 px-4 py-3 hover:border-red-400 transition dark:border-red-900 dark:bg-red-900/20 dark:hover:border-red-700">
            <p class="text-xs font-semibold uppercase tracking-wide text-red-700 dark:text-red-400">EN {{ __('navigation.org_admin_translation_missing') }}</p>
            <p class="mt-1 text-2xl font-bold text-red-800 dark:text-red-200">{{ $stats['missing_en'] }}</p>
        </a>
        <a href="{{ route('organization.admin.translations', ['organization' => $organization->slug, 'status' => 'OVERRIDDEN']) }}" class="block rounded-xl border border-purple-200 bg-purple-50 px-4 py-3 hover:border-purple-400 transition dark:border-purple-900 dark:bg-purple-900/20 dark:hover:border-purple-700">
            <p class="text-xs font-semibold uppercase tracking-wide text-purple-700 dark:text-purple-400">{{ __('navigation.org_admin_translation_overridden') }}</p>
            <p class="mt-1 text-2xl font-bold text-purple-800 dark:text-purple-200">{{ $stats['overridden'] }}</p>
        </a>
        <a href="{{ route('organization.admin.translations', ['organization' => $organization->slug]) }}" class="block rounded-xl border border-gray-200 bg-white px-4 py-3 hover:border-gray-400 transition dark:border-gray-700 dark:bg-gray-800 dark:hover:border-gray-500">
            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('navigation.org_admin_translation_remaining') }}</p>
            <p class="mt-1 text-2xl font-bold text-gray-900 dark:text-gray-100">{{ $stats['remaining'] }}</p>
        </a>
    </div>

    {{-- Override creation form --}}
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

    {{-- Filters --}}
    <div class="mb-4 flex flex-wrap items-center gap-3">
        <form method="GET" class="flex flex-wrap items-center gap-3">
            <select name="group" class="rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-700 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-300">
                <option value="_all" @selected($activeGroup === '_all')>{{ __('navigation.org_admin_translation_all_groups') }}</option>
                @foreach($groups as $g)
                    <option value="{{ $g }}" @selected($activeGroup === $g)>{{ $g }}</option>
                @endforeach
            </select>

            <select name="status" class="rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-700 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-300">
                <option value="_all" @selected($activeStatus === '_all')>{{ __('navigation.org_admin_translation_all_statuses') }}</option>
                <option value="OK" @selected($activeStatus === 'OK')>OK</option>
                <option value="MISSING_FR" @selected($activeStatus === 'MISSING_FR')>FR {{ __('navigation.org_admin_translation_missing') }}</option>
                <option value="MISSING_EN" @selected($activeStatus === 'MISSING_EN')>EN {{ __('navigation.org_admin_translation_missing') }}</option>
                <option value="OVERRIDDEN" @selected($activeStatus === 'OVERRIDDEN')>{{ __('navigation.org_admin_translation_overridden') }}</option>
            </select>

            <input type="text" name="search" value="{{ $search }}" placeholder="{{ __('navigation.org_admin_translation_search') }}"
                   class="rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-700 placeholder-gray-400 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-300 dark:placeholder-gray-500">

            <button type="submit" class="rounded-lg bg-purple-600 px-4 py-2 text-sm font-medium text-white hover:bg-purple-700 transition">{{ __('navigation.org_admin_filter') }}</button>

            @if(($activeGroup ?? '_all') !== '_all' || ($activeStatus ?? '_all') !== '_all' || $search)
                <a href="{{ route('organization.admin.translations', ['organization' => $organization->slug]) }}" class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-100 dark:border-gray-600 dark:text-gray-300 dark:hover:bg-gray-700 transition">{{ __('navigation.org_admin_clear') }}</a>
            @endif
        </form>
    </div>

    {{-- Entries table (hidden by default, toggled via Alpine) --}}
    <div x-data="{ showEntries: {{ $activeGroup !== '_all' || $activeStatus !== '_all' || $search ? 'true' : 'false' }} }" class="mb-6">
        <button @click="showEntries = !showEntries" class="mb-3 inline-flex items-center gap-2 text-sm font-medium text-gray-600 hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-100 transition">
            <template x-if="showEntries">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
            </template>
            <template x-if="!showEntries">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
            </template>
            <span x-text="showEntries ? '{{ __('navigation.org_admin_translation_hide_entries', ['count' => $entries->count()]) }}' : '{{ __('navigation.org_admin_translation_show_entries', ['count' => $entries->count()]) }}'"></span>
        </button>

        <div x-show="showEntries" x-cloak>
            <div class="overflow-hidden rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 dark:bg-gray-700">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('navigation.org_admin_translation_group') }}</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('navigation.org_admin_translation_key') }}</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">FR</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">EN</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('navigation.org_admin_translation_status') }}</th>
                                <th class="px-4 py-3 text-center text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('navigation.org_admin_translation_action') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                            @forelse($entries as $entry)
                            <tr x-data="{ showModal: false }"
                                class="hover:bg-gray-50 dark:hover:bg-gray-750 {{ $entry['status'] !== 'OK' ? 'bg-amber-50/50 dark:bg-amber-900/10' : '' }}">
                                <td class="px-4 py-2 font-mono text-xs text-gray-600 dark:text-gray-400">{{ $entry['group'] }}</td>
                                <td class="px-4 py-2 font-mono text-xs text-gray-900 dark:text-gray-100">{{ $entry['key'] }}</td>
                                <td class="px-4 py-2 text-sm max-w-xs truncate
                                    @php $hasFr = isset($overrides["{$entry['group']}.{$entry['key']}:fr"]) @endphp
                                    {{ $hasFr ? 'text-purple-600 dark:text-purple-400 font-medium' : '' }}
                                    {{ !$hasFr && is_array($entry['fr']) ? 'text-gray-400 italic' : '' }}
                                    {{ !$hasFr && !is_array($entry['fr']) && $entry['fr'] === null ? 'text-red-500 italic' : '' }}">
                                    @if($hasFr)
                                        <span title="{{ __('navigation.org_admin_translation_overridden') }}">{{ $overrides["{$entry['group']}.{$entry['key']}:fr"]->value }}</span>
                                        <span class="text-xs text-purple-400">(override)</span>
                                    @elseif(is_array($entry['fr']))
                                        <span class="text-xs text-gray-400 dark:text-gray-500">{{ __('navigation.org_admin_translation_nested') }}</span>
                                    @else
                                        {{ $entry['fr'] ?? '—' }}
                                    @endif
                                </td>
                                <td class="px-4 py-2 text-sm max-w-xs truncate
                                    @php $hasEn = isset($overrides["{$entry['group']}.{$entry['key']}:en"]) @endphp
                                    {{ $hasEn ? 'text-purple-600 dark:text-purple-400 font-medium' : '' }}
                                    {{ !$hasEn && is_array($entry['en']) ? 'text-gray-400 italic' : '' }}
                                    {{ !$hasEn && !is_array($entry['en']) && $entry['en'] === null ? 'text-red-500 italic' : '' }}">
                                    @if($hasEn)
                                        <span title="{{ __('navigation.org_admin_translation_overridden') }}">{{ $overrides["{$entry['group']}.{$entry['key']}:en"]->value }}</span>
                                        <span class="text-xs text-purple-400">(override)</span>
                                    @elseif(is_array($entry['en']))
                                        <span class="text-xs text-gray-400 dark:text-gray-500">{{ __('navigation.org_admin_translation_nested') }}</span>
                                    @else
                                        {{ $entry['en'] ?? '—' }}
                                    @endif
                                </td>
                                <td class="px-4 py-2">
                                    @php
                                        $statusColors = [
                                            'OK' => 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200',
                                            'MISSING_FR' => 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200',
                                            'MISSING_EN' => 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200',
                                        ];
                                        $statusLabels = [
                                            'OK' => 'OK',
                                            'MISSING_FR' => 'FR manquante',
                                            'MISSING_EN' => 'EN manquante',
                                        ];
                                    @endphp
                                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium {{ $statusColors[$entry['status']] ?? 'bg-gray-100 text-gray-800' }}">
                                        {{ $statusLabels[$entry['status']] ?? $entry['status'] }}
                                    </span>
                                </td>
                                <td class="px-4 py-2 text-center">
                                    @if(is_array($entry['fr']) || is_array($entry['en']))
                                        <span class="text-xs text-gray-400 italic">—</span>
                                    @else
                                        <div class="flex items-center justify-center gap-1">
                                        <button @click="showModal = true"
                                                class="text-xs font-medium text-indigo-600 hover:text-indigo-800 dark:text-indigo-400 dark:hover:text-indigo-300 transition">
                                            {{ __('navigation.org_admin_translation_modify') }}
                                        </button>
                                        @if($hasFr || $hasEn)
                                        <span class="text-gray-300 dark:text-gray-600">|</span>
                                        <form method="POST" action="{{ route('organization.admin.translations.reset', ['organization' => $organization->slug]) }}" class="inline" onsubmit="return confirm('{{ __('navigation.org_admin_translation_reset_confirm') }}')">
                                            @csrf
                                            <input type="hidden" name="group" value="{{ $entry['group'] }}">
                                            <input type="hidden" name="key" value="{{ $entry['key'] }}">
                                            <button type="submit" class="text-xs font-medium text-red-500 hover:text-red-700 dark:text-red-400 dark:hover:text-red-300 transition">
                                                {{ __('navigation.org_admin_translation_reset') }}
                                            </button>
                                        </form>
                                        @endif
                                        </div>

                                        {{-- Alpine modal — override form for both FR and EN --}}
                                        <div x-show="showModal" x-cloak
                                             class="fixed inset-0 z-50 flex items-center justify-center bg-black/50"
                                             @click.self="showModal = false">
                                            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-xl p-6 w-full max-w-lg mx-4 max-h-[90vh] overflow-y-auto">
                                                <div class="flex items-center justify-between mb-4">
                                                    <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">
                                                        {{ __('navigation.org_admin_translation_modify') }}
                                                        <code class="ml-1 text-purple-600 dark:text-purple-400">{{ $entry['group'] }}.{{ $entry['key'] }}</code>
                                                    </h3>
                                                    <button @click="showModal = false"
                                                            class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 transition">
                                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                                                    </button>
                                                </div>

                                                {{-- FR section --}}
                                                <div class="mb-4 p-3 rounded-lg border dark:border-gray-600">
                                                    <div class="flex items-center justify-between mb-2">
                                                        <span class="text-sm font-semibold text-gray-700 dark:text-gray-300 uppercase">FR</span>
                                                        @if($hasFr)
                                                            <span class="inline-flex items-center rounded-full bg-purple-100 px-2 py-0.5 text-xs font-medium text-purple-700 dark:bg-purple-900 dark:text-purple-300">overridé</span>
                                                        @endif
                                                    </div>
                                                    <p class="text-sm text-gray-600 dark:text-gray-400 mb-2">{{ $entry['fr'] ?? '—' }}</p>

                                                    <form method="POST" action="{{ route('organization.admin.translations.store', ['organization' => $organization->slug]) }}" class="flex gap-2">
                                                        @csrf
                                                        <input type="hidden" name="group" value="{{ $entry['group'] }}">
                                                        <input type="hidden" name="key" value="{{ $entry['key'] }}">
                                                        <input type="hidden" name="locale" value="fr">
                                                        <input type="text" name="value" value="{{ $hasFr ? $overrides["{$entry['group']}.{$entry['key']}:fr"]->value : '' }}" placeholder="FR override"
                                                               class="flex-1 rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200">
                                                        <button type="submit" class="rounded-lg bg-purple-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-purple-700 transition">{{ __('navigation.org_admin_translation_save') }}</button>
                                                    </form>

                                                    @if($hasFr)
                                                    <form method="POST" action="{{ route('organization.admin.translations.deactivate', ['organization' => $organization->slug, 'translationOverride' => $overrides["{$entry['group']}.{$entry['key']}:fr"]]) }}" class="mt-2">
                                                        @csrf @method('PATCH')
                                                        <button type="submit" class="w-full rounded-lg border border-red-200 bg-red-50 px-3 py-1.5 text-sm font-medium text-red-700 hover:bg-red-100 dark:border-red-800 dark:bg-red-900/30 dark:text-red-400 dark:hover:bg-red-900/50 transition">
                                                            {{ __('navigation.org_admin_translation_reset') }}
                                                        </button>
                                                    </form>
                                                    @endif
                                                </div>

                                                {{-- EN section --}}
                                                <div class="mb-4 p-3 rounded-lg border dark:border-gray-600">
                                                    <div class="flex items-center justify-between mb-2">
                                                        <span class="text-sm font-semibold text-gray-700 dark:text-gray-300 uppercase">EN</span>
                                                        @if($hasEn)
                                                            <span class="inline-flex items-center rounded-full bg-purple-100 px-2 py-0.5 text-xs font-medium text-purple-700 dark:bg-purple-900 dark:text-purple-300">overridé</span>
                                                        @endif
                                                    </div>
                                                    <p class="text-sm text-gray-600 dark:text-gray-400 mb-2">{{ $entry['en'] ?? '—' }}</p>

                                                    <form method="POST" action="{{ route('organization.admin.translations.store', ['organization' => $organization->slug]) }}" class="flex gap-2">
                                                        @csrf
                                                        <input type="hidden" name="group" value="{{ $entry['group'] }}">
                                                        <input type="hidden" name="key" value="{{ $entry['key'] }}">
                                                        <input type="hidden" name="locale" value="en">
                                                        <input type="text" name="value" value="{{ $hasEn ? $overrides["{$entry['group']}.{$entry['key']}:en"]->value : '' }}" placeholder="EN override"
                                                               class="flex-1 rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200">
                                                        <button type="submit" class="rounded-lg bg-purple-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-purple-700 transition">{{ __('navigation.org_admin_translation_save') }}</button>
                                                    </form>

                                                    @if($hasEn)
                                                    <form method="POST" action="{{ route('organization.admin.translations.deactivate', ['organization' => $organization->slug, 'translationOverride' => $overrides["{$entry['group']}.{$entry['key']}:en"]]) }}" class="mt-2">
                                                        @csrf @method('PATCH')
                                                        <button type="submit" class="w-full rounded-lg border border-red-200 bg-red-50 px-3 py-1.5 text-sm font-medium text-red-700 hover:bg-red-100 dark:border-red-800 dark:bg-red-900/30 dark:text-red-400 dark:hover:bg-red-900/50 transition">
                                                            {{ __('navigation.org_admin_translation_reset') }}
                                                        </button>
                                                    </form>
                                                    @endif
                                                </div>

                                                <div class="text-right">
                                                    <button @click="showModal = false"
                                                            class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-100 dark:border-gray-600 dark:text-gray-300 dark:hover:bg-gray-700 transition">
                                                        {{ __('navigation.org_admin_translation_close') }}
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    @endif
                                </td>
                            </tr>
                            @empty
                            <tr>
                                <td colspan="6" class="px-4 py-8 text-center text-gray-500 dark:text-gray-400">{{ __('navigation.org_admin_translation_no_entries') }}</td>
                            </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                {{ __('navigation.org_admin_translation_file_based') }}
            </p>
        </div>
    </div>
</x-org-admin-layout>