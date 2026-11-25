{{-- TASK-1903 — CENTRE INSTITUTIONNEL UNIQUE « Informations legales & securite ».

     Une seule vue pour quatre rubriques : mentions legales, politique de
     confidentialite, suppression de compte, securite des enfants (normes CSAE).
     Les textes restent ceux de lang/{fr,en}/{legal,privacy,account_deletion,
     child_safety}.php, rendus par legal.partials.sections ; rien n'est duplique.

     Servie par huit routes (global + /org/{slug}) :
       /mentions-legales                     -> centre complet ($focus absent)
       /confidentialite                      -> $focus = 'confidentialite'
       /suppression-de-compte                -> $focus = 'suppression-compte'
       /securite-enfants                     -> $focus = 'securite-enfants'

     MODE CIBLE (URL declarees a Google Play) : reponse 200 directe, sans
     redirection ni JavaScript. La rubrique visee est rendue EN PREMIER et son
     titre est le <h1> et le <title> de la page : un robot qui ignore les
     fragments `#…` voit d'emblee « Politique de confidentialite », etc. Les
     autres rubriques suivent, apres le sommaire.

     Ancres stables : #mentions, #confidentialite, #suppression-compte,
     #securite-enfants, #sommaire (+ sous-ancres CSAE : #signaler, #contact…). --}}
@php
    $focus = $focus ?? null;

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
        'legal' => ['url' => '#mentions', 'label' => __('privacy.legal_link')],
        'deletion' => ['url' => '#suppression-compte', 'label' => __('privacy.deletion_link')],
        'contact' => ['url' => 'mailto:'.__('child_safety.contact_email'), 'label' => __('child_safety.contact_link')],
    ];

    $ordre = array_keys($rubriques);
    if ($focus !== null) {
        $ordre = array_merge([$focus], array_values(array_diff($ordre, [$focus])));
    }

    $pageTitle = $focus !== null ? $rubriques[$focus]['title'] : __('legal.center_title');
@endphp
<x-app-layout :title="$pageTitle" :show-internal-navigation="auth()->check() && showsOrganizationInternalNavigation()">
    <x-page-container>
        <div class="mx-auto max-w-4xl rounded-[2rem] border border-[var(--bp-border)] bg-[var(--bp-surface)]/90 p-6 shadow-sm backdrop-blur md:p-10"
             data-legal-page="{{ $focus ?? 'center' }}">

            <div class="mb-8 text-[var(--bp-muted)]">
                @if ($focus !== null)
                    {{-- Le centre, rappele en tete : il mene au sommaire. --}}
                    <a href="#sommaire"
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

            @if ($focus === null)
                @include('legal.partials.toc', ['rubriques' => $rubriques, 'focus' => $focus])
            @endif

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

                @if ($focus !== null && $loop->first)
                    <div class="mt-12">
                        @include('legal.partials.toc', ['rubriques' => $rubriques, 'focus' => $focus])
                    </div>
                @endif
            @endforeach
        </div>
    </x-page-container>
</x-app-layout>
