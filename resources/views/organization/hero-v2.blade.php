<?php $settings = $organization->homepage_settings ?? []; ?>
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>{{ $settings['headline'] ?? $organization->name }} — {{ $organization->platform_tagline ?? __('hero.meta_title') }}</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Caveat:wght@500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@3.31.0/dist/tabler-icons.min.css">
<link rel="stylesheet" href="{{ asset('css/bouclepro-hero.css') }}?v={{ filemtime(public_path('css/bouclepro-hero.css')) }}">
</head>
<body class="bp-hero-v2">
<div class="page">

  {{-- NAV --}}
  <header class="nav">
    <div class="brand"><span class="flower"></span><b>{{ $organization->name }}</b></div>
    <nav class="nav-right">
      <nav class="nav-menu" aria-label="Menu">
        @guest
          <a href="{{ route('organization.login', $organization) }}">{{ org_trans('hero.nav_login') }}</a>
          <a href="{{ route('organization.register', $organization) }}" class="btn-join">{{ org_trans('hero.nav_signup') }}</a>
        @else
          <a href="{{ route('organization.home', $organization) }}" class="btn-join">{{ org_trans('navigation.dashboard') }}</a>
        @endguest
        <div class="lang" aria-label="Langue / Language">
          @foreach (['fr' => 'FR', 'en' => 'EN'] as $code => $label)
            <form method="POST" action="{{ route('locale.switch', ['locale' => $code]) }}" class="inline">
              @csrf
              <button type="submit" @if(app()->getLocale() === $code) aria-current="true" @endif>{{ $label }}</button>
            </form>
          @endforeach
        </div>
      </nav>
      <button class="burger" aria-label="{{ __('common.open_menu') }}" aria-expanded="false" aria-controls="m-menu"><i class="ti ti-menu-2"></i></button>
    </nav>
  </header>

  {{-- MOBILE MENU --}}
  <div class="m-menu" id="m-menu" hidden>
    @guest
      <a href="{{ route('organization.login', $organization) }}">{{ org_trans('hero.nav_login') }}</a>
      <a href="{{ route('organization.register', $organization) }}" class="btn-join">{{ org_trans('hero.nav_signup') }}</a>
    @else
      <a href="{{ route('organization.home', $organization) }}">{{ org_trans('navigation.dashboard') }}</a>
    @endguest
    <div class="m-lang" aria-label="Langue / Language">
      @foreach (['fr' => 'FR', 'en' => 'EN'] as $code => $label)
        <form method="POST" action="{{ route('locale.switch', ['locale' => $code]) }}" style="display:inline">
          @csrf
          <button type="submit" @if(app()->getLocale() === $code) aria-current="true" @endif>{{ $label }}</button>
        </form>
      @endforeach
    </div>
    <a href="{{ route('organization.register', $organization) }}" class="btn-join">{{ org_trans('hero.nav_signup') }}</a>
  </div>

  {{-- HERO --}}
  <main class="hero">

    {{-- LEFT --}}
    <section class="lead">
      <h1 data-anim>
        <span class="word1">{{ $settings['word_1'] ?? org_trans('hero.word_1') }}</span><br>
        <span class="word2">{{ $settings['word_2'] ?? org_trans('hero.word_2') }}</span><br>
        <span class="word3">{{ $settings['word_3'] ?? org_trans('hero.word_3') }}</span><span class="dot">.</span>
      </h1>
      <p data-anim>{{ $settings['subheadline'] ?? org_trans('hero.intro') }}</p>
      <div class="tript" data-anim>
        <span class="t1">{{ org_trans('hero.tript_1') }}</span>
        <span class="dot"></span>
        <span class="t2">{{ org_trans('hero.tript_2') }}</span>
        <span class="dot"></span>
        <span class="t3">{{ org_trans('hero.tript_3') }}</span>
      </div>
      <div class="cta-row" data-anim>
        <a href="{{ $settings['primary_cta_url'] ?? route('organization.register', $organization) }}" class="cta-primary">{{ $settings['primary_cta_label'] ?? org_trans('hero.cta_primary') }} <i class="ti ti-arrow-right"></i></a>
        <a href="{{ $settings['secondary_cta_url'] ?? route('organization.loops.index', $organization) }}" class="cta-secondary"><i class="ti ti-circle-plus"></i>{{ $settings['secondary_cta_label'] ?? org_trans('hero.cta_secondary') }}</a>
      </div>
    </section>

    {{-- RIGHT : ORBIT --}}
    <section class="orbit" aria-hidden="true">
      <img class="rings-img" src="{{ asset('img/boucle-rings.svg') }}" alt="" width="600" height="600">

      <div class="slot slot-help" style="--d:0s;--a:0deg">
        <div class="hand">
          <a href="{{ route('organization.explorer', $organization) }}" class="ocard card-help" data-anim>
            <div class="top">
              <span class="ic"><i class="ti ti-heart"></i></span>
              <h3>{!! org_trans('hero.card_help') !!}</h3>
            </div>
            <div class="avatars">
              <img src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='33' height='33' viewBox='0 0 33 33'%3E%3Ccircle cx='16.5' cy='16.5' r='16.5' fill='%23FBD7E5'/%3E%3Ctext x='16.5' y='21' text-anchor='middle' font-size='14' font-weight='700' fill='%23FF4F9A'%3EA%3C/text%3E%3C/svg%3E" alt="">
              <img src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='33' height='33' viewBox='0 0 33 33'%3E%3Ccircle cx='16.5' cy='16.5' r='16.5' fill='%23FBD7E5'/%3E%3Ctext x='16.5' y='21' text-anchor='middle' font-size='14' font-weight='700' fill='%23FF4F9A'%3EB%3C/text%3E%3C/svg%3E" alt="">
              <img src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='33' height='33' viewBox='0 0 33 33'%3E%3Ccircle cx='16.5' cy='16.5' r='16.5' fill='%23FBD7E5'/%3E%3Ctext x='16.5' y='21' text-anchor='middle' font-size='14' font-weight='700' fill='%23FF4F9A'%3EC%3C/text%3E%3C/svg%3E" alt="">
              <span class="more">+12</span>
            </div>
          </a>
        </div>
      </div>

      <div class="slot slot-offer" style="--d:-17s;--a:90deg">
        <div class="hand">
          <a href="{{ route('organization.explorer', $organization) }}" class="ocard card-offer" data-anim>
            <div class="top">
              <span class="ic"><i class="ti ti-hand-stop"></i></span>
              <h3>{!! org_trans('hero.card_offer') !!}</h3>
            </div>
            <div class="avatars">
              <img src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='33' height='33' viewBox='0 0 33 33'%3E%3Ccircle cx='16.5' cy='16.5' r='16.5' fill='%23DCE4FF'/%3E%3Ctext x='16.5' y='21' text-anchor='middle' font-size='14' font-weight='700' fill='%234D7CFF'%3ED%3C/text%3E%3C/svg%3E" alt="">
              <img src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='33' height='33' viewBox='0 0 33 33'%3E%3Ccircle cx='16.5' cy='16.5' r='16.5' fill='%23DCE4FF'/%3E%3Ctext x='16.5' y='21' text-anchor='middle' font-size='14' font-weight='700' fill='%234D7CFF'%3EE%3C/text%3E%3C/svg%3E" alt="">
              <img src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='33' height='33' viewBox='0 0 33 33'%3E%3Ccircle cx='16.5' cy='16.5' r='16.5' fill='%23DCE4FF'/%3E%3Ctext x='16.5' y='21' text-anchor='middle' font-size='14' font-weight='700' fill='%234D7CFF'%3EF%3C/text%3E%3C/svg%3E" alt="">
              <span class="more">+8</span>
            </div>
          </a>
        </div>
      </div>

      <div class="slot slot-meet" style="--d:-34s;--a:180deg">
        <div class="hand">
          <a href="{{ route('organization.explorer', $organization) }}" class="ocard card-meet" data-anim>
            <div class="top">
              <span class="ic"><i class="ti ti-link"></i></span>
              <h3>{!! org_trans('hero.card_meet') !!}</h3>
            </div>
            <div class="avatars">
              <img src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='33' height='33' viewBox='0 0 33 33'%3E%3Ccircle cx='16.5' cy='16.5' r='16.5' fill='%23FFE2BD'/%3E%3Ctext x='16.5' y='21' text-anchor='middle' font-size='14' font-weight='700' fill='%23FF8A3D'%3EG%3C/text%3E%3C/svg%3E" alt="">
              <img src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='33' height='33' viewBox='0 0 33 33'%3E%3Ccircle cx='16.5' cy='16.5' r='16.5' fill='%23FFE2BD'/%3E%3Ctext x='16.5' y='21' text-anchor='middle' font-size='14' font-weight='700' fill='%23FF8A3D'%3EH%3C/text%3E%3C/svg%3E" alt="">
              <img src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='33' height='33' viewBox='0 0 33 33'%3E%3Ccircle cx='16.5' cy='16.5' r='16.5' fill='%23FFE2BD'/%3E%3Ctext x='16.5' y='21' text-anchor='middle' font-size='14' font-weight='700' fill='%23FF8A3D'%3EI%3C/text%3E%3C/svg%3E" alt="">
              <span class="more">+9</span>
            </div>
          </a>
        </div>
      </div>

      <div class="slot slot-create" style="--d:-51s;--a:270deg">
        <div class="hand">
          <a href="{{ route('organization.loops.index', $organization) }}" class="ocard card-create" data-anim>
            <div class="top">
              <span class="ic"><i class="ti ti-bulb"></i></span>
              <h3>{!! org_trans('hero.card_create') !!}</h3>
            </div>
            <div class="avatars">
              <img src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='33' height='33' viewBox='0 0 33 33'%3E%3Ccircle cx='16.5' cy='16.5' r='16.5' fill='%23D6EECC'/%3E%3Ctext x='16.5' y='21' text-anchor='middle' font-size='14' font-weight='700' fill='%2356B254'%3EJ%3C/text%3E%3C/svg%3E" alt="">
              <img src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='33' height='33' viewBox='0 0 33 33'%3E%3Ccircle cx='16.5' cy='16.5' r='16.5' fill='%23D6EECC'/%3E%3Ctext x='16.5' y='21' text-anchor='middle' font-size='14' font-weight='700' fill='%2356B254'%3EK%3C/text%3E%3C/svg%3E" alt="">
              <img src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='33' height='33' viewBox='0 0 33 33'%3E%3Ccircle cx='16.5' cy='16.5' r='16.5' fill='%23D6EECC'/%3E%3Ctext x='16.5' y='21' text-anchor='middle' font-size='14' font-weight='700' fill='%2356B254'%3EL%3C/text%3E%3C/svg%3E" alt="">
              <span class="more">+5</span>
            </div>
          </a>
        </div>
      </div>

      <div class="ai-note" data-anim>
        <svg width="34" height="40" viewBox="0 0 34 40" fill="none">
          <path d="M7 36 C 6 18, 14 8, 30 6" stroke="#B7BAC8" stroke-width="2" stroke-linecap="round"/>
          <path d="M21 4 L31 6 L27 15" stroke="#B7BAC8" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
        <p>{{ org_trans('hero.ai_note') }}</p>
      </div>
    </section>

  </main>

  {{-- FOOTER --}}
  <footer class="foot">
    <span class="foot-credit">{{ $settings['footer_contact_name'] ?? org_trans('hero.credit') }}</span>
    <span class="foot-beta"><span class="dot"></span>{{ org_trans('hero.beta') }}</span>
    <nav class="foot-links">
      <a href="{{ route('organization.home', $organization) }}">{{ org_trans('hero.legal') }}</a>
      <a href="https://github.com/cslucki/entraide" target="_blank" rel="noopener" aria-label="Code source sur GitHub">
        <svg width="19" height="19" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 .5C5.7.5.5 5.7.5 12c0 5.1 3.3 9.4 7.9 10.9.6.1.8-.2.8-.5v-2c-3.2.7-3.9-1.4-3.9-1.4-.5-1.3-1.3-1.7-1.3-1.7-1-.7.1-.7.1-.7 1.2.1 1.8 1.2 1.8 1.2 1 .1.8 1.7 2.5 1.4.1-.7.4-1.2.7-1.5-2.6-.3-5.3-1.3-5.3-5.7 0-1.3.5-2.3 1.2-3.1-.1-.3-.5-1.5.1-3.1 0 0 1-.3 3.3 1.2a11.5 11.5 0 0 1 6 0C17.3 4.5 18.3 4.8 18.3 4.8c.6 1.6.2 2.8.1 3.1.8.8 1.2 1.8 1.2 3.1 0 4.4-2.7 5.4-5.3 5.7.4.4.8 1.1.8 2.2v3.3c0 .3.2.6.8.5 4.6-1.5 7.9-5.8 7.9-10.9C23.5 5.7 18.3.5 12 .5z"/></svg>
      </a>
    </nav>
  </footer>
</div>

<script>
(function () {
  if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
  const els = [...document.querySelectorAll('[data-anim]')];
  const isCard = el => el.classList.contains('ocard');
  els.forEach((el, i) => {
    const frames = isCard(el)
      ? [{ opacity: 0 }, { opacity: 1 }]
      : [{ opacity: 0, transform: 'translateY(20px)' }, { opacity: 1, transform: 'translateY(0)' }];
    el.animate(frames, { duration: 640, delay: 120 + i * 95, easing: 'cubic-bezier(.22,.72,.24,1)', fill: 'backwards' });
  });
  setTimeout(() => els.forEach(el => el.getAnimations().forEach(a => {
    try { if (a.effect.getComputedTiming().iterations !== Infinity) a.finish(); } catch (e) {}
  })), 2200);
})();

(function () {
  const burger = document.querySelector('.burger');
  const menu = document.getElementById('m-menu');
  if (!burger || !menu) return;
  const close = () => { menu.hidden = true; burger.setAttribute('aria-expanded', 'false'); };
  const open = () => { menu.hidden = false; burger.setAttribute('aria-expanded', 'true'); };
  burger.addEventListener('click', e => { e.stopPropagation(); menu.hidden ? open() : close(); });
  document.addEventListener('click', e => { if (!menu.hidden && !menu.contains(e.target)) close(); });
  menu.querySelectorAll('a').forEach(a => a.addEventListener('click', close));
  document.addEventListener('keydown', e => { if (e.key === 'Escape') close(); });
})();
</script>
</body>
</html>
