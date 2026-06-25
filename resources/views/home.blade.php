<x-app-layout title="Accueil">
    <section class="min-h-screen bg-[var(--bp-page)] px-4 py-6 text-[var(--bp-text)] md:px-8 md:py-8">
        <div class="mx-auto flex min-h-[calc(100vh-3rem)] flex-col border-0 md:bg-[var(--bp-surface)]/80 md:shadow-sm md:backdrop-blur md:min-h-[calc(100vh-4rem)] md:max-w-6xl md:rounded-[2rem]">
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

                        <div class="mt-8 flex flex-col gap-3 sm:flex-row">
                            @guest
                                <a href="{{ route('register') }}" class="inline-flex items-center justify-center rounded-full bg-[var(--bp-primary)] px-6 py-3 text-sm font-semibold text-white shadow-sm transition hover:bg-[var(--bp-primary-deep)]">
                                    {{ __('navigation.create_account') }}
                                </a>
                                <a href="{{ route('boucles.index') }}" class="inline-flex items-center justify-center rounded-full border border-[var(--bp-border)] px-6 py-3 text-sm font-semibold text-[var(--bp-muted)] transition hover:bg-[var(--bp-panel)] hover:text-[var(--bp-text)]">
                                    {{ __('navigation.discover_loops') }}
                                </a>
                            @else
                                <a href="{{ route('loops.index') }}" class="inline-flex items-center justify-center rounded-full bg-[var(--bp-primary)] px-6 py-3 text-sm font-semibold text-white shadow-sm transition hover:bg-[var(--bp-primary-deep)]">
                                    {{ __('navigation.join_loop') }}
                                </a>
                                <a href="{{ route('explorer') }}" class="inline-flex items-center justify-center rounded-full border border-[var(--bp-border)] px-6 py-3 text-sm font-semibold text-[var(--bp-muted)] transition hover:bg-[var(--bp-panel)] hover:text-[var(--bp-text)]">
                                    {{ __('navigation.see_exchanges') }}
                                </a>
                            @endguest
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
    </section>
</x-app-layout>
