{{--
    TASK-1739 — « Notifications sur cet appareil ».

    Bloc DISTINCT des préférences par événement, et c'est une décision :
    un abonnement est un état d'APPAREIL, pas une préférence métier. Il ne
    passe donc pas par le formulaire du catalogue, et il n'a pas de bouton
    « Enregistrer » — activer EST le geste.

    ## Quatre états, et aucun n'est supposé

    Trois se lisent dans le navigateur et nulle part ailleurs :
    `Notification.permission` (`default`, `granted`, `denied`) et la présence
    même de l'API. Le quatrième — « cet endpoint est-il connu du serveur pour
    cette personne ? » — vient du serveur. Il faut les DEUX moitiés : une
    permission accordée sans abonnement en base n'est pas une activation, et
    un abonnement en base que le navigateur a oublié n'en est plus une.

    C'est pourquoi l'état affiché au chargement est « Vérification… » et non
    un état deviné.

    ## La permission n'est demandée QU'AU CLIC

    CDC 5.3, et c'est la règle la plus importante de cet écran : une demande
    au chargement se fait refuser par réflexe, et un refus ne se rattrape pas.
    `Notification.requestPermission()` n'apparaît donc que dans le
    gestionnaire du bouton.

    ## Réconciliation

    `pushsubscriptionchange` n'est pas pris en charge partout. À chaque
    affichage de cette page, si le navigateur détient déjà un abonnement, il
    le RECONFIRME au serveur — opération idempotente côté magasin. C'est ce
    qui fait qu'un appareil revendiqué par un compte frère revient au bon
    compte dès que la personne ouvre cet écran.
--}}
<section data-push-device
         data-push-enabled="{{ config('webpush.enabled') ? '1' : '0' }}"
         data-push-vapid="{{ config('webpush.vapid.public_key') }}"
         data-push-url="{{ route($routePush, $routeParams) }}"
         {{-- TASK-1877 — le test Web Push reel de CET appareil. Absent si la
              page qui inclut le partiel ne le propose pas. --}}
         data-push-test-url="{{ isset($routeTest) ? route($routeTest, $routeParams) : '' }}"
         {{-- TASK-1744 — meme garde que le panneau de diagnostic : l'attribut
              n'existe pas hors local. Un message d'erreur technique n'a rien
              a faire devant un membre, mais sans lui une recette se fait a
              l'aveugle. --}}
         data-push-debug="{{ app()->environment('local') ? '1' : '0' }}"
         class="mb-8 rounded-xl border border-[var(--bp-border)] bg-[var(--bp-panel)] p-5">

    {{-- TASK-1877 — le titre est porte par le `<h1>` de la page dediee,
         seule page qui inclut ce partiel : le repeter ici le dirait deux
         fois a la suite. --}}
    <p class="text-sm text-[var(--bp-muted)]">{{ __('push.device_intro') }}</p>

    <p class="mt-4 flex items-center gap-2 text-sm font-medium text-[var(--bp-text)]">
        <span data-push-dot class="inline-block h-2.5 w-2.5 shrink-0 rounded-full bg-gray-400"></span>
        <span data-push-state>{{ __('push.state_checking') }}</span>
    </p>

    <p data-push-count class="mt-1 text-xs text-[var(--bp-muted)]"></p>

    {{-- TASK-1821 — en LOCAL uniquement : quel abonnement EXACT le serveur
         vient de reconnaitre pour CE navigateur. Jamais affiche hors local
         (le JSON qui l'alimente ne porte ces champs qu'en local, voir
         `PushSubscriptionController`), et jamais une ligne choisie au
         hasard parmi les appareils du compte — uniquement celle qui
         correspond a l'endpoint de CE navigateur. --}}
    @if(app()->environment('local'))
        <p data-push-device-info hidden style="display: none" class="mt-1 text-xs font-mono text-[var(--bp-muted)]"></p>
    @endif

    {{-- Les deux boutons sont rendus masqués et c'est le script qui révèle
         celui qui correspond à l'état réel. Un bouton « Activer » visible
         alors que le navigateur a refusé serait un bouton factice.

         TASK-1744 — `hidden` SEUL ne masquait RIEN, et ça a coûté une recette.

         L'attribut `hidden` ne masque que par la feuille de style du
         navigateur (`[hidden] { display: none }`). La classe `inline-flex`
         de Tailwind pose `display: inline-flex` au niveau AUTEUR, qui gagne.
         Mesuré : `hidden=true` sur les DEUX boutons, et pourtant
         `display: flex`, hauteur non nulle, les deux visibles — dans tous les
         états, depuis T1739.

         Conséquence vécue : un bouton primaire « Activer les notifications »
         s'affichait en permanence, y compris sur un appareil DÉJÀ activé. Il
         ressemblait à l'action à faire, et il détournait de la seule qui
         restait à faire — cocher les événements, plus bas.

         D'où le `display: none` EN LIGNE : une déclaration d'auteur, que
         `peindre()` lève ou repose. Pas de règle globale `[hidden]
         { display: none !important }` : elle changerait le comportement de
         tous les `hidden` du dépôt pour régler un défaut local. --}}
    <div class="mt-4 flex flex-wrap gap-2">
        <button type="button" data-push-enable hidden style="display: none"
                class="inline-flex min-h-11 items-center gap-2 rounded-xl bg-indigo-600 px-5 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-700 disabled:opacity-60">
            {{ __('push.enable') }}
        </button>
        <button type="button" data-push-disable hidden style="display: none"
                class="inline-flex min-h-11 items-center gap-2 rounded-xl border border-[var(--bp-border)] px-5 text-sm font-semibold text-[var(--bp-text)] transition hover:bg-[var(--bp-hover)] disabled:opacity-60">
            {{ __('push.disable') }}
        </button>
        {{-- TASK-1877 — test Web Push REEL, vers CET appareil seulement.
             Revele par le script dans l'etat « activees » uniquement : sans
             abonnement, il n'y a rien a tester. --}}
        @isset($routeTest)
            <button type="button" data-push-test hidden style="display: none"
                    class="inline-flex min-h-11 items-center gap-2 rounded-xl border border-[var(--bp-border)] px-5 text-sm font-semibold text-[var(--bp-text)] transition hover:bg-[var(--bp-hover)] disabled:opacity-60">
                {{ __('push.test_button') }}
            </button>
        @endisset
    </div>

    {{-- TASK-1877 — le verdict du test. Il ne dit jamais « recue » : un
         fournisseur qui accepte n'a rien affiche (arbitrage MASTER, R2). --}}
    <p data-push-test-resultat role="status" hidden style="display: none" class="mt-3 text-sm font-medium"></p>

    {{-- TASK-1744 — l'aide remonte AVANT le panneau de diagnostic.

         Elle vivait en dessous. Sur un téléphone, cela plaçait la phrase
         « cochez Sur mes appareils ci-dessous » après ~280 px de diagnostic,
         et la première case à cocher à 724 px du haut — soit deux écrans
         plus bas que l'état qu'elle commente. Mesuré, pas supposé.

         Et elle porte désormais un LIEN : lire « ci-dessous » ne vaut rien si
         « ci-dessous » est hors de l'écran. --}}
    <div data-push-help-box hidden style="display: none"
         class="mt-3 rounded-lg bg-[var(--bp-hover)] px-3 py-2 text-xs text-[var(--bp-muted)]">
        <p data-push-help></p>

        @if(($pushActifs ?? 0) === 0)
            {{-- TASK-1877 — le bloc a quitte la page des reglages : l'ancre
                 devient un lien vers elle. --}}
            <a href="{{ route($routePreferences ?? 'notifications.preferences.edit', $routeParams ?? []) }}#reglages-par-evenement" data-push-help-lien
               class="mt-1 inline-flex min-h-11 items-center font-semibold text-[var(--bp-primary)] underline">
                {{ __('push.ready_none_action') }}
            </a>
        @endif
    </div>

    {{-- =====================================================================
         TASK-1740 — PANNEAU DE DIAGNOSTIC. TASK-1877 — PUBLIE (option B).

         Ne en recette locale : FCM repondait `201` et rien ne s'affichait,
         avec cinq maillons qu'on ne pouvait que SUPPOSER depuis un
         telephone — permission, origine, scope, exemplaire du Service Worker
         reellement actif, rattachement de l'abonnement.

         TASK-1877 (arbitrage MASTER du 21/11) : chaque membre doit pouvoir
         depanner SES notifications sans administrateur. Le panneau est donc
         rendu partout, REPLIE, et traduit. Ce qui reste local :
         - les details bruts d'erreur (`data-push-debug`, plus haut) ;
         - la ligne « Premier lancement » et la reinitialisation du report,
           outils de recette de T1745.
         Ce qu'il ne montre JAMAIS : l'endpoint complet ni une clef privee.
         L'empreinte (8 caracteres) est calculee dans le navigateur, sur
         l'abonnement de CE navigateur ; la clef VAPID affichee est publique.

         T1744 — REPLIE par defaut : deplie, il repoussait le reste de
         l'ecran deux pages plus bas sur un telephone.
         ===================================================================== --}}
    {{-- TASK-1878 — deplie sur la console de diagnostic. --}}
    <details data-push-diag-panel class="mt-4 rounded-lg border border-dashed border-[var(--bp-border)] p-3" @if(! empty($diagOuvert)) open @endif>
        <summary class="cursor-pointer text-xs font-semibold uppercase tracking-wide text-[var(--bp-muted)]">
            {{ __('push.diag.title') }}
        </summary>

        <dl data-push-diag class="mt-2 space-y-1 font-mono text-[11px] leading-snug text-[var(--bp-text)]">
            @foreach(array_merge([
                'permission' => __('push.diag.permission'),
                'origine' => __('push.diag.origin'),
                'securise' => __('push.diag.secure'),
                'scope' => __('push.diag.scope'),
                'version' => __('push.diag.version'),
                'abonnement' => __('push.diag.subscription'),
                'empreinte' => __('push.diag.fingerprint'),
                'base' => __('push.diag.server'),
                'cle' => __('push.diag.key'),
            ], app()->environment('local') ? [
                // TASK-1745, recette 03/11 n°2 — la CAUSE de la decision du
                // premier lancement, ecrite par `push-first-run.js`. Outil de
                // recette : local seulement (TASK-1877).
                'premier-lancement' => 'Premier lancement',
            ] : []) as $cle => $libelle)
                <div class="flex gap-2">
                    <dt class="w-32 shrink-0 text-[var(--bp-muted)]">{{ $libelle }}</dt>
                    <dd data-push-diag-value="{{ $cle }}" class="min-w-0 break-all">…</dd>
                </div>
            @endforeach
        </dl>

        {{-- Les deux essais qui SEPARENT les causes (T1740).

             « Afficher en local » n'emprunte ni le reseau, ni FCM, ni le
             chiffrement : s'il echoue, la panne est dans la permission ou
             l'affichage systeme.

             « Simuler une charge » fait traiter une charge par l'exemplaire
             ACTIF du Service Worker, par le meme chemin que le push reel,
             toujours sans reseau : s'il reussit alors que l'envoi reel
             echoue, la panne est dans la LIVRAISON. --}}
        <div class="mt-3 flex flex-wrap gap-2">
            <button type="button" data-push-diag-local
                    class="inline-flex min-h-11 items-center rounded-lg border border-[var(--bp-border)] px-3 text-xs font-semibold text-[var(--bp-text)]">
                {{ __('push.diag.test_local') }}
            </button>
            <button type="button" data-push-diag-simuler
                    class="inline-flex min-h-11 items-center rounded-lg border border-[var(--bp-border)] px-3 text-xs font-semibold text-[var(--bp-text)]">
                {{ __('push.diag.simulate') }}
            </button>

            {{-- TASK-1745 (point 6, recette 03/11) — efface le REPORT du
                 premier lancement (`localStorage`), et rien d'autre. Ecoute
                 par `push-first-run.js`, qui possede la cle. Outil de
                 recette : LOCAL seulement, y compris apres TASK-1877. --}}
            @if(app()->environment('local'))
                <button type="button" data-push-first-run-reset
                        data-reset-done="Report effacé — fermez et rouvrez l'application : la proposition reviendra"
                        data-reset-done-subscribed="Report effacé — mais cet appareil est DÉJÀ abonné, et la proposition ne s'affiche jamais sur un appareil abonné. « Désactiver sur cet appareil » d'abord, puis fermez et rouvrez l'application"
                        data-reset-done-browser-tab="Report effacé — la proposition n'apparaît que dans l'application INSTALLÉE, pas dans un onglet de navigateur"
                        class="inline-flex min-h-11 items-center rounded-lg border border-amber-500 px-3 text-xs font-semibold text-amber-800 disabled:opacity-60 dark:text-amber-200">
                    Revoir la proposition du premier lancement
                </button>
            @endif
        </div>

        <p data-push-diag-resultat hidden class="mt-2 text-[11px] font-semibold"></p>

        {{-- TASK-1877 — les textes du panneau, traduits cote serveur : le
             script n'en fabrique aucun. `%s` est remplace par le script. --}}
        <span hidden
              data-push-diag-texts
              data-api-absent="{{ __('push.diag.api_absent') }}"
              data-yes="{{ __('push.diag.yes') }}"
              data-no="{{ __('push.diag.no') }}"
              data-none="{{ __('push.diag.none') }}"
              data-no-answer="{{ __('push.diag.no_answer') }}"
              data-push-supported="{{ __('push.diag.push_supported') }}"
              data-sw-old="{{ __('push.diag.sw_old') }}"
              data-server-silent="{{ __('push.diag.server_silent') }}"
              data-key-hidden="{{ __('push.diag.key_hidden') }}"
              data-key-match="{{ __('push.diag.key_match') }}"
              data-key-diverge="{{ __('push.diag.key_diverge') }}"
              data-permission-refused="{{ __('push.diag.permission_refused') }}"
              data-local-title="{{ __('push.diag.local_title') }}"
              data-local-body="{{ __('push.diag.local_body') }}"
              data-local-done="{{ __('push.diag.local_done') }}"
              data-local-failed="{{ __('push.diag.local_failed') }}"
              data-simulate-needs-permission="{{ __('push.diag.simulate_needs_permission') }}"
              data-simulate-title="{{ __('push.diag.simulate_title') }}"
              data-simulate-body="{{ __('push.diag.simulate_body') }}"
              data-simulate-old="{{ __('push.diag.simulate_old') }}"
              data-simulate-done="{{ __('push.diag.simulate_done') }}"
              data-simulate-refused="{{ __('push.diag.simulate_refused') }}"></span>
    </details>

    {{-- Les messages passent par des attributs de données : ils sont
         traduits côté serveur, et le script n'en fabrique aucun. --}}
    <span hidden
          data-push-texts
          data-text-on="{{ __('push.state_on') }}"
          data-text-off="{{ __('push.state_off') }}"
          data-text-denied="{{ __('push.state_denied') }}"
          data-text-unsupported="{{ __('push.state_unsupported') }}"
          {{-- TASK-1768 — le 5e etat : le navigateur SAIT faire, mais le
               produit ne le propose pas encore ici. Distinct de
               « non prises en charge », qui accuse le navigateur. --}}
          data-text-unavailable="{{ __('push.state_unavailable') }}"
          data-text-denied-help="{{ __('push.denied_help') }}"
          data-text-unsupported-help="{{ __('push.unsupported_help') }}"
          data-text-unavailable-help="{{ __('push.unavailable_help') }}"
          {{-- TASK-1744 — le message affiché quand l'appareil est enregistré.
               Il dépend de ce que CETTE personne a coché, pas d'un état
               général du produit : voir le calcul de `$pushActifs` dans
               `notifications/preferences.blade.php`. --}}
          data-text-ready="{{ ($pushActifs ?? 0) > 0 ? __('push.ready_some') : __('push.ready_none') }}"
          data-text-enable-failed="{{ __('push.enable_failed') }}"
          data-text-disable-failed="{{ __('push.disable_failed') }}"
          data-text-key-stale="{{ __('push.key_stale') }}"
          {{-- TASK-1877 — verdicts du test Web Push reel (R1/R2 MASTER). --}}
          data-text-test-sent="{{ __('push.test_sent') }}"
          data-text-test-failed="{{ __('push.test_failed') }}"
          data-text-test-revoked="{{ __('push.test_revoked') }}"
          data-text-test-revoked-cleanup-failed="{{ __('push.test_revoked_cleanup_failed') }}"
          data-text-test-unknown="{{ __('push.test_unknown') }}"
          data-text-test-throttled="{{ __('push.test_throttled') }}"
          data-text-device-registered="{{ __('push.device_registered') }}"
          data-text-device-registered-unlabeled="{{ __('push.device_registered_unlabeled') }}"
          data-count-zero="{{ trans_choice('push.devices_count', 0) }}"
          data-count-one="{{ trans_choice('push.devices_count', 1) }}"
          data-count-many="{{ trans_choice('push.devices_count', 2, ['count' => '%d']) }}"></span>
</section>
