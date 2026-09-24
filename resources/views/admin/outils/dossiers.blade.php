{{-- TASK-1630 — « Nettoyage des Dossiers ».
     Un ecran d'operation, pas de demonstration : la ligne dit ce qu'elle est,
     le badge dit si elle est protegee, et rien ne se supprime d'ici. --}}
<x-admin-layout :title="__('admin.dossiers_cleanup.title')">
    <div class="mb-6">
        <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('admin.dossiers_cleanup.subtitle') }}</p>
    </div>

    @if(session('success'))
        <div class="mb-4 p-3 bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 rounded-lg text-sm text-green-700 dark:text-green-300">
            {{ session('success') }}
        </div>
    @endif
    @if(session('error'))
        <div class="mb-4 p-3 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg text-sm text-red-700 dark:text-red-300">
            {{ session('error') }}
        </div>
    @endif
    @if($unknownOrganization)
        {{-- Un slug demande mais introuvable ne filtre pas en silence : sinon
             on croirait voir une Organization vide. --}}
        <div class="mb-4 p-3 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 rounded-lg text-sm text-amber-800 dark:text-amber-300">
            {{ __('admin.dossiers_cleanup.unknown_organization', ['slug' => $unknownOrganization]) }}
        </div>
    @endif

    {{-- Filtres : une lecture, jamais un droit. --}}
    <form method="GET" action="{{ route('admin.outils.dossiers') }}" class="mb-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
        <div>
            <label for="organization" class="block text-xs font-semibold text-gray-500 dark:text-gray-400 mb-1">{{ __('admin.dossiers_cleanup.filter_organization') }}</label>
            <select id="organization" name="organization" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 text-sm">
                <option value="">{{ __('admin.dossiers_cleanup.filter_organization_all') }}</option>
                @foreach($organizations as $organization)
                    <option value="{{ $organization->slug }}" @selected($filters['organization'] === $organization->slug)>{{ $organization->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="type" class="block text-xs font-semibold text-gray-500 dark:text-gray-400 mb-1">{{ __('admin.dossiers_cleanup.filter_type') }}</label>
            <select id="type" name="type" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 text-sm">
                <option value="">{{ __('admin.dossiers_cleanup.filter_type_all') }}</option>
                <option value="legacy_child" @selected($filters['type'] === 'legacy_child')>{{ __('admin.dossiers_cleanup.type_legacy_child') }}</option>
                <option value="loop_root" @selected($filters['type'] === 'loop_root')>{{ __('admin.dossiers_cleanup.type_loop_root') }}</option>
                <option value="user_root" @selected($filters['type'] === 'user_root')>{{ __('admin.dossiers_cleanup.type_user_root') }}</option>
                <option value="root" @selected($filters['type'] === 'root')>{{ __('admin.dossiers_cleanup.filter_type_root') }}</option>
            </select>
        </div>
        <div>
            <label for="state" class="block text-xs font-semibold text-gray-500 dark:text-gray-400 mb-1">{{ __('admin.dossiers_cleanup.filter_state') }}</label>
            <select id="state" name="state" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 text-sm">
                <option value="">{{ __('admin.dossiers_cleanup.filter_state_all') }}</option>
                <option value="active" @selected($filters['state'] === 'active')>{{ __('admin.dossiers_cleanup.filter_state_active') }}</option>
                <option value="deleted" @selected($filters['state'] === 'deleted')>{{ __('admin.dossiers_cleanup.filter_state_deleted') }}</option>
            </select>
        </div>
        <div>
            <label for="search" class="block text-xs font-semibold text-gray-500 dark:text-gray-400 mb-1">{{ __('admin.dossiers_cleanup.filter_search') }}</label>
            <input id="search" name="search" type="text" value="{{ $filters['search'] }}" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 text-sm">
        </div>
        <div class="flex items-end gap-2">
            <button type="submit" class="inline-flex min-h-10 items-center rounded-lg bg-indigo-600 px-4 text-sm font-semibold text-white hover:bg-indigo-700">{{ __('admin.dossiers_cleanup.filter_apply') }}</button>
            <a href="{{ route('admin.outils.dossiers') }}" class="inline-flex min-h-10 items-center rounded-lg border border-gray-300 dark:border-gray-600 px-4 text-sm font-semibold text-gray-700 dark:text-gray-200">{{ __('admin.dossiers_cleanup.filter_reset') }}</a>
        </div>
    </form>

    {{-- Le formulaire de SELECTION ne supprime rien : il mene a la preview. --}}
    <form method="POST" action="{{ route('admin.outils.dossiers.preview') }}"
          x-data="{ count: 0, recount() { this.count = $el.querySelectorAll('input[name=\'dossiers[]\']:checked').length } }"
          @change="recount()">
        @csrf
        @foreach(['organization', 'type', 'state', 'search'] as $passthrough)
            <input type="hidden" name="{{ $passthrough }}" value="{{ $filters[$passthrough] }}">
        @endforeach

        <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 dark:bg-gray-700">
                    <tr>
                        <th class="px-3 py-3 text-left">
                            <label class="inline-flex items-center gap-2 text-xs font-semibold text-gray-500 dark:text-gray-400">
                                <input type="checkbox"
                                       @change="$el.closest('form').querySelectorAll('input[name=\'dossiers[]\']:not(:disabled)').forEach(c => c.checked = $el.checked); recount()"
                                       class="rounded border-gray-300 dark:border-gray-600">
                                {{ __('admin.dossiers_cleanup.select_page') }}
                            </label>
                        </th>
                        <th class="px-3 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('admin.dossiers_cleanup.col_name') }}</th>
                        <th class="px-3 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('admin.dossiers_cleanup.col_organization') }}</th>
                        <th class="px-3 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('admin.dossiers_cleanup.col_type') }}</th>
                        <th class="px-3 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('admin.dossiers_cleanup.col_holder') }}</th>
                        <th class="px-3 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('admin.dossiers_cleanup.col_parent') }}</th>
                        <th class="px-3 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('admin.dossiers_cleanup.col_visibility') }}</th>
                        <th class="px-3 py-3 text-right text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('admin.dossiers_cleanup.col_children') }}</th>
                        <th class="px-3 py-3 text-right text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('admin.dossiers_cleanup.col_files') }}</th>
                        <th class="px-3 py-3 text-right text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('admin.dossiers_cleanup.col_articles') }}</th>
                        <th class="px-3 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('admin.dossiers_cleanup.col_updated') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    @forelse($dossiers as $dossier)
                        @php
                            $type = $eligibility->type($dossier);
                            $raison = $eligibility->protectionReason($dossier);
                        @endphp
                        <tr class="{{ $raison ? 'bg-gray-50/60 dark:bg-gray-900/30' : '' }}">
                            <td class="px-3 py-3 align-top">
                                {{-- Une case desactivee ET la raison ecrite juste a cote :
                                     un bouton grise sans explication laisse croire a un bug. --}}
                                <input type="checkbox" name="dossiers[]" value="{{ $dossier->getKey() }}"
                                       @disabled($raison !== null)
                                       class="rounded border-gray-300 dark:border-gray-600 disabled:opacity-40">
                            </td>
                            <td class="px-3 py-3 align-top">
                                <div class="font-medium text-gray-900 dark:text-gray-100">{{ $dossier->displayName() }}</div>
                                @if($raison)
                                    <div class="mt-1 text-xs text-amber-700 dark:text-amber-400">{{ __('admin.dossiers_cleanup.protected_'.$raison) }}</div>
                                @endif
                            </td>
                            <td class="px-3 py-3 align-top text-gray-600 dark:text-gray-300">{{ $dossier->organization?->name }}</td>
                            <td class="px-3 py-3 align-top">
                                <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold
                                    {{ $type === 'legacy_child' ? 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300' : 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-200' }}">
                                    {{ __('admin.dossiers_cleanup.type_'.$type) }}
                                </span>
                                @if($raison)
                                    <span class="ml-1 inline-flex items-center rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-semibold text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300">{{ __('admin.dossiers_cleanup.badge_protected') }}</span>
                                @endif
                                @if($dossier->trashed())
                                    <span class="ml-1 inline-flex items-center rounded-full bg-red-100 px-2 py-0.5 text-xs font-semibold text-red-800 dark:bg-red-900/40 dark:text-red-300">{{ __('admin.dossiers_cleanup.badge_deleted') }}</span>
                                @endif
                            </td>
                            <td class="px-3 py-3 align-top text-gray-600 dark:text-gray-300">
                                @if($dossier->loop_id !== null)
                                    {{ __('admin.dossiers_cleanup.holder_loop', ['name' => $dossier->loop?->name ?? '?']) }}
                                @elseif($dossier->owner_id !== null)
                                    {{ __('admin.dossiers_cleanup.holder_user', ['name' => $dossier->owner?->publicDisplayName() ?? '?']) }}
                                @elseif($dossier->parent_id !== null)
                                    {{ __('admin.dossiers_cleanup.holder_inherited') }}
                                @else
                                    {{ __('admin.dossiers_cleanup.holder_none') }}
                                @endif
                            </td>
                            <td class="px-3 py-3 align-top text-gray-600 dark:text-gray-300">{{ $dossier->parent?->name ?? __('admin.dossiers_cleanup.no_parent') }}</td>
                            <td class="px-3 py-3 align-top text-gray-600 dark:text-gray-300">{{ $dossier->effectiveVisibility() }}</td>
                            <td class="px-3 py-3 align-top text-right text-gray-600 dark:text-gray-300">{{ $dossier->children_count }}</td>
                            <td class="px-3 py-3 align-top text-right text-gray-600 dark:text-gray-300">{{ $dossier->files_count }}</td>
                            <td class="px-3 py-3 align-top text-right text-gray-600 dark:text-gray-300">{{ $dossier->dossier_blog_posts_count }}</td>
                            <td class="px-3 py-3 align-top text-gray-500 dark:text-gray-400">{{ $dossier->updated_at?->format('d/m/Y H:i') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="11" class="px-3 py-8 text-center text-gray-500 dark:text-gray-400">{{ __('admin.dossiers_cleanup.empty') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-4 flex flex-wrap items-center justify-between gap-3">
            <span class="text-sm text-gray-500 dark:text-gray-400"
                  x-text="@js(__('admin.dossiers_cleanup.selected_count', ['count' => '__N__'])).replace('__N__', count)"></span>
            <button type="submit" :disabled="count === 0"
                    class="inline-flex min-h-11 items-center rounded-lg bg-red-600 px-5 text-sm font-semibold text-white hover:bg-red-700 disabled:opacity-40 disabled:cursor-not-allowed">
                {{ __('admin.dossiers_cleanup.review_selection') }}
            </button>
        </div>
    </form>

    <div class="mt-6">{{ $dossiers->links() }}</div>
</x-admin-layout>
