{{-- TASK-1631 — le tableau principal.
     Les compteurs sont de VRAIS liens vers la route canonique du dataset,
     avec leur filtre dans l'URL. Ils etaient des <button> qui fabriquaient
     une URL en JavaScript et l'ouvraient en popup : un `return` place plus
     haut dans le meme bloc, un bloqueur de popup ou une interpolation vide
     suffisaient a les rendre inertes, sans rien afficher. --}}
<x-admin-layout :title="__('admin.assign_data.title')">
    <div class="mb-6">
        <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('admin.assign_data.subtitle') }}</p>
    </div>

    @if(session('success'))
        <div class="mb-4 p-3 bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 rounded-lg text-sm text-green-700 dark:text-green-300">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="mb-4 p-3 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg text-sm text-red-700 dark:text-red-300">{{ session('error') }}</div>
    @endif
    @if($unknownOrganization)
        <div class="mb-4 p-3 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 rounded-lg text-sm text-amber-800 dark:text-amber-300">
            {{ __('admin.assign_data.unknown_organization', ['slug' => $unknownOrganization]) }}
        </div>
    @endif

    {{-- La legende : l'outil doit se lire sans connaitre l'histoire
         Community -> Organization. --}}
    <div class="mb-6 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-4">
        <h2 class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('admin.assign_data.legend') }}</h2>
        <dl class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3 text-xs">
            @foreach($classifications as $classification)
                <div class="flex gap-2">
                    <dt class="shrink-0 font-semibold text-gray-700 dark:text-gray-200">{{ __($classification->labelKey()) }}</dt>
                    <dd class="text-gray-500 dark:text-gray-400">{{ __($classification->hintKey()) }}</dd>
                </div>
            @endforeach
        </dl>
    </div>

    <div class="mb-4">
        <label for="target-organization" class="block text-xs font-semibold text-gray-500 dark:text-gray-400 mb-1">{{ __('admin.assign_data.target_organization') }}</label>
        <select id="target-organization" form="none" class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 text-sm"
                onchange="document.querySelectorAll('.assign-org').forEach(i => i.value = this.value)">
            @foreach($organizations as $organization)
                <option value="{{ $organization->id }}">{{ $organization->name }}</option>
            @endforeach
        </select>
    </div>

    <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 dark:bg-gray-700">
                <tr>
                    <th class="px-3 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('admin.assign_data.col_dataset') }}</th>
                    <th class="px-3 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('admin.assign_data.col_table') }}</th>
                    <th class="px-3 py-3 text-right text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('admin.assign_data.col_total') }}</th>
                    <th class="px-3 py-3 text-right text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('admin.assign_data.col_with') }}</th>
                    <th class="px-3 py-3 text-right text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('admin.assign_data.col_without') }}</th>
                    <th class="px-3 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('admin.assign_data.col_classification') }}</th>
                    <th class="px-3 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('admin.assign_data.col_action') }}</th>
                    <th class="px-3 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('admin.assign_data.col_detail') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                @foreach($rows as $row)
                    @php $ds = $row['dataset']; @endphp
                    <tr>
                        <td class="px-3 py-3 align-top">
                            <div class="font-medium text-gray-900 dark:text-gray-100">
                                {{ __($ds->labelKey()) }}
                                @if($ds->critical)
                                    <span class="ml-1 inline-flex items-center rounded-full bg-red-100 px-2 py-0.5 text-xs font-semibold text-red-700 dark:bg-red-900/40 dark:text-red-300">{{ __('admin.assign_data.critical') }}</span>
                                @endif
                            </div>
                            <div class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ __($ds->descriptionKey()) }}</div>
                        </td>
                        <td class="px-3 py-3 align-top font-mono text-xs text-gray-500 dark:text-gray-400">{{ $ds->table }}</td>
                        <td class="px-3 py-3 align-top text-right text-gray-600 dark:text-gray-300">
                            <a href="{{ route('admin.outils.assign-data.detail', ['dataset' => $ds->key, 'filter' => 'all']) }}" class="underline hover:text-indigo-600">{{ $row['total'] }}</a>
                        </td>
                        <td class="px-3 py-3 align-top text-right text-gray-600 dark:text-gray-300">
                            <a href="{{ route('admin.outils.assign-data.detail', ['dataset' => $ds->key, 'filter' => 'with_organization']) }}" class="underline hover:text-green-600">{{ $row['with_organization'] }}</a>
                        </td>
                        <td class="px-3 py-3 align-top text-right">
                            <a href="{{ route('admin.outils.assign-data.detail', ['dataset' => $ds->key, 'filter' => 'without_organization']) }}"
                               class="underline {{ $row['without_organization'] > 0 && $ds->isAssignable() ? 'text-orange-600 dark:text-orange-400' : 'text-gray-600 dark:text-gray-300' }}">
                                {{ $row['without_organization'] }}
                            </a>
                        </td>
                        <td class="px-3 py-3 align-top">
                            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold
                                {{ $ds->isAssignable() ? 'bg-indigo-100 text-indigo-800 dark:bg-indigo-900/40 dark:text-indigo-300' : 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300' }}">
                                {{ __($ds->classification->labelKey()) }}
                            </span>
                        </td>
                        <td class="px-3 py-3 align-top text-xs">
                            @if($row['actionable'])
                                {{-- L'action n'apparait que si elle a un objet.
                                     Ailleurs on ecrit « aucune action », jamais un
                                     bouton grise dont on ne sait pas s'il est casse. --}}
                                <form method="POST" action="{{ route('admin.outils.assign-data.preview') }}">
                                    @csrf
                                    <input type="hidden" name="dataset" value="{{ $ds->key }}">
                                    <input type="hidden" name="organization_id" class="assign-org" value="{{ $organizations->first()?->id }}">
                                    <button type="submit" class="text-indigo-600 dark:text-indigo-400 underline hover:text-indigo-800">
                                        {{ __('admin.assign_data.action_assign', ['count' => $row['without_organization']]) }}
                                    </button>
                                </form>
                            @else
                                <span class="text-gray-400">{{ __('admin.assign_data.no_action') }}</span>
                            @endif
                        </td>
                        <td class="px-3 py-3 align-top text-xs">
                            <a href="{{ route('admin.outils.assign-data.detail', ['dataset' => $ds->key]) }}" class="text-indigo-600 dark:text-indigo-400 underline hover:text-indigo-800">{{ __('admin.assign_data.see_detail') }}</a>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</x-admin-layout>
