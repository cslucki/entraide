<x-admin-layout title="Traductions">
    {{-- Stats --}}
    <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-6">
        <a href="{{ route('admin.translations') }}" class="block rounded-xl border border-gray-200 bg-white px-4 py-3 hover:border-gray-400 transition dark:border-gray-700 dark:bg-gray-800 dark:hover:border-gray-500">
            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Total</p>
            <p class="mt-1 text-2xl font-bold text-gray-900 dark:text-gray-100">{{ $stats['total'] }}</p>
        </a>
        <a href="{{ route('admin.translations', ['status' => 'OK']) }}" class="block rounded-xl border border-green-200 bg-green-50 px-4 py-3 hover:border-green-400 transition dark:border-green-900 dark:bg-green-900/20 dark:hover:border-green-700">
            <p class="text-xs font-semibold uppercase tracking-wide text-green-700 dark:text-green-400">OK</p>
            <p class="mt-1 text-2xl font-bold text-green-800 dark:text-green-200">{{ $stats['ok'] }}</p>
        </a>
        <a href="{{ route('admin.translations', ['status' => 'MISSING_FR']) }}" class="block rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 hover:border-amber-400 transition dark:border-amber-900 dark:bg-amber-900/20 dark:hover:border-amber-700">
            <p class="text-xs font-semibold uppercase tracking-wide text-amber-700 dark:text-amber-400">FR manquante</p>
            <p class="mt-1 text-2xl font-bold text-amber-800 dark:text-amber-200">{{ $stats['missing_fr'] }}</p>
        </a>
        <a href="{{ route('admin.translations', ['status' => 'MISSING_EN']) }}" class="block rounded-xl border border-red-200 bg-red-50 px-4 py-3 hover:border-red-400 transition dark:border-red-900 dark:bg-red-900/20 dark:hover:border-red-700">
            <p class="text-xs font-semibold uppercase tracking-wide text-red-700 dark:text-red-400">EN manquante</p>
            <p class="mt-1 text-2xl font-bold text-red-800 dark:text-red-200">{{ $stats['missing_en'] }}</p>
        </a>
        <a href="{{ route('admin.translations', ['status' => 'OVERRIDDEN']) }}" class="block rounded-xl border border-purple-200 bg-purple-50 px-4 py-3 hover:border-purple-400 transition dark:border-purple-900 dark:bg-purple-900/20 dark:hover:border-purple-700">
            <p class="text-xs font-semibold uppercase tracking-wide text-purple-700 dark:text-purple-400">{{ __('navigation.org_admin_translation_overridden') }}</p>
            <p class="mt-1 text-2xl font-bold text-purple-800 dark:text-purple-200">{{ $stats['overridden'] }}</p>
        </a>
        <a href="{{ route('admin.translations') }}" class="block rounded-xl border border-gray-200 bg-white px-4 py-3 hover:border-gray-400 transition dark:border-gray-700 dark:bg-gray-800 dark:hover:border-gray-500">
            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Restant</p>
            <p class="mt-1 text-2xl font-bold text-gray-900 dark:text-gray-100">{{ $stats['remaining'] }}</p>
        </a>
    </div>

    {{-- Override creation form --}}
    <div x-data="{ open: false }" class="mb-6">
        <button @click="open = !open"
                class="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 transition">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
            Nouvel override
        </button>

        <div x-show="open" x-cloak
             class="mt-4 rounded-xl border border-gray-200 bg-white p-6 dark:border-gray-700 dark:bg-gray-800">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">Nouvel override</h3>
            <form method="POST" action="{{ route('admin.translations.overrides.store') }}" class="grid grid-cols-1 sm:grid-cols-6 gap-4">
                @csrf
                <input type="hidden" name="organization_id" value="">
                <div class="sm:col-span-1">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Locale</label>
                    <select name="locale" required class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-300">
                        <option value="fr">FR</option>
                        <option value="en">EN</option>
                    </select>
                </div>
                <div class="sm:col-span-1">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Groupe</label>
                    <select name="group" required class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-300">
                        @foreach($groups as $group)
                            <option value="{{ $group }}">{{ $group }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="sm:col-span-1">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Clé</label>
                    <input type="text" name="key" required placeholder="my.key"
                           class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-300">
                </div>
                <div class="sm:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Valeur</label>
                    <input type="text" name="value" required placeholder="Override value"
                           class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-300">
                </div>
                <div class="sm:col-span-1 flex items-end">
                    <button type="submit" class="w-full rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 transition">
                        Créer
                    </button>
                </div>
            </form>
        </div>
    </div>

    {{-- Filters --}}
    <div class="mb-4 flex flex-wrap items-center gap-3">
        <form method="GET" class="flex flex-wrap items-center gap-3">
            @if($activeOrgId)
                <input type="hidden" name="org_id" value="{{ $activeOrgId }}">
            @endif
            <select name="group" class="rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-700 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-300">
                <option value="_all" {{ $activeGroup === '_all' || !$activeGroup ? 'selected' : '' }}>Tous les groupes</option>
                @foreach($groups as $g)
                    <option value="{{ $g }}" {{ $activeGroup === $g ? 'selected' : '' }}>{{ $g }}</option>
                @endforeach
            </select>

            <select name="status" class="rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-700 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-300">
                <option value="_all" {{ $activeStatus === '_all' || !$activeStatus ? 'selected' : '' }}>Tous les statuts</option>
                <option value="OK" {{ $activeStatus === 'OK' ? 'selected' : '' }}>OK</option>
                <option value="MISSING_FR" {{ $activeStatus === 'MISSING_FR' ? 'selected' : '' }}>FR manquante</option>
                <option value="MISSING_EN" {{ $activeStatus === 'MISSING_EN' ? 'selected' : '' }}>EN manquante</option>
                <option value="EMPTY_FR" {{ $activeStatus === 'EMPTY_FR' ? 'selected' : '' }}>FR vide</option>
                <option value="EMPTY_EN" {{ $activeStatus === 'EMPTY_EN' ? 'selected' : '' }}>EN vide</option>
                <option value="NESTED" {{ $activeStatus === 'NESTED' ? 'selected' : '' }}>Structure imbriquée</option>
                <option value="OVERRIDDEN" {{ $activeStatus === 'OVERRIDDEN' ? 'selected' : '' }}>{{ __('navigation.org_admin_translation_overridden') }}</option>
            </select>

            <input type="text" name="search" value="{{ $search ?? '' }}" placeholder="Rechercher..."
                   class="rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-700 placeholder-gray-400 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-300 dark:placeholder-gray-500">

            <button type="submit" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 transition">Filtrer</button>

            @if($activeGroup || $activeStatus || $search)
                <a href="{{ route('admin.translations') }}{{ $activeOrgId ? '?org_id='.$activeOrgId : '' }}" class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-100 dark:border-gray-600 dark:text-gray-300 dark:hover:bg-gray-700 transition">Réinitialiser</a>
            @endif
        </form>
    </div>

    {{-- Entries table (hidden by default) --}}
    <div x-data="{ showEntries: {{ ($activeGroup && $activeGroup !== '_all') || ($activeStatus && $activeStatus !== '_all') || $search ? 'true' : 'false' }} }" class="mb-6">
        <button @click="showEntries = !showEntries" class="mb-3 inline-flex items-center gap-2 text-sm font-medium text-gray-600 hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-100 transition">
            <template x-if="showEntries">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
            </template>
            <template x-if="!showEntries">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
            </template>
            <span x-text="showEntries ? 'Masquer les entrées' : 'Afficher {{ $entries->count() }} entrées'"></span>
        </button>

        <div x-show="showEntries" x-cloak>
            <div class="overflow-hidden rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 dark:bg-gray-700">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Groupe</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Clé</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">FR</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">EN</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Statut</th>
                                <th class="px-4 py-3 text-center text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                            @forelse($entries as $entry)
                            <tr x-data="{ showModal: false }"
                                class="hover:bg-gray-50 dark:hover:bg-gray-750 {{ $entry['status'] !== 'OK' ? 'bg-amber-50/50 dark:bg-amber-900/10' : '' }}">
                                <td class="px-4 py-3 font-mono text-xs text-gray-600 dark:text-gray-400">{{ $entry['group'] }}</td>
                                <td class="px-4 py-3 font-mono text-xs text-gray-900 dark:text-gray-100">{{ $entry['key'] }}</td>
                                <td class="px-4 py-3 text-sm max-w-xs truncate
                                    @php $hasFr = isset($globalOverridesKeyed["{$entry['group']}.{$entry['key']}:fr"]) @endphp
                                    {{ $hasFr ? 'text-purple-600 dark:text-purple-400 font-medium' : '' }}
                                    {{ !$hasFr && is_array($entry['fr']) ? 'text-gray-400 italic' : '' }}
                                    {{ !$hasFr && !is_array($entry['fr']) && $entry['fr'] === null ? 'text-red-500 italic' : '' }}">
                                    @if($hasFr)
                                        <span title="Override actif">{{ $globalOverridesKeyed["{$entry['group']}.{$entry['key']}:fr"]->value }}</span>
                                        <span class="text-xs text-purple-400">(override)</span>
                                    @elseif(is_array($entry['fr']))
                                        <span class="text-xs text-gray-400 dark:text-gray-500">structure imbriquée</span>
                                    @else
                                        {{ $entry['fr'] ?? '—' }}
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-sm max-w-xs truncate
                                    @php $hasEn = isset($globalOverridesKeyed["{$entry['group']}.{$entry['key']}:en"]) @endphp
                                    {{ $hasEn ? 'text-purple-600 dark:text-purple-400 font-medium' : '' }}
                                    {{ !$hasEn && is_array($entry['en']) ? 'text-gray-400 italic' : '' }}
                                    {{ !$hasEn && !is_array($entry['en']) && $entry['en'] === null ? 'text-red-500 italic' : '' }}">
                                    @if($hasEn)
                                        <span title="Override actif">{{ $globalOverridesKeyed["{$entry['group']}.{$entry['key']}:en"]->value }}</span>
                                        <span class="text-xs text-purple-400">(override)</span>
                                    @elseif(is_array($entry['en']))
                                        <span class="text-xs text-gray-400 dark:text-gray-500">structure imbriquée</span>
                                    @else
                                        {{ $entry['en'] ?? '—' }}
                                    @endif
                                </td>
                                <td class="px-4 py-3">
                                    @php
                                        $statusColors = [
                                            'OK' => 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200',
                                            'MISSING_FR' => 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200',
                                            'MISSING_EN' => 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200',
                                            'EMPTY_FR' => 'bg-amber-100 text-amber-800 dark:bg-amber-900 dark:text-amber-200',
                                            'EMPTY_EN' => 'bg-amber-100 text-amber-800 dark:bg-amber-900 dark:text-amber-200',
                                            'NESTED' => 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300',
                                        ];
                                        $statusLabels = [
                                            'OK' => 'OK',
                                            'MISSING_FR' => 'FR manquante',
                                            'MISSING_EN' => 'EN manquante',
                                            'EMPTY_FR' => 'FR vide',
                                            'EMPTY_EN' => 'EN vide',
                                            'NESTED' => 'Structure imbriquée',
                                        ];
                                    @endphp
                                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium {{ $statusColors[$entry['status']] ?? 'bg-gray-100 text-gray-800' }}">
                                        {{ $statusLabels[$entry['status']] ?? $entry['status'] }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-center">
                                    @if(is_array($entry['fr']) || is_array($entry['en']))
                                        <span class="text-xs text-gray-400 italic">—</span>
                                    @else
                                        <div class="flex items-center justify-center gap-1">
                                        <button @click="showModal = true"
                                                class="text-xs font-medium text-indigo-600 hover:text-indigo-800 dark:text-indigo-400 dark:hover:text-indigo-300 transition">
                                            Modifier
                                        </button>
                                        @if($hasFr || $hasEn)
                                        <span class="text-gray-300 dark:text-gray-600">|</span>
                                        <form method="POST" action="{{ route('admin.translations.overrides.reset') }}" class="inline" onsubmit="return confirm('{{ __('navigation.org_admin_translation_reset_confirm') }}')">
                                            @csrf
                                            <input type="hidden" name="group" value="{{ $entry['group'] }}">
                                            <input type="hidden" name="key" value="{{ $entry['key'] }}">
                                            <button type="submit" class="text-xs font-medium text-red-500 hover:text-red-700 dark:text-red-400 dark:hover:text-red-300 transition">
                                                {{ __('navigation.org_admin_translation_reset') }}
                                            </button>
                                        </form>
                                        @endif
                                        </div>

                                        {{-- Alpine modal — override for both FR and EN --}}
                                        <div x-show="showModal" x-cloak
                                             class="fixed inset-0 z-50 flex items-center justify-center bg-black/50"
                                             @click.self="showModal = false">
                                            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-xl p-6 w-full max-w-lg mx-4 max-h-[90vh] overflow-y-auto">
                                                <div class="flex items-center justify-between mb-4">
                                                    <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">
                                                        Modifier <code class="ml-1 text-indigo-600 dark:text-indigo-400">{{ $entry['group'] }}.{{ $entry['key'] }}</code>
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

                                                    <form method="POST" action="{{ route('admin.translations.overrides.store') }}" class="flex gap-2">
                                                        @csrf
                                                        <input type="hidden" name="organization_id" value="">
                                                        <input type="hidden" name="group" value="{{ $entry['group'] }}">
                                                        <input type="hidden" name="key" value="{{ $entry['key'] }}">
                                                        <input type="hidden" name="locale" value="fr">
                                                        <input type="text" name="value" value="{{ $hasFr ? $globalOverridesKeyed["{$entry['group']}.{$entry['key']}:fr"]->value : '' }}" placeholder="FR override"
                                                               class="flex-1 rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200">
                                                        <button type="submit" class="rounded-lg bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-700 transition">Sauver</button>
                                                    </form>

                                                    @if($hasFr)
                                                    <form method="POST" action="{{ route('admin.translations.overrides.deactivate', $globalOverridesKeyed["{$entry['group']}.{$entry['key']}:fr"]) }}" class="mt-2">
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

                                                    <form method="POST" action="{{ route('admin.translations.overrides.store') }}" class="flex gap-2">
                                                        @csrf
                                                        <input type="hidden" name="organization_id" value="">
                                                        <input type="hidden" name="group" value="{{ $entry['group'] }}">
                                                        <input type="hidden" name="key" value="{{ $entry['key'] }}">
                                                        <input type="hidden" name="locale" value="en">
                                                        <input type="text" name="value" value="{{ $hasEn ? $globalOverridesKeyed["{$entry['group']}.{$entry['key']}:en"]->value : '' }}" placeholder="EN override"
                                                               class="flex-1 rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200">
                                                        <button type="submit" class="rounded-lg bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-700 transition">Sauver</button>
                                                    </form>

                                                    @if($hasEn)
                                                    <form method="POST" action="{{ route('admin.translations.overrides.deactivate', $globalOverridesKeyed["{$entry['group']}.{$entry['key']}:en"]) }}" class="mt-2">
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
                                                        Fermer
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    @endif
                                </td>
                            </tr>
                            @empty
                            <tr>
                                <td colspan="6" class="px-4 py-8 text-center text-gray-500 dark:text-gray-400">Aucune traduction trouvée.</td>
                            </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <p class="mt-4 text-xs text-gray-500 dark:text-gray-400">
                {{ $entries->count() }} entrées — Lecture seule. Les overrides (globaux) sont prioritaires sur les fichiers <code class="rounded bg-gray-100 px-1 py-0.5 font-mono text-gray-700 dark:bg-gray-700 dark:text-gray-300">lang/</code>.
            </p>
        </div>
    </div>

    {{-- Overrides DB section --}}
    <div class="mt-10">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Overrides DB</h2>

            <form method="GET" action="{{ route('admin.translations') }}" class="flex items-center gap-2">
                <select name="org_id" onchange="this.form.submit()"
                        class="rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-700 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-300">
                    <option value="">Toutes les organisations</option>
                    @foreach($organizations as $org)
                        <option value="{{ $org->id }}" {{ $activeOrgId === $org->id ? 'selected' : '' }}>{{ $org->name }}</option>
                    @endforeach
                </select>
                @if($activeOrgId)
                    <a href="{{ route('admin.translations') }}" class="text-xs text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200 transition">Effacer</a>
                @endif
            </form>
        </div>

        @if(session('success'))
            <div class="mb-4 rounded-lg bg-green-50 px-4 py-3 text-sm text-green-800 dark:bg-green-900/20 dark:text-green-200 border border-green-200 dark:border-green-900">
                {{ session('success') }}
            </div>
        @endif

        @if(session('error'))
            <div class="mb-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-800 dark:bg-red-900/20 dark:text-red-200 border border-red-200 dark:border-red-900">
                {{ session('error') }}
            </div>
        @endif

        <div class="overflow-hidden rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-700">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Organisation</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Locale</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Groupe</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Clé</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Valeur</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Statut</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Créé par</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        @forelse($overrides as $override)
                        <tr class="hover:bg-gray-50 dark:hover:bg-gray-750 {{ !$override->is_active ? 'opacity-60' : '' }}">
                            <td class="px-4 py-3 text-xs text-gray-600 dark:text-gray-400">
                                {{ $override->organization?->name ?? '—' }}
                                @if(!$override->organization_id)
                                    <span class="rounded bg-indigo-100 px-1.5 py-0.5 text-xs font-medium text-indigo-700 dark:bg-indigo-900 dark:text-indigo-300">Global</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 font-mono text-xs text-gray-700 dark:text-gray-300">{{ $override->locale }}</td>
                            <td class="px-4 py-3 font-mono text-xs text-gray-600 dark:text-gray-400">{{ $override->group }}</td>
                            <td class="px-4 py-3 font-mono text-xs text-gray-900 dark:text-gray-100">{{ $override->key }}</td>
                            <td class="max-w-xs truncate px-4 py-3 text-sm text-gray-700 dark:text-gray-300" title="{{ $override->value }}">{{ $override->value }}</td>
                            <td class="px-4 py-3">
                                @if($override->is_active)
                                    <span class="inline-flex items-center rounded-full bg-green-100 px-2 py-0.5 text-xs font-medium text-green-800 dark:bg-green-900 dark:text-green-200">Actif</span>
                                @else
                                    <span class="inline-flex items-center rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-600 dark:bg-gray-700 dark:text-gray-400">Inactif</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-xs text-gray-500 dark:text-gray-400">
                                {{ $override->createdBy?->name ?? '—' }}
                            </td>
                            <td class="px-4 py-3 text-right">
                                <a href="{{ route('admin.translations.overrides.edit', $override) }}"
                                   class="text-sm font-medium text-indigo-600 hover:text-indigo-800 dark:text-indigo-400 dark:hover:text-indigo-300 transition">Modifier</a>
                                @if($override->is_active)
                                    <form method="POST" action="{{ route('admin.translations.overrides.deactivate', $override) }}" class="inline">
                                        @csrf
                                        @method('PATCH')
                                        <button type="submit" onclick="return confirm('Désactiver cet override ?')"
                                                class="ml-3 text-sm font-medium text-red-600 hover:text-red-800 dark:text-red-400 dark:hover:text-red-300 transition">
                                            Désactiver
                                        </button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="8" class="px-4 py-8 text-center text-gray-500 dark:text-gray-400">Aucun override pour le moment.</td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
            {{ $overrides->count() }} override(s) — Les overrides sont prioritaires sur les fichiers <code class="rounded bg-gray-100 px-1 py-0.5 font-mono text-gray-700 dark:bg-gray-700 dark:text-gray-300">lang/</code>.
        </p>
    </div>
</x-admin-layout>