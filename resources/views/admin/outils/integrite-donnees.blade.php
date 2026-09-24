{{-- TASK-1632 — le cockpit. Un SuperAdmin doit savoir en trente secondes si
     sa base est saine apres ses suppressions : un resume en tete, des cartes
     par domaine, et des statuts explicites plutot qu'un score. --}}
<x-admin-layout :title="__('admin.integrity.title')">
    <div class="mb-6">
        <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('admin.integrity.subtitle') }}</p>
        <p class="mt-1 text-xs text-gray-400 dark:text-gray-500">{{ __('admin.integrity.read_only') }}</p>
    </div>

    {{-- Le resume : la reponse a la question, avant tout le reste. --}}
    @php
        $badge = [
            'ok' => 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-300',
            'information' => 'bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-300',
            'watch' => 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300',
            'action_required' => 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-300',
        ];
    @endphp
    <div class="mb-8 rounded-xl border p-4 {{ $worst->value === 'action_required' ? 'border-red-300 bg-red-50 dark:border-red-800 dark:bg-red-900/20' : 'border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800' }}">
        <h2 class="mb-3 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('admin.integrity.summary_title') }}</h2>
        <div class="flex flex-wrap gap-2">
            @foreach($summary as $status => $count)
                @continue($count === 0)
                <span class="inline-flex items-center rounded-full px-3 py-1 text-sm font-semibold {{ $badge[$status] }}">
                    {{ __('admin.integrity.summary_'.$status, ['count' => $count]) }}
                </span>
            @endforeach
        </div>
    </div>

    @foreach($groups as $group => $groupChecks)
        <section class="mb-6">
            <h2 class="mb-2 text-sm font-semibold text-gray-700 dark:text-gray-200">{{ __('admin.integrity.group_'.$group) }}</h2>

            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-700">
                        <tr>
                            <th class="px-3 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('admin.integrity.col_check') }}</th>
                            <th class="px-3 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('admin.integrity.col_status') }}</th>
                            <th class="px-3 py-3 text-right text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('admin.integrity.col_count') }}</th>
                            <th class="px-3 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        @foreach($groupChecks as $check)
                            <tr>
                                <td class="px-3 py-3 align-top">
                                    <div class="font-medium text-gray-900 dark:text-gray-100">{{ __($check->labelKey()) }}</div>
                                    <div class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ __($check->descriptionKey(), $check->replacements) }}</div>
                                </td>
                                <td class="px-3 py-3 align-top">
                                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold {{ $badge[$check->status->value] }}">
                                        {{ __($check->status->labelKey()) }}
                                    </span>
                                </td>
                                <td class="px-3 py-3 align-top text-right font-mono text-gray-600 dark:text-gray-300">{{ $check->count }}</td>
                                <td class="px-3 py-3 align-top text-xs">
                                    {{-- Un lien seulement s'il ouvre quelque chose : une page
                                         de detail vide est pire que pas de lien. --}}
                                    @if($check->hasDetail())
                                        <a href="{{ route('admin.outils.integrite.detail', ['check' => $check->detailKey]) }}" class="text-indigo-600 dark:text-indigo-400 underline hover:text-indigo-800">{{ __('admin.integrity.see_detail') }}</a>
                                    @elseif($check->key === 'unscoped_data')
                                        <a href="{{ route('admin.outils.assign-data') }}" class="text-indigo-600 dark:text-indigo-400 underline hover:text-indigo-800">{{ __('admin.integrity.go_assign_data') }}</a>
                                    @elseif($check->key === 'legacy_subfolders')
                                        <a href="{{ route('admin.outils.dossiers', ['type' => 'legacy_child']) }}" class="text-indigo-600 dark:text-indigo-400 underline hover:text-indigo-800">{{ __('admin.integrity.go_dossier_cleanup') }}</a>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endforeach
</x-admin-layout>
