<x-org-admin-layout :title="$contact->fullName !== '' ? $contact->fullName : __('crm.show.untitled')" :organization="$organization">
    {{-- TASK-1417 — CRM-5 : la fiche Contact, « qu'est-ce que je sais de ma relation avec cette personne ? ». --}}
    <div class="mb-4">
        <a href="{{ route('organization.admin.crm.contacts', ['organization' => $organization->slug]) }}" class="text-sm text-indigo-600 dark:text-indigo-400 hover:underline" data-crm-back>&larr; {{ __('crm.title') }}</a>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-1 space-y-4">
            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-5" data-crm-header>
                <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">{{ $contact->fullName !== '' ? $contact->fullName : __('crm.show.untitled') }}</h1>
                @if($contact->company)<p class="text-sm text-gray-500 dark:text-gray-400">{{ $contact->company }}</p>@endif
                <div class="mt-2 flex flex-wrap gap-1">
                    @if($contact->isLinkedToAccount())
                    <a href="{{ route('organization.admin.users', ['organization' => $organization->slug, 'search' => $contact->user?->email]) }}" data-crm-linked
                       class="inline-block px-1.5 py-0.5 rounded text-[10px] uppercase tracking-wide bg-green-100 dark:bg-green-900/40 text-green-700 dark:text-green-300 hover:underline">{{ __('crm.linked_account') }}</a>
                    @endif
                    @unless($contact->isContactable())
                    <span class="inline-block px-1.5 py-0.5 rounded text-[10px] uppercase tracking-wide bg-red-100 dark:bg-red-900/40 text-red-700 dark:text-red-300" data-crm-do-not-contact>{{ __('crm.do_not_contact') }}</span>
                    @endunless
                </div>
                <dl class="mt-4 space-y-2 text-sm">
                    <div class="flex justify-between gap-3"><dt class="text-gray-500 dark:text-gray-400">{{ __('crm.column.email') }}</dt><dd class="text-gray-900 dark:text-gray-100 break-all text-right" data-crm-email>{{ $contact->email ?? '—' }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-gray-500 dark:text-gray-400">{{ __('crm.column.phone') }}</dt><dd class="text-gray-900 dark:text-gray-100 text-right" data-crm-phone>{{ $contact->phone ?? '—' }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-gray-500 dark:text-gray-400">{{ __('crm.column.source') }}</dt><dd class="text-gray-900 dark:text-gray-100 text-right">{{ __('crm.source.'.$contact->source) }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-gray-500 dark:text-gray-400">{{ __('crm.column.last_interaction') }}</dt><dd class="text-gray-900 dark:text-gray-100 text-right" data-crm-last-interaction>{{ $contact->last_interaction_at?->format('d/m/Y H:i') ?? __('crm.never_contacted') }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-gray-500 dark:text-gray-400">{{ __('crm.show.created_at') }}</dt><dd class="text-gray-900 dark:text-gray-100 text-right">{{ $contact->created_at?->format('d/m/Y') }}@if($contact->createdBy) · {{ $contact->createdBy->fullName }}@endif</dd></div>
                </dl>
                <form method="POST" action="{{ route('organization.admin.crm.contacts.status', ['organization' => $organization->slug, 'contact' => $contact->id]) }}" class="mt-4">
                    @csrf
                    <label class="block text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1">{{ __('crm.column.status') }}</label>
                    <select name="status_id" onchange="this.form.submit()" data-crm-status-select
                        class="w-full px-2 py-1.5 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm">
                        @if(!$contact->status_id)<option value="" selected>—</option>@endif
                        @foreach($statuses as $status)
                        <option value="{{ $status->id }}" {{ $contact->status_id === $status->id ? 'selected' : '' }}>{{ $status->label }}</option>
                        @endforeach
                        @if($contact->status && !$statuses->contains('id', $contact->status_id))
                        <option value="{{ $contact->status_id }}" selected>{{ $contact->status->label }}</option>
                        @endif
                    </select>
                </form>
            </div>

            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-5" x-data="{ open: {{ $errors->hasAny(['first_name','last_name','email','phone','company']) ? 'true' : 'false' }} }">
                <button type="button" @click="open = !open" data-crm-edit-toggle class="text-sm font-semibold text-gray-900 dark:text-gray-100 flex items-center justify-between w-full">
                    <span>{{ __('crm.edit.title') }}</span><span class="text-gray-400" x-text="open ? '−' : '+'"></span>
                </button>
                <form method="POST" action="{{ route('organization.admin.crm.contacts.update', ['organization' => $organization->slug, 'contact' => $contact->id]) }}" x-show="open" x-cloak data-crm-edit-form class="mt-3 grid grid-cols-1 gap-2">
                    @csrf
                    @method('PUT')
                    <input type="text" name="first_name" value="{{ old('first_name', $contact->first_name) }}" placeholder="{{ __('crm.field.first_name') }}" maxlength="100" class="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm">
                    <input type="text" name="last_name" value="{{ old('last_name', $contact->last_name) }}" placeholder="{{ __('crm.field.last_name') }}" maxlength="100" class="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm">
                    <input type="email" name="email" value="{{ old('email', $contact->email) }}" placeholder="{{ __('crm.field.email') }}" maxlength="255" class="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm">
                    <input type="text" name="phone" value="{{ old('phone', $contact->phone) }}" placeholder="{{ __('crm.field.phone') }}" maxlength="30" class="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm">
                    <input type="text" name="company" value="{{ old('company', $contact->company) }}" placeholder="{{ __('crm.field.company') }}" maxlength="150" class="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm">
                    @if($errors->any())
                    <ul class="text-xs text-red-600 dark:text-red-400 space-y-0.5" data-crm-edit-errors>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
                    @endif
                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('crm.edit.hint') }}</p>
                    <div class="flex justify-end"><button type="submit" class="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm hover:bg-indigo-700">{{ __('crm.save_contact') }}</button></div>
                </form>
            </div>
        </div>

        <div class="lg:col-span-2 space-y-4">
            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-5">
                <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100 mb-3">{{ __('crm.show.add_note') }}</h2>
                <form method="POST" action="{{ route('organization.admin.crm.contacts.notes.store', ['organization' => $organization->slug, 'contact' => $contact->id]) }}" data-crm-note-form class="flex flex-col gap-2">
                    @csrf
                    <textarea name="body" rows="3" required maxlength="5000" placeholder="{{ __('crm.note_placeholder') }}" class="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm">{{ old('body') }}</textarea>
                    <div class="flex flex-wrap items-center gap-2">
                        <select name="channel" class="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm">
                            <option value="">{{ __('crm.channel.none') }}</option>
                            @foreach($channels as $channel)
                            <option value="{{ $channel }}">{{ __('crm.channel.'.$channel) }}</option>
                            @endforeach
                        </select>
                        <span class="text-xs text-gray-500 dark:text-gray-400 flex-1">{{ __('crm.show.channel_hint') }}</span>
                        <button type="submit" class="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm hover:bg-indigo-700">{{ __('crm.save_note') }}</button>
                    </div>
                    @error('body')<p class="text-xs text-red-600 dark:text-red-400">{{ $message }}</p>@enderror
                    @error('channel')<p class="text-xs text-red-600 dark:text-red-400">{{ $message }}</p>@enderror
                </form>
            </div>

            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-5" data-crm-timeline>
                <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100 mb-3">{{ __('crm.show.timeline') }} <span class="font-normal text-gray-400">· {{ __('crm.show.timeline_order') }}</span></h2>
                <ol class="space-y-3">
                    @forelse($events as $event)
                    <li class="flex gap-3" data-crm-event="{{ $event->type }}">
                        <div class="mt-1 w-2 h-2 rounded-full flex-shrink-0 {{ match($event->type) { 'note' => 'bg-indigo-500', 'status_changed' => 'bg-amber-500', 'contact_updated' => 'bg-gray-400', default => 'bg-green-500' } }}"></div>
                        <div class="flex-1 min-w-0">
                            <div class="flex flex-wrap items-baseline gap-x-2 text-xs text-gray-500 dark:text-gray-400">
                                <span class="font-semibold text-gray-700 dark:text-gray-300">{{ __('crm.event.'.$event->type) }}</span>
                                @if($event->type === 'note' && ($event->payload['channel'] ?? null))<span class="px-1.5 py-0.5 rounded bg-indigo-50 dark:bg-indigo-900/30 text-indigo-700 dark:text-indigo-300">{{ __('crm.channel.'.$event->payload['channel']) }}</span>@endif
                                <span>{{ $event->occurred_at->format('d/m/Y H:i') }}</span>
                                @if($event->author)<span>· {{ $event->author->fullName }}</span>@endif
                            </div>
                            <div class="text-sm text-gray-900 dark:text-gray-100 mt-0.5">
                                @switch($event->type)
                                    @case('note'){!! nl2br(e($event->payload['body'] ?? '')) !!}@break
                                    @case('status_changed'){{ $event->payload['from_label'] ?? '—' }} &rarr; <strong>{{ $event->payload['to_label'] ?? '—' }}</strong>@break
                                    @case('contact_updated')
                                        <ul class="text-xs space-y-0.5">
                                        @foreach(($event->payload['changes'] ?? []) as $field => $change)
                                            <li>{{ __('crm.field.'.$field) }} : <span class="line-through text-gray-400">{{ $change['from'] ?? '—' }}</span> &rarr; {{ $change['to'] ?? '—' }}</li>
                                        @endforeach
                                        </ul>
                                    @break
                                    @default —
                                @endswitch
                            </div>
                        </div>
                    </li>
                    @empty
                    <li class="text-sm text-gray-400" data-crm-timeline-empty>{{ __('crm.show.timeline_empty') }}</li>
                    @endforelse
                </ol>
            </div>
        </div>
    </div>
</x-org-admin-layout>
