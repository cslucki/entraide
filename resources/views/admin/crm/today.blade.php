<x-admin-layout :title="__('admin.crm_today_title')">
    {{-- TASK-1427 — decision Cyril : les echeances de toutes les Organizations, avec les noms. Lecture. --}}
    @include('admin.crm._tabs')
    @php
        $name = fn ($c) => $c->full_name !== '' ? $c->full_name : ($c->email ?: $c->phone ?: __('crm.show.untitled'));
        $fiche = fn ($c) => route('organization.admin.crm.contacts.show', ['organization' => $c->organization->slug, 'contact' => $c->id]);
    @endphp
    <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">{{ $day->translatedFormat('l j F Y') }}</p>
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
        @foreach([['today', $today, __('crm.today.actions_today')], ['overdue', $overdue, __('crm.today.overdue')], ['week', $week, __('crm.today.week')]] as [$key, $rows, $heading])
        <section data-crm-admin-section="{{ $key }}" data-crm-count="{{ $rows->count() }}" class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-5 {{ $key === 'overdue' && $rows->isNotEmpty() ? 'border-red-300 dark:border-red-800' : '' }}">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100 mb-3">{{ $heading }} <span class="px-2 py-0.5 rounded-full text-xs bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300">{{ $rows->count() }}</span></h2>
            @if($rows->isEmpty())
                <p class="text-sm text-gray-500 dark:text-gray-400" data-crm-admin-empty="{{ $key }}">—</p>
            @else
            <ul class="divide-y divide-gray-100 dark:divide-gray-700">
                @foreach($rows as $contact)
                <li class="py-2" data-crm-admin-row="{{ $contact->id }}">
                    <div class="text-xs text-gray-500 dark:text-gray-400">{{ $contact->organization->name }}</div>
                    <a href="{{ $fiche($contact) }}" class="text-sm font-medium text-gray-900 dark:text-gray-100 hover:text-indigo-600 dark:hover:text-indigo-400">{{ $name($contact) }}</a>
                    <div class="text-xs text-gray-600 dark:text-gray-300">{{ __('crm.action_type.'.$contact->next_action_type) }} · {{ $contact->next_action_date->format('d/m') }}@if($contact->nextActionTime()) {{ $contact->nextActionTime() }}@endif @if($contact->next_action_label) — {{ $contact->next_action_label }}@endif</div>
                </li>
                @endforeach
            </ul>
            @endif
        </section>
        @endforeach
    </div>
</x-admin-layout>
