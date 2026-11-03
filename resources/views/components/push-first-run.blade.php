{{--
    TASK-1745 (demande Cyril, 03/11) — « Activer les notifications », au
    premier lancement de la PWA installée.

    ## Pourquoi ici et pas ailleurs

    Quelqu'un qui vient d'installer BouclePro ouvre l'application et ne voit
    rien de neuf. Les notifications existent (T1739/T1744), mais il faut
    savoir aller dans les réglages pour les activer — c'est exactement le
    cul-de-sac qui a coûté deux recettes le 02/11.

    ## Ce que ce bloc n'est PAS

    Ni une page, ni une étape obligatoire, ni un moteur. Il appelle la même
    mécanique que le panneau des réglages (`push-abonnement.js`, extrait de
    T1739 pour avoir deux appelants au lieu d'une copie), et le serveur ne
    voit aucune route nouvelle.

    **La navigation n'est jamais bloquée** : c'est une barre en bas, pas une
    superposition modale. Rien n'est inatteignable pendant qu'elle est là.

    ## Quatre conditions, et toutes doivent tenir

    Deux sont posées ICI, côté serveur — la capacité doit exister, et la
    personne doit être connectée. Deux sont posées par le script, parce
    qu'elles ne se lisent que dans le navigateur : l'application doit être
    LANCÉE INSTALLÉE (`display-mode: standalone`), et l'appareil ne doit pas
    être déjà abonné.

    Le `display-mode` est la condition qui donne son nom à ce bloc : dans un
    onglet ordinaire, la personne n'a pas « lancé l'application », et la
    proposition n'aurait pas de sens.

    ## Le refus est RESPECTÉ, et durablement

    « Plus tard » pose un renoncement dans `localStorage` — pas un cookie :
    c'est un état d'appareil, comme l'abonnement lui-même, et il n'a aucune
    raison de voyager dans chaque requête. Tant qu'il est là, ce bloc ne
    reparaît pas.
--}}
@auth
    @if($annonceLesNotifications ?? false)
        <div data-push-first-run
             data-push-url="{{ $urlPush }}"
             data-push-vapid="{{ config('webpush.vapid.public_key') }}"
             data-push-text-failed="{{ __('push.first_run_failed') }}"
             hidden style="display: none"
             class="fixed inset-x-0 bottom-0 z-[60] mx-auto w-full max-w-xl p-3">
            <div class="rounded-xl border border-[var(--bp-border)] bg-[var(--bp-panel)] p-4 shadow-lg">
                <p class="text-sm font-semibold text-[var(--bp-text)]">{{ __('push.first_run_title') }}</p>
                <p class="mt-1 text-xs text-[var(--bp-muted)]">{{ __('push.first_run_body') }}</p>

                <div class="mt-3 flex flex-wrap items-center gap-2">
                    <button type="button" data-push-first-run-accept
                            class="inline-flex min-h-11 items-center rounded-xl bg-indigo-600 px-4 text-sm font-semibold text-white transition hover:bg-indigo-700 disabled:opacity-60">
                        {{ __('push.first_run_accept') }}
                    </button>

                    {{-- « Plus tard », et c'est un vrai refus : il est
                         respecté, et il ne revient pas demander. --}}
                    <button type="button" data-push-first-run-later
                            class="inline-flex min-h-11 items-center rounded-xl px-4 text-sm font-semibold text-[var(--bp-muted)] transition hover:bg-[var(--bp-hover)]">
                        {{ __('push.first_run_later') }}
                    </button>
                </div>

                <p data-push-first-run-help hidden style="display: none"
                   class="mt-2 text-xs text-[var(--bp-muted)]"></p>
            </div>
        </div>

        @push('scripts')
            @vite('resources/js/push-first-run.js')
        @endpush
    @endif
@endauth
