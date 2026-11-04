{{-- TASK-1749 — la page de maintenance d'une Organization.

     ## Pourquoi elle n'utilise AUCUN layout

     Meme raison qu'`organization-member-required` (T1483), et elle vaut ici
     encore plus : `x-app-layout` est le layout du tenant COURANT, c'est-a-dire
     precisement celui qu'on est en train de fermer. Il monterait son
     `header_javascript` rendu en BRUT, son rail de navigation, sa barre mobile
     et le Shell IA — toute la plateforme qu'on vient de declarer indisponible.

     Une page qui annonce une maintenance en chargeant la machinerie qu'elle
     dit arretee n'annonce rien.

     Elle est donc autonome, comme `404` et le 403 de T1483, et n'emprunte
     AUCUNE variable partagee : le symbole est celui de la plateforme, jamais
     `$brandLogoUrl`.

     ## Le nom du tenant

     Passe par le middleware, et seulement si l'Organization est PUBLIQUE :
     nommer une Organization privee a qui n'en fait pas partie confirmerait son
     existence. Le titre devient neutre sinon. --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <x-pwa-head />
    <x-pwa-boot />
    <title>{{ __('errors.org_maintenance_title') }} · {{ config('app.name') }}</title>
    {{-- `noindex` malgre le 503 : la page elle-meme n'a aucune raison d'etre
         indexee. Le 503 dit aux robots de REVENIR, ce qui est le bon signal
         pour la page qu'ils cherchaient. --}}
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
    <div class="mx-auto w-full max-w-md px-6 py-12 text-center sm:max-w-xl" data-org-maintenance>
        <img src="{{ asset('brand/bouclepro-symbol-64.png') }}" alt="" aria-hidden="true" class="mx-auto mb-8 h-20 w-20 opacity-80">

        <span class="mb-6 inline-flex items-center gap-2 rounded-full bg-amber-500/10 px-3 py-1 text-xs font-semibold uppercase tracking-wider text-amber-300">
            <span class="h-1.5 w-1.5 rounded-full bg-amber-400"></span>
            {{ __('errors.org_maintenance_badge') }}
        </span>

        <h1 class="mb-4 text-2xl font-bold tracking-tight text-white sm:text-3xl" data-org-maintenance-title>
            @if($organizationName !== null)
                {{ __('errors.org_maintenance_heading', ['organization' => $organizationName]) }}
            @else
                {{ __('errors.org_maintenance_heading_neutral') }}
            @endif
        </h1>

        <p class="mb-10 text-base leading-relaxed text-gray-400" data-org-maintenance-body>
            {{ __('errors.org_maintenance_body') }}
        </p>

        {{-- Aucun bouton « reessayer » : il rechargerait la meme page tant que
             la case est cochee, et promettrait une issue qu'on ne controle pas.
             Le seul lien propose sort vers la plateforme, qui elle fonctionne. --}}
        <a href="{{ route('home') }}"
           class="inline-flex min-h-11 items-center justify-center rounded-xl border border-gray-700 px-5 text-sm font-medium text-gray-300 transition hover:border-gray-500 hover:text-white"
           data-org-maintenance-home>
            {{ __('errors.org_maintenance_home') }}
        </a>
    </div>
</body>
</html>
