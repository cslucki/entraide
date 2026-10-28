{{--
    TASK-1720 — le menu de compte des gabarits de homepage AUTONOMES.

    ## Pourquoi un partiel, et pas trois copies

    `hero-v2`, `artscilab-hero` et `ofsh-hero` sont trois pages completes, avec
    chacune sa feuille de style et son propre balisage de navigation. Le menu,
    lui, doit dire la MEME chose aux trois : un visiteur connecte qui arrive sur
    l'une ou l'autre doit y trouver les memes portes.

    Recopier le bloc trois fois aurait garanti qu'ils divergent — a la premiere
    entree ajoutee, au premier libelle corrige. Le balisage, le comportement et
    l'apparence vivent donc ici, en un exemplaire.

    ## Le style et le script sont EMBARQUES, et c'est delibere

    Les trois gabarits chargent trois fichiers CSS distincts
    (`bouclepro-hero.css`, `artscilab-hero.css`, `ofsh-hero.css`). Y ecrire le
    meme bloc trois fois aurait pose le meme probleme a l'etage du dessous.

    `@once` garantit qu'ils ne sortent qu'UNE fois, meme quand le partiel est
    inclus deux fois dans la meme page — la barre de bureau et le menu mobile.
    Le script travaille donc sur `querySelectorAll`, pas sur `querySelector`.

    Les classes sont prefixees `bpam-` : elles ne croisent aucune des trois
    feuilles, qui scopent tout sous `.bp-hero-v2`, `.artscilab` ou `.ofsh`.

    ## Parametres

    - `$organization` : l'Organization de la page. Les trois liens sont
      ORG-SCOPES, avec repli sur la route globale quand elle n'existe pas sous
      sa forme org — meme idiome que le rail gauche.
    Il n'y a plus de `$triggerClass` : le declencheur est un avatar rond,
    identique sur les trois gabarits, et ne se fait plus habiller par aucun
    d'eux. C'est ce qui avait produit le « noir et plus petit » de LaunchPals,
    dont le bouton est declare sur la balise `a.btn-join--outline`.
--}}
@php
    $org = $organization->slug;

    // Meme idiome que `app-side-nav` : la forme org-scopee quand elle existe,
    // la globale sinon. Aucune des trois n'est supposee presente.
    $lien = function (string $orgRoute, string $globalRoute) use ($org) {
        return Route::has($orgRoute)
            ? route($orgRoute, ['organization' => $org])
            : route($globalRoute);
    };

    $entrees = [
        ['url' => route('dashboard'), 'label' => __('navigation.dashboard')],
        ['url' => $lien('organization.profile.edit', 'profile.edit'), 'label' => __('navigation.settings')],
        ['url' => $lien('organization.loops.index', 'loops.index'), 'label' => __('navigation.loops')],
        ['url' => $lien('organization.bug-reports.index', 'bug-reports.index'), 'label' => __('footer.bug')],
    ];
@endphp

<div class="bpam" data-marqueur="menu-compte-accueil">
    {{-- Le declencheur est l'AVATAR, comme au rail gauche. Un libelle
         « Tableau de bord » nommait une seule des cinq destinations du menu et
         pesait la largeur d'un bouton dans une barre deja chargee. --}}
    <button type="button"
            class="bpam-trigger"
            aria-expanded="false"
            aria-haspopup="true"
            aria-label="{{ __('navigation.user_menu') }}">
        <img src="{{ auth()->user()->avatar_url }}" alt="{{ auth()->user()->full_name }}" class="bpam-avatar">
        {{-- La pastille suit `is_available` et la meme garde que le rail
             (TASK-1694 bug AF) : une Organization qui a decoche la
             disponibilite ne doit pas voir d'indicateur ici non plus. --}}
        @if(availabilityIsShown())
            <span class="bpam-dot {{ auth()->user()->is_available ? 'bpam-dot--on' : '' }}"
                  data-availability-dot="{{ auth()->user()->is_available ? 'available' : 'unavailable' }}"></span>
        @endif
    </button>

    <div class="bpam-panel" hidden>
        {{-- L'en-tete dit QUI est connecte. Le libelle du bouton le disait
             jusqu'ici par sa seule presence ; l'avatar seul ne suffit pas a
             lever le doute sur un poste partage. --}}
        <div class="bpam-head">
            <img src="{{ auth()->user()->avatar_url }}" alt="" class="bpam-head-avatar" aria-hidden="true">
            <span class="bpam-head-text">
                <strong>{{ auth()->user()->full_name }}</strong>
                <span>{{ auth()->user()->points_balance }} pts</span>
            </span>
        </div>
        @foreach($entrees as $entree)
            <a href="{{ $entree['url'] }}" class="bpam-item">{{ $entree['label'] }}</a>
        @endforeach
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="bpam-item bpam-logout">{{ __('navigation.logout') }}</button>
        </form>
    </div>
</div>

@once
<style>
/*
   Le panneau est un WIDGET depose dans trois feuilles etrangeres. Ses
   couleurs sont donc declarees `!important`, et ce n'est pas une surenchere
   de confort : la regle hote la plus forte mesuree est
   `.bp-artscilab .nav-links a:not(.btn-join):hover` — specificite 0,4,1.

   Sur LaunchPals, le resultat etait du blanc sur blanc : le menu vit dans
   `.nav-links`, dont les liens sont `rgba(255,255,255,.9)` pour se poser sur
   le degrade. Seuls « Boucles » (survole) et « Deconnexion » (un `button`,
   que la regle ne visait pas) se lisaient.

   Gagner par specificite aurait demande d'empiler des classes jusqu'a 0,5,1,
   a refaire au premier gabarit ajoute. `!important` sur la seule frontiere du
   widget dit la bonne chose : ce panneau ne s'habille pas chez l'hote.
*/
.bpam{position:relative;display:inline-block}
/* Le declencheur est un AVATAR, pas un bouton de l'hote. Il ne depend donc
   plus d'aucune classe du gabarit : plus de `$triggerClass`, et plus de
   regression possible du genre « noir et plus petit » — le `<button>` est
   entierement remis a plat ici. */
.bpam-trigger{position:relative;display:inline-flex;align-items:center;justify-content:center;
  width:40px;height:40px;padding:0;border:0;border-radius:50%;cursor:pointer;
  background:rgba(255,255,255,.92);box-shadow:0 2px 10px rgba(20,24,60,.18);
  transition:transform .14s, box-shadow .2s}
.bpam-trigger:hover{transform:scale(1.06);box-shadow:0 6px 18px rgba(20,24,60,.26)}
.bpam-avatar{width:36px;height:36px;border-radius:50%;object-fit:cover;display:block}
.bpam-dot{position:absolute;right:0;bottom:0;width:11px;height:11px;border-radius:50%;
  background:#9AA0AE;border:2px solid #fff}
.bpam-dot--on{background:#10B981}
.bpam-head{display:flex;align-items:center;gap:10px;padding:8px 10px 11px;margin-bottom:5px;
  border-bottom:1px solid rgba(20,24,60,.09)}
.bpam-head-avatar{width:36px;height:36px;border-radius:50%;object-fit:cover;flex:0 0 auto}
.bpam-head-text{min-width:0;display:block}
.bpam-head-text strong{display:block;font-size:13.5px;line-height:1.25;color:#14183C !important;
  white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.bpam-head-text span{display:block;font-size:11.5px;line-height:1.3;margin-top:2px;color:#7A8098 !important}
.bpam-panel{
  position:absolute;right:0;top:calc(100% + 10px);z-index:200;width:232px;
  background:#fff;border:1px solid rgba(20,24,60,.10);border-radius:18px;padding:7px;
  box-shadow:0 24px 56px -16px rgba(20,24,60,.32);
  text-align:left;
}
.bpam-panel[hidden]{display:none}
.bpam-item{
  display:block;width:100%;box-sizing:border-box;padding:9px 12px;border-radius:11px;
  text-align:left;font-size:14px;font-weight:600;line-height:1.3;
  color:#4A4F63 !important;background:none !important;border:0;cursor:pointer;text-decoration:none;
}
.bpam-item:hover{background:rgba(20,24,60,.055) !important;color:#14183C !important}
/* La sortie de session se detache du reste : ce n'est pas une navigation. */
.bpam-logout{margin-top:5px;border-top:1px solid rgba(20,24,60,.09);border-radius:0 0 11px 11px;padding-top:11px}
html.dark .bpam-panel{background:#24263D;border-color:rgba(255,255,255,.10)}
html.dark .bpam-item{color:#C9CCDC !important}
html.dark .bpam-item:hover{background:rgba(255,255,255,.07) !important;color:#fff !important}
html.dark .bpam-logout{border-top-color:rgba(255,255,255,.12)}
html.dark .bpam-head{border-bottom-color:rgba(255,255,255,.12)}
html.dark .bpam-head-text strong{color:#F2F4FA !important}
html.dark .bpam-head-text span{color:#9DA3B8 !important}
html.dark .bpam-dot{border-color:#24263D}
</style>

<script>
(function () {
  // `querySelectorAll` : une page peut porter DEUX menus — la barre de bureau
  // et le menu mobile. Un `querySelector` n'en aurait anime qu'un.
  const menus = [...document.querySelectorAll('.bpam')];
  if (!menus.length) return;

  const fermerTous = (sauf) => menus.forEach(m => {
    if (m === sauf) return;
    m.querySelector('.bpam-panel').hidden = true;
    m.querySelector('.bpam-trigger').setAttribute('aria-expanded', 'false');
  });

  menus.forEach(menu => {
    const trigger = menu.querySelector('.bpam-trigger');
    const panel = menu.querySelector('.bpam-panel');
    if (!trigger || !panel) return;

    trigger.addEventListener('click', e => {
      e.stopPropagation();
      const ouvrir = panel.hidden;
      fermerTous(menu);
      panel.hidden = !ouvrir;
      trigger.setAttribute('aria-expanded', ouvrir ? 'true' : 'false');
    });
  });

  document.addEventListener('click', e => {
    if (!menus.some(m => m.contains(e.target))) fermerTous(null);
  });
  document.addEventListener('keydown', e => { if (e.key === 'Escape') fermerTous(null); });
})();
</script>
@endonce
