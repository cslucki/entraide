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
