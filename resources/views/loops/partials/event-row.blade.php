{{--
    Une rencontre, telle qu'elle s'affiche.

    Partagee par la Card d'une Boucle et par l'agenda d'Organization : les deux
    montrent la meme chose, et diverger aurait ete la premiere chose a arriver si
    chacune avait son balisage.

    Les actions ne sont rendues que si l'appelant les fournit (`$interactive`).
    L'agenda, lui, montre et laisse agir dans la Boucle.
--}}
@php
    $interactive = $interactive ?? true;
    $showLoopName = $showLoopName ?? false;
    $canPublishOrg = $canPublishOrg ?? false;
    $total = $event['counts']['going'] + $event['counts']['maybe'] + $event['counts']['not_going'];
@endphp

<div class="rounded-2xl border p-4 {{ $event['is_cancelled']
        ? 'border-gray-200 bg-gray-50 dark:border-gray-700 dark:bg-gray-900/60'
        : 'border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-900' }}"
     wire:key="event-{{ $event['id'] }}" data-event-id="{{ $event['id'] }}">

    <div class="flex flex-wrap items-start justify-between gap-2">
        <p class="min-w-0 flex-1 text-sm font-semibold {{ $event['is_cancelled']
                ? 'text-gray-500 line-through dark:text-gray-400'
                : 'text-gray-900 dark:text-gray-100' }}">{{ $event['title'] }}</p>

        <span class="flex shrink-0 flex-wrap items-center gap-1">
            @if($event['is_cancelled'])
                <span class="rounded-full bg-gray-200 px-2 py-0.5 text-[11px] font-semibold text-gray-600 dark:bg-gray-700 dark:text-gray-300">{{ __('events.status_cancelled') }}</span>
            @endif
            @if($event['is_organization_wide'])
                <span class="rounded-full bg-violet-100 px-2 py-0.5 text-[11px] font-semibold text-violet-700 dark:bg-violet-900/40 dark:text-violet-300">{{ __('events.scope_organization') }}</span>
            @endif
        </span>
    </div>

    {{-- La date d'abord : c'est l'information qu'on cherche. Le fuseau est
         toujours ecrit — sans lui, une heure ne veut rien dire. --}}
    <p class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs text-gray-700 dark:text-gray-300">
        <span class="font-medium">{{ $event['starts_local']->translatedFormat('D j M Y · H:i') }}</span>
        @if($event['ends_local'])
            <span>→ {{ $event['ends_local']->translatedFormat('H:i') }}</span>
        @endif
        <span class="text-gray-400 dark:text-gray-500">{{ $event['timezone'] }}</span>
    </p>

    <p class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-[11px] text-gray-400 dark:text-gray-500">
        <span>{{ __('events.format_'.$event['format']) }}</span>
        @if($showLoopName && $event['loop_name'])
            <span>·</span>
            <span>{{ __('events.from_loop', ['name' => $event['loop_name']]) }}</span>
        @endif
        <span>·</span>
        <span>{{ __('events.by', ['name' => $event['author']]) }}</span>
        @if($event['is_cancelled'] && $event['cancelled_at'])
            <span>·</span>
            <span>{{ __('events.cancelled_notice', ['date' => $event['cancelled_at']->isoFormat('LL')]) }}</span>
        @endif
    </p>

    @if($event['description'])
        <p class="mt-2 text-xs leading-5 text-gray-600 dark:text-gray-300">{{ $event['description'] }}</p>
    @endif

    @if($event['location'])
        <p class="mt-1.5 flex items-start gap-1.5 text-xs text-gray-600 dark:text-gray-300">
            <svg class="mt-0.5 h-3.5 w-3.5 shrink-0 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1 1 15 0Z"/></svg>
            <span class="min-w-0">{{ $event['location'] }}</span>
        </p>
    @endif

    @if($event['meeting_url'])
        {{-- `noopener` systematique : le lien vient d'un membre, pas de nous. --}}
        <p class="mt-1.5 flex items-start gap-1.5 text-xs">
            <svg class="mt-0.5 h-3.5 w-3.5 shrink-0 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13.19 8.688a4.5 4.5 0 0 1 1.242 7.244l-4.5 4.5a4.5 4.5 0 0 1-6.364-6.364l1.757-1.757m13.35-.622 1.757-1.757a4.5 4.5 0 0 0-6.364-6.364l-4.5 4.5a4.5 4.5 0 0 0 1.242 7.244"/></svg>
            <a href="{{ $event['meeting_url'] }}" target="_blank" rel="noopener noreferrer"
               class="min-w-0 break-all text-sky-700 hover:underline dark:text-sky-300">{{ $event['meeting_url'] }}</a>
        </p>
    @endif

    {{-- Les compteurs, toujours ; les noms, sur demande. --}}
    <div class="mt-2.5 flex flex-wrap items-center gap-2 text-[11px]">
        <span class="rounded-full bg-emerald-100 px-2 py-0.5 font-semibold text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300">
            {{ __('events.going_short') }} {{ $event['counts']['going'] }}
        </span>
        <span class="rounded-full bg-amber-100 px-2 py-0.5 font-semibold text-amber-700 dark:bg-amber-900/40 dark:text-amber-300">
            {{ __('events.maybe_short') }} {{ $event['counts']['maybe'] }}
        </span>
        <span class="rounded-full bg-gray-100 px-2 py-0.5 font-semibold text-gray-600 dark:bg-gray-800 dark:text-gray-400">
            {{ __('events.not_going_short') }} {{ $event['counts']['not_going'] }}
        </span>

        @if($interactive && $total > 0)
            <button type="button" wire:click="toggleAttendees('{{ $event['id'] }}')"
                    class="font-semibold text-sky-700 transition hover:underline dark:text-sky-300">
                {{ $event['attendees'] !== null ? __('events.attendees_hide') : __('events.attendees_show') }}
            </button>
        @endif
    </div>

    @if($event['attendees'] !== null)
        <div class="mt-2 space-y-1 border-t border-gray-100 pt-2 dark:border-gray-800">
            @foreach([
                \App\Models\LoopEventResponse::GOING => __('events.going_short'),
                \App\Models\LoopEventResponse::MAYBE => __('events.maybe_short'),
                \App\Models\LoopEventResponse::NOT_GOING => __('events.not_going_short'),
            ] as $key => $label)
                @if(! empty($event['attendees'][$key]))
                    <p class="flex flex-wrap items-baseline gap-x-2 text-[11px]">
                        <span class="font-semibold text-gray-700 dark:text-gray-200">{{ $label }}</span>
                        <span class="text-gray-500 dark:text-gray-400">{{ implode(', ', $event['attendees'][$key]) }}</span>
                    </p>
                @endif
            @endforeach
        </div>
    @endif

    @if($event['my_response'])
        <p class="mt-2 text-xs text-gray-600 dark:text-gray-300">
            <span class="font-semibold">{{ __('events.your_response') }}</span>
            {{ __('events.'.$event['my_response']) }}
        </p>
    @endif

    @if($interactive)
        @if($event['can_respond'])
            <div class="mt-2.5 flex flex-wrap gap-1.5">
                @foreach([
                    \App\Models\LoopEventResponse::GOING => ['label' => __('events.going'), 'on' => 'bg-emerald-600 text-white', 'off' => 'border-emerald-300 text-emerald-700 hover:bg-emerald-50 dark:border-emerald-800 dark:text-emerald-300 dark:hover:bg-emerald-900/30'],
                    \App\Models\LoopEventResponse::MAYBE => ['label' => __('events.maybe'), 'on' => 'bg-amber-600 text-white', 'off' => 'border-amber-300 text-amber-700 hover:bg-amber-50 dark:border-amber-800 dark:text-amber-300 dark:hover:bg-amber-900/30'],
                    \App\Models\LoopEventResponse::NOT_GOING => ['label' => __('events.not_going'), 'on' => 'bg-gray-600 text-white', 'off' => 'border-gray-300 text-gray-600 hover:bg-gray-100 dark:border-gray-600 dark:text-gray-300 dark:hover:bg-gray-800'],
                ] as $value => $style)
                    @php($chosen = $event['my_response'] === $value)
                    <button type="button" wire:click="respond('{{ $event['id'] }}', '{{ $value }}')"
                            class="rounded-lg border px-3 py-1.5 text-xs font-semibold transition {{ $chosen ? $style['on'].' border-transparent' : $style['off'] }}"
                            aria-pressed="{{ $chosen ? 'true' : 'false' }}">
                        {{ $style['label'] }}
                    </button>
                @endforeach
            </div>
        @endif

        @if($event['can_manage'] && ! $event['is_cancelled'])
            <div class="mt-2.5 flex flex-wrap gap-1.5">
                <button type="button" wire:click="openEditForm('{{ $event['id'] }}')"
                        class="rounded-lg border border-gray-300 px-3 py-1.5 text-xs font-semibold text-gray-600 transition hover:bg-gray-100 dark:border-gray-600 dark:text-gray-300 dark:hover:bg-gray-800">
                    {{ __('events.edit') }}
                </button>

                @if($canPublishOrg)
                    @php($target = $event['is_organization_wide']
                        ? \App\Models\LoopEvent::VISIBILITY_LOOP
                        : \App\Models\LoopEvent::VISIBILITY_ORGANIZATION)
                    <button type="button" wire:click="confirmVisibility('{{ $event['id'] }}', '{{ $target }}')"
                            class="rounded-lg border border-violet-300 px-3 py-1.5 text-xs font-semibold text-violet-700 transition hover:bg-violet-50 dark:border-violet-800 dark:text-violet-300 dark:hover:bg-violet-900/30">
                        {{ $event['is_organization_wide'] ? __('events.restrict_loop_cta') : __('events.publish_org_cta') }}
                    </button>
                @endif

                <button type="button" wire:click="confirmCancel('{{ $event['id'] }}')"
                        class="rounded-lg border border-amber-300 px-3 py-1.5 text-xs font-semibold text-amber-700 transition hover:bg-amber-50 dark:border-amber-800 dark:text-amber-300 dark:hover:bg-amber-900/30">
                    {{ __('events.cancel_event') }}
                </button>

                @if($event['can_delete'])
                    <button type="button" wire:click="confirmDelete('{{ $event['id'] }}')"
                            class="rounded-lg border border-red-300 px-3 py-1.5 text-xs font-semibold text-red-700 transition hover:bg-red-50 dark:border-red-800 dark:text-red-300 dark:hover:bg-red-900/30">
                        {{ __('events.delete') }}
                    </button>
                @endif
            </div>
        @endif
    @endif
</div>
