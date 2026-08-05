{{--
    Card Evenements.

    Deux vues : liste par defaut — c'est elle qui repond a « qu'est-ce qui arrive
    bientot » — et un mois. Le detail des participants est replie : c'est le seul
    chargement couteux de cet ecran, et il ne se paie que si on le demande.
--}}
<div class="space-y-3" data-events-root>

    @if($readOnly)
        <p class="rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-xs leading-5 text-amber-800 dark:border-amber-800/60 dark:bg-amber-900/20 dark:text-amber-200">
            {{ __('events.read_only') }}
        </p>
    @endif

    @if($errorMessage)
        <p class="rounded-xl border border-red-200 bg-red-50 px-3 py-2 text-xs leading-5 text-red-700 dark:border-red-800/60 dark:bg-red-900/20 dark:text-red-300" role="alert">{{ $errorMessage }}</p>
    @endif

    @if($successMessage)
        <p class="rounded-xl border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs leading-5 text-emerald-700 dark:border-emerald-800/60 dark:bg-emerald-900/20 dark:text-emerald-300">{{ $successMessage }}</p>
    @endif

    <div class="flex flex-wrap items-center gap-2">
        <div class="inline-flex rounded-lg border border-gray-300 p-0.5 dark:border-gray-600">
            @foreach(['list' => __('events.view_list'), 'calendar' => __('events.view_calendar')] as $mode => $label)
                <button type="button" wire:click="setView('{{ $mode }}')"
                        class="rounded-md px-2.5 py-1 text-xs font-semibold transition {{ $view === $mode
                            ? 'bg-sky-600 text-white'
                            : 'text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800' }}">
                    {{ $label }}
                </button>
            @endforeach
        </div>

        @if($canCreate && ! $showForm)
            <button type="button" wire:click="openCreateForm"
                    class="ml-auto inline-flex items-center gap-1.5 rounded-xl bg-sky-600 px-3 py-2 text-xs font-semibold text-white transition hover:bg-sky-700">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                {{ __('events.create') }}
            </button>
        @endif
    </div>

    {{-- ── Formulaire ─────────────────────────────────────────────────── --}}
    @if($showForm)
        <div class="rounded-2xl border border-sky-200 bg-white p-4 dark:border-sky-800/60 dark:bg-gray-900">
            <p class="text-sm font-semibold text-gray-900 dark:text-gray-100">
                {{ $editingId ? __('events.form_title_edit') : __('events.form_title_create') }}
            </p>

            @if($editingHasResponses)
                {{-- On previent, on n'interdit pas : les reponses survivent a
                     tout changement, y compris de date. --}}
                <p class="mt-2 rounded-xl border border-amber-300 bg-amber-50 px-3 py-2 text-xs leading-5 text-amber-900 dark:border-amber-800/60 dark:bg-amber-900/20 dark:text-amber-200">
                    {{ __('events.edit_warning') }}
                </p>
            @endif

            <label class="mt-3 block text-xs font-medium text-gray-500 dark:text-gray-400" for="ev-title">{{ __('events.event_title') }}</label>
            <input id="ev-title" type="text" wire:model="title" maxlength="255"
                   placeholder="{{ __('events.event_title_placeholder') }}"
                   class="mt-1 w-full rounded-xl border-gray-300 bg-white text-sm text-gray-900 focus:border-sky-500 focus:ring-sky-500 dark:border-gray-600 dark:bg-gray-950 dark:text-gray-100">

            <label class="mt-3 block text-xs font-medium text-gray-500 dark:text-gray-400" for="ev-description">{{ __('events.description') }}</label>
            <textarea id="ev-description" wire:model="description" rows="2" maxlength="2000"
                      placeholder="{{ __('events.description_placeholder') }}"
                      class="mt-1 w-full resize-none rounded-xl border-gray-300 bg-white text-sm text-gray-900 focus:border-sky-500 focus:ring-sky-500 dark:border-gray-600 dark:bg-gray-950 dark:text-gray-100"></textarea>

            <div class="mt-3 grid gap-3 sm:grid-cols-2">
                <div>
                    <label class="block text-xs font-medium text-gray-500 dark:text-gray-400" for="ev-start">{{ __('events.starts_at') }}</label>
                    <input id="ev-start" type="datetime-local" wire:model="startsAt"
                           class="mt-1 w-full rounded-xl border-gray-300 bg-white text-sm text-gray-900 focus:border-sky-500 focus:ring-sky-500 dark:border-gray-600 dark:bg-gray-950 dark:text-gray-100">
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-500 dark:text-gray-400" for="ev-end">{{ __('events.ends_at') }}</label>
                    <input id="ev-end" type="datetime-local" wire:model="endsAt"
                           class="mt-1 w-full rounded-xl border-gray-300 bg-white text-sm text-gray-900 focus:border-sky-500 focus:ring-sky-500 dark:border-gray-600 dark:bg-gray-950 dark:text-gray-100">
                </div>
            </div>

            {{-- Le fuseau est toujours visible : sans lui, « 19:00 » ne veut rien
                 dire. Ce produit n'a pas de preference de fuseau par personne,
                 c'est donc l'evenement qui la porte. --}}
            <label class="mt-3 block text-xs font-medium text-gray-500 dark:text-gray-400" for="ev-tz">{{ __('events.timezone') }}</label>
            <select id="ev-tz" wire:model="timezone"
                    class="mt-1 w-full rounded-xl border-gray-300 bg-white text-sm text-gray-900 focus:border-sky-500 focus:ring-sky-500 dark:border-gray-600 dark:bg-gray-950 dark:text-gray-100">
                @foreach(\App\Models\LoopEvent::TIMEZONES as $tz)
                    <option value="{{ $tz }}">{{ $tz }}</option>
                @endforeach
            </select>
            <p class="mt-1 text-[11px] text-gray-400 dark:text-gray-500">{{ __('events.timezone_hint') }}</p>

            <fieldset class="mt-3">
                <legend class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('events.format') }}</legend>
                <div class="mt-1.5 flex flex-wrap gap-2">
                    @foreach([
                        \App\Models\LoopEvent::FORMAT_IN_PERSON => __('events.format_in_person'),
                        \App\Models\LoopEvent::FORMAT_ONLINE => __('events.format_online'),
                        \App\Models\LoopEvent::FORMAT_HYBRID => __('events.format_hybrid'),
                    ] as $value => $label)
                        <label class="flex cursor-pointer items-center gap-2 rounded-lg border border-gray-300 px-3 py-1.5 text-xs text-gray-700 transition has-[:checked]:border-sky-500 has-[:checked]:bg-sky-50 dark:border-gray-600 dark:text-gray-200 dark:has-[:checked]:bg-sky-900/20">
                            <input type="radio" wire:model.live="format" value="{{ $value }}" class="text-sky-600 focus:ring-sky-500">
                            {{ $label }}
                        </label>
                    @endforeach
                </div>
            </fieldset>

            @if(in_array($format, [\App\Models\LoopEvent::FORMAT_IN_PERSON, \App\Models\LoopEvent::FORMAT_HYBRID], true))
                <label class="mt-3 block text-xs font-medium text-gray-500 dark:text-gray-400" for="ev-location">{{ __('events.location') }}</label>
                <input id="ev-location" type="text" wire:model="location" maxlength="255"
                       placeholder="{{ __('events.location_placeholder') }}"
                       class="mt-1 w-full rounded-xl border-gray-300 bg-white text-sm text-gray-900 focus:border-sky-500 focus:ring-sky-500 dark:border-gray-600 dark:bg-gray-950 dark:text-gray-100">
            @endif

            @if(in_array($format, [\App\Models\LoopEvent::FORMAT_ONLINE, \App\Models\LoopEvent::FORMAT_HYBRID], true))
                <label class="mt-3 block text-xs font-medium text-gray-500 dark:text-gray-400" for="ev-url">{{ __('events.meeting_url') }}</label>
                <input id="ev-url" type="url" wire:model="meetingUrl" maxlength="2048"
                       placeholder="{{ __('events.meeting_url_placeholder') }}"
                       class="mt-1 w-full rounded-xl border-gray-300 bg-white text-sm text-gray-900 focus:border-sky-500 focus:ring-sky-500 dark:border-gray-600 dark:bg-gray-950 dark:text-gray-100">
            @endif

            {{-- Le selecteur de portee n'existe pas sur une Boucle privee : on ne
                 propose pas un choix que le serveur refusera. --}}
            @if($canPublishOrg)
                <fieldset class="mt-3">
                    <legend class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('events.scope') }}</legend>
                    <div class="mt-1.5 flex flex-wrap gap-2">
                        @foreach([
                            \App\Models\LoopEvent::VISIBILITY_LOOP => __('events.scope_loop'),
                            \App\Models\LoopEvent::VISIBILITY_ORGANIZATION => __('events.scope_organization'),
                        ] as $value => $label)
                            <label class="flex cursor-pointer items-center gap-2 rounded-lg border border-gray-300 px-3 py-1.5 text-xs text-gray-700 transition has-[:checked]:border-sky-500 has-[:checked]:bg-sky-50 dark:border-gray-600 dark:text-gray-200 dark:has-[:checked]:bg-sky-900/20">
                                <input type="radio" wire:model="visibility" value="{{ $value }}" class="text-sky-600 focus:ring-sky-500">
                                {{ $label }}
                            </label>
                        @endforeach
                    </div>
                </fieldset>
            @else
                <p class="mt-3 text-[11px] italic text-gray-400 dark:text-gray-500">{{ __('events.private_loop_notice') }}</p>
            @endif

            <div class="mt-4 flex flex-wrap justify-end gap-2">
                <button type="button" wire:click="closeForm"
                        class="rounded-lg border border-gray-300 px-3 py-1.5 text-xs font-semibold text-gray-600 transition hover:bg-gray-100 dark:border-gray-600 dark:text-gray-300 dark:hover:bg-gray-800">
                    {{ __('events.cancel') }}
                </button>
                <button type="button" wire:click="save"
                        class="rounded-lg bg-sky-600 px-4 py-1.5 text-xs font-semibold text-white transition hover:bg-sky-700">
                    {{ $editingId ? __('events.save') : __('events.publish') }}
                </button>
            </div>
        </div>
    @endif

    {{-- ── Vue calendrier ─────────────────────────────────────────────── --}}
    @if($view === 'calendar')
        <div class="rounded-2xl border border-gray-200 bg-white p-3 dark:border-gray-700 dark:bg-gray-900">
            <div class="flex items-center justify-between gap-2">
                <button type="button" wire:click="shiftMonth(-1)"
                        class="rounded-lg p-1.5 text-gray-500 transition hover:bg-gray-100 dark:hover:bg-gray-800"
                        aria-label="{{ __('events.previous_month') }}">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5"/></svg>
                </button>
                <p class="text-sm font-semibold capitalize text-gray-900 dark:text-gray-100">{{ $calendar['label'] ?? '' }}</p>
                <button type="button" wire:click="shiftMonth(1)"
                        class="rounded-lg p-1.5 text-gray-500 transition hover:bg-gray-100 dark:hover:bg-gray-800"
                        aria-label="{{ __('events.next_month') }}">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5"/></svg>
                </button>
            </div>

            {{-- La grille deborde plutot que d'ecraser les jours : une semaine
                 tient sur sept colonnes lisibles, ou elle defile. --}}
            <div class="mt-2 overflow-x-auto">
                <table class="w-full min-w-[22rem] table-fixed border-collapse">
                    <thead>
                        <tr>
                            @foreach(['L', 'M', 'M', 'J', 'V', 'S', 'D'] as $dayLabel)
                                <th class="pb-1 text-center text-[10px] font-semibold uppercase text-gray-400 dark:text-gray-500">{{ $dayLabel }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($calendar['weeks'] ?? [] as $week)
                            <tr>
                                @foreach($week as $day)
                                    <td class="h-14 border border-gray-100 p-0.5 align-top dark:border-gray-800 {{ $day['in_month'] ? '' : 'opacity-40' }}">
                                        <span class="block text-right text-[10px] {{ $day['is_today'] ? 'font-bold text-sky-600 dark:text-sky-300' : 'text-gray-400 dark:text-gray-500' }}">{{ $day['day'] }}</span>
                                        @foreach($day['events'] as $dayEvent)
                                            <span class="mt-0.5 block truncate rounded px-1 text-[10px] leading-4 {{ $dayEvent['is_cancelled']
                                                    ? 'bg-gray-100 text-gray-500 line-through dark:bg-gray-800 dark:text-gray-400'
                                                    : 'bg-sky-100 text-sky-800 dark:bg-sky-900/40 dark:text-sky-200' }}"
                                                  title="{{ $dayEvent['title'] }}">{{ $dayEvent['title'] }}</span>
                                        @endforeach
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    {{-- ── Vue liste ──────────────────────────────────────────────────── --}}
    @if($view === 'list')
        @if($upcoming->isEmpty() && $past->isEmpty() && ! $showForm)
            <div class="rounded-2xl border border-dashed border-gray-300 bg-white p-5 text-center dark:border-gray-700 dark:bg-gray-900">
                <p class="text-sm font-semibold text-gray-700 dark:text-gray-200">{{ __('events.empty') }}</p>
                <p class="mt-1 text-xs leading-5 text-gray-500 dark:text-gray-400">{{ __('events.empty_hint') }}</p>
            </div>
        @endif

        @foreach([__('events.upcoming') => $upcoming, __('events.past') => $past] as $sectionLabel => $section)
            @if($section->isNotEmpty())
                <p class="pt-1 text-[11px] font-bold uppercase tracking-wide text-gray-400 dark:text-gray-500">{{ $sectionLabel }}</p>

                @foreach($section as $event)
                    @include('loops.partials.event-row', ['event' => $event, 'canPublishOrg' => $canPublishOrg])
                @endforeach
            @endif
        @endforeach
    @endif

    {{-- ── Confirmations ──────────────────────────────────────────────────
         Modales Alpine, jamais `confirm()` : une alerte native ne sait pas dire
         ce qui est conserve, et c'est l'information utile. --}}
    @if($confirmingCancelId || $confirmingDeleteId || $confirmingVisibilityId)
        @php
            $deleting = (bool) $confirmingDeleteId;
            $scoping = (bool) $confirmingVisibilityId;
            $toOrg = $pendingVisibility === \App\Models\LoopEvent::VISIBILITY_ORGANIZATION;

            $modalTitle = $deleting ? __('events.delete_confirm_title')
                : ($scoping ? ($toOrg ? __('events.publish_org_title') : __('events.restrict_loop_title'))
                : __('events.cancel_confirm_title'));
            $modalBody = $deleting ? __('events.delete_confirm_body')
                : ($scoping ? ($toOrg ? __('events.publish_org_body') : __('events.restrict_loop_body'))
                : __('events.cancel_confirm_body'));
            $modalCta = $deleting ? __('events.delete_confirm_cta')
                : ($scoping ? ($toOrg ? __('events.publish_org_cta') : __('events.restrict_loop_cta'))
                : __('events.cancel_confirm_cta'));
            $modalAction = $deleting ? "deleteEvent('".$confirmingDeleteId."')"
                : ($scoping ? 'applyVisibility' : "cancelEvent('".$confirmingCancelId."')");
            $modalTone = $deleting ? 'bg-red-600 hover:bg-red-700'
                : ($scoping ? 'bg-sky-600 hover:bg-sky-700' : 'bg-amber-600 hover:bg-amber-700');
        @endphp
        <template x-teleport="body">
            <div class="fixed inset-0 z-50 flex items-center justify-center bg-gray-900/50 p-4"
                 x-on:keydown.escape.window="$wire.cancelConfirmation()"
                 x-on:click.self="$wire.cancelConfirmation()"
                 role="dialog" aria-modal="true">
                <div class="w-full max-w-md rounded-2xl bg-white p-5 shadow-xl dark:bg-gray-800">
                    <h2 class="text-base font-bold text-gray-900 dark:text-gray-100">{{ $modalTitle }}</h2>
                    <p class="mt-2 text-sm leading-6 text-gray-600 dark:text-gray-300">{{ $modalBody }}</p>
                    <div class="mt-5 flex flex-wrap justify-end gap-2">
                        <button type="button" wire:click="cancelConfirmation"
                                class="rounded-lg border border-gray-300 px-4 py-2 text-sm text-gray-600 transition hover:bg-gray-100 dark:border-gray-600 dark:text-gray-300 dark:hover:bg-gray-700">
                            {{ __('events.cancel') }}
                        </button>
                        <button type="button" wire:click="{{ $modalAction }}"
                                class="rounded-lg px-4 py-2 text-sm font-semibold text-white transition {{ $modalTone }}">
                            {{ $modalCta }}
                        </button>
                    </div>
                </div>
            </div>
        </template>
    @endif
</div>
