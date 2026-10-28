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
    - `$triggerClass` : la classe de bouton du gabarit hote (`btn-join`,
      `btn-join--outline`, `nav-dashboard`...), pour que le declencheur garde
      exactement l'allure du bouton qu'il remplace.
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
    <button type="button"
            class="bpam-trigger {{ $triggerClass ?? '' }}"
            aria-expanded="false"
            aria-haspopup="true"
            aria-label="{{ __('navigation.user_menu') }}">
        {{ __('navigation.dashboard') }}
        <span class="bpam-caret" aria-hidden="true"></span>
    </button>

    <div class="bpam-panel" hidden>
        @foreach($entrees as $entree)
            <a href="{{ $entree['url'] }}">{{ $entree['label'] }}</a>
        @endforeach
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="bpam-logout">{{ __('navigation.logout') }}</button>
        </form>
    </div>
</div>

@once
<style>
.bpam{position:relative;display:inline-block}
.bpam-trigger{display:inline-flex;align-items:center;gap:7px;cursor:pointer}
.bpam-caret{width:0;height:0;border-left:4.5px solid transparent;border-right:4.5px solid transparent;border-top:5px solid currentColor;opacity:.65;transition:transform .15s}
.bpam-trigger[aria-expanded="true"] .bpam-caret{transform:rotate(180deg)}
.bpam-panel{
  position:absolute;right:0;top:calc(100% + 10px);z-index:200;width:232px;
  background:#fff;border:1px solid rgba(20,24,60,.10);border-radius:18px;padding:7px;
  box-shadow:0 24px 56px -16px rgba(20,24,60,.32);
  text-align:left;
}
.bpam-panel[hidden]{display:none}
.bpam-panel a,.bpam-panel button{
  display:block;width:100%;box-sizing:border-box;padding:9px 12px;border-radius:11px;
  text-align:left;font-size:14px;font-weight:600;line-height:1.3;
  color:#4A4F63;background:none;border:0;cursor:pointer;text-decoration:none;
}
.bpam-panel a:hover,.bpam-panel button:hover{background:rgba(20,24,60,.055);color:#14183C}
/* La sortie de session se detache du reste : ce n'est pas une navigation. */
.bpam-logout{margin-top:5px;border-top:1px solid rgba(20,24,60,.09) !important;border-radius:0 0 11px 11px !important;padding-top:11px !important}
@media (prefers-color-scheme: dark){
  .bpam-panel{background:#24263D;border-color:rgba(255,255,255,.10)}
  .bpam-panel a,.bpam-panel button{color:#C9CCDC}
  .bpam-panel a:hover,.bpam-panel button:hover{background:rgba(255,255,255,.07);color:#fff}
  .bpam-logout{border-top-color:rgba(255,255,255,.12) !important}
}
html.dark .bpam-panel{background:#24263D;border-color:rgba(255,255,255,.10)}
html.dark .bpam-panel a,html.dark .bpam-panel button{color:#C9CCDC}
html.dark .bpam-panel a:hover,html.dark .bpam-panel button:hover{background:rgba(255,255,255,.07);color:#fff}
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
