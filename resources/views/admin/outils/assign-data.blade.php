<x-admin-layout title="{{ __('admin.outils.page_title') }}">
    <div class="mb-6">
        <p class="text-sm text-gray-500 dark:text-gray-400">
            {{ __('admin.outils.page_desc') }}
        </p>
    </div>

    <form method="GET" action="{{ route('admin.outils.assign-data') }}" id="org-form"
          class="mb-4 flex flex-wrap items-center gap-3">
        <label class="text-sm font-medium text-gray-700 dark:text-gray-300">
            {{ __('admin.outils.organization') }}
        </label>
        <select name="organization_id" id="organization-select" onchange="this.form.submit()"
                class="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm">
            <option value="">{{ __('admin.outils.org_default') }}</option>
            @foreach($organizations as $org)
            <option value="{{ $org->id }}" {{ $selectedOrgId == $org->id ? 'selected' : '' }}>
                {{ $org->name }} {{ $org->is_default ? '('.strtolower(__('admin.outils.org_default_short')).')' : '' }}
            </option>
            @endforeach
        </select>
    </form>

    @if(session('success'))
    <div class="mb-4 p-3 bg-green-50 dark:bg-green-900/20 border border-green-300 dark:border-green-700 rounded-lg text-sm text-green-700 dark:text-green-300">
        {{ session('success') }}
    </div>
    @endif

    @if(session('error'))
    <div class="mb-4 p-3 bg-red-50 dark:bg-red-900/20 border border-red-300 dark:border-red-700 rounded-lg text-sm text-red-700 dark:text-red-300">
        {{ session('error') }}
    </div>
    @endif

    @if($selectedOrgId)
    <form method="POST" action="{{ route('admin.outils.assign-data.do') }}" id="assign-data-form" class="mb-4">
        @csrf
        <input type="hidden" name="organization_id" value="{{ $selectedOrgId }}">

        <div class="mb-4 flex flex-wrap items-center gap-3">
            <button type="submit" id="submit-btn" disabled
                    class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 disabled:bg-gray-400 disabled:cursor-not-allowed text-white text-sm font-medium rounded-lg transition">
                {{ __('admin.outils.submit_assign') }}
            </button>
            <span id="selected-count" class="text-xs text-gray-500">{{ __('admin.outils.selected_count', ['count' => 0]) }}</span>
        </div>

        <div class="mb-4 p-4 border border-yellow-300 dark:border-yellow-600 bg-yellow-50 dark:bg-yellow-900/20 rounded-lg hidden" id="confirmation-wrapper">
            <label class="block text-sm font-medium text-yellow-800 dark:text-yellow-300 mb-1">
                {{ __('admin.outils.confirm_label') }}
            </label>
            <input type="text" name="confirmation" id="confirmation-input" autocomplete="off"
                   class="px-3 py-2 border border-yellow-400 dark:border-yellow-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm w-64"
                   placeholder="{{ __('admin.outils.confirm_placeholder') }}">
        </div>
    @endif

    <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
        @if($isGlobalView)
        <div class="px-4 py-2 bg-gray-50 dark:bg-gray-700 border-b border-gray-200 dark:border-gray-600 text-xs text-gray-500 dark:text-gray-400">
            {{ __('admin.outils.select_org_prompt') }}
        </div>
        @endif
        <table class="w-full text-sm">
            <thead class="bg-gray-50 dark:bg-gray-700">
                <tr>
                    @if($selectedOrgId)
                    <th class="px-3 py-3 w-10">
                        <input type="checkbox" id="select-all"
                               class="rounded border-gray-300 dark:border-gray-600 text-indigo-600 focus:ring-indigo-500">
                    </th>
                    @endif
                    <th class="px-3 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase">{{ __('admin.outils.dataset') }}</th>
                    <th class="px-3 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase">{{ __('admin.outils.description') }}</th>
                    <th class="px-3 py-3 text-right text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase">{{ __('admin.outils.total') }}</th>
                    @if($selectedOrgId)
                    <th class="px-3 py-3 text-right text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase">{{ __('admin.outils.in_org') }}</th>
                    <th class="px-3 py-3 text-right text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase">{{ __('admin.outils.other_orgs') }}</th>
                    @else
                    <th class="px-3 py-3 text-right text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase">{{ __('admin.outils.with_org') }}</th>
                    @endif
                    <th class="px-3 py-3 text-right text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase">{{ __('admin.outils.without_org') }}</th>
                    <th class="px-3 py-3 text-center text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase">{{ __('admin.outils.mode') }}</th>
                    <th class="px-3 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase">{{ __('admin.outils.action') }}</th>
                    <th class="px-3 py-3 text-center text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase">{{ __('admin.outils.detail') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                @foreach($enriched as $key => $ds)
                <tr class="hover:bg-gray-50 dark:hover:bg-gray-750 {{ $ds['mode'] === 'diagnostic' ? 'opacity-70' : '' }}">
                    @if($selectedOrgId)
                    <td class="px-3 py-3">
                        @if($ds['mode'] === 'assignable')
                        <input type="checkbox" name="datasets[]" value="{{ $key }}"
                               class="dataset-checkbox rounded border-gray-300 dark:border-gray-600 text-indigo-600 focus:ring-indigo-500"
                               data-mode="{{ $ds['mode'] }}" data-critical="{{ $ds['critical'] ? '1' : '0' }}">
                        @else
                        <span class="text-gray-300 dark:text-gray-600 text-xs">{{ __('admin.outils.mode_diagnostic') }}</span>
                        @endif
                    </td>
                    @endif
                    <td class="px-3 py-3">
                        <div class="font-medium text-gray-900 dark:text-gray-100">{{ $ds['label'] }}</div>
                        <div class="font-mono text-xs text-gray-400">{{ $key }}</div>
                    </td>
                    <td class="px-3 py-3 text-gray-600 dark:text-gray-400 text-xs">{{ $ds['description'] }}</td>
                    <td class="px-3 py-3 text-right text-gray-700 dark:text-gray-300">{{ $ds['total'] }}</td>
                    @if($selectedOrgId)
                    <td class="px-3 py-3 text-right {{ $ds['in_org'] > 0 ? 'text-green-600 dark:text-green-400 font-medium' : 'text-gray-400' }}">{{ $ds['in_org'] }}</td>
                    <td class="px-3 py-3 text-right {{ $ds['other_orgs'] > 0 ? 'text-orange-600 dark:text-orange-400 font-medium' : 'text-gray-400' }}">
                        @if($ds['other_orgs'] > 0)
                        <button type="button" class="detail-filter-link text-orange-600 hover:text-orange-800 dark:text-orange-400 dark:hover:text-orange-300 underline" data-dataset="{{ $key }}" data-filter="other_orgs">{{ $ds['other_orgs'] }}</button>
                        @else
                        {{ $ds['other_orgs'] }}
                        @endif
                    </td>
                    @else
                    <td class="px-3 py-3 text-right {{ $ds['with_org'] > 0 ? 'text-green-600 dark:text-green-400 font-medium' : 'text-gray-400' }}">
                        @if($ds['with_org'] > 0)
                        <button type="button" class="detail-filter-link text-green-600 hover:text-green-800 dark:text-green-400 dark:hover:text-green-300 underline" data-dataset="{{ $key }}" data-filter="with_org">{{ $ds['with_org'] }}</button>
                        @else
                        {{ $ds['with_org'] }}
                        @endif
                    </td>
                    @endif
                    <td class="px-3 py-3 text-right {{ $ds['without_organization'] > 0 ? 'text-orange-600 dark:text-orange-400 font-medium' : 'text-gray-400' }}">
                        @if($ds['without_organization'] > 0)
                        <button type="button" class="detail-filter-link text-orange-600 hover:text-orange-800 dark:text-orange-400 dark:hover:text-orange-300 underline" data-dataset="{{ $key }}" data-filter="without_org">{{ $ds['without_organization'] }}</button>
                        @else
                        {{ $ds['without_organization'] }}
                        @endif
                    </td>
                    <td class="px-3 py-3 text-center">
                        @if($ds['mode'] === 'diagnostic')
                        <span class="inline-block px-2 py-0.5 text-xs rounded bg-gray-100 dark:bg-gray-700 text-gray-500 dark:text-gray-400">{{ __('admin.outils.mode_diagnostic') }}</span>
                        @elseif($ds['critical'])
                        <span class="inline-block px-2 py-0.5 text-xs rounded bg-red-100 dark:bg-red-900/30 text-red-600 dark:text-red-400 font-medium">{{ __('admin.outils.mode_critical') }}</span>
                        @else
                        <span class="inline-block px-2 py-0.5 text-xs rounded bg-green-100 dark:bg-green-900/30 text-green-600 dark:text-green-400">{{ __('admin.outils.mode_assignable') }}</span>
                        @endif
                    </td>
                    <td class="px-3 py-3 text-xs">
                        @if($ds['mode'] === 'diagnostic')
                        <span class="text-gray-400">{{ __('admin.outils.no_action') }}</span>
                        @elseif($selectedOrgId)
                            @if($ds['other_orgs'] > 0 || $ds['without_organization'] > 0)
                                @if($ds['without_organization'] > 0)
                                <div class="text-indigo-600 dark:text-indigo-400">{{ __('admin.outils.action_assign_no_org', ['count' => $ds['without_organization']]) }}</div>
                                @endif
                                @if($ds['other_orgs'] > 0)
                                <div class="text-orange-600 dark:text-orange-400">{{ __('admin.outils.action_other_orgs_reassign', ['count' => $ds['other_orgs']]) }}</div>
                                @endif
                            @else
                            <span class="text-gray-400">{{ __('admin.outils.no_action') }}</span>
                            @endif
                        @else
                            @if($ds['without_organization'] > 0)
                            <div class="text-indigo-600 dark:text-indigo-400">{{ __('admin.outils.action_assign_no_org', ['count' => $ds['without_organization']]) }}</div>
                            @elseif($ds['with_org'] > 0)
                            <span class="text-green-600 dark:text-green-400">{{ __('admin.outils.no_action') }}</span>
                            @else
                            <span class="text-gray-400">{{ __('admin.outils.no_action') }}</span>
                            @endif
                        @endif
                    </td>
                    <td class="px-3 py-3 text-center">
                        <button type="button"
                                class="detail-btn text-xs text-indigo-600 hover:text-indigo-800 dark:text-indigo-400 dark:hover:text-indigo-300"
                                data-dataset="{{ $key }}">
                            {{ __('admin.outils.detail') }}
                        </button>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    @if($selectedOrgId)
    </form>
    @endif

    @push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const selectAll = document.getElementById('select-all');
            const checkboxes = document.querySelectorAll('.dataset-checkbox');
            const counter = document.getElementById('selected-count');
            const submitBtn = document.getElementById('submit-btn');
            const confirmationWrapper = document.getElementById('confirmation-wrapper');
            const confirmationInput = document.getElementById('confirmation-input');

            if (!selectAll || !checkboxes.length) return;

            function updateUI() {
                const checked = document.querySelectorAll('.dataset-checkbox:checked');
                const count = checked.length;
                counter.textContent = '{{ __('admin.outils.selected_count', ['count' => ':COUNT']) }}'.replace(':COUNT', count);

                let hasCritical = false;
                checked.forEach(cb => {
                    if (cb.dataset.critical === '1') hasCritical = true;
                });

                submitBtn.disabled = count === 0;

                if (hasCritical) {
                    confirmationWrapper.classList.remove('hidden');
                    submitBtn.textContent = '{{ __('admin.outils.submit_confirm') }}';
                } else {
                    confirmationWrapper.classList.add('hidden');
                    confirmationInput.value = '';
                    submitBtn.textContent = '{{ __('admin.outils.submit_assign') }}';
                }
            }

            selectAll.addEventListener('change', function () {
                checkboxes.forEach(cb => cb.checked = this.checked && !cb.disabled);
                updateUI();
            });

            checkboxes.forEach(cb => {
                cb.addEventListener('change', function () {
                    const all = document.querySelectorAll('.dataset-checkbox:not(:disabled)');
                    const checked = document.querySelectorAll('.dataset-checkbox:checked:not(:disabled)');
                    selectAll.checked = all.length > 0 && all.length === checked.length;
                    updateUI();
                });
            });

            document.querySelectorAll('.detail-btn').forEach(btn => {
                btn.addEventListener('click', function () {
                    const params = new URLSearchParams();
                    params.append('datasets[]', this.dataset.dataset);
                    params.append('organization_id', '{{ $selectedOrgId }}');
                    const url = "{{ route('admin.outils.assign-data.detail') }}?" + params.toString();
                    window.open(url, '_blank', 'width=1000,height=750,scrollbars=yes');
                });
            });

            document.querySelectorAll('.detail-filter-link').forEach(btn => {
                btn.addEventListener('click', function () {
                    const params = new URLSearchParams();
                    params.append('datasets[]', this.dataset.dataset);
                    params.append('organization_id', '{{ $selectedOrgId }}');
                    params.append('filter', this.dataset.filter);
                    const url = "{{ route('admin.outils.assign-data.detail') }}?" + params.toString();
                    window.open(url, '_blank', 'width=1000,height=750,scrollbars=yes');
                });
            });
        });
    </script>
    @endpush
</x-admin-layout>
