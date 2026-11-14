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

    ## « Plus tard » est un REPORT daté, pas un renoncement éternel

    Recette du 03/11 (retour Cyril) : la première version posait un `1`
    définitif dans `localStorage` — un report devenait un refus permanent,
    qui survivait même à une désinstallation/réinstallation de la PWA
    (Android ne purge pas le stockage du site en désinstallant une WebAPK).
    La clé porte maintenant une ÉCHÉANCE : sept jours de silence, puis la
    proposition redevient possible. `localStorage` reste le bon étage — état
    d'appareil, comme l'abonnement, aucune raison de voyager en HTTP.

    ## Les trois états de permission sont TROIS parcours (demande Cyril 03/11)

    - `default` : le bouton d'autorisation — la demande système ne part
      qu'au clic (CDC 5.3) ;
    - `granted` : activation simple — l'autorisation est déjà là, aucun
      prompt ne repartira, le corps le dit ;
    - `denied` : une EXPLICATION de la réactivation, et aucun bouton
      d'activation — il ne pourrait qu'échouer (CDC 3.3).

    Et l'état n'est pas figé au chargement : Chrome Android 155+ permet
    d'accorder la permission après coup depuis les contrôles du site. Le
    script OBSERVE `navigator.permissions` et redécide au changement.

    ## Le script est émis ICI, jamais par @push — défaut payé le 03/11

    La première version faisait `@push('scripts')`. Or ce composant est
    inclus en FIN de layout, APRÈS la ligne `@stack('scripts')` : la pile
    était déjà rendue quand le composant y déposait son `@vite`. Résultat —
    le script n'était émis sur AUCUNE page, le bloc restait `display:none`
    à vie, et toute la recette « premier lancement » échouait en silence.
    Aucun test ne le voyait : ils mesuraient le balisage du bloc, pas
    l'émission du script. `@vite()` est donc appelé EN PLACE (il émet un
    `<script type="module">` différé, le DOM est complet à l'exécution),
    et `TASK1745PushFirstRunTest` garde désormais l'émission elle-même.

    Les libellés viennent de `lang/*/pwa.php` — la v1 lisait `push.*`, un
    espace de noms où ces clés n'existent pas : `__()` aurait rendu les
    clés brutes à l'écran. Le test de libellés dérive maintenant les clés
    DU GABARIT au lieu d'une liste recopiée, pour que vue et catalogue ne
    puissent plus diverger sans rougir.
--}}
@auth
    @if($annonceLesNotifications ?? false)
        <div data-push-first-run
             data-push-url="{{ $urlPush }}"
             data-push-vapid="{{ config('webpush.vapid.public_key') }}"
             data-push-text-failed="{{ __('pwa.first_run_failed') }}"
             data-push-text-granted-body="{{ __('pwa.first_run_granted_body') }}"
             data-push-text-denied-body="{{ __('pwa.first_run_denied_body') }}"
             data-push-text-understood="{{ __('pwa.first_run_understood') }}"
             {{-- TASK-1822 — l'abonnement existe mais sa clé VAPID n'a plus
                  cours : la permission n'est pas en cause, l'appareil ne
                  recevra simplement plus rien. On le dit, et on propose un
                  geste de RÉACTIVATION plutôt qu'une « activation » qui
                  laisserait croire que rien n'était fait. --}}
             data-push-text-stale-key-body="{{ __('pwa.first_run_stale_key_body') }}"
             data-push-text-stale-key-action="{{ __('pwa.first_run_stale_key_action') }}"
             hidden style="display: none"
             class="fixed inset-x-0 bottom-0 z-[60] mx-auto w-full max-w-xl p-3">
            <div class="rounded-xl border border-[var(--bp-border)] bg-[var(--bp-panel)] p-4 shadow-lg">
                <p class="text-sm font-semibold text-[var(--bp-text)]">{{ __('pwa.first_run_title') }}</p>
                <p data-push-first-run-body class="mt-1 text-xs text-[var(--bp-muted)]">{{ __('pwa.first_run_body') }}</p>

                <div class="mt-3 flex flex-wrap items-center gap-2">
                    <button type="button" data-push-first-run-accept
                            class="inline-flex min-h-11 items-center rounded-xl bg-indigo-600 px-4 text-sm font-semibold text-white transition hover:bg-indigo-700 disabled:opacity-60">
                        {{ __('pwa.first_run_accept') }}
                    </button>

                    {{-- « Plus tard » : un report de sept jours, posé et
                         respecté. En état `denied`, le script le relabelle
                         « Compris » — on ne « reporte » pas une explication. --}}
                    <button type="button" data-push-first-run-later
                            class="inline-flex min-h-11 items-center rounded-xl px-4 text-sm font-semibold text-[var(--bp-muted)] transition hover:bg-[var(--bp-hover)]">
                        {{ __('pwa.first_run_later') }}
                    </button>
                </div>

                <p data-push-first-run-help hidden style="display: none"
                   class="mt-2 text-xs text-[var(--bp-muted)]"></p>
            </div>
        </div>

        {{-- EN PLACE, pas en @push : voir l'en-tête de ce fichier. --}}
        @vite('resources/js/push-first-run.js')
    @endif
@endauth
