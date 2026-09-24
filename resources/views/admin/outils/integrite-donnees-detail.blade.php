{{-- TASK-1632 — les lignes derriere un controle. Lecture seule, paginee, et
     limitee aux colonnes que la requete du service a nommees : aucun
     `select *` dont une colonne ajoutee demain se retrouverait a l'ecran. --}}
<x-admin-layout :title="__('admin.integrity.detail_title_'.$check->detailKey)">
    <div class="mb-4">
        <a href="{{ route('admin.outils.integrite') }}" class="text-sm text-indigo-600 dark:text-indigo-400 underline">{{ __('admin.integrity.back') }}</a>
    </div>

    <div class="mb-6">
        <h1 class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ __($check->labelKey()) }}</h1>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __($check->descriptionKey(), $check->replacements) }}</p>
    </div>

    <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 dark:bg-gray-700">
                <tr>
                    @foreach($columns as $column)
                        <th class="px-3 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ $column }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                @forelse($rows as $row)
                    <tr>
                        @foreach($columns as $column)
                            <td class="px-3 py-3 align-top font-mono text-xs text-gray-600 dark:text-gray-300">{{ \Illuminate\Support\Str::limit((string) ($row->{$column} ?? ''), 60) }}</td>
                        @endforeach
                    </tr>
                @empty
                    <tr><td colspan="{{ max(count($columns), 1) }}" class="px-3 py-8 text-center text-gray-500 dark:text-gray-400">{{ __('admin.integrity.detail_empty') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-6">{{ $rows->links() }}</div>
</x-admin-layout>
