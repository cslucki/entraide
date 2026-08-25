{{--
    Le refus d'acces a un Dossier — rendu avec le statut 403.

    Cette vue ne recoit JAMAIS le Dossier refuse : ni son modele, ni son nom,
    ni son identifiant. Elle ne sait pas ce qui a ete demande, et ne peut donc
    rien en dire. C'est la garantie la plus simple contre la divulgation :
    l'absence de donnee, pas la discipline d'affichage.

    Le texte ne confirme pas non plus la CAUSE du refus. « Votre acces a
    peut-etre ete retire » couvre autant un partage revoque qu'une ressource
    indisponible : dire « m3 vous a retire l'acces » apprendrait a la fois que
    le Dossier existe encore et que quelqu'un a agi dessus.

    La sortie est offerte, jamais la demande d'acces : rien ici ne contacte le
    proprietaire ni ne suggere qu'il existe.
--}}
<x-app-layout>
    <x-page-container>
        <x-dossiers.module espace="documents">
            <div class="flex min-h-[60vh] w-full items-center justify-center px-4 py-12">
                <div class="w-full max-w-lg text-center">
                    <span class="mx-auto mb-6 flex h-14 w-14 items-center justify-center rounded-2xl bg-amber-50 text-amber-600 dark:bg-amber-950/40 dark:text-amber-300"
                          aria-hidden="true">
                        <svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="4" y="10.5" width="16" height="10" rx="2"/>
                            <path d="M8 10.5V7.5a4 4 0 0 1 8 0v3"/>
                        </svg>
                    </span>

                    <h1 class="text-xl font-semibold tracking-tight text-[var(--bp-text)] sm:text-2xl">
                        {{ __('dossiers.access_denied_title') }}
                    </h1>

                    <p class="mt-3 text-sm leading-relaxed text-[var(--bp-text)] sm:text-base">
                        {{ __('dossiers.access_denied_message') }}
                    </p>

                    <p class="mt-2 text-sm leading-relaxed text-[var(--bp-muted)]">
                        {{ __('dossiers.access_denied_hint') }}
                    </p>

                    {{-- Deux sorties, pas une impasse : la sienne, et celle ou
                         vivent les Dossiers qu'on lui a reellement confies. --}}
                    <div class="mt-8 flex flex-col items-stretch gap-3 sm:flex-row sm:justify-center">
                        <a href="{{ route('organization.dossiers.index', ['organization' => $organizationRouteParam]) }}"
                           class="inline-flex min-h-11 items-center justify-center gap-2 whitespace-nowrap rounded-xl bg-indigo-600 px-5 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-700">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                            </svg>
                            {{ __('dossiers.access_denied_back') }}
                        </a>

                        <a href="{{ route('organization.dossiers.index', ['organization' => $organizationRouteParam, 'espace' => 'partages', 'vue' => 'avec-moi']) }}"
                           class="inline-flex min-h-11 items-center justify-center gap-2 whitespace-nowrap rounded-xl border border-[var(--bp-border)] bg-[var(--bp-panel)] px-5 text-sm font-semibold text-[var(--bp-text)] shadow-sm transition hover:bg-[var(--bp-surface)]">
                            <svg class="h-4 w-4 text-indigo-600 dark:text-indigo-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M12 3.75v12m0 0 4.5-4.5M12 15.75l-4.5-4.5M4.5 19.5h15"/>
                            </svg>
                            {{ __('dossiers.access_denied_shared') }}
                        </a>
                    </div>
                </div>
            </div>
        </x-dossiers.module>
    </x-page-container>
</x-app-layout>
