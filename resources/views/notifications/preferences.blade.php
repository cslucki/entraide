{{--
    TASK-1375 — les reglages de notification.

    L'ecran n'invente aucun reglage : il affiche ce que le catalogue declare, et
    rien d'autre. Un canal OBLIGATOIRE s'y montre sans bouton plutot que de
    disparaitre — le membre a le droit de savoir ce qui lui sera envoye, meme
    quand il ne peut pas le changer.
--}}
@php
    $libelleCle = function (string $cle) {
        $t = 'notifications.keys.'.str_replace('.', '_', $cle);

        return \Illuminate\Support\Facades\Lang::has($t) ? __($t) : __('notifications.key_fallback');
    };
    $libelleCanal = function (string $canal) {
        $t = 'notifications.channel_'.$canal;

        return \Illuminate\Support\Facades\Lang::has($t) ? __($t) : $canal;
    };
@endphp

<x-app-layout>
    <x-slot name="title">{{ __('notifications.preferences_title') }}</x-slot>

    <x-page-container>
        <div class="mb-6">
            <h1 class="text-3xl font-bold text-[var(--bp-text)]">{{ __('notifications.preferences_title') }}</h1>
            <p class="mt-1 text-sm text-[var(--bp-muted)]">{{ __('notifications.preferences_subtitle') }}</p>
            <a href="{{ route($routeCentre, $routeParams) }}"
               class="mt-2 inline-flex min-h-11 items-center text-sm font-semibold text-[var(--bp-primary)] transition hover:underline">
                {{ __('notifications.preferences_back') }}
            </a>
        </div>

        {{-- TASK-1775 (CDC B1) — « Mes Boucles », EN PREMIER sur cet écran.

             Avant les réglages par événement et avant le panneau « cet
             appareil » : « de quelle Boucle je veux entendre parler » est la
             première question qu'on vient régler ici, et c'est la seule
             section qui nomme explicitement un contexte personnel — la liste
             des Boucles dont on est membre ACTIF, dans SON Organization. --}}
        <div class="mb-6">
            <h2 class="text-sm font-semibold text-[var(--bp-text)]">{{ __('notifications.loops.title') }}</h2>
            <p class="mt-0.5 text-xs text-[var(--bp-muted)]">{{ __('notifications.loops.hint') }}</p>

            @if(empty($mesBoucles))
                {{-- CDC B2 — message EXACT, et un chemin vers l'annuaire des
                     Boucles plutôt qu'un nouvel écran. --}}
                <div data-loops-empty class="mt-3 rounded-xl border border-[var(--bp-border)] bg-[var(--bp-panel)] px-4 py-3">
                    <p class="text-sm text-[var(--bp-muted)]">{{ __('notifications.loops.empty_message') }}</p>
                    <a href="{{ route($routeLoopsIndex, $routeParams) }}"
                       data-loops-join-cta
                       class="mt-3 inline-flex min-h-11 items-center gap-2 rounded-xl bg-indigo-600 px-5 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-700">
                        {{ __('notifications.loops.join_cta') }}
                    </a>
                </div>
            @else
                <form method="POST" action="{{ route($routeLoopsUpdate, $routeParams) }}" class="mt-3 space-y-3">
                    @csrf

                    <ul class="space-y-3">
                        @foreach($mesBoucles as $boucle)
                            {{-- TASK-1877 (demande Cyril) — carte PLIEE par defaut : la
                                 page liste toutes les Boucles et tous les evenements,
                                 depliee elle s'etalait sur plusieurs ecrans. `<details>`
                                 natif, sans script ; les cases repliees restent dans le
                                 formulaire et sont bien soumises. --}}
                            <li data-loop-preference="{{ $boucle['id'] }}"
                                class="rounded-xl border border-[var(--bp-border)] bg-[var(--bp-panel)] px-4 py-1 shadow-sm">
                                <details data-preference-card class="group open:pb-2">
                                <summary class="flex min-h-11 cursor-pointer list-none items-center justify-between gap-3 text-sm font-semibold text-[var(--bp-text)] [&::-webkit-details-marker]:hidden">
                                    <span>{{ $boucle['nom'] }}</span>
                                    <svg aria-hidden="true" class="h-4 w-4 shrink-0 text-[var(--bp-muted)] transition-transform group-open:rotate-180" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                                </summary>

                                <ul class="mt-2 space-y-2">
                                    {{-- TASK-1775 (arbitrage Cyril, 07/11) — l'in-app
                                         est redevenu OBLIGATOIRE : plus de case a
                                         cocher, le meme badge verrouille que les
                                         cles personnelles du reste de l'ecran. --}}
                                    <li data-loop-channel="{{ $boucle['id'] }}:collective"
                                        class="flex flex-wrap items-center justify-between gap-3">
                                        <span class="text-sm text-[var(--bp-muted)]">{{ __('notifications.loops.master_label') }}</span>
                                        <span data-preference-locked
                                              title="{{ __('notifications.preferences_mandatory_hint') }}"
                                              class="rounded-full bg-[var(--bp-border)] px-2.5 py-0.5 text-[11px] font-bold text-[var(--bp-muted)]">
                                            {{ __('notifications.preferences_mandatory') }}
                                        </span>
                                    </li>

                                    @foreach([
                                        ['email', __('notifications.channel_email'), $boucle['email_enabled']],
                                        ['web_push', __('notifications.channel_web_push'), $boucle['web_push_enabled']],
                                    ] as [$levier, $libelle, $actif])
                                        <li data-loop-channel="{{ $boucle['id'] }}:{{ $levier }}"
                                            class="flex flex-wrap items-center justify-between gap-3">
                                            <span class="text-sm text-[var(--bp-muted)]">{{ $libelle }}</span>
                                            <label class="inline-flex min-h-11 items-center gap-2">
                                                {{-- Meme garde qu'ailleurs sur cet ecran : le champ
                                                     cache garantit qu'une case decochee est bien
                                                     transmise comme « non », jamais comme une absence. --}}
                                                <input type="hidden" name="boucles[{{ $boucle['id'] }}][{{ $levier }}]" value="0">
                                                <input type="checkbox"
                                                       name="boucles[{{ $boucle['id'] }}][{{ $levier }}]"
                                                       value="1"
                                                       @checked($actif)
                                                       class="h-5 w-5 rounded border-[var(--bp-border)] text-[var(--bp-primary)]">
                                            </label>
                                        </li>
                                    @endforeach
                                </ul>
                                </details>
                            </li>
                        @endforeach
                    </ul>

                    <button type="submit"
                            data-loops-save
                            class="inline-flex min-h-11 items-center gap-2 rounded-xl bg-indigo-600 px-5 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-700">
                        {{ __('notifications.preferences_save') }}
                    </button>
                </form>
            @endif
        </div>

        {{-- TASK-1877 — « Notifications sur cet appareil » vit desormais sur
             sa propre page (T1739 l'avait pose ici). Il reste un BOUTON, a la
             meme place : sans autorisation sur l'appareil, aucun reglage
             « Sur mes appareils » ci-dessous ne produit de notification, et
             l'ordre de l'ecran continue de suivre l'ordre des conditions. --}}
        <div class="mb-8">
            <a href="{{ route($routeDevice, $routeParams) }}"
               data-push-device-link
               class="inline-flex min-h-11 items-center gap-2 rounded-xl border border-[var(--bp-border)] bg-[var(--bp-panel)] px-5 text-sm font-semibold text-[var(--bp-text)] transition hover:bg-[var(--bp-hover)]">
                {{ __('push.device_settings_button') }}
                <span aria-hidden="true">→</span>
            </a>
        </div>

        @if(session('notification_preferences_saved'))
            <div data-preferences-saved
                 class="mb-5 rounded-xl border border-[var(--bp-border)] bg-[var(--bp-panel)] px-4 py-3 text-sm text-[var(--bp-muted)]">
                {{ session('notification_preferences_saved') }}
            </div>
        @endif

        <form method="POST" action="{{ route($routeUpdate, $routeParams) }}">
            @csrf

            {{-- TASK-1744 — cible du lien « Aller aux réglages par
                 événement » du panneau d'appareil. Sans elle, l'aide renvoie
                 à un « ci-dessous » qui, sur un téléphone, est deux écrans
                 plus bas. --}}
            {{-- TASK-1746 (CDC §7A) — REGROUPE par intention.

                 Six evenements a plat, chacun avec jusqu'a trois canaux :
                 le §7 redoute « des dizaines d'interrupteurs », et chaque
                 producteur du §8 aggravera le compte. Les sections repondent
                 a « qu'est-ce que cela me demande ? », pas a « de quel objet
                 technique cela parle ».

                 Le regroupement ajoute un niveau AUTOUR : `data-preference-key`
                 et `data-preference-channel` sont intacts, et c'est
                 deliberé — le banc T1744 localise les lignes par ces ancres,
                 et les casser ferait rougir une TASK fusionnee pour une
                 raison cosmetique. --}}
            <div id="reglages-par-evenement" class="scroll-mt-4 space-y-6">
                @foreach($sections as $intention => $evenements)
                <section data-preference-intention="{{ $intention }}">
                    <h2 class="text-sm font-semibold text-[var(--bp-text)]">
                        {{ __('notifications.intentions.'.$intention.'.title') }}
                    </h2>
                    <p class="mt-0.5 text-xs text-[var(--bp-muted)]">
                        {{ __('notifications.intentions.'.$intention.'.hint') }}
                    </p>

                    <ul class="mt-2 space-y-2">
                @foreach($evenements as $cle => $canaux)
                    {{-- TASK-1877 — meme carte pliee que « Mes Boucles ». --}}
                    <li data-preference-key="{{ $cle }}"
                        class="rounded-xl border border-[var(--bp-border)] bg-[var(--bp-panel)] px-4 py-1 shadow-sm">
                        <details data-preference-card class="group open:pb-2">
                        <summary class="flex min-h-11 cursor-pointer list-none items-center justify-between gap-3 text-sm font-semibold text-[var(--bp-text)] [&::-webkit-details-marker]:hidden">
                            <span>{{ $libelleCle($cle) }}</span>
                            <svg aria-hidden="true" class="h-4 w-4 shrink-0 text-[var(--bp-muted)] transition-transform group-open:rotate-180" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                        </summary>

                        <ul class="mt-2 space-y-2">
                            @foreach($canaux as $canal => $reglage)
                                <li data-preference-channel="{{ $cle }}:{{ $canal }}"
                                    @if($reglage['configurable']) data-preference-configurable @else data-preference-mandatory @endif
                                    class="flex flex-wrap items-center justify-between gap-3">
                                    <span class="text-sm text-[var(--bp-muted)]">{{ $libelleCanal($canal) }}</span>

                                    @if($reglage['configurable'])
                                        <label class="inline-flex min-h-11 items-center gap-2">
                                            {{-- Le champ cache garantit qu'un canal decoche est bien
                                                 transmis comme « non » : sans lui, decocher revient a
                                                 ne rien envoyer, donc a ne rien changer. --}}
                                            <input type="hidden" name="canaux[{{ $cle }}][{{ $canal }}]" value="0">
                                            <input type="checkbox"
                                                   name="canaux[{{ $cle }}][{{ $canal }}]"
                                                   value="1"
                                                   @checked($reglage['enabled'])
                                                   class="h-5 w-5 rounded border-[var(--bp-border)] text-[var(--bp-primary)]">
                                        </label>
                                    @else
                                        <span data-preference-locked="{{ $cle }}:{{ $canal }}"
                                              title="{{ __('notifications.preferences_mandatory_hint') }}"
                                              class="rounded-full bg-[var(--bp-border)] px-2.5 py-0.5 text-[11px] font-bold text-[var(--bp-muted)]">
                                            {{ __('notifications.preferences_mandatory') }}
                                        </span>
                                    @endif
                                </li>
                            @endforeach
                        </ul>

                        @if(collect($canaux)->every(fn (array $r) => ! $r['configurable']))
                            <p class="mt-2 text-xs text-[var(--bp-muted)]">
                                {{ __('notifications.preferences_mandatory_hint') }}
                            </p>
                        @endif
                        </details>
                    </li>
                @endforeach
                    </ul>
                </section>
                @endforeach
            </div>

            @php $ilYADuConfigurable = collect($etat)->flatten(1)->contains(fn (array $r) => $r['configurable']); @endphp

            @if($ilYADuConfigurable)
                <div class="mt-6">
                    <button type="submit"
                            data-preferences-save
                            class="inline-flex min-h-11 items-center gap-2 rounded-xl bg-indigo-600 px-5 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-700">
                        {{ __('notifications.preferences_save') }}
                    </button>
                </div>
            @endif
        </form>
    </x-page-container>
</x-app-layout>
