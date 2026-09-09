{{--
    TASK-1442 — SW-8a : l'OVERLAY public du Shell Welcome (Shell Welcome V3 §19, MASTER Q70).
    TASK-1443 — SW-8b : le meme runtime, le meme partial, deux PLACEMENTS (MASTER Q70) :
      - overlay      : widget flottant, inclus en BAS de la landing (`position` = bottom) ;
      - shell_first  : le Shell est l'experience principale, inclus en HAUT (`position` = top),
                       le contenu public classique reste entier juste en dessous.
    Une seule instance est rendue par page ; l'autre include ne produit rien.
    Autonome par construction (CSS + JS inline, aucun Alpine/Tailwind requis) : il se monte
    aussi bien dans les landings « hero » (HTML brut) que dans x-app-layout.
    Monte SEULEMENT si le Shell est reellement disponible, ou en etat DEGRADE honnete
    (politique activee mais pas prete) : accueil non-IA, jamais un faux echange.
    TASK-1467 : dans l'etat degrade il n'y a plus de CTA « Creer un compte » — la
    cause est toujours technique, et un compte n'y change rien.
    Jamais un cookie, jamais une identite Guest sur simple visite : tout part du premier message.
    Distinct du Shell membre : aucun composant membre touche, aucun @auth retire.
--}}
@php
    $gsDisplay = $guestShell['display'] ?? null;
    $gsPosition = $position ?? 'bottom';
    // Le placement suit le mode EFFECTIF quand le Shell est disponible, la preference choisie quand il est degrade.
    $gsLayout = $gsDisplay === null ? null : ($gsDisplay['visible'] ? $gsDisplay['mode'] : ($gsDisplay['degraded'] ? $gsDisplay['preference'] : null));
    $gsMount = $gsLayout !== null
        && (($gsLayout === \App\Support\GuestShell\GuestShellDisplayMode::OVERLAY && $gsPosition === 'bottom')
            || ($gsLayout === \App\Support\GuestShell\GuestShellDisplayMode::SHELL_FIRST && $gsPosition === 'top'));
@endphp
@if($gsMount)
@php
    $gsLive = $gsDisplay['visible'];
    $gsConversation = $guestShell['conversation'] ?? null;
    $gsCta = $guestShell['cta'] ?? null;
    $gsLabels = [
        'sending' => __('guest_shell.ui.sending'),
        'network' => __('guest_shell.ui.network_error'),
        'failed' => __('guest_shell.ui.failed'),
        'refused' => __('guest_shell.ui.refused_generic'),
        'refused_max_messages_reached' => __('guest_shell.ui.refused_max_messages_reached'),
        'refused_visitor_monthly_quota_reached' => __('guest_shell.ui.refused_visitor_monthly_quota_reached'),
        'refused_rate_limited' => __('guest_shell.ui.refused_rate_limited'),
        'refused_input_out_of_bounds' => __('guest_shell.ui.refused_input_out_of_bounds'),
        // TASK-1467 : ce libelle porte `:name` — il etait passe SANS parametre,
        // donc un Shell devenu indisponible en cours de conversation affichait
        // « L'assistant de :name … » en clair. Meme chaine que le rendu
        // serveur, meme substitution.
        'unavailable' => __('guest_shell.ui.degraded_text', ['name' => $organization->name]),
        'remaining' => __('guest_shell.ui.remaining'),
        'limit_reached' => __('guest_shell.ui.limit_reached'),
    ];
@endphp
<style>
  #bp-guest-shell{position:fixed;right:16px;bottom:16px;z-index:9990;font-family:inherit;color:#111827}
  #bp-guest-shell *{box-sizing:border-box}
  #bp-guest-shell button,#bp-guest-shell textarea,#bp-guest-shell a{all:revert;box-sizing:border-box;font-family:inherit}
/* TASK-1472 : le declencheur porte la couleur « Action principale » du theme
   de l'Organization. Le repli #111827 n'est pas decoratif : si le token
   manquait, un bouton transparent serait pire qu'un bouton d'une autre
   couleur. Depuis TASK-1471 le token est reellement emis sur ces landings —
   avant, ce `var()` aurait ete un no-op silencieux. */
#bp-guest-shell .bpgs-toggle{display:flex;align-items:center;gap:10px;border:0;cursor:pointer;background:var(--bp-primary,#111827);color:#fff;border-radius:999px;padding:12px 18px;font:inherit;font-weight:600;box-shadow:0 10px 30px rgba(17,24,39,.25)}
#bp-guest-shell .bpgs-toggle:hover{background:var(--bp-primary-deep,#111827)}
#bp-guest-shell .bpgs-toggle:focus-visible{outline:3px solid #6366f1;outline-offset:2px}
#bp-guest-shell .bpgs-panel{position:absolute;right:0;bottom:64px;width:360px;max-width:calc(100vw - 32px);height:520px;max-height:calc(100vh - 96px);display:flex;flex-direction:column;background:#fff;border:1px solid #e5e7eb;border-radius:16px;box-shadow:0 20px 50px rgba(17,24,39,.25);overflow:hidden}
#bp-guest-shell .bpgs-panel[hidden]{display:none}
#bp-guest-shell .bpgs-head{display:flex;align-items:center;justify-content:space-between;gap:8px;padding:12px 14px;border-bottom:1px solid #e5e7eb;background:#f9fafb}
#bp-guest-shell .bpgs-head strong{font-size:14px}
#bp-guest-shell .bpgs-close{border:0;background:transparent;cursor:pointer;font-size:20px;line-height:1;color:#6b7280;padding:4px}
#bp-guest-shell .bpgs-log{flex:1;overflow:auto;padding:12px 14px;display:flex;flex-direction:column;gap:8px;font-size:14px;line-height:1.4}
#bp-guest-shell .bpgs-msg{max-width:88%;padding:8px 12px;border-radius:12px;white-space:pre-wrap;word-break:break-word}
#bp-guest-shell .bpgs-msg-user{align-self:flex-end;background:#111827;color:#fff;border-bottom-right-radius:4px}
#bp-guest-shell .bpgs-msg-assistant{align-self:flex-start;background:#f3f4f6;color:#111827;border-bottom-left-radius:4px}
#bp-guest-shell .bpgs-note{align-self:stretch;font-size:12px;color:#6b7280;text-align:center;padding:4px 0}
#bp-guest-shell .bpgs-note-warn{color:#b45309}
#bp-guest-shell .bpgs-cta{display:inline-block;align-self:center;margin-top:4px;background:var(--bp-primary,#4f46e5);color:#fff;text-decoration:none;padding:8px 14px;border-radius:999px;font-size:13px;font-weight:600}
#bp-guest-shell .bpgs-form{display:flex;gap:8px;padding:10px 12px;border-top:1px solid #e5e7eb;background:#fff}
#bp-guest-shell .bpgs-form textarea{flex:1;resize:none;min-height:40px;max-height:120px;border:1px solid #d1d5db;border-radius:10px;padding:8px 10px;font:inherit;font-size:14px}
#bp-guest-shell .bpgs-form button{border:0;background:var(--bp-primary,#4f46e5);color:#fff;border-radius:10px;padding:0 14px;font:inherit;font-weight:600;cursor:pointer}
#bp-guest-shell .bpgs-form button:hover:not([disabled]){background:var(--bp-primary-deep,#4338ca)}
#bp-guest-shell .bpgs-form button[disabled],#bp-guest-shell .bpgs-form textarea[disabled]{opacity:.5;cursor:not-allowed}
#bp-guest-shell .bpgs-foot{font-size:11px;color:#9ca3af;padding:0 12px 10px;text-align:center}
  @media (max-width:480px){#bp-guest-shell{right:12px;bottom:12px}#bp-guest-shell .bpgs-panel{position:fixed;left:0;right:0;bottom:0;width:100vw;max-width:100vw;height:82vh;max-height:82vh;border-radius:16px 16px 0 0}}
  /* TASK-1494 : en shell_first le Shell EST l'experience, il occupe donc la
     hauteur disponible. Avant, il etait borne a 480px / 70vh parce qu'une
     landing marketing se deroulait dessous (TASK-1443) ; ce n'est plus le cas.
     `flex:1` sur une colonne pleine hauteur plutot qu'une hauteur fixe : la
     page hote (`organization/shell-first`) donne la colonne, le Shell la
     remplit, et rien ne casse si le chrome change de taille. */
  #bp-guest-shell.bpgs-first{position:static;right:auto;bottom:auto;display:flex;flex-direction:column;flex:1;width:100%;max-width:960px;margin:0 auto;padding:8px 0 0}
  #bp-guest-shell.bpgs-first .bpgs-panel{position:static;width:100%;max-width:100%;height:auto;flex:1;min-height:0;max-height:none;bottom:auto;right:auto}
  @media (max-width:480px){#bp-guest-shell.bpgs-first{padding:4px 0 0}#bp-guest-shell.bpgs-first .bpgs-panel{position:static;width:100%;border-radius:16px}}
</style>
<div id="bp-guest-shell"
     class="{{ $gsLayout === \App\Support\GuestShell\GuestShellDisplayMode::SHELL_FIRST ? 'bpgs-first' : '' }}"
     data-guest-shell
     data-guest-shell-layout="{{ $gsLayout }}"
     data-guest-shell-mode="{{ $gsDisplay['mode'] }}"
     {{-- TASK-1467 (CDC §2.5) : la RAISON ne sort pas cote public. `policy_not_ready`
          est un code interne ; il etait lisible dans la source de la page. L'etat
          (`live` / `degraded`) suffit au navigateur, et la raison reste mesurable
          la ou elle vit vraiment : dans le payload serveur. --}}
     data-guest-shell-state="{{ $gsLive ? 'live' : 'degraded' }}"
     data-guest-shell-endpoint="{{ route('organization.shell.message', ['organization' => $organization->slug]) }}"
     data-guest-shell-csrf="{{ csrf_token() }}"
     data-guest-shell-max="{{ $guestShell['limits']['max_input_chars'] }}"
     data-guest-shell-labels='@json($gsLabels)'>
  @if($gsLayout === \App\Support\GuestShell\GuestShellDisplayMode::OVERLAY)
  {{-- TASK-1472 (P2 laisse ouvert par TASK-1467) : le PANNEAU disait deja la
       verite en etat degrade, le BOUTON promettait encore « Une question ? ».
       On ouvrait pour poser une question, on apprenait ensuite que l'assistant
       ne repond pas. Le declencheur se nomme donc simplement, et porte l'etat. --}}
  <button type="button" class="bpgs-toggle" data-guest-shell-toggle aria-expanded="false" aria-controls="bpgs-panel"
          data-guest-shell-toggle-state="{{ $gsLive ? 'live' : 'degraded' }}">
    <span aria-hidden="true">{{ $gsLive ? '💬' : 'ⓘ' }}</span><span>{{ $gsLive ? __('guest_shell.ui.open', ['name' => $organization->name]) : __('guest_shell.ui.open_unavailable') }}</span>
  </button>
  @endif
  <section id="bpgs-panel" class="bpgs-panel" @if($gsLayout === \App\Support\GuestShell\GuestShellDisplayMode::OVERLAY) hidden @endif role="{{ $gsLayout === \App\Support\GuestShell\GuestShellDisplayMode::OVERLAY ? 'dialog' : 'region' }}" aria-label="{{ __('guest_shell.ui.title', ['name' => $organization->name]) }}" data-guest-shell-panel>
    <div class="bpgs-head">
      <strong>{{ __('guest_shell.ui.title', ['name' => $organization->name]) }}</strong>
      @if($gsLayout === \App\Support\GuestShell\GuestShellDisplayMode::OVERLAY)<button type="button" class="bpgs-close" data-guest-shell-close aria-label="{{ __('guest_shell.ui.close') }}">×</button>@endif
    </div>
    <div class="bpgs-log" data-guest-shell-log aria-live="polite">
      @if($gsLive)
        @if($gsConversation === null || $gsConversation['messages'] === [])
          <div class="bpgs-msg bpgs-msg-assistant" data-guest-shell-welcome>{{ __('guest_shell.ui.welcome', ['name' => $organization->name]) }}</div>
        @else
          @foreach($gsConversation['messages'] as $message)
            <div class="bpgs-msg {{ $message['role'] === 'user' ? 'bpgs-msg-user' : 'bpgs-msg-assistant' }}" data-guest-shell-message="{{ $message['role'] }}">{{ $message['body'] }}</div>
          @endforeach
          @if($gsConversation['status'] !== \App\Models\GuestConversation::STATUS_ACTIVE)
            <div class="bpgs-note bpgs-note-warn" data-guest-shell-note>{{ __('guest_shell.ui.limit_reached') }}</div>
          @endif
        @endif
      @else
        <div class="bpgs-msg bpgs-msg-assistant" data-guest-shell-degraded>{{ __('guest_shell.ui.degraded_text', ['name' => $organization->name]) }}</div>
      @endif
      {{-- TASK-1467 : le CTA « Creer un compte » n'apparait PAS dans l'etat
           degrade. Cet etat a toujours une cause technique (plafond plateforme
           non pose, cle absente, politique non prete) : un compte n'y change
           rien, et l'afficher la transformerait une panne en argument de
           conversion. Quand le Shell est vivant, le CTA reste. --}}
      @if($gsLive && $gsCta !== null)
        <a class="bpgs-cta" href="{{ $gsCta['url'] }}" data-guest-shell-cta>{{ $gsCta['label'] }}</a>
      @endif
    </div>
    <form class="bpgs-form" data-guest-shell-form>
      <textarea name="message" rows="1" maxlength="{{ $guestShell['limits']['max_input_chars'] }}" placeholder="{{ __('guest_shell.ui.placeholder') }}" data-guest-shell-input @disabled(! $gsLive || ($gsConversation !== null && $gsConversation['status'] !== \App\Models\GuestConversation::STATUS_ACTIVE))></textarea>
      <button type="submit" data-guest-shell-send @disabled(! $gsLive || ($gsConversation !== null && $gsConversation['status'] !== \App\Models\GuestConversation::STATUS_ACTIVE))>{{ __('guest_shell.ui.send') }}</button>
    </form>
    <div class="bpgs-foot">{{ __('guest_shell.ui.privacy') }}</div>
  </section>
  {{-- TASK-1494 : l'ancre « Voir la page de :name ↓ » est RETIREE. Elle
       pointait vers le contenu marketing que TASK-1443 rendait sous le Shell en
       mode shell_first ; ce contenu n'existe plus dans ce mode, et un lien qui
       promet un ailleurs inexistant est pire qu'une absence de lien. --}}
</div>
<script>
(function () {
  var root = document.getElementById('bp-guest-shell');
  if (!root) return;
  var labels = JSON.parse(root.getAttribute('data-guest-shell-labels') || '{}');
  var toggle = root.querySelector('[data-guest-shell-toggle]');
  var panel = root.querySelector('[data-guest-shell-panel]');
  var close = root.querySelector('[data-guest-shell-close]');
  var log = root.querySelector('[data-guest-shell-log]');
  var form = root.querySelector('[data-guest-shell-form]');
  var input = root.querySelector('[data-guest-shell-input]');
  var send = root.querySelector('[data-guest-shell-send]');
  var cta = root.querySelector('[data-guest-shell-cta]');
  var busy = false;
  // TASK-1447 : l'attribution TRANSPARENTE de l'URL d'atterrissage (shortcut, utm_*) — le serveur la relit en base, jamais crue sur parole.
  function attribution() { var q = new URLSearchParams(window.location.search); var out = {}; ['shortcut', 'utm_source', 'utm_medium', 'utm_campaign'].forEach(function (k) { var v = q.get(k); if (v) out[k] = String(v).slice(0, 200); }); return out; }
  var floating = !!toggle;
  function open() { panel.hidden = false; if (toggle) toggle.setAttribute('aria-expanded', 'true'); if (!input.disabled) input.focus(); log.scrollTop = log.scrollHeight; }
  function shut() { if (!floating) return; panel.hidden = true; toggle.setAttribute('aria-expanded', 'false'); }
  if (toggle) toggle.addEventListener('click', function () { panel.hidden ? open() : shut(); });
  if (close) close.addEventListener('click', shut);
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && floating && !panel.hidden) shut(); });
  function line(cls, text, attr) { var el = document.createElement('div'); el.className = cls; el.textContent = text; if (attr) el.setAttribute(attr[0], attr[1]); if (cta) log.insertBefore(el, cta); else log.appendChild(el); log.scrollTop = log.scrollHeight; return el; }
  function lock(off) { input.disabled = off; send.disabled = off; }
  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var text = (input.value || '').trim();
    if (!text || busy || input.disabled) return;
    busy = true; lock(true);
    var welcome = root.querySelector('[data-guest-shell-welcome]'); if (welcome) welcome.remove();
    line('bpgs-msg bpgs-msg-user', text, ['data-guest-shell-message', 'user']);
    var pending = line('bpgs-note', labels.sending, ['data-guest-shell-pending', '1']);
    fetch(root.getAttribute('data-guest-shell-endpoint'), {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': root.getAttribute('data-guest-shell-csrf'), 'X-Requested-With': 'XMLHttpRequest' },
      body: JSON.stringify({ message: text, attribution: attribution() })
    }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, json: j }; }); }).then(function (res) {
      pending.remove();
      var j = res.json || {};
      var refusedUser = root.querySelectorAll('[data-guest-shell-message="user"]');
      if (j.turn === 'answered' || j.turn === 'failed') {
        if (j.assistant && j.assistant.body) line('bpgs-msg bpgs-msg-assistant', j.assistant.body, ['data-guest-shell-message', 'assistant']);
        if (j.turn === 'failed') line('bpgs-note bpgs-note-warn', labels.failed, ['data-guest-shell-note', 'failed']);
        input.value = '';
      } else if (j.turn === 'refused') {
        // Le message n'a PAS ete accepte : on retire la bulle locale et on dit pourquoi, sans inventer de reponse.
        var last = refusedUser[refusedUser.length - 1]; if (last) last.remove();
        line('bpgs-note bpgs-note-warn', labels['refused_' + j.reason] || labels.refused, ['data-guest-shell-note', 'refused:' + (j.reason || '')]);
      } else if (j.turn === 'unavailable') {
        var lastU = refusedUser[refusedUser.length - 1]; if (lastU) lastU.remove();
        line('bpgs-msg bpgs-msg-assistant', labels.unavailable, ['data-guest-shell-degraded', '1']);
        root.setAttribute('data-guest-shell-state', 'degraded');
        busy = false; lock(true); return;
      } else {
        var lastE = refusedUser[refusedUser.length - 1]; if (lastE) lastE.remove();
        line('bpgs-note bpgs-note-warn', (j.message ? j.message : labels.network), ['data-guest-shell-note', 'error']);
      }
      var conv = j.conversation || null;
      busy = false;
      if (conv && conv.status !== 'active') { line('bpgs-note bpgs-note-warn', labels.limit_reached, ['data-guest-shell-note', 'limit']); lock(true); }
      else { lock(false); input.focus(); }
    }).catch(function () { pending.remove(); line('bpgs-note bpgs-note-warn', labels.network, ['data-guest-shell-note', 'network']); busy = false; lock(false); });
  });
  input.addEventListener('keydown', function (e) { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); form.requestSubmit ? form.requestSubmit() : form.dispatchEvent(new Event('submit', { cancelable: true })); } });
})();
</script>
@endif
