{{--
    Une rencontre de l'agenda : la ligne (bento) + le geste RSVP + le lien
    « Ouvrir la Boucle ». Extrait de la boucle `@@foreach` de `agenda.blade.php`
    (TASK-1802, suite, demande Cyril) pour etre rendu DEUX fois sans jamais
    diverger : une fois dans la vue liste (toujours montee), une fois par
    Evenement dans le pop-up de detail de la vue calendrier.

    `$organization` n'est pas passe explicitement : l'inclusion herite du
    contexte de la vue appelante, ou il est deja disponible.
--}}
<div x-data="{
        myResponse: @js($event['my_response']),
        going: {{ $event['counts']['going'] }},
        maybe: {{ $event['counts']['maybe'] }},
        busy: false,
        async respond(value) {
            if (this.busy || this.myResponse === value) return;
            const previous = this.myResponse;
            this.busy = true;
            if (previous === 'going') this.going--;
            if (previous === 'maybe') this.maybe--;
            if (value === 'going') this.going++;
            if (value === 'maybe') this.maybe++;
            this.myResponse = value;
            try {
                const res = await fetch('{{ route('organization.events.agenda.respond', ['organization' => $organization->slug, 'event' => $event['id']]) }}', {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({ response: value }),
                });
                if (! res.ok) { throw new Error('rsvp failed'); }
            } catch (e) {
                if (value === 'going') this.going--;
                if (value === 'maybe') this.maybe--;
                if (previous === 'going') this.going++;
                if (previous === 'maybe') this.maybe++;
                this.myResponse = previous;
            } finally {
                this.busy = false;
            }
        },
    }">
    @include('loops.partials.event-row', [
        'event' => $event,
        'interactive' => false,
        'showLoopName' => true,
        'reactivePresence' => true,
    ])
    {{-- TASK-1656 — REPONDRE depuis l'agenda.

         `can_respond` est calcule par le presentateur, qui interroge
         `LoopEventService::canRespondTo()` : la MEME autorite que la Card de
         la Boucle. Aucune regle n'est reecrite ici, et il n'est pas demande
         de rejoindre la Boucle — un evenement remonte au niveau Organization
         se repond par tout membre actif, c'est le sens de l'avoir remonte.

         Des formulaires, pas `wire:click` : l'agenda n'est pas un composant
         Livewire, et en faire un pour deux boutons couterait plus que le
         geste ne vaut. Les libelles sont ceux de la Card — `events.going`,
         `events.maybe` — pour que la meme action ne porte pas deux noms.

         TASK-1802 (suite, demande Cyril) — « remplace le bouton "Je ne
         participe pas" par "Ajouter à l'agenda" ». Demande LIMITEE a ce
         remplacement : "Je participe"/"Peut-être" gardent leur libelle
         d'origine ici (la renomination en "Oui"/"Intéressé" ne concernait
         que la Card). Pas de formulaire pour ce bouton-la : des liens
         simples (externes ou `download`), memes URLs que la Card
         (`calendar_links`, calculees une fois par `LoopEventPresenter`). --}}
    @if($event['can_respond'] && ! $event['is_past'] && ! $event['is_cancelled'])
        <div data-agenda-rsvp="{{ $event['id'] }}" class="mt-2 flex flex-wrap items-center gap-1.5 pl-1">
            @foreach([
                \App\Models\LoopEventResponse::GOING => ['label' => __('events.going'), 'on' => 'bg-emerald-600 text-white border-transparent', 'off' => 'border-emerald-300 text-emerald-700 hover:bg-emerald-50 dark:border-emerald-800 dark:text-emerald-300 dark:hover:bg-emerald-900/30'],
                \App\Models\LoopEventResponse::MAYBE => ['label' => __('events.maybe'), 'on' => 'bg-amber-600 text-white border-transparent', 'off' => 'border-amber-300 text-amber-700 hover:bg-amber-50 dark:border-amber-800 dark:text-amber-300 dark:hover:bg-amber-900/30'],
            ] as $valeur => $style)
                <form method="POST"
                      x-on:submit.prevent="respond('{{ $valeur }}')"
                      action="{{ route('organization.events.agenda.respond', ['organization' => $organization->slug, 'event' => $event['id']]) }}">
                    @csrf
                    <input type="hidden" name="response" value="{{ $valeur }}">
                    <button type="submit"
                            data-rsvp="{{ $valeur }}"
                            x-bind:aria-pressed="(myResponse === '{{ $valeur }}').toString()"
                            x-bind:class="myResponse === '{{ $valeur }}' ? '{{ $style['on'] }}' : '{{ $style['off'] }}'"
                            class="rounded-lg border px-3 py-1.5 text-xs font-semibold transition">
                        {{ $style['label'] }}
                    </button>
                </form>
            @endforeach

            <div class="relative" x-data="{ ouvert: false }" x-on:keydown.escape.window="ouvert = false">
                <button type="button" x-on:click="ouvert = ! ouvert" x-bind:aria-expanded="ouvert"
                        aria-label="{{ __('events.add_to_calendar') }}"
                        data-agenda-add-to-calendar
                        class="inline-flex items-center gap-1.5 rounded-lg border border-gray-300 px-3 py-1.5 text-xs font-semibold text-gray-600 transition hover:bg-gray-100 dark:border-gray-600 dark:text-gray-300 dark:hover:bg-gray-800">
                    <svg class="h-3.5 w-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0V11.25A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5"/></svg>
                    {{ __('events.add_cta') }}
                </button>

                <div x-show="ouvert" x-cloak x-transition x-on:click.outside="ouvert = false"
                     class="absolute left-0 z-10 mt-1 w-48 rounded-xl border border-gray-200 bg-white p-1 shadow-lg dark:border-gray-700 dark:bg-gray-800">
                    <a href="{{ $event['calendar_links']['google'] }}" target="_blank" rel="noopener noreferrer"
                       x-on:click="ouvert = false"
                       class="block rounded-lg px-2.5 py-1.5 text-xs font-semibold text-gray-600 transition hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700">
                        {{ __('events.calendar_google') }}
                    </a>
                    <a href="{{ $event['calendar_links']['outlook'] }}" target="_blank" rel="noopener noreferrer"
                       x-on:click="ouvert = false"
                       class="block rounded-lg px-2.5 py-1.5 text-xs font-semibold text-gray-600 transition hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700">
                        {{ __('events.calendar_outlook') }}
                    </a>
                    <a href="{{ $event['calendar_links']['yahoo'] }}" target="_blank" rel="noopener noreferrer"
                       x-on:click="ouvert = false"
                       class="block rounded-lg px-2.5 py-1.5 text-xs font-semibold text-gray-600 transition hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700">
                        {{ __('events.calendar_yahoo') }}
                    </a>
                    <a href="{{ $event['calendar_links']['ics'] }}" download="{{ \Illuminate\Support\Str::slug($event['title']) }}.ics"
                       x-on:click="ouvert = false"
                       class="block rounded-lg px-2.5 py-1.5 text-xs font-semibold text-gray-600 transition hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700">
                        {{ __('events.calendar_download_ics') }}
                    </a>
                </div>
            </div>
        </div>
    @endif

    <p class="mt-1 pl-1">
        <a href="{{ route('organization.loops.show', ['organization' => $organization->slug, 'loop' => $event['loop_id']]) }}"
           class="text-[11px] font-semibold text-sky-700 hover:underline dark:text-sky-300">
            {{ __('events.open_loop') }} →
        </a>
    </p>
</div>
