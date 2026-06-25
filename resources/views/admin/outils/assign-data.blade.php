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
            <select name="organization_id" id="organization-select"
                    class="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm">
                <option value="">— Organisation par défaut de la plateforme —</option>
                @foreach($organizations as $org)
                <option value="{{ $org->id }}" data-name="{{ $org->name }}">{{ $org->name }} {{ $org->is_default ? '(par défaut)' : '' }}</option>
                @endforeach
            </select>

            <button type="submit" id="submit-btn"
                    class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium rounded-lg transition">
                Affecter la sélection
            </button>

            <span id="selected-count" class="text-xs text-gray-500">0 jeu(x) sélectionné(s)</span>
        </div>

        <div class="mb-4 p-4 border border-yellow-300 dark:border-yellow-600 bg-yellow-50 dark:bg-yellow-900/20 rounded-lg hidden" id="confirmation-wrapper">
            <label class="block text-sm font-medium text-yellow-800 dark:text-yellow-300 mb-1">
                Confirmation requise — tapez <code class="font-bold">REASSIGN USERS</code> pour confirmer
            </label>
            <input type="text" name="confirmation" id="confirmation-input" autocomplete="off"
                   class="px-3 py-2 border border-yellow-400 dark:border-yellow-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm w-64"
                   placeholder="REASSIGN USERS">
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
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Action</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Description</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Total</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Sans org</th>
                        <th class="px-4 py-3 text-center text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Détail</th>
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
                        <td class="px-4 py-3 text-xs action-cell">
                            <span class="action-text text-gray-400">—</span>
                        </td>
                        <td class="px-4 py-3 text-gray-600 dark:text-gray-400">{{ $dataset['description'] }}</td>
                        <td class="px-4 py-3 text-right text-gray-700 dark:text-gray-300">{{ $dataset['total'] }}</td>
                        <td class="px-4 py-3 text-right">
                            @if($dataset['without_organization'] > 0)
                            <span class="text-orange-600 dark:text-orange-400 font-medium">{{ $dataset['without_organization'] }}</span>
                            @else
                            <span class="text-gray-400">0</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-center">
                            <button type="button"
                                    class="detail-btn text-xs text-indigo-600 hover:text-indigo-800 dark:text-indigo-400 dark:hover:text-indigo-300 disabled:text-gray-300 disabled:cursor-not-allowed"
                                    data-dataset="{{ $key }}"
                                    disabled>
                                Voir détail
                            </button>
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
            const orgSelect = document.getElementById('organization-select');
            const actionCells = document.querySelectorAll('.action-text');
            const detailBtns = document.querySelectorAll('.detail-btn');
            const submitBtn = document.getElementById('submit-btn');
            const confirmationWrapper = document.getElementById('confirmation-wrapper');
            const confirmationInput = document.getElementById('confirmation-input');

            function getOrgName() {
                const selected = orgSelect.options[orgSelect.selectedIndex];
                return selected ? selected.dataset.name || 'organisation cible' : 'organisation cible';
            }

            function updateActionsAndDetail() {
                const checked = document.querySelectorAll('.dataset-checkbox:checked');
                const count = checked.length;
                counter.textContent = count + ' jeu(x) sélectionné(s)';

                const orgName = getOrgName();
                const orgId = orgSelect.value;

                let hasUsers = false;

                checked.forEach(cb => {
                    const row = cb.closest('tr');
                    const actionCell = row.querySelector('.action-text');
                    const detailBtn = row.querySelector('.detail-btn');
                    const dataset = cb.value;

                    if (dataset === 'users') hasUsers = true;

                    if (orgId) {
                        actionCell.textContent = 'Affecter à ' + orgName;
                        actionCell.className = 'action-text text-xs text-indigo-600';
                        detailBtn.disabled = false;
                        detailBtn.className = 'detail-btn text-xs text-indigo-600 hover:text-indigo-800 dark:text-indigo-400 dark:hover:text-indigo-300';
                    } else {
                        actionCell.textContent = 'Affecter à (org par défaut)';
                        actionCell.className = 'action-text text-xs text-gray-600';
                        detailBtn.disabled = false;
                        detailBtn.className = 'detail-btn text-xs text-indigo-600 hover:text-indigo-800 dark:text-indigo-400 dark:hover:text-indigo-300';
                    }
                });

                checkboxes.forEach(cb => {
                    if (!cb.checked) {
                        const row = cb.closest('tr');
                        row.querySelector('.action-text').textContent = '—';
                        row.querySelector('.action-text').className = 'action-text text-xs text-gray-400';
                        row.querySelector('.detail-btn').disabled = true;
                        row.querySelector('.detail-btn').className = 'detail-btn text-xs text-indigo-600 hover:text-indigo-800 dark:text-indigo-400 dark:hover:text-indigo-300 disabled:text-gray-300 disabled:cursor-not-allowed';
                    }
                });

                if (hasUsers) {
                    confirmationWrapper.classList.remove('hidden');
                    submitBtn.textContent = 'Confirmer et affecter';
                } else {
                    confirmationWrapper.classList.add('hidden');
                    confirmationInput.value = '';
                    submitBtn.textContent = 'Affecter la sélection';
                }
            }

            selectAll.addEventListener('change', function () {
                checkboxes.forEach(cb => cb.checked = this.checked);
                updateActionsAndDetail();
            });

            checkboxes.forEach(cb => {
                cb.addEventListener('change', updateActionsAndDetail);
            });

            orgSelect.addEventListener('change', updateActionsAndDetail);

            detailBtns.forEach(btn => {
                btn.addEventListener('click', function () {
                    const dataset = this.dataset.dataset;
                    const orgId = orgSelect.value;
                    const params = new URLSearchParams();
                    params.append('datasets[]', dataset);
                    if (orgId) params.append('organization_id', orgId);
                    const url = "{{ route('admin.outils.assign-data.detail') }}?" + params.toString();
                    window.open(url, '_blank', 'width=900,height=700,scrollbars=yes');
                });
            });
        });
    </script>
    @endpush
</x-admin-layout>
