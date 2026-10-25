<?php
/*
 * TASK-1700 — « À propos » de l'Organization OFSH.
 *
 * ## Pourquoi un fichier, et pas une traduction de `about-launchpals`
 *
 * `about-launchpals` raconte ArtSciLab : son village numérique, sa mémoire
 * vivante, ses alumni. Le traduire aurait donné un texte français qui parle
 * d'autre chose. La TASK le dit en toutes lettres : « ne pas faire une simple
 * traduction de LaunchPals ».
 *
 * Ce qui est REPRIS d'elle, en revanche, c'est son ARCHITECTURE, et cela fait
 * l'essentiel de ce fichier : document autonome (aucun layout applicatif),
 * pont de sections pleine hauteur, navigation par ancres fixée à droite qui
 * suit le défilement, logo fixe, barre basse de découverte sur téléphone, et
 * le même `IntersectionObserver` pour éclairer la rubrique courante.
 *
 * ## L'aiguillage n'est pas ici
 *
 * `OrganizationLandingController::about()` choisit entre ce fichier et
 * `about-launchpals` sur `homepage_template`. `main` et `launchpals` ne
 * passent donc jamais par ici, et leur page n'a pas bougé d'un octet.
 *
 * ## Les quatre intentions sont les MÊMES qu'à l'accueil
 *
 * Mêmes libellés (`ofsh.card_*_title`, partagés avec `ofsh-hero`), mêmes
 * quatre couleurs dans le même ordre, mêmes icônes, mêmes destinations. Une
 * carte reconnue sur la page d'accueil doit se retrouver ici : c'est le même
 * geste, pas une illustration qui lui ressemble. Les libellés ne sont pas
 * recopiés — ils sont LUS aux mêmes clefs, donc une personnalisation
 * d'Organization vaut pour les deux pages d'un coup.
 *
 * ## Toutes les URL sont bornées
 *
 * Aucune route globale. Ce gabarit suit l'Organization qui a choisi le
 * gabarit OFSH, quelle qu'elle soit — doctrine TASK-1602 / 1604.
 */

$showInternalHomepageContent = showsOrganizationInternalNavigation();

// Les destinations des quatre intentions, et la règle qui les gouverne : la
// surface réelle pour un membre, l'invitation à entrer pour un visiteur. Même
// règle qu'à l'accueil, et c'est volontaire — les deux pages proposent le même
// geste, elles doivent mener au même endroit.
$porte = fn (string $route): string => $showInternalHomepageContent
    ? route($route, ['organization' => $organization->slug])
    : route('organization.login', ['organization' => $organization->slug]);

$intentions = [
    ['help', 'card_help_title', 'about_s4_i1_text', $porte('organization.explorer')],
    ['offer', 'card_offer_title', 'about_s4_i2_text', $showInternalHomepageContent
        ? route('organization.explorer', ['organization' => $organization->slug]).'?tab=requests'
        : route('organization.login', ['organization' => $organization->slug])],
    ['share', 'card_share_title', 'about_s4_i3_text', $porte('organization.blog.index')],
    ['together', 'card_together_title', 'about_s4_i4_text', $porte('organization.loops.index')],
];

$icones = [
    'help' => '<circle cx="12" cy="12" r="9"/><path d="M9.5 9a2.8 2.8 0 0 1 5.2 1.4c0 2-2.7 2.3-2.7 4.2"/><path d="M12 17.7h.01"/>',
    'offer' => '<path d="M20.8 8.6c0 4.2-8.8 9.7-8.8 9.7S3.2 12.8 3.2 8.6a4.6 4.6 0 0 1 8.8-1.7 4.6 4.6 0 0 1 8.8 1.7z"/>',
    'share' => '<path d="M12 6.2C9.4 4.8 6.4 4.8 3 5.4v12.2c3.4-.6 6.4-.6 9 1 2.6-1.6 5.6-1.6 9-1V5.4c-3.4-.6-6.4-.6-9 .8z"/><path d="M12 6.2v12.4"/>',
    'together' => '<circle cx="9" cy="8" r="2.5"/><circle cx="17.5" cy="9" r="2"/><path d="M3.5 19v-1.4A5.5 5.5 0 0 1 9 12.2a5.5 5.5 0 0 1 5.5 5.4V19H3.5z"/><path d="M16 13.4a4.3 4.3 0 0 1 4.5 4.2V19h-4"/>',
];

// Les six réalités du métier (section 2) et les quatre valeurs (section 3).
// Déclarées ici plutôt que déroulées dans le balisage : six et quatre blocs
// identiques recopiés à la main divergent au premier ajustement.
$realites = ['1' => '#097FD5', '2' => '#158C68', '3' => '#FFC247', '4' => '#F26A35', '5' => '#097FD5', '6' => '#158C68'];
$valeurs = ['1' => '#097FD5', '2' => '#158C68', '3' => '#FFC247', '4' => '#F26A35'];

$rubriques = [
    's-intro' => 'about_nav_1',
    's-purpose' => 'about_nav_2',
    's-values' => 'about_nav_3',
    's-ways' => 'about_nav_4',
    's-loops' => 'about_nav_5',
    's-quiet' => 'about_nav_6',
    's-ai' => 'about_nav_7',
    's-join' => 'about_nav_8',
];
?>
<!DOCTYPE html>
{{-- TASK-1471 : la clé de thème est posée côté SERVEUR. --}}
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-bp-theme="{{ bp_organization_theme_key($organization) }}">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>{{ org_trans('ofsh.about_meta_title') }} — {{ $organization->name }}</title>
<meta name="description" content="{{ org_trans('ofsh.about_s1_p1') }}">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="{{ asset('css/ofsh-about.css') }}?v={{ filemtime(public_path('css/ofsh-about.css')) }}">
{{-- Le MÊME producteur de jetons que les layouts, et le même volet sombre
     système qu'à l'accueil : les deux pages d'OFSH se ressemblent dans les
     deux modes. --}}
<x-theme-tokens system-dark />
</head>
<body>

<a class="of-logo" href="{{ route('organization.home', ['organization' => $organization->slug]) }}" aria-label="{{ $organization->name }}">
  <img src="{{ asset('img/ofsh/ofsh-header-light.png') }}"
       srcset="{{ asset('img/ofsh/ofsh-header-light.png') }} 1x, {{ asset('img/ofsh/ofsh-header-light@2x.png') }} 2x"
       alt="{{ org_trans('ofsh.logo_alt') }}" width="191" height="55" data-ofsh-logo-light>
  <img src="{{ asset('img/ofsh/ofsh-header-dark.png') }}"
       srcset="{{ asset('img/ofsh/ofsh-header-dark.png') }} 1x, {{ asset('img/ofsh/ofsh-header-dark@2x.png') }} 2x"
       alt="{{ org_trans('ofsh.logo_alt') }}" width="191" height="55" data-ofsh-logo-dark>
</a>

<nav class="of-nav" aria-label="{{ org_trans('ofsh.nav_menu') }}">
  @foreach($rubriques as $ancre => $clef)
    <a href="#{{ $ancre }}" class="of-nav__link" data-of-nav>{{ org_trans('ofsh.'.$clef) }}</a>
  @endforeach
</nav>

<main class="of-deck" id="main">

  {{-- 1 ─ Introduction --}}
  <section class="of-panel of-panel--intro" id="s-intro">
    <div class="of-panel__content">
      <p class="of-kicker">{{ org_trans('ofsh.about_s1_kicker') }}</p>
      <h1 class="of-title of-title--hero">{{ org_trans('ofsh.about_s1_title') }}</h1>
      <p class="of-text">{{ org_trans('ofsh.about_s1_p1') }}</p>
      <p class="of-text">{{ org_trans('ofsh.about_s1_p2') }}</p>
      <p class="of-punch">{{ org_trans('ofsh.about_s1_punch') }}</p>
    </div>
  </section>

  {{-- 2 ─ Notre raison d'être --}}
  <section class="of-panel of-panel--purpose" id="s-purpose">
    <div class="of-panel__content">
      <p class="of-kicker">{{ org_trans('ofsh.about_s2_kicker') }}</p>
      <h2 class="of-title">{{ org_trans('ofsh.about_s2_title') }}</h2>
      <p class="of-text">{{ org_trans('ofsh.about_s2_p1') }}</p>

      {{-- Six réalités du métier, nommées sans dramatisation : la TASK demande
           explicitement de ne pas les présenter de manière alarmiste. Chacune
           est suivie de ce que le collectif y apporte. --}}
      <div class="of-grid of-grid--3">
        @foreach($realites as $rang => $teinte)
          <article class="of-card" style="--puce: {{ $teinte }}">
            <h3 class="of-card__title">{{ org_trans('ofsh.about_s2_item_'.$rang.'_title') }}</h3>
            <p class="of-card__text">{{ org_trans('ofsh.about_s2_item_'.$rang.'_text') }}</p>
          </article>
        @endforeach
      </div>

      <p class="of-text">{{ org_trans('ofsh.about_s2_p2') }}</p>
    </div>
  </section>

  {{-- 3 ─ Nos valeurs --}}
  <section class="of-panel of-panel--values" id="s-values">
    <div class="of-panel__content">
      <p class="of-kicker">{{ org_trans('ofsh.about_s3_kicker') }}</p>
      <h2 class="of-title">{{ org_trans('ofsh.about_s3_title') }}</h2>

      <div class="of-grid of-grid--4">
        @foreach($valeurs as $rang => $teinte)
          <article class="of-card of-value" style="--teinte: {{ $teinte }}">
            <span class="of-value__num">0{{ $rang }}</span>
            <h3 class="of-value__title">{{ org_trans('ofsh.about_s3_v'.$rang.'_title') }}</h3>
            <p class="of-card__text">{{ org_trans('ofsh.about_s3_v'.$rang.'_text') }}</p>
          </article>
        @endforeach
      </div>
    </div>
  </section>

  {{-- 4 ─ Comment participer : le passage visuel fort de la page --}}
  <section class="of-panel of-panel--ways" id="s-ways">
    <div class="of-panel__content">
      <p class="of-kicker">{{ org_trans('ofsh.about_s4_kicker') }}</p>
      <h2 class="of-title">{{ org_trans('ofsh.about_s4_title') }}</h2>
      <p class="of-text">{{ org_trans('ofsh.about_s4_lead') }}</p>

      <div class="of-ways">
        @foreach($intentions as [$clef, $titre, $texte, $url])
          <a class="of-way of-way--{{ $clef }}" href="{{ $url }}" data-of-way="{{ $clef }}">
            <span class="of-way__symbol" aria-hidden="true">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">{!! $icones[$clef] !!}</svg>
            </span>
            <span>
              <span class="of-way__title">{{ org_trans('ofsh.'.$titre) }}</span>
              <span class="of-way__text">{{ org_trans('ofsh.'.$texte) }}</span>
            </span>
          </a>
        @endforeach
      </div>
    </div>
  </section>

  {{-- 5 ─ La force des Boucles --}}
  <section class="of-panel of-panel--loops" id="s-loops">
    <div class="of-panel__content">
      <p class="of-kicker">{{ org_trans('ofsh.about_s5_kicker') }}</p>
      <h2 class="of-title">{{ org_trans('ofsh.about_s5_title') }}</h2>
      <p class="of-text">{{ org_trans('ofsh.about_s5_p1') }}</p>
      <p class="of-text">{{ org_trans('ofsh.about_s5_p2') }}</p>

      <div class="of-pills">
        @for($i = 1; $i <= 6; $i++)
          <span class="of-pill">{{ org_trans('ofsh.about_s5_t'.$i) }}</span>
        @endfor
      </div>
    </div>
  </section>

  {{-- 6 ─ Une autre manière de communiquer --}}
  <section class="of-panel of-panel--quiet" id="s-quiet">
    <div class="of-panel__content">
      <p class="of-kicker">{{ org_trans('ofsh.about_s6_kicker') }}</p>
      <h2 class="of-title">{{ org_trans('ofsh.about_s6_title') }}</h2>
      <p class="of-text">{{ org_trans('ofsh.about_s6_p1') }}</p>

      <ul class="of-list">
        @for($i = 1; $i <= 5; $i++)
          <li>{{ org_trans('ofsh.about_s6_l'.$i) }}</li>
        @endfor
      </ul>
    </div>
  </section>

  {{-- 7 ─ Une technologie au service de l'humain --}}
  <section class="of-panel of-panel--ai" id="s-ai">
    <div class="of-panel__content">
      <p class="of-kicker">{{ org_trans('ofsh.about_s7_kicker') }}</p>
      <h2 class="of-title">{{ org_trans('ofsh.about_s7_title') }}</h2>
      <p class="of-text">{{ org_trans('ofsh.about_s7_p1') }}</p>
      <p class="of-text">{{ org_trans('ofsh.about_s7_p2') }}</p>

      {{-- La règle fondamentale, détachée du corps de texte : c'est un
           engagement, pas un argument parmi d'autres. --}}
      <p class="of-rule" data-of-rule>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>
        <span>{{ org_trans('ofsh.about_s7_rule') }}</span>
      </p>
    </div>
  </section>

  {{-- 8 ─ Rejoindre le collectif --}}
  <section class="of-panel of-panel--join" id="s-join">
    <div class="of-panel__content">
      <p class="of-kicker">{{ org_trans('ofsh.about_s8_kicker') }}</p>
      <h2 class="of-title">{{ org_trans('ofsh.about_s8_title') }}</h2>
      <p class="of-text">{{ org_trans('ofsh.about_s8_p1') }}</p>
      <p class="of-text">{{ org_trans('ofsh.about_s8_p2') }}</p>
      <p class="of-text">{{ org_trans('ofsh.about_s8_p3') }}</p>
      <p class="of-punch">{{ org_trans('ofsh.about_s8_punch') }}</p>

      <div class="of-actions">
        {{-- Le CTA principal : rejoindre pour un visiteur, le tableau de bord
             pour quelqu'un qui est déjà entré. Proposer « Rejoindre » à un
             membre serait une impasse polie — même arbitrage qu'à l'accueil. --}}
        @guest
          <a href="{{ route('organization.register', ['organization' => $organization->slug]) }}" class="of-button of-button--primary" data-of-cta-join>{{ org_trans('ofsh.about_cta_join') }}
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14m-6-6 6 6-6 6"/></svg>
          </a>
        @else
          <a href="{{ route('dashboard') }}" class="of-button of-button--primary" data-of-cta-join>{{ org_trans('ofsh.nav_dashboard') }}
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14m-6-6 6 6-6 6"/></svg>
          </a>
        @endguest

        <a href="{{ $porte('organization.loops.index') }}" class="of-button of-button--secondary" data-of-cta-loops>{{ org_trans('ofsh.about_cta_loops') }}
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg>
        </a>
      </div>
    </div>
  </section>

  {{-- TASK-1698 — le pied, en entier et borné à l'Organization de l'URL. --}}
  <footer class="of-footer">
    <span>{{ org_trans('ofsh.powered_by') }}</span>
    <div class="of-footer__links">
      {{-- Pas de lien de retour vers l'accueil ICI : le logo fixe, en haut a
           gauche, y mene deja. TASK-1698 avait retire exactement ce doublon du
           pied de la page d'accueil — « un lien BouclePro qui ne sert a rien ».
           Ne pas le reintroduire sous un autre nom. --}}
      <a href="{{ route('organization.mycelium', ['organization' => $organization->slug]) }}" data-footer-mycelium>{{ __('mycelium.footer_link') }}</a>
      <a href="{{ route('organization.flowchart', ['organization' => $organization->slug]) }}" data-footer-flowchart>{{ __('footer.flowchart') }}</a>
      <a href="{{ route('organization.mentions-legales', ['organization' => $organization->slug]) }}">{{ __('footer.mentions_legales') }}</a>
      <a href="{{ route('organization.open-source', ['organization' => $organization->slug]) }}" data-footer-opensource>{{ __('footer.opensource') }}</a>
      <a href="{{ route('organization.bug-reports.index', ['organization' => $organization->slug]) }}" data-footer-bug>{{ __('footer.bug') }}</a>
      <span>{{ config('app.version') }}</span>
    </div>
  </footer>
</main>

{{-- La rubrique courante s'éclaire au défilement. Repris de la page « À
     propos » existante, avec un seul changement : le défilement se fait sur
     `.of-deck` en desktop et sur la FENÊTRE en dessous de 900px, où le pont
     n'est plus un conteneur à défilement propre. Observer le mauvais des deux
     rendrait la navigation inerte — mais elle est masquée à cette largeur,
     donc le script s'arrête là.

     Ce commentaire est en Blade et non en JavaScript : il reste dans la
     source et ne part PAS dans le navigateur. Mesure faite — en JavaScript, il
     y expédiait sept lignes à chaque visiteur, et sa mention du gabarit
     d'origine faisait rougir la garde « aucune trace de LaunchPals ». --}}
<script>
(function () {
    var liens = document.querySelectorAll('[data-of-nav]');

    if (!liens.length) {
        return;
    }

    var carte = {};
    liens.forEach(function (l) { carte[l.getAttribute('href').slice(1)] = l; });

    var observateur = new IntersectionObserver(function (entrees) {
        entrees.forEach(function (e) {
            if (!e.isIntersecting) {
                return;
            }
            liens.forEach(function (l) { l.classList.remove('of-nav__link--active'); });
            var lien = carte[e.target.id];
            if (lien) { lien.classList.add('of-nav__link--active'); }
        });
    }, { threshold: 0.3 });

    document.querySelectorAll('.of-panel').forEach(function (s) { observateur.observe(s); });

    var pont = document.querySelector('.of-deck');

    liens.forEach(function (l) {
        l.addEventListener('click', function (ev) {
            var cible = document.getElementById(this.getAttribute('href').slice(1));

            if (!cible || !pont || pont.scrollHeight <= pont.clientHeight) {
                return; // Le pont ne défile pas lui-même : l'ancre native suffit.
            }

            ev.preventDefault();
            pont.scrollTo({
                top: cible.getBoundingClientRect().top + pont.scrollTop - pont.getBoundingClientRect().top,
                behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth',
            });
        });
    });
})();
</script>

{{-- TASK-1609 — la barre basse de découverte, partagée avec « Mycélium » et
     « Logigramme ». Téléphone uniquement, connecté ou non. --}}
<x-discovery-bottom-nav :organization="$organization" />
</body>
</html>
