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

        @if(session('notification_preferences_saved'))
            <div data-preferences-saved
                 class="mb-5 rounded-xl border border-[var(--bp-border)] bg-[var(--bp-panel)] px-4 py-3 text-sm text-[var(--bp-muted)]">
                {{ session('notification_preferences_saved') }}
            </div>
        @endif

        <form method="POST" action="{{ route($routeUpdate, $routeParams) }}">
            @csrf

            <ul class="space-y-2">
                @foreach($etat as $cle => $canaux)
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
