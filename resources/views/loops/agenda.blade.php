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
            <div class="space-y-3">
                @foreach($events as $event)
                    <div>
                        @include('loops.partials.event-row', [
                            'event' => $event,
                            'interactive' => false,
                            'showLoopName' => true,
                        ])
                        {{-- TASK-1656 — REPONDRE depuis l'agenda.

                             `can_respond` est calcule par le presentateur, qui
                             interroge `LoopEventService::canRespondTo()` : la
                             MEME autorite que la Card de la Boucle. Aucune regle
                             n'est reecrite ici, et il n'est pas demande de
                             rejoindre la Boucle — un evenement remonte au niveau
                             Organization se repond par tout membre actif, c'est
                             le sens de l'avoir remonte.

                             Des formulaires, pas `wire:click` : l'agenda n'est
                             pas un composant Livewire, et en faire un pour trois
                             boutons couterait plus que le geste ne vaut. Les
                             libelles sont ceux de la Card — `events.going`,
                             `events.maybe`, `events.not_going` — pour que la
                             meme action ne porte pas deux noms. --}}
                        @if($event['can_respond'] && ! $event['is_past'] && ! $event['is_cancelled'])
                            <div data-agenda-rsvp="{{ $event['id'] }}" class="mt-2 flex flex-wrap gap-1.5 pl-1">
                                @foreach([
                                    \App\Models\LoopEventResponse::GOING => ['label' => __('events.going'), 'on' => 'bg-emerald-600 text-white border-transparent', 'off' => 'border-emerald-300 text-emerald-700 hover:bg-emerald-50 dark:border-emerald-800 dark:text-emerald-300 dark:hover:bg-emerald-900/30'],
                                    \App\Models\LoopEventResponse::MAYBE => ['label' => __('events.maybe'), 'on' => 'bg-amber-600 text-white border-transparent', 'off' => 'border-amber-300 text-amber-700 hover:bg-amber-50 dark:border-amber-800 dark:text-amber-300 dark:hover:bg-amber-900/30'],
                                    \App\Models\LoopEventResponse::NOT_GOING => ['label' => __('events.not_going'), 'on' => 'bg-gray-600 text-white border-transparent', 'off' => 'border-gray-300 text-gray-600 hover:bg-gray-100 dark:border-gray-600 dark:text-gray-300 dark:hover:bg-gray-800'],
                                ] as $valeur => $style)
                                    @php($choisi = $event['my_response'] === $valeur)
                                    <form method="POST"
                                          action="{{ route('organization.events.agenda.respond', ['organization' => $organization->slug, 'event' => $event['id']]) }}">
                                        @csrf
                                        <input type="hidden" name="response" value="{{ $valeur }}">
                                        <button type="submit"
                                                data-rsvp="{{ $valeur }}"
                                                aria-pressed="{{ $choisi ? 'true' : 'false' }}"
                                                class="rounded-lg border px-3 py-1.5 text-xs font-semibold transition {{ $choisi ? $style['on'] : $style['off'] }}">
                                            {{ $style['label'] }}
                                        </button>
                                    </form>
                                @endforeach
                            </div>
                        @endif

                        <p class="mt-1 pl-1">
                            <a href="{{ route('organization.loops.show', ['organization' => $organization->slug, 'loop' => $event['loop_id']]) }}"
                               class="text-[11px] font-semibold text-sky-700 hover:underline dark:text-sky-300">
                                {{ __('events.open_loop') }} →
                            </a>
                        </p>
                    </div>
                @endforeach
            </div>
        @endif
    </x-page-container>
</x-app-layout>
