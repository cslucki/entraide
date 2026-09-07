<x-org-admin-layout :title="__('crm.title')" :organization="$organization">
    {{-- TASK-1416 — CRM-4 : la liste des Contacts, premiere surface du Mini-CRM. --}}
    <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">{{ __('crm.title') }}</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">{{ __('crm.subtitle') }}</p>
        </div>
        <div x-data="{ open: {{ $errors->any() ? 'true' : 'false' }}, pick: {{ $memberSearch !== null ? 'true' : 'false' }} }" class="w-full lg:w-auto">
            <div class="flex flex-wrap gap-2">
                <button type="button" @click="open = !open; if (open) pick = false" data-crm-new-toggle
                    class="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm hover:bg-indigo-700">{{ __('crm.new_contact') }}</button>
                {{-- TASK-1430 — un membre EXISTANT de l'Organization entre dans Relations d'ici aussi. --}}
                <button type="button" @click="pick = !pick; if (pick) open = false" data-crm-member-toggle
                    class="px-4 py-2 border border-indigo-300 dark:border-indigo-700 text-indigo-700 dark:text-indigo-300 rounded-lg text-sm hover:bg-indigo-50 dark:hover:bg-indigo-900/30">{{ __('crm.add_existing_member') }}</button>
            </div>
            <form method="POST" action="{{ route('organization.admin.crm.contacts.store', ['organization' => $organization->slug]) }}"
                  x-show="open" x-cloak data-crm-new-form
                  class="mt-3 grid grid-cols-1 sm:grid-cols-2 gap-3 p-4 bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 lg:w-[36rem]">
                @csrf
                <input type="text" name="first_name" value="{{ old('first_name') }}" placeholder="{{ __('crm.field.first_name') }}" maxlength="100"
                    class="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm">
                <input type="text" name="last_name" value="{{ old('last_name') }}" placeholder="{{ __('crm.field.last_name') }}" maxlength="100"
                    class="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm">
                <input type="email" name="email" value="{{ old('email') }}" placeholder="{{ __('crm.field.email') }}" maxlength="255"
                    class="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm">
                <input type="text" name="phone" value="{{ old('phone') }}" placeholder="{{ __('crm.field.phone') }}" maxlength="30"
                    class="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm">
                <input type="text" name="company" value="{{ old('company') }}" placeholder="{{ __('crm.field.company') }}" maxlength="150"
                    class="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm sm:col-span-2">
                @if($errors->any())
                <ul class="sm:col-span-2 text-xs text-red-600 dark:text-red-400 space-y-0.5" data-crm-new-errors>
                    @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
                </ul>
                @endif
                <p class="sm:col-span-2 text-xs text-gray-500 dark:text-gray-400">{{ __('crm.field.email_or_phone_hint') }}</p>
                <div class="sm:col-span-2 flex justify-end">
                    <button type="submit" class="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm hover:bg-indigo-700">{{ __('crm.save_contact') }}</button>
                </div>
            </form>
            <div x-show="pick" x-cloak data-crm-member-panel
                 class="mt-3 p-4 bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 lg:w-[36rem]">
                {{-- TASK-1430 — la recherche est un GET : rien n'est ecrit tant que l'OrgAdmin n'a pas clique « Ajouter au suivi ». --}}
                <form method="GET" action="{{ route('organization.admin.crm.contacts', ['organization' => $organization->slug]) }}" class="flex gap-2" data-crm-member-search>
                    <input type="search" name="member_search" value="{{ $memberSearch }}" placeholder="{{ __('crm.member_picker.search') }}" maxlength="100" autocomplete="off"
                        class="flex-1 px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm">
                    <button type="submit" class="px-3 py-2 bg-indigo-600 text-white rounded-lg text-sm hover:bg-indigo-700">{{ __('crm.member_picker.search_button') }}</button>
                </form>
                <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ __('crm.member_picker.hint') }}</p>
                @if($memberSearch !== null)
                <ul class="mt-3 divide-y divide-gray-100 dark:divide-gray-700" data-crm-member-results>
                    @forelse($members as $member)
                    <li class="py-2 flex items-center justify-between gap-3" data-crm-member="{{ $member->id }}">
                        <div class="min-w-0">
                            <div class="text-sm font-medium text-gray-900 dark:text-gray-100 truncate">{{ trim(($member->first_name ?? '').' '.($member->name ?? '')) ?: '—' }}</div>
                            <div class="text-xs text-gray-500 truncate">{{ $member->email }}</div>
                        </div>
                        @if($followedContactIds->has($member->id))
                        <a href="{{ route('organization.admin.crm.contacts.show', ['organization' => $organization->slug, 'contact' => $followedContactIds->get($member->id)]) }}" data-crm-member-followed="{{ $member->id }}"
                           class="shrink-0 px-2 py-1 text-xs rounded bg-green-100 dark:bg-green-900/40 text-green-700 dark:text-green-300 hover:underline">{{ __('crm.member_picker.already_followed') }}</a>
                        @else
                        <form method="POST" action="{{ route('organization.admin.crm.members.follow', ['organization' => $organization->slug, 'user' => $member->id]) }}" class="shrink-0">
                            @csrf
                            <button type="submit" data-crm-follow="{{ $member->id }}"
                                class="px-2 py-1 text-xs rounded border border-indigo-300 dark:border-indigo-700 text-indigo-700 dark:text-indigo-300 hover:bg-indigo-50 dark:hover:bg-indigo-900/30">{{ __('crm.follow_member') }}</button>
                        </form>
                        @endif
                    </li>
                    @empty
                    <li class="py-3 text-sm text-gray-400" data-crm-member-empty>{{ __('crm.member_picker.empty') }}</li>
                    @endforelse
                </ul>
                @if($members->count() >= $memberPickerLimit)
                <p class="mt-2 text-xs text-gray-400" data-crm-member-truncated>{{ __('crm.member_picker.truncated', ['limit' => $memberPickerLimit]) }}</p>
                @endif
                @endif
            </div>
        </div>
    </div>

    <form method="GET" class="mb-5 flex flex-wrap gap-3 items-center" data-crm-filters>
        <input type="text" name="search" value="{{ $search }}" placeholder="{{ __('crm.filter.search') }}"
            class="flex-1 min-w-48 px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-indigo-500">
        <select name="status" class="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm">
            <option value="">{{ __('crm.filter.all_statuses') }}</option>
            @foreach($statuses as $status)
            <option value="{{ $status->id }}" {{ $statusFilter === $status->id ? 'selected' : '' }}>{{ $status->label }}</option>
            @endforeach
        </select>
        <select name="due" data-crm-due-filter class="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm">
            @foreach(['any' => '', 'today' => 'today', 'overdue' => 'overdue', 'week' => 'week'] as $key => $value)
            <option value="{{ $value }}" {{ $due === $value ? 'selected' : '' }}>{{ __('crm.filter_due.'.$key) }}</option>
            @endforeach
        </select>
        <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
            <input type="checkbox" name="idle" value="1" {{ $idle ? 'checked' : '' }} class="rounded border-gray-300 dark:border-gray-600">
            {{ __('crm.filter.idle', ['days' => $idleDays]) }}
        </label>
        <button type="submit" class="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm hover:bg-indigo-700">{{ __('navigation.org_admin_filter') }}</button>
        @if($search !== '' || $statusFilter !== '' || $idle || $due !== '')
        <a href="{{ route('organization.admin.crm.contacts', ['organization' => $organization->slug]) }}" class="px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg text-sm text-gray-600 dark:text-gray-400">{{ __('navigation.org_admin_clear') }}</a>
        @endif
    </form>

    <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-x-auto">
        <table class="w-full text-sm" data-crm-contacts-table>
            <thead class="bg-gray-50 dark:bg-gray-700">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('crm.column.name') }}</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('crm.column.email') }}</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('crm.column.phone') }}</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('crm.column.status') }}</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('crm.column.last_interaction') }}</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('crm.column.source') }}</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('crm.column_next_action') }}</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('crm.column.actions') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                @forelse($contacts as $contact)
                <tr class="hover:bg-gray-50 dark:hover:bg-gray-700 align-top" data-crm-contact="{{ $contact->id }}" x-data="{ note: false, plan: false }">
                    <td class="px-4 py-3">
                        <a href="{{ route('organization.admin.crm.contacts.show', ['organization' => $organization->slug, 'contact' => $contact->id]) }}" data-crm-open="{{ $contact->id }}"
                           class="font-medium text-gray-900 dark:text-gray-100 hover:text-indigo-600 dark:hover:text-indigo-400 hover:underline">{{ $contact->fullName !== '' ? $contact->fullName : '—' }}</a>
                        @if($contact->company)<div class="text-xs text-gray-500">{{ $contact->company }}</div>@endif
                        @if($contact->isLinkedToAccount())<span class="inline-block mt-1 px-1.5 py-0.5 rounded text-[10px] uppercase tracking-wide bg-green-100 dark:bg-green-900/40 text-green-700 dark:text-green-300" data-crm-linked>{{ __('crm.linked_account') }}</span>@endif
                        @unless($contact->isContactable())<span class="inline-block mt-1 px-1.5 py-0.5 rounded text-[10px] uppercase tracking-wide bg-red-100 dark:bg-red-900/40 text-red-700 dark:text-red-300" data-crm-do-not-contact>{{ __('crm.do_not_contact') }}</span>@endunless
                    </td>
                    <td class="px-4 py-3 text-xs text-gray-500 break-all">{{ $contact->email ?? '—' }}</td>
                    <td class="px-4 py-3 text-xs text-gray-500 whitespace-nowrap">{{ $contact->phone ?? '—' }}</td>
                    <td class="px-4 py-3">
                        <form method="POST" action="{{ route('organization.admin.crm.contacts.status', ['organization' => $organization->slug, 'contact' => $contact->id]) }}" class="flex items-center gap-1.5">
                            @csrf
                            @if($contact->status?->color)<span class="inline-block w-2.5 h-2.5 rounded-full flex-shrink-0" data-crm-status-color style="background-color: {{ $contact->status->color }}"></span>@endif
                            <select name="status_id" onchange="this.form.submit()" data-crm-status-select aria-label="{{ __('crm.column.status') }}"
                                class="px-2 py-1 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-xs">
                                @if(!$contact->status_id)<option value="" selected>—</option>@endif
                                @foreach($statuses as $status)
                                <option value="{{ $status->id }}" {{ $contact->status_id === $status->id ? 'selected' : '' }}>{{ $status->label }}</option>
                                @endforeach
                                @if($contact->status && !$statuses->contains('id', $contact->status_id))
                                <option value="{{ $contact->status_id }}" selected>{{ $contact->status->label }}</option>
                                @endif
                            </select>
                        </form>
                    </td>
                    <td class="px-4 py-3 text-xs text-gray-500 whitespace-nowrap" data-crm-last-interaction>{{ $contact->last_interaction_at?->diffForHumans() ?? __('crm.never_contacted') }}</td>
                    <td class="px-4 py-3 text-xs text-gray-500">{{ __('crm.source.'.$contact->source) }}</td>
                    <td class="px-4 py-3 text-xs whitespace-nowrap" data-crm-next-action-cell>
                        @if($contact->hasNextAction())
                        <span class="text-gray-900 dark:text-gray-100">{{ __('crm.action_type.'.$contact->next_action_type) }}</span>
                        <span class="{{ $contact->isNextActionOverdue() ? 'text-red-600 dark:text-red-400 font-semibold' : 'text-gray-500' }}" @if($contact->isNextActionOverdue()) data-crm-overdue @endif>· {{ $contact->nextActionDueLabel() }}</span>
                        @if($contact->next_action_label)<div class="text-gray-500 truncate max-w-[12rem]">{{ $contact->next_action_label }}</div>@endif
                        @else
                        <span class="text-gray-400">—</span>
                        @endif
                    </td>
                    <td class="px-4 py-3">
                        <button type="button" @click="plan = !plan" data-crm-plan-toggle
                            class="px-2 py-1 text-xs rounded border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 mr-1">{{ __('crm.next_action.plan') }}</button>
                        <form method="POST" action="{{ route('organization.admin.crm.contacts.next-action.plan', ['organization' => $organization->slug, 'contact' => $contact->id]) }}"
                              x-show="plan" x-cloak data-crm-plan-form class="mt-2 flex flex-col gap-2 min-w-[14rem]">
                            @csrf
                            <select name="next_action_type" class="px-2 py-1 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-xs">
                                @foreach($actionTypes as $type)
                                <option value="{{ $type }}" {{ $contact->next_action_type === $type ? 'selected' : '' }}>{{ __('crm.action_type.'.$type) }}</option>
                                @endforeach
                            </select>
                            <div class="flex gap-1">
                                <input type="date" name="next_action_date" required value="{{ $contact->next_action_date?->format('Y-m-d') }}" class="flex-1 px-2 py-1 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-xs">
                                <input type="time" name="next_action_time" value="{{ $contact->nextActionTime() }}" class="w-24 px-2 py-1 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-xs">
                            </div>
                            <input type="text" name="next_action_label" maxlength="120" value="{{ $contact->next_action_label }}" placeholder="{{ __('crm.next_action.label_placeholder') }}" class="px-2 py-1 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-xs">
                            <button type="submit" class="px-2 py-1 text-xs rounded bg-indigo-600 text-white hover:bg-indigo-700">{{ __('crm.next_action.plan') }}</button>
                        </form>
                        <button type="button" @click="note = !note" data-crm-note-toggle
                            class="px-2 py-1 text-xs rounded border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700">{{ __('crm.add_note') }}</button>
                        <form method="POST" action="{{ route('organization.admin.crm.contacts.notes.store', ['organization' => $organization->slug, 'contact' => $contact->id]) }}"
                              x-show="note" x-cloak data-crm-note-form class="mt-2 flex flex-col gap-2 min-w-[14rem]">
                            @csrf
                            <textarea name="body" rows="2" required maxlength="5000" placeholder="{{ __('crm.note_placeholder') }}"
                                class="px-2 py-1 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-xs"></textarea>
                            <div class="flex gap-2">
                                <select name="channel" class="px-2 py-1 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-xs">
                                    <option value="">{{ __('crm.channel.none') }}</option>
                                    @foreach($channels as $channel)
                                    <option value="{{ $channel }}">{{ __('crm.channel.'.$channel) }}</option>
                                    @endforeach
                                </select>
                                <button type="submit" class="px-2 py-1 text-xs rounded bg-indigo-600 text-white hover:bg-indigo-700">{{ __('crm.save_note') }}</button>
                            </div>
                        </form>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="8" class="px-4 py-8 text-center text-sm text-gray-400" data-crm-empty>{{ __('crm.empty') }}</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if($contacts->hasPages())
    <div class="mt-4">{{ $contacts->links() }}</div>
    @endif
</x-org-admin-layout>
