<?php
$settings = $organization->homepage_settings ?? [];
$demoUrl = 'https://bouclepro.com/demo';
$heroAvatars = collect($heroAvatars ?? []);
$cardLabel = fn (string $key, string $fallback) => filled($settings[$key] ?? null) ? e($settings[$key]) : org_trans($fallback);
$avatar = fn (int $index) => $heroAvatars->get($index) ?? asset('img/bouclepro-symbol.png');
$profileUrl = fn () => route('organization.profile.show', ['organization' => $organization, 'user' => auth()->user()]);
$settingsUrl = fn () => route('organization.profile.edit', $organization);
$bugReportUrl = route('organization.bug-reports.index', $organization);
$adminUrl = function () use ($organization): ?string {
    if (! auth()->check()) {
        return null;
    }

    if (auth()->user()->is_admin) {
        return route('admin.dashboard');
    }

    if ($organization->admin_id === auth()->id()) {
        return route('organization.admin.dashboard', $organization);
    }

    return null;
};
$settingText = fn (string $key, string $fallback, array $legacyPlaceholders = []) => filled($settings[$key] ?? null) && ! in_array($settings[$key], $legacyPlaceholders, true)
    ? $settings[$key]
    : org_trans($fallback);
$safeUrl = function (?string $url, string $fallback): string {
    if (! filled($url)) {
        return $fallback;
    }

    if (str_starts_with($url, '/') && ! str_starts_with($url, '//')) {
        return $url;
    }

    return filter_var($url, FILTER_VALIDATE_URL) !== false && parse_url($url, PHP_URL_SCHEME) === 'https'
        ? $url
        : $fallback;
};
$primaryCtaUrl = $safeUrl($settings['primary_cta_url'] ?? null, route('organization.register', $organization));
$secondaryCtaUrl = $safeUrl($settings['secondary_cta_url'] ?? null, route('organization.loops.index', $organization));
?>
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>{{ $organization->name }} — {{ $organization->platform_tagline ?? __('hero.meta_title') }}</title>
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
        <a href="{{ route('about') }}">{{ __('navigation.about') }}</a>
        <a href="{{ route('organization.subscriptions', $organization) }}">{{ __('navigation.subscriptions') }}</a>
        <div class="lang" aria-label="Langue / Language">
          @foreach (['fr' => 'FR', 'en' => 'EN'] as $code => $label)
            <form method="POST" action="{{ route('locale.switch', ['locale' => $code]) }}" class="inline">
              @csrf
              <button type="submit" @if(app()->getLocale() === $code) aria-current="true" @endif>{{ $label }}</button>
            </form>
          @endforeach
        </div>
        @guest
          <a href="{{ route('organization.login', $organization) }}">{{ org_trans('hero.nav_login') }}</a>
          <a href="{{ route('organization.register', $organization) }}" class="btn-join">{{ org_trans('hero.nav_signup') }}</a>
        @else
          <div class="user-menu">
            <button type="button" class="user-trigger" aria-label="{{ __('navigation.user_menu') }}" aria-expanded="false">
              <img src="{{ auth()->user()->avatar_url }}" alt="{{ auth()->user()->name }}">
            </button>
            <div class="user-dropdown" hidden>
              <div class="user-summary">
                <img src="{{ auth()->user()->avatar_url }}" alt="{{ auth()->user()->name }}">
                <div>
                  <strong>{{ auth()->user()->name }}</strong>
                  <span>{{ auth()->user()->points_balance }} pts</span>
                </div>
              </div>
              <a href="{{ route('dashboard') }}">{{ __('navigation.dashboard') }}</a>
              <a href="{{ $profileUrl() }}">{{ __('navigation.profile') }}</a>
              <a href="{{ $settingsUrl() }}">{{ __('navigation.settings') }}</a>
              <a href="{{ route('organization.points.index', $organization) }}">{{ __('navigation.points_history') }}</a>
              <a href="{{ route('organization.favorites.index', $organization) }}">{{ __('navigation.favorites') }}</a>
              <a href="{{ route('help') }}">{{ __('navigation.help') }}</a>
              <a href="{{ $bugReportUrl }}">{{ __('navigation.report_bug') }}</a>
              @if($adminUrl())
                <a href="{{ $adminUrl() }}">{{ __('navigation.administration') }}</a>
              @endif
              <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit">{{ __('navigation.logout') }}</button>
              </form>
              <a href="{{ route('mentions-legales') }}" class="user-dropdown-muted">{{ __('navigation.legal_notices') }}</a>
              <span class="user-dropdown-version">{{ __('navigation.version') }} {{ config('app.version') }}</span>
            </div>
          </div>
        @endguest
      </nav>
      <button class="burger" aria-label="{{ __('common.open_menu') }}" aria-expanded="false" aria-controls="m-menu"><i class="ti ti-menu-2"></i></button>
    </nav>
  </header>

  {{-- MOBILE MENU --}}
  <div class="m-menu" id="m-menu" hidden>
    <a href="{{ route('about') }}">{{ __('navigation.about') }}</a>
    <a href="{{ route('organization.subscriptions', $organization) }}">{{ __('navigation.subscriptions') }}</a>
    @guest
      <a href="{{ route('organization.login', $organization) }}">{{ org_trans('hero.nav_login') }}</a>
      <a href="{{ route('organization.register', $organization) }}" class="btn-join">{{ org_trans('hero.nav_signup') }}</a>
    @else
      <a href="{{ route('dashboard') }}">{{ org_trans('navigation.dashboard') }}</a>
      <a href="{{ $profileUrl() }}">{{ __('navigation.profile') }}</a>
      <a href="{{ $settingsUrl() }}">{{ __('navigation.settings') }}</a>
      <a href="{{ route('organization.points.index', $organization) }}">{{ __('navigation.points_history') }}</a>
      <a href="{{ route('organization.favorites.index', $organization) }}">{{ __('navigation.favorites') }}</a>
      <a href="{{ route('help') }}">{{ __('navigation.help') }}</a>
      <a href="{{ $bugReportUrl }}">{{ __('navigation.report_bug') }}</a>
      @if($adminUrl())
        <a href="{{ $adminUrl() }}">{{ __('navigation.administration') }}</a>
      @endif
      <a href="{{ route('mentions-legales') }}">{{ __('navigation.legal_notices') }}</a>
      <span class="m-version">{{ __('navigation.version') }} {{ config('app.version') }}</span>
    @endguest
    <div class="m-lang" aria-label="Langue / Language">
      @foreach (['fr' => 'FR', 'en' => 'EN'] as $code => $label)
        <form method="POST" action="{{ route('locale.switch', ['locale' => $code]) }}" style="display:inline">
          @csrf
          <button type="submit" @if(app()->getLocale() === $code) aria-current="true" @endif>{{ $label }}</button>
        </form>
      @endforeach
    </div>
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
      <p data-anim>{{ $settingText('subheadline', 'hero.intro', ['Subheadline (sous-titre)']) }}</p>
      <div class="tript" data-anim>
        <span class="t1">{{ org_trans('hero.tript_1') }}</span>
        <span class="dot"></span>
        <span class="t2">{{ org_trans('hero.tript_2') }}</span>
        <span class="dot"></span>
        <span class="t3">{{ org_trans('hero.tript_3') }}</span>
      </div>
      <div class="cta-row" data-anim>
        <a href="{{ $primaryCtaUrl }}" class="cta-primary">{{ $settingText('primary_cta_label', 'hero.cta_primary', ['CTA primaire — label']) }} <i class="ti ti-arrow-right"></i></a>
        <a href="{{ $secondaryCtaUrl }}" class="cta-secondary"><i class="ti ti-circle-plus"></i>{{ $settingText('secondary_cta_label', 'hero.cta_secondary', ['CTA secondaire — label']) }}</a>
      </div>
    </section>

    {{-- RIGHT : ORBIT --}}
    <section class="orbit" aria-hidden="true">
      <img class="rings-img" src="{{ asset('img/boucle-rings.svg') }}" alt="" width="600" height="600">

      <div class="slot slot-help" style="--d:0s;--a:0deg">
        <div class="hand">
          <a href="{{ $demoUrl }}" class="ocard card-help" data-anim>
            <div class="top">
              <span class="ic"><i class="ti ti-heart"></i></span>
              <h3>{!! $cardLabel('card_help_label', 'hero.card_help') !!}</h3>
            </div>
            <div class="avatars">
              <img src="{{ $avatar(0) }}" alt="">
              <img src="{{ $avatar(1) }}" alt="">
              <img src="{{ $avatar(2) }}" alt="">
              <span class="more">+12</span>
            </div>
          </a>
        </div>
      </div>

      <div class="slot slot-offer" style="--d:-17s;--a:90deg">
        <div class="hand">
          <a href="{{ $demoUrl }}" class="ocard card-offer" data-anim>
            <div class="top">
              <span class="ic"><i class="ti ti-hand-stop"></i></span>
              <h3>{!! $cardLabel('card_offer_label', 'hero.card_offer') !!}</h3>
            </div>
            <div class="avatars">
              <img src="{{ $avatar(3) }}" alt="">
              <img src="{{ $avatar(4) }}" alt="">
              <img src="{{ $avatar(5) }}" alt="">
              <span class="more">+8</span>
            </div>
          </a>
        </div>
      </div>

      <div class="slot slot-meet" style="--d:-34s;--a:180deg">
        <div class="hand">
          <a href="{{ $demoUrl }}" class="ocard card-meet" data-anim>
            <div class="top">
              <span class="ic"><i class="ti ti-link"></i></span>
              <h3>{!! $cardLabel('card_meet_label', 'hero.card_meet') !!}</h3>
            </div>
            <div class="avatars">
              <img src="{{ $avatar(6) }}" alt="">
              <img src="{{ $avatar(7) }}" alt="">
              <img src="{{ $avatar(8) }}" alt="">
              <span class="more">+9</span>
            </div>
          </a>
        </div>
      </div>

      <div class="slot slot-create" style="--d:-51s;--a:270deg">
        <div class="hand">
          <a href="{{ $demoUrl }}" class="ocard card-create" data-anim>
            <div class="top">
              <span class="ic"><i class="ti ti-bulb"></i></span>
              <h3>{!! $cardLabel('card_create_label', 'hero.card_create') !!}</h3>
            </div>
            <div class="avatars">
              <img src="{{ $avatar(9) }}" alt="">
              <img src="{{ $avatar(10) }}" alt="">
              <img src="{{ $avatar(11) }}" alt="">
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
        <p>{{ $settings['ai_note'] ?? org_trans('hero.ai_note') }}</p>
      </div>
    </section>

  </main>

  {{-- FOOTER --}}
  <footer class="foot">
    <a class="foot-credit" href="https://amteletravail.fr" target="_blank" rel="noopener">{{ __('footer.by_amt') }}</a>
    <nav class="foot-links">
      <a href="{{ route('mentions-legales') }}">{{ __('footer.mentions_legales') }}</a>
      <a href="{{ $demoUrl }}">{{ __('footer.kit_demo') }}</a>
      <a href="https://github.com/cslucki/entraide" target="_blank" rel="noopener">{{ __('footer.opensource') }}</a>
      <a href="{{ route('organization.bug-reports.index', $organization) }}">{{ __('footer.bug') }}</a>
      <a href="https://github.com/cslucki/entraide" target="_blank" rel="noopener" aria-label="Code source sur GitHub">
        <svg width="19" height="19" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 .5C5.7.5.5 5.7.5 12c0 5.1 3.3 9.4 7.9 10.9.6.1.8-.2.8-.5v-2c-3.2.7-3.9-1.4-3.9-1.4-.5-1.3-1.3-1.7-1.3-1.7-1-.7.1-.7.1-.7 1.2.1 1.8 1.2 1.8 1.2 1 .1.8 1.7 2.5 1.4.1-.7.4-1.2.7-1.5-2.6-.3-5.3-1.3-5.3-5.7 0-1.3.5-2.3 1.2-3.1-.1-.3-.5-1.5.1-3.1 0 0 1-.3 3.3 1.2a11.5 11.5 0 0 1 6 0C17.3 4.5 18.3 4.8 18.3 4.8c.6 1.6.2 2.8.1 3.1.8.8 1.2 1.8 1.2 3.1 0 4.4-2.7 5.4-5.3 5.7.4.4.8 1.1.8 2.2v3.3c0 .3.2.6.8.5 4.6-1.5 7.9-5.8 7.9-10.9C23.5 5.7 18.3.5 12 .5z"/></svg>
      </a>
      <span>{{ config('app.version') }}</span>
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

(function () {
  const menu = document.querySelector('.user-menu');
  if (!menu) return;
  const trigger = menu.querySelector('.user-trigger');
  const dropdown = menu.querySelector('.user-dropdown');
  const close = () => { dropdown.hidden = true; trigger.setAttribute('aria-expanded', 'false'); };
  const open = () => { dropdown.hidden = false; trigger.setAttribute('aria-expanded', 'true'); };
  trigger.addEventListener('click', e => { e.stopPropagation(); dropdown.hidden ? open() : close(); });
  document.addEventListener('click', e => { if (!dropdown.hidden && !menu.contains(e.target)) close(); });
  document.addEventListener('keydown', e => { if (e.key === 'Escape') close(); });
})();
</script>
</body>
</html>
