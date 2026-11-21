{{--
    TASK-1878 — console « Diagnostic PWA ».

    Quatre sections. Installation, Service Worker et Partage sont MESURES par
    `pwa-diagnostic.js` ; Notifications reprend TEL QUEL le bloc de T1877
    (meme partiel, meme script, memes routes) : aucun second moteur
    d'abonnement. Les verifications que le web ne peut pas faire (menu
    Partager d'Android, reception reelle) sont une section A PART, remplie a
    la main.

    Rien ici n'ecrit : ni session, ni Service Worker, ni cache.
--}}
@php
    $d = 'pwa.diagnostic.';
    $sections = [
        'installation' => ['inst_origin', 'inst_secure', 'inst_standalone', 'inst_manifest_link', 'inst_manifest_valid', 'inst_name', 'inst_support'],
        'service_worker' => ['sw_registration', 'sw_controller', 'sw_scope', 'sw_version', 'sw_waiting'],
        'notifications' => ['push_permission', 'push_subscription', 'push_server', 'push_key', 'push_events', 'push_local', 'push_simulate', 'push_real'],
        'sharing' => ['share_standalone', 'share_declared', 'share_params', 'share_server', 'share_route', 'share_simulation', 'share_pending', 'share_native'],
    ];
@endphp
<x-app-layout>
    <x-slot name="title">{{ __($d.'title') }}</x-slot>

    <x-page-container>
        <div data-pwa-diagnostic
             data-verification-url="{{ route($routeVerification, $routeParams) }}"
             data-manifest-path="{{ $manifestePath }}"
             data-expected-name="{{ $nomAttendu }}"
             data-expected-sw="{{ $swAttendu }}"
             data-app-version="{{ $versionApplication }}"
             data-environment="{{ app()->environment() }}"
             data-push-events="{{ $pushActifs }}"
             class="space-y-6">

            <div>
                <h1 class="text-3xl font-bold text-[var(--bp-text)]">{{ __($d.'title') }}</h1>
                <a href="{{ route($routeDevice, $routeParams) }}"
                   data-pwa-diagnostic-back
                   class="mt-2 inline-flex min-h-11 items-center text-sm font-semibold text-[var(--bp-primary)] transition hover:underline">
                    {{ __($d.'back_device') }}
                </a>
                <p class="mt-2 text-sm text-[var(--bp-muted)]">{{ __($d.'intro') }}</p>
                <p data-pwa-diagnostic-limits class="mt-3 rounded-lg border border-amber-300 bg-amber-50 p-3 text-xs text-amber-900 dark:border-amber-700 dark:bg-amber-950/40 dark:text-amber-100">
                    {{ __($d.'limits') }}
                </p>
            </div>

            {{-- Le rapport : en tete, parce que c'est ce qu'on envoie. --}}
            <div>
                <button type="button" data-pwa-report-copy
                        class="inline-flex min-h-11 w-full items-center justify-center rounded-lg bg-[var(--bp-primary)] px-4 text-sm font-semibold text-white sm:w-auto">
                    {{ __($d.'report.copy') }}
                </button>
                <p data-pwa-report-status role="status" hidden class="mt-2 text-sm font-medium"></p>
                <textarea data-pwa-report-text readonly hidden rows="12" aria-label="{{ __($d.'report.title') }}"
                          class="mt-2 w-full rounded-lg border border-[var(--bp-border)] bg-[var(--bp-surface)] p-2 font-mono text-[11px] text-[var(--bp-text)]"></textarea>
            </div>

            <h2 class="text-lg font-semibold text-[var(--bp-text)]">{{ __($d.'auto_heading') }}</h2>

            @foreach($sections as $section => $lignes)
                <section data-diag-section="{{ $section }}" class="rounded-xl border border-[var(--bp-border)] bg-[var(--bp-surface)] p-4">
                    <h3 class="text-base font-semibold text-[var(--bp-text)]">{{ __($d.'sections.'.$section) }}</h3>

                    <ul class="mt-3 divide-y divide-[var(--bp-border)]">
                        @foreach($lignes as $cle)
                            <li data-diag-row="{{ $cle }}" data-diag-status="untested" class="py-2">
                                <div class="flex items-start justify-between gap-3">
                                    <span class="min-w-0 text-sm text-[var(--bp-text)]">{{ __($d.'rows.'.$cle) }}</span>
                                    <span data-diag-badge class="shrink-0 rounded px-2 py-0.5 text-[11px] font-bold tracking-wide">{{ __($d.'status.untested') }}</span>
                                </div>
                                <p data-diag-value class="mt-0.5 break-all font-mono text-[11px] text-[var(--bp-muted)]">{{ __($d.'measuring') }}</p>
                                <p data-diag-help hidden class="mt-1 text-xs text-red-800 dark:text-red-200"></p>
                            </li>
                        @endforeach
                    </ul>

                    @if($section === 'notifications')
                        {{-- Les actions existantes de T1877, incluses telles quelles. --}}
                        <div class="mt-4 border-t border-[var(--bp-border)] pt-4">
                            @include('notifications.partials.push-device', [
                                'pushActifs' => $pushActifs,
                                'routeTest' => $routeTest,
                                'routePreferences' => $routePreferences,
                                'diagOuvert' => true,
                            ])
                        </div>
                    @endif
                </section>
            @endforeach

            <h2 class="text-lg font-semibold text-[var(--bp-text)]">{{ __($d.'manual_heading') }}</h2>

            <section data-diag-manual class="rounded-xl border border-[var(--bp-border)] bg-[var(--bp-surface)] p-4">
                <p class="text-sm text-[var(--bp-muted)]">{{ __($d.'manual.intro') }}</p>

                <h3 class="mt-4 text-base font-semibold text-[var(--bp-text)]">{{ __($d.'manual.steps_heading') }}</h3>
                <ol class="mt-2 list-decimal space-y-1 pl-5 text-sm text-[var(--bp-text)]">
                    @foreach(__($d.'manual.steps') as $etape)
                        <li>{{ $etape }}</li>
                    @endforeach
                </ol>
                {{-- `show()` affiche, il ne publie pas ; il ne touche pas un
                     partage valide en attente. --}}
                <a href="{{ route('partage.choisir') }}" data-diag-open-choose
                   class="mt-3 inline-flex min-h-11 items-center text-sm font-semibold text-[var(--bp-primary)] hover:underline">
                    {{ __($d.'manual.open_choose') }}
                </a>

                <ul class="mt-4 divide-y divide-[var(--bp-border)]">
                    @foreach(__($d.'manual.questions') as $cle => $question)
                        <li data-diag-row="{{ $cle }}" data-diag-manual-question data-diag-status="untested" class="py-3">
                            <div class="flex items-start justify-between gap-3">
                                <span class="min-w-0 text-sm text-[var(--bp-text)]">{{ $question }}</span>
                                <span data-diag-badge class="shrink-0 rounded px-2 py-0.5 text-[11px] font-bold tracking-wide">{{ __($d.'status.untested') }}</span>
                            </div>
                            <div class="mt-2 flex flex-wrap gap-2" role="radiogroup" aria-label="{{ $question }}">
                                @foreach(['yes' => 'answer_yes', 'no' => 'answer_no', 'untested' => 'answer_untested'] as $valeur => $libelle)
                                    <label class="inline-flex min-h-11 cursor-pointer items-center gap-2 rounded-lg border border-[var(--bp-border)] px-3 text-sm text-[var(--bp-text)]">
                                        <input type="radio" name="diag-{{ $cle }}" value="{{ $valeur }}" @checked($valeur === 'untested')>
                                        {{ __($d.'manual.'.$libelle) }}
                                    </label>
                                @endforeach
                            </div>
                            <p data-diag-help hidden class="mt-2 text-xs text-red-800 dark:text-red-200"></p>
                        </li>
                    @endforeach
                </ul>
            </section>

            {{-- Couleur par statut : pilotee par `data-diag-status`, que le
                 script pose. Pas de classe Tailwind posee en JS — le scanner
                 ne lit pas `resources/js` et la purgerait. --}}
            <style>
                [data-diag-row] [data-diag-badge] { background: rgba(107, 114, 128, .18); color: var(--bp-text); }
                [data-diag-row][data-diag-status="ok"] [data-diag-badge] { background: rgba(16, 185, 129, .22); }
                [data-diag-row][data-diag-status="fail"] [data-diag-badge] { background: rgba(239, 68, 68, .25); }
                [data-diag-row][data-diag-status="unverifiable"] [data-diag-badge] { background: rgba(245, 158, 11, .25); }
                [data-diag-row][data-diag-status="ok"] [data-diag-help],
                [data-diag-row][data-diag-status="untested"] [data-diag-help] { color: var(--bp-muted); }
            </style>

            {{-- Tous les textes du script, traduits cote serveur. --}}
            <script type="application/json" data-pwa-diagnostic-texts>@json(__('pwa.diagnostic'))</script>
        </div>
    </x-page-container>

    @push('scripts')
        @vite(['resources/js/push-device.js', 'resources/js/pwa-diagnostic.js'])
    @endpush
</x-app-layout>
