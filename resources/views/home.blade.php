<x-app-layout title="Accueil">
    <section class="min-h-screen bg-[var(--bp-page)] px-4 py-6 text-[var(--bp-text)] md:px-8 md:py-8">
        <div class="mx-auto flex min-h-[calc(100vh-3.5rem)] flex-col border-0 md:bg-[var(--bp-surface)]/80 md:shadow-sm md:backdrop-blur md:min-h-[calc(100vh-4rem)] md:max-w-6xl md:rounded-[2rem]">
            @guest
            <div class="hidden items-center justify-end border-b border-[var(--bp-border)] px-5 py-4 md:flex md:px-8">
                <a href="{{ route('login') }}" class="rounded-full border border-[var(--bp-border)] px-4 py-2 text-sm font-semibold text-[var(--bp-muted)] transition hover:bg-[var(--bp-panel)] hover:text-[var(--bp-text)]">
                    {{ __('navigation.login') }}
                </a>
            </div>
            @endguest

            <div class="flex flex-1 flex-col md:flex-row md:items-stretch md:justify-center gap-0">
                <div class="flex flex-col items-center justify-center py-12 md:px-14">
                    <div class="w-full block rounded-[1.5rem] px-6 py-10 shadow-sm transition hover:-translate-y-0.5 hover:shadow-md bg-[var(--bp-card-welcome)] text-black dark:text-black md:max-w-2xl">
                        <a href="{{ route('home') }}" class="flex items-center gap-4 mb-8" aria-label="Accueil BouclePro">
                            <img src="{{ $brandLogoUrl }}" alt="" class="h-14 w-14 rounded-2xl bg-[var(--bp-panel)] shadow-sm ring-1 ring-[var(--bp-border)]" aria-hidden="true">
                            <div>
                                <p class="text-2xl font-bold tracking-tight text-black dark:text-white">BouclePro</p>
                                <p class="text-sm text-[var(--bp-muted)]">{{ __('home.tagline') }}</p>
                            </div>
                        </a>
                        <p class="mb-4 inline-flex rounded-full bg-[color-mix(in_srgb,var(--bp-primary)_12%,transparent)] px-3 py-1 text-xs font-semibold uppercase tracking-[0.2em] text-[var(--bp-primary)]">
                            {{ __('home.welcome') }}
                        </p>
                        <h1 class="text-4xl font-semibold tracking-tight text-[var(--bp-text)] sm:text-5xl md:text-6xl">
                            {{ __('home.what_do_you_want') }}
                        </h1>
                        <p class="mt-5 max-w-xl text-lg leading-8 text-[var(--bp-muted)]">
                            {{ __('home.description') }}
                        </p>

                        {{-- TASK-1628 — les CTA de l'Accueil traditionnel menent aux BOUCLES.
                             Cette page n'etait quasiment jamais servie avant que le choix
                             « Accueil traditionnel » redevienne souverain ; ses CTA
                             renvoyaient vers l'inscription et une liste publique, pas vers
                             ce que la plateforme fait.

                             Les liens pointent DANS l'Organization par defaut plutot que sur
                             les routes globales : c'est la lecon de TASK-1608 sur la landing
                             d'Organization — un CTA global sort le visiteur du contexte que
                             la racine vient de resoudre.

                             Invite comme connecte recoivent les deux MEMES CTA. Les deux
                             routes sont derriere `Authenticate` : un invite rencontre la
                             connexion puis revient, flux deja gere, non contourne ici.

                             `$defaultOrganization` peut etre NULL (aucune Organization par
                             defaut) : on retombe alors sur les routes globales, qui portent
                             exactement les memes middlewares. Sans ce repli, `route()`
                             leverait sur une page d'accueil. --}}
                        @php
                            $ctaLoopsIndex = $defaultOrganization
                                ? route('organization.loops.index', $defaultOrganization)
                                : route('loops.index');
                            $ctaLoopsCreate = $defaultOrganization
                                ? route('organization.loops.create', $defaultOrganization)
                                : route('loops.create');
                        @endphp
                        <div class="mt-8 flex flex-col gap-3 sm:flex-row">
                            <a href="{{ $ctaLoopsIndex }}" class="inline-flex items-center justify-center rounded-full bg-[var(--bp-primary)] px-6 py-3 text-sm font-semibold text-white shadow-sm transition hover:bg-[var(--bp-primary-deep)]" data-home-cta="loops-index">
                                {{ __('navigation.join_loops') }}
                            </a>
                            <a href="{{ $ctaLoopsCreate }}" class="inline-flex items-center justify-center rounded-full border border-[var(--bp-border)] px-6 py-3 text-sm font-semibold text-[var(--bp-muted)] transition hover:bg-[var(--bp-panel)] hover:text-[var(--bp-text)]" data-home-cta="loops-create">
                                {{ __('navigation.create_your_loops') }}
                            </a>
                        </div>
                    </div>
                </div>

                <div class="flex flex-col items-center justify-center border-t border-[var(--bp-border)] bg-[var(--bp-surface-soft)]/80 p-4 md:w-[22rem] md:border-t-0 md:p-5">
                    <div class="grid w-full gap-3">
                        @php
                            $features = [
                                ['label' => __('home.feature_loops'), 'text' => __('home.feature_loops_desc'), 'href' => auth()->check() ? route('loops.index') : route('boucles.index'), 'tone' => 'bg-[var(--bp-card-loop)] text-black dark:text-black'],
                                ['label' => __('home.feature_exchanges'), 'text' => __('home.feature_exchanges_desc'), 'href' => route('explorer'), 'tone' => 'bg-[var(--bp-card-exchange)] text-black dark:text-black'],
                                ['label' => __('home.feature_directory'), 'text' => __('home.feature_directory_desc'), 'href' => auth()->check() ? route('dashboard') : route('login'), 'tone' => 'bg-[var(--bp-card-directory)] text-black dark:text-black'],
                                ['label' => __('home.feature_blog'), 'text' => __('home.feature_blog_desc'), 'href' => route('blog.index'), 'tone' => 'bg-[var(--bp-card-news)] text-black dark:text-black'],
                            ];
                        @endphp

                        @foreach($features as $feature)
                            <a href="{{ $feature['href'] }}" class="block rounded-[1.5rem] p-5 shadow-sm transition hover:-translate-y-0.5 hover:shadow-md {{ $feature['tone'] }}">
                                <p class="text-lg font-semibold">{{ $feature['label'] }}</p>
                                <p class="mt-8 text-sm leading-6 opacity-85">{{ $feature['text'] }}</p>
                            </a>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
        @include('partials.footer')
    </section>
</x-app-layout>
