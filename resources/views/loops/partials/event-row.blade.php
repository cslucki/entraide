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

    TASK-1802 (suite, demande Cyril) — « le bouton "Je participe" et "Je ne
    participe pas" et peut-être dans un menu 3 points. Ainsi, on gagne de
    l'espace [...] un joli aspect pour les boutons. » La reponse RSVP
    quittait sa propre ligne de 3 boutons pour un menu ⋮ loge dans la tuile
    presences (meme idiome `x-data`/`x-show`/`click.outside` que le menu
    « Gerer la Boucle », `loops/partials/header-actions.blade.php`) — le
    declencheur porte deja la couleur de la reponse en cours (vide = pas
    encore repondu), la coche se voit une fois le menu ouvert.
--}}
@php
    $interactive = $interactive ?? true;
    $showLoopName = $showLoopName ?? false;
    $canPublishOrg = $canPublishOrg ?? false;
    $total = $event['counts']['going'] + $event['counts']['maybe'] + $event['counts']['not_going'];
    $hasWhere = (bool) ($event['location'] || $event['meeting_url']);
    $rsvpOptions = [
        \App\Models\LoopEventResponse::GOING => [
            'label' => __('events.going'),
            'dot' => 'bg-emerald-500',
            'trigger' => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300',
            'item_on' => 'bg-emerald-50 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300',
        ],
        \App\Models\LoopEventResponse::MAYBE => [
            'label' => __('events.maybe'),
            'dot' => 'bg-amber-500',
            'trigger' => 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300',
            'item_on' => 'bg-amber-50 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300',
        ],
        \App\Models\LoopEventResponse::NOT_GOING => [
            'label' => __('events.not_going'),
            'dot' => 'bg-gray-400',
            'trigger' => 'bg-gray-200 text-gray-700 dark:bg-gray-700 dark:text-gray-300',
            'item_on' => 'bg-gray-100 text-gray-700 dark:bg-gray-700/60 dark:text-gray-200',
        ],
    ];
    $rsvpTriggerClass = $rsvpOptions[$event['my_response']]['trigger']
        ?? 'text-gray-400 hover:bg-gray-100 dark:text-gray-500 dark:hover:bg-gray-700';
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

        {{-- Tuile presences — les compteurs, toujours ; les noms, sur demande. --}}
        <div class="min-w-[10rem] flex-1 rounded-2xl bg-gray-50 p-3 dark:bg-gray-800/60">
            <div class="flex items-center justify-between gap-2">
                <p class="text-[10px] font-bold uppercase tracking-wide text-gray-400 dark:text-gray-500">{{ __('events.attendance_label') }}</p>

                @if($interactive && $event['can_respond'])
                    {{-- Menu ⋮ — meme idiome que « Gerer la Boucle »
                         (`header-actions.blade.php`) : `x-data`/`x-show`/
                         `click.outside`. Le declencheur porte deja la
                         couleur de la reponse en cours ; vide, il invite a
                         repondre. --}}
                    <div class="relative" x-data="{ ouvert: false }" x-on:keydown.escape.window="ouvert = false">
                        <button type="button" x-on:click="ouvert = ! ouvert" x-bind:aria-expanded="ouvert"
                                aria-label="{{ $event['my_response']
                                    ? __('events.your_response').' '.__('events.'.$event['my_response'])
                                    : __('events.respond_menu_label') }}"
                                class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full transition {{ $rsvpTriggerClass }}">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6.75h.007v.008H12V6.75Zm0 5.25h.007v.008H12V12Zm0 5.25h.007v.008H12v-.008Z"/></svg>
                        </button>

                        <div x-show="ouvert" x-cloak x-transition x-on:click.outside="ouvert = false"
                             class="absolute right-0 z-10 mt-1 w-40 rounded-xl border border-gray-200 bg-white p-1 shadow-lg dark:border-gray-700 dark:bg-gray-800">
                            @foreach($rsvpOptions as $value => $opt)
                                @php($chosen = $event['my_response'] === $value)
                                <button type="button" wire:click="respond('{{ $event['id'] }}', '{{ $value }}')"
                                        x-on:click="ouvert = false"
                                        aria-pressed="{{ $chosen ? 'true' : 'false' }}"
                                        class="flex w-full items-center gap-2 rounded-lg px-2.5 py-1.5 text-left text-xs font-semibold transition {{ $chosen ? $opt['item_on'] : 'text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700' }}">
                                    <span class="h-2 w-2 shrink-0 rounded-full {{ $opt['dot'] }}"></span>
                                    <span class="truncate">{{ $opt['label'] }}</span>
                                    @if($chosen)
                                        <svg class="ml-auto h-3.5 w-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/></svg>
                                    @endif
                                </button>
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>
            <div class="mt-1 flex flex-wrap items-center gap-1.5 text-[11px]">
                <span class="rounded-full bg-emerald-100 px-2 py-0.5 font-semibold text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300">
                    {{ __('events.going_short') }} {{ $event['counts']['going'] }}
                </span>
                <span class="rounded-full bg-amber-100 px-2 py-0.5 font-semibold text-amber-700 dark:bg-amber-900/40 dark:text-amber-300">
                    {{ __('events.maybe_short') }} {{ $event['counts']['maybe'] }}
                </span>
                <span class="rounded-full bg-gray-100 px-2 py-0.5 font-semibold text-gray-600 dark:bg-gray-800 dark:text-gray-400">
                    {{ __('events.not_going_short') }} {{ $event['counts']['not_going'] }}
                </span>
            </div>

            @if($interactive && $total > 0)
                <button type="button" wire:click="toggleAttendees('{{ $event['id'] }}')"
                        class="mt-1.5 text-[11px] font-semibold text-sky-700 transition hover:underline dark:text-sky-300">
                    {{ $event['attendees'] !== null ? __('events.attendees_hide') : __('events.attendees_show') }}
                </button>
            @endif
        </div>

        {{-- Tuile description, pleine largeur --}}
        @if($event['description'])
            <div class="w-full rounded-2xl bg-gray-50 p-3 text-xs leading-5 text-gray-600 dark:bg-gray-800/60 dark:text-gray-300">
                {{ $event['description'] }}
            </div>
        @endif
    </div>

    @if($event['attendees'] !== null)
        <div class="mt-2.5 space-y-1 border-t border-gray-100 pt-2 dark:border-gray-800">
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

    {{-- TASK-1802 (demande Cyril) — la reponse RSVP (« Je participe » /
         « Peut-être » / « Je ne participe pas ») vit desormais dans le menu
         ⋮ de la tuile presences, ci-dessus : plus de ligne de 3 boutons ni
         de texte « Votre reponse » ici, gain de place demande. --}}

    @if($interactive)
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
