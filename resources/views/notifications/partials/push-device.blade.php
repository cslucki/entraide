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
         {{-- TASK-1744 — meme garde que le panneau de diagnostic : l'attribut
              n'existe pas hors local. Un message d'erreur technique n'a rien
              a faire devant un membre, mais sans lui une recette se fait a
              l'aveugle. --}}
         data-push-debug="{{ app()->environment('local') ? '1' : '0' }}"
         class="mb-8 rounded-xl border border-[var(--bp-border)] bg-[var(--bp-panel)] p-5">

    <h2 class="text-lg font-semibold text-[var(--bp-text)]">{{ __('push.device_title') }}</h2>
    <p class="mt-1 text-sm text-[var(--bp-muted)]">{{ __('push.device_intro') }}</p>

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
    </div>

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
            <a href="#reglages-par-evenement" data-push-help-lien
               class="mt-1 inline-flex min-h-11 items-center font-semibold text-[var(--bp-primary)] underline">
                {{ __('push.ready_none_action') }}
            </a>
        @endif
    </div>

    {{-- =====================================================================
         TASK-1740 — PANNEAU DE DIAGNOSTIC, TEMPORAIRE ET LOCAL SEULEMENT.

         Pourquoi il existe : FCM répondait `201` et rien ne s'affichait sur
         l'appareil. Entre « le fournisseur a accepté » et « Android a
         affiché » se trouvent cinq maillons qu'on ne pouvait que SUPPOSER
         depuis un téléphone — permission, origine, scope, exemplaire du
         Service Worker réellement actif, et rattachement de l'abonnement.
         Supposer, c'est ce qui a produit des essais répétés sans progrès.

         Pourquoi `local` seulement : c'est un outil de recette, pas une
         fonctionnalité. La garde est côté SERVEUR — ni le panneau ni ses
         boutons n'existent dans le HTML ailleurs qu'en local, donc il n'y a
         rien à désactiver côté navigateur.

         Les libellés sont en clair et non traduits : volontaire, pour ne pas
         faire entrer un outil temporaire dans les catalogues de langue.
         ===================================================================== --}}
    {{-- TASK-1744 — REPLIÉ par défaut.

         Déplié, il mesure ~280 px et s'intercale entre l'état de l'appareil
         et les réglages par événement. Sur un téléphone, il repoussait la
         première case à cocher deux écrans plus bas : l'outil de recette
         empêchait de faire la recette. Son contenu est intact, il reste à un
         doigt. --}}
    @if(app()->environment('local'))
        <details data-push-diag-panel class="mt-4 rounded-lg border border-dashed border-amber-400 bg-amber-50/50 p-3 dark:bg-amber-900/10">
            <summary class="cursor-pointer text-xs font-semibold uppercase tracking-wide text-amber-700 dark:text-amber-300">
                Diagnostic (recette locale)
            </summary>

            <dl data-push-diag class="mt-2 space-y-1 font-mono text-[11px] leading-snug text-[var(--bp-text)]">
                @foreach([
                    'permission' => 'Permission',
                    'origine' => 'Origine',
                    'securise' => 'Contexte sûr',
                    'scope' => 'Scope SW',
                    'version' => 'SW actif',
                    'abonnement' => 'Abonnement',
                    'empreinte' => 'Empreinte',
                    'base' => 'Connu du serveur',
                    'cle' => 'Clé d’abonnement',

                    // TASK-1745, recette 03/11 n°2 — la CAUSE de la décision
                    // du premier lancement, écrite par `push-first-run.js`
                    // (`dire()`). « La barre ne s'affiche pas » ne disait pas
                    // POURQUOI ; cette ligne le dit en continu :
                    // `deja-abonne`, `report-en-cours`, `onglet-ordinaire`…
                    'premier-lancement' => 'Premier lancement',
                ] as $cle => $libelle)
                    <div class="flex gap-2">
                        <dt class="w-32 shrink-0 text-[var(--bp-muted)]">{{ $libelle }}</dt>
                        <dd data-push-diag-value="{{ $cle }}" class="min-w-0 break-all">…</dd>
                    </div>
                @endforeach
            </dl>

            {{-- Les deux essais qui SÉPARENT les causes.

                 « Afficher en local » n'emprunte ni le réseau, ni FCM, ni le
                 chiffrement : il appelle `showNotification()` depuis la page.
                 S'il échoue, la panne est dans la permission ou l'affichage
                 système, et aucun envoi distant ne pouvait aboutir.

                 « Simuler une charge » fait traiter une charge utile par
                 l'exemplaire ACTIF du Service Worker, par exactement le même
                 chemin d'affichage que le push réel, toujours sans réseau.
                 S'il réussit alors que l'envoi distant échoue, la panne est
                 dans la LIVRAISON, pas dans notre code. --}}
            <div class="mt-3 flex flex-wrap gap-2">
                <button type="button" data-push-diag-local
                        class="inline-flex min-h-11 items-center rounded-lg border border-amber-500 px-3 text-xs font-semibold text-amber-800 dark:text-amber-200">
                    Tester l'affichage local
                </button>
                <button type="button" data-push-diag-simuler
                        class="inline-flex min-h-11 items-center rounded-lg border border-amber-500 px-3 text-xs font-semibold text-amber-800 dark:text-amber-200">
                    Simuler une charge Push
                </button>

                {{-- TASK-1745 (point 6, recette 03/11) — efface le REPORT du
                     premier lancement (`localStorage`), et rien d'autre : ni
                     l'abonnement, ni le Service Worker, ni le cache. C'est le
                     moyen sûr de revoir la proposition — l'alternative
                     « vider les données du site » détruirait l'abonnement
                     Push. Écouté par `push-first-run.js`, qui possède la clé.

                     Recette n°2 (03/11) : la première version disait « fermez
                     et rouvrez » sans regarder si la proposition POUVAIT
                     revenir. Sur l'appareil de Cyril, le blocage n'était pas
                     le report mais « déjà abonné » — et le bouton laissait
                     deviner. Il REDÉCIDE maintenant après l'effacement et
                     rend la réponse qui correspond à l'état réel. --}}
                <button type="button" data-push-first-run-reset
                        data-reset-done="Report effacé — fermez et rouvrez l'application : la proposition reviendra"
                        data-reset-done-subscribed="Report effacé — mais cet appareil est DÉJÀ abonné, et la proposition ne s'affiche jamais sur un appareil abonné. « Désactiver sur cet appareil » d'abord, puis fermez et rouvrez l'application"
                        data-reset-done-browser-tab="Report effacé — la proposition n'apparaît que dans l'application INSTALLÉE, pas dans un onglet de navigateur"
                        class="inline-flex min-h-11 items-center rounded-lg border border-amber-500 px-3 text-xs font-semibold text-amber-800 disabled:opacity-60 dark:text-amber-200">
                    Revoir la proposition du premier lancement
                </button>
            </div>

            <p data-push-diag-resultat hidden class="mt-2 text-[11px] font-semibold"></p>
        </details>
    @endif

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
          data-text-device-registered="{{ __('push.device_registered') }}"
          data-text-device-registered-unlabeled="{{ __('push.device_registered_unlabeled') }}"
          data-count-zero="{{ trans_choice('push.devices_count', 0) }}"
          data-count-one="{{ trans_choice('push.devices_count', 1) }}"
          data-count-many="{{ trans_choice('push.devices_count', 2, ['count' => '%d']) }}"></span>
</section>
