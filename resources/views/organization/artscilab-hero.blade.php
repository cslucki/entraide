<?php
$settings = $organization->homepage_settings ?? [];
$heroAvatars = collect($heroAvatars ?? []);
$avatar = fn (int $index) => $heroAvatars->get($index) ?? asset('img/bouclepro-symbol.png');
$settingText = fn (string $key, string $fallback) => filled($settings[$key] ?? null) && ! in_array($settings[$key], [
    'card_create_label', 'card_meet_label', 'card_help_label', 'card_offer_label',
    'ai_note', 'subheadline', 'headline_solid', 'headline_outline',
], true) ? $settings[$key] : org_trans($fallback);
$safeUrl = function (?string $url, string $fallback): string {
    if (! filled($url)) {
        return $fallback;
    }
    if (str_starts_with($url, '/') && ! str_starts_with($url, '//')) {
        return $url;
    }

    return filter_var($url, FILTER_VALIDATE_URL) !== false && parse_url($url, PHP_URL_SCHEME) === 'https' ? $url : $fallback;
};
$primaryCtaUrl = $safeUrl($settings['primary_cta_url'] ?? null, route('organization.register', $organization));
$secondaryCtaUrl = $safeUrl($settings['secondary_cta_url'] ?? null, route('organization.loops.index', $organization));
?>
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>{{ $organization->name }} — {{ org_trans('artscilab.meta_title') }}</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Archivo:wght@600;700;800;900&family=Caveat:wght@600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@3.31.0/dist/tabler-icons.min.css">
<link rel="stylesheet" href="{{ asset('css/artscilab-hero.css') }}?v={{ filemtime(public_path('css/artscilab-hero.css')) }}">
</head>
<body class="bp-artscilab">

{{-- DECORATIVE --}}
<div class="grid-plane"></div>
<div class="stars" id="bp-stars"></div>
<div class="mouse-light" id="bp-mlight"></div>

<div class="page">

  {{-- HEADER --}}
  <header class="nav">
    <div class="brand">
      <a href="{{ url('/') }}"><img class="logo" src="{{ asset('img/artscilab-icon.png') }}" alt="{{ $organization->name }}"></a>
    </div>
    <div class="nav-right">
      <div class="lang" aria-label="Langue / Language">
        @foreach (['fr' => 'FR', 'en' => 'EN'] as $code => $label)
          <form method="POST" action="{{ route('locale.switch', ['locale' => $code]) }}" class="inline">
            @csrf
            <button type="submit" @if(app()->getLocale() === $code) class="on" @endif>{{ $label }}</button>
          </form>
          @if (! $loop->last)<span class="sep">—</span>@endif
        @endforeach
      </div>
      <nav class="nav-links">
        <a href="{{ route('organization.about', $organization) }}">{{ org_trans('artscilab.nav_about') }}</a>
        @guest
          @if ($organization->is_public)
            <a href="{{ route('organization.login', $organization) }}">{{ org_trans('artscilab.nav_login') }}</a>
            <a href="{{ route('organization.register', $organization) }}" class="btn-join">{{ org_trans('artscilab.nav_join') }}</a>
          @endif
        @else
          <a href="{{ route('dashboard') }}" class="btn-join--outline">{{ org_trans('artscilab.nav_dashboard') }}</a>
        @endguest
      </nav>
    </div>
  </header>

  {{-- HERO --}}
  <main class="hero">
    <section class="lead">
      <h1 data-anim>
        <span class="solid">{{ $settingText('headline_solid', 'artscilab.headline_solid') }}</span>
        <span class="outline">{{ $settingText('headline_outline', 'artscilab.headline_outline') }}</span>
      </h1>
      <p data-anim>{{ $settingText('subheadline', 'artscilab.subheadline') }}</p>
    </section>

    <section class="stage" data-anim>
      <div class="orbit" id="bp-orbit">
        <div class="loop"></div>

        <a href="{{ route('organization.explorer', $organization).'?tab=requests' }}" class="ocard c1">
          <div class="top">
            <span class="ic"><i class="ti ti-hand-stop"></i></span>
            <span class="lbl">{{ $settingText('card_1_label', 'artscilab.card_1_label') }}</span>
          </div>
          <div class="ppl">
            <div class="avatars">
              <img src="{{ $avatar(0) }}" alt=""><img src="{{ $avatar(1) }}" alt=""><img src="{{ $avatar(2) }}" alt=""><img src="{{ $avatar(3) }}" alt="">
            </div>
            <span class="more">+4</span>
          </div>
        </a>

        <a href="{{ route('organization.explorer', $organization) }}" class="ocard c2">
          <div class="top">
            <span class="ic"><i class="ti ti-lifebuoy"></i></span>
            <span class="lbl">{{ $settingText('card_2_label', 'artscilab.card_2_label') }}</span>
          </div>
          <div class="ppl">
            <div class="avatars">
              <img src="{{ $avatar(4) }}" alt=""><img src="{{ $avatar(5) }}" alt=""><img src="{{ $avatar(6) }}" alt=""><img src="{{ $avatar(7) }}" alt="">
            </div>
            <span class="more">+7</span>
          </div>
        </a>

        <a href="{{ route('organization.members.index', $organization) }}" class="ocard c3">
          <div class="top">
            <span class="ic"><i class="ti ti-bulb"></i></span>
            <span class="lbl">{{ $settingText('card_3_label', 'artscilab.card_3_label') }}</span>
          </div>
          <div class="ppl">
            <div class="avatars">
              <img src="{{ $avatar(8) }}" alt=""><img src="{{ $avatar(9) }}" alt=""><img src="{{ $avatar(10) }}" alt=""><img src="{{ $avatar(11) }}" alt="">
            </div>
            <span class="more">+3</span>
          </div>
        </a>

        <a href="{{ route('organization.loops.index', $organization) }}" class="ocard c4">
          <div class="top">
            <span class="ic"><i class="ti ti-friends"></i></span>
            <span class="lbl">{{ $settingText('card_4_label', 'artscilab.card_4_label') }}</span>
          </div>
          <div class="ppl">
            <div class="avatars">
              <img src="{{ $avatar(12) }}" alt=""><img src="{{ $avatar(13) }}" alt=""><img src="{{ $avatar(14) }}" alt=""><img src="{{ $avatar(15) }}" alt="">
            </div>
            <span class="more">+6</span>
          </div>
        </a>

        <div class="ainote">
          <svg width="32" height="40" viewBox="0 0 34 40" fill="none" aria-hidden="true">
            <path d="M27 36 C 28 18, 20 8, 4 6" stroke="rgba(255,255,255,.82)" stroke-width="2" stroke-linecap="round"/>
            <path d="M13 4 L3 6 L7 15" stroke="rgba(255,255,255,.82)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
          </svg>
          <p>{!! $settingText('ai_note', 'artscilab.ai_note') !!}</p>
        </div>
      </div>
    </section>
  </main>

  {{-- FOOTER --}}
  <footer class="foot">
    <div class="foot-left">
      <a href="{{ route('organization.home', 'main') }}">{{ org_trans('artscilab.powered_by') }}</a>
      <img class="foot-symbol" src="{{ asset('img/bouclepro-symbol.png') }}" alt="" aria-hidden="true">
    </div>
    <nav class="foot-right">
      <a href="{{ route('mentions-legales') }}">{{ org_trans('artscilab.footer_terms') }}</a>
      <a href="https://github.com/cslucki/entraide" target="_blank" rel="noopener" aria-label="GitHub">
        <svg width="19" height="19" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 .5C5.7.5.5 5.7.5 12c0 5.1 3.3 9.4 7.9 10.9.6.1.8-.2.8-.5v-2c-3.2.7-3.9-1.4-3.9-1.4-.5-1.3-1.3-1.7-1.3-1.7-1-.7.1-.7.1-.7 1.2.1 1.8 1.2 1.8 1.2 1 .1.8 1.7 2.5 1.4.1-.7.4-1.2.7-1.5-2.6-.3-5.3-1.3-5.3-5.7 0-1.3.5-2.3 1.2-3.1-.1-.3-.5-1.5.1-3.1 0 0 1-.3 3.3 1.2a11.5 11.5 0 0 1 6 0C17.3 4.5 18.3 4.8 18.3 4.8c.6 1.6.2 2.8.1 3.1.8.8 1.2 1.8 1.2 3.1 0 4.4-2.7 5.4-5.3 5.7.4.4.8 1.1.8 2.2v3.3c0 .3.2.6.8.5 4.6-1.5 7.9-5.8 7.9-10.9C23.5 5.7 18.3.5 12 .5z"/></svg>
      </a>
    </nav>
  </footer>
</div>

<script src="{{ asset('js/artscilab-hero.js') }}?v={{ filemtime(public_path('js/artscilab-hero.js')) }}"></script>
</body>
</html>
