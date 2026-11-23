{{--
    TASK-1883 — desinscription de la newsletter, sans connexion (lien signe).

    Ecran aux couleurs de l'Organization DU MEMBRE (jetons de son theme) : c'est
    sa newsletter qu'il quitte, pas « BouclePro ». Le motif central est la
    boucle de la semaine : 7 jours sur un anneau, le jour de la newsletter en
    haut. Se desinscrire OUVRE l'anneau a cet endroit (le jour s'eteint) ; se
    reinscrire le referme. Seul mouvement de la page, et il repond a l'action.
    Etats : question | desinscrit | reinscrit.
--}}
@php
    $nomOrg = $organisation?->name ?? config('app.name', 'BouclePro');
    $logo = $organisation?->logo_url;
    $couleur = fn (string $cle, string $repli) => preg_match('/^#[0-9A-Fa-f]{3,8}$/', (string) ($tokens[$cle] ?? '')) ? $tokens[$cle] : $repli;
    $ouvert = $etat === 'desinscrit';
    // Anneau : r = 88, circonference ~ 552.9 ; ouvert = breche de 72 centree en haut.
    $jours = collect(range(0, 6))->map(fn ($i) => [
        'x' => 100 + 88 * sin(2 * M_PI * $i / 7),
        'y' => 100 - 88 * cos(2 * M_PI * $i / 7),
        'jour' => $i === 0,
    ]);
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex">
    <title>{{ __('newsletter.title') }} — {{ $nomOrg }}</title>
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    {{-- TASK-1737 — contrat PWA obligatoire sur tout document HTML complet. --}}
    <x-pwa-head />
    <x-pwa-boot />
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700&display=swap" rel="stylesheet">
    <style>
        :root {
            --page: {{ $couleur('page', '#F3F6FA') }};
            --panel: {{ $couleur('panel', '#FFFFFF') }};
            --primary: {{ $couleur('primary', '#4F46E5') }};
            --primary-deep: {{ $couleur('primary-deep', '#3730A3') }};
            --text: {{ $couleur('text', '#1F2937') }};
            --muted: {{ $couleur('muted', '#667085') }};
            --border: {{ $couleur('border', '#E3E7EF') }};
        }
        * { box-sizing: border-box; }
        html, body { margin: 0; }
        body {
            min-height: 100vh; min-height: 100dvh;
            display: flex; flex-direction: column; align-items: center; justify-content: center;
            padding: 48px 16px calc(32px + env(safe-area-inset-bottom, 0px));
            background: var(--page); color: var(--text);
            font: 400 16px/1.55 Figtree, ui-sans-serif, system-ui, sans-serif;
            -webkit-font-smoothing: antialiased;
        }
        .nl { width: 100%; max-width: 27rem; text-align: center; }

        .nl-boucle { width: 184px; height: 184px; margin: 0 auto 32px; position: relative; }
        .nl-boucle svg { width: 100%; height: 100%; overflow: visible; }
        .nl-anneau { fill: none; stroke: var(--primary); stroke-width: 3; stroke-linecap: round;
                     stroke-dasharray: 553 0; stroke-dashoffset: 0; transform: rotate(-90deg); transform-origin: 100px 100px; }
        .nl-jour { fill: var(--panel); stroke: var(--primary); stroke-width: 2.5; }
        .nl-jour--newsletter { fill: var(--primary); }
        .nl-centre { position: absolute; inset: 42px; border-radius: 50%; background: var(--panel);
                     display: grid; place-items: center; overflow: hidden;
                     box-shadow: 0 0 0 1px var(--border), 0 18px 40px -24px var(--primary-deep); }
        .nl-centre img { width: 68%; height: 68%; object-fit: contain; }
        .nl-centre span { font-size: 40px; font-weight: 700; color: var(--primary); letter-spacing: -0.02em; }

        /* Ouvert : breche de 72 centree en haut, le jour de la newsletter s'eteint. */
        .nl--ouvert .nl-anneau { stroke-dasharray: 481 72; stroke-dashoffset: -36; }
        .nl--ouvert .nl-jour--newsletter { opacity: 0; transform: scale(.4); }
        .nl-jour--newsletter { transform-box: fill-box; transform-origin: center; }
        .nl-anime .nl-anneau { transition: stroke-dasharray .9s cubic-bezier(.65,0,.35,1), stroke-dashoffset .9s cubic-bezier(.65,0,.35,1); }
        .nl-anime .nl-jour--newsletter { transition: opacity .45s ease .45s, transform .45s ease .45s; }

        .nl-org { margin: 0 0 6px; font-size: 15px; font-weight: 600; color: var(--primary-deep); }
        h1 { margin: 0 0 14px; font-size: clamp(26px, 6.4vw, 32px); line-height: 1.15; font-weight: 700; letter-spacing: -0.022em; text-wrap: balance; }
        .nl-texte { margin: 0 auto 28px; max-width: 24rem; color: var(--muted); text-wrap: pretty; }
        .nl-texte strong { color: var(--text); font-weight: 600; overflow-wrap: anywhere; }

        .nl-actions { display: flex; flex-direction: column; align-items: center; gap: 14px; }
        .nl-actions form { margin: 0; width: 100%; display: flex; justify-content: center; }
        .nl-bouton { min-height: 48px; min-width: 14rem; padding: 0 28px; border: 0; border-radius: 999px; cursor: pointer;
                     background: var(--primary); color: #fff; font-family: inherit; font-size: 16px; font-weight: 600; line-height: 1; }
        .nl-bouton:hover { background: var(--primary-deep); }
        .nl-bouton--doux { background: transparent; color: var(--primary-deep); box-shadow: inset 0 0 0 1.5px var(--primary); }
        .nl-bouton--doux:hover { background: var(--panel); }
        .nl-lien { color: var(--primary-deep); font-weight: 600; text-decoration: underline; text-underline-offset: 3px; text-decoration-thickness: 1px; }
        .nl-note { margin: 28px 0 0; font-size: 14px; color: var(--muted); }
        :is(.nl-bouton, .nl-lien):focus-visible { outline: 3px solid var(--primary); outline-offset: 3px; }

        .nl-pied { margin-top: 56px; font-size: 13px; color: var(--muted); }

        @media (prefers-reduced-motion: reduce) {
            .nl-anime .nl-anneau, .nl-anime .nl-jour--newsletter { transition: none; }
        }
    </style>
</head>
<body>
    <main class="nl {{ $ouvert ? 'nl--ouvert' : '' }}" id="nl" data-newsletter-unsubscribe data-etat="{{ $etat }}"
          @if(request()->isMethod('post')) data-apres-clic @endif>

        <div class="nl-boucle" aria-hidden="true">
            <svg viewBox="0 0 200 200">
                <circle class="nl-anneau" cx="100" cy="100" r="88" />
                @foreach($jours as $j)
                    <circle class="nl-jour {{ $j['jour'] ? 'nl-jour--newsletter' : '' }}" cx="{{ round($j['x'], 2) }}" cy="{{ round($j['y'], 2) }}" r="{{ $j['jour'] ? 7 : 5 }}" />
                @endforeach
            </svg>
            <div class="nl-centre">
                @if($logo)
                    <img src="{{ $logo }}" alt="">
                @else
                    <span>{{ mb_strtoupper(mb_substr($nomOrg, 0, 1)) }}</span>
                @endif
            </div>
        </div>

        <p class="nl-org">{{ $nomOrg }}</p>

        @if($etat === 'question')
            <h1>{{ __('newsletter.question_title', ['org' => $nomOrg]) }}</h1>
            <p class="nl-texte">{!! __('newsletter.question_text', ['org' => e($nomOrg), 'email' => '<strong>'.e($compte->email).'</strong>']) !!}</p>
            <div class="nl-actions">
                <form method="POST" action="{{ $urlConfirmer }}">
                    @csrf
                    <button type="submit" class="nl-bouton" data-newsletter-unsubscribe-confirm>{{ __('newsletter.confirm_button') }}</button>
                </form>
                <a href="{{ $accueil }}" class="nl-lien">{{ __('newsletter.keep_link') }}</a>
            </div>
            <p class="nl-note">{{ __('newsletter.scope_note') }}</p>
        @elseif($etat === 'desinscrit')
            <h1 data-newsletter-unsubscribed>{{ __('newsletter.done_title') }}</h1>
            <p class="nl-texte">{!! __('newsletter.done', ['org' => e($nomOrg), 'email' => '<strong>'.e($compte->email).'</strong>']) !!}</p>
            <div class="nl-actions">
                <form method="POST" action="{{ $urlAnnuler }}">
                    @csrf
                    <button type="submit" class="nl-bouton nl-bouton--doux" data-newsletter-resubscribe>{{ __('newsletter.undo_button') }}</button>
                </form>
                <a href="{{ $accueil }}" class="nl-lien">{{ __('newsletter.back_link', ['org' => $nomOrg]) }}</a>
            </div>
            <p class="nl-note">{{ __('newsletter.done_hint') }}</p>
        @else
            <h1 data-newsletter-resubscribed>{{ __('newsletter.resubscribed_title') }}</h1>
            <p class="nl-texte">{!! __('newsletter.resubscribed', ['org' => e($nomOrg), 'email' => '<strong>'.e($compte->email).'</strong>']) !!}</p>
            <div class="nl-actions">
                <a href="{{ $accueil }}" class="nl-lien">{{ __('newsletter.back_link', ['org' => $nomOrg]) }}</a>
            </div>
        @endif

        <p class="nl-pied">{{ config('app.name', 'BouclePro') }}</p>
    </main>

    <script>
        // L'anneau part de l'etat PRECEDENT et rejoint le nouveau : il s'ouvre
        // juste apres « Me desinscrire », se referme apres « Me reinscrire ».
        // Le serveur pose deja l'etat final : sans JS, ou en rouvrant le lien, rien ne bouge.
        (function () {
            var nl = document.getElementById('nl');
            if (!nl.hasAttribute('data-apres-clic')) return;
            var ouvrir = nl.dataset.etat === 'desinscrit';
            nl.classList.toggle('nl--ouvert', !ouvrir);
            requestAnimationFrame(function () {
                requestAnimationFrame(function () {
                    nl.classList.add('nl-anime');
                    nl.classList.toggle('nl--ouvert', ouvrir);
                });
            });
        })();
    </script>
</body>
</html>
