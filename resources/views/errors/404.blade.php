@php
    // TASK-1606 — d'ou vient l'Organization de cette page d'erreur.
    //
    // LE DEFAUT. Les deux branches precedentes lisaient l'URL REFUSEE :
    // `$currentOrganization`, que `ResolveUrlOrganization` lie depuis elle, puis
    // a defaut une requete `Organization::where('slug', segment(2))`. Mesure :
    // un membre de LaunchPals refuse sur `/org/main/loops` recevait une sortie
    // vers `/org/main` — l'Organization ETRANGERE — et un titre annoncant le nom
    // de celle-ci, sans une seule mention de la sienne.
    //
    // LE CONTEXTE SUR. Pour un utilisateur AUTHENTIFIE, son Organization est
    // connue independamment de ce qu'il a demande. On la prefere, toujours :
    // aucune requete n'interroge plus la ressource refusee dans ce cas, donc
    // son existence n'est jamais un signal.
    //
    // L'INVITE NE CHANGE PAS. Il n'a pas d'Organization legitime, et l'URL
    // qu'il a demandee reste l'expression de son contexte — meme doctrine que
    // TASK-1602/1604. On ne lui invente rien.
    //
    // Le statut reste 404 et le message reste muet sur la ressource : cette
    // TASK ne touche a aucune autorisation.
    $errorOrg = auth()->user()?->organization;

    if (! $errorOrg) {
        $errorOrg = $currentOrganization ?? null;

        if (! $errorOrg && request()->segment(1) === 'org' && request()->segment(2)) {
            try {
                $errorOrg = \App\Models\Organization::where('slug', request()->segment(2))->first();
            } catch (\Exception $e) {
                //
            }
        }
    }

    $homeUrl = $errorOrg
        ? route('organization.home', ['organization' => $errorOrg])
        : (auth()->check() ? route('dashboard') : url('/'));
    $orgName = $errorOrg?->name ?? ($brandOrganizationName ?? config('app.name'));

    // TASK-1685 (Q3) — le LIBELLE d'un refus cross-tenant demontre.
    //
    // CE QUI NE CHANGE PAS. Le statut reste 404 : la doctrine TASK-1601→1606
    // l'a tranche, et un 403 dirait « il y a quelque chose ici, mais pas pour
    // vous ». La sortie vers SON Organization, le titre portant SON nom et tout
    // le reste de cette page restent ceux de T1606. Seule la PHRASE change.
    //
    // LE DEFAUT. `errors.404_message` dit « Hmm, cette boucle semble partie en
    // vacances… ». A un membre authentifie refuse sur l'espace d'une AUTRE
    // Organization, cette formule d'ambiance parle d'une Boucle — il n'y en a
    // aucune en cause — et se lit comme une panne. Constat de recette :
    // `main.member1` sur `/org/ofsh/agenda`.
    //
    // LA CONDITION, et pourquoi elle ne cree AUCUN ORACLE D'EXISTENCE
    //
    // Trois predicats cumulatifs :
    //
    //  1. l'utilisateur est AUTHENTIFIE et porte une Organization — un invite
    //     n'a pas d'espace a lui, et T1606 a deliberement laisse son cas
    //     inchange ;
    //  2. une route DECLAREE a ete resolue (`request()->route() !== null`) —
    //     c'est ce qui separe « cette page existe et n'est pas la tienne » de
    //     « cette URL n'existe pas ». Mesure : `/org/ofsh/agenda` resout
    //     `organization.events.agenda`, `/org/ofsh/page-inexistante` ne resout
    //     RIEN. Sans ce predicat, l'ecart de slug seul confondrait refus
    //     d'acces et ressource inexistante — ce que MASTER a interdit ;
    //  3. le slug porte par l'URL differe de celui de SON Organization,
    //     compare comme une CHAINE.
    //
    // Le point 3 ne fait AUCUNE lecture en base de l'Organization visee, et
    // c'est tout l'enjeu. Mesure : `/org/slug-qui-nexiste-pas/agenda` resout la
    // meme route et recoit donc EXACTEMENT le meme message que `/org/ofsh/agenda`.
    // La phrase ne distingue pas une Organization qui existe d'une qui n'existe
    // pas : elle ne divulgue donc rien de plus que le 404 d'aujourd'hui.
    //
    // Ce qui garde l'ancien message : toute URL sans route (y compris sous un
    // slug etranger), et tout refus DANS son propre espace — `/org/main/loops/<uuid
    // absent>` reste « introuvable », parce que la ressource est bien ce qui
    // manque. Les deux cas sont mesures et figes par des tests.
    $ownOrg = auth()->user()?->organization;
    $urlOrgSlug = request()->route()?->parameter('organization')
        ?? request()->route()?->parameter('community');

    if ($urlOrgSlug instanceof \App\Models\Organization) {
        $urlOrgSlug = $urlOrgSlug->slug;
    }

    $crossTenantRefusal = $ownOrg !== null
        && is_string($urlOrgSlug)
        && $urlOrgSlug !== ''
        && $urlOrgSlug !== $ownOrg->slug;

    // Le retour. Pour un refus inter-tenant, MASTER veut le TABLEAU DE BORD de
    // l'Organization du compte connecte — une destination ou l'on reprend son
    // travail, pas une page d'accueil. Pour tout autre 404, la sortie de T1606
    // ne bouge pas d'un caractere.
    $backUrl = $crossTenantRefusal && \Illuminate\Support\Facades\Route::has('organization.dashboard')
        ? route('organization.dashboard', ['organization' => $ownOrg->slug])
        : $homeUrl;
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- TASK-1685 : le titre d'onglet suit la page. Sur un refus inter-tenant,
         il ne commence plus par « 404 » : l'onglet aussi disait « panne ». Le
         nom reste celui de SON Organization (T1606). --}}
    <title>{{ $crossTenantRefusal ? __('errors.404_cross_tenant_title') : '404 — '.__('errors.404_title') }} · {{ $orgName }}</title>
    <meta name="robots" content="noindex, nofollow">
    <link rel="icon" href="{{ asset('brand/bouclepro-symbol-64.png') }}">
    @vite(['resources/css/app.css'])
    <style>
        body {
            background-color: #030712;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: Figtree, ui-sans-serif, system-ui, sans-serif;
        }
    </style>
</head>
<body>
    <div class="mx-auto w-full max-w-md px-6 py-12 text-center" data-error-page="{{ $crossTenantRefusal ? 'cross-tenant' : 'generic' }}">
        @if($crossTenantRefusal)
            {{-- TASK-1685 (UX MASTER) — un refus inter-tenant n'est pas une panne.
                 Le grand « 404 » et la formule d'ambiance faisaient lire une page
                 cassee a quelqu'un d'identifie, qui a tape ce slug lui-meme. Le
                 nombre laisse donc place a un CADENAS, et la page dit ce qui se
                 passe puis ce qu'on peut faire.

                 Le statut HTTP reste 404 (doctrine T1601-T1606) : seule la
                 presentation change. Et rien ici ne nomme l'Organization visee —
                 ni son nom, ni son slug, ni aucune de ses donnees. --}}
            <svg class="mx-auto mb-8 h-16 w-16 text-indigo-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" aria-hidden="true" data-error-icon="lock">
                <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z"/>
            </svg>

            <h1 class="mb-4 text-2xl sm:text-3xl font-bold tracking-tight text-white" data-error-title>
                {{ __('errors.404_cross_tenant_title') }}
            </h1>

            <p class="mb-3 text-base sm:text-lg leading-relaxed text-gray-300" data-error-body>
                {{ __('errors.404_cross_tenant_body') }}
            </p>

            <p class="mb-10 text-sm leading-relaxed text-gray-500" data-error-hint>
                {{ __('errors.404_cross_tenant_hint') }}
            </p>
        @else
            <img
                src="{{ $brandLogoUrl }}"
                alt="BouclePro"
                class="mx-auto w-28 h-28 sm:w-36 sm:h-36 opacity-90 mb-8"
            >

            <h1 class="text-4xl sm:text-5xl font-bold tracking-tight text-white mb-4">
                404
            </h1>

            <p class="text-lg sm:text-xl text-gray-400 leading-relaxed mb-10" data-error-message="generic">
                {{ __('errors.404_message') }}
            </p>
        @endif

        {{--
            TASK-1603 — il y avait ici un moteur de recherche (« Que cherchez-vous ? »,
            `<form action="/search" role="search">`). Retire : une page d'erreur qui
            invite a chercher promet une issue qu'elle n'a pas. Parcours reel :
            /mycelium -> /loops -> login -> 404 tenant -> l'utilisateur tente une
            recherche -> seconde frustration.

            Le moteur de recherche NORMAL du produit vit dans
            `layouts/navigation.blade.php` et n'est pas touche : cette vue est un
            document autonome, sans @extends ni @include.

            L'espacement est deja porte par le `mb-10` du paragraphe ci-dessus ;
            rien a restyler.
        --}}

        @if($crossTenantRefusal)
            {{-- Un BOUTON, pas un lien discret : c'est la seule chose a faire
                 depuis cette page, elle doit se voir. Il mene au tableau de bord
                 de SON Organization — on y reprend son travail. --}}
            <a
                href="{{ $backUrl }}"
                class="inline-flex min-h-11 items-center justify-center gap-2 rounded-xl bg-indigo-600 px-5 text-sm font-semibold text-white transition hover:bg-indigo-500"
                data-error-back
            >
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                </svg>
                {{ __('errors.404_cross_tenant_back') }}
            </a>
        @else
            <a
                href="{{ $homeUrl }}"
                class="inline-flex items-center gap-2 text-sm font-medium text-indigo-400 hover:text-indigo-300 transition"
            >
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                </svg>
                {{ __('errors.404_back_home') }}
            </a>
        @endif
    </div>
</body>
</html>
