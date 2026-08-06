{{--
    Les actions de la Boucle, sur une seule ligne.

    Cinq gestes de même nature — ouvrir le Manifeste, voir les membres, demander
    un résumé, modifier, archiver — donc cinq boutons de même forme, groupés à
    droite du titre. Les répartir sur deux lignes coûtait une ligne entière de
    hauteur pour rien.

    Une seule taille, une seule géométrie, un seul comportement au survol : ce
    qui distingue ces boutons, c'est leur icône et leur teinte, pas leur forme.
    Les Cards du cadre permanent portent leur état actif ; les deux actions de
    droite n'en ont pas.
--}}
@php
    $frame = collect($frameCards ?? []);
    $chatActions = collect($chatActionCards ?? []);

    // La même géométrie pour les cinq. Écrite une fois : cinq copies auraient
    // divergé au premier ajustement.
    $shape = 'inline-flex h-9 shrink-0 items-center justify-center gap-1.5 rounded-xl border text-xs font-semibold transition';
    $quiet = 'border-[var(--bp-border)] bg-[var(--bp-panel)] text-[var(--bp-muted)] hover:text-[var(--bp-text)] hover:border-[var(--bp-primary)]/40';
@endphp

<div class="flex shrink-0 items-center gap-1.5">

    @foreach($frame as $card)
        @if($card['key'] === 'core.members')
            {{-- Membres : les visages plutôt qu'un mot. Savoir qui est là se lit
                 d'un coup d'œil, la liste est à un clic. --}}
            <button type="button"
                    x-on:click="openCard(@js($card['key']))"
                    x-bind:aria-pressed="activeCard === @js($card['key'])"
                    x-bind:class="activeCard === @js($card['key'])
                        ? 'border-[var(--bp-primary)] bg-[var(--bp-primary)]/10 text-[var(--bp-text)]'
                        : '{{ $quiet }}'"
                    class="{{ $shape }} pl-1.5 pr-2.5"
                    title="{{ __($card['label_key']) }}"
                    aria-label="{{ __($card['label_key']) }}">
                {{-- Les vrais visages, pas des initiales : trois avatars en
                     pile disent « qui est là » sans qu'on ait à lire. --}}
                <span class="flex -space-x-2">
                    @foreach($loopMembers->take(3) as $frameMember)
                        <img src="{{ $frameMember->user?->avatar_url }}"
                             alt=""
                             aria-hidden="true"
                             class="h-6 w-6 rounded-full object-cover ring-2 ring-[var(--bp-panel)]">
                    @endforeach
                </span>
                <span class="tabular-nums">{{ $loopMembers->count() }}</span>
            </button>
        @else
            {{-- Manifeste, et tout autre élément du cadre. --}}
            <button type="button"
                    x-on:click="openCard(@js($card['key']))"
                    x-bind:aria-pressed="activeCard === @js($card['key'])"
                    x-bind:class="activeCard === @js($card['key'])
                        ? 'border-[var(--bp-primary)] bg-[var(--bp-primary)]/10 text-[var(--bp-text)]'
                        : '{{ $quiet }}'"
                    class="{{ $shape }} px-2.5"
                    title="{{ __($card['label_key']) }}">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-6a2.25 2.25 0 0 0-.659-1.591l-3-3A2.25 2.25 0 0 0 14.25 3H6.75A2.25 2.25 0 0 0 4.5 5.25v13.5A2.25 2.25 0 0 0 6.75 21h10.5a2.25 2.25 0 0 0 2.25-2.25v-4.5z"/><path stroke-linecap="round" stroke-linejoin="round" d="M14.25 3v4.5h4.5"/></svg>
                <span class="hidden sm:inline">{{ __($card['label_key']) }}</span>
            </button>
        @endif
    @endforeach

    @foreach($chatActions as $card)
        {{-- Le Résumé IA : une action de la conversation. Il garde la même
             forme que les autres, avec la teinte de l'IA. --}}
        <button type="button"
                x-on:click="openCard(@js($card['key']))"
                x-bind:aria-pressed="activeCard === @js($card['key'])"
                x-bind:class="activeCard === @js($card['key'])
                    ? 'border-[var(--bp-accent)] bg-[var(--bp-accent)]/15 text-[var(--bp-text)]'
                    : 'border-[var(--bp-accent)]/30 bg-[var(--bp-accent)]/5 text-[var(--bp-accent)] hover:bg-[var(--bp-accent)]/10'"
                class="{{ $shape }} px-2.5"
                title="{{ __($card['label_key']) }}">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 11.18 18.55a.75.75 0 0 0 1.38-.031l1.745-3.83a.75.75 0 0 1 .322-.36l3.746-2.25a.75.75 0 0 0 0-1.27l-3.746-2.25a.75.75 0 0 1-.322-.36L12.56 5.48a.75.75 0 0 0-1.38-.031l-1.367 2.647a.75.75 0 0 1-.5.369L4.88 9.373a.75.75 0 0 0 0 1.463l3.432.92a.75.75 0 0 1 .5.368z"/></svg>
            <span class="hidden lg:inline">{{ __($card['label_key']) }}</span>
        </button>
    @endforeach

    @can('update', $currentLoop)
        <a href="{{ $_loopRoute('edit', ['loop' => $currentLoop]) }}"
           class="{{ $shape }} {{ $quiet }} w-9"
           aria-label="{{ __('loops.edit') }}" title="{{ __('loops.edit') }}">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                <path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487a2.1 2.1 0 1 1 2.97 2.97L8.63 18.66l-4.243.53.53-4.243L16.862 4.487Z"/>
            </svg>
        </a>
    @endcan

    @if($canArchiveLoop ?? false)
        {{-- Archiver / réactiver. Hors du @can('update') : cette ability refuse
             une Boucle archivée, et la réactivation doit rester accessible à la
             personne qui l'a archivée. --}}
        <button type="button" x-on:click="$dispatch('open-loop-archive')"
                class="{{ $shape }} w-9 border-amber-300/60 bg-amber-50 text-amber-700 hover:bg-amber-100 dark:border-amber-800/60 dark:bg-amber-900/25 dark:text-amber-300 dark:hover:bg-amber-900/50"
                aria-label="{{ $currentLoop->isArchived() ? __('loops.reactivate_action') : __('loops.archive_action') }}"
                title="{{ $currentLoop->isArchived() ? __('loops.reactivate_action') : __('loops.archive_action') }}">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                <path stroke-linecap="round" stroke-linejoin="round" d="m20.25 7.5-.625 10.632a2.25 2.25 0 0 1-2.247 2.118H6.622a2.25 2.25 0 0 1-2.247-2.118L3.75 7.5M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125Z"/>
            </svg>
        </button>
    @endif
</div>
