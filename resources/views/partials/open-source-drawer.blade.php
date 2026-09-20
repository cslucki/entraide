{{--
    TASK-1612 — Open Source Explorer.

    Coquille STATIQUE du drawer. Rien de ce qui vient de GitHub n'est rendu
    ici : `resources/js/open-source-drawer.js` la remplit a la premiere
    ouverture, depuis `/open-source/repository`.

    Pourquoi pas un rendu serveur direct ? Ce partial vit dans le pied de
    page, donc sur TOUTES les surfaces publiques. Lire l'instantane a chaque
    rendu de page ferait payer a une page quelconque, le jour ou le cache
    expire, une salve de 14 appels sortants. La donnee arrive donc a
    l'ouverture, avec un squelette.

    Pourquoi pas Alpine ? `layouts/guest` (connexion, inscription) inclut ce
    pied de page mais ne monte NI Livewire NI Alpine — mesure faite avant
    d'ecrire. Un `x-data` y serait inerte, et silencieusement : aucune
    erreur console, juste un bouton mort. Le drawer est donc pilote en JS
    nu depuis `app.js`, present sur les cinq surfaces.

    Pourquoi des `<template>` plutot qu'une construction de classes en JS ?
    `tailwind.config.js` ne scanne que `resources/views/**/*.blade.php` :
    une classe ecrite dans un fichier `.js` serait purgee du build. Toutes
    les classes vivent donc ici, et le JS ne fait que cloner et remplir.
--}}
@php
    // TASK-1612 — les libelles de temps relatif, traduits cote serveur et
    // transmis au JS. Le temps relatif ne peut PAS etre calcule au rendu :
    // l'instantane est cache 60 minutes, un « il y a 2 h » fige y serait
    // faux d'une heure au pire. Le navigateur le recalcule a l'ouverture,
    // a partir des dates ISO.
    $openSourceAgoLabels = [
        'now' => __('open_source.ago_now'),
        'minutes' => __('open_source.ago_minutes', ['count' => ':count']),
        'hours' => __('open_source.ago_hours', ['count' => ':count']),
        'yesterday' => __('open_source.ago_yesterday'),
        'days' => __('open_source.ago_days', ['count' => ':count']),
        'months' => __('open_source.ago_months', ['count' => ':count']),
        'years_one' => __('open_source.ago_years_one', ['count' => ':count']),
        'years_other' => __('open_source.ago_years_other', ['count' => ':count']),
    ];
@endphp

<div data-open-source-drawer
     data-os-endpoint="{{ route('open-source.repository') }}"
     data-os-locale="{{ str_replace('_', '-', app()->getLocale()) }}"
     data-os-label-stale="{{ __('open_source.stale') }}"
     data-os-label-unavailable="{{ __('open_source.unavailable') }}"
     data-os-label-ago="{{ json_encode($openSourceAgoLabels, JSON_UNESCAPED_UNICODE) }}"
     class="hidden">
    {{-- Voile. Cliquable : fermer.

         z-index 9995/9996, et ce n'est pas un nombre pris au hasard : le
         Shell invite (`#bp-guest-shell`) vit a 9990, tres au-dessus de
         l'echelle Tailwind du produit qui plafonne a 70. Mesure faite au
         navigateur avant de corriger : a z-[70], le drawer etait bien
         ouvert, bien dimensionne, bien peint — et le Shell lui passait
         devant. Le panneau doit couvrir tout ce que la page affiche, Shell
         compris. --}}
    <div data-os-backdrop
         class="fixed inset-0 z-[9995] bg-gray-900/60 opacity-0 backdrop-blur-sm transition-opacity duration-200 dark:bg-black/70"
         aria-hidden="true"></div>

    <div data-os-panel
         role="dialog"
         aria-modal="true"
         aria-labelledby="open-source-title"
         tabindex="-1"
         class="fixed inset-y-0 right-0 z-[9996] flex w-full translate-x-full flex-col bg-white text-gray-900 shadow-2xl outline-none transition-transform duration-300 ease-out sm:max-w-xl lg:max-w-3xl dark:bg-gray-950 dark:text-gray-100">

        {{-- ================= EN-TETE ================= --}}
        <header class="relative flex-shrink-0 border-b border-gray-200 px-5 pb-5 pt-6 sm:px-8 dark:border-gray-800">
            <button type="button"
                    data-os-close
                    class="absolute right-4 top-4 inline-flex h-9 w-9 items-center justify-center rounded-full text-gray-400 transition hover:bg-gray-100 hover:text-gray-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 focus-visible:ring-offset-2 dark:hover:bg-gray-800 dark:hover:text-white dark:focus-visible:ring-white dark:focus-visible:ring-offset-gray-950"
                    aria-label="{{ __('open_source.close') }}">
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                    <path d="M18 6 6 18M6 6l12 12"/>
                </svg>
            </button>

            <div class="flex items-center gap-3 pr-12">
                <span class="inline-flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-xl bg-gray-900 text-white dark:bg-white dark:text-gray-900">
                    <svg class="h-6 w-6" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path d="M8.5 16.5 4 12l4.5-4.5M15.5 7.5 20 12l-4.5 4.5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </span>
                <h2 id="open-source-title" class="text-xl font-semibold tracking-tight sm:text-2xl">
                    {{ __('open_source.title') }}
                </h2>
            </div>

            <p class="mt-3 max-w-xl text-sm leading-relaxed text-gray-500 dark:text-gray-400">
                {{ __('open_source.tagline') }}
            </p>

            {{-- Badges. « Public », la licence et la pile sont DECLARES
                 (config/open_source.php) : ils s'affichent meme quand GitHub
                 ne repond pas. Seul le langage vient de l'API — il reste
                 masque tant qu'il n'est pas connu. --}}
            <div class="mt-4 flex flex-wrap items-center gap-1.5">
                <span class="inline-flex items-center gap-1.5 rounded-full border border-gray-200 px-2.5 py-1 text-[11px] font-medium text-gray-600 dark:border-gray-700 dark:text-gray-300">
                    <span class="h-1.5 w-1.5 rounded-full bg-emerald-500" aria-hidden="true"></span>
                    {{ __('open_source.badge_public') }}
                </span>
                <span class="inline-flex items-center rounded-full border border-gray-200 px-2.5 py-1 text-[11px] font-medium text-gray-600 dark:border-gray-700 dark:text-gray-300">
                    {{ config('open_source.license') }}
                </span>
                @foreach ((array) config('open_source.stack', []) as $layer)
                    <span class="inline-flex items-center rounded-full border border-gray-200 px-2.5 py-1 text-[11px] font-medium text-gray-600 dark:border-gray-700 dark:text-gray-300">
                        {{ $layer }}
                    </span>
                @endforeach
                <span data-os-language
                      class="hidden inline-flex items-center rounded-full border border-gray-200 px-2.5 py-1 text-[11px] font-medium text-gray-600 dark:border-gray-700 dark:text-gray-300"></span>
            </div>
        </header>

        {{-- ================= CORPS ================= --}}
        <div data-os-scroll class="flex-1 overflow-y-auto overscroll-contain px-5 py-5 sm:px-8">

            {{-- Activite. La branche est declarative : elle est vraie meme
                 hors ligne. Les compteurs et la derniere activite viennent
                 de l'API et restent masques tant qu'ils sont inconnus.
                 Stars, forks et watchers sont deliberement absents : ils
                 n'ont pas encore de sens produit. --}}
            <div class="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-gray-500 dark:text-gray-400">
                <span class="inline-flex items-center gap-1.5 rounded-md bg-gray-100 px-2 py-1 font-mono text-[11px] text-gray-700 dark:bg-gray-900 dark:text-gray-200">
                    <svg class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                        <circle cx="6" cy="6" r="2.5"/><circle cx="6" cy="18" r="2.5"/><circle cx="18" cy="8" r="2.5"/>
                        <path d="M6 8.5v7M8.5 6h4a3 3 0 0 1 3 3v.5"/>
                    </svg>
                    {{ __('open_source.activity_branch', ['branch' => config('open_source.repository.branch')]) }}
                </span>

                @foreach ([
                    'branches' => ['one' => 'activity_branches_one', 'other' => 'activity_branches_other'],
                    'tags' => ['one' => 'activity_tags_one', 'other' => 'activity_tags_other'],
                    'commits' => ['one' => 'activity_commits_one', 'other' => 'activity_commits_other'],
                ] as $stat => $keys)
                    {{-- Le separateur « · » est un vrai element, pas un
                         `before:content-[…]` : une valeur arbitraire portant
                         un caractere non-ASCII est un pari inutile sur
                         l'echappement du build CSS. --}}
                    <span data-os-stat="{{ $stat }}"
                          data-label-one="{{ __('open_source.'.$keys['one'], ['count' => ':count']) }}"
                          data-label-other="{{ __('open_source.'.$keys['other'], ['count' => ':count']) }}"
                          class="hidden inline-flex items-center gap-2">
                        <span class="text-gray-300 dark:text-gray-700" aria-hidden="true">·</span>
                        <span data-os-stat-value></span>
                    </span>
                @endforeach

                {{-- `hidden` ne cohabite PAS avec un utilitaire d'affichage
                     responsive. Mesure faite au navigateur : `sm:inline-flex`
                     est ecrit APRES `hidden` dans la feuille generee (les
                     variantes `sm:` vivent dans une media query posee plus
                     bas), donc au-dessus de 640 px il l'emportait et cet
                     element restait AFFICHE — un separateur « · » orphelin
                     apres « Branche main » sur l'ecran de panne.
                     La largeur, elle, peut rester responsive : `w-full` dans
                     un parent `flex-wrap` force le retour a la ligne sans
                     toucher au `display`. --}}
                <span data-os-last-activity
                      data-label="{{ __('open_source.activity_last', ['ago' => ':ago']) }}"
                      class="hidden inline-flex w-full items-center gap-2 sm:w-auto">
                    <span class="hidden text-gray-300 sm:inline dark:text-gray-700" aria-hidden="true">·</span>
                    <span data-os-last-activity-value></span>
                </span>
            </div>

            {{-- Bandeau d'etat : donnee datee, ou depot injoignable. Jamais
                 un message technique brut. --}}
            <p data-os-notice
               class="mt-4 hidden rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:border-amber-900/50 dark:bg-amber-950/40 dark:text-amber-200"></p>

            {{-- Le bloc entier disparait quand le depot est injoignable : le
                 bandeau au-dessus dit deja pourquoi, et une boite vide qui
                 repete la meme phrase ne fait que l'affaiblir. --}}
            <div data-os-structure>
            <h3 class="mt-6 text-[11px] font-semibold uppercase tracking-[0.12em] text-gray-400 dark:text-gray-500">
                {{ __('open_source.structure') }}
            </h3>

            <div class="mt-3 overflow-hidden rounded-xl border border-gray-200 dark:border-gray-800">
                {{-- Squelette : six lignes, le temps de la premiere lecture. --}}
                <div data-os-skeleton class="animate-pulse divide-y divide-gray-100 dark:divide-gray-800/70" aria-hidden="true">
                    @for ($i = 0; $i < 6; $i++)
                        <div class="flex items-center gap-3 px-4 py-3">
                            <span class="h-4 w-4 flex-shrink-0 rounded bg-gray-200 dark:bg-gray-800"></span>
                            <span class="h-3 w-24 rounded bg-gray-200 dark:bg-gray-800"></span>
                            <span class="ml-auto h-3 w-32 rounded bg-gray-100 dark:bg-gray-800/60"></span>
                        </div>
                    @endfor
                </div>

                <ul data-os-entries class="hidden divide-y divide-gray-100 dark:divide-gray-800/70"></ul>

                {{-- Cas distinct du precedent : le depot a repondu, mais sa
                     racine ne contient rien d'affichable. Une autre phrase,
                     parce que ce n'est pas la meme situation. --}}
                <p data-os-empty class="hidden px-4 py-6 text-center text-xs text-gray-400 dark:text-gray-500">
                    {{ __('open_source.empty') }}
                </p>
            </div>
            </div>
        </div>

        {{-- ================= PIED / CTA ================= --}}
        <footer class="flex-shrink-0 border-t border-gray-200 px-5 py-4 sm:px-8 dark:border-gray-800">
            <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
                {{-- Les deux CTA pointent sur une URL BouclePro qui redirige
                     cote serveur. L'URL du depot n'apparait donc ni dans le
                     HTML, ni dans la barre d'etat au survol. --}}
                <a href="{{ route('open-source.github') }}"
                   target="_blank" rel="noopener noreferrer"
                   class="inline-flex items-center justify-center gap-2 rounded-full bg-gray-900 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-gray-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 focus-visible:ring-offset-2 dark:bg-white dark:text-gray-900 dark:hover:bg-gray-200 dark:focus-visible:ring-white dark:focus-visible:ring-offset-gray-950">
                    <svg class="h-4 w-4" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path fill-rule="evenodd" d="M12 2C6.477 2 2 6.484 2 12.017c0 4.425 2.865 8.18 6.839 9.504.5.092.682-.217.682-.483 0-.237-.008-.868-.013-1.703-2.782.605-3.369-1.343-3.369-1.343-.454-1.158-1.11-1.466-1.11-1.466-.908-.62.069-.608.069-.608 1.003.07 1.531 1.032 1.531 1.032.892 1.53 2.341 1.088 2.91.832.092-.647.35-1.088.636-1.338-2.22-.253-4.555-1.113-4.555-4.951 0-1.093.39-1.988 1.029-2.688-.103-.253-.446-1.272.098-2.65 0 0 .84-.27 2.75 1.026A9.564 9.564 0 0112 6.844c.85.004 1.705.115 2.504.337 1.909-1.296 2.747-1.027 2.747-1.027.546 1.379.202 2.398.1 2.651.64.7 1.028 1.595 1.028 2.688 0 3.848-2.339 4.695-4.566 4.942.359.31.678.921.678 1.856 0 1.338-.012 2.419-.012 2.747 0 .268.18.58.688.482A10.019 10.019 0 0022 12.017C22 6.484 17.522 2 12 2z" clip-rule="evenodd"/>
                    </svg>
                    {{ __('open_source.cta_github') }}
                </a>

                {{-- « Contribuer » n'est la que parce qu'une destination
                     canonique existe : le depot porte un CONTRIBUTING.md a
                     sa racine (verifie). Sans lui, ce bouton n'aurait mene
                     nulle part et n'aurait pas ete ajoute. --}}
                <a href="{{ route('open-source.github', ['to' => 'contributing']) }}"
                   target="_blank" rel="noopener noreferrer"
                   class="inline-flex items-center justify-center gap-2 rounded-full border border-gray-200 px-4 py-2.5 text-sm font-semibold text-gray-700 transition hover:border-gray-300 hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900 focus-visible:ring-offset-2 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-gray-900 dark:focus-visible:ring-white dark:focus-visible:ring-offset-gray-950">
                    {{ __('open_source.cta_contribute') }}
                </a>
            </div>
        </footer>
    </div>

    {{-- ================= GABARIT DE LIGNE =================
         Clone par le JS pour chaque entree de la racine. Les deux icones
         cohabitent ici ; le JS retire celle qui ne correspond pas au type.
         Aucune classe Tailwind n'est donc construite hors de ce fichier. --}}
    <template data-os-row>
        <li class="group flex items-center gap-3 px-4 py-2.5 transition-colors hover:bg-gray-50 dark:hover:bg-gray-900/60">
            <span data-os-icon="dir" class="flex-shrink-0 text-gray-400 dark:text-gray-500" title="{{ __('open_source.type_dir') }}">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                    <path d="M3 6.5A1.5 1.5 0 0 1 4.5 5h4.2a1.5 1.5 0 0 1 1.06.44l1.3 1.3H19.5A1.5 1.5 0 0 1 21 8.24V17.5A1.5 1.5 0 0 1 19.5 19h-15A1.5 1.5 0 0 1 3 17.5z"/>
                </svg>
                <span class="sr-only">{{ __('open_source.type_dir') }}</span>
            </span>
            <span data-os-icon="file" class="flex-shrink-0 text-gray-300 dark:text-gray-600" title="{{ __('open_source.type_file') }}">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" aria-hidden="true">
                    <path d="M14 3H7a1.5 1.5 0 0 0-1.5 1.5v15A1.5 1.5 0 0 0 7 21h10a1.5 1.5 0 0 0 1.5-1.5V7.5z"/>
                    <path d="M14 3v4.5h4.5"/>
                </svg>
                <span class="sr-only">{{ __('open_source.type_file') }}</span>
            </span>

            {{-- Mobile : le nom prend la place, le message de commit sort.
                 GitHub fait le meme arbitrage, et une ligne de commit
                 tronquee a douze caracteres ne dit rien a personne. Le temps
                 relatif, lui, reste : c'est lui qui donne la sensation d'un
                 projet vivant. --}}
            <span data-os-name class="min-w-0 flex-1 truncate font-mono text-[13px] font-medium text-gray-900 sm:w-44 sm:flex-none dark:text-gray-100"></span>

            <span data-os-subject class="hidden min-w-0 flex-1 truncate text-xs text-gray-500 sm:block dark:text-gray-400"></span>

            <time data-os-time class="ml-auto flex-shrink-0 pl-2 text-[11px] tabular-nums text-gray-400 dark:text-gray-500"></time>
        </li>
    </template>
</div>
