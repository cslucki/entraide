<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>{{ __('about.meta_title') }}</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Caveat:wght@500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@3.31.0/dist/tabler-icons.min.css">
  <style>
    :root{
      --ink:#0D1538;--ink-soft:#3A4060;--muted:#6B7186;--faint:#B7BAC8;
      --purple:#6B2CFF;--pink:#FF4F9A;--blue:#4D7CFF;--green:#56B254;--orange:#FF8A3D;
      --cream:#FAF7F0;--panel:#FFFFFF;--border:#ECE7DC;
      --ease:cubic-bezier(.22,.72,.24,1);
    }
    *{box-sizing:border-box}
    body{margin:0;background:var(--cream);color:var(--ink);font-family:'Inter',system-ui,sans-serif;-webkit-font-smoothing:antialiased}
    a{color:inherit;text-decoration:none}
    button{font-family:inherit}
    .about-page{min-height:100vh;overflow:hidden;background:radial-gradient(110% 95% at 88% 0%,#fff 0%,var(--cream) 50%,#F7EFE6 100%)}
    .wrap{max-width:1240px;margin:0 auto;padding:22px 44px 38px;position:relative}
    .glow{position:absolute;border-radius:999px;filter:blur(58px);pointer-events:none;opacity:.48}.g1{right:-120px;top:120px;width:340px;height:340px;background:#4D7CFF}.g2{left:-140px;top:420px;width:300px;height:300px;background:#FF4F9A}.g3{right:18%;bottom:80px;width:230px;height:230px;background:#56B254;opacity:.22}
    .nav{position:relative;z-index:5;display:flex;align-items:center;justify-content:space-between;margin-bottom:34px}.brand{display:flex;align-items:center;gap:11px;font-weight:900;font-size:23px;letter-spacing:-.03em}.flower{width:34px;height:34px;background:url('/img/bouclepro-symbol.png') center/contain no-repeat;display:inline-block}.nav-links{display:flex;align-items:center;gap:24px;font-size:15px;font-weight:700;color:var(--ink-soft)}.nav-links a:hover{color:var(--ink)}.nav-cta{background:#fff;border-radius:999px;padding:11px 22px;color:var(--ink);box-shadow:0 12px 30px -14px rgba(107,44,255,.65)}
    .hero{position:relative;z-index:2;display:grid;grid-template-columns:minmax(0,1fr) minmax(360px,.86fr);gap:44px;align-items:center}.eyebrow{font-size:13px;font-weight:900;letter-spacing:.28em;text-transform:uppercase;color:var(--purple);margin:0 0 20px}.hero h1{font-size:clamp(46px,6vw,84px);line-height:.93;letter-spacing:-.06em;margin:0;color:var(--ink);max-width:760px}.hero h1 .pink{color:var(--pink)}.intro{max-width:670px;margin:26px 0 0;font-size:18px;line-height:1.72;color:var(--muted);font-weight:500}.script{font-family:'Caveat',cursive;color:var(--muted);font-weight:700;line-height:1.05}.ai-line{margin:26px 0 0;font-size:34px;max-width:520px}.cta-row{display:flex;flex-wrap:wrap;gap:14px;margin-top:34px}.btn{display:inline-flex;align-items:center;gap:12px;border-radius:999px;font-weight:900;transition:transform .15s var(--ease),box-shadow .2s var(--ease)}.btn:hover{transform:translateY(-2px)}.btn-primary{background:var(--purple);color:#fff;padding:18px 28px;box-shadow:0 18px 42px -15px rgba(107,44,255,.7)}.btn-secondary{background:#fff;color:var(--ink);padding:16px 24px;box-shadow:0 12px 30px -18px rgba(13,21,56,.35);border:1px solid var(--border)}
    .orbit-art{position:relative;aspect-ratio:1/1;min-height:420px}.rings{position:absolute;inset:0;width:100%;height:100%;object-fit:contain;opacity:.8}.float-card{position:absolute;width:220px;border-radius:28px;padding:20px;box-shadow:0 28px 58px -28px rgba(20,24,60,.42)}.float-card i{display:grid;place-items:center;width:48px;height:48px;border-radius:999px;font-size:24px}.float-card strong{display:block;margin-top:18px;font-size:23px;line-height:1.03;letter-spacing:-.04em}.c1{left:3%;top:16%;background:#FCEAF1}.c1 i{background:#FBD7E5;color:var(--pink)}.c2{right:0;top:34%;background:#FFF1E0}.c2 i{background:#FFE2BD;color:var(--orange)}.c3{left:30%;bottom:8%;background:#EAF5E3}.c3 i{background:#D6EECC;color:var(--green)}
    .section-grid{position:relative;z-index:3;display:grid;grid-template-columns:repeat(3,1fr);gap:18px;margin-top:56px}.story-card{border-radius:32px;padding:26px;box-shadow:0 22px 54px -38px rgba(20,24,60,.42);border:1px solid rgba(255,255,255,.72)}.story-card h2{font-size:42px;margin:0}.story-card p{font-size:14.5px;line-height:1.72;color:var(--ink-soft);margin:20px 0 0}.story-card ul{list-style:none;padding:0;margin:22px 0 0;display:grid;gap:11px}.story-card li{display:flex;gap:10px;font-size:14px;font-weight:800}.story-card li::before{content:'';width:8px;height:8px;border-radius:999px;margin-top:6px;flex:0 0 auto}.tone-purple{background:#F2ECFF}.tone-purple li::before{background:var(--purple)}.tone-orange{background:#FFF1E0}.tone-orange li::before{background:var(--orange)}.tone-green{background:#EAF5E3}.tone-green li::before{background:var(--green)}
    .village{position:relative;z-index:3;margin-top:22px;display:grid;grid-template-columns:.86fr 1.14fr;gap:20px;background:rgba(255,255,255,.78);border:1px solid rgba(255,255,255,.72);border-radius:34px;padding:30px;box-shadow:0 26px 68px -44px rgba(20,24,60,.42)}.village h2{font-size:48px;margin:0}.village p{font-size:16px;line-height:1.78;color:var(--muted);margin:18px 0 0}.quotes{display:grid;grid-template-columns:1fr 1fr;gap:12px}.quote{background:var(--cream);border:1px solid var(--border);border-radius:24px;padding:20px;font-size:19px;font-weight:900;line-height:1.18;letter-spacing:-.04em}
    .compare{position:relative;z-index:3;margin-top:22px;background:var(--ink);color:#fff;border-radius:38px;padding:34px;box-shadow:0 36px 90px -44px rgba(13,21,56,.85)}.compare-head{display:grid;grid-template-columns:.9fr 1.1fr;gap:24px;align-items:end}.compare h2{font-size:52px;margin:0;color:var(--faint)}.compare p{margin:0;color:rgba(255,255,255,.68);font-size:15px;line-height:1.72}.accordion{display:grid;gap:10px;margin-top:28px}details{border-radius:26px;background:rgba(255,255,255,.065);border:1px solid rgba(255,255,255,.1);overflow:hidden}details[open]{background:rgba(255,255,255,.09)}summary{list-style:none;cursor:pointer;display:flex;align-items:center;justify-content:space-between;gap:18px;padding:20px 22px}summary::-webkit-details-marker{display:none}.need{font-size:18px;font-weight:900;letter-spacing:-.035em}.plus{width:38px;height:38px;border-radius:999px;background:rgba(255,255,255,.1);display:grid;place-items:center;transition:transform .18s var(--ease)}details[open] .plus{transform:rotate(45deg)}.compare-row{border-top:1px solid rgba(255,255,255,.1);padding:18px;display:grid;grid-template-columns:repeat(4,1fr);gap:12px}.compare-cell{border-radius:20px;background:rgba(255,255,255,.06);padding:16px;color:rgba(255,255,255,.78);font-size:13.5px;line-height:1.55}.compare-cell b{display:block;margin-bottom:10px;font-size:11px;text-transform:uppercase;letter-spacing:.18em;color:rgba(255,255,255,.42)}.compare-cell.bp{background:#fff;color:var(--ink);font-weight:700}.compare-cell.bp b{color:var(--purple)}
    .bottom-grid{position:relative;z-index:3;display:grid;grid-template-columns:.9fr .9fr 1.12fr;gap:18px;margin-top:22px}.info-card{background:#fff;border:1px solid var(--border);border-radius:32px;padding:28px}.info-card h2{font-size:40px;margin:0}.info-card p{font-size:14.5px;line-height:1.76;color:var(--muted);margin:18px 0 0}.final-card{background:var(--purple);color:#fff;border-radius:32px;padding:28px;box-shadow:0 28px 68px -34px rgba(107,44,255,.8)}.final-card h2{font-size:42px;margin:0;color:rgba(255,255,255,.78)}.final-card p{font-size:14.5px;line-height:1.74;color:rgba(255,255,255,.82);margin:18px 0 0}.final-card strong{display:block;margin-top:20px;font-size:20px;line-height:1.18;letter-spacing:-.035em}
    .foot{position:relative;z-index:3;display:flex;justify-content:space-between;gap:20px;align-items:center;margin-top:28px;color:#8B90A2;font-size:13px;font-weight:700}.foot a:hover{color:var(--ink)}
    @media (max-width:1000px){.wrap{padding:20px}.hero,.village,.compare-head,.bottom-grid{grid-template-columns:1fr}.section-grid{grid-template-columns:1fr}.orbit-art{max-width:520px;margin:0 auto}.compare-row{grid-template-columns:1fr 1fr}.nav-links{gap:14px}}
    @media (max-width:620px){.nav{align-items:flex-start}.brand{font-size:20px}.nav-links{flex-wrap:wrap;justify-content:flex-end;font-size:13px}.hero h1{font-size:46px}.ai-line{font-size:30px}.orbit-art{min-height:360px}.float-card{width:176px;padding:16px}.float-card strong{font-size:19px}.section-grid{margin-top:26px}.quotes,.compare-row{grid-template-columns:1fr}.compare,.village{padding:22px;border-radius:28px}.foot{flex-direction:column;align-items:flex-start}}
  </style>
</head>
<body>
<main class="about-page">
  <div class="wrap">
    <span class="glow g1"></span><span class="glow g2"></span><span class="glow g3"></span>

    <header class="nav">
      <a href="{{ route('home') }}" class="brand"><span class="flower"></span><span>BouclePro</span></a>
      <nav class="nav-links" aria-label="Navigation">
        <a href="{{ route('home') }}">{{ __('navigation.home') }}</a>
        <a href="https://bouclepro.com/demo">{{ __('footer.kit_demo') }}</a>
        <a href="{{ route('partenaires.request.create') }}" class="nav-cta">{{ __('about.cta_primary') }}</a>
      </nav>
    </header>

    <section class="hero">
      <div>
        <p class="eyebrow">{{ __('about.eyebrow') }}</p>
        <h1>{{ __('about.title') }}<span class="pink">.</span></h1>
        <p class="intro">{{ __('about.intro') }}</p>
        <p class="script ai-line">{{ __('about.ai_line') }}</p>
        <div class="cta-row">
          <a href="{{ route('partenaires.request.create') }}" class="btn btn-primary">{{ __('about.cta_primary') }} <i class="ti ti-arrow-right"></i></a>
          <a href="https://bouclepro.com/demo" class="btn btn-secondary"><i class="ti ti-player-play"></i>{{ __('about.cta_secondary') }}</a>
        </div>
      </div>
      <div class="orbit-art" aria-hidden="true">
        <img class="rings" src="{{ asset('img/boucle-rings.svg') }}" alt="">
        <div class="float-card c1"><i class="ti ti-heart"></i><strong>{{ __('about.proof_points.0') }}</strong></div>
        <div class="float-card c2"><i class="ti ti-link"></i><strong>{{ __('about.proof_points.1') }}</strong></div>
        <div class="float-card c3"><i class="ti ti-bulb"></i><strong>{{ __('about.proof_points.2') }}</strong></div>
      </div>
    </section>

    <div class="section-grid">
      @foreach(__('about.sections') as $section)
        <section class="story-card tone-{{ $section['tone'] }}">
          <h2 class="script">{{ $section['title'] }}</h2>
          <p>{{ $section['body'] }}</p>
          <ul>
            @foreach($section['items'] as $item)
              <li>{{ $item }}</li>
            @endforeach
          </ul>
        </section>
      @endforeach
    </div>

    <section class="village">
      <div>
        <h2 class="script">{{ __('about.village_title') }}</h2>
        <p>{{ __('about.village_body') }}</p>
      </div>
      <div class="quotes">
        @foreach(__('about.village_quotes') as $quote)
          <blockquote class="quote">“{{ $quote }}”</blockquote>
        @endforeach
      </div>
    </section>

    <section class="compare">
      <div class="compare-head">
        <h2 class="script">{{ __('about.comparison_title') }}</h2>
        <p>{{ __('about.comparison_intro') }}</p>
      </div>
      <div class="accordion">
        @foreach(__('about.comparison') as $row)
          <details @if($loop->first) open @endif>
            <summary><span class="need">{{ $row['need'] }}</span><span class="plus"><i class="ti ti-plus"></i></span></summary>
            <div class="compare-row">
              @foreach(['linkedin', 'slack', 'whatsapp', 'bouclepro'] as $column)
                <article class="compare-cell {{ $column === 'bouclepro' ? 'bp' : '' }}">
                  <b>{{ __('about.comparison_headers.' . (array_search($column, ['linkedin', 'slack', 'whatsapp', 'bouclepro'], true) + 1)) }}</b>
                  {{ $row[$column] }}
                </article>
              @endforeach
            </div>
          </details>
        @endforeach
      </div>
    </section>

    <div class="bottom-grid">
      <section class="info-card"><h2 class="script">{{ __('about.audience_title') }}</h2><p>{{ __('about.audience') }}</p></section>
      <section class="info-card"><h2 class="script">{{ __('about.cyberworkers_title') }}</h2><p>{{ __('about.cyberworkers') }}</p></section>
      <section class="final-card"><h2 class="script">{{ __('about.closing_title') }}</h2><p>{{ __('about.closing') }}</p><strong>{{ __('about.closing_line') }}</strong></section>
    </div>

    <footer class="foot">
      <a href="https://amteletravail.fr" target="_blank" rel="noopener">{{ __('footer.by_amt') }}</a>
      <span>{{ config('app.version') }}</span>
    </footer>
  </div>
</main>
</body>
</html>
