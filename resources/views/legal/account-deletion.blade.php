{{-- TASK-1898 — page publique de suppression de compte (Google Play). Meme
     gabarit que mentions-legales ; servie en global (/suppression-de-compte)
     et dans le contexte d'une Organization. Elle DECRIT le parcours existant
     (Securite du compte > Fermer mon compte) sans le modifier. --}}
@php
    $links = [
        'legal' => ['url' => organizationScopedUrl('mentions-legales', 'organization.mentions-legales'), 'label' => __('account_deletion.legal_link')],
    ];
@endphp
<x-app-layout title="{{ __('account_deletion.title') }}" :show-internal-navigation="auth()->check() && showsOrganizationInternalNavigation()">
    <x-page-container>
        <div class="mx-auto max-w-4xl rounded-[2rem] border border-[var(--bp-border)] bg-[var(--bp-surface)]/90 p-6 shadow-sm backdrop-blur md:p-10" data-legal-page="account-deletion">
            <div class="mb-8">
                <h1 class="mb-3 inline-flex rounded-full bg-[color-mix(in_srgb,var(--bp-primary)_12%,transparent)] px-3 py-1 text-xs font-semibold uppercase tracking-[0.2em] text-[var(--bp-primary)]">
                    {{ __('account_deletion.title') }}
                </h1>
                <p class="text-xs text-[var(--bp-muted)]">{{ __('account_deletion.updated') }}</p>
                <p class="mt-2 max-w-2xl text-base leading-7 text-[var(--bp-muted)]">
                    {{ __('account_deletion.intro') }}
                </p>
            </div>

            @include('legal.partials.sections', ['sections' => __('account_deletion.sections'), 'links' => $links])

            <p class="mt-6 text-sm">
                <a href="{{ organizationScopedUrl('confidentialite', 'organization.confidentialite') }}"
                   class="text-indigo-600 dark:text-indigo-400 hover:underline font-medium">
                    {{ __('account_deletion.privacy_link') }} →
                </a>
            </p>
        </div>
    </x-page-container>
</x-app-layout>
