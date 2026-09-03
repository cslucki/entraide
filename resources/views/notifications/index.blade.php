{{--
    TASK-1373 — le Centre de notifications.

    Une notification ne porte que des references (`object_type` + `object_id`) :
    aucun texte, aucune URL. Le libelle vient du catalogue et la destination est
    resolue AU CLIC, sous les permissions du moment — jamais lue dans la ligne.

    C'est ce qui fait qu'une notification vieille d'un mois n'ouvre aucune porte
    fermee depuis. Quand la cible n'est plus accessible, l'ecran le dit
    (`data-notification-unreachable`) plutot que de rediriger vers une page qui
    refuserait.

    TASK-1382 — ce bloc affirmait « cet ecran n'ouvre aucun objet », ce qui a
    cesse d'etre vrai des TASK-1374 : le bouton « Ouvrir » est juste en dessous
    depuis, et le resolveur de cible existe. Un commentaire qui contredit le
    code sous lui est pire qu'aucun commentaire — il oriente la relecture
    suivante dans la mauvaise direction.
--}}
@php
    $filtreActif = fn (string $f) => $filtre === $f;
    $urlFiltre = function (string $f) {
        $params = $f === \App\Http\Controllers\NotificationCenterController::FILTRE_TOUTES
            ? []
            : ['filtre' => $f];

        return request()->url().($params ? '?'.http_build_query($params) : '');
    };

    // Le libelle vient du catalogue, jamais de la ligne : le stockage ne
    // contient aucun texte. Les points de la cle deviennent des tirets bas.
    //
    // TASK-1382 — le troisieme argument `false` DESACTIVE le repli de locale, et
    // il est load-bearing. `Lang::has()` honore la locale de repli par defaut :
    // une cle presente en francais seulement repondrait `true` sous une
    // interface anglaise, et le membre lirait du francais au lieu du repli
    // generique — que ce code existe precisement pour afficher.
    //
    // Le cas est aujourd'hui inatteignable : la garde de TASK-1379 exige un
    // libelle dans les DEUX locales pour toute cle du catalogue. C'est bien
    // pour cela que le repli doit etre juste — il ne sert que le jour ou cette
    // garde aura ete contournee ou une locale ajoutee.
    $libelle = function (string $cle) {
        $traduction = 'notifications.keys.'.str_replace('.', '_', $cle);

        return \Illuminate\Support\Facades\Lang::has($traduction, null, false)
            ? __($traduction)
            : __('notifications.key_fallback');
    };
@endphp

<x-app-layout>
    <x-slot name="title">{{ __('notifications.title') }}</x-slot>

    <x-page-container>
        @if(session('notification_unreachable'))
            {{-- L'etat honnete : generique, sans un fragment de ce que la cible
                 contenait. Il n'y a rien d'ancien a reafficher — le stockage ne
                 retient aucun contenu. --}}
            <div data-notification-unreachable
                 class="mb-5 rounded-xl border border-[var(--bp-border)] bg-[var(--bp-panel)] px-4 py-3 text-sm text-[var(--bp-muted)]">
                {{ session('notification_unreachable') }}
            </div>
        @endif

        <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
            <div>
                <h1 class="text-3xl font-bold text-[var(--bp-text)]">{{ __('notifications.title') }}</h1>
                <p class="mt-1 text-sm text-[var(--bp-muted)]">{{ __('notifications.subtitle') }}</p>
            </div>

            @if($nonLues > 0)
                <form method="POST" action="{{ route($routeReadAll, $routeParams) }}">
                    @csrf
                    <button type="submit"
                            data-notifications-mark-all
                            class="inline-flex min-h-11 items-center gap-2 rounded-xl bg-indigo-600 px-5 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-700">
                        {{ __('notifications.mark_all_read') }}
                    </button>
                </form>
            @endif
        </div>

        {{-- Filtres en GET : une vue filtree se partage par son URL. --}}
        <div class="mb-5 inline-flex rounded-lg border border-[var(--bp-border)] p-0.5">
            @foreach([
                \App\Http\Controllers\NotificationCenterController::FILTRE_TOUTES => __('notifications.filter_all'),
                \App\Http\Controllers\NotificationCenterController::FILTRE_NON_LUES => __('notifications.filter_unread'),
            ] as $valeur => $texte)
                <a href="{{ $urlFiltre($valeur) }}"
                   data-notifications-filter="{{ $valeur }}"
                   @if($filtreActif($valeur)) aria-current="page" @endif
                   class="min-h-11 rounded-md px-4 py-2 text-sm font-semibold transition {{ $filtreActif($valeur) ? 'bg-[var(--bp-panel)] text-[var(--bp-text)] shadow-sm' : 'text-[var(--bp-muted)] hover:text-[var(--bp-text)]' }}">
                    {{ $texte }}
                </a>
            @endforeach
        </div>

        @if($notifications->isEmpty())
            {{-- DEUX etats vides, et les confondre serait un mensonge : une boite
                 vide et un filtre sans resultat ne disent pas la meme chose. --}}
            @if($filtreActif(\App\Http\Controllers\NotificationCenterController::FILTRE_NON_LUES))
                <div data-notifications-filter-empty class="py-14 text-center">
                    <p class="text-base font-semibold text-[var(--bp-text)]">{{ __('notifications.filter_empty_title') }}</p>
                    <p class="mt-1 text-sm text-[var(--bp-muted)]">{{ __('notifications.filter_empty_body') }}</p>
                </div>
            @else
                <div data-notifications-empty class="py-14 text-center">
                    <p class="text-base font-semibold text-[var(--bp-text)]">{{ __('notifications.empty_title') }}</p>
                    <p class="mt-1 text-sm text-[var(--bp-muted)]">{{ __('notifications.empty_body') }}</p>
                </div>
            @endif
        @else
            <ul class="space-y-2">
                @foreach($notifications as $notification)
                    <li data-notification-id="{{ $notification->id }}"
                        @if($notification->isRead()) data-notification-read @else data-notification-unread @endif
                        class="flex flex-wrap items-start justify-between gap-3 rounded-xl border border-[var(--bp-border)] bg-[var(--bp-panel)] px-4 py-3 shadow-sm">
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-semibold text-[var(--bp-text)]">
                                {{ $libelle($notification->notification_key) }}
                            </p>
                            <p class="mt-0.5 text-xs text-[var(--bp-muted)]">
                                <time datetime="{{ $notification->created_at?->toIso8601String() }}">
                                    {{ $notification->created_at?->diffForHumans() }}
                                </time>
                            </p>
                        </div>

                        <div class="flex shrink-0 items-center gap-3">
                            {{-- Ouvrir est un POST : le geste marque lu, et un GET
                                 qui mute se rejoue — prefetch, scanner de liens,
                                 bouton precedent. --}}
                            <form method="POST" action="{{ route($routeOpen, $routeParams + ['notification' => $notification->id]) }}">
                                @csrf
                                <button type="submit"
                                        data-notification-open="{{ $notification->id }}"
                                        class="min-h-11 text-sm font-semibold text-[var(--bp-primary)] transition hover:underline">
                                    {{ __('notifications.open') }}
                                </button>
                            </form>

                            @unless($notification->isRead())
                                <span class="rounded-full bg-red-500/10 px-2 py-0.5 text-[11px] font-bold text-red-600 dark:text-red-400">
                                    {{ __('notifications.unread_badge') }}
                                </span>

                                <form method="POST" action="{{ route($routeRead, $routeParams + ['notification' => $notification->id]) }}">
                                    @csrf
                                    <button type="submit"
                                            data-notification-mark-read="{{ $notification->id }}"
                                            class="min-h-11 text-sm font-semibold text-[var(--bp-primary)] transition hover:underline">
                                        {{ __('notifications.mark_read') }}
                                    </button>
                                </form>
                            @endunless
                        </div>
                    </li>
                @endforeach
            </ul>

            <div class="mt-6">{{ $notifications->links() }}</div>
        @endif
    </x-page-container>
</x-app-layout>
