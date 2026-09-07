    {{-- TASK-1427 — decision Cyril : le SuperAdmin voit TOUS les contacts. Lecture ; on agit depuis la fiche du cockpit. --}}
    <h2 class="text-base font-semibold text-gray-900 dark:text-gray-100 mt-8 mb-3" id="contacts">{{ __('admin.crm_contacts_title') }}</h2>
    @php
        $name = fn ($c) => $c->full_name !== '' ? $c->full_name : ($c->email ?: $c->phone ?: __('crm.show.untitled'));
        $fiche = fn ($c) => route('organization.admin.crm.contacts.show', ['organization' => $c->organization->slug, 'contact' => $c->id]);
    @endphp
    <form method="GET" class="mb-4 grid grid-cols-1 md:grid-cols-6 gap-2 text-sm" data-crm-admin-filters>
        <input type="text" name="search" value="{{ $filters['search'] }}" placeholder="{{ __('crm.filter.search') }}" class="md:col-span-2 px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100">
        <select name="organization" class="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100">
            <option value="">{{ __('admin.crm_filter_all_organizations') }}</option>
            @foreach($organizations as $organization)<option value="{{ $organization->id }}" @selected($filters['organization'] === $organization->id)>{{ $organization->name }}</option>@endforeach
        </select>
        <select name="status" class="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100">
            <option value="">{{ __('crm.filter.all_statuses') }}</option>
            @foreach($statusLabels as $label)<option value="{{ $label }}" @selected($filters['status'] === $label)>{{ $label }}</option>@endforeach
        </select>
        <select name="due" class="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100">
            @foreach(['' => __('crm.filter_due.any'), 'today' => __('crm.filter_due.today'), 'overdue' => __('crm.filter_due.overdue'), 'week' => __('crm.filter_due.week')] as $value => $label)<option value="{{ $value }}" @selected($filters['due'] === $value)>{{ $label }}</option>@endforeach
        </select>
        <select name="contactable" class="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100">
            <option value="">{{ __('admin.crm_filter_contactable_any') }}</option>
            <option value="yes" @selected($filters['contactable'] === 'yes')>{{ __('crm.policy_state.contactable') }}</option>
            <option value="no" @selected($filters['contactable'] === 'no')>{{ __('crm.policy_state.do_not_contact') }}</option>
        </select>
        <label class="flex items-center gap-2 text-gray-700 dark:text-gray-200"><input type="checkbox" name="idle" value="1" @checked($filters['idle'])> {{ __('crm.filter.idle', ['days' => $idleDays]) }}</label>
        <div class="md:col-span-5 flex gap-2">
            <button type="submit" class="px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700">{{ __('admin.crm_filter_apply') }}</button>
            <a href="{{ route('admin.crm.overview') }}" class="px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg text-gray-700 dark:text-gray-200">{{ __('admin.crm_filter_reset') }}</a>
            <span class="self-center text-xs text-gray-500 dark:text-gray-400" data-crm-admin-total="{{ $contacts->total() }}">{{ trans_choice('admin.crm_contacts_count', $contacts->total(), ['count' => $contacts->total()]) }}</span>
        </div>
    </form>

    <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 dark:bg-gray-700">
                <tr>
                    @foreach(['organization', 'contact', 'status', 'last_interaction', 'next_action', 'contactable', 'source'] as $col)
                    <th class="px-3 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide whitespace-nowrap">{{ __('admin.crm_col_'.$col) }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                @forelse($contacts as $contact)
                <tr data-crm-admin-contact="{{ $contact->id }}" data-crm-admin-org="{{ $contact->organization->slug }}">
                    <td class="px-3 py-2 whitespace-nowrap"><a href="{{ route('organization.admin.crm.today', ['organization' => $contact->organization->slug]) }}" class="text-gray-700 dark:text-gray-200 hover:text-indigo-600">{{ $contact->organization->name }}</a></td>
                    <td class="px-3 py-2">
                        <a href="{{ $fiche($contact) }}" class="font-medium text-gray-900 dark:text-gray-100 hover:text-indigo-600 dark:hover:text-indigo-400" data-crm-admin-open="{{ $contact->id }}">{{ $name($contact) }}</a>
                        <div class="text-xs text-gray-500 dark:text-gray-400">{{ $contact->email ?? '' }}@if($contact->email && $contact->phone) · @endif{{ $contact->phone ?? '' }}@if($contact->company) · {{ $contact->company }}@endif</div>
                    </td>
                    <td class="px-3 py-2 whitespace-nowrap">@if($contact->status)<span class="inline-flex items-center gap-1"><span class="w-2 h-2 rounded-full" style="background-color: {{ $contact->status->color ?: '#9ca3af' }}"></span>{{ $contact->status->label }}</span>@else <span class="text-gray-400">—</span>@endif</td>
                    <td class="px-3 py-2 whitespace-nowrap text-gray-600 dark:text-gray-300">{{ $contact->last_interaction_at?->format('d/m/Y') ?? __('crm.never_contacted') }}</td>
                    <td class="px-3 py-2 whitespace-nowrap">@if($contact->hasNextAction())<span class="{{ $contact->isNextActionOverdue() ? 'text-red-600 dark:text-red-400 font-semibold' : 'text-gray-700 dark:text-gray-200' }}">{{ __('crm.action_type.'.$contact->next_action_type) }} · {{ $contact->nextActionDueLabel() }}</span>@else <span class="text-gray-400">—</span>@endif</td>
                    <td class="px-3 py-2 whitespace-nowrap">{!! $contact->isContactable() ? '<span class="text-emerald-700 dark:text-emerald-300 text-xs">'.e(__('crm.policy_state.contactable')).'</span>' : '<span class="text-orange-600 dark:text-orange-400 text-xs font-semibold">'.e(__('crm.policy_state.do_not_contact')).'</span>' !!}</td>
                    <td class="px-3 py-2 whitespace-nowrap text-xs text-gray-500">{{ __('crm.source.'.$contact->source) }}</td>
                </tr>
                @empty
                <tr><td colspan="7" class="px-3 py-6 text-center text-sm text-gray-500" data-crm-admin-empty>{{ __('admin.crm_contacts_empty') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $contacts->links() }}</div>
