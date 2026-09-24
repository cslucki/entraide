{{-- TASK-1631 — la vue detail d'UN dataset, avec son filtre dans l'URL.
     Les colonnes affichees viennent de la liste BLANCHE du registre : une
     colonne non declaree n'est jamais rendue, y compris une colonne ajoutee
     demain a cette table. L'ancien ecran faisait l'inverse (toArray() moins
     treize noms connus). --}}
<x-admin-layout :title="__('admin.assign_data.detail_title', ['dataset' => __($dataset->labelKey())])">
    <div class="mb-4">
        <a href="{{ route('admin.outils.assign-data') }}" class="text-sm text-indigo-600 dark:text-indigo-400 underline">{{ __('admin.assign_data.back') }}</a>
    </div>

    <div class="mb-4">
        <h1 class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ __($dataset->labelKey()) }}</h1>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __($dataset->descriptionKey()) }}</p>
        <p class="mt-2 text-xs">
            <span class="inline-flex items-center rounded-full px-2 py-0.5 font-semibold
                {{ $dataset->isAssignable() ? 'bg-indigo-100 text-indigo-800 dark:bg-indigo-900/40 dark:text-indigo-300' : 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300' }}">
                {{ __($dataset->classification->labelKey()) }}
            </span>
            <span class="ml-2 text-gray-500 dark:text-gray-400">{{ __($dataset->classification->hintKey()) }}</span>
        </p>
    </div>

    {{-- Les trois filtres, et les memes compteurs que le tableau : c'est ce
         qui garantit qu'un « 14 » cliqué ouvre bien 14 lignes. --}}
    <div class="mb-4 flex flex-wrap gap-2">
        @foreach($filters as $available)
            @php
                $count = match ($available) {
                    'with_organization' => $with_organization,
                    'without_organization' => $without_organization,
                    default => $total,
                };
            @endphp
            <a href="{{ route('admin.outils.assign-data.detail', ['dataset' => $dataset->key, 'filter' => $available]) }}"
               class="rounded-lg px-3 py-1.5 text-xs font-semibold {{ $filter === $available ? 'bg-indigo-600 text-white' : 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-200' }}">
                {{ __('admin.assign_data.filter_'.$available) }} ({{ $count }})
            </a>
        @endforeach
    </div>

    <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 dark:bg-gray-700">
                <tr>
                    @foreach($dataset->columns as $column)
                        <th class="px-3 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">
                            {{ $column === 'organization_id' ? __('admin.assign_data.organization_column') : $column }}
                        </th>
                    @endforeach
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                @forelse($rows as $row)
                    <tr>
                        @foreach($dataset->columns as $column)
                            <td class="px-3 py-3 align-top text-gray-600 dark:text-gray-300 font-mono text-xs">
                                @if($column === 'organization_id')
                                    @if($row->organization_id)
                                        {{ $organizationNames[$row->organization_id] ?? $row->organization_id }}
                                    @else
                                        <span class="text-orange-600 dark:text-orange-400">{{ __('admin.assign_data.organization_none') }}</span>
                                    @endif
                                @else
                                    {{ \Illuminate\Support\Str::limit((string) ($row->{$column} ?? ''), 60) }}
                                @endif
                            </td>
                        @endforeach
                    </tr>
                @empty
                    <tr><td colspan="{{ count($dataset->columns) }}" class="px-3 py-8 text-center text-gray-500 dark:text-gray-400">{{ __('admin.assign_data.detail_empty') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-6">{{ $rows->links() }}</div>
</x-admin-layout>
