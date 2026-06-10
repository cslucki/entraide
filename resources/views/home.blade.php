<x-app-layout title="Accueil">
    <section class="min-h-screen bg-[var(--bp-page)] px-4 py-6 text-[var(--bp-text)] md:px-8 md:py-8">
        <div class="mx-auto flex min-h-[calc(100vh-3rem)] max-w-6xl flex-col rounded-[2rem] border border-[var(--bp-border)] bg-[var(--bp-surface)]/80 shadow-sm backdrop-blur md:min-h-[calc(100vh-4rem)]">
            <div class="flex items-center justify-between border-b border-[var(--bp-border)] px-5 py-4 md:px-8">
                <a href="{{ route('home') }}" class="flex items-center gap-3" aria-label="Accueil BouclePro">
                    <img src="/brand/bouclepro-symbol-64.png" alt="" class="h-11 w-11 rounded-2xl bg-[var(--bp-panel)] shadow-sm ring-1 ring-[var(--bp-border)]" aria-hidden="true">
                    <div>
                        <p class="text-lg font-bold tracking-tight">BouclePro</p>
                        <p class="text-xs text-[var(--bp-muted)]">Travailler, s'entraider, avancer.</p>
                    </div>
                </a>

                @guest
                    <a href="{{ route('login') }}" class="rounded-full border border-[var(--bp-border)] px-4 py-2 text-sm font-semibold text-[var(--bp-muted)] transition hover:bg-[var(--bp-panel)] hover:text-[var(--bp-text)]">
                        Connexion
                    </a>
                @else
                    <a href="{{ route('loops.index') }}" class="rounded-full bg-[var(--bp-primary)] px-4 py-2 text-sm font-semibold text-white transition hover:bg-[var(--bp-primary-deep)]">
                        Ouvrir ChatLoop
                    </a>
                @endguest
            </div>

            <div class="grid flex-1 gap-0 md:grid-cols-[minmax(0,1fr)_22rem]">
                <div class="flex flex-col justify-center px-6 py-12 md:px-14">
                    <div class="max-w-2xl block rounded-[1.5rem] px-6 py-10 shadow-sm transition hover:-translate-y-0.5 hover:shadow-md bg-[var(--bp-card-welcome)] text-black dark:text-black">
                        <p class="mb-4 inline-flex rounded-full bg-[color-mix(in_srgb,var(--bp-primary)_12%,transparent)] px-3 py-1 text-xs font-semibold uppercase tracking-[0.2em] text-[var(--bp-primary)]">
                            Interface sobre
                        </p>
                        <h1 class="text-4xl font-semibold tracking-tight text-[var(--bp-text)] sm:text-5xl md:text-6xl">
                            Que voulez-vous faire aujourd'hui ?
                        </h1>
                        <p class="mt-5 max-w-xl text-lg leading-8 text-[var(--bp-muted)]">
                            Entrez dans une boucle, trouvez un échange, suivez vos objectifs ou lisez les actus. BouclePro se concentre sur l'action utile, sans tableau de bord bruyant.
                        </p>

                        <div class="mt-8 flex flex-col gap-3 sm:flex-row">
                            @guest
                                <a href="{{ route('register') }}" class="inline-flex items-center justify-center rounded-full bg-[var(--bp-primary)] px-6 py-3 text-sm font-semibold text-white shadow-sm transition hover:bg-[var(--bp-primary-deep)]">
                                    Créer un compte
                                </a>
                                <a href="{{ route('boucles.index') }}" class="inline-flex items-center justify-center rounded-full border border-[var(--bp-border)] px-6 py-3 text-sm font-semibold text-[var(--bp-muted)] transition hover:bg-[var(--bp-panel)] hover:text-[var(--bp-text)]">
                                    Découvrir les boucles
                                </a>
                            @else
                                <a href="{{ route('loops.index') }}" class="inline-flex items-center justify-center rounded-full bg-[var(--bp-primary)] px-6 py-3 text-sm font-semibold text-white shadow-sm transition hover:bg-[var(--bp-primary-deep)]">
                                    Rejoindre ChatLoop
                                </a>
                                <a href="{{ route('explorer') }}" class="inline-flex items-center justify-center rounded-full border border-[var(--bp-border)] px-6 py-3 text-sm font-semibold text-[var(--bp-muted)] transition hover:bg-[var(--bp-panel)] hover:text-[var(--bp-text)]">
                                    Voir les échanges
                                </a>
                            @endguest
                        </div>
                    </div>
                </div>

                <div class="border-t border-[var(--bp-border)] bg-[var(--bp-surface-soft)]/80 p-4 md:border-l md:border-t-0 md:p-5">
                    <div class="grid gap-3">
                        @php
                            $features = [
                                ['label' => 'Boucles', 'text' => 'Le ChatLoop comme point de départ.', 'href' => auth()->check() ? route('loops.index') : route('boucles.index'), 'tone' => 'bg-[var(--bp-card-loop)] text-black dark:text-black'],
                                ['label' => 'Échanges', 'text' => 'Services, demandes et conversations utiles.', 'href' => route('explorer'), 'tone' => 'bg-[var(--bp-card-exchange)] text-black dark:text-black'],
                                ['label' => 'Annuaire', 'text' => 'Retrouvez les membres, profils et points de contact.', 'href' => auth()->check() ? route('dashboard') : route('login'), 'tone' => 'bg-[var(--bp-card-directory)] text-black dark:text-black'],
                                ['label' => 'Actus', 'text' => 'Les nouvelles de la communauté.', 'href' => route('blog.index'), 'tone' => 'bg-[var(--bp-card-news)] text-black dark:text-black'],
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
