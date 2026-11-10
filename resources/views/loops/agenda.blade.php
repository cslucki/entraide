{{--
    L'agenda d'une Organization.

    TASK-1706 — elle ORGANISE desormais, et cette phrase remplace celle qui
    disait l'inverse. Cyril : « il faut donc passer par une boucle. Ce n'est
    pas pratique. Pas de bouton pour creer un evenement. »

    La rencontre appartient toujours a une Boucle — `loop_events.loop_id` est
    NOT NULL —, mais on la choisit ici au lieu d'aller la chercher.

    Chaque ligne est le meme partiel que dans la Card, en mode non interactif :
    les deux ecrans montrent la meme chose parce qu'ils partagent le balisage.
--}}
<x-app-layout :title="__('events.agenda_title')">
    <x-page-container>
        <div class="mb-6 flex flex-wrap items-start justify-between gap-3">
            <div>
                {{-- TASK-1720 — le sous-titre est retire. Il redisait ce que la
                     page montre, et coutait une ligne en haut d'ecran avant la
                     premiere rencontre. Decision MASTER : « cela prend de la
                     place pour rien ».

                     `events.agenda_subtitle` n'avait aucun autre usage : les
                     deux clefs partent avec, dans les deux langues. --}}
                <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">{{ __('events.agenda_title') }}</h1>
            </div>

            {{-- TASK-1706 — organiser depuis l'agenda.

                 Le bouton EXISTE TOUJOURS, meme quand la personne ne peut pas
                 creer : c'est ce que Cyril a demande explicitement (« le bouton
                 existe, mais renvoie une erreur »). Un bouton absent ne dit pas
                 ce qu'il faut faire pour l'obtenir ; un bouton qui s'explique,
                 oui.

                 TROIS refus distincts, et aucun n'est interchangeable : chacun
                 nomme un geste different. Se replier sur un message unique
                 afficherait un texte FAUX pour deux personnes sur trois — un
                 proprietaire a bien le role, c'est l'outil qui lui manque.

                 La decision vient du SERVEUR (`creationContextFor`) : cette vue
                 ne redevine aucun droit. --}}
            @if($creationLoops->isNotEmpty())
                <a href="{{ route('organization.events.agenda.create', ['organization' => $organization->slug]) }}"
                   data-agenda-create
                   class="inline-flex items-center gap-2 rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-700">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/>
                    </svg>
                    {{ __('events.agenda_create_button') }}
                </a>
            @else
                <div x-data="{ ouvert: false }" class="relative">
                    <button type="button"
                            data-agenda-create-refused="{{ $creationRefus }}"
                            x-on:click="ouvert = ! ouvert"
                            class="inline-flex items-center gap-2 rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-700">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/>
                        </svg>
                        {{ __('events.agenda_create_button') }}
                    </button>

                    <div x-show="ouvert"
                         x-cloak
                         x-transition
                         x-on:click.outside="ouvert = false"
                         data-agenda-create-message
                         class="absolute right-0 z-20 mt-2 w-80 rounded-xl border border-amber-200 bg-white p-4 text-sm shadow-lg dark:border-amber-900/50 dark:bg-gray-800">
                        <p class="text-gray-700 dark:text-gray-200">
                            {{ __('events.'.$creationRefus.'_refus') }}
                        </p>

                        @if($creationRefus === 'no_loop')
                            <a href="{{ route('organization.loops.index', ['organization' => $organization->slug]) }}"
                               data-agenda-create-cta
                               class="mt-3 inline-flex text-sm font-semibold text-indigo-600 hover:underline dark:text-indigo-400">
                                {{ __('events.no_loop_refus_cta') }}
                            </a>
                        @endif
                    </div>
                </div>
            @endif
        </div>

        {{-- TASK-1656 — l'agenda porte desormais un geste : il lui faut donc un
             retour. Sans ces deux blocs, une reponse enregistree ne se
             distinguait pas d'une reponse ignoree : la page revenait identique,
             et seule la couleur du bouton choisi changeait. --}}
        @if(session('status'))
            <div data-flash="status" class="mb-5 rounded-xl border border-emerald-300 bg-emerald-50 p-3 text-sm text-emerald-900 dark:border-emerald-800 dark:bg-emerald-900/20 dark:text-emerald-200">
                {{ session('status') }}
            </div>
        @endif

        @if($errors->any())
            <div data-flash="error" class="mb-5 rounded-xl border border-red-300 bg-red-50 p-3 dark:border-red-800 dark:bg-red-900/20">
                <ul class="space-y-1 text-sm text-red-900 dark:text-red-200">
                    @foreach($errors->all() as $erreur)
                        <li>{{ $erreur }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        {{-- Filtres en GET : une vue filtree se partage par son URL. --}}
        <form method="GET" class="mb-5 flex flex-wrap items-center gap-2">
            {{-- TASK-1802 (suite, demande Cyril) — « on va réutiliser cette
                 vue "Calendrier" sur la page Agenda ». Cette page n'est pas
                 un composant Livewire : la bascule est un lien GET, pas un
                 `wire:click` — chaque vue reste une URL qui se partage.

                 Puis, demande suivante — « supprime la vue calendrier [...]
                 uniquement sur la version smartphone car ça ne se voit pas »
                 : le bouton disparait en dessous de `md`, comme sur la Card. --}}
            <div class="hidden md:inline-flex rounded-lg border border-gray-300 p-0.5 dark:border-gray-600">
                @foreach(['list' => __('events.view_list'), 'calendar' => __('events.view_calendar')] as $mode => $label)
                    <a href="{{ request()->fullUrlWithQuery(['view' => $mode]) }}"
                       class="rounded-md px-2.5 py-1 text-xs font-semibold transition {{ $view === $mode
                            ? 'bg-sky-600 text-white'
                            : 'text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800' }}">
                        {{ $label }}
                    </a>
                @endforeach
            </div>

            <div class="inline-flex rounded-lg border border-gray-300 p-0.5 dark:border-gray-600">
                @foreach(['upcoming' => __('events.filter_upcoming'), 'past' => __('events.filter_past')] as $value => $label)
                    <a href="{{ request()->fullUrlWithQuery(['when' => $value]) }}"
                       class="rounded-md px-3 py-1 text-xs font-semibold transition {{ $when === $value
                            ? 'bg-sky-600 text-white'
                            : 'text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800' }}">
                        {{ $label }}
                    </a>
                @endforeach
            </div>

            <label class="sr-only" for="agenda-loop">{{ __('events.filter_all_loops') }}</label>
            <select id="agenda-loop" name="loop" onchange="this.form.submit()"
                    class="rounded-xl border-gray-300 bg-white text-xs text-gray-900 focus:border-sky-500 focus:ring-sky-500 dark:border-gray-600 dark:bg-gray-950 dark:text-gray-100">
                <option value="">{{ __('events.filter_all_loops') }}</option>
                @foreach($loops as $id => $name)
                    <option value="{{ $id }}" @selected($loopFilter === $id)>{{ $name }}</option>
                @endforeach
            </select>

            <label class="sr-only" for="agenda-format">{{ __('events.filter_all_formats') }}</label>
            <select id="agenda-format" name="format" onchange="this.form.submit()"
                    class="rounded-xl border-gray-300 bg-white text-xs text-gray-900 focus:border-sky-500 focus:ring-sky-500 dark:border-gray-600 dark:bg-gray-950 dark:text-gray-100">
                <option value="">{{ __('events.filter_all_formats') }}</option>
                @foreach([\App\Models\LoopEvent::FORMAT_IN_PERSON, \App\Models\LoopEvent::FORMAT_ONLINE, \App\Models\LoopEvent::FORMAT_HYBRID] as $value)
                    <option value="{{ $value }}" @selected($formatFilter === $value)>{{ __('events.format_'.$value) }}</option>
                @endforeach
            </select>

            <input type="hidden" name="when" value="{{ $when }}">
        </form>

        @if($events->isEmpty())
            <div class="rounded-2xl border border-dashed border-gray-300 bg-white p-8 text-center dark:border-gray-700 dark:bg-gray-900">
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('events.agenda_empty') }}</p>
            </div>
        @else
            {{-- `selectedEventId` est partage par les pop-up de detail de la
                 vue calendrier ci-dessous : un seul etat Alpine, commun aux
                 deux vues, meme si la vue liste ne s'en sert jamais. --}}
            <div x-data="{ selectedEventId: null }">
                {{-- Vue liste — TOUJOURS montee, meme quand `$view ===
                     'calendar'` : seul `md:hidden` la cache alors, et
                     seulement a partir de `md` (TASK-1802, suite, demande
                     Cyril : « supprime la vue calendrier [...] uniquement
                     sur la version smartphone car ça ne se voit pas »). Sur
                     smartphone, la liste reste donc TOUJOURS visible, meme
                     derriere un lien `?view=calendar` partage depuis un
                     ordinateur. --}}
                <div class="space-y-3 {{ $view === 'calendar' ? 'md:hidden' : '' }}">
                    @foreach($events as $event)
                        @include('loops.partials.agenda-event', ['event' => $event])
                    @endforeach
                </div>

                @if($view === 'calendar')
                    {{-- TASK-1802 (suite, demande Cyril) — « on va réutiliser
                         cette vue "Calendrier" sur la page Agenda ». Memes
                         grilles que la Card (`LoopEventPresenter::weekGrid()`/
                         `monthGrid()`), mais navigation par LIENS GET — cette
                         page n'est pas un composant Livewire — et detail au
                         clic par un pop-up Alpine (plus bas) plutot qu'un
                         `openEventDetail()` cote serveur, qui n'existe pas ici. --}}
                    <div class="hidden md:block mt-3 rounded-2xl border border-gray-200 bg-white p-3 dark:border-gray-700 dark:bg-gray-900">
                        @php
                            $calendarShiftKey = $calendarGranularity === 'week' ? 'week' : 'month';
                        @endphp
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div class="flex items-center gap-2">
                                <a href="{{ request()->fullUrlWithQuery(['view' => 'calendar', $calendarShiftKey => $calendarGranularity === 'week' ? $calendarPrevWeek : $calendarPrevMonth]) }}"
                                   class="rounded-lg p-1.5 text-gray-500 transition hover:bg-gray-100 dark:hover:bg-gray-800"
                                   aria-label="{{ __('events.previous_month') }}">
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5"/></svg>
                                </a>
                                <p class="min-w-0 text-sm font-semibold capitalize text-gray-900 dark:text-gray-100">{{ $calendar['label'] ?? '' }}</p>
                                <a href="{{ request()->fullUrlWithQuery(['view' => 'calendar', $calendarShiftKey => $calendarGranularity === 'week' ? $calendarNextWeek : $calendarNextMonth]) }}"
                                   class="rounded-lg p-1.5 text-gray-500 transition hover:bg-gray-100 dark:hover:bg-gray-800"
                                   aria-label="{{ __('events.next_month') }}">
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5"/></svg>
                                </a>
                            </div>

                            <div class="inline-flex rounded-lg border border-gray-200 p-0.5 dark:border-gray-700">
                                @foreach(['week' => __('events.view_week'), 'month' => __('events.view_month')] as $granularity => $label)
                                    <a href="{{ request()->fullUrlWithQuery(['view' => 'calendar', 'granularity' => $granularity]) }}"
                                       class="rounded-md px-2 py-1 text-[11px] font-semibold transition {{ $calendarGranularity === $granularity
                                            ? 'bg-sky-600 text-white'
                                            : 'text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800' }}">
                                        {{ $label }}
                                    </a>
                                @endforeach
                            </div>
                        </div>

                        @if($calendarGranularity === 'week')
                            @php
                                $hourPx = 40;
                                $dayHeight = $hourPx * 24;
                                $visibleStartHour = 8;
                            @endphp
                            <div class="mt-2 overflow-auto rounded-xl border border-gray-100 dark:border-gray-800"
                                 style="max-height: 440px;"
                                 x-data x-init="$nextTick(() => { $el.scrollTop = {{ $visibleStartHour * $hourPx }}; })">
                                <div class="grid" style="grid-template-columns: 2.75rem repeat(7, 9rem);">
                                    <div class="sticky top-0 z-20 bg-white dark:bg-gray-900"></div>
                                    @foreach($calendar['days'] ?? [] as $day)
                                        <p class="sticky top-0 z-20 bg-white pb-1 text-center text-[10px] font-semibold uppercase dark:bg-gray-900 {{ $day['is_today'] ? 'text-sky-600 dark:text-sky-300' : 'text-gray-400 dark:text-gray-500' }}">
                                            {{ $day['date']->translatedFormat('D j') }}
                                        </p>
                                    @endforeach

                                    <div>
                                        @for($h = 0; $h < 24; $h++)
                                            <div class="border-t border-gray-100 pr-1 text-right text-[9px] leading-none text-gray-400 dark:border-gray-800 dark:text-gray-500"
                                                 style="height: {{ $hourPx }}px;">
                                                <span class="relative -top-1.5">{{ sprintf('%02d:00', $h) }}</span>
                                            </div>
                                        @endfor
                                    </div>

                                    @foreach($calendar['days'] ?? [] as $day)
                                        <div class="relative border-l border-gray-100 dark:border-gray-800 {{ $day['is_today'] ? 'bg-sky-50/40 dark:bg-sky-900/10' : '' }}"
                                             style="height: {{ $dayHeight }}px;">
                                            @for($h = 0; $h < 24; $h++)
                                                <div class="absolute inset-x-0 border-t border-gray-100 dark:border-gray-800" style="top: {{ $h * $hourPx }}px;"></div>
                                            @endfor

                                            @foreach($day['events'] as $dayEvent)
                                                @php
                                                    $startMin = $dayEvent['starts_local']->hour * 60 + $dayEvent['starts_local']->minute;
                                                    $endMin = $dayEvent['ends_local']
                                                        ? $dayEvent['ends_local']->hour * 60 + $dayEvent['ends_local']->minute
                                                        : $startMin + 60;
                                                    if ($endMin <= $startMin) {
                                                        $endMin = $startMin + 60;
                                                    }
                                                    $topPx = ($startMin / 60) * $hourPx;
                                                    $heightPx = max((($endMin - $startMin) / 60) * $hourPx, 22);
                                                @endphp
                                                <button type="button" x-on:click="selectedEventId = '{{ $dayEvent['id'] }}'"
                                                        class="absolute inset-x-0.5 z-10 overflow-hidden rounded-lg px-1.5 py-0.5 text-left text-[10px] leading-tight shadow-sm {{ $dayEvent['is_cancelled']
                                                            ? 'bg-gray-100 text-gray-500 line-through dark:bg-gray-800 dark:text-gray-400'
                                                            : 'bg-sky-100 text-sky-800 hover:bg-sky-200 dark:bg-sky-900/50 dark:text-sky-200 dark:hover:bg-sky-900/70' }}"
                                                        style="top: {{ $topPx }}px; height: {{ $heightPx }}px;"
                                                        title="{{ $dayEvent['title'] }}">
                                                    <span class="font-semibold">{{ $dayEvent['starts_local']->format('H:i') }}</span>
                                                    {{ $dayEvent['title'] }}
                                                </button>
                                            @endforeach
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @else
                            <div class="mt-2 overflow-x-auto">
                                <table class="w-full min-w-[52rem] table-fixed border-collapse">
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
                                                    <td class="h-24 border border-gray-100 p-1 align-top dark:border-gray-800 {{ $day['in_month'] ? '' : 'opacity-40' }}">
                                                        <span class="block text-right text-[10px] {{ $day['is_today'] ? 'font-bold text-sky-600 dark:text-sky-300' : 'text-gray-400 dark:text-gray-500' }}">{{ $day['day'] }}</span>
                                                        @foreach($day['events'] as $dayEvent)
                                                            <button type="button" x-on:click="selectedEventId = '{{ $dayEvent['id'] }}'"
                                                                    class="mt-0.5 block w-full truncate rounded px-1 text-left text-[10px] leading-4 {{ $dayEvent['is_cancelled']
                                                                    ? 'bg-gray-100 text-gray-500 line-through dark:bg-gray-800 dark:text-gray-400'
                                                                    : 'bg-sky-100 text-sky-800 hover:bg-sky-200 dark:bg-sky-900/40 dark:text-sky-200 dark:hover:bg-sky-900/60' }}"
                                                                    title="{{ $dayEvent['title'] }}">{{ $dayEvent['title'] }}</button>
                                                        @endforeach
                                                    </td>
                                                @endforeach
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </div>

                    {{-- Detail d'un Evenement clique dans le calendrier — un
                         pop-up par Evenement, MEME partiel que la vue liste
                         (`agenda-event`), teleporte au `<body>`. Cette page
                         n'a pas de round-trip serveur a offrir pour l'ouvrir
                         (pas de Livewire), contrairement a `openEventDetail()`
                         sur la Card : tout est deja en memoire, rien a
                         charger de plus au clic. --}}
                    @foreach($events as $event)
                        <template x-teleport="body">
                            <div x-show="selectedEventId === '{{ $event['id'] }}'" x-cloak x-transition
                                 class="fixed inset-0 z-50 flex items-center justify-center bg-gray-900/50 p-4"
                                 x-on:keydown.escape.window="selectedEventId = null"
                                 x-on:click.self="selectedEventId = null"
                                 role="dialog" aria-modal="true">
                                <div class="max-h-[85vh] w-full max-w-md overflow-y-auto rounded-2xl bg-white p-1 shadow-xl dark:bg-gray-800">
                                    <div class="flex items-center justify-end p-2">
                                        <button type="button" x-on:click="selectedEventId = null"
                                                class="rounded-lg p-1 text-gray-400 transition hover:bg-gray-100 hover:text-gray-600 dark:hover:bg-gray-700 dark:hover:text-gray-200"
                                                aria-label="{{ __('events.cancel') }}">
                                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/></svg>
                                        </button>
                                    </div>
                                    <div class="px-3 pb-3">
                                        @include('loops.partials.agenda-event', ['event' => $event])
                                    </div>
                                </div>
                            </div>
                        </template>
                    @endforeach
                @endif
            </div>
        @endif
    </x-page-container>
</x-app-layout>
