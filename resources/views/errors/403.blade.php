@php
    /**
     * TASK-1685 — la page 403 du produit.
     *
     * ## Le defaut mesure
     *
     * Il n'existait AUCUNE vue `errors/403`. Laravel rendait donc la page nue
     * du framework — titre « Forbidden », 12 549 octets, pas un mot de
     * francais, pas de marque, aucune sortie. Relevee en recette sur trois
     * routes atteintes par un membre de `main` sur l'espace `ofsh` :
     * `/org/ofsh/dossiers`, `/org/ofsh/blog/rediger/nouveau` et
     * `/org/ofsh/flux/creer`. Ces trois-la refusent par une policy
     * (`authorize()` -> AuthorizationException), la ou l'agenda et le
     * catalogue refusent par un controleur (abort 404).
     *
     * C'est exactement le defaut que TASK-1145 avait corrige pour UN ecran
     * (`dossiers.acces-refuse`, rendu en 403 par `DossierController::show()`).
     * Ce qui manquait etait le cas GENERAL.
     *
     * ## Ce que cette vue ne fait pas
     *
     * Elle ne change AUCUN statut : ce qui arrive ici est deja un 403, decide
     * par une policy ou une garde. Elle n'elargit aucune permission, et ne
     * convertit aucune autre erreur. Les 403 qui ont deja leur page — le refus
     * d'appartenance de TASK-1483, rendu par `response()->view()`, et
     * `dossiers.acces-refuse` — ne passent pas par ici : Laravel ne consulte
     * `errors/403` que pour une exception non rendue.
     *
     * ## Pourquoi aucun layout
     *
     * Meme raison qu'en TASK-1483 : `x-app-layout` est le layout du tenant
     * COURANT, c'est-a-dire ici celui dont l'acces vient d'etre refuse. Il
     * porterait son `header_javascript` en brut, son theme et son nom de
     * marque. Un refus qui s'habille avec les habits de ce qu'il refuse n'est
     * pas un refus. Cette page est donc autonome, comme `404.blade.php`.
     *
     * ## Deux visages, une seule decision
     *
     * Sur un espace ETRANGER, c'est le meme panneau que le 404 inter-tenant —
     * le partiel `errors.partials.cross-tenant` — parce qu'un membre refuse ne
     * doit pas lire deux textes differents selon que la garde qui l'a arrete
     * etait un controleur ou une policy. Ailleurs (refus DANS son propre
     * espace : un Dossier qu'on ne partage plus, un droit qu'on n'a pas), un
     * message sobre et une sortie.
     *
     * La decision vient de `CrossTenantRefusal`, l'autorite que `errors/404`
     * consulte aussi : aucune seconde ecriture du predicat, donc aucune
     * divergence possible — et aucun oracle d'existence, pour la raison
     * expliquee dans son docblock.
     */
    $crossTenantRefusal = \App\Support\Errors\CrossTenantRefusal::detect();
    $ownOrg = \App\Support\Errors\CrossTenantRefusal::ownOrganization();

    $backUrl = \App\Support\Errors\CrossTenantRefusal::ownDashboardUrl()
        ?? (auth()->check() ? route('dashboard') : url('/'));

    // Le nom affiche est celui de SON Organization, jamais celui du tenant
    // refuse — meme discipline que `errors/404` depuis TASK-1606.
    $orgName = $ownOrg?->name ?? config('app.name');
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $crossTenantRefusal ? __('errors.404_cross_tenant_title') : __('errors.403_title') }} · {{ $orgName }}</title>
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
    <div class="mx-auto w-full max-w-md px-6 py-12 text-center" data-error-page="{{ $crossTenantRefusal ? 'cross-tenant' : 'forbidden' }}">
        @if($crossTenantRefusal)
            @include('errors.partials.cross-tenant', ['backUrl' => $backUrl])
        @else
            <svg class="mx-auto mb-8 h-16 w-16 text-indigo-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" aria-hidden="true" data-error-icon="shield">
                <path stroke-linecap="round" stroke-linejoin="round" d="M11.25 4.533A9.707 9.707 0 0 0 6 3a9.735 9.735 0 0 0-3.25.555.75.75 0 0 0-.5.707v14.25a.75.75 0 0 0 1 .707A8.237 8.237 0 0 1 6 18.75c1.995 0 3.823.707 5.25 1.886V4.533ZM12.75 20.636A8.214 8.214 0 0 1 18 18.75c.966 0 1.89.166 2.75.47a.75.75 0 0 0 1-.708V4.262a.75.75 0 0 0-.5-.707A9.735 9.735 0 0 0 18 3a9.707 9.707 0 0 0-5.25 1.533v16.103Z"/>
            </svg>

            <h1 class="mb-4 text-2xl sm:text-3xl font-bold tracking-tight text-white" data-error-title>
                {{ __('errors.403_title') }}
            </h1>

            <p class="mb-10 text-base sm:text-lg leading-relaxed text-gray-400" data-error-body>
                {{ __('errors.403_message') }}
            </p>

            <a
                href="{{ $backUrl }}"
                class="inline-flex items-center gap-2 text-sm font-medium text-indigo-400 hover:text-indigo-300 transition"
                data-error-back
            >
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                </svg>
                {{ __('errors.403_back') }}
            </a>
        @endif
    </div>
</body>
</html>
