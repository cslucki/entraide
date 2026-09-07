<x-org-admin-layout :title="__('crm.today.title')" :organization="$organization">
    {{-- TASK-1424 — CRM-14 : « Aujourd'hui », la page d'entree de Relations. Lecture seule : AUCUN formulaire ici. --}}
    @php
        $contactName = fn ($c) => $c ? ($c->full_name !== '' ? $c->full_name : ($c->email ?: $c->phone ?: __('crm.show.untitled'))) : __('crm.today.deleted_contact');
        $fiche = fn ($c) => route('organization.admin.crm.contacts.show', ['organization' => $organization->slug, 'contact' => $c->id]);
        $list = fn (array $q) => route('organization.admin.crm.contacts', ['organization' => $organization->slug] + $q);
    @endphp
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">{{ __('crm.today.title') }} <span class="text-base font-normal text-gray-500 dark:text-gray-400">· {{ $day->translatedFormat('l j F Y') }}</span></h1>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">{{ __('crm.today.subtitle') }}</p>
    </div>

    @if(session('success'))
        <div class="mb-4 p-3 rounded-lg bg-green-50 dark:bg-green-900/20 text-green-800 dark:text-green-200 text-sm">{{ session('success') }}</div>
    @endif

    {{-- Les trois echeances --}}
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-6">
        @foreach([['today', $today, 'indigo', __('crm.today.actions_today'), __('crm.today.empty_today')], ['overdue', $overdue, 'red', __('crm.today.overdue'), __('crm.today.empty_overdue')], ['week', $week, 'sky', __('crm.today.week'), __('crm.today.empty_week')]] as [$key, $rows, $tone, $heading, $empty])
        <section data-crm-section="{{ $key }}" data-crm-count="{{ $rows->count() }}"
                 class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-5 {{ $key === 'overdue' && $rows->isNotEmpty() ? 'border-red-300 dark:border-red-800' : '' }}">
            <div class="flex items-center justify-between mb-3">
                <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100 flex items-center gap-2">
                    {{ $heading }}
                    <span class="px-2 py-0.5 rounded-full text-xs {{ $rows->isEmpty() ? 'bg-gray-100 dark:bg-gray-700 text-gray-500' : ($tone === 'red' ? 'bg-red-100 dark:bg-red-900/40 text-red-700 dark:text-red-300' : ($tone === 'sky' ? 'bg-sky-100 dark:bg-sky-900/40 text-sky-700 dark:text-sky-300' : 'bg-indigo-100 dark:bg-indigo-900/40 text-indigo-700 dark:text-indigo-300')) }}">{{ $rows->count() }}</span>
                </h2>
                @if($rows->isNotEmpty())
                <a href="{{ $list(['due' => $key]) }}" class="text-xs text-indigo-600 dark:text-indigo-400 hover:underline">{{ __('crm.today.open_list') }}</a>
                @endif
            </div>
            @if($rows->isEmpty())
                <p class="text-sm text-gray-500 dark:text-gray-400" data-crm-empty="{{ $key }}">{{ $empty }}</p>
            @else
            <ul class="divide-y divide-gray-100 dark:divide-gray-700">
                @foreach($rows as $contact)
                <li class="py-2 flex items-start gap-3" data-crm-row="{{ $contact->id }}">
                    <div class="flex-1 min-w-0">
                        <a href="{{ $fiche($contact) }}" class="text-sm font-medium text-gray-900 dark:text-gray-100 hover:text-indigo-600 dark:hover:text-indigo-400 truncate block">{{ $contactName($contact) }}</a>
                        <div class="text-xs text-gray-500 dark:text-gray-400 flex flex-wrap items-center gap-x-2">
                            <span class="font-medium text-gray-700 dark:text-gray-300">{{ __('crm.action_type.'.$contact->next_action_type) }}</span>
                            @if($key !== 'today')<span>{{ $contact->next_action_date->format('d/m') }}</span>@endif
                            @if($contact->nextActionTime())<span>{{ $contact->nextActionTime() }}</span>@endif
                            @if($contact->next_action_label)<span class="truncate">— {{ $contact->next_action_label }}</span>@endif
                            @if($contact->status)<span class="inline-flex items-center gap-1"><span class="w-2 h-2 rounded-full" style="background-color: {{ $contact->status->color ?: '#9ca3af' }}"></span>{{ $contact->status->label }}</span>@endif
                            @unless($contact->isContactable())<span class="text-orange-600 dark:text-orange-400">{{ __('crm.do_not_contact') }}</span>@endunless
                        </div>
                    </div>
                    {{-- Lecture seule (MASTER Q36) : on marque « faite » depuis la fiche, avec tout le contexte. --}}
                    <a href="{{ $fiche($contact) }}" class="text-xs text-indigo-600 dark:text-indigo-400 hover:underline whitespace-nowrap">{{ __('crm.today.open_contact') }}</a>
                </li>
                @endforeach
            </ul>
            @endif
        </section>
        @endforeach
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-6">
        {{-- Sans contact depuis N jours --}}
        <section data-crm-section="idle" data-crm-idle-count="{{ $idleCount }}" class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-5">
            <div class="flex items-center justify-between mb-1">
                <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100 flex items-center gap-2">
                    {{ __('crm.today.idle', ['days' => $idleDays]) }}
                    <span class="px-2 py-0.5 rounded-full text-xs {{ $idleCount === 0 ? 'bg-gray-100 dark:bg-gray-700 text-gray-500' : 'bg-amber-100 dark:bg-amber-900/40 text-amber-700 dark:text-amber-300' }}">{{ $idleCount }}</span>
                </h2>
                @if($idleCount > 0)
                <a href="{{ $list(['idle' => 1]) }}" class="text-xs text-indigo-600 dark:text-indigo-400 hover:underline">{{ __('crm.today.see_all', ['count' => $idleCount]) }}</a>
                @endif
            </div>
            <p class="text-xs text-gray-500 dark:text-gray-400 mb-3">{{ __('crm.today.idle_hint', ['days' => $idleDays]) }}</p>
            @if($idle->isEmpty())
                <p class="text-sm text-gray-500 dark:text-gray-400" data-crm-empty="idle">{{ __('crm.today.empty_idle') }}</p>
            @else
            <ul class="divide-y divide-gray-100 dark:divide-gray-700">
                @foreach($idle as $contact)
                <li class="py-2 flex items-center justify-between gap-3" data-crm-row="{{ $contact->id }}">
                    <a href="{{ $fiche($contact) }}" class="text-sm font-medium text-gray-900 dark:text-gray-100 hover:text-indigo-600 dark:hover:text-indigo-400 truncate">{{ $contactName($contact) }}</a>
                    <span class="text-xs text-gray-500 dark:text-gray-400 whitespace-nowrap">{{ $contact->last_interaction_at ? $contact->last_interaction_at->format('d/m/Y') : __('crm.today.never') }}</span>
                </li>
                @endforeach
            </ul>
            @endif
        </section>

        {{-- Volumes par statut --}}
        <section data-crm-section="statuses" data-crm-total="{{ $total }}" data-crm-unassigned="{{ $unassignedCount }}" class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-5">
            <div class="flex items-center justify-between mb-3">
                <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ __('crm.today.by_status') }}</h2>
                <span class="text-xs text-gray-500 dark:text-gray-400">{{ __('crm.today.total') }} : <strong class="text-gray-900 dark:text-gray-100">{{ $total }}</strong></span>
            </div>
            <ul class="space-y-2">
                @foreach($byStatus as $status)
                @if($status->is_active || $status->contacts_count > 0)
                <li class="flex items-center gap-3 {{ $status->is_active ? '' : 'opacity-60' }}" data-crm-status-count="{{ $status->contacts_count }}" data-crm-status="{{ $status->id }}">
                    <span class="w-2.5 h-2.5 rounded-full flex-shrink-0" style="background-color: {{ $status->color ?: '#9ca3af' }}"></span>
                    <a href="{{ $list(['status' => $status->id]) }}" class="text-sm text-gray-800 dark:text-gray-200 hover:text-indigo-600 dark:hover:text-indigo-400 w-36 truncate">{{ $status->label }}</a>
                    <div class="flex-1 h-2 rounded-full bg-gray-100 dark:bg-gray-700 overflow-hidden">
                        <div class="h-2 rounded-full" style="width: {{ $total > 0 ? round($status->contacts_count * 100 / $total) : 0 }}%; background-color: {{ $status->color ?: '#9ca3af' }}"></div>
                    </div>
                    <span class="text-sm font-semibold text-gray-900 dark:text-gray-100 w-8 text-right">{{ $status->contacts_count }}</span>
                </li>
                @endif
                @endforeach
                @if($unassignedCount > 0)
                <li class="flex items-center gap-3 text-gray-500" data-crm-status-unassigned="{{ $unassignedCount }}">
                    <span class="w-2.5 h-2.5 rounded-full flex-shrink-0 bg-gray-300 dark:bg-gray-600"></span>
                    <span class="text-sm w-36 truncate">{{ __('crm.today.unassigned') }}</span>
                    <div class="flex-1"></div>
                    <span class="text-sm font-semibold w-8 text-right">{{ $unassignedCount }}</span>
                </li>
                @endif
            </ul>
        </section>
    </div>

    {{-- Derniers faits --}}
    <section data-crm-section="facts" data-crm-count="{{ $facts->count() }}" class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-5">
        <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100 mb-1">{{ __('crm.today.facts') }}</h2>
        <p class="text-xs text-gray-500 dark:text-gray-400 mb-3">{{ __('crm.today.facts_hint', ['count' => $factsLimit]) }}</p>
        @if($facts->isEmpty())
            <p class="text-sm text-gray-500 dark:text-gray-400" data-crm-empty="facts">{{ __('crm.today.empty_facts') }}</p>
        @else
        <ol class="divide-y divide-gray-100 dark:divide-gray-700">
            @foreach($facts as $event)
            <li class="py-2 flex gap-3" data-crm-event="{{ $event->type }}">
                <div class="mt-1.5 w-2 h-2 rounded-full flex-shrink-0 {{ match($event->type) { 'note' => 'bg-indigo-500', 'status_changed' => 'bg-amber-500', 'contact_updated' => 'bg-gray-400', 'next_action_planned' => 'bg-sky-500', 'next_action_done' => 'bg-emerald-600', 'email_sent' => 'bg-violet-500', 'email_failed' => 'bg-red-500', 'contact_policy_changed' => 'bg-orange-500', default => 'bg-green-500' } }}"></div>
                <div class="flex-1 min-w-0 text-sm">
                    <div class="flex flex-wrap items-baseline gap-x-2">
                        @if($event->contact)
                            <a href="{{ $fiche($event->contact) }}" class="font-medium text-gray-900 dark:text-gray-100 hover:text-indigo-600 dark:hover:text-indigo-400">{{ $contactName($event->contact) }}</a>
                            @if($event->contact->trashed())<span class="text-xs text-gray-400">({{ __('crm.today.deleted_contact') }})</span>@endif
                        @else
                            <span class="text-gray-500">{{ __('crm.today.deleted_contact') }}</span>
                        @endif
                        <span class="text-xs font-semibold text-gray-600 dark:text-gray-300">{{ in_array($event->type, ['next_action_planned', 'next_action_done', 'email_sent', 'email_failed', 'contact_policy_changed'], true) ? __('crm.event_'.$event->type) : __('crm.event.'.$event->type) }}</span>
                        <span class="text-xs text-gray-500 dark:text-gray-400">{{ $event->occurred_at->format('d/m H:i') }}@if($event->author) · {{ $event->author->fullName }}@endif</span>
                    </div>
                    <div class="text-xs text-gray-700 dark:text-gray-300 truncate">
                        @switch($event->type)
                            @case('note'){{ \Illuminate\Support\Str::limit((string) ($event->payload['body'] ?? ''), 120) }}@break
                            @case('status_changed'){{ $event->payload['from_label'] ?? '—' }} &rarr; <strong>{{ $event->payload['to_label'] ?? '—' }}</strong>@break
                            @case('contact_updated'){{ implode(', ', array_map(fn ($f) => __('crm.field.'.$f), array_keys($event->payload['changes'] ?? []))) }}@break
                            @case('next_action_planned')
                            @case('next_action_done'){{ __('crm.action_type.'.($event->payload['action_type'] ?? 'other')) }}@if(!empty($event->payload['date'])) · {{ \Carbon\Carbon::parse($event->payload['date'])->format('d/m') }}@endif@if(!empty($event->payload['time'])) {{ $event->payload['time'] }}@endif@if(!empty($event->payload['label'])) — {{ $event->payload['label'] }}@endif @break
                            @case('email_sent')
                            @case('email_failed'){{ $event->payload['subject'] ?? '' }}@if(!empty($event->payload['error'])) <span class="text-red-600 dark:text-red-400">{{ $event->payload['error'] }}</span>@endif @break
                            @case('contact_policy_changed'){{ __('crm.policy_state.'.(($event->payload['to_contactable'] ?? true) ? 'contactable' : 'do_not_contact')) }}@if(!empty($event->payload['note'])) — {{ $event->payload['note'] }}@endif @break
                            @default
                        @endswitch
                    </div>
                </div>
            </li>
            @endforeach
        </ol>
        @endif
    </section>
</x-org-admin-layout>
