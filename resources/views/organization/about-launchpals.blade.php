<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>About — LaunchPals</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
  <style>
    *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
    body{font-family:'Inter',system-ui,sans-serif;-webkit-font-smoothing:antialiased;color:#fff;background:#1a1a1a}
    a{color:inherit;text-decoration:none}

    .lp-snap{height:100vh;overflow-y:auto;scroll-snap-type:y mandatory;scroll-behavior:smooth;background:#f9f6f0;color:#1a1a1a}
    .lp-panel{position:relative;min-height:100vh;scroll-snap-align:start;display:flex;align-items:center;justify-content:flex-start;overflow:hidden;padding:clamp(2rem,5vw,6rem)}
    .lp-panel__content{position:relative;z-index:2;width:min(100%,980px);margin:0}
    .lp-panel__content--center{text-align:left}
    .lp-panel__content--wide{width:min(100%,1180px)}

    .lp-kicker{margin:0 0 1.25rem;font-size:clamp(.75rem,1vw,.95rem);letter-spacing:.18em;text-transform:uppercase;opacity:.72}
    .lp-title{margin:0;font-size:clamp(3rem,8vw,4.5rem);line-height:.92;letter-spacing:-.06em;font-weight:760}
    .lp-title--xl{font-size:clamp(3.5rem,9vw,4.5rem)}
    .lp-title--lg{font-size:clamp(2.5rem,6vw,4.5rem)}
    .lp-text,.lp-lead{max-width:840px;margin:2rem 0 0;font-size:clamp(1.25rem,2vw,2.1rem);line-height:1.22;letter-spacing:-.03em;opacity:.9}
    .lp-text + .lp-text{margin-top:.7em;opacity:.74}
    .lp-support{max-width:760px;margin:1.2rem 0 0;font-size:clamp(1rem,1.45vw,1.35rem);line-height:1.35;letter-spacing:-.02em;opacity:.72}
    .lp-punch{max-width:760px;margin:1.35rem 0 0;font-size:clamp(1.1rem,1.8vw,1.65rem);line-height:1.25;font-weight:800;letter-spacing:-.03em;opacity:.95}
    .lp-tags{display:flex;flex-wrap:wrap;gap:.75rem;margin-top:1.8rem;justify-content:flex-start}

    .lp-fixed-logo{position:fixed;top:1.5rem;left:1.5rem;z-index:30;display:inline-flex;align-items:center;gap:.65rem;padding:.55rem .8rem .55rem .6rem;border-radius:999px;font-size:.92rem;font-weight:850;letter-spacing:-.03em;transition:background .35s,color .35s,border-color .35s;background:rgba(255,255,255,.14);color:#1a1a1a;border:1px solid rgba(0,0,0,.12);box-shadow:0 4px 24px rgba(0,0,0,.08);backdrop-filter:blur(12px)}
    .lp-fixed-logo img{width:1.85rem;height:1.85rem;display:block;border-radius:999px}

    .lp-nav{position:fixed;top:50%;right:clamp(6px,1.2vw,18px);transform:translateY(-50%);z-index:25;display:flex;flex-direction:column;gap:10px;mix-blend-mode:difference;pointer-events:none}
    .lp-nav__link{display:block;padding:6px 10px;border-radius:6px;font-size:clamp(.9rem,1.8vw,2.7rem);font-weight:750;letter-spacing:.06em;text-transform:uppercase;color:#fff;text-decoration:none;text-align:right;opacity:.3;transition:opacity .35s;pointer-events:auto;white-space:nowrap;line-height:1}
    .lp-nav__link:hover{opacity:.7}
    .lp-nav__link--active{opacity:1}

    .lp-tag{display:inline-flex;align-items:center;gap:.55rem;padding:.7rem 1rem;border-radius:999px;border:1px solid rgba(0,0,0,.12);background:rgba(255,255,255,.08);font-size:clamp(.9rem,1.1vw,1rem);font-weight:700}
    .lp-tag::before{content:'';width:.75rem;height:.75rem;border-radius:50%;flex:0 0 auto}
    .lp-tag:nth-child(1)::before{background:#e87500}
    .lp-tag:nth-child(2)::before{background:#154734}
    .lp-tag:nth-child(3)::before{background:#5fe0b7}
    .lp-tag:nth-child(4)::before{background:#e87500}
    .lp-tag:nth-child(5)::before{background:#154734}
    .lp-tag:nth-child(6)::before{background:#5fe0b7}

    .lp-section-tags{display:flex;flex-wrap:wrap;gap:.45rem;margin-top:1.5rem}
    .lp-section-tag{padding:.35rem .75rem;border-radius:999px;font-size:clamp(.7rem,.9vw,.8rem);font-weight:700;letter-spacing:.06em;text-transform:uppercase;border:1px solid rgba(255,255,255,.25);background:rgba(255,255,255,.1)}

    .lp-cycle{display:flex;flex-wrap:wrap;align-items:center;gap:10px 12px;margin-top:clamp(28px,4vh,44px)}
    .lp-cycle__step{padding:10px 18px;border-radius:999px;font-weight:700;font-size:clamp(.9rem,1.6vw,1.1rem);background:rgba(255,255,255,.14);border:1px solid rgba(255,255,255,.28)}
    .lp-cycle__step--active{background:#154734;color:#fff;border-color:#154734}
    .lp-cycle__arrow{opacity:.6;font-weight:700;font-size:1.1rem}

    .lp-four-ways{display:grid;grid-template-columns:1fr 1fr;gap:1.25rem;margin-top:2rem}
    .lp-four-way{padding:1.5rem;border-radius:1.25rem;border:1px solid rgba(0,0,0,.1);background:rgba(255,255,255,.06)}
    .lp-four-way__title{margin:0 0 .5rem;font-size:1.1rem;font-weight:800;letter-spacing:-.02em}
    .lp-four-way__text{margin:0;font-size:.95rem;line-height:1.4;opacity:.78}

    .lp-success-list{margin:1.5rem 0 0;padding:0;list-style:none;display:flex;flex-direction:column;gap:.75rem}
    .lp-success-list li{padding-left:1.5rem;position:relative;font-size:clamp(1rem,1.4vw,1.2rem);line-height:1.35;opacity:.85}
    .lp-success-list li::before{content:'*';position:absolute;left:0;font-weight:800;color:#e87500}

    /* ── Section backgrounds ── */
    .lp-panel--hero{background:#f9f6f0;color:#1a1a1a}
    .lp-panel--green{background:#154734}
    .lp-panel--orange{background:#e87500}
    .lp-panel--light{background:#f9f6f0;color:#1a1a1a}
    .lp-panel--dark{background:#2d2a24}
    .lp-panel--final{background:linear-gradient(135deg,#154734 0%,#e87500 100%)}

    .lp-actions{display:flex;gap:1rem;flex-wrap:wrap;margin-top:2.5rem;justify-content:flex-start}
    .lp-button{display:inline-flex;align-items:center;justify-content:center;min-height:3.25rem;padding:0 1.4rem;border-radius:999px;text-decoration:none;font-weight:700;transition:transform 180ms ease,background 180ms ease,color 180ms ease}
    .lp-button:hover{transform:translateY(-2px)}
    .lp-button--primary{background:#fff;color:#154734}
    .lp-button--secondary{border:2px solid rgba(255,255,255,.6);color:#fff}

    .lp-footer{text-align:center;padding:2rem 1rem;font-size:13px;font-weight:700;color:rgba(255,255,255,.5);background:#2d2a24;border-top:1px solid rgba(255,255,255,.08)}
    .lp-footer a:hover{color:#fff}
    .lp-footer span{margin:0 12px}

    @media (max-width:768px){
      .lp-snap{height:100dvh;overflow-y:auto;scroll-snap-type:y mandatory}
      .lp-panel{min-height:100dvh;scroll-snap-align:start;padding:4rem 1.25rem}
      .lp-nav{display:none}
      .lp-four-ways{grid-template-columns:1fr}
      .lp-fixed-logo{top:.85rem;left:.85rem;padding:.45rem .7rem .45rem .5rem;font-size:.85rem}
      .lp-fixed-logo img{width:1.55rem;height:1.55rem}
      .lp-title,.lp-title--xl{font-size:clamp(2.4rem,13vw,4.2rem);line-height:.98}
      .lp-text,.lp-lead{font-size:1.2rem;line-height:1.34}
      .lp-actions{flex-direction:column}
      .lp-button{width:100%}
    }
    @media (prefers-reduced-motion:reduce){
      .lp-snap{scroll-behavior:auto}
      .lp-button{transition:none}
    }
  </style>
</head>
<body>

<a class="lp-fixed-logo" href="{{ route('organization.home', $organization) }}" aria-label="LaunchPals">
  <img src="{{ asset('img/bouclepro-symbol.png') }}" alt="" aria-hidden="true">
  <span>LaunchPals</span>
</a>

<nav class="lp-nav" aria-label="Navigation">
  <a href="#s-hero" class="lp-nav__link">Village</a>
  <a href="#s-mission" class="lp-nav__link">Mission</a>
  <a href="#s-loop" class="lp-nav__link">Loop</a>
  <a href="#s-ways" class="lp-nav__link">Ways</a>
  <a href="#s-mycelium" class="lp-nav__link">Network</a>
  <a href="#s-memory" class="lp-nav__link">Memory</a>
  <a href="#s-position" class="lp-nav__link">Position</a>
  <a href="#s-success" class="lp-nav__link">Success</a>
  <a href="#s-future" class="lp-nav__link">Future</a>
  <a href="#s-cta" class="lp-nav__link">Join</a>
</nav>

<main class="lp-snap">

  {{-- Section 1 : HERO --}}
  <section class="lp-panel lp-panel--hero" id="s-hero">
    <div class="lp-panel__content lp-panel__content--center">
      <p class="lp-kicker">LaunchPals</p>
      <h1 class="lp-title">A living digital village for ArtSciLab.</h1>
      <p class="lp-text">LaunchPals reconnects ArtSciLab members, alumni, collaborators and friends through trusted circles of mutual support, curiosity and collaborative action.</p>
      <p class="lp-support">Not another social network. A living memory for a community that keeps creating.</p>
      <p class="lp-punch">A question, a skill, an intuition — and the right connection can become action.</p>
      <div class="lp-section-tags">
        <span class="lp-section-tag">Community</span>
        <span class="lp-section-tag">Memory</span>
        <span class="lp-section-tag">Curiosity</span>
        <span class="lp-section-tag">Support</span>
        <span class="lp-section-tag">Collaboration</span>
        <span class="lp-section-tag">Action</span>
      </div>
    </div>
  </section>

  {{-- Section 2 : THE MISSION --}}
  <section class="lp-panel lp-panel--green" id="s-mission">
    <div class="lp-panel__content lp-panel__content--center">
      <p class="lp-kicker">The Mission</p>
      <h2 class="lp-title lp-title--lg">From dispersed people to meaningful collaboration.</h2>
      <p class="lp-text">ArtSciLab has gathered artists, scientists, designers, researchers, writers, engineers and cultural innovators across many years.</p>
      <p class="lp-support">But networks disperse. People move. Projects evolve. Ideas remain unfinished. Useful connections become dormant.</p>
      <p class="lp-punch">LaunchPals helps reactivate those links.</p>
      <p class="lp-support">The goal is not more online activity. The goal is meaningful human movement.</p>
      <div class="lp-section-tags">
        <span class="lp-section-tag">Alumni</span>
        <span class="lp-section-tag">Projects</span>
        <span class="lp-section-tag">Signals</span>
        <span class="lp-section-tag">Reconnection</span>
        <span class="lp-section-tag">Trust</span>
        <span class="lp-section-tag">Movement</span>
      </div>
    </div>
  </section>

  {{-- Section 3 : THE LOOP --}}
  <section class="lp-panel lp-panel--orange" id="s-loop">
    <div class="lp-panel__content lp-panel__content--center">
      <p class="lp-kicker">The Loop, Concretely</p>
      <h2 class="lp-title lp-title--lg">Small circles. Not a crowd.</h2>
      <p class="lp-text">LaunchPals is built around trusted circles where members can ask, offer, share and connect.</p>
      <p class="lp-support">A circle is not a noisy group chat. It is a lightweight space for qualified cooperation.</p>
      <p class="lp-support">AI can help clarify, summarize and suggest. Humans always validate before anything important is shared.</p>
      <p class="lp-punch">The loop keeps the signal. The feed loses it.</p>
      <div class="lp-section-tags">
        <span class="lp-section-tag">Circle</span>
        <span class="lp-section-tag">Context</span>
        <span class="lp-section-tag">Clarity</span>
        <span class="lp-section-tag">Validation</span>
        <span class="lp-section-tag">Cooperation</span>
        <span class="lp-section-tag">Trust</span>
      </div>
    </div>
  </section>

  {{-- Section 4 : FOUR WAYS TO PARTICIPATE --}}
  <section class="lp-panel lp-panel--light" id="s-ways">
    <div class="lp-panel__content lp-panel__content--center">
      <p class="lp-kicker">How Members Contribute</p>
      <h2 class="lp-title lp-title--lg">Four simple ways to enter the loop.</h2>
      <p class="lp-text">LaunchPals is organized around four forms of exchange:</p>
      <div class="lp-four-ways">
        <div class="lp-four-way">
          <h3 class="lp-four-way__title">I can help with…</h3>
          <p class="lp-four-way__text">Share skills, experience, contacts, methods or resources.</p>
        </div>
        <div class="lp-four-way">
          <h3 class="lp-four-way__title">I am looking for help with…</h3>
          <p class="lp-four-way__text">Turn a vague need into a clear, actionable request.</p>
        </div>
        <div class="lp-four-way">
          <h3 class="lp-four-way__title">I am currently fascinated by…</h3>
          <p class="lp-four-way__text">Make visible the questions, ideas and intuitions that may lead to new work.</p>
        </div>
        <div class="lp-four-way">
          <h3 class="lp-four-way__title">I think these two people should meet…</h3>
          <p class="lp-four-way__text">Suggest useful introductions and unexpected collaborations.</p>
        </div>
      </div>
      <p class="lp-punch">A small signal can become a real collaboration.</p>
      <div class="lp-section-tags">
        <span class="lp-section-tag">Help</span>
        <span class="lp-section-tag">Need</span>
        <span class="lp-section-tag">Curiosity</span>
        <span class="lp-section-tag">Introduction</span>
        <span class="lp-section-tag">Signal</span>
        <span class="lp-section-tag">Collaboration</span>
      </div>
    </div>
  </section>

  {{-- Section 5 : MYCELIAL NETWORK --}}
  <section class="lp-panel lp-panel--green" id="s-mycelium">
    <div class="lp-panel__content lp-panel__content--center">
      <p class="lp-kicker">The Network Idea</p>
      <h2 class="lp-title lp-title--lg">A mycelial network of mutual support.</h2>
      <p class="lp-text">LaunchPals is inspired by mycelium: a living network that connects signals, resources and possibilities beneath the surface.</p>
      <p class="lp-support">A member may have the knowledge another one needs. A former student may hold a missing experience. Two people may share the same question without knowing it.</p>
      <p class="lp-punch">LaunchPals makes those hidden bridges easier to discover.</p>
      <p class="lp-support">Useful collaborations often begin as weak signals.</p>
      <div class="lp-section-tags">
        <span class="lp-section-tag">Mycelium</span>
        <span class="lp-section-tag">Weak Signals</span>
        <span class="lp-section-tag">Bridges</span>
        <span class="lp-section-tag">Relations</span>
        <span class="lp-section-tag">Discovery</span>
        <span class="lp-section-tag">Support</span>
      </div>
    </div>
  </section>

  {{-- Section 6 : LIVING MEMORY --}}
  <section class="lp-panel lp-panel--orange" id="s-memory">
    <div class="lp-panel__content lp-panel__content--center">
      <p class="lp-kicker">Community Memory</p>
      <h2 class="lp-title lp-title--lg">Not a content feed. A living memory.</h2>
      <p class="lp-text">LaunchPals helps preserve projects, publications, prototypes, conversations, questions and unfinished ideas.</p>
      <p class="lp-support">What has been learned once should not disappear in a forgotten thread.</p>
      <p class="lp-support">The community becomes easier to navigate, support and remember.</p>
      <p class="lp-punch">The feed passes. The loop remembers.</p>
      <div class="lp-section-tags">
        <span class="lp-section-tag">Archive</span>
        <span class="lp-section-tag">Memory</span>
        <span class="lp-section-tag">Projects</span>
        <span class="lp-section-tag">Context</span>
        <span class="lp-section-tag">Knowledge</span>
        <span class="lp-section-tag">Continuity</span>
      </div>
    </div>
  </section>

  {{-- Section 7 : POSITIONING --}}
  <section class="lp-panel lp-panel--dark" id="s-position">
    <div class="lp-panel__content">
      <p class="lp-kicker">What It Is Not</p>
      <h2 class="lp-title lp-title--lg">Not LinkedIn. Not Slack. Not another feed.</h2>
      <p class="lp-text">LinkedIn optimizes visibility. Slack and Discord optimize conversation. Classic platforms reward activity.</p>
      <p class="lp-punch">LaunchPals is different.</p>
      <p class="lp-support">It is designed to support trust, context, memory, introductions and small collaborations inside a specific community.</p>
      <p class="lp-punch">Not more noise. More useful cooperation.</p>
      <div class="lp-section-tags">
        <span class="lp-section-tag">Visibility</span>
        <span class="lp-section-tag">Noise</span>
        <span class="lp-section-tag">Conversation</span>
        <span class="lp-section-tag">Trust</span>
        <span class="lp-section-tag">Context</span>
        <span class="lp-section-tag">Cooperation</span>
      </div>
    </div>
  </section>

  {{-- Section 8 : SUCCESS --}}
  <section class="lp-panel lp-panel--light" id="s-success">
    <div class="lp-panel__content">
      <p class="lp-kicker">What Success Looks Like</p>
      <h2 class="lp-title lp-title--lg">When dormant links become active again.</h2>
      <p class="lp-text">LaunchPals succeeds when:</p>
      <ul class="lp-success-list">
        <li>a former member reconnects with a current project;</li>
        <li>two people meet and start a collaboration;</li>
        <li>a student receives timely guidance;</li>
        <li>an unfinished idea finds a new context;</li>
        <li>a publication, artwork, prototype or research project emerges;</li>
        <li>ArtSciLab becomes easier to remember, navigate and support.</li>
      </ul>
      <p class="lp-punch">The value is not the number of posts. The value is the movement created.</p>
      <div class="lp-section-tags">
        <span class="lp-section-tag">Reconnection</span>
        <span class="lp-section-tag">Guidance</span>
        <span class="lp-section-tag">Projects</span>
        <span class="lp-section-tag">Research</span>
        <span class="lp-section-tag">Creation</span>
        <span class="lp-section-tag">Movement</span>
      </div>
    </div>
  </section>

  {{-- Section 9 : FUTURE --}}
  <section class="lp-panel lp-panel--green" id="s-future">
    <div class="lp-panel__content">
      <p class="lp-kicker">A First Node</p>
      <h2 class="lp-title lp-title--lg">One village. Then a federation of villages.</h2>
      <p class="lp-text">LaunchPals begins with ArtSciLab.</p>
      <p class="lp-support">But the model can grow: research labs, cultural networks, learning communities, civic initiatives, artistic collectives and communities of practice.</p>
      <p class="lp-support">Each village keeps its own identity. Bridges can emerge where they make sense.</p>
      <p class="lp-punch">A living community can connect with other living communities.</p>
      <div class="lp-section-tags">
        <span class="lp-section-tag">Node</span>
        <span class="lp-section-tag">Village</span>
        <span class="lp-section-tag">Federation</span>
        <span class="lp-section-tag">Practice</span>
        <span class="lp-section-tag">Bridges</span>
        <span class="lp-section-tag">Future</span>
      </div>
    </div>
  </section>

  {{-- Section 10 : FINAL CTA --}}
  <section class="lp-panel lp-panel--final" id="s-cta">
    <div class="lp-panel__content lp-panel__content--center">
      <p class="lp-kicker">Join the Loop</p>
      <h2 class="lp-title lp-title--lg">Ask. Offer. Connect. Remember.</h2>
      <p class="lp-text">LaunchPals invites ArtSciLab members to return, contribute, ask, offer, connect and remember together.</p>
      <p class="lp-support">Not to be more visible. Not to produce more noise. But to help each other move forward.</p>
      <div class="lp-actions">
        <a href="{{ route('organization.register', $organization) }}" class="lp-button lp-button--primary">Join LaunchPals</a>
        <a href="{{ route('organization.home', $organization) }}" class="lp-button lp-button--secondary">Discover the circles</a>
      </div>
    </div>
  </section>

  <footer class="lp-footer">
    <a href="{{ route('organization.home', $organization) }}">LaunchPals</a>
    <span>·</span>
    <a href="{{ route('mentions-legales') }}">{{ __('footer.mentions_legales') }}</a>
    <span>·</span>
    <span>{{ config('app.version') }}</span>
  </footer>

</main>

<script>
(function(){
  var sections = document.querySelectorAll('.lp-panel');
  var logo = document.querySelector('.lp-fixed-logo');
  var navLinks = document.querySelectorAll('.lp-nav__link');
  var map = {};
  navLinks.forEach(function(l){ map[l.getAttribute('href').slice(1)] = l; });

  var observer = new IntersectionObserver(function(entries){
    entries.forEach(function(e){
      if(e.isIntersecting){
        navLinks.forEach(function(l){ l.classList.remove('lp-nav__link--active'); });
        var link = map[e.target.id];
        if(link) link.classList.add('lp-nav__link--active');
      }
    });
  }, {threshold:0.3});

  sections.forEach(function(s){ observer.observe(s); });

  var deck = document.querySelector('.lp-snap');
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
