<x-admin-layout :title="__('admin.crm_facts_title')">
    {{-- TASK-1427 — decision Cyril : les derniers faits de toutes les Organizations, AVEC leur contenu. Lecture. --}}
    @include('admin.crm._tabs')
    @php $name = fn ($c) => $c ? ($c->full_name !== '' ? $c->full_name : ($c->email ?: $c->phone ?: __('crm.show.untitled'))) : __('crm.today.deleted_contact'); @endphp
    <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">{{ __('admin.crm_facts_hint', ['count' => $limit]) }}</p>
    <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-5" data-crm-admin-facts data-crm-count="{{ $facts->count() }}">
        @if($facts->isEmpty())
            <p class="text-sm text-gray-500" data-crm-admin-empty="facts">{{ __('crm.today.empty_facts') }}</p>
        @else
        <ol class="divide-y divide-gray-100 dark:divide-gray-700">
            @foreach($facts as $event)
            <li class="py-2 flex gap-3 text-sm" data-crm-event="{{ $event->type }}">
                <div class="mt-1.5 w-2 h-2 rounded-full flex-shrink-0 {{ match($event->type) { 'note' => 'bg-indigo-500', 'status_changed' => 'bg-amber-500', 'contact_updated' => 'bg-gray-400', 'next_action_planned' => 'bg-sky-500', 'next_action_done' => 'bg-emerald-600', 'email_sent' => 'bg-violet-500', 'email_failed' => 'bg-red-500', 'contact_policy_changed' => 'bg-orange-500', default => 'bg-green-500' } }}"></div>
                <div class="flex-1 min-w-0">
                    <div class="flex flex-wrap items-baseline gap-x-2">
                        <span class="text-xs text-gray-500 dark:text-gray-400">{{ $event->organization?->name ?? '—' }}</span>
                        @if($event->contact)<a href="{{ route('organization.admin.crm.contacts.show', ['organization' => $event->organization?->slug ?? '-', 'contact' => $event->contact->id]) }}" class="font-medium text-gray-900 dark:text-gray-100 hover:text-indigo-600 dark:hover:text-indigo-400">{{ $name($event->contact) }}</a>@else<span class="text-gray-500">{{ $name(null) }}</span>@endif
                        <span class="text-xs font-semibold text-gray-600 dark:text-gray-300">{{ in_array($event->type, ['next_action_planned', 'next_action_done', 'email_sent', 'email_failed', 'contact_policy_changed'], true) ? __('crm.event_'.$event->type) : __('crm.event.'.$event->type) }}</span>
                        <span class="text-xs text-gray-500 dark:text-gray-400">{{ $event->occurred_at->format('d/m/Y H:i') }}@if($event->author) · {{ $event->author->fullName }}@endif</span>
                    </div>
                    <div class="text-sm text-gray-800 dark:text-gray-200">
                        @switch($event->type)
                            @case('note'){!! nl2br(e($event->payload['body'] ?? '')) !!}@break
                            @case('status_changed'){{ $event->payload['from_label'] ?? '—' }} &rarr; <strong>{{ $event->payload['to_label'] ?? '—' }}</strong>@break
                            @case('contact_updated'){{ implode(', ', array_map(fn ($f) => __('crm.field.'.$f), array_keys($event->payload['changes'] ?? []))) }}@break
                            @case('next_action_planned')
                            @case('next_action_done'){{ __('crm.action_type.'.($event->payload['action_type'] ?? 'other')) }}@if(!empty($event->payload['date'])) · {{ \Carbon\Carbon::parse($event->payload['date'])->format('d/m/Y') }}@endif@if(!empty($event->payload['time'])) {{ $event->payload['time'] }}@endif@if(!empty($event->payload['label'])) — {{ $event->payload['label'] }}@endif @break
                            @case('email_sent')
                            @case('email_failed')<span class="font-medium">{{ $event->payload['subject'] ?? '' }}</span> <span class="text-xs text-gray-500">&rarr; {{ $event->payload['to'] ?? '' }} · {{ $event->payload['template_name'] ?? '' }}</span>@if(!empty($event->payload['error'])) <span class="text-xs text-red-600 dark:text-red-400">{{ $event->payload['error'] }}</span>@endif @break
                            @case('contact_policy_changed')<strong>{{ __('crm.policy_state.'.(($event->payload['to_contactable'] ?? true) ? 'contactable' : 'do_not_contact')) }}</strong> <span class="text-xs text-gray-500">· {{ __('crm.policy.reason.'.($event->payload['reason'] ?? 'other')) }}</span>@if(!empty($event->payload['note'])) — {{ $event->payload['note'] }}@endif @break
                            @default —
                        @endswitch
                    </div>
                </div>
            </li>
            @endforeach
        </ol>
        @endif
    </div>
</x-admin-layout>
