<?php
/*
 * TASK-1699 — le gabarit d'accueil de l'Organization OFSH.
 *
 * Troisieme landing AUTONOME du produit, apres `hero-v2` et `artscilab-hero` :
 * un document HTML complet, qui n'emprunte ni `<x-app-layout>` ni le rail de
 * navigation applicatif. C'est l'idiome maison pour une vitrine, et il est
 * repris ici a l'identique — jusqu'au producteur de jetons de theme, qui est
 * le MEME que celui des layouts (`<x-theme-tokens />`, TASK-1471).
 *
 * ## Ce que ce gabarit RE-UTILISE plutot que de le reinventer
 *
 *   * `<x-theme-tokens system-dark />` — les couleurs viennent du theme `ofsh`
 *     (`config/bouclepro_themes.php`), clair ET sombre. Aucune couleur de
 *     marque n'est ecrite dans ce fichier.
 *   * `@include('partials.footer')` — non. Ce pied-la est celui des pages
 *     applicatives ; les landings autonomes portent le leur, et celui-ci est
 *     dessine par la maquette. Les memes DESTINATIONS y figurent neanmoins,
 *     bornees a l'Organization de l'URL (TASK-1698).
 *   * `org_trans('ofsh.*')` — chaque texte est surchargeable par
 *     l'Organization depuis son ecran de traductions.
 *   * `route('locale.switch')` en POST — le selecteur FR/EN des deux autres
 *     landings, au mot pres.
 *
 * ## La garde de contenu interne
 *
 * `$showInternalHomepageContent` est faux pour un visiteur anonyme et pour un
 * membre d'une AUTRE Organization (TASK-1692). Ici, il ne commande AUCUN
 * contenu : cette landing ne lit ni membre, ni atelier, ni Boucle — elle
 * n'affiche que des textes et quatre portes. Il ne commande donc que la
 * DESTINATION de ces portes, et c'est son seul role dans ce fichier.
 *
 * ## Les quatre cartes : libelle et destination disent la MEME chose
 *
 * C'est la lecon de TASK-1608, qui avait trouve l'inverse sur `hero-v2` —
 * « J'explore une piste » menait a l'Annuaire. Le mapping est donc ecrit ici
 * une fois, en clair :
 *
 *   | carte | pour un membre | pour un visiteur |
 *   |---|---|---|
 *   | J'ai besoin d'entraide        | Explorer, onglet Propositions | connexion |
 *   | Je peux apporter mon aide     | Explorer, onglet Demandes     | connexion |
 *   | J'ai quelque chose a partager | le Blog                       | connexion |
 *   | Et si on faisait ensemble ?   | les Boucles                   | connexion |
 *
 * « J'ai besoin d'entraide » mene aux PROPOSITIONS et non aux demandes : qui a
 * besoin d'aide cherche ce que les autres proposent. Le symetrique vaut pour
 * « Je peux apporter mon aide ». C'est le mapping de `hero-v2`, verifie avant
 * d'etre recopie.
 *
 * Pour un visiteur, les quatre menent a la CONNEXION BORNEE de l'Organization.
 * Ce n'est pas un lien qui ne mene nulle part — c'est une invitation a entrer,
 * et c'est ce que la maquette prevoit (`laravel/config/ofsh_home.php` les
 * pointait toutes sur `/login`). La doctrine TASK-1692 vise les liens qui
 * PROMETTENT une page et ne rendent qu'une redirection ; ici la page de
 * connexion est la destination annoncee.
 *
 * ## Toutes les URL sont BORNEES
 *
 * Aucune route globale n'est appelee. Ce gabarit est selectionnable par
 * n'importe quelle Organization depuis `/admin/homepages` : une URL globale y
 * enverrait les visiteurs d'un tenant sur les surfaces d'un autre. C'est la
 * doctrine TASK-1602 / 1604, et le defaut que TASK-1608 a reellement trouve
 * sur `hero-v2`.
 */

$settings = $organization->homepage_settings ?? [];
$showInternalHomepageContent = (bool) ($showInternalHomepageContent ?? false);

// Un reglage d'administration ne vaut que s'il porte du texte : une chaine
// vide, laissee par un champ efface, doit rendre la main a la traduction.
$texte = function (string $cle, string $traduction) use ($settings): string {
    $valeur = trim((string) ($settings[$cle] ?? ''));

    return $valeur !== '' ? $valeur : org_trans($traduction);
};

// La porte d'une carte : la surface reelle pour un membre, la connexion
// bornee pour tout autre visiteur.
$porte = fn (string $route, array $parametres = []): string => $showInternalHomepageContent
    ? route($route, array_merge(['organization' => $organization->slug], $parametres))
    : route('organization.login', ['organization' => $organization->slug]);

// TASK-1725 (demande Cyril, 29/10) — les quatre cartes ne gardent QUE leur titre.
//
// « J'ai besoin d'entraide » se suffit ; « Un conseil, une question, une
// difficulte » repetait la meme idee en plus petit. Cyril : « afin de gagner de
// l'espace », pour que la page tienne sans defilement sur un ecran de bureau.
//
// La cle `desc` part de ce tableau avec le `<small>` : une donnee qu'on calcule
// et que personne n'affiche est une invitation a croire qu'elle sert encore.
// Les clefs de langue `ofsh.card_*_desc` RESTENT en place, elles : les retirer
// obligerait a reecrire quatre traductions dans deux langues si la decision se
// revisait, pour un gain nul.
$cartes = [
    'help' => [
        'url' => $porte('organization.explorer'),
        'titre' => $texte('card_help_label', 'ofsh.card_help_title'),
    ],
    'offer' => [
        'url' => $showInternalHomepageContent
            ? route('organization.explorer', ['organization' => $organization->slug]).'?tab=requests'
            : route('organization.login', ['organization' => $organization->slug]),
        'titre' => $texte('card_offer_label', 'ofsh.card_offer_title'),
    ],
    'share' => [
        'url' => $porte('organization.blog.index'),
        'titre' => $texte('card_share_label', 'ofsh.card_share_title'),
    ],
    'together' => [
        'url' => $porte('organization.loops.index'),
        'titre' => $texte('card_together_label', 'ofsh.card_together_title'),
    ],
];

$urlConnexion = route('organization.login', ['organization' => $organization->slug]);
$urlInscription = route('organization.register', ['organization' => $organization->slug]);
$urlApropos = route('organization.about', ['organization' => $organization->slug]);
?>
<!DOCTYPE html>
{{-- TASK-1471 : la cle de theme est posee cote SERVEUR. Un visiteur anonyme
     doit voir les couleurs de l'Organization qu'il consulte, jamais une
     preference de theme laissee dans son navigateur par une autre page. --}}
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-bp-theme="{{ bp_organization_theme_key($organization) }}">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>{{ $organization->name }} — {{ org_trans('ofsh.meta_title') }}</title>
<meta name="description" content="{{ $texte('subheadline', 'ofsh.meta_description') }}">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="{{ asset('css/ofsh-hero.css') }}?v={{ filemtime(public_path('css/ofsh-hero.css')) }}">
{{-- `system-dark` : le volet sombre suit la preference SYSTEME du visiteur.
     Pas `.dark`, qui vient d'un `localStorage` partage entre Organizations —
     voir le docblock de ce composant. --}}
<x-theme-tokens system-dark />
</head>
<body class="ofsh-hero">
<div class="page">

  <header class="nav">
    {{-- Le logo mene a l'accueil de SON Organization, jamais a la racine du
         produit : sous `/org/{slug}`, « accueil » veut dire cette vitrine. --}}
    <a class="logo" href="{{ route('organization.home', ['organization' => $organization->slug]) }}" aria-label="{{ $organization->name }}">
      <img src="{{ asset('img/ofsh/ofsh-header-light.png') }}"
           srcset="{{ asset('img/ofsh/ofsh-header-light.png') }} 1x, {{ asset('img/ofsh/ofsh-header-light@2x.png') }} 2x"
           alt="{{ org_trans('ofsh.logo_alt') }}" width="191" height="55" data-ofsh-logo-light>
      <img src="{{ asset('img/ofsh/ofsh-header-dark.png') }}"
           srcset="{{ asset('img/ofsh/ofsh-header-dark.png') }} 1x, {{ asset('img/ofsh/ofsh-header-dark@2x.png') }} 2x"
           alt="{{ org_trans('ofsh.logo_alt') }}" width="191" height="55" data-ofsh-logo-dark>
    </a>

    <nav class="nav-links" aria-label="{{ org_trans('ofsh.nav_menu') }}">
      <a href="{{ $urlApropos }}">{{ org_trans('ofsh.nav_about') }}</a>
      {{-- TASK-1725 (demande Cyril, 29/10) — le selecteur FR/EN suit le drapeau
           « site multilingue » de l'Organization. Decoche, l'interface reste dans la
           langue d'affichage choisie, et le selecteur ne promet plus un choix que le
           site ne propose pas. --}}
      @if($organization->multilingual_enabled)
      <div class="lang" aria-label="Langue / Language">
        @foreach (['fr' => 'FR', 'en' => 'EN'] as $code => $label)
          <form method="POST" action="{{ route('locale.switch', ['locale' => $code]) }}" class="inline">
            @csrf
            <button type="submit" class="{{ app()->getLocale() === $code ? 'active' : '' }}" @if(app()->getLocale() === $code) aria-current="true" @endif>{{ $label }}</button>
          </form>
        @endforeach
      </div>
      @endif
      @guest
        <a href="{{ $urlConnexion }}">{{ org_trans('ofsh.nav_login') }}</a>
        {{-- TASK-1725 (demande Cyril, 29/10) — « Rejoindre le collectif » RETIRE du
             bandeau haut pour un visiteur deconnecte.

             Le meme appel vit deja dans le hero, quelques centaines de pixels plus
             bas et bien plus visible (`data-ofsh-cta-primary`, meme destination
             `$urlInscription`). Deux boutons identiques a l'ecran se disputaient le
             meme geste. Cyril : « on a deja un bouton similaire en bas a droite ».

             « Se connecter » RESTE : c'est l'autre geste, et il n'a pas de double
             dans le hero.

             Le menu BURGER garde son entree, volontairement : la demande vise le
             bouton « en haut a droite », et dans le menu l'entree n'est pas un
             doublon flottant mais la moitie d'une paire de destinations. L'oter
             laisserait « Se connecter » seul, et un visiteur venu s'inscrire par le
             menu n'y trouverait plus rien. --}}
      @else
        {{-- TASK-1720 — menu de compte, partiel partage par les trois
             gabarits de homepage autonomes. --}}
        @include('organization.partials.account-menu', ['organization' => $organization])
      @endguest
    </nav>

    {{-- TASK-1725 (demande Cyril, 29/10) — le menu BURGER est retire ; sur
         telephone la barre est celle du bureau.

         Il ne contenait plus que « A propos » et le selecteur FR/EN — lequel
         disparait desormais sur une Organization non multilingue (meme TASK).
         Un bouton qui ouvre un panneau d'un seul lien coute un geste pour rien,
         et cachait l'avatar derriere deux clics alors que tout le reste de
         l'application le montre d'emblee sur telephone.

         Rien de nouveau n'est ecrit : `nav-links` cesse simplement d'etre
         masquee sous 720 px. Elle porte deja « A propos » et, selon l'etat de la
         session, « Se connecter » ou le menu de compte — le MEME partiel que le
         bureau, donc les memes entrees, ce que la demande appelle « exactement
         comme la version desktop ».

         Le script `js/ofsh-hero.js` n'animait QUE ce menu : il est supprime
         avec lui, ainsi que son inclusion. Son garde `if (!toggle || !menu)`
         aurait certes rendu la page silencieuse, mais une requete HTTP sur
         chaque visite pour un fichier sans effet n'a pas de raison d'etre. --}}
  </header>

  @include('organization.partials.guest-shell-overlay', ['guestShell' => $guestShell ?? null, 'organization' => $organization, 'position' => 'top'])

  <main id="main" class="hero">
    <section class="lead" aria-labelledby="ofsh-hero-title">
      <div class="eyebrow">{{ $texte('eyebrow', 'ofsh.eyebrow') }}</div>

      <h1 id="ofsh-hero-title">
        <span>{{ org_trans('ofsh.title_1') }}</span>
        <span class="line-blue">{{ org_trans('ofsh.title_2') }}</span>
        <span class="line-green">{{ org_trans('ofsh.title_3') }}</span>
        <span>{{ org_trans('ofsh.title_4') }}</span>
      </h1>

      <p class="intro">{{ $texte('subheadline', 'ofsh.intro') }}</p>

      <div class="mantra">
        <span>{{ org_trans('ofsh.mantra_1') }}</span>
        <i class="bullet" aria-hidden="true"></i>
        <span>{{ org_trans('ofsh.mantra_2') }}</span>
        <i class="bullet" aria-hidden="true"></i>
        <span>{{ org_trans('ofsh.mantra_3') }}</span>
      </div>

      <div class="cta-row">
        {{-- Le CTA principal : rejoindre pour un visiteur, le tableau de bord
             pour quelqu'un qui est deja entre. Un bouton « Rejoindre » propose
             a un membre serait une impasse polie. --}}
        @guest
          <a href="{{ $urlInscription }}" class="cta cta-primary" data-ofsh-cta-primary>{{ $texte('primary_cta_label', 'ofsh.cta_join') }}
            <svg class="icon" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14m-6-6 6 6-6 6"/></svg>
          </a>
        @else
          <a href="{{ route('dashboard') }}" class="cta cta-primary" data-ofsh-cta-primary>{{ org_trans('ofsh.nav_dashboard') }}
            <svg class="icon" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14m-6-6 6 6-6 6"/></svg>
          </a>
        @endguest
        <a href="{{ $urlApropos }}" class="cta cta-secondary" data-ofsh-cta-secondary>{{ $texte('secondary_cta_label', 'ofsh.cta_about') }}
          <svg class="icon" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg>
        </a>
      </div>
    </section>

    <section class="orbit-wrap" aria-label="{{ org_trans('ofsh.eyebrow') }}">
      <div class="orbit-bg" aria-hidden="true"></div>
      <div class="orbit-center" aria-hidden="true"></div>

      <a class="orbit-card card-help" href="{{ $cartes['help']['url'] }}" data-ofsh-card="help">
        <span class="symbol" aria-hidden="true"><svg class="icon" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M9.5 9a2.8 2.8 0 0 1 5.2 1.4c0 2-2.7 2.3-2.7 4.2"/><path d="M12 17.7h.01"/></svg></span>
        <div class="card-copy"><h2>{{ $cartes['help']['titre'] }}</h2></div>
      </a>

      <a class="orbit-card card-offer" href="{{ $cartes['offer']['url'] }}" data-ofsh-card="offer">
        <span class="symbol" aria-hidden="true"><svg class="icon" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M20.8 8.6c0 4.2-8.8 9.7-8.8 9.7S3.2 12.8 3.2 8.6a4.6 4.6 0 0 1 8.8-1.7 4.6 4.6 0 0 1 8.8 1.7z"/></svg></span>
        <div class="card-copy"><h2>{{ $cartes['offer']['titre'] }}</h2></div>
      </a>

      <a class="orbit-card card-share" href="{{ $cartes['share']['url'] }}" data-ofsh-card="share">
        <span class="symbol" aria-hidden="true"><svg class="icon" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M12 6.2C9.4 4.8 6.4 4.8 3 5.4v12.2c3.4-.6 6.4-.6 9 1 2.6-1.6 5.6-1.6 9-1V5.4c-3.4-.6-6.4-.6-9 .8z"/><path d="M12 6.2v12.4"/></svg></span>
        <div class="card-copy"><h2>{{ $cartes['share']['titre'] }}</h2></div>
      </a>

      <a class="orbit-card card-together" href="{{ $cartes['together']['url'] }}" data-ofsh-card="together">
        <span class="symbol" aria-hidden="true"><svg class="icon" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="8" r="2.5"/><circle cx="17.5" cy="9" r="2"/><path d="M3.5 19v-1.4A5.5 5.5 0 0 1 9 12.2a5.5 5.5 0 0 1 5.5 5.4V19H3.5z"/><path d="M16 13.4a4.3 4.3 0 0 1 4.5 4.2V19h-4"/></svg></span>
        <div class="card-copy"><h2>{{ $cartes['together']['titre'] }}</h2></div>
      </a>

      {{-- TASK-1725 (demande Cyril, 29/10) — sans IA, la mention disparait.

           Cette phrase est une PROMESSE sur le fonctionnement du produit. Sur une
           Organization dont l'IA est eteinte, elle decrit quelque chose qui
           n'arrive pas : la page annoncait un role que rien ne joue.

           Le bloc ENTIER part, pas seulement le texte — la fleche decorative
           pointerait sinon vers un vide.

           `availableFor()` et non `memberAgentAvailableFor()` : la phrase parle du
           role de l'IA dans les echanges, pas des agents de profil. Couper le
           reglage « profils IA » ne doit pas effacer un propos qui reste vrai.
           C'est l'autorite que `/org/{org}/admin/ai` gouverne, celle que Cyril a
           designee. --}}
      @if(app(\App\Support\Ai\AiSurfaceGate::class)->availableFor((string) $organization->id))
      <p class="orbit-note">{{ $texte('ai_note', 'ofsh.ai_note') }}</p>
      @endif
    </section>
  </main>

  {{-- TASK-1720 — la section « Un collectif pour ne pas avancer seul » est
       retiree de la HOMEPAGE. MASTER : « cela prend trop de place ».

       Elle poussait la page a defiler sur un ecran de bureau alors que le
       hero, lui, tient. Le discours n'est pas perdu : « A propos » et
       « Decouvrir OFSH » menent tous deux a `organization.about`, une page
       entiere qui le developpe. Aucun lien ne visait cette ancre — `#about`
       n'etait la cible de rien, verifie.

       Les trois clefs `ofsh.about_title`, `about_body` et `about_line` RESTENT
       en place : `org_trans()` les lit a travers `TranslationOverrideService`,
       un dictionnaire administrable par Organization. Les supprimer retirerait
       trois entrees du vocabulaire qu'un administrateur peut reecrire. Aucune
       personnalisation ne porte sur elles aujourd'hui (verifie en base), mais
       la clef est l'offre, pas son usage sur une page. --}}

  {{-- TASK-1698 — le pied est servi EN ENTIER, connecte ou non, et chacune de
       ses destinations est BORNEE a l'Organization de l'URL. Seul « Un bug ? »
       exige encore d'etre membre : c'est voulu, et c'est le seul mur du pied.
       Voir le pied de `hero-v2`, dont celui-ci reprend les destinations. --}}
  {{-- TASK-1725 (demande Cyril, 29/10) — le pied se lit en TROIS lignes sur
       telephone, et le credit de portage porte son symbole.

           Mycelium · Logigramme
           OpenSource · Mentions legales
           Un bug ? · (github)  1.725

       ## Les paires, et pourquoi pas un `flex-wrap` libre

       TASK-1698 avait pose `flex-wrap` sur `.footer-right` pour que les six
       entrees cessent de deborder a 390 px. Ca ne debordait plus, mais la
       coupure tombait ou la largeur voulait : Cyril, capture a l'appui — « ne
       fait vraiment pas premium ». Les liens vont donc par PAIRES insecables, et
       c'est le groupe qui porte le retour a la ligne. Le separateur appartient
       au groupe : il ne peut plus ouvrir une ligne.

       ## Le symbole BouclePro

       Demande explicite — « on pourrait ajouter le Propulse par BouclePro avec le
       petit logo comme launchpals ». Le texte etait deja la, le symbole non. La
       forme est reprise de `artscilab-hero` (`.foot-left` + `.foot-symbol`),
       seule reference existante, pour que les deux pieds se ressemblent.

       Le symbole est purement DECORATIF : `alt=""` et `aria-hidden` — le texte a
       cote dit deja tout, et le faire lire deux fois est une gene pour qui
       ecoute la page. --}}
  <footer class="footer">
    <div class="footer-credit">
      <span>{{ org_trans('ofsh.powered_by') }}</span>
      <img class="footer-symbol" src="{{ asset('img/bouclepro-symbol.png') }}" alt="" aria-hidden="true">
    </div>
    <div class="footer-right">
      <span class="footer-pair">
        <a href="{{ route('organization.mycelium', ['organization' => $organization->slug]) }}" data-footer-mycelium>{{ __('mycelium.footer_link') }}</a>
        <span class="footer-sep" aria-hidden="true">·</span>
        <a href="{{ route('organization.flowchart', ['organization' => $organization->slug]) }}" data-footer-flowchart>{{ __('footer.flowchart') }}</a>
      </span>
      <span class="footer-pair">
        <a href="{{ route('organization.open-source', ['organization' => $organization->slug]) }}" data-footer-opensource>{{ __('footer.opensource') }}</a>
        <span class="footer-sep" aria-hidden="true">·</span>
        <a href="{{ route('organization.mentions-legales', ['organization' => $organization->slug]) }}">{{ __('footer.mentions_legales') }}</a>
      </span>
      <span class="footer-pair">
        <a href="{{ route('organization.bug-reports.index', ['organization' => $organization->slug]) }}" data-footer-bug>{{ __('footer.bug') }}</a>
        <span class="footer-sep" aria-hidden="true">·</span>
        <a href="{{ route('open-source.github') }}" target="_blank" rel="noopener"
           data-footer-github
           aria-label="{{ __('open_source.github_aria') }}">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 .5C5.7.5.5 5.7.5 12c0 5.1 3.3 9.4 7.8 10.9.6.1.8-.2.8-.6v-2c-3.2.7-3.9-1.5-3.9-1.5-.5-1.3-1.3-1.7-1.3-1.7-1-.7.1-.7.1-.7 1.2.1 1.8 1.2 1.8 1.2 1 1.8 2.8 1.3 3.5 1 .1-.8.4-1.3.7-1.6-2.6-.3-5.3-1.3-5.3-5.8 0-1.3.5-2.3 1.2-3.2-.1-.3-.5-1.5.1-3.2 0 0 1-.3 3.3 1.2a11 11 0 0 1 6 0c2.3-1.5 3.3-1.2 3.3-1.2.6 1.7.2 2.9.1 3.2.8.9 1.2 1.9 1.2 3.2 0 4.5-2.7 5.5-5.3 5.8.4.4.8 1.1.8 2.2v3.3c0 .4.2.7.8.6 4.5-1.5 7.8-5.8 7.8-10.9C23.5 5.7 18.3.5 12 .5z"/></svg>
        </a>
        <span class="footer-version">{{ config('app.version') }}</span>
      </span>
    </div>
  </footer>
</div>

@include('organization.partials.guest-shell-overlay', ['guestShell' => $guestShell ?? null, 'organization' => $organization, 'position' => 'bottom'])
</body>
</html>
