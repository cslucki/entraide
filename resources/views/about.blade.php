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
    :root{--ink:#0D1538;--ink-soft:#3A4060;--muted:#6B7186;--purple:#6B2CFF;--pink:#FF4F9A;--blue:#4D7CFF;--green:#56B254;--orange:#FF8A3D;--cream:#FAF7F0;--ease:cubic-bezier(.22,.72,.24,1)}
    *{box-sizing:border-box;margin:0;padding:0}
    body{font-family:'Inter',system-ui,sans-serif;-webkit-font-smoothing:antialiased;color:var(--ink);overflow-x:hidden}
    a{color:inherit;text-decoration:none}
    .about-caveat{font-family:'Caveat',cursive}
    img,svg{display:block;max-width:100%}

    .section{min-height:100vh;display:grid;place-items:center;position:relative;overflow:hidden;padding:60px 40px}
    .section-inner{max-width:1000px;width:100%;position:relative;z-index:2;text-align:center}

    /* Hero rings full-bleed */
    .rings-hero{background:var(--cream)}
    .rings-hero .rings-bg{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;opacity:.55;transition:filter 1.2s var(--ease)}
    .rings-hero h1{font-size:clamp(36px,7vw,76px);font-weight:900;line-height:1.06;letter-spacing:-.06em;margin-bottom:24px}
    .rings-hero h1 .caveat{font-family:'Caveat',cursive;font-size:clamp(44px,8vw,92px);font-weight:700;display:block;margin-top:8px}
    .rings-hero .sub{font-size:clamp(17px,2.2vw,22px);color:var(--ink-soft);line-height:1.6;max-width:700px;margin:0 auto;font-weight:500}
    .rings-hero .cta-row{display:flex;flex-wrap:wrap;gap:14px;justify-content:center;margin-top:40px}

    /* Screen 2 — purple statement */
    .statement{background:var(--purple);color:#fff}
    .statement blockquote{font-size:clamp(24px,4vw,54px);font-weight:800;line-height:1.15;letter-spacing:-.03em;max-width:860px}

    /* Color sections */
    .color-section{padding:80px 40px;text-align:center;color:#fff}
    .color-section h2{font-size:clamp(32px,5vw,64px);font-weight:900;line-height:1.1;letter-spacing:-.04em;margin-bottom:24px}
    .color-section .sub{font-size:clamp(16px,1.8vw,20px);line-height:1.7;max-width:680px;margin:0 auto 32px;opacity:.9;font-weight:500}
    .color-section ul{list-style:none;display:flex;flex-wrap:wrap;gap:12px;justify-content:center;max-width:640px;margin:0 auto}
    .color-section li{background:rgba(255,255,255,.15);padding:12px 20px;border-radius:999px;font-size:15px;font-weight:700;backdrop-filter:blur(4px)}
    .c-purple{background:var(--purple)}
    .c-pink{background:var(--pink)}
    .c-orange{background:var(--orange)}
    .c-blue{background:var(--blue)}
    .c-green{background:var(--green)}

    /* Comparison accordion */
    .compare-section{background:var(--ink);color:#fff}
    .compare-section h2{font-size:clamp(28px,4vw,48px);font-weight:900;margin-bottom:12px}
    .compare-section>p{font-size:16px;color:rgba(255,255,255,.68);max-width:640px;margin:0 auto 36px}
    .accordion{max-width:900px;margin:0 auto;display:grid;gap:10px;text-align:left}
    details{border-radius:20px;background:rgba(255,255,255,.065);border:1px solid rgba(255,255,255,.1);overflow:hidden}
    details[open]{background:rgba(255,255,255,.09)}
    summary{list-style:none;cursor:pointer;display:flex;align-items:center;justify-content:space-between;padding:18px 22px}
    summary::-webkit-details-marker{display:none}
    .need{font-size:16px;font-weight:800}
    .plus{width:34px;height:34px;border-radius:50%;background:rgba(255,255,255,.1);display:grid;place-items:center;transition:transform .2s var(--ease);flex:0 0 auto}
    details[open] .plus{transform:rotate(45deg)}
    .compare-row{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;padding:0 18px 18px}
    .compare-cell{border-radius:16px;background:rgba(255,255,255,.06);padding:14px;font-size:13px;line-height:1.55;color:rgba(255,255,255,.78)}
    .compare-cell b{display:block;margin-bottom:8px;font-size:10px;text-transform:uppercase;letter-spacing:.18em;color:rgba(255,255,255,.42)}
    .compare-cell.bp{background:#fff;color:var(--ink);font-weight:700}
    .compare-cell.bp b{color:var(--purple)}

    /* Bottom cards */
    .bottom-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:18px;max-width:1000px;width:100%}
    .info-card{background:#fff;border:1px solid #ECE7DC;border-radius:28px;padding:28px;text-align:left}
    .info-card h2{font-family:'Caveat',cursive;font-size:38px;margin:0 0 16px}
    .info-card p{font-size:14px;line-height:1.7;color:var(--muted)}
    .final-card{background:var(--purple);color:#fff;border-radius:28px;padding:28px;text-align:left;box-shadow:0 28px 68px -34px rgba(107,44,255,.8)}
    .final-card h2{font-family:'Caveat',cursive;font-size:38px;margin:0 0 16px;color:rgba(255,255,255,.8)}
    .final-card p{font-size:14px;line-height:1.7;color:rgba(255,255,255,.82)}
    .final-card strong{display:block;margin-top:16px;font-size:18px;letter-spacing:-.03em}

    .btn{display:inline-flex;align-items:center;gap:10px;border-radius:999px;font-weight:800;transition:transform .15s var(--ease),box-shadow .2s var(--ease);padding:16px 26px;font-size:16px}
    .btn:hover{transform:translateY(-2px)}
    .btn-primary{background:var(--purple);color:#fff;box-shadow:0 18px 42px -15px rgba(107,44,255,.7)}
    .btn-secondary{background:#fff;color:var(--ink);border:1px solid #ECE7DC;box-shadow:0 12px 30px -18px rgba(13,21,56,.35)}

    .foot{text-align:center;padding:40px;font-size:13px;font-weight:700;color:#8B90A2;background:var(--cream)}
    .foot a:hover{color:var(--ink)}
    .foot span{margin:0 12px}

    /* Fade-in on scroll */
    .fade-in{opacity:0;transform:translateY(40px);transition:opacity .7s var(--ease),transform .7s var(--ease)}
    .fade-in.visible{opacity:1;transform:translateY(0)}

    @media (max-width:760px){.section{padding:40px 24px}.compare-row{grid-template-columns:1fr 1fr}.bottom-grid{grid-template-columns:1fr}}
    @media (max-width:480px){.rings-hero h1{font-size:28px}.rings-hero h1 .caveat{font-size:36px}.statement{min-height:80vh}}
  </style>
</head>
<body>

{{-- HERO : rings full-screen --}}
<section class="section rings-hero fade-in" id="hero">
  <img class="rings-bg" src="{{ asset('img/boucle-rings.svg') }}" alt="">
  <div class="section-inner">
    <h1>{{ __('about.intro') }}<span class="caveat">{{ __('about.ai_line') }}</span></h1>
    <p class="sub">{{ __('about.title') }}.</p>
    <div class="cta-row">
      <a href="{{ route('partenaires.request.create') }}" class="btn btn-primary">{{ __('about.cta_primary') }} <i class="ti ti-arrow-right"></i></a>
      <a href="https://bouclepro.com/demo" class="btn btn-secondary"><i class="ti ti-player-play"></i>{{ __('about.cta_secondary') }}</a>
    </div>
  </div>
</section>

{{-- STATEMENT : purple full-screen --}}
<section class="section statement fade-in">
  <div class="section-inner">
    <blockquote class="about-caveat">{{ __('about.intro') }}</blockquote>
  </div>
</section>

{{-- SECTIONS : each as a full-height color panel --}}
@foreach(__('about.sections') as $section)
<section class="section color-section c-{{ $section['tone'] }} fade-in">
  <div class="section-inner">
    <h2 class="about-caveat">{{ $section['title'] }}</h2>
    <p class="sub">{{ $section['body'] }}</p>
    <ul>
      @foreach($section['items'] as $item)
      <li>{{ $item }}</li>
      @endforeach
    </ul>
  </div>
</section>
@endforeach

{{-- COMPARISON --}}
<section class="section compare-section fade-in">
  <div class="section-inner">
    <h2 class="about-caveat">{{ __('about.comparison_title') }}</h2>
    <p>{{ __('about.comparison_intro') }}</p>
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
  </div>
</section>

{{-- BOTTOM CARDS --}}
<section class="section fade-in" style="background:var(--cream)">
  <div class="bottom-grid">
    <section class="info-card"><h2>{{ __('about.audience_title') }}</h2><p>{{ __('about.audience') }}</p></section>
    <section class="info-card"><h2>{{ __('about.cyberworkers_title') }}</h2><p>{{ __('about.cyberworkers') }}</p></section>
    <section class="final-card"><h2>{{ __('about.closing_title') }}</h2><p>{{ __('about.closing') }}</p><strong>{{ __('about.closing_line') }}</strong></section>
  </div>
</section>

<footer class="foot">
  <a href="https://amteletravail.fr" target="_blank" rel="noopener">{{ __('footer.by_amt') }}</a>
  <span>·</span>
  <a href="{{ route('mentions-legales') }}">{{ __('footer.mentions_legales') }}</a>
  <span>·</span>
  <span>{{ config('app.version') }}</span>
</footer>

<script>
document.addEventListener('DOMContentLoaded', () => {
  const observer = new IntersectionObserver((entries) => {
    entries.forEach(entry => {
      if (entry.isIntersecting) {
        entry.target.classList.add('visible');
      }
    });
  }, { threshold: 0.1 });
  document.querySelectorAll('.fade-in').forEach(el => observer.observe(el));
});
</script>
</body>
</html>