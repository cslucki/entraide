<x-guest-layout>
    {{-- TASK-1883 — desinscription de la newsletter, sans connexion (lien signe). --}}
    <div class="space-y-4 text-center" data-newsletter-unsubscribe>
        <h1 class="text-xl font-semibold text-gray-900 dark:text-gray-100">{{ __('newsletter.title') }}</h1>

        @if($fait)
            <p class="text-sm text-gray-600 dark:text-gray-300" data-newsletter-unsubscribed>{{ __('newsletter.done', ['email' => $compte->email]) }}</p>
            <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('newsletter.done_hint') }}</p>
        @else
            <p class="text-sm text-gray-600 dark:text-gray-300">{{ __('newsletter.confirm_text', ['email' => $compte->email]) }}</p>
            <form method="POST" action="{{ request()->fullUrl() }}">
                @csrf
                <button type="submit" class="inline-flex min-h-11 items-center rounded-xl bg-indigo-600 px-5 text-sm font-semibold text-white hover:bg-indigo-700" data-newsletter-unsubscribe-confirm>
                    {{ __('newsletter.confirm_button') }}
                </button>
            </form>
        @endif
    </div>
</x-guest-layout>
