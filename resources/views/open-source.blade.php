{{--
    TASK-1613 — l'explorateur Open Source, en PAGE.

    TASK-1612 en avait fait une surcouche. Deux mesures l'ont condamnee :

      - les gabarits autonomes (`hero-v2` — la HOMEPAGE —, `artscilab-hero`,
        `about`) ne chargent ni Tailwind ni le bundle Vite : la surcouche n'y
        existait pas, et « OpenSource » y partait toujours sur GitHub ;

      - une surcouche fait payer son CSS et son JS a TOUTES les pages, pour
        une fonction que peu ouvrent.

    Arbitrage Cyril : une vraie page. Les autres surfaces ne portent plus
    qu'un lien, donc plus rien a charger. Et le contenu gagne une URL
    partageable.

    Le rendu est SERVEUR. Il n'y a plus ni squelette ni `fetch` : sur une
    page dediee, l'attente d'un cache froid (~3,7 s, 14 appels) est a sa
    place — elle ne pesait pas la ou elle etait, sur une page d'accueil.
    L'instantane reste cache 60 min, avec repli 24 h.

    Le temps relatif est calcule ICI, au rendu, a partir des dates ISO de
    l'instantane — jamais fige dans le cache, ou il serait faux d'une heure
    au pire.
--}}
<x-app-layout :title="__('open_source.title')">
    <x-page-container>
        <div class="mx-auto max-w-4xl rounded-[2rem] border border-[var(--bp-border)] bg-[var(--bp-surface)]/90 shadow-sm backdrop-blur">

            {{-- ===================== EN-TETE ===================== --}}
            <header class="border-b border-[var(--bp-border)] p-6 md:p-10">
                <div class="flex items-center gap-3">
                    <span class="inline-flex h-11 w-11 flex-shrink-0 items-center justify-center rounded-xl bg-[var(--bp-text)] text-[var(--bp-panel)]">
                        <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M8.5 16.5 4 12l4.5-4.5M15.5 7.5 20 12l-4.5 4.5"/>
                        </svg>
                    </span>
                    <h1 class="text-2xl font-semibold tracking-tight text-[var(--bp-text)] md:text-3xl">
                        {{ __('open_source.title') }}
                    </h1>
                </div>

                <p class="mt-4 max-w-2xl text-sm leading-relaxed text-[var(--bp-muted)] md:text-base">
                    {{ __('open_source.tagline') }}
                </p>

                {{-- Badges. « Public », la licence et la pile sont DECLARES
                     (config/open_source.php) : ils s'affichent meme quand
                     GitHub ne repond pas. Seul le langage vient de l'API. --}}
                <div class="mt-5 flex flex-wrap items-center gap-1.5">
                    <span class="inline-flex items-center gap-1.5 rounded-full border border-[var(--bp-border)] px-2.5 py-1 text-[11px] font-medium text-[var(--bp-muted)]">
                        <span class="h-1.5 w-1.5 rounded-full bg-emerald-500" aria-hidden="true"></span>
                        {{ __('open_source.badge_public') }}
                    </span>
                    <span class="inline-flex items-center rounded-full border border-[var(--bp-border)] px-2.5 py-1 text-[11px] font-medium text-[var(--bp-muted)]">
                        {{ config('open_source.license') }}
                    </span>
                    @foreach ((array) config('open_source.stack', []) as $layer)
                        <span class="inline-flex items-center rounded-full border border-[var(--bp-border)] px-2.5 py-1 text-[11px] font-medium text-[var(--bp-muted)]">
                            {{ $layer }}
                        </span>
                    @endforeach
                    @if ($snapshot['badges']['language'] ?? null)
                        <span class="inline-flex items-center rounded-full border border-[var(--bp-border)] px-2.5 py-1 text-[11px] font-medium text-[var(--bp-muted)]">
                            {{ $snapshot['badges']['language'] }}
                        </span>
                    @endif
                </div>
            </header>

            {{-- ===================== CORPS ===================== --}}
            <div class="p-6 md:p-10">

                {{-- Activite. La branche est declarative : vraie meme hors
                     ligne. Les compteurs ne s'affichent que connus et non
                     nuls — « 0 tag » serait du bruit. Ni stars, ni forks, ni
                     watchers : pas encore de sens produit. --}}
                @php
                    $activite = $snapshot['activity'] ?? null;
                    $derniere = ($activite['last_activity_at'] ?? null)
                        ? \Carbon\Carbon::parse($activite['last_activity_at'])->diffForHumans(null, null, true)
                        : null;
                @endphp
                <div class="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-[var(--bp-muted)]">
                    <span class="inline-flex items-center gap-1.5 rounded-md bg-[var(--bp-panel)] px-2 py-1 font-mono text-[11px] text-[var(--bp-text)]">
                        <svg class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                            <circle cx="6" cy="6" r="2.5"/><circle cx="6" cy="18" r="2.5"/><circle cx="18" cy="8" r="2.5"/>
                            <path d="M6 8.5v7M8.5 6h4a3 3 0 0 1 3 3v.5"/>
                        </svg>
                        {{ __('open_source.activity_branch', ['branch' => config('open_source.repository.branch')]) }}
                    </span>

                    @foreach (['branches', 'tags', 'commits'] as $compteur)
                        @if (($activite[$compteur] ?? 0) > 0)
                            <span class="text-[var(--bp-disabled)]" aria-hidden="true">·</span>
                            <span>{{ __('open_source.activity_'.$compteur.($activite[$compteur] > 1 ? '_other' : '_one'), ['count' => number_format($activite[$compteur], 0, ',', "\u{202f}")]) }}</span>
                        @endif
                    @endforeach

                    @if ($derniere)
                        <span class="text-[var(--bp-disabled)]" aria-hidden="true">·</span>
                        <span>{{ __('open_source.activity_last', ['ago' => $derniere]) }}</span>
                    @endif
                </div>

                {{-- Bandeau d'etat : donnee datee, ou depot injoignable.
                     Jamais un message technique brut. --}}
                @if (! ($snapshot['available'] ?? false))
                    <p class="mt-5 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:border-amber-900/50 dark:bg-amber-950/40 dark:text-amber-200">
                        {{ __('open_source.unavailable') }}
                    </p>
                @elseif ($snapshot['stale'] ?? false)
                    <p class="mt-5 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:border-amber-900/50 dark:bg-amber-950/40 dark:text-amber-200">
                        {{ __('open_source.stale') }}
                    </p>
                @endif

                {{-- ================ RACINE DU DEPOT ================ --}}
                @if (! empty($snapshot['entries']))
                    <h2 class="mt-8 text-[11px] font-semibold uppercase tracking-[0.12em] text-[var(--bp-disabled)]">
                        {{ __('open_source.structure') }}
                    </h2>

                    <ul class="mt-3 overflow-hidden rounded-xl border border-[var(--bp-border)] divide-y divide-[var(--bp-border)]/60">
                        @foreach ($snapshot['entries'] as $entree)
                            @php $estDossier = $entree['type'] === 'dir'; @endphp
                            <li class="flex items-center gap-3 px-4 py-2.5 transition-colors hover:bg-[var(--bp-panel)]">
                                <span class="flex-shrink-0 {{ $estDossier ? 'text-[var(--bp-muted)]' : 'text-[var(--bp-disabled)]' }}" title="{{ $estDossier ? __('open_source.type_dir') : __('open_source.type_file') }}">
                                    @if ($estDossier)
                                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                            <path d="M3 6.5A1.5 1.5 0 0 1 4.5 5h4.2a1.5 1.5 0 0 1 1.06.44l1.3 1.3H19.5A1.5 1.5 0 0 1 21 8.24V17.5A1.5 1.5 0 0 1 19.5 19h-15A1.5 1.5 0 0 1 3 17.5z"/>
                                        </svg>
                                    @else
                                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" aria-hidden="true">
                                            <path d="M14 3H7a1.5 1.5 0 0 0-1.5 1.5v15A1.5 1.5 0 0 0 7 21h10a1.5 1.5 0 0 0 1.5-1.5V7.5z"/>
                                            <path d="M14 3v4.5h4.5"/>
                                        </svg>
                                    @endif
                                    <span class="sr-only">{{ $estDossier ? __('open_source.type_dir') : __('open_source.type_file') }}</span>
                                </span>

                                {{-- Telephone : le message de commit sort, le
                                     nom prend la place. Le temps relatif
                                     reste : c'est lui qui donne la sensation
                                     d'un projet vivant. --}}
                                <span class="min-w-0 flex-1 truncate font-mono text-[13px] font-medium text-[var(--bp-text)] sm:w-44 sm:flex-none">
                                    {{ $entree['name'] }}
                                </span>

                                {{-- Un fichier n'a pas de commit dans cet
                                     instantane : son message n'est ni
                                     invente, ni repris de son voisin. --}}
                                <span class="hidden min-w-0 flex-1 truncate text-xs text-[var(--bp-muted)] sm:block"
                                      @if ($entree['commit']['subject'] ?? null) title="{{ $entree['commit']['subject'] }}" @endif>
                                    {{ $entree['commit']['subject'] ?? '' }}
                                </span>

                                @if ($entree['commit']['at'] ?? null)
                                    <time class="ml-auto flex-shrink-0 pl-2 text-[11px] tabular-nums text-[var(--bp-disabled)]"
                                          datetime="{{ $entree['commit']['at'] }}">
                                        {{ \Carbon\Carbon::parse($entree['commit']['at'])->diffForHumans(null, null, true) }}
                                    </time>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif

                {{-- ===================== CTA ===================== --}}
                <div class="mt-8 flex flex-col gap-2 sm:flex-row sm:items-center">
                    {{-- Les deux CTA passent par une redirection BouclePro :
                         l'URL du depot n'apparait ni dans le HTML, ni dans la
                         barre d'etat au survol. --}}
                    <a href="{{ route('open-source.github') }}"
                       target="_blank" rel="noopener noreferrer"
                       class="inline-flex items-center justify-center gap-2 rounded-full bg-[var(--bp-text)] px-5 py-2.5 text-sm font-semibold text-[var(--bp-panel)] transition hover:opacity-90 focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--bp-primary)] focus-visible:ring-offset-2">
                        <svg class="h-4 w-4" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path fill-rule="evenodd" d="M12 2C6.477 2 2 6.484 2 12.017c0 4.425 2.865 8.18 6.839 9.504.5.092.682-.217.682-.483 0-.237-.008-.868-.013-1.703-2.782.605-3.369-1.343-3.369-1.343-.454-1.158-1.11-1.466-1.11-1.466-.908-.62.069-.608.069-.608 1.003.07 1.531 1.032 1.531 1.032.892 1.53 2.341 1.088 2.91.832.092-.647.35-1.088.636-1.338-2.22-.253-4.555-1.113-4.555-4.951 0-1.093.39-1.988 1.029-2.688-.103-.253-.446-1.272.098-2.65 0 0 .84-.27 2.75 1.026A9.564 9.564 0 0112 6.844c.85.004 1.705.115 2.504.337 1.909-1.296 2.747-1.027 2.747-1.027.546 1.379.202 2.398.1 2.651.64.7 1.028 1.595 1.028 2.688 0 3.848-2.339 4.695-4.566 4.942.359.31.678.921.678 1.856 0 1.338-.012 2.419-.012 2.747 0 .268.18.58.688.482A10.019 10.019 0 0022 12.017C22 6.484 17.522 2 12 2z" clip-rule="evenodd"/>
                        </svg>
                        {{ __('open_source.cta_github') }}
                    </a>

                    {{-- « Contribuer » n'est la que parce qu'une destination
                         canonique existe : le depot porte un CONTRIBUTING.md
                         a sa racine. --}}
                    <a href="{{ route('open-source.github', ['to' => 'contributing']) }}"
                       target="_blank" rel="noopener noreferrer"
                       class="inline-flex items-center justify-center gap-2 rounded-full border border-[var(--bp-border)] px-5 py-2.5 text-sm font-semibold text-[var(--bp-text)] transition hover:bg-[var(--bp-panel)] focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--bp-primary)] focus-visible:ring-offset-2">
                        {{ __('open_source.cta_contribute') }}
                    </a>
                </div>
            </div>
        </div>
    </x-page-container>
</x-app-layout>
