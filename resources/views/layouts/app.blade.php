<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"
      class="{{ ($globalColorMode ?? 'dark') === 'dark' ? 'dark' : '' }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        <meta name="theme-color" content="#1B1FCC">
        <meta name="apple-mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        @auth
        <meta name="user-id" content="{{ auth()->id() }}">
        @endauth

        {{-- TASK-1610 — identite de la page : titre, description, apercu social.

             Trois defauts mesures, corriges ici et ICI SEULEMENT (c'est la
             seule source vivante : les 22 autres `name="description"` du depot
             sont des CHAMPS DE FORMULAIRE, et `layouts/guest` n'a qu'un titre).

             1. La description de repli portait l'ancien positionnement du
                produit (l'echange de competences entre professionnels).

                Ce texte n'est volontairement PAS recopie ici : une garde de
                TASK-1610 balaye les sources servies et refuserait le fichier.
                La citation exacte vit dans le TASK file, qui n'est pas servi.
             2. Le bloc OG etait enveloppe dans `@isset($ogTitle)` : une page
                qui n'en definit pas — le logigramme, par exemple — n'emettait
                AUCUNE balise `og:*`. WhatsApp se rabattait alors sur la
                `meta description`, donc sur l'ancien slogan. C'est la cause
                exacte de l'apercu signale sur `/org/launchpals/flowchart`.
             3. Le titre ignorait l'Organization.

             Les valeurs posees par une page restent PRIORITAIRES : ces trois
             variables ne sont que des replis. --}}
        @php
            $bpNomPlateforme = config('app.name', 'Entraide');

            // `brandOrganizationName` est deja partage avec TOUTES les vues par
            // le `View::composer('*')` d'AppServiceProvider : aucune
            // architecture nouvelle. Mais il retombe sur l'Organization PAR
            // DEFAUT hors contexte scope, et celle-ci se nomme « BouclePro » —
            // d'ou « BouclePro | BouclePro » si on ne s'en garde pas.
            $bpOrganisation = $brandOrganizationName ?? null;
            $bpOrganisationDistincte = filled($bpOrganisation)
                && mb_strtolower(trim($bpOrganisation)) !== mb_strtolower(trim($bpNomPlateforme));

            // « Logigramme · LaunchPals | BouclePro », sinon « Logigramme | BouclePro ».
            // La LANGUE du titre de page reste celle de la page : on ne compose
            // que le gabarit, jamais le libelle.
            $bpTitrePage = isset($title) && filled($title) ? trim($title) : null;
            $bpTitre = collect([
                    $bpTitrePage,
                    $bpOrganisationDistincte ? $bpOrganisation : null,
                ])->filter()->implode(' · ');
            $bpTitre = filled($bpTitre) ? $bpTitre.' | '.$bpNomPlateforme : $bpNomPlateforme;

            $bpDescription = isset($description) && filled($description)
                ? $description
                : 'Intelligence augmented by your peers.';
        @endphp

        <title>{{ $bpTitre }}</title>
        <meta name="description" content="{{ $bpDescription }}">

        {{-- L'apercu social est emis SANS CONDITION : c'est precisement son
             absence qui laissait les reseaux inventer un resume. --}}
        <meta property="og:title" content="{{ $ogTitle ?? $bpTitre }}">
        <meta property="og:description" content="{{ $ogDescription ?? $bpDescription }}">
        <meta property="og:type" content="website">
        <meta property="og:url" content="{{ url()->current() }}">
        <meta property="og:site_name" content="{{ $bpNomPlateforme }}">
        <meta property="og:image" content="{{ $ogImage ?? asset('brand/bouclepro-symbol-64.png') }}">

        <meta name="twitter:card" content="summary">
        <meta name="twitter:title" content="{{ $ogTitle ?? $bpTitre }}">
        <meta name="twitter:description" content="{{ $ogDescription ?? $bpDescription }}">
        <meta name="twitter:image" content="{{ $ogImage ?? asset('brand/bouclepro-symbol-64.png') }}">
        @isset($jsonLd)
        <script type="application/ld+json">{!! $jsonLd !!}</script>
        @endisset

        <!-- Favicon BouclePro -->
        <link rel="icon" type="image/svg+xml" href="/favicon.svg" />
        <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png" />
        <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png" />
        <link rel="shortcut icon" href="/favicon.ico" />
        <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png" />
        <meta name="apple-mobile-web-app-title" content="BouclePro" />
        <link rel="manifest" href="/site.webmanifest" />

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        {{-- TASK-1471 : la MEME source que le composant `theme-tokens` et que
             `layouts/org-admin` — le bloc de chargement etait recopie ici. --}}
        @php
            $bp = bp_themes();
            $bpThemes = $bp['themes'];
            $bpDefaultTheme = $bp['default'];
        @endphp

        <!-- Scripts -->
        <script>
            window.bpThemes = @json(collect($bpThemes)->map(fn ($theme) => ['label' => $theme['label']])->all());
            window.bpDefaultTheme = @json($bpDefaultTheme);
            var orgThemeKey = @json(optional($currentOrganization ?? null)?->theme?->key) || window.bpDefaultTheme;
            document.documentElement.dataset.bpTheme = localStorage.bpTheme || orgThemeKey;

            if (localStorage.theme === 'dark' || (!('theme' in localStorage) && @json($globalColorMode ?? 'dark') === 'dark')) {
                document.documentElement.classList.add('dark');
            } else {
                document.documentElement.classList.remove('dark');
            }
        </script>
        @vite(['resources/css/app.css', 'resources/js/app.js'])

        @php
            $org = app()->bound('current_organization') ? app('current_organization') : null;
        @endphp
        @if($org && $org->header_javascript_enabled && $org->header_javascript)
            {!! $org->header_javascript !!}
        @endif

        @stack('head')

        {{-- TASK-1471 : le producteur unique des variables CSS de theme. --}}
        <x-theme-tokens />

        <style>
            /* Mobile safe areas */
            .mobile-safe-top { padding-top: 0; }
            .mobile-safe-bottom-auth { padding-bottom: 0; }
            @media (max-width: 767px) {
                .mobile-safe-top { padding-top: calc(3.5rem + env(safe-area-inset-top, 0px)); }
                .mobile-safe-bottom-auth { padding-bottom: calc(4rem + env(safe-area-inset-bottom, 0px)); }
            }
        </style>
    </head>
    <body class="font-sans antialiased">
        {{-- Admin impersonation banner --}}
        @if(session('admin_original_id'))
        <div class="bg-amber-500 text-amber-950 px-4 py-2 text-sm font-medium flex items-center justify-center gap-3">
            <span>Connecté sous <strong>{{ auth()->user()->full_name }}</strong> (mode admin)</span>
            <a href="{{ route('admin.back-to-admin') }}"
               class="inline-flex items-center gap-1 px-3 py-1 bg-amber-700 text-white rounded-lg text-xs font-semibold hover:bg-amber-800 transition">
                Retour au compte admin
            </a>
        </div>
        @endif

        {{-- Mobile shell (hidden md:block) --}}
        <x-mobile-topbar title="{{ isset($title) && filled($title) ? $title : config('app.name') }}" :brand-name="$brandOrganizationName ?? null" />
        <x-mobile-bottom-nav />
        <x-mobile-fab />
        {{-- TASK-1231 : FAB « BouclePro IA » — layout membre uniquement (jamais
             guest / admin / org-admin). Contexte calcule cote serveur. --}}
        <x-ai-fab />
        {{-- TASK-1315 : le Shell « BouclePro IA ». Monte ici, donc remonte a
             chaque page — l'application ne fait aucune navigation SPA. Ce qui
             survit a la navigation est le FIL, relu en base a chaque montage,
             pas l'etat de ce composant.

             Le montage est CONDITIONNE : sur une page dont l'objet a ete refuse
             a l'utilisateur, le Shell ne se monte pas du tout. Monter un
             composant Livewire y inscrirait son instantane, dont `memo.path` —
             l'URL courante, qui porte l'identifiant refuse (TASK-1145). --}}
        @auth
        @if(app(\App\Support\Ai\AiFabContext::class)->shouldMountShell(request(), auth()->user()))
        <livewire:ai-shell />
        @endif
        @endauth

        <x-app-side-nav />

        <div class="min-h-screen flex flex-col bg-[var(--bp-page)] pt-0 md:pl-20 pb-0 md:pb-0 mobile-safe-top mobile-safe-bottom-auth">

            <!-- Page Heading -->
            @isset($header)
                <header class="bg-white dark:bg-gray-800 shadow">
                    <div class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8">
                        {{ $header }}
                    </div>
                </header>
            @endisset

            <!-- Page Content -->
            <main class="flex-1 md:min-h-screen">
                @hasSection('content')
                    @yield('content')
                @else
                    {{ $slot ?? '' }}
                @endif
            </main>

            
        </div>
        <!-- Toast notifications globales -->
        @if((session('success') && session('success') !== 'Message envoyé.') || session('error') || session('info'))
        <div x-data="{ show: true }" x-show="show"
             x-init="setTimeout(() => show = false, 4500)"
             x-transition:enter="transition ease-out duration-300"
             x-transition:enter-start="opacity-0 translate-y-3"
             x-transition:enter-end="opacity-100 translate-y-0"
             x-transition:leave="transition ease-in duration-200"
             x-transition:leave-start="opacity-100 translate-y-0"
             x-transition:leave-end="opacity-0 translate-y-3"
             class="fixed bottom-5 right-5 z-50 max-w-sm w-full shadow-xl"
             x-cloak>
            @if(session('success'))
            <div class="flex items-center gap-3 bg-green-600 text-white px-4 py-3 rounded-xl">
                <svg class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                <p class="text-sm font-medium flex-1">{{ session('success') }}</p>
                <button @click="show = false" class="opacity-70 hover:opacity-100 text-xl leading-none">&times;</button>
            </div>
            @elseif(session('error'))
            <div class="flex items-center gap-3 bg-red-600 text-white px-4 py-3 rounded-xl">
                <svg class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                <p class="text-sm font-medium flex-1">{{ session('error') }}</p>
                <button @click="show = false" class="opacity-70 hover:opacity-100 text-xl leading-none">&times;</button>
            </div>
            @elseif(session('info'))
            <div class="flex items-center gap-3 bg-indigo-600 text-white px-4 py-3 rounded-xl">
                <svg class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                <p class="text-sm font-medium flex-1">{{ session('info') }}</p>
                <button @click="show = false" class="opacity-70 hover:opacity-100 text-xl leading-none">&times;</button>
            </div>
            @endif
        </div>
        @endif

        @stack('scripts')

        <livewire:styles />

        <livewire:scripts />
    </body>
</html>
