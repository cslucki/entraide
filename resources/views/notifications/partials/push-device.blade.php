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
         class="mb-8 rounded-xl border border-[var(--bp-border)] bg-[var(--bp-panel)] p-5">

    <h2 class="text-lg font-semibold text-[var(--bp-text)]">{{ __('push.device_title') }}</h2>
    <p class="mt-1 text-sm text-[var(--bp-muted)]">{{ __('push.device_intro') }}</p>

    <p class="mt-4 flex items-center gap-2 text-sm font-medium text-[var(--bp-text)]">
        <span data-push-dot class="inline-block h-2.5 w-2.5 shrink-0 rounded-full bg-gray-400"></span>
        <span data-push-state>{{ __('push.state_checking') }}</span>
    </p>

    <p data-push-count class="mt-1 text-xs text-[var(--bp-muted)]"></p>

    {{-- Les deux boutons sont rendus masqués et c'est le script qui révèle
         celui qui correspond à l'état réel. Un bouton « Activer » visible
         alors que le navigateur a refusé serait un bouton factice. --}}
    <div class="mt-4 flex flex-wrap gap-2">
        <button type="button" data-push-enable hidden
                class="inline-flex min-h-11 items-center gap-2 rounded-xl bg-indigo-600 px-5 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-700 disabled:opacity-60">
            {{ __('push.enable') }}
        </button>
        <button type="button" data-push-disable hidden
                class="inline-flex min-h-11 items-center gap-2 rounded-xl border border-[var(--bp-border)] px-5 text-sm font-semibold text-[var(--bp-text)] transition hover:bg-[var(--bp-hover)] disabled:opacity-60">
            {{ __('push.disable') }}
        </button>
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
    @if(app()->environment('local'))
        <div data-push-diag-panel class="mt-4 rounded-lg border border-dashed border-amber-400 bg-amber-50/50 p-3 dark:bg-amber-900/10">
            <p class="text-xs font-semibold uppercase tracking-wide text-amber-700 dark:text-amber-300">
                Diagnostic (recette locale)
            </p>

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
            </div>

            <p data-push-diag-resultat hidden class="mt-2 text-[11px] font-semibold"></p>
        </div>
    @endif

    <p data-push-help hidden class="mt-3 rounded-lg bg-[var(--bp-hover)] px-3 py-2 text-xs text-[var(--bp-muted)]"></p>

    {{-- Les messages passent par des attributs de données : ils sont
         traduits côté serveur, et le script n'en fabrique aucun. --}}
    <span hidden
          data-push-texts
          data-text-on="{{ __('push.state_on') }}"
          data-text-off="{{ __('push.state_off') }}"
          data-text-denied="{{ __('push.state_denied') }}"
          data-text-unsupported="{{ __('push.state_unsupported') }}"
          data-text-denied-help="{{ __('push.denied_help') }}"
          data-text-unsupported-help="{{ __('push.unsupported_help') }}"
          data-text-not-yet="{{ __('push.not_yet_sending') }}"
          data-text-enable-failed="{{ __('push.enable_failed') }}"
          data-text-disable-failed="{{ __('push.disable_failed') }}"
          data-count-zero="{{ trans_choice('push.devices_count', 0) }}"
          data-count-one="{{ trans_choice('push.devices_count', 1) }}"
          data-count-many="{{ trans_choice('push.devices_count', 2, ['count' => '%d']) }}"></span>
</section>
