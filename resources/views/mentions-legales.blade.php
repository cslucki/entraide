{{-- TASK-1903 — PAGES LEGALES, UNE VUE (decision Cyril : plusieurs pages).

     Quatre PAGES distinctes, chacune avec son seul contenu, servies par cette
     vue unique (textes de lang/{fr,en}/{legal,privacy,account_deletion,
     child_safety}.php, rendus par legal.partials.sections ; rien n'est
     duplique). Routes (global + /org/{slug}) :
       /mentions-legales       -> « Informations legales & securite » : les
                                  mentions legales (seul lien du pied de page).
       /confidentialite        -> $focus = 'confidentialite'     } URL declarees
       /suppression-de-compte  -> $focus = 'suppression-compte'  } a Google Play,
       /securite-enfants       -> $focus = 'securite-enfants'    } 200 direct
     Chaque page : son titre en <h1> et en <title>, son contenu seul, et en
     tete le MEME sommaire des quatre pages (decision Cyril).

     LIENS (decision Cyril) : jamais d'ancre `#…`, toujours les URL reelles,
     bornees a l'Organization courante. --}}
@php
    $focus = $focus ?? null;

    // URL dediee de chaque rubrique (global, ou /org/{slug} dans une Organization).
    $urls = [
        'mentions' => organizationScopedUrl('mentions-legales', 'organization.mentions-legales'),
        'confidentialite' => organizationScopedUrl('confidentialite', 'organization.confidentialite'),
        'suppression-compte' => organizationScopedUrl('suppression-de-compte', 'organization.suppression-de-compte'),
        'securite-enfants' => organizationScopedUrl('securite-enfants', 'organization.securite-enfants'),
    ];

    $rubriques = [
        'mentions' => [
            'title' => __('legal.title'),
            'intro' => __('legal.intro'),
        ],
        'confidentialite' => [
            'title' => __('privacy.title'),
            'updated' => __('privacy.updated'),
            'intro' => __('privacy.intro'),
            'sections' => __('privacy.sections'),
        ],
        'suppression-compte' => [
            'title' => __('account_deletion.title'),
            'updated' => __('account_deletion.updated'),
            'intro' => __('account_deletion.intro'),
            'sections' => __('account_deletion.sections'),
        ],
        'securite-enfants' => [
            'title' => __('child_safety.title'),
            'subtitle' => __('child_safety.heading'),
            'updated' => __('child_safety.updated'),
            'intro' => __('child_safety.intro'),
            'sections' => __('child_safety.sections'),
        ],
    ];

    if ($focus !== null && ! isset($rubriques[$focus])) {
        $focus = null;
    }

    // Liens internes des textes (`link` d'une section) : ancres de cette page.
    $links = [
        'legal' => ['url' => $urls['mentions'], 'label' => __('privacy.legal_link')],
        'deletion' => ['url' => $urls['suppression-compte'], 'label' => __('privacy.deletion_link')],
        'contact' => ['url' => 'mailto:'.__('child_safety.contact_email'), 'label' => __('child_safety.contact_link')],
    ];

    // Une page = une rubrique : la page ciblee, ou les mentions legales.
    $ordre = [$focus ?? 'mentions'];

    $pageTitle = $focus !== null ? $rubriques[$focus]['title'] : __('legal.center_title');
@endphp
<x-app-layout :title="$pageTitle" :show-internal-navigation="auth()->check() && showsOrganizationInternalNavigation()">
    <x-page-container>
        <div class="mx-auto max-w-4xl rounded-[2rem] border border-[var(--bp-border)] bg-[var(--bp-surface)]/90 p-6 shadow-sm backdrop-blur md:p-10"
             data-legal-page="{{ $focus ?? 'center' }}">

            <div class="mb-8 text-[var(--bp-muted)]">
                @if ($focus !== null)
                    {{-- Retour vers « Informations legales & securite ». --}}
                    <a href="{{ $urls['mentions'] }}"
                       class="mb-3 inline-flex rounded-full bg-[color-mix(in_srgb,var(--bp-primary)_12%,transparent)] px-3 py-1 text-xs font-semibold uppercase tracking-[0.2em] text-[var(--bp-primary)] hover:underline">
                        {{ __('legal.center_title') }}
                    </a>
                @else
                    <h1 class="text-2xl font-bold leading-tight text-[var(--bp-heading)] md:text-3xl">
                        {{ __('legal.center_title') }}
                    </h1>
                    <p class="mt-3 max-w-2xl text-base leading-7">{{ __('legal.center_intro') }}</p>
                @endif
            </div>

            {{-- Le MEME sommaire en tete de chaque page (decision Cyril). --}}
            @include('legal.partials.toc', ['rubriques' => $rubriques, 'urls' => $urls, 'courante' => $focus ?? 'mentions'])

            @foreach ($ordre as $id)
                @php
                    $rubrique = $rubriques[$id];
                    $tag = $id === $focus ? 'h1' : 'h2';
                    $sousTag = $id === $focus ? 'h2' : 'h3';
                @endphp

                <section id="{{ $id }}" data-legal-rubric="{{ $id }}"
                         class="scroll-mt-24 text-[var(--bp-muted)] {{ $loop->first ? '' : 'mt-12 border-t border-[var(--bp-border)] pt-10' }}">
                    <{{ $tag }} class="{{ $tag === 'h1' ? 'text-2xl md:text-3xl font-bold' : 'text-xl md:text-2xl font-bold' }} leading-tight text-[var(--bp-heading)]">
                        {{ $rubrique['title'] }}
                    </{{ $tag }}>
                    @isset($rubrique['subtitle'])
                        <p class="mt-1 text-base font-semibold">{{ $rubrique['subtitle'] }}</p>
                    @endisset
                    @isset($rubrique['updated'])
                        <p class="mt-2 text-xs">{{ $rubrique['updated'] }}</p>
                    @endisset
                    <p class="mt-3 max-w-2xl text-base leading-7">{{ $rubrique['intro'] }}</p>

                    @if ($id === 'securite-enfants')
                        {{-- Le moyen de signalement, sans faire defiler la rubrique. --}}
                        <div class="mt-6 rounded-3xl bg-[color-mix(in_srgb,var(--bp-primary)_8%,transparent)] p-5 ring-1 ring-[var(--bp-border)]" data-child-safety-contact>
                            <p class="text-lg font-semibold text-[var(--bp-heading)]">{{ __('child_safety.report_title') }}</p>
                            <p class="mt-1 text-sm">{{ __('child_safety.report_text') }}</p>
                            <a href="mailto:{{ __('child_safety.contact_email') }}"
                               class="mt-2 inline-flex break-all text-base font-semibold text-indigo-600 hover:underline dark:text-indigo-400">
                                {{ __('child_safety.contact_email') }}
                            </a>
                        </div>
                    @endif

                    <div class="mt-6">
                        @if ($id === 'mentions')
                            @include('legal.partials.notice', ['headingTag' => $sousTag])
                        @else
                            @include('legal.partials.sections', ['sections' => $rubrique['sections'], 'links' => $links, 'headingTag' => $sousTag])
                        @endif
                    </div>
                </section>

            @endforeach
        </div>
    </x-page-container>
</x-app-layout>
