{{-- TASK-1483 — le refus qui EXPLIQUE.

     Un membre connecte qui atteignait le tableau de bord d'une AUTRE
     Organization recevait 200. Il recoit desormais 403 — mais un 404 generique
     l'aurait laisse croire a une page cassee, alors qu'il est identifie et
     qu'il a tape ce slug lui-meme.

     ## Pourquoi cette page n'utilise AUCUN layout

     `x-app-layout` est le layout du tenant COURANT — c'est-a-dire, ici, du
     tenant qu'on vient de refuser. Mesure faite, il porte :

       - `header_javascript` rendu en BRUT : du JavaScript arbitraire de
         l'Organization visee, execute dans le navigateur d'un etranger ;
       - le theme, le logo et le nom de marque de cette Organization, partages
         dans TOUTE vue par le `View::composer('*')` de `AppServiceProvider` ;
       - `x-app-side-nav`, `x-mobile-topbar` et le Shell IA, qui montent sur une
         page dont l'objet vient d'etre refuse (ce que TASK-1145 interdit).

     Un refus qui s'habille avec les habits du tenant refuse n'est pas un refus.
     Cette page est donc autonome, comme `404.blade.php`, et n'emprunte AUCUNE
     des variables partagees : le logo est celui de la plateforme, jamais
     `$brandLogoUrl`.

     ## La seule donnee du tenant qui apparait — et a quelle condition

     Son NOM, et seulement s'il est public. Nommer une Organization privee a
     quelqu'un qui n'en fait pas partie confirmerait son existence ; le
     middleware passe alors `null` et le titre devient neutre. --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>403 — {{ __('errors.org_member_required_title') }} · {{ config('app.name') }}</title>
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
    <div class="mx-auto w-full max-w-md px-6 py-12 text-center sm:max-w-xl" data-org-member-required>
        {{-- Le symbole de la PLATEFORME. `$brandLogoUrl` vaudrait ici le logo de
             l'Organization refusee. --}}
        <img src="{{ asset('brand/bouclepro-symbol-64.png') }}" alt="" aria-hidden="true" class="mx-auto mb-8 h-20 w-20 opacity-80">

        <h1 class="mb-4 text-2xl font-bold tracking-tight text-white sm:text-3xl" data-org-member-required-title>
            @if($organizationName !== null)
                {{ __('errors.org_member_required_heading', ['organization' => $organizationName]) }}
            @else
                {{ __('errors.org_member_required_heading_neutral') }}
            @endif
        </h1>

        <p class="mb-10 text-base leading-relaxed text-gray-400" data-org-member-required-body>
            {{ __('errors.org_member_required_body') }}
        </p>

        <div class="flex flex-col items-stretch gap-3 sm:flex-row sm:justify-center">
            <a href="{{ $ownSpaceUrl }}"
               class="inline-flex min-h-11 items-center justify-center rounded-xl bg-indigo-600 px-5 text-sm font-semibold text-white transition hover:bg-indigo-500 sm:whitespace-nowrap"
               data-org-member-required-own>
                {{ __('errors.org_member_required_own_space') }}
            </a>

            {{-- Propose UNIQUEMENT si cet accueil existe reellement. Mesure
                 faite : une Organization privee n'a pas de landing publique
                 (`/org/artscilab-en` rend 302, pas une page). --}}
            @if($publicHomeUrl !== null)
                <a href="{{ $publicHomeUrl }}"
                   class="inline-flex min-h-11 items-center justify-center rounded-xl border border-gray-700 px-5 text-sm font-medium text-gray-300 transition hover:border-gray-500 hover:text-white sm:whitespace-nowrap"
                   data-org-member-required-public-home>
                    {{ __('errors.org_member_required_public_home') }}
                </a>
            @endif
        </div>
    </div>
</body>
</html>
