    {{-- TASK-1417 — CRM-5 : la fiche Contact, « qu'est-ce que je sais de ma relation avec cette personne ? ».
         TASK-1431 — partial partage : l'OrgAdmin (layout org) et la plateforme (layout admin) rendent la MEME
         fiche ; `$r()` fabrique les URLs d'action du contexte, `$links` les liens hors CRM. --}}
    @php $mutable = ! $contact->trashed(); @endphp
    <div class="mb-4">
        <a href="{{ $links['index'] }}" class="text-sm text-indigo-600 dark:text-indigo-400 hover:underline" data-crm-back>&larr; {{ __('crm.title') }}</a>
    </div>
    @unless($mutable)
    <div class="mb-4 p-4 rounded-xl border border-red-200 dark:border-red-800 bg-red-50 dark:bg-red-900/20 text-sm text-red-800 dark:text-red-200 flex flex-wrap items-center gap-3" data-crm-trashed-banner>
        <span>{{ __('crm.trashed.banner', ['date' => $contact->deleted_at->format('d/m/Y H:i')]) }}</span>
        @isset($links['restore'])
        <form method="POST" action="{{ $links['restore'] }}">
            @csrf
            <button type="submit" data-crm-admin-restore class="px-3 py-1 rounded bg-green-600 text-white text-xs hover:bg-green-700">{{ __('crm.trashed.restore') }}</button>
        </form>
        @endisset
    </div>
    @endunless

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-1 space-y-4">
            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-5" data-crm-header>
                <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">{{ $contact->fullName !== '' ? $contact->fullName : __('crm.show.untitled') }}</h1>
                @if($contact->company)<p class="text-sm text-gray-500 dark:text-gray-400">{{ $contact->company }}</p>@endif
                <div class="mt-2 flex flex-wrap gap-1">
                    @if($contact->isLinkedToAccount())
                    <a href="{{ $links['linked_user'] }}" data-crm-linked
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
                @include('admin.org.crm._attribution', ['attribution' => $attribution ?? null])
                @if(isset($links['delete']) && $mutable)
                {{-- TASK-1431 — suppression PLATEFORME : SoftDelete, tracee, restaurable. Jamais proposee a l'OrgAdmin (MASTER Q50). --}}
                <form method="POST" action="{{ $links['delete'] }}" class="mt-4" data-crm-admin-delete-form onsubmit="return confirm(this.dataset.confirm)" data-confirm="{{ __('crm.trashed.confirm') }}">
                    @csrf
                    @method('DELETE')
                    <button type="submit" data-crm-admin-delete class="px-3 py-1.5 text-xs rounded border border-red-300 dark:border-red-700 text-red-700 dark:text-red-300 hover:bg-red-50 dark:hover:bg-red-900/30">{{ __('crm.trashed.delete') }}</button>
                </form>
                @endif
                @if($mutable)
                <form method="POST" action="{{ $r('contacts.status', ['contact' => $contact->id]) }}" class="mt-4">
                    @csrf
                    <label class="block text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1 flex items-center gap-1.5">@if($contact->status?->color)<span class="inline-block w-2.5 h-2.5 rounded-full" data-crm-status-color style="background-color: {{ $contact->status->color }}"></span>@endif{{ __('crm.column.status') }}</label>
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
                @endif
            </div>

            @if($mutable)
            {{-- TASK-1418 — CRM-6 : la prochaine action, sur la fiche. --}}
            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-5" data-crm-next-action x-data="{ open: {{ $errors->hasAny(['next_action_type','next_action_date','next_action_time','next_action_label']) ? 'true' : 'false' }} }">
                <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100 mb-2">{{ __('crm.next_action.title') }}</h2>
                @if($contact->hasNextAction())
                <div class="text-sm text-gray-900 dark:text-gray-100 flex flex-wrap items-center gap-2" data-crm-next-action-current>
                    <span class="font-medium">{{ __('crm.action_type.'.$contact->next_action_type) }}</span>
                    <span class="{{ $contact->isNextActionOverdue() ? 'text-red-600 dark:text-red-400 font-semibold' : 'text-gray-600 dark:text-gray-300' }}" @if($contact->isNextActionOverdue()) data-crm-overdue @endif>{{ $contact->nextActionDueLabel() }}@if($contact->isNextActionOverdue()) · {{ __('crm.next_action.overdue') }}@endif</span>
                    @if($contact->next_action_label)<span class="text-gray-500 dark:text-gray-400">— {{ $contact->next_action_label }}</span>@endif
                </div>
                <div class="mt-3 flex gap-2">
                    <form method="POST" action="{{ $r('contacts.next-action.complete', ['contact' => $contact->id]) }}">
                        @csrf
                        <button type="submit" data-crm-next-action-done class="px-3 py-1.5 text-xs rounded bg-green-600 text-white hover:bg-green-700">{{ __('crm.next_action.done') }}</button>
                    </form>
                    <button type="button" @click="open = !open" data-crm-next-action-toggle class="px-3 py-1.5 text-xs rounded border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700">{{ __('crm.next_action.replan') }}</button>
                </div>
                @else
                <p class="text-sm text-gray-400" data-crm-next-action-none>{{ __('crm.next_action.none') }}</p>
                <button type="button" @click="open = !open" data-crm-next-action-toggle class="mt-3 px-3 py-1.5 text-xs rounded bg-indigo-600 text-white hover:bg-indigo-700">{{ __('crm.next_action.plan') }}</button>
                @endif
                <form method="POST" action="{{ $r('contacts.next-action.plan', ['contact' => $contact->id]) }}" x-show="open" x-cloak data-crm-next-action-form class="mt-3 grid grid-cols-1 gap-2">
                    @csrf
                    <select name="next_action_type" class="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm">
                        @foreach($actionTypes as $type)
                        <option value="{{ $type }}" {{ old('next_action_type', $contact->next_action_type) === $type ? 'selected' : '' }}>{{ __('crm.action_type.'.$type) }}</option>
                        @endforeach
                    </select>
                    <div class="grid grid-cols-2 gap-2">
                        <input type="date" name="next_action_date" required value="{{ old('next_action_date', $contact->next_action_date?->format('Y-m-d')) }}" class="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm">
                        <input type="time" name="next_action_time" value="{{ old('next_action_time', $contact->nextActionTime()) }}" placeholder="{{ __('crm.next_action.time_placeholder') }}" class="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm">
                    </div>
                    <input type="text" name="next_action_label" maxlength="120" value="{{ old('next_action_label', $contact->next_action_label) }}" placeholder="{{ __('crm.next_action.label_placeholder') }}" class="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm">
                    @error('next_action_type')<p class="text-xs text-red-600 dark:text-red-400">{{ $message }}</p>@enderror
                    @error('next_action_date')<p class="text-xs text-red-600 dark:text-red-400">{{ $message }}</p>@enderror
                    @error('next_action_time')<p class="text-xs text-red-600 dark:text-red-400">{{ $message }}</p>@enderror
                    <div class="flex justify-end"><button type="submit" class="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm hover:bg-indigo-700">{{ __('crm.next_action.plan') }}</button></div>
                </form>
            </div>

            {{-- TASK-1422 — CRM-13 : contactabilite, decidee explicitement (raison, auteur, trace). --}}
            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-5" data-crm-policy x-data="{ open: {{ $errors->hasAny(['reason', 'note', 'action']) ? 'true' : 'false' }} }">
                <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100 mb-2">{{ __('crm.policy.title') }}</h2>
                @if($contact->isContactable())
                <p class="text-sm text-gray-700 dark:text-gray-300" data-crm-policy-state="contactable">{{ __('crm.policy.state_contactable') }}</p>
                <button type="button" @click="open = !open" data-crm-policy-toggle class="mt-3 px-3 py-1.5 text-xs rounded border border-red-300 dark:border-red-700 text-red-700 dark:text-red-300 hover:bg-red-50 dark:hover:bg-red-900/30">{{ __('crm.policy.block') }}</button>
                @else
                <p class="text-sm text-red-700 dark:text-red-300" data-crm-policy-state="blocked">{{ __('crm.policy.state_blocked', ['date' => $contact->do_not_contact_at->format('d/m/Y')]) }}</p>
                <button type="button" @click="open = !open" data-crm-policy-toggle class="mt-3 px-3 py-1.5 text-xs rounded border border-green-300 dark:border-green-700 text-green-700 dark:text-green-300 hover:bg-green-50 dark:hover:bg-green-900/30">{{ __('crm.policy.allow') }}</button>
                @endif
                <form method="POST" action="{{ $r('contacts.policy', ['contact' => $contact->id]) }}" x-show="open" x-cloak data-crm-policy-form class="mt-3 grid grid-cols-1 gap-2">
                    @csrf
                    <input type="hidden" name="action" value="{{ $contact->isContactable() ? 'block' : 'allow' }}">
                    <select name="reason" required class="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm">
                        @foreach($policyReasons as $reason)
                        <option value="{{ $reason }}" {{ old('reason') === $reason ? 'selected' : '' }}>{{ __('crm.policy.reason.'.$reason) }}</option>
                        @endforeach
                    </select>
                    <input type="text" name="note" maxlength="500" value="{{ old('note') }}" placeholder="{{ __('crm.policy.note_placeholder') }}" class="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm">
                    @error('reason')<p class="text-xs text-red-600 dark:text-red-400">{{ $message }}</p>@enderror
                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('crm.policy.hint') }}</p>
                    <div class="flex justify-end"><button type="submit" class="px-4 py-2 rounded-lg text-sm text-white {{ $contact->isContactable() ? 'bg-red-600 hover:bg-red-700' : 'bg-green-600 hover:bg-green-700' }}">{{ $contact->isContactable() ? __('crm.policy.confirm_block') : __('crm.policy.confirm_allow') }}</button></div>
                </form>
            </div>

            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-5" x-data="{ open: {{ $errors->hasAny(['first_name','last_name','email','phone','company']) ? 'true' : 'false' }} }">
                <button type="button" @click="open = !open" data-crm-edit-toggle class="text-sm font-semibold text-gray-900 dark:text-gray-100 flex items-center justify-between w-full">
                    <span>{{ __('crm.edit.title') }}</span><span class="text-gray-400" x-text="open ? '−' : '+'"></span>
                </button>
                <form method="POST" action="{{ $r('contacts.update', ['contact' => $contact->id]) }}" x-show="open" x-cloak data-crm-edit-form class="mt-3 grid grid-cols-1 gap-2">
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
            @endif
        </div>

        <div class="lg:col-span-2 space-y-4">
            @if($mutable)
            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-5">
                <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100 mb-3">{{ __('crm.show.add_note') }}</h2>
                <form method="POST" action="{{ $r('contacts.notes.store', ['contact' => $contact->id]) }}" data-crm-note-form class="flex flex-col gap-2">
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

            {{-- TASK-1421 — CRM-7b : envoyer un modele d'email a ce Contact (l'humain confirme sur la page suivante). --}}
            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-5" data-crm-email-block>
                <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100 mb-2">{{ __('crm.email.block_title') }}</h2>
                @if(!$contact->isContactable())
                <p class="text-xs text-red-600 dark:text-red-400" data-crm-email-blocked>{{ __('crm.email.blocked_do_not_contact') }}</p>
                @elseif(!$contact->email)
                <p class="text-xs text-gray-500 dark:text-gray-400" data-crm-email-blocked>{{ __('crm.email.blocked_no_email') }}</p>
                @elseif($emailTemplates->isEmpty())
                <p class="text-xs text-gray-500 dark:text-gray-400" data-crm-email-no-template>{{ __('crm.email.no_template') }} <a href="{{ $links['templates_create'] }}" class="text-indigo-600 dark:text-indigo-400 hover:underline">{{ __('crm.templates.new') }}</a></p>
                @else
                <form method="GET" action="{{ $r('contacts.email.pick', ['contact' => $contact->id]) }}" class="flex gap-2" data-crm-email-pick>
                    <select name="template" required class="flex-1 px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm">
                        @foreach($emailTemplates as $emailTemplate)
                        <option value="{{ $emailTemplate->id }}">{{ $emailTemplate->name }}</option>
                        @endforeach
                    </select>
                    <button type="submit" class="px-3 py-2 bg-indigo-600 text-white rounded-lg text-sm hover:bg-indigo-700">{{ __('crm.email.prepare') }}</button>
                </form>
                @endif
            </div>

            @endif

            {{-- TASK-1426 — CRM-8 : ce qui a ete tente par email, avec quel resultat (lecture seule, tenant + Contact). --}}
            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-5" data-crm-email-history>
                <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100 mb-1">{{ __('crm.email_history.title') }}</h2>
                <p class="text-xs text-gray-500 dark:text-gray-400 mb-3">{{ __('crm.email_history.status_hint') }}</p>
                @if($emailLogs->isEmpty())
                    <p class="text-sm text-gray-500 dark:text-gray-400" data-crm-email-history-empty>{{ __('crm.email_history.empty') }}</p>
                @else
                <ul class="divide-y divide-gray-100 dark:divide-gray-700">
                    @foreach($emailLogs as $log)
                    <li class="py-2 text-sm" data-crm-email-log="{{ $log->id }}" data-crm-email-status="{{ $log->status }}">
                        <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
                            <span class="font-medium text-gray-900 dark:text-gray-100 truncate">{{ $log->subject }}</span>
                            <span class="px-2 py-0.5 rounded text-xs font-semibold {{ match($log->status) { 'sent' => 'bg-emerald-100 dark:bg-emerald-900/40 text-emerald-800 dark:text-emerald-300', 'failed' => 'bg-red-100 dark:bg-red-900/40 text-red-800 dark:text-red-300', default => 'bg-amber-100 dark:bg-amber-900/40 text-amber-800 dark:text-amber-300' } }}">{{ __('crm.email_history.status_'.$log->status) }}</span>
                        </div>
                        <div class="text-xs text-gray-500 dark:text-gray-400 flex flex-wrap gap-x-2">
                            <span>{{ $log->created_at->format('d/m/Y H:i') }}</span>
                            <span>&rarr; {{ $log->to_email }}</span>
                            <span>· {{ $log->template?->name ?? ($log->data['template_slug'] ?? '—') }}</span>
                            @if(isset($emailSenders[$log->data['sender_id'] ?? '']))<span>· {{ __('crm.email_history.by') }} {{ $emailSenders[$log->data['sender_id']]->fullName }}</span>@endif
                            <a href="{{ $r('contacts.emails.show', ['contact' => $contact->id, 'log' => $log->id]) }}" class="text-indigo-600 dark:text-indigo-400 hover:underline" data-crm-email-reread="{{ $log->id }}">{{ __('crm.email_history.reread') }}</a>
                        </div>
                        @if($log->error_message)<div class="text-xs text-red-600 dark:text-red-400">{{ \Illuminate\Support\Str::limit($log->error_message, 120) }}</div>@endif
                    </li>
                    @endforeach
                </ul>
                @endif
            </div>

            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-5" data-crm-timeline>
                <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100 mb-3">{{ __('crm.show.timeline') }} <span class="font-normal text-gray-400">· {{ __('crm.show.timeline_order') }}</span></h2>
                <ol class="space-y-3">
                    @forelse($events as $event)
                    <li class="flex gap-3" data-crm-event="{{ $event->type }}">
                        <div class="mt-1 w-2 h-2 rounded-full flex-shrink-0 {{ match($event->type) { 'note' => 'bg-indigo-500', 'status_changed' => 'bg-amber-500', 'contact_updated' => 'bg-gray-400', 'next_action_planned' => 'bg-sky-500', 'next_action_done' => 'bg-emerald-600', 'email_sent' => 'bg-violet-500', 'email_failed' => 'bg-red-500', 'contact_policy_changed' => 'bg-orange-500', default => 'bg-green-500' } }}"></div>
                        <div class="flex-1 min-w-0">
                            <div class="flex flex-wrap items-baseline gap-x-2 text-xs text-gray-500 dark:text-gray-400">
                                <span class="font-semibold text-gray-700 dark:text-gray-300">{{ in_array($event->type, ['next_action_planned', 'next_action_done', 'email_sent', 'email_failed', 'contact_policy_changed'], true) ? __('crm.event_'.$event->type) : __('crm.event.'.$event->type) }}</span>
                                @if($event->type === 'note' && ($event->payload['channel'] ?? null))<span class="px-1.5 py-0.5 rounded bg-indigo-50 dark:bg-indigo-900/30 text-indigo-700 dark:text-indigo-300">{{ __('crm.channel.'.$event->payload['channel']) }}</span>@endif
                                <span>{{ $event->occurred_at->format('d/m/Y H:i') }}</span>
                                @if($event->author)<span>· {{ $event->author->fullName }}</span>@endif
                                @if($event->author?->is_admin)<span class="px-1.5 py-0.5 rounded bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300" data-crm-event-platform>{{ __('crm.event_by_platform') }}</span>@endif
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
                                    @case('next_action_planned')
                                    @case('next_action_done')
                                        {{ __('crm.action_type.'.($event->payload['action_type'] ?? 'other')) }}
                                        @if(!empty($event->payload['date'])) · {{ \Carbon\Carbon::parse($event->payload['date'])->format('d/m/Y') }}@endif@if(!empty($event->payload['time'])) {{ $event->payload['time'] }}@endif
                                        @if(!empty($event->payload['label'])) — {{ $event->payload['label'] }}@endif
                                    @break
                                    @case('workshop_participation_confirmed')
                                        <span class="font-medium">{{ $event->payload['workshop_title'] ?? '—' }}</span>
                                        @if(!empty($event->payload['session_starts_at']))<span class="text-xs text-gray-500 dark:text-gray-400">· {{ $event->payload['session_starts_at'] }}</span>@endif
                                        @if(!empty($event->payload['utm_campaign']) || !empty($event->payload['shortcut']))<span class="text-xs text-gray-500 dark:text-gray-400">· {{ $event->payload['utm_campaign'] ?? '' }}{{ !empty($event->payload['shortcut']) ? ' /s/'.$event->payload['shortcut'] : '' }}</span>@endif
                                    @break
                                    @case('shell_claimed')
                                        <span class="text-xs text-gray-500 dark:text-gray-400">{{ trans_choice('crm.shell_conversations', (int) ($event->payload['conversations'] ?? 0), ['count' => (int) ($event->payload['conversations'] ?? 0)]) }}{{ !empty($event->payload['utm_campaign']) ? ' · '.$event->payload['utm_campaign'] : '' }}{{ !empty($event->payload['shortcut']) ? ' · /s/'.$event->payload['shortcut'] : '' }}</span>
                                    @break
                                    @case('email_sent')
                                    @case('email_failed')
                                        <span class="font-medium">{{ $event->payload['subject'] ?? '' }}</span>
                                        <span class="text-xs text-gray-500 dark:text-gray-400">&rarr; {{ $event->payload['to'] ?? '' }} · {{ $event->payload['template_name'] ?? '' }}</span>
                                        @if(!empty($event->payload['error']))<div class="text-xs text-red-600 dark:text-red-400">{{ $event->payload['error'] }}</div>@endif
                                        {{-- TASK-1426 — CRM-8 : « Relire » seulement si le log est bien de ce Contact et de ce tenant. --}}
                                        @if(!empty($event->payload['log_id']) && $emailLogs->contains('id', $event->payload['log_id']))
                                            <a href="{{ $r('contacts.emails.show', ['contact' => $contact->id, 'log' => $event->payload['log_id']]) }}" class="text-xs text-indigo-600 dark:text-indigo-400 hover:underline" data-crm-event-reread="{{ $event->payload['log_id'] }}">{{ __('crm.email_history.reread') }}</a>
                                        @endif
                                    @break
                                    @case('contact_policy_changed')
                                        <strong>{{ __('crm.policy_state.'.(($event->payload['to_contactable'] ?? true) ? 'contactable' : 'do_not_contact')) }}</strong>
                                        <span class="text-xs text-gray-500 dark:text-gray-400">· {{ __('crm.policy.reason.'.($event->payload['reason'] ?? 'other')) }}</span>
                                        @if(!empty($event->payload['note']))<div class="text-xs text-gray-600 dark:text-gray-300">{{ $event->payload['note'] }}</div>@endif
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
