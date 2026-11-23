{{-- TASK-1898 — politique de confidentialite publique (Google Play). Meme
     gabarit que mentions-legales ; servie en global (/confidentialite) et
     dans le contexte d'une Organization (/org/{slug}/confidentialite). --}}
@php
    $links = [
        'legal' => ['url' => organizationScopedUrl('mentions-legales', 'organization.mentions-legales'), 'label' => __('privacy.legal_link')],
        'deletion' => ['url' => organizationScopedUrl('suppression-de-compte', 'organization.suppression-de-compte'), 'label' => __('privacy.deletion_link')],
    ];
@endphp
<x-app-layout title="{{ __('privacy.title') }}" :show-internal-navigation="auth()->check() && showsOrganizationInternalNavigation()">
    <x-page-container>
        <div class="mx-auto max-w-4xl rounded-[2rem] border border-[var(--bp-border)] bg-[var(--bp-surface)]/90 p-6 shadow-sm backdrop-blur md:p-10" data-legal-page="privacy">
            <div class="mb-8">
                <h1 class="mb-3 inline-flex rounded-full bg-[color-mix(in_srgb,var(--bp-primary)_12%,transparent)] px-3 py-1 text-xs font-semibold uppercase tracking-[0.2em] text-[var(--bp-primary)]">
                    {{ __('privacy.title') }}
                </h1>
                <p class="text-xs text-[var(--bp-muted)]">{{ __('privacy.updated') }}</p>
                <p class="mt-2 max-w-2xl text-base leading-7 text-[var(--bp-muted)]">
                    {{ __('privacy.intro') }}
                </p>
            </div>

            @include('legal.partials.sections', ['sections' => __('privacy.sections'), 'links' => $links])
        </div>
    </x-page-container>
</x-app-layout>
