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

        {{-- TASK-1739 — « Notifications sur cet appareil ».

             Placé AVANT les préférences par événement, et c'est voulu : sans
             autorisation sur l'appareil, aucun réglage par événement ne peut
             produire de notification Push. L'ordre de l'écran suit l'ordre
             des conditions.

             Hors du formulaire des préférences : un abonnement est un état
             d'appareil, activer EST le geste, il n'y a rien à « enregistrer ».
        --}}
        @php
            // TASK-1744 — combien d'événements cette personne a-t-elle
            // réellement ouverts au Push ? Le panneau d'appareil en a besoin
            // pour dire la vérité plutôt qu'une promesse : un appareil
            // enregistré sans aucune case cochée ne reçoit RIEN, et c'est ce
            // silence-là qui a fait échouer la première recette.
            //
            // Compté ici et non dans le contrôleur : `$etat` est déjà
            // l'autorité de cet écran, et le partiel n'est inclus que d'ici.
            $pushActifs = collect($etat)
                ->filter(fn (array $canaux) => ($canaux[\App\Support\Notifications\NotificationCatalogue::CHANNEL_WEB_PUSH]['enabled'] ?? false) === true)
                ->count();
        @endphp

        @include('notifications.partials.push-device', ['pushActifs' => $pushActifs])

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
                    <li data-preference-key="{{ $cle }}"
                        class="rounded-xl border border-[var(--bp-border)] bg-[var(--bp-panel)] px-4 py-3 shadow-sm">
                        <p class="text-sm font-semibold text-[var(--bp-text)]">{{ $libelleCle($cle) }}</p>

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

    {{-- TASK-1739 — chargé sur CETTE page seulement : `pushManager` et la
         demande de permission n'ont rien à peser ailleurs. --}}
    @push('scripts')
        @vite('resources/js/push-device.js')
    @endpush
</x-app-layout>
