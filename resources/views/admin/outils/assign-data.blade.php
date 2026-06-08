<x-admin-layout title="Affecter les données à une organisation">
    <div class="mb-6">
        <p class="text-sm text-gray-500 dark:text-gray-400">
            Réaligne en masse les données importées depuis la production vers une organisation cible.
            Cette opération met à jour uniquement la colonne <code>organization_id</code> des jeux de données cochés.
        </p>
    </div>

    <form method="POST" action="{{ route('admin.outils.assign-data.do') }}" id="assign-data-form">
        @csrf

        <div class="mb-4 flex flex-wrap items-center gap-3">
            <label class="text-sm font-medium text-gray-700 dark:text-gray-300">Organisation cible :</label>
            <select name="organization_id"
                    class="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm">
                <option value="">— Organisation par défaut de la plateforme —</option>
                @foreach($organizations as $org)
                <option value="{{ $org->id }}">{{ $org->name }} {{ $org->is_default ? '(par défaut)' : '' }}</option>
                @endforeach
            </select>

            <button type="submit"
                    onclick="return confirm('Confirmer l’affectation des jeux de données cochés à cette organisation ?')"
                    class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium rounded-lg transition">
                Affecter la sélection
            </button>

            <span id="selected-count" class="text-xs text-gray-500">0 jeu(x) sélectionné(s)</span>
        </div>

        <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 dark:bg-gray-700">
                    <tr>
                        <th class="px-4 py-3 w-10">
                            <input type="checkbox" id="select-all"
                                   class="rounded border-gray-300 dark:border-gray-600 text-indigo-600 focus:ring-indigo-500">
                        </th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Table</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Données</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Description</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Total</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Sans org</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    @foreach($datasets as $key => $dataset)
                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-750">
                        <td class="px-4 py-3">
                            <input type="checkbox" name="datasets[]" value="{{ $key }}"
                                   class="dataset-checkbox rounded border-gray-300 dark:border-gray-600 text-indigo-600 focus:ring-indigo-500">
                        </td>
                        <td class="px-4 py-3 font-mono text-xs text-gray-500 dark:text-gray-400">{{ $dataset['table'] ?? $key }}</td>
                        <td class="px-4 py-3 font-medium text-gray-900 dark:text-gray-100">{{ $dataset['label'] }}</td>
                        <td class="px-4 py-3 text-gray-600 dark:text-gray-400">{{ $dataset['description'] }}</td>
                        <td class="px-4 py-3 text-right text-gray-700 dark:text-gray-300">{{ $dataset['total'] }}</td>
                        <td class="px-4 py-3 text-right">
                            @if($dataset['without_organization'] > 0)
                            <span class="text-orange-600 dark:text-orange-400 font-medium">{{ $dataset['without_organization'] }}</span>
                            @else
                            <span class="text-gray-400">0</span>
                            @endif
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </form>

    @push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const selectAll = document.getElementById('select-all');
            const checkboxes = document.querySelectorAll('.dataset-checkbox');
            const counter = document.getElementById('selected-count');

            selectAll.addEventListener('change', function () {
                checkboxes.forEach(cb => cb.checked = this.checked);
                updateCounter();
            });

            checkboxes.forEach(cb => {
                cb.addEventListener('change', updateCounter);
            });

            function updateCounter() {
                const checked = document.querySelectorAll('.dataset-checkbox:checked').length;
                counter.textContent = checked + ' jeu(x) sélectionné(s)';
            }
        });
    </script>
    @endpush
</x-admin-layout>
