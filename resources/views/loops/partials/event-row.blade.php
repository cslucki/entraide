{{--
    Une rencontre, telle qu'elle s'affiche.

    Partagee par la Card d'une Boucle et par l'agenda d'Organization : les deux
    montrent la meme chose, et diverger aurait ete la premiere chose a arriver si
    chacune avait son balisage.

    Les actions ne sont rendues que si l'appelant les fournit (`$interactive`).
    L'agenda, lui, montre et laisse agir dans la Boucle.

    TASK-1802 (demande Cyril, 10/11) — « le design est loin d'etre premium,
    que pourrait-on faire en mode bento ? ». L'information ne change pas, sa
    mise en page oui : une tuile date (la seule surface pleine couleur), une
    tuile titre, une tuile lieu/lien, une tuile presences, et une tuile
    description.

    TASK-1802 (suite, « optimiser encore [...] sur smartphone [...] mais
    aussi sur desktop ») — date et titre sont groupes dans un SEUL bloc
    `flex` interne (jamais `flex-wrap` entre eux), lui-meme UN SEUL element
    du `flex-wrap` externe qui porte aussi lieu/presences/description.
    Premiere version : date et titre etaient deux elements SEPARES de ce
    `flex-wrap` externe — sur un telephone, la date restait seule sur sa
    ligne (son voisin, trop large, retombait plus bas), avec tout l'espace a
    sa droite perdu. Les grouper fait qu'ils refluent ENSEMBLE, jamais l'un
    sans l'autre ; `min-w-0` sur la tuile titre la laisse RETRECIR a
    l'interieur du bloc : c'est le TEXTE qui enjambe plusieurs lignes,
    jamais une tuile qui saute seule.

    TASK-1802 (suite, demande Cyril) — « juste un bouton pour participer et
    un bouton pour ajouter à son agenda [...] Concernant ceux qui
    participent, on met uniquement ceux-là [...] un icône et un chiffre. »
    Le menu ⋮ a 3 choix (ci-dessus) a vecu : un seul bouton bascule
    GOING/NOT_GOING (`respond()` accepte toujours les 3 valeurs — la donnee
    MAYBE deja enregistree reste lisible, simplement plus proposee a l'ecran).
    Seul le compte GOING s'affiche (icone + chiffre), jamais la repartition
    Peut-être/Absents. « Ajouter à l'agenda » ouvre un second menu (meme
    idiome) : aucune URL ne couvre Google/Outlook/Yahoo ET Apple/le reste a
    la fois, d'ou le choix plutot qu'un lien unique.
--}}
@php
    $interactive = $interactive ?? true;
    $showLoopName = $showLoopName ?? false;
    $canPublishOrg = $canPublishOrg ?? false;
    $hasWhere = (bool) ($event['location'] || $event['meeting_url']);
    $isGoing = $event['my_response'] === \App\Models\LoopEventResponse::GOING;
    $isInterested = $event['my_response'] === \App\Models\LoopEventResponse::MAYBE;
@endphp

<div class="rounded-[26px] border p-3 shadow-sm {{ $event['is_cancelled']
        ? 'border-gray-200 bg-gray-50 dark:border-gray-700 dark:bg-gray-900/60 dark:shadow-none'
        : 'border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-900 dark:shadow-none' }}"
     wire:key="event-{{ $event['id'] }}" data-event-id="{{ $event['id'] }}">

    <div class="flex flex-wrap gap-2">
        {{-- Bloc date + titre — un SEUL element du `flex-wrap` : ils refluent
             ensemble, jamais l'un sans l'autre. --}}
        <div class="flex min-w-[12rem] flex-[2_1_16rem] gap-2">
            {{-- Tuile date — la seule surface pleine couleur : le reste reste
                 sobre pour ne pas lui faire concurrence. Le fuseau est toujours
                 ecrit, meme minuscule : sans lui une heure ne veut rien dire. --}}
            <div class="flex w-20 flex-none flex-col items-center justify-center gap-0.5 rounded-2xl p-2.5 text-center text-white {{ $event['is_cancelled']
                    ? 'bg-gradient-to-br from-gray-400 to-gray-500 dark:from-gray-600 dark:to-gray-700'
                    : 'bg-gradient-to-br from-indigo-600 to-indigo-700 dark:from-indigo-500 dark:to-indigo-600' }}">
                <span class="text-[10px] font-bold uppercase tracking-wide opacity-80">{{ $event['starts_local']->translatedFormat('D') }}</span>
                <span class="font-mono text-2xl font-bold leading-none tabular-nums">{{ $event['starts_local']->translatedFormat('d') }}</span>
                <span class="text-[10px] font-semibold opacity-90">{{ $event['starts_local']->translatedFormat('M') }} {{ $event['starts_local']->format('Y') }}</span>
                <span class="mt-1 rounded-full bg-white/20 px-1.5 py-0.5 font-mono text-[10px] leading-tight tabular-nums">
                    {{ $event['starts_local']->format('H:i') }}{{ $event['ends_local'] ? '–'.$event['ends_local']->format('H:i') : '' }}
                </span>
                <span class="text-[9px] leading-tight opacity-70">{{ $event['timezone'] }}</span>
            </div>

            {{-- Tuile titre — `min-w-0` : sans lui, un `flex` enfant ne retrecit
                 jamais sous sa largeur de contenu, et c'est la tuile ENTIERE qui
                 passerait a la ligne plutot que son texte. --}}
            <div class="min-w-0 flex-1 rounded-2xl bg-gray-50 p-3 dark:bg-gray-800/60">
                @if($event['is_cancelled'] || $event['is_organization_wide'])
                    <span class="mb-1 flex flex-wrap items-center gap-1">
                        @if($event['is_cancelled'])
                            <span class="rounded-full bg-gray-200 px-2 py-0.5 text-[11px] font-semibold text-gray-600 dark:bg-gray-700 dark:text-gray-300">{{ __('events.status_cancelled') }}</span>
                        @endif
                        @if($event['is_organization_wide'])
                            <span class="rounded-full bg-violet-100 px-2 py-0.5 text-[11px] font-semibold text-violet-700 dark:bg-violet-900/40 dark:text-violet-300">{{ __('events.scope_organization') }}</span>
                        @endif
                    </span>
                @endif

                <p class="text-sm font-semibold {{ $event['is_cancelled']
                        ? 'text-gray-500 line-through dark:text-gray-400'
                        : 'text-gray-900 dark:text-gray-100' }}">{{ $event['title'] }}</p>

                <p class="mt-1 flex flex-wrap items-center gap-x-1.5 gap-y-0.5 text-[11px] text-gray-500 dark:text-gray-400">
                    {{-- Le format n'est repete ici QUE s'il n'a pas deja sa propre
                         tuile « ou » plus bas — jamais le dire deux fois. --}}
                    @unless($hasWhere)
                        <span>{{ __('events.format_'.$event['format']) }}</span>
                        <span>·</span>
                    @endunless
                    @if($showLoopName && $event['loop_name'])
                        <span>{{ __('events.from_loop', ['name' => $event['loop_name']]) }}</span>
                        <span>·</span>
                    @endif
                    <span>{{ __('events.by', ['name' => $event['author']]) }}</span>
                    @if($event['is_cancelled'] && $event['cancelled_at'])
                        <span>·</span>
                        <span>{{ __('events.cancelled_notice', ['date' => $event['cancelled_at']->isoFormat('LL')]) }}</span>
                    @endif
                </p>

                {{-- TASK-1802 (demande Cyril) — « il faut mettre le
                     descriptif dans le bento ou il y a le titre » : la tuile
                     titre restait vide en dessous du meta sur un titre court
                     (visible mobile, capture avec fleche). La description y
                     vit desormais, au lieu d'une tuile dediee plus bas. --}}
                @if($event['description'])
                    <p class="mt-1.5 text-xs leading-5 text-gray-600 dark:text-gray-300">
                        {{ $event['description'] }}
                    </p>
                @endif
            </div>
        </div>

        {{-- Tuile lieu / lien --}}
        @if($hasWhere)
            <div class="min-w-[10rem] flex-1 rounded-2xl bg-gray-50 p-3 dark:bg-gray-800/60">
                <p class="text-[10px] font-bold uppercase tracking-wide text-gray-400 dark:text-gray-500">{{ __('events.format_'.$event['format']) }}</p>

                @if($event['location'])
                    <p class="mt-1 flex items-start gap-1.5 text-xs text-gray-600 dark:text-gray-300">
                        <svg class="mt-0.5 h-3.5 w-3.5 shrink-0 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1 1 15 0Z"/></svg>
                        <span class="min-w-0">{{ $event['location'] }}</span>
                    </p>
                @endif

                @if($event['meeting_url'])
                    {{-- `noopener` systematique : le lien vient d'un membre, pas de nous. --}}
                    <p class="mt-1 flex items-start gap-1.5 text-xs">
                        <svg class="mt-0.5 h-3.5 w-3.5 shrink-0 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13.19 8.688a4.5 4.5 0 0 1 1.242 7.244l-4.5 4.5a4.5 4.5 0 0 1-6.364-6.364l1.757-1.757m13.35-.622 1.757-1.757a4.5 4.5 0 0 0-6.364-6.364l-4.5 4.5a4.5 4.5 0 0 0 1.242 7.244"/></svg>
                        <a href="{{ $event['meeting_url'] }}" target="_blank" rel="noopener noreferrer"
                           class="min-w-0 break-all text-sky-700 hover:underline dark:text-sky-300">{{ $event['meeting_url'] }}</a>
                    </p>
                @endif
            </div>
        @endif

        {{-- Tuile presences — demande Cyril : « on met uniquement ceux
             [qui participent] [...] un icône et un chiffre » pour ne pas
             saturer la tuile — jamais le compte des absents. Les interesses
             rejoignent les participants (demande Cyril, suite) : meme icone,
             coloris distinct (emeraude/ambre, deja la convention du produit). --}}
        @php
            $hasAnyAttendee = ($event['counts']['going'] + $event['counts']['maybe']) > 0;
            // TASK-1802 (suite, demande Cyril) — « sur la page agenda, il
            // faut qu'on puisse cliquer sur [...] les icones des nombres de
            // participants [...] un pop-up alpine affiche les participants ».
            // Un pop-up n'a de sens QUE si les noms sont deja en main : sur
            // la Card (`$interactive`), ils restent charges a la demande par
            // `toggleAttendees()` (Livewire) et s'ouvrent en ligne, inchange.
            // Hors Card (agenda), `LoopEventAgendaController` les charge
            // desormais toujours — jamais de round-trip pour un simple clic.
            $canPopAttendees = ! $interactive && $hasAnyAttendee && $event['attendees'] !== null;
        @endphp
        {{-- TASK-1802 (suite, demande Cyril) — « pouvoir cliquer sur la card
             et non pas sur les chiffres [...] plus facile pour afficher les
             participants ». Le declencheur du pop-up est maintenant la tuile
             ENTIERE (`role="button"`), plus seulement les deux pastilles —
             cible bien plus grande, surtout au doigt. --}}
        <div class="min-w-[9rem] flex-1 rounded-2xl bg-gray-50 p-3 dark:bg-gray-800/60 {{ $canPopAttendees ? 'cursor-pointer transition hover:bg-gray-100 dark:hover:bg-gray-700/50' : '' }}"
             x-data="{ ouvert: false }" x-on:keydown.escape.window="ouvert = false"
             @if($canPopAttendees)
                 role="button" tabindex="0" aria-haspopup="dialog"
                 x-on:click="ouvert = true" x-on:keydown.enter="ouvert = true"
             @endif>
            <p class="text-[10px] font-bold uppercase tracking-wide text-gray-400 dark:text-gray-500">{{ __('events.attendance_label') }}</p>

            <div class="mt-1 flex items-center gap-3">
                @include('loops.partials.event-row-presence-counts', ['event' => $event, 'reactive' => $reactivePresence ?? false])
            </div>

            @if($canPopAttendees)
                <template x-teleport="body">
                        <div x-show="ouvert" x-cloak x-transition
                             class="fixed inset-0 z-50 flex items-center justify-center bg-gray-900/50 p-4"
                             x-on:click.self="ouvert = false" role="dialog" aria-modal="true">
                            <div class="w-full max-w-sm rounded-2xl bg-white p-5 shadow-xl dark:bg-gray-800">
                                <div class="flex items-center justify-between">
                                    <h2 class="text-sm font-bold text-gray-900 dark:text-gray-100">{{ $event['title'] }}</h2>
                                    <button type="button" x-on:click="ouvert = false"
                                            class="rounded-lg p-1 text-gray-400 transition hover:bg-gray-100 hover:text-gray-600 dark:hover:bg-gray-700 dark:hover:text-gray-200"
                                            aria-label="{{ __('events.cancel') }}">
                                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/></svg>
                                    </button>
                                </div>

                                <div class="mt-4 space-y-4">
                                    @foreach([
                                        \App\Models\LoopEventResponse::GOING => ['label' => __('events.going_short'), 'ring' => 'ring-emerald-200 dark:ring-emerald-800', 'bg' => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/50 dark:text-emerald-300'],
                                        \App\Models\LoopEventResponse::MAYBE => ['label' => __('events.maybe_short'), 'ring' => 'ring-amber-200 dark:ring-amber-800', 'bg' => 'bg-amber-100 text-amber-700 dark:bg-amber-900/50 dark:text-amber-300'],
                                    ] as $key => $section)
                                        @if(! empty($event['attendees'][$key]))
                                            <div>
                                                <p class="text-[10px] font-bold uppercase tracking-wide text-gray-400 dark:text-gray-500">
                                                    {{ $section['label'] }} · {{ count($event['attendees'][$key]) }}
                                                </p>
                                                <ul class="mt-2 space-y-1.5">
                                                    @foreach($event['attendees'][$key] as $name)
                                                        <li class="flex items-center gap-2.5">
                                                            <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-xs font-bold ring-2 {{ $section['ring'] }} {{ $section['bg'] }}">
                                                                {{ mb_strtoupper(mb_substr($name, 0, 1)) }}
                                                            </span>
                                                            <span class="truncate text-sm text-gray-700 dark:text-gray-200">{{ $name }}</span>
                                                        </li>
                                                    @endforeach
                                                </ul>
                                            </div>
                                        @endif
                                    @endforeach
                                </div>
                            </div>
                        </div>
                    </template>
            @endif

            @if($interactive && $hasAnyAttendee)
                <button type="button" wire:click="toggleAttendees('{{ $event['id'] }}')"
                        class="mt-1.5 text-[11px] font-semibold text-sky-700 transition hover:underline dark:text-sky-300">
                    {{ $event['attendees'] !== null ? __('events.attendees_hide') : __('events.attendees_show') }}
                </button>
            @endif
        </div>
    </div>

    @if($interactive && $event['attendees'] !== null)
        <div class="mt-2.5 space-y-1 border-t border-gray-100 pt-2 dark:border-gray-800">
            @foreach([
                \App\Models\LoopEventResponse::GOING => __('events.going_short'),
                \App\Models\LoopEventResponse::MAYBE => __('events.maybe_short'),
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

    @if($interactive)
        {{-- Toujours rendue : « Ajouter à l'agenda » ne depend d'aucune
             permission RSVP, seul « Participer » l'exige. --}}
        <div class="mt-2.5 flex flex-wrap items-center gap-1.5">
            @if($event['can_respond'])
                {{-- Deux boutons — demande Cyril : « oui et interessé » —
                     plutot qu'un seul qui bascule : chacun porte SA propre
                     reponse, et en choisir un desactive l'autre (une seule
                     reponse possible, `respond()` ecrase la precedente). --}}
                <button type="button" wire:click="respond('{{ $event['id'] }}', '{{ $isGoing ? \App\Models\LoopEventResponse::NOT_GOING : \App\Models\LoopEventResponse::GOING }}')"
                        aria-pressed="{{ $isGoing ? 'true' : 'false' }}"
                        class="inline-flex items-center gap-1.5 rounded-xl border px-3 py-1.5 text-xs font-semibold transition {{ $isGoing
                            ? 'border-transparent bg-emerald-600 text-white hover:bg-emerald-700'
                            : 'border-emerald-300 text-emerald-700 hover:bg-emerald-50 dark:border-emerald-800 dark:text-emerald-300 dark:hover:bg-emerald-900/30' }}">
                    @if($isGoing)
                        <svg class="h-3.5 w-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/></svg>
                    @endif
                    {{ __('events.rsvp_yes') }}
                </button>

                <button type="button" wire:click="respond('{{ $event['id'] }}', '{{ $isInterested ? \App\Models\LoopEventResponse::NOT_GOING : \App\Models\LoopEventResponse::MAYBE }}')"
                        aria-pressed="{{ $isInterested ? 'true' : 'false' }}"
                        class="inline-flex items-center gap-1.5 rounded-xl border px-3 py-1.5 text-xs font-semibold transition {{ $isInterested
                            ? 'border-transparent bg-amber-600 text-white hover:bg-amber-700'
                            : 'border-amber-300 text-amber-700 hover:bg-amber-50 dark:border-amber-800 dark:text-amber-300 dark:hover:bg-amber-900/30' }}">
                    @if($isInterested)
                        <svg class="h-3.5 w-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/></svg>
                    @endif
                    {{ __('events.rsvp_interested') }}
                </button>
            @endif

            {{-- « Ajouter à l'agenda » — compatible Google Agenda et, si
                 possible, les autres (demande Cyril). Bouton compact : icone
                 + le seul verbe « Ajouter » (demande Cyril, suite) — le
                 libelle complet reste porte par `aria-label`, pour qui lit
                 l'ecran. --}}
            <div class="relative" x-data="{ ouvert: false }" x-on:keydown.escape.window="ouvert = false">
                <button type="button" x-on:click="ouvert = ! ouvert" x-bind:aria-expanded="ouvert"
                        aria-label="{{ __('events.add_to_calendar') }}"
                        class="inline-flex items-center gap-1.5 rounded-xl border border-gray-300 px-3 py-1.5 text-xs font-semibold text-gray-600 transition hover:bg-gray-100 dark:border-gray-600 dark:text-gray-300 dark:hover:bg-gray-800">
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

        @if($event['can_manage'] && ! $event['is_cancelled'])
            <div class="mt-2.5 flex flex-wrap gap-1.5">
                <button type="button" wire:click="openEditForm('{{ $event['id'] }}')"
                        class="rounded-xl border border-gray-300 px-3 py-1.5 text-xs font-semibold text-gray-600 transition hover:bg-gray-100 dark:border-gray-600 dark:text-gray-300 dark:hover:bg-gray-800">
                    {{ __('events.edit') }}
                </button>

                @if($canPublishOrg)
                    @php($target = $event['is_organization_wide']
                        ? \App\Models\LoopEvent::VISIBILITY_LOOP
                        : \App\Models\LoopEvent::VISIBILITY_ORGANIZATION)
                    <button type="button" wire:click="confirmVisibility('{{ $event['id'] }}', '{{ $target }}')"
                            class="rounded-xl border border-violet-300 px-3 py-1.5 text-xs font-semibold text-violet-700 transition hover:bg-violet-50 dark:border-violet-800 dark:text-violet-300 dark:hover:bg-violet-900/30">
                        {{ $event['is_organization_wide'] ? __('events.restrict_loop_cta') : __('events.publish_org_cta') }}
                    </button>
                @endif

                <button type="button" wire:click="confirmCancel('{{ $event['id'] }}')"
                        class="rounded-xl border border-amber-300 px-3 py-1.5 text-xs font-semibold text-amber-700 transition hover:bg-amber-50 dark:border-amber-800 dark:text-amber-300 dark:hover:bg-amber-900/30">
                    {{ __('events.cancel_event') }}
                </button>

                @if($event['can_delete'])
                    <button type="button" wire:click="confirmDelete('{{ $event['id'] }}')"
                            class="rounded-xl border border-red-300 px-3 py-1.5 text-xs font-semibold text-red-700 transition hover:bg-red-50 dark:border-red-800 dark:text-red-300 dark:hover:bg-red-900/30">
                        {{ __('events.delete') }}
                    </button>
                @endif
            </div>
        @endif
    @endif
</div>
