{{--
    Card Evenements.

    Deux vues : liste par defaut — c'est elle qui repond a « qu'est-ce qui arrive
    bientot » — et un mois. Le detail des participants est replie : c'est le seul
    chargement couteux de cet ecran, et il ne se paie que si on le demande.

    **Le fuseau du navigateur voyage avec l'ouverture du formulaire.** Ce produit
    n'enregistre aucune preference de fuseau, ni par personne ni par
    Organization, et `app.timezone` vaut `UTC` : le navigateur est donc la seule
    source qui sache reellement ou se trouve quelqu'un. Sans lui, une personne a
    Dallas se verrait proposer l'heure de Paris sans qu'on le lui dise.

    La detection est passee au clic et non a `x-init` : celui-ci s'execute avant
    que Livewire ait fini d'hydrater le composant, et l'appel partait dans le
    vide — constate en recette. Au clic, le moment est celui ou la valeur sert,
    sans course possible, et sans aller-retour supplementaire au chargement.

    Le serveur revalide contre les identifiants IANA de PHP : le navigateur
    propose, il ne decide pas.
--}}
<div class="space-y-3" data-events-root
     x-data="{
        /* Le fuseau declare par l'appareil, ou une chaine vide s'il se tait. */
        detectedTimezone() {
            try { return Intl.DateTimeFormat().resolvedOptions().timeZone || ''; }
            catch (e) { return ''; }
        }
     }">

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
        {{-- TASK-1802 (suite, demande Cyril) — « supprime la vue calendrier
             sur l'agenda et sur la boucle/card evenement [...] uniquement
             sur la version smartphone car ça ne se voit pas ». Le bouton
             bascule disparait en dessous de `md` : sur smartphone, seule la
             liste reste proposee. --}}
        <div class="hidden md:inline-flex rounded-lg border border-gray-300 p-0.5 dark:border-gray-600">
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
            <button type="button" x-on:click="$wire.openCreateForm(detectedTimezone())"
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
                    {{-- `step="900"` (15 min) : la saisie des minutes a la souris
                         n'offre plus que des quarts d'heure, ce qui regle deja
                         l'essentiel de l'inconfort pour le DEBUT (demande de
                         Cyril). --}}
                    <input id="ev-start" type="datetime-local" step="900" wire:model.live="startsAt"
                           class="mt-1 w-full rounded-xl border-gray-300 bg-white text-sm text-gray-900 focus:border-sky-500 focus:ring-sky-500 dark:border-gray-600 dark:bg-gray-950 dark:text-gray-100">
                </div>
                <div>
                    {{-- TASK-1802 (demande Cyril) — la FIN se choisit comme une
                         DUREE relative au debut plutot que comme une minute
                         absolue : `applyDuration()` cote serveur calcule
                         `$endsAt` tout seul. Le champ de date ne reapparait que
                         si cette duree deborde sur un autre jour, ou en mode
                         « dates personnalisees ». --}}
                    <label class="block text-xs font-medium text-gray-500 dark:text-gray-400" for="ev-duration">{{ __('events.duration') }}</label>
                    <select id="ev-duration" wire:model.live="duration"
                            class="mt-1 w-full rounded-xl border-gray-300 bg-white text-sm text-gray-900 focus:border-sky-500 focus:ring-sky-500 dark:border-gray-600 dark:bg-gray-950 dark:text-gray-100">
                        <option value="">{{ __('events.duration_none') }}</option>
                        @foreach ([
                            '30' => 'events.duration_30',
                            '60' => 'events.duration_60',
                            '90' => 'events.duration_90',
                            '120' => 'events.duration_120',
                            '180' => 'events.duration_180',
                            '240' => 'events.duration_240',
                            '480' => 'events.duration_480',
                        ] as $valeur => $cle)
                            <option value="{{ $valeur }}">{{ __($cle) }}</option>
                        @endforeach
                        <option value="{{ \App\Livewire\LoopEventsCard::DURATION_CUSTOM }}">{{ __('events.duration_custom') }}</option>
                    </select>
                </div>
            </div>

            @if($durationCrossesDay)
                <div class="mt-3" data-event-ends-at-field>
                    <label class="block text-xs font-medium text-gray-500 dark:text-gray-400" for="ev-end">{{ __('events.ends_at') }}</label>
                    @if($duration !== \App\Livewire\LoopEventsCard::DURATION_CUSTOM)
                        {{-- La duree reste la source : ce champ ne fait que MONTRER
                             la fin qu'elle calcule, parce qu'elle tombe un autre
                             jour et merite d'etre vue. --}}
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('events.duration_crosses_day') }}</p>
                    @endif
                    <input id="ev-end" type="datetime-local" step="900" wire:model.live="endsAt"
                           class="mt-1 w-full rounded-xl border-gray-300 bg-white text-sm text-gray-900 focus:border-sky-500 focus:ring-sky-500 dark:border-gray-600 dark:bg-gray-950 dark:text-gray-100">
                </div>
            @endif

            {{-- Le fuseau est toujours visible : sans lui, « 19:00 » ne veut
                 rien dire. --}}
            <label class="mt-3 block text-xs font-medium text-gray-500 dark:text-gray-400" for="ev-tz">{{ __('events.timezone') }}</label>
            {{-- `$timezoneOptions` porte toujours le fuseau affiche, meme absent
                 de la liste courte : sinon quelqu'un a Antananarivo verrait le
                 sien detecte puis introuvable apres l'avoir quitte. --}}
            <select id="ev-tz" wire:model="timezone"
                    class="mt-1 w-full rounded-xl border-gray-300 bg-white text-sm text-gray-900 focus:border-sky-500 focus:ring-sky-500 dark:border-gray-600 dark:bg-gray-950 dark:text-gray-100">
                @foreach($timezoneOptions as $tz)
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
        {{-- `hidden md:block` : meme raison que le bouton bascule ci-dessus.
             La vue liste (plus bas) reste, elle, TOUJOURS montee — c'est ce
             qui garantit qu'un redimensionnement vers un smartphone pendant
             que ce calendrier est ouvert ne laisse jamais un ecran vide. --}}
        <div class="hidden md:block rounded-2xl border border-gray-200 bg-white p-3 dark:border-gray-700 dark:bg-gray-900">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div class="flex items-center gap-2">
                    <button type="button" wire:click="{{ $calendarGranularity === 'week' ? 'shiftWeek(-1)' : 'shiftMonth(-1)' }}"
                            class="rounded-lg p-1.5 text-gray-500 transition hover:bg-gray-100 dark:hover:bg-gray-800"
                            aria-label="{{ __('events.previous_month') }}">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5"/></svg>
                    </button>
                    <p class="min-w-0 text-sm font-semibold capitalize text-gray-900 dark:text-gray-100">{{ $calendar['label'] ?? '' }}</p>
                    <button type="button" wire:click="{{ $calendarGranularity === 'week' ? 'shiftWeek(1)' : 'shiftMonth(1)' }}"
                            class="rounded-lg p-1.5 text-gray-500 transition hover:bg-gray-100 dark:hover:bg-gray-800"
                            aria-label="{{ __('events.next_month') }}">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5"/></svg>
                    </button>
                </div>

                {{-- TASK-1802 (demande Cyril, 10/11) — « possibilite via un
                     menu de selectionner semaine ou mois ». --}}
                <div class="inline-flex rounded-lg border border-gray-200 p-0.5 dark:border-gray-700">
                    @foreach(['week' => __('events.view_week'), 'month' => __('events.view_month')] as $granularity => $label)
                        <button type="button" wire:click="setCalendarGranularity('{{ $granularity }}')"
                                class="rounded-md px-2 py-1 text-[11px] font-semibold transition {{ $calendarGranularity === $granularity
                                    ? 'bg-sky-600 text-white'
                                    : 'text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800' }}">
                            {{ $label }}
                        </button>
                    @endforeach
                </div>
            </div>

            @if($calendarGranularity === 'week')
                {{-- TASK-1802 (demande Cyril, 10/11) — « il faut vraiment
                     aerer [...] mettre des horaires ». Une vraie grille
                     horaire (24h) plutot qu'une pile de pastilles : chaque
                     Evenement est positionne a SA hauteur exacte
                     (`top`/`height` calcules depuis son heure locale), comme
                     un agenda papier.

                     TASK-1802 (suite, demande Cyril) — « recentre sur 8h a
                     19h [...] pour le reste il faut scroller. [...] on ne
                     voit pas [...] le titre correctement, on va elargir les
                     cases, quitte a ce qu'on scrolle horizontalement. » Un
                     SEUL conteneur `overflow-auto` (vertical ET horizontal),
                     hauteur bornee a la fenetre 8h-19h, ouvert directement
                     dessus via `x-init` (`scrollTop`) plutot que sur minuit —
                     le reste de la journee reste a un scroll de distance.
                     L'en-tete des jours est `sticky` : il reste visible
                     pendant qu'on descend dans les heures. Les colonnes
                     passent d'une largeur elastique a une largeur FIXE
                     (9rem) : assez pour lire un titre, au prix d'un defilement
                     horizontal sur un ecran etroit — compromis demande
                     explicitement plutot que subi. --}}
                @php
                    $hourPx = 40; // 1h = 2.5rem — resserre pour que 8h-19h tienne dans une fenetre raisonnable
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

                        {{-- Colonne des heures — alignee ligne a ligne sur les
                             traits des colonnes de jours ci-dessous. --}}
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
                                    <button type="button" wire:click="openEventDetail('{{ $dayEvent['id'] }}')"
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
                {{-- TASK-1802 (suite, demande Cyril) — « idem sur la vue
                     mensuelle, il faut agrandir verticalement et elargir
                     horizontalement, quitte a ce qu'on scrolle, parce que
                     sinon on ne voit pas les evenements. » Lignes plus
                     hautes (`h-24` au lieu de `h-14`) et table bien plus
                     large que le panneau (`min-w-[52rem]`) : le defilement,
                     horizontal comme vertical, est assume plutot qu'evite —
                     meme logique que la vue semaine. --}}
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
                                                <button type="button" wire:click="openEventDetail('{{ $dayEvent['id'] }}')"
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
    @endif

    {{-- ── Vue liste ──────────────────────────────────────────────────── ──
         TOUJOURS montee, meme quand `$view === 'calendar'` : seul `md:hidden`
         la cache alors, et seulement a partir de `md`. Sur smartphone, elle
         reste donc TOUJOURS visible — y compris si `$view` valait deja
         « calendar » avant un redimensionnement vers un petit ecran. --}}
    <div class="{{ $view === 'calendar' ? 'md:hidden' : '' }} space-y-3">
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
    </div>

    {{-- ── Detail d'un evenement (depuis le calendrier) ─────────────────────
         TASK-1802 (demande Cyril, 10/11) — « quand on clique sur un
         evenement on voit son detail ». Meme partiel que la vue liste : un
         clic dans le calendrier n'en montre pas moins qu'une ligne de liste. --}}
    @if($selectedEvent)
        <template x-teleport="body">
            <div class="fixed inset-0 z-50 flex items-center justify-center bg-gray-900/50 p-4"
                 x-on:keydown.escape.window="$wire.closeEventDetail()"
                 x-on:click.self="$wire.closeEventDetail()"
                 role="dialog" aria-modal="true">
                <div class="max-h-[85vh] w-full max-w-md overflow-y-auto rounded-2xl bg-white p-1 shadow-xl dark:bg-gray-800">
                    <div class="flex items-center justify-end p-2">
                        <button type="button" wire:click="closeEventDetail"
                                class="rounded-lg p-1 text-gray-400 transition hover:bg-gray-100 hover:text-gray-600 dark:hover:bg-gray-700 dark:hover:text-gray-200"
                                aria-label="{{ __('events.cancel') }}">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/></svg>
                        </button>
                    </div>
                    <div class="px-3 pb-3">
                        @include('loops.partials.event-row', ['event' => $selectedEvent, 'canPublishOrg' => $canPublishOrg])
                    </div>
                </div>
            </div>
        </template>
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
