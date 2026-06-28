<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>{{ __('about.meta_title') }}</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
  <style>
    /* ── Reset ── */
    *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
    body{font-family:'Inter',system-ui,sans-serif;-webkit-font-smoothing:antialiased;color:#fff;background:#080712}
    a{color:inherit;text-decoration:none}

    /* ── Snap container ── */
    .about-snap{height:100vh;overflow-y:auto;scroll-snap-type:y mandatory;scroll-behavior:smooth;background:#080712;color:#fff}

    /* ── Panel ── */
    .about-panel{position:relative;min-height:100vh;scroll-snap-align:start;display:flex;align-items:center;justify-content:flex-start;overflow:hidden;padding:clamp(2rem,5vw,6rem)}
    .about-panel__content{position:relative;z-index:2;width:min(100%,980px);margin:0}
    .about-panel__content--center{text-align:left}
    .about-panel__content--wide{width:min(100%,1180px)}

    /* ── Typography ── */
    .about-kicker{margin:0 0 1.25rem;font-size:clamp(.75rem,1vw,.95rem);letter-spacing:.18em;text-transform:uppercase;opacity:.72}
    .about-title{margin:0;font-size:clamp(3rem,8vw,4.5rem);line-height:.92;letter-spacing:-.06em;font-weight:760}
    .about-title--xl{font-size:clamp(3.5rem,9vw,4.5rem)}
    .about-title--lg{font-size:clamp(2.5rem,6vw,4.5rem)}
    .about-text,.about-lead{max-width:840px;margin:2rem 0 0;font-size:clamp(1.25rem,2vw,2.1rem);line-height:1.22;letter-spacing:-.03em;opacity:.9}
    .about-text + .about-text{margin-top:.7em;opacity:.74}
    .about-support{max-width:760px;margin:1.2rem 0 0;font-size:clamp(1rem,1.45vw,1.35rem);line-height:1.35;letter-spacing:-.02em;opacity:.72}
    .about-panel__content--center .about-text,.about-panel__content--center .about-lead{margin-left:0;margin-right:0}
    .about-punch{max-width:760px;margin:1.35rem 0 0;font-size:clamp(1.1rem,1.8vw,1.65rem);line-height:1.25;font-weight:800;letter-spacing:-.03em;opacity:.95}
    .about-loop-types{display:flex;flex-wrap:wrap;gap:.75rem;margin-top:1.8rem;justify-content:flex-start}

    /* ── Fixed brand (theme per section via JS) ── */
    .about-fixed-logo{position:fixed;top:1.5rem;left:1.5rem;z-index:30;display:inline-flex;align-items:center;gap:.65rem;padding:.55rem .8rem .55rem .6rem;border-radius:999px;font-size:.92rem;font-weight:850;letter-spacing:-.03em;transition:background .35s,color .35s,border-color .35s;background:rgba(255,255,255,.14);color:#fff;border:1px solid rgba(255,255,255,.25);box-shadow:0 4px 24px rgba(0,0,0,.16);backdrop-filter:blur(12px)}
    .about-fixed-logo img{width:1.85rem;height:1.85rem;display:block;border-radius:999px}
    .about-fixed-logo[data-section="s-besoin"]{background:rgba(26,26,46,.86);color:#f5efe0;border-color:rgba(26,26,46,.12);backdrop-filter:blur(12px) brightness(.8)}

    /* ── Sidebar navigation ── */
    .about-nav{position:fixed;top:50%;right:clamp(6px,1.2vw,18px);transform:translateY(-50%);z-index:25;display:flex;flex-direction:column;gap:10px;mix-blend-mode:difference;pointer-events:none}
    .about-nav__link{display:block;padding:6px 10px;border-radius:6px;font-size:clamp(.9rem,1.8vw,2.7rem);font-weight:750;letter-spacing:.06em;text-transform:uppercase;color:#fff;text-decoration:none;text-align:right;opacity:.3;transition:opacity .35s;pointer-events:auto;white-space:nowrap;line-height:1}
    .about-nav__link:hover{opacity:.7}
    .about-nav__link--active{opacity:1}

    /* ── Loop type badges with dot ── */
    .about-loop-type{display:inline-flex;align-items:center;gap:.55rem;padding:.7rem 1rem;border-radius:999px;border:1px solid rgba(255,255,255,.18);background:rgba(255,255,255,.08);font-size:clamp(.9rem,1.1vw,1rem);font-weight:700}
    .about-loop-type::before{content:'';width:.75rem;height:.75rem;border-radius:50%;flex:0 0 auto}
    .about-loop-type:nth-child(1)::before{background:#9FE6BC}
    .about-loop-type:nth-child(2)::before{background:#FFD98A}
    .about-loop-type:nth-child(3)::before{background:#A6CCFF}
    .about-loop-type:nth-child(4)::before{background:#FFB3B3}
    .about-loop-type:nth-child(5)::before{background:#D7BCFF}

    /* ── Mission cycle ── */
    .about-cycle{display:flex;flex-wrap:wrap;align-items:center;gap:10px 12px;margin-top:clamp(28px,4vh,44px)}
    .about-cycle__step{padding:10px 18px;border-radius:999px;font-weight:700;font-size:clamp(.9rem,1.6vw,1.1rem);background:rgba(255,255,255,.14);border:1px solid rgba(255,255,255,.28)}
    .about-cycle__step--active{background:#fff;color:#111827;border-color:#fff}
    .about-cycle__arrow{opacity:.6;font-weight:700;font-size:1.1rem}

    /* ── Screen 1 : Hero beige ── */
    .about-panel--hero{display:grid;place-items:center start;background:#f5efe0;color:#1a1a2e}
    .about-rings{position:absolute;inset:0;background-position:center;background-repeat:no-repeat;background-size:cover;pointer-events:none;z-index:0}
    .about-rings--besoin{background-image:url('{{ asset("img/boucle-rings.svg") }}');opacity:.72}
    .about-rings--mission{background-image:url('{{ asset("img/rings1.svg") }}');opacity:.4}
    .about-rings--transmission,
    .about-rings--memoire{background-image:url('{{ asset("img/rings2.svg") }}');background-attachment:fixed;opacity:.35}
    .about-rings--personnes{background-image:url('{{ asset("img/rings4.svg") }}');opacity:.3}
    .about-rings--dark{background-image:url('{{ asset("img/rings6.svg") }}');opacity:.55;filter:brightness(3) saturate(1.4)}
    .about-rings--tableau{background-image:url('{{ asset("img/rings6.svg") }}');opacity:.25}
    .about-panel--hero .about-panel__content{transform:translateY(1.5vh)}
    .about-panel--hero .about-title{font-size:clamp(2.2rem,5vw,4.5rem);letter-spacing:-.04em;color:#1a1a2e}
    .about-panel--hero .about-text,.about-panel--hero .about-support{color:#3b3950}
    .about-panel--hero .about-support{opacity:1}

    /* ── Panel backgrounds ── */
    .about-panel--violet{background:#4b22b8}
    .about-panel--blue{background:#173bc2}
    .about-panel--dark{background:#080712}
    .about-panel--orange{background:#c95b1d}
    .about-panel--final{background:radial-gradient(circle at top right,rgba(255,255,255,.16),transparent 32%),linear-gradient(135deg,#24105c 0%,#7a245e 52%,#111827 100%)}

    /* ── Comparison table ── */
    .about-compare-wrapper{overflow-x:auto;border-radius:1.5rem;border:1px solid rgba(255,255,255,.18);background:rgba(255,255,255,.06);backdrop-filter:blur(18px);margin-top:1.8rem}
    .about-compare{width:100%;border-collapse:collapse;min-width:820px}
    .about-compare th,.about-compare td{padding:1rem 1.1rem;border-bottom:1px solid rgba(255,255,255,.12);text-align:center;font-size:1rem}
    .about-compare th:first-child,.about-compare td:first-child{text-align:left;width:34%}
    .about-compare th{font-size:.8rem;letter-spacing:.12em;text-transform:uppercase;opacity:.74}
    .about-compare tbody tr:last-child td{border-bottom:0}
    .about-compare td.is-strong{font-weight:800;background:rgba(255,255,255,.12)}
    .about-legend{margin-top:.85rem;font-size:.9rem;opacity:.68}

    .about-panel--tiered{flex-direction:column;justify-content:center;padding:clamp(1.5rem,3vw,4rem) clamp(2rem,5vw,6rem)}
    .about-tier{width:min(100%,980px);margin:0;display:flex;flex-direction:column;justify-content:center;text-align:left}
    .about-tier--top{flex:0 0 auto;padding-bottom:1rem}
    .about-tier--mid{flex:1;padding:.5rem 0}
    .about-tier--bot{flex:1;padding-top:.5rem}
    .about-tier .about-title{margin:0;font-size:clamp(2.5rem,6vw,4.5rem)}
    .about-tier .about-text,.about-tier .about-punch{margin:0;max-width:840px}
    .about-tier .about-kicker{margin-bottom:1rem}

    .about-compare-wrapper--m3{box-shadow:0 4px 28px rgba(0,0,0,.35),0 1px 4px rgba(0,0,0,.18)}
    .about-compare-wrapper--m3 .about-compare th{font-size:.82rem;letter-spacing:.14em;padding:1.25rem 1.1rem .85rem;font-weight:600}
    .about-compare-wrapper--m3 .about-compare thead::after{content:'';display:block;height:1px;background:rgba(255,255,255,.12);margin:0 1.1rem}
    .about-panel--table .about-compare-wrapper{margin-top:0}
    .about-panel--table .about-panel__content{display:flex;flex-direction:column;justify-content:center;min-height:80vh}

    /* ── Mobile comparison cards ── */
    .about-cards{display:none}
    .about-card{padding:1.5rem;border-radius:1.5rem;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.12);backdrop-filter:blur(18px)}
    .about-card__title{margin:0 0 1rem;font-size:1.2rem;font-weight:800;line-height:1.2;letter-spacing:-.03em}
    .about-card__row{display:flex;justify-content:space-between;gap:1rem;padding:.75rem 0;border-top:1px solid rgba(255,255,255,.08)}
    .about-card__row--highlight{margin-top:.5rem;padding:.75rem 1rem;border-radius:1rem;background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.14)}
    .about-card__platform{opacity:.82}
    .about-card__status{font-weight:700;white-space:nowrap}

    /* ── CTA buttons ── */
    .about-actions{display:flex;gap:1rem;flex-wrap:wrap;margin-top:2.5rem;justify-content:flex-start}
    .about-button{display:inline-flex;align-items:center;justify-content:center;min-height:3.25rem;padding:0 1.4rem;border-radius:999px;text-decoration:none;font-weight:700;transition:transform 180ms ease,background 180ms ease,color 180ms ease}
    .about-button:hover{transform:translateY(-2px)}
    .about-button--primary{background:#fff;color:#111827}
    .about-button--secondary{border:1px solid rgba(255,255,255,.4);color:#fff}

    /* ── Footer ── */
    .about-footer{text-align:center;padding:2rem 1rem;font-size:13px;font-weight:700;color:rgba(255,255,255,.5);background:#080712;border-top:1px solid rgba(255,255,255,.08)}
    .about-footer a:hover{color:#fff}
    .about-footer span{margin:0 12px}

    /* ── Mobile ── */
    @media (max-width:768px){
      .about-snap{height:100dvh;overflow-y:auto;scroll-snap-type:y mandatory}
      .about-panel{min-height:100dvh;scroll-snap-align:start;padding:4rem 1.25rem}
      .about-nav{display:none}
      .about-panel--tiered{padding:3rem 1.25rem}
      .about-tier--top{padding-bottom:.5rem}
      .about-tier--mid{padding:.25rem 0}
      .about-tier--bot{padding-top:.25rem}
      .about-panel--hero .about-title{font-size:clamp(2rem,9vw,3.2rem)}
      .about-fixed-logo{top:.85rem;left:.85rem;padding:.45rem .7rem .45rem .5rem;font-size:.85rem}
      .about-fixed-logo img{width:1.55rem;height:1.55rem}
      .about-title,.about-title--xl{font-size:clamp(2.4rem,13vw,4.2rem);line-height:.98}
      .about-text,.about-lead{font-size:1.2rem;line-height:1.34}
      .about-compare-wrapper{margin-left:-.25rem;margin-right:-.25rem;border-radius:1rem}
      .about-compare{min-width:620px}
      .about-compare tbody tr:nth-child(-n+3){display:none}
      .about-compare-wrapper{display:none}
      .about-cards{display:flex;flex-direction:column;gap:1rem}
      .about-actions{flex-direction:column}
      .about-button{width:100%}
    }
    @media (prefers-reduced-motion:reduce){
      .about-snap{scroll-behavior:auto}
      .about-button{transition:none}
    }
  </style>
</head>
<body>

<a class="about-fixed-logo" href="{{ url('/') }}" aria-label="BouclePro">
  <img src="{{ asset('img/bouclepro-symbol.png') }}" alt="" aria-hidden="true">
  <span>BouclePro</span>
</a>

<nav class="about-nav" aria-label="Navigation">
  <a href="#s-besoin" class="about-nav__link">{{ __('about.nav_besoin') }}</a>
  <a href="#s-mission" class="about-nav__link">{{ __('about.nav_mission') }}</a>
  <a href="#s-boucle" class="about-nav__link">{{ __('about.nav_boucle') }}</a>
  <a href="#s-entraide" class="about-nav__link">{{ __('about.nav_transmission') }}</a>
  <a href="#s-memoire" class="about-nav__link">{{ __('about.nav_memoire') }}</a>
  <a href="#s-personnes" class="about-nav__link">{{ __('about.nav_personnes') }}</a>
  <a href="#s-positionnement" class="about-nav__link">{{ __('about.nav_positionnement') }}</a>
  <a href="#s-tableau" class="about-nav__link">{{ __('about.nav_tableau') }}</a>
  <a href="#s-cta" class="about-nav__link">{{ __('about.nav_cta') }}</a>
</nav>

<main class="about-snap">

  {{-- Screen 1 : Besoin --}}
  <section class="about-panel about-panel--hero" id="s-besoin">
    <div class="about-rings about-rings--besoin" aria-hidden="true"></div>
    <div class="about-panel__content about-panel__content--center">
      <p class="about-kicker">{{ __('about.s1_kicker') }}</p>
      <h1 class="about-title">{{ __('about.s1_title') }}</h1>
      <p class="about-text">{{ __('about.s1_text') }}</p>
      <p class="about-support">{{ __('about.s1_support') }}</p>
    </div>
  </section>

  {{-- Screen 2 : Mission --}}
  <section class="about-panel about-panel--blue" id="s-mission">
    <div class="about-rings about-rings--mission" aria-hidden="true"></div>
    <div class="about-panel__content about-panel__content--center">
      <p class="about-kicker">{{ __('about.s2_kicker') }}</p>
      <h2 class="about-title about-title--lg">{{ __('about.s2_title') }}</h2>
      <p class="about-text">{{ __('about.s2_text') }}</p>
      <p class="about-support">{{ __('about.s2_support') }}</p>
      <div class="about-cycle" role="list" aria-label="Cycle">
        @foreach(__('about.s2_cycle') as $i => $step)
          @if($i > 0)
          <span class="about-cycle__arrow" aria-hidden="true">→</span>
          @endif
          <span class="about-cycle__step{{ $i === 0 ? ' about-cycle__step--active' : '' }}">{{ $step }}</span>
        @endforeach
      </div>
    </div>
  </section>

  {{-- Screen 3 : Boucle --}}
  <section class="about-panel about-panel--dark" id="s-boucle">
    <div class="about-rings about-rings--dark" aria-hidden="true"></div>
    <div class="about-panel__content about-panel__content--center">
      <p class="about-kicker">{{ __('about.s3_kicker') }}</p>
      <h2 class="about-title about-title--lg">{{ __('about.s3_title') }}</h2>
      <p class="about-text">{{ __('about.s3_text') }}</p>
      <div class="about-loop-types" aria-label="{{ __('about.s3_kicker') }}">
        @foreach(__('about.loop_types') as $type)
        <span class="about-loop-type">{{ $type }}</span>
        @endforeach
      </div>
    </div>
  </section>

  {{-- Screen 4 : Transmission & compagnonnage --}}
  <section class="about-panel about-panel--violet" id="s-entraide">
    <div class="about-rings about-rings--transmission" aria-hidden="true"></div>
    <div class="about-panel__content about-panel__content--center">
      <p class="about-kicker">{{ __('about.s4_kicker') }}</p>
      <h2 class="about-title about-title--lg">{{ __('about.s4_title') }}</h2>
      <p class="about-text">{{ __('about.s4_text') }}</p>
      <p class="about-support">{{ __('about.s4_support') }}</p>
    </div>
  </section>

  {{-- Screen 5 : Mémoire collective --}}
  <section class="about-panel about-panel--blue" id="s-memoire">
    <div class="about-rings about-rings--memoire" aria-hidden="true"></div>
    <div class="about-panel__content about-panel__content--center">
      <p class="about-kicker">{{ __('about.s5_kicker') }}</p>
      <h2 class="about-title about-title--lg">{{ __('about.s5_title') }}</h2>
      <p class="about-text">{{ __('about.s5_text') }}</p>
      <p class="about-support">{{ __('about.s5_support') }}</p>
    </div>
  </section>

  {{-- Screen 6 : Personnes --}}
  <section class="about-panel about-panel--orange" id="s-personnes">
    <div class="about-rings about-rings--personnes" aria-hidden="true"></div>
    <div class="about-panel__content about-panel__content--center">
      <p class="about-kicker">{{ __('about.s6_kicker') }}</p>
      <h2 class="about-title about-title--lg">{{ __('about.s6_title') }}</h2>
      <p class="about-text">{{ __('about.s6_text') }}</p>
      <p class="about-text">{{ __('about.s6_punch') }}</p>
    </div>
  </section>

  {{-- Screen 7 : Positionnement --}}
  <section class="about-panel about-panel--dark" id="s-positionnement">
    <div class="about-rings about-rings--dark" aria-hidden="true"></div>
    <div class="about-panel__content">
      <p class="about-kicker">{{ __('about.s7_kicker') }}</p>
      <h2 class="about-title about-title--lg">{{ __('about.s7_title') }}</h2>
      <p class="about-punch">{{ __('about.s7_punch') }}</p>
      <p class="about-text">{{ __('about.s7_text') }}</p>
    </div>
  </section>

  {{-- Screen 8 : Tableau comparatif --}}
  <section class="about-panel about-panel--dark about-panel--table" id="s-tableau">
    <div class="about-rings about-rings--tableau" aria-hidden="true"></div>
    <div class="about-panel__content about-panel__content--wide">
      <div class="about-compare-wrapper about-compare-wrapper--m3" role="region" aria-label="{{ __('about.s8_kicker') }}" tabindex="0">
        <table class="about-compare">
          <thead>
            <tr>
              @foreach(__('about.s4_compare_headers') as $header)
              <th>{{ $header }}</th>
              @endforeach
            </tr>
          </thead>
          <tbody>
            @foreach(__('about.s4_compare_rows') as $row)
            <tr>
              <td>{{ $row['label'] }}</td>
              <td>{{ $row['linkedin'] }}</td>
              <td>{{ $row['reddit'] }}</td>
              <td>{{ $row['discord'] }}</td>
              <td class="is-strong">{{ $row['bouclepro'] }}</td>
            </tr>
            @endforeach
          </tbody>
        </table>
      </div>
      @php $platformKeys = ['linkedin','reddit','discord','bouclepro']; $platformNames = array_slice(__('about.s4_compare_headers'), 1); @endphp
      <div class="about-cards" role="region" aria-label="Comparison cards">
        @foreach(__('about.s4_compare_rows') as $row)
        <div class="about-card">
          <h3 class="about-card__title">{{ $row['label'] }}</h3>
          @foreach($platformNames as $i => $name)
          @php $val = $row[$platformKeys[$i]]; @endphp
          <div class="about-card__row{{ $name === 'BouclePro' ? ' about-card__row--highlight' : '' }}">
            <span class="about-card__platform">{{ $name }}</span>
            <span class="about-card__status">{{ $val }}</span>
          </div>
          @endforeach
        </div>
        @endforeach
      </div>
      <p class="about-legend">{{ __('about.s4_legend') }}</p>
    </div>
  </section>

  {{-- Screen 9 : Commencer --}}
  <section class="about-panel about-panel--final" id="s-cta">
    <div class="about-panel__content about-panel__content--center">
      <p class="about-kicker">{{ __('about.s9_kicker') }}</p>
      <h2 class="about-title about-title--lg">{{ __('about.s9_title') }}</h2>
      <p class="about-text">{{ __('about.s9_text') }}</p>
      <p class="about-support">{{ __('about.s9_support') }}</p>
      <div class="about-actions">
        <a href="{{ route('partenaires.request.create') }}" class="about-button about-button--primary">{{ __('about.cta_primary') }}</a>
        <a href="{{ route('partenaires.request.create') }}" class="about-button about-button--secondary">{{ __('about.cta_secondary') }}</a>
      </div>
    </div>
  </section>

  <footer class="about-footer">
    <a href="https://amteletravail.fr" target="_blank" rel="noopener">{{ __('footer.by_amt') }}</a>
    <span>·</span>
    <a href="{{ route('mentions-legales') }}">{{ __('footer.mentions_legales') }}</a>
    <span>·</span>
    <span>{{ config('app.version') }}</span>
  </footer>

</main>

<script>
(function(){
  var sections = document.querySelectorAll('.about-panel');
  var logo = document.querySelector('.about-fixed-logo');
  var navLinks = document.querySelectorAll('.about-nav__link');
  var map = {};
  navLinks.forEach(function(l){ map[l.getAttribute('href').slice(1)] = l; });

  var observer = new IntersectionObserver(function(entries){
    entries.forEach(function(e){
      if(e.isIntersecting){
        if(logo) logo.setAttribute('data-section', e.target.id);
        navLinks.forEach(function(l){ l.classList.remove('about-nav__link--active'); });
        var link = map[e.target.id];
        if(link) link.classList.add('about-nav__link--active');
      }
    });
  }, {threshold:0.3});

  sections.forEach(function(s){ observer.observe(s); });

  var deck = document.querySelector('.about-snap');
  navLinks.forEach(function(l){
    l.addEventListener('click', function(ev){
      ev.preventDefault();
      var t = document.getElementById(this.getAttribute('href').slice(1));
      if(t && deck){
        var top = t.getBoundingClientRect().top + deck.scrollTop - deck.getBoundingClientRect().top;
        deck.scrollTo({top:top, behavior:'smooth'});
      }
    });
  });
})();
</script>
</body>
</html>
