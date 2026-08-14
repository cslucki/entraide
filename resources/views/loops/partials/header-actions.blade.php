{{--
    Les actions de la Boucle, sur une seule ligne.

    Deux familles, désormais séparées :

    — **Ouvrir quelque chose** : le Manifeste et le Résumé IA. Ce sont des
      Cards ; elles ouvrent un panneau sans quitter la conversation, et
      gardent leur bouton propre avec leur état actif.

    — **Gérer cette Boucle** : membres, outils, modification, archivage. Quatre
      gestes de même nature, qui menaient à quatre boutons alignés parmi les
      autres. Ils vivent maintenant sous un seul point d'entrée « Gérer ».

    Ce contrôle n'est pas un FAB : le « + » du rail gauche crée et agit, celui-ci
    règle **cette** Boucle.

    Le menu ne montre que ce que la personne peut réellement faire, avec les
    mêmes gardes qu'avant — aucune permission nouvelle. S'il ne reste aucun
    geste, il ne s'affiche pas du tout.
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

    @foreach($frame->reject(fn ($c) => $c['key'] === 'core.members') as $card)
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

    @php
        // Ce que cette personne peut réellement faire ici. Les quatre gardes
        // existaient déjà et sont calculées par le contrôleur ; on les lit,
        // on n'en invente aucune.
        $membresCard = $frame->firstWhere('key', 'core.members');
        $peutOutils = ($canCustomiseTools ?? false) && ($_org ?? null);
        $peutModifier = auth()->user()?->can('update', $currentLoop) ?? false;
        $peutArchiver = $canArchiveLoop ?? false;
        $aQuelqueChose = $membresCard || $peutOutils || $peutModifier || $peutArchiver;
    @endphp

    @if($aQuelqueChose)
        <div class="relative shrink-0" x-data="{ gerer: false }" x-on:keydown.escape.window="gerer = false">
            <button type="button" x-on:click="gerer = ! gerer" x-bind:aria-expanded="gerer"
                    class="{{ $shape }} {{ $quiet }} px-2.5"
                    title="{{ __('loops.manage_loop_title') }}">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M10.343 3.94c.09-.542.56-.94 1.11-.94h1.093c.55 0 1.02.398 1.11.94l.149.894c.07.424.384.764.78.93.398.164.855.142 1.205-.108l.737-.527a1.125 1.125 0 0 1 1.45.12l.773.774c.39.389.44 1.002.12 1.45l-.527.737c-.25.35-.272.806-.107 1.204.165.397.505.71.93.78l.893.15c.543.09.94.559.94 1.109v1.094c0 .55-.397 1.02-.94 1.11l-.894.149c-.424.07-.764.383-.929.78-.165.398-.143.854.107 1.204l.527.738c.32.447.269 1.06-.12 1.45l-.774.773a1.125 1.125 0 0 1-1.449.12l-.738-.527c-.35-.25-.806-.272-1.203-.107-.397.165-.71.505-.781.929l-.149.894c-.09.542-.56.94-1.11.94h-1.094c-.55 0-1.019-.398-1.11-.94l-.148-.894c-.071-.424-.384-.764-.781-.93-.398-.164-.854-.142-1.204.108l-.738.527c-.447.32-1.06.269-1.45-.12l-.773-.774a1.125 1.125 0 0 1-.12-1.45l.527-.737c.25-.35.273-.806.108-1.204-.165-.397-.505-.71-.93-.78l-.894-.15c-.542-.09-.94-.56-.94-1.109v-1.094c0-.55.398-1.02.94-1.11l.894-.149c.425-.07.765-.383.93-.78.165-.398.143-.854-.108-1.204l-.526-.738a1.125 1.125 0 0 1 .12-1.45l.773-.773a1.125 1.125 0 0 1 1.45-.12l.737.527c.35.25.807.272 1.204.107.397-.165.71-.505.78-.929l.15-.894Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/></svg>
                <span class="hidden sm:inline">{{ __('loops.manage_loop') }}</span>
                <svg class="h-3 w-3 transition-transform" x-bind:class="gerer && 'rotate-180'" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5"/></svg>
            </button>

            <div x-show="gerer" x-cloak x-on:click.outside="gerer = false"
                 class="absolute right-0 top-full z-30 mt-1.5 w-60 rounded-2xl border border-[var(--bp-border)] bg-[var(--bp-panel)] p-1.5 shadow-lg">

                @if($membresCard)
                    {{-- Membres reste une Card : le menu n'est que son nouveau
                         point de départ, elle ouvre le même panneau. --}}
                    <button type="button" x-on:click="openCard(@js($membresCard['key'])); gerer = false"
                            class="flex w-full items-center gap-2.5 rounded-xl px-2.5 py-2.5 text-left text-sm font-semibold text-[var(--bp-text)] transition hover:bg-[var(--bp-primary)]/10">
                        <svg class="h-4 w-4 shrink-0 text-[var(--bp-muted)]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.094 9.094 0 0 0 3.741-.479 3 3 0 0 0-4.682-2.72m.94 3.198.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0 1 12 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 0 1 6 18.719m12 0a5.971 5.971 0 0 0-.941-3.197m0 0A5.995 5.995 0 0 0 12 12.75a5.995 5.995 0 0 0-5.058 2.772m0 0a3 3 0 0 0-4.681 2.72 8.986 8.986 0 0 0 3.74.477m.94-3.197a5.971 5.971 0 0 0-.94 3.197M15 6.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm6 3a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Zm-13.5 0a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Z"/></svg>
                        {{ __($membresCard['label_key']) }}
                    </button>
                @endif

                @if($peutOutils)
                    <a href="{{ route('organization.loops.tools', ['organization' => $_org, 'loop' => $currentLoop->id]) }}"
                       class="flex w-full items-center gap-2.5 rounded-xl px-2.5 py-2.5 text-left text-sm font-semibold text-[var(--bp-text)] transition hover:bg-[var(--bp-primary)]/10">
                        <svg class="h-4 w-4 shrink-0 text-[var(--bp-muted)]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M11.42 15.17 17.25 21A2.652 2.652 0 0 0 21 17.25l-5.877-5.877M11.42 15.17l2.496-3.03c.317-.384.74-.626 1.208-.766M11.42 15.17l-4.655 5.653a2.548 2.548 0 1 1-3.586-3.586l6.837-5.63m5.108-.233c.55-.164 1.163-.188 1.743-.14a4.5 4.5 0 0 0 4.486-6.336l-3.276 3.277a3.004 3.004 0 0 1-2.25-2.25l3.276-3.276a4.5 4.5 0 0 0-6.336 4.486c.091 1.076-.071 2.264-.904 2.95l-.102.085m-1.745 1.437L5.909 7.5H4.5L2.25 3.75l1.5-1.5L7.5 4.5v1.409l4.26 4.26m-1.745 1.437 1.745-1.437m6.615 8.206L15.75 15.75M4.867 19.125h.008v.008h-.008v-.008Z"/></svg>
                        {{ __('loops.owner_tools_action') }}
                    </a>
                @endif

                @if($peutModifier)
                    <a href="{{ $_loopRoute('edit', ['loop' => $currentLoop]) }}"
                       class="flex w-full items-center gap-2.5 rounded-xl px-2.5 py-2.5 text-left text-sm font-semibold text-[var(--bp-text)] transition hover:bg-[var(--bp-primary)]/10">
                        <svg class="h-4 w-4 shrink-0 text-[var(--bp-muted)]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487a2.1 2.1 0 1 1 2.97 2.97L8.63 18.66l-4.243.53.53-4.243L16.862 4.487Z"/></svg>
                        {{ __('loops.edit') }}
                    </a>
                @endif

                @if($peutArchiver)
                    {{-- Séparé : c'est le seul geste du menu qui change l'état
                         de la Boucle pour tout le monde. --}}
                    <div class="my-1 border-t border-[var(--bp-border)]"></div>
                    <button type="button" x-on:click="$dispatch('open-loop-archive'); gerer = false"
                            class="flex w-full items-center gap-2.5 rounded-xl px-2.5 py-2.5 text-left text-sm font-semibold text-amber-700 transition hover:bg-amber-50 dark:text-amber-300 dark:hover:bg-amber-900/25">
                        <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="m20.25 7.5-.625 10.632a2.25 2.25 0 0 1-2.247 2.118H6.622a2.25 2.25 0 0 1-2.247-2.118L3.75 7.5M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125Z"/></svg>
                        {{ $currentLoop->isArchived() ? __('loops.reactivate_action') : __('loops.archive_action') }}
                    </button>
                @endif
            </div>
        </div>
    @endif

</div>
