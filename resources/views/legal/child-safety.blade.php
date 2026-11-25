{{-- TASK-1903 — page publique « Securite des enfants » (normes CSAE exigees par
     Google Play pour les applications de reseaux sociaux). Meme gabarit que
     les autres pages legales (T1898) ; servie en global (/securite-enfants) et
     dans le contexte d'une Organization. Textes : lang/{fr,en}/child_safety.php.
     Un sommaire mene aux ancres des sections ; le contact est en tete de page. --}}
@php
    $sections = __('child_safety.sections');
    $contactEmail = __('child_safety.contact_email');
    $links = [
        'contact' => ['url' => 'mailto:'.$contactEmail, 'label' => __('child_safety.contact_link')],
    ];
@endphp
<x-app-layout title="{{ __('child_safety.title') }}" :show-internal-navigation="auth()->check() && showsOrganizationInternalNavigation()">
    <x-page-container>
        <div class="mx-auto max-w-4xl rounded-[2rem] border border-[var(--bp-border)] bg-[var(--bp-surface)]/90 p-6 shadow-sm backdrop-blur md:p-10" data-legal-page="child-safety">
            <div class="mb-8 text-[var(--bp-muted)]">
                <p class="mb-3 inline-flex rounded-full bg-[color-mix(in_srgb,var(--bp-primary)_12%,transparent)] px-3 py-1 text-xs font-semibold uppercase tracking-[0.2em] text-[var(--bp-primary)]">
                    {{ __('child_safety.title') }}
                </p>
                <h1 class="text-2xl font-bold leading-tight text-[var(--bp-heading)] md:text-3xl">
                    {{ __('child_safety.heading') }}
                </h1>
                <p class="mt-2 text-xs text-[var(--bp-muted)]">{{ __('child_safety.updated') }}</p>
                <p class="mt-3 max-w-2xl text-base leading-7 text-[var(--bp-muted)]">
                    {{ __('child_safety.intro') }}
                </p>
            </div>

            {{-- Le moyen de signalement, accessible sans faire defiler la page. --}}
            <div class="mb-6 rounded-3xl text-[var(--bp-muted)] bg-[color-mix(in_srgb,var(--bp-primary)_8%,transparent)] p-5 ring-1 ring-[var(--bp-border)]" data-child-safety-contact>
                <h2 class="text-lg font-semibold text-[var(--bp-heading)]">{{ __('child_safety.report_title') }}</h2>
                <p class="mt-1 text-sm text-[var(--bp-muted)]">{{ __('child_safety.report_text') }}</p>
                <a href="mailto:{{ $contactEmail }}"
                   class="mt-2 inline-flex break-all text-base font-semibold text-indigo-600 hover:underline dark:text-indigo-400">
                    {{ $contactEmail }}
                </a>
            </div>

            <nav class="mb-6 rounded-3xl bg-[var(--bp-panel)] p-5 text-sm text-[var(--bp-muted)] ring-1 ring-[var(--bp-border)]" aria-labelledby="child-safety-toc">
                <h2 id="child-safety-toc" class="mb-2 text-lg font-semibold text-[var(--bp-heading)]">{{ __('child_safety.toc_title') }}</h2>
                <ol class="list-decimal space-y-1 pl-5">
                    @foreach ($sections as $section)
                        <li>
                            <a href="#{{ $section['id'] }}" class="text-indigo-600 hover:underline dark:text-indigo-400">{{ $section['title'] }}</a>
                        </li>
                    @endforeach
                </ol>
            </nav>

            @include('legal.partials.sections', ['sections' => $sections, 'links' => $links])

            <p class="mt-6 flex flex-wrap gap-x-6 gap-y-2 text-sm">
                <a href="{{ organizationScopedUrl('mentions-legales', 'organization.mentions-legales') }}"
                   class="font-medium text-indigo-600 hover:underline dark:text-indigo-400">
                    {{ __('child_safety.legal_link') }} →
                </a>
                <a href="{{ organizationScopedUrl('confidentialite', 'organization.confidentialite') }}"
                   class="font-medium text-indigo-600 hover:underline dark:text-indigo-400">
                    {{ __('child_safety.privacy_link') }} →
                </a>
            </p>
        </div>
    </x-page-container>
</x-app-layout>
