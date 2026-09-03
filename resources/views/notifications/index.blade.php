{{--
    TASK-1373 — le Centre de notifications.

    Ce que cet ecran ne fait PAS, et c'est deliberе : il n'ouvre aucun objet.
    Une notification ne porte que des references (`object_type` + `object_id`) ;
    les resoudre en lien profond, re-verifier la permission au clic et dire
    honnetement que la cible a disparu appartient a la tranche qui branchera le
    premier producteur reel. Montrer un lien qui pourrait mentir serait pire que
    de n'en montrer aucun.
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
    $libelle = function (string $cle) {
        $traduction = 'notifications.keys.'.str_replace('.', '_', $cle);

        return \Illuminate\Support\Facades\Lang::has($traduction)
            ? __($traduction)
            : __('notifications.key_fallback');
    };
@endphp

<x-app-layout>
    <x-slot name="title">{{ __('notifications.title') }}</x-slot>

    <x-page-container>
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
