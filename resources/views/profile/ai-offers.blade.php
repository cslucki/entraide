{{--
    « Voir les offres » (TASK-1229) — page d'INFORMATION, aucun paiement, aucun
    catalogue reel (Stripe / facturation hors scope). Ce qu'est le credit IA,
    ce qui compte, comment l'etendre (Organization / plateforme), et le lien
    vers les abonnements de l'Organization s'ils sont actives.
--}}
@php
    $quota = $credit->quota();
    $situation = $credit->isUnlimited()
        ? __('ai.credit_unlimited')
        : __('ai.credit_used_of_quota', ['used' => number_format($credit->used), 'quota' => number_format((int) $quota)]);
@endphp

<x-app-layout>
    <x-slot name="title">{{ __('ai.offers_title') }}</x-slot>

    <x-page-container>
        <div class="max-w-3xl mx-auto py-8 px-4 space-y-6" data-ai-offers>
            <div>
                <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">{{ __('ai.offers_title') }}</h1>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">{{ __('ai.offers_intro') }}</p>
            </div>

            <section class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-6">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-2">{{ __('ai.offers_current_title') }}</h2>
                <p class="text-xl font-semibold text-gray-900 dark:text-gray-100" data-ai-offers-situation>{{ $situation }}</p>
                @unless($credit->isUnlimited())
                    <p class="text-sm text-gray-600 dark:text-gray-300 mt-1">{{ trans_choice('ai.credit_remaining', (int) $credit->remaining(), ['count' => number_format((int) $credit->remaining())]) }} · {{ __('ai.credit_renews_at', ['date' => $credit->renewsAt->format('d/m/Y')]) }}</p>
                @endunless
            </section>

            <section class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-6">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-2">{{ __('ai.offers_what_counts_title') }}</h2>
                <p class="text-sm text-gray-700 dark:text-gray-300">{{ __('ai.offers_what_counts_body') }}</p>
            </section>

            <section class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-6 space-y-3">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('ai.offers_next_title') }}</h2>
                <p class="text-sm text-gray-700 dark:text-gray-300">{{ __('ai.offers_next_organization') }}</p>
                <p class="text-sm text-gray-700 dark:text-gray-300">{{ __('ai.offers_next_platform') }}</p>
                <div class="flex flex-wrap gap-3 pt-2">
                    @if($subscriptionsUrl)
                        <a href="{{ $subscriptionsUrl }}" class="inline-flex items-center rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700" data-ai-offers-subscriptions>{{ __('ai.offers_subscriptions_link') }}</a>
                    @endif
                    {{-- Le mail de l'admin n'est propose que s'il le rend visible (preference show_email). --}}
                    @if($organization->admin?->email && $organization->admin->show_email)
                        <a href="mailto:{{ $organization->admin->email }}" class="inline-flex items-center rounded-lg border border-gray-300 dark:border-gray-600 px-4 py-2 text-sm font-medium text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700" data-ai-offers-contact>{{ __('ai.offers_contact_admin') }}</a>
                    @endif
                    <a href="{{ $usageUrl }}" class="inline-flex items-center rounded-lg px-4 py-2 text-sm font-medium text-indigo-700 dark:text-indigo-300 hover:underline">{{ __('ai.offers_back') }}</a>
                </div>
            </section>

            <p class="text-xs text-gray-400 dark:text-gray-500">{{ __('ai.offers_no_payment') }}</p>
        </div>
    </x-page-container>
</x-app-layout>
