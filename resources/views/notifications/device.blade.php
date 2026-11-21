{{--
    TASK-1877 — « Notifications sur cet appareil », sur sa propre page.

    Le bloc de T1739 est inclus TEL QUEL : meme partiel, meme script, memes
    routes d'abonnement. La page des preferences n'y mene plus que par un
    bouton, et ce partiel y renvoie pour les reglages par evenement.
--}}
<x-app-layout>
    <x-slot name="title">{{ __('push.device_title') }}</x-slot>

    <x-page-container>
        <div class="mb-6">
            <h1 class="text-3xl font-bold text-[var(--bp-text)]">{{ __('push.device_title') }}</h1>
            <a href="{{ route($routePreferences, $routeParams) }}"
               data-push-device-back
               class="mt-2 inline-flex min-h-11 items-center text-sm font-semibold text-[var(--bp-primary)] transition hover:underline">
                {{ __('push.device_back') }}
            </a>
            {{-- TASK-1878 — lien discret vers la console complete. --}}
            <a href="{{ route($routeDiagnostic, $routeParams) }}"
               data-pwa-diagnostic-link
               class="mt-2 ml-4 inline-flex min-h-11 items-center text-sm text-[var(--bp-muted)] underline-offset-2 transition hover:underline">
                {{ __('pwa.diagnostic.link_from_device') }}
            </a>
        </div>

        @include('notifications.partials.push-device', [
            'pushActifs' => $pushActifs,
            'routeTest' => $routeTest,
            'routePreferences' => $routePreferences,
        ])
    </x-page-container>

    {{-- TASK-1739 — charge sur CETTE page seulement : `pushManager` et la
         demande de permission n'ont rien a peser ailleurs. --}}
    @push('scripts')
        @vite('resources/js/push-device.js')
    @endpush
</x-app-layout>
