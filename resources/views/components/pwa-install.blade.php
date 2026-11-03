{{--
    TASK-1737 — l'invitation a installer BouclePro.

    Deux formes, une seule source : l'ecran PLEIN FORMAT et le BANDEAU bas.
    `PwaInstallContext` decide laquelle, ou aucune ; voir son docbloc pour la
    regle complete et pour la collision racine/vitrine qu'elle tranche.

    ## Pourquoi la CSS est ici, en clair, et pas en Tailwind

    Mesure du 31/10/2026 : `ofsh-hero`, `hero-v2`, `artscilab-hero`,
    `about-ofsh` et `about-launchpals` ne chargent **que** leur propre feuille
    (`public/css/*.css`) et des polices. **Aucun Tailwind.** Un ecran style en
    classes utilitaires y serait litteralement invisible — le no-op silencieux
    que ce depot redoute, et ce composant doit s'afficher precisement la.

    La CSS est donc portee par le composant, prefixee `bp-pwa-`, et n'emprunte
    qu'une chose a la page : `var(--bp-primary)`, que `<x-theme-tokens />`
    (TASK-1471) produit **deja** sur tous ces documents. L'appel a l'action
    prend ainsi la couleur de l'Organization consultee, gratuitement, avec un
    repli si le token manque.

    La pile de polices est `system-ui` : aucune fonderie a attendre. Cet ecran
    est la premiere impression du produit, il ne doit rien attendre.

    ## Le masquage est en CSS, jamais en JavaScript

    `.bp-pwa` est `display: none` par DEFAUT, et seul
    `@media (display-mode: browser)` l'affiche. Consequences :

    - une PWA installee ne voit **jamais** l'ecran, pas meme une fraction de
      seconde — aucun flash, aucune course avec le JavaScript ;
    - un navigateur qui ne connait pas `display-mode` n'affiche rien, ce qui est
      le bon repli : il ne sait pas installer non plus.

    `navigator.standalone` reste en ceinture pour les iOS anciens, pose en
    attribut sur `<html>` par le script ci-dessous.

    ## Aucun bouton factice — CDC section 3.3

    `beforeinstallprompt` arrive APRES la premiere peinture : a ce moment, on ne
    sait pas encore si un prompt existe. L'ecran affiche donc par defaut les
    **instructions manuelles de la plateforme**, et le bouton n'apparait que
    lorsque l'evenement a ete capture — `data-bp-prompt="ready"`.

    Sur iOS l'evenement n'existe pas : l'etat manuel est donc l'etat final, et
    c'est voulu.

    ## Les safe areas, sans dependre du viewport

    Les landings autonomes n'ont pas `viewport-fit=cover`, donc
    `env(safe-area-inset-*)` y rend zero. Leur ajouter ferait passer leur
    contenu sous l'encoche — un changement visuel hors de cette TASK. Les
    marges utilisent donc `max(valeur, env(...))` : confortables partout,
    exactes la ou l'information existe.
--}}
@php
    $pwa = \App\Support\Pwa\PwaInstallContext::for(request());
@endphp

@if ($pwa->emetQuelqueChose())
    @once
        <style>
            .bp-pwa {
                /* Par DEFAUT invisible : seul `display-mode: browser` l'allume. */
                display: none;
                --bp-pwa-ink: #ffffff;
                --bp-pwa-ink-soft: rgba(255, 255, 255, .72);
                --bp-pwa-ink-faint: rgba(255, 255, 255, .54);
                --bp-pwa-bg: #0b0b10;
                --bp-pwa-card: rgba(255, 255, 255, .06);
                --bp-pwa-line: rgba(255, 255, 255, .12);
                --bp-pwa-cta: var(--bp-primary, #1b1fcc);
                font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
                -webkit-font-smoothing: antialiased;
                box-sizing: border-box;
            }

            .bp-pwa *,
            .bp-pwa *::before,
            .bp-pwa *::after { box-sizing: inherit; }

            @media all and (display-mode: browser) {
                .bp-pwa--full { display: flex; }
                .bp-pwa--banner { display: block; }
            }

            /* Ceinture pour les iOS anciens, ou `display-mode` n'etait pas lu. */
            html[data-bp-standalone] .bp-pwa { display: none !important; }

            /* ── Ecran plein format ──────────────────────────────────────── */
            .bp-pwa--full {
                position: fixed;
                inset: 0;
                z-index: 2147483000;
                flex-direction: column;
                align-items: center;
                /* PAS de `justify-content: center` — defaut mesure en recette
                   (Cyril, 01/11/2026, capture d'un Pixel).

                   L'axe PRINCIPAL est vertical ici (`flex-direction: column`),
                   donc `justify-content` centre VERTICALEMENT. Combine a
                   `overflow-y: auto`, c'est le piege classique : quand le
                   contenu depasse, le centrage pousse son haut AU-DESSUS de
                   l'origine du defilement, et cette zone devient
                   INATTEIGNABLE — on ne peut pas faire defiler vers un
                   scrollTop negatif.

                   Mesure a `scrollTop = 0` : haut de la carte a **-45 px** en
                   390x600, **-85 px** en 360x520. Sur la capture, l'icone
                   etait coupee par le haut.

                   `margin: auto` sur l'enfant (plus bas) fait le meme centrage
                   quand il y a de la place, et ne rogne rien quand il n'y en a
                   pas : les marges automatiques se resorbent au lieu de
                   deborder. C'est la correction standard de ce piege. */
                gap: 1.75rem;
                overflow-y: auto;
                background: var(--bp-pwa-bg);
                color: var(--bp-pwa-ink);
                padding: max(1.5rem, env(safe-area-inset-top, 0px)) 1.25rem
                         max(1.5rem, env(safe-area-inset-bottom, 0px));
                text-align: center;
            }

            .bp-pwa__inner {
                width: 100%;
                max-width: 26rem;
                /* Le centrage vertical, en version qui ne rogne pas. */
                margin-top: auto;
                margin-bottom: auto;
            }

            .bp-pwa__icon {
                width: 84px;
                height: 84px;
                border-radius: 20px;
                display: block;
                margin: 0 auto 1.25rem;
                box-shadow: 0 10px 30px rgba(0, 0, 0, .45);
            }

            .bp-pwa__title {
                margin: 0 0 .5rem;
                font-size: 1.5rem;
                line-height: 1.22;
                font-weight: 700;
                letter-spacing: -.02em;
            }

            .bp-pwa__promise {
                margin: 0 0 1.75rem;
                font-size: .95rem;
                line-height: 1.5;
                color: var(--bp-pwa-ink-soft);
            }

            .bp-pwa__benefits {
                list-style: none;
                margin: 0 0 1.75rem;
                padding: 0;
                border: 1px solid var(--bp-pwa-line);
                border-radius: 14px;
                background: var(--bp-pwa-card);
                overflow: hidden;
                text-align: left;
            }

            .bp-pwa__benefit {
                display: flex;
                gap: .75rem;
                padding: .85rem 1rem;
            }

            .bp-pwa__benefit + .bp-pwa__benefit { border-top: 1px solid var(--bp-pwa-line); }
            .bp-pwa__benefit svg { flex: 0 0 auto; width: 20px; height: 20px; margin-top: 2px; opacity: .9; }
            .bp-pwa__benefit b { display: block; font-size: .9rem; font-weight: 600; }
            .bp-pwa__benefit span { display: block; font-size: .8rem; line-height: 1.45; color: var(--bp-pwa-ink-faint); }

            /* ── Ecrans COURTS ───────────────────────────────────────────
               Second volet du meme defaut de recette : meme sans rognage, le
               contenu mesurait 669 px pour une hauteur VISIBLE d'environ 600 a
               670 px — la barre d'adresse du navigateur mange 110 a 150 px que
               `100vh` ignore. Il fallait donc defiler pour atteindre l'action
               principale d'un ecran dont c'est l'unique raison d'etre.

               On compacte a partir de 740 px de haut, ce qui couvre la quasi
               totalite des telephones en navigation. Les grands ecrans gardent
               le dessin d'origine. Aucune information n'est retiree : seuls
               l'echelle et les espacements changent. */
            @media (max-height: 740px) {
                .bp-pwa--full { gap: 1rem; }
                .bp-pwa__icon { width: 56px; height: 56px; border-radius: 14px; margin-bottom: .75rem; }
                .bp-pwa__title { font-size: 1.25rem; }
                .bp-pwa__promise { margin-bottom: 1rem; font-size: .9rem; }
                .bp-pwa__benefits { margin-bottom: 1rem; }
                .bp-pwa__benefit { padding: .6rem .85rem; }
                .bp-pwa__benefit span { font-size: .78rem; line-height: 1.35; }
                .bp-pwa__manual-intro { margin-bottom: .5rem; }
                .bp-pwa__step { padding: .4rem 0; }
                .bp-pwa__dismiss { margin-top: .6rem; }
            }

            /* ── Appel a l'action ────────────────────────────────────────── */
            .bp-pwa__cta {
                display: none; /* n'apparait QUE si un prompt existe reellement */
                width: 100%;
                min-height: 54px;
                padding: .95rem 1.25rem;
                border: 0;
                border-radius: 14px;
                background: var(--bp-pwa-cta);
                color: #fff;
                font: inherit;
                font-size: 1rem;
                font-weight: 650;
                cursor: pointer;
                -webkit-appearance: none;
                appearance: none;
            }

            .bp-pwa[data-bp-prompt="ready"] .bp-pwa__cta { display: block; }
            .bp-pwa[data-bp-prompt="ready"] .bp-pwa__manual { display: none; }
            .bp-pwa__cta:active { transform: translateY(1px); }

            .bp-pwa__dismiss {
                display: inline-block;
                margin-top: 1.1rem;
                min-height: 44px;
                padding: .6rem .5rem;
                border: 0;
                background: none;
                color: var(--bp-pwa-ink-soft);
                font: inherit;
                font-size: .9rem;
                text-decoration: underline;
                text-underline-offset: 3px;
                cursor: pointer;
            }

            /* ── Instructions manuelles ──────────────────────────────────── */
            .bp-pwa__manual { text-align: left; }
            .bp-pwa__manual[hidden] { display: none; }

            .bp-pwa__manual-intro {
                margin: 0 0 .85rem;
                font-size: .9rem;
                font-weight: 600;
                color: var(--bp-pwa-ink-soft);
            }

            .bp-pwa__steps { list-style: none; margin: 0; padding: 0; counter-reset: bp-pwa-step; }

            .bp-pwa__step {
                display: flex;
                align-items: flex-start;
                gap: .75rem;
                padding: .6rem 0;
                font-size: .9rem;
                line-height: 1.45;
            }

            .bp-pwa__step::before {
                counter-increment: bp-pwa-step;
                content: counter(bp-pwa-step);
                flex: 0 0 auto;
                width: 24px;
                height: 24px;
                border-radius: 8px;
                border: 1px solid var(--bp-pwa-line);
                background: var(--bp-pwa-card);
                font-size: .78rem;
                font-weight: 650;
                line-height: 22px;
                text-align: center;
            }

            .bp-pwa__glyph {
                display: inline-block;
                width: 17px;
                height: 17px;
                vertical-align: -3px;
                margin: 0 2px;
            }

            .bp-pwa__disclaimer {
                margin: 1rem 0 0;
                font-size: .78rem;
                line-height: 1.45;
                color: var(--bp-pwa-ink-faint);
            }

            /* ── Bandeau non bloquant ────────────────────────────────────── */
            /* `--bp-pwa-bottom` est mesure par le script : le bandeau se place
               AU-DESSUS d'une barre de navigation basse quand il y en a une,
               via le selecteur stable `[data-bp-mobile-nav]` que portent les
               deux barres du depot. Repli 0 : aucune connaissance par page. */
            .bp-pwa--banner {
                position: fixed;
                left: 0;
                right: 0;
                bottom: var(--bp-pwa-bottom, 0px);
                z-index: 2147482000;
                background: var(--bp-pwa-bg);
                color: var(--bp-pwa-ink);
                border-top: 1px solid var(--bp-pwa-line);
                padding: .85rem 1rem calc(.85rem + env(safe-area-inset-bottom, 0px));
                box-shadow: 0 -8px 28px rgba(0, 0, 0, .3);
            }

            /* TASK-1745 — la CARTE de confirmation.

               Volontairement plus haute et plus contrastee que le bandeau
               qu'elle remplace : c'est le moment ou la personne a le plus
               besoin d'etre guidee, et une ligne de 38 px ne s'y voyait pas.
               Elle reste dans le meme conteneur `fixed bottom`, donc toujours
               non bloquante. */
            .bp-pwa__confirm {
                max-width: 40rem;
                margin: 0 auto;
                border-left: 4px solid var(--bp-pwa-accent, #6B2CFF);
                padding-left: .9rem;
            }
            .bp-pwa__confirm-head { display: flex; align-items: center; gap: .75rem; }
            .bp-pwa__confirm-head img { width: 44px; height: 44px; border-radius: 11px; flex: 0 0 auto; }
            .bp-pwa__confirm-head b {
                flex: 1 1 auto; min-width: 0;
                font-size: 1rem; font-weight: 700; line-height: 1.25;
            }
            .bp-pwa__confirm-steps {
                margin: .6rem 0 0;
                padding-left: 1.25rem;
                display: grid;
                gap: .3rem;
                font-size: .82rem;
                line-height: 1.4;
                color: var(--bp-pwa-ink);
            }
            /* Le dernier pas — les notifications — se detache : c'est le seul
               qui annonce ce qui arrivera APRES, et non un geste a faire. */
            .bp-pwa__confirm-steps li:last-child { color: var(--bp-pwa-ink-faint); }

            .bp-pwa__bar { display: flex; align-items: center; gap: .85rem; max-width: 40rem; margin: 0 auto; }
            .bp-pwa__bar img { width: 38px; height: 38px; border-radius: 10px; flex: 0 0 auto; }
            .bp-pwa__bar-text { flex: 1 1 auto; min-width: 0; }
            .bp-pwa__bar-text b { display: block; font-size: .85rem; font-weight: 650; line-height: 1.3; }
            .bp-pwa__bar-text span { display: block; font-size: .76rem; line-height: 1.35; color: var(--bp-pwa-ink-faint); }

            .bp-pwa__bar-action {
                flex: 0 0 auto;
                min-height: 40px;
                padding: .55rem .95rem;
                border: 0;
                border-radius: 11px;
                background: var(--bp-pwa-cta);
                color: #fff;
                font: inherit;
                font-size: .88rem;
                font-weight: 650;
                text-decoration: none;
                cursor: pointer;
                -webkit-appearance: none;
                appearance: none;
            }

            .bp-pwa__bar-close {
                flex: 0 0 auto;
                width: 40px;
                height: 40px;
                border: 0;
                background: none;
                color: var(--bp-pwa-ink-faint);
                font: inherit;
                font-size: 1.25rem;
                line-height: 1;
                cursor: pointer;
            }

            /* L'anneau de focus appartient aux elements INTERACTIFS.
               Mesure a la capture du 31/10/2026 : le titre s'affichait
               encadre. Cause — le script lui donne le focus a l'ouverture
               (`tabindex="-1"`, pour que le lecteur d'ecran annonce le
               dialogue), et Chromium dessine alors l'anneau sur un element que
               personne ne peut atteindre au clavier. Le retirer LA ne coute
               aucune accessibilite : on n'y arrive jamais par tabulation. */
            .bp-pwa button:focus-visible,
            .bp-pwa a:focus-visible {
                outline: 2px solid #fff;
                outline-offset: 2px;
                border-radius: 6px;
            }

            .bp-pwa__title:focus,
            .bp-pwa__title:focus-visible { outline: none; }

            @media (prefers-reduced-motion: reduce) {
                .bp-pwa, .bp-pwa * { transition: none !important; animation: none !important; }
            }
        </style>
    @endonce

    @if ($pwa->estPleinEcran())
        <div class="bp-pwa bp-pwa--full"
             data-bp-pwa-install="full"
             @if ($pwa->estPageDediee()) data-bp-pwa-dedicated="1" @endif
             role="dialog"
             aria-modal="true"
             aria-labelledby="bp-pwa-title">
            <div class="bp-pwa__inner">
                <img class="bp-pwa__icon" src="/brand/icon-192.png" width="84" height="84"
                     alt="" aria-hidden="true">

                <h1 class="bp-pwa__title" id="bp-pwa-title" tabindex="-1">{{ __('pwa.title') }}</h1>
                <p class="bp-pwa__promise">{{ __('pwa.promise') }}</p>

                <ul class="bp-pwa__benefits">
                    <li class="bp-pwa__benefit">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"
                             stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V20h14V9.5"/>
                        </svg>
                        <div>
                            <b>{{ __('pwa.benefits.home_screen.title') }}</b>
                            <span>{{ __('pwa.benefits.home_screen.body') }}</span>
                        </div>
                    </li>
                    <li class="bp-pwa__benefit">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"
                             stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M4 9V5a1 1 0 0 1 1-1h4"/><path d="M20 9V5a1 1 0 0 0-1-1h-4"/>
                            <path d="M4 15v4a1 1 0 0 0 1 1h4"/><path d="M20 15v4a1 1 0 0 1-1 1h-4"/>
                        </svg>
                        <div>
                            <b>{{ __('pwa.benefits.fullscreen.title') }}</b>
                            <span>{{ __('pwa.benefits.fullscreen.body') }}</span>
                        </div>
                    </li>
                    <li class="bp-pwa__benefit">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"
                             stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M13 3 4 14h6l-1 7 9-11h-6l1-7Z"/>
                        </svg>
                        <div>
                            <b>{{ __('pwa.benefits.faster.title') }}</b>
                            <span>{{ __('pwa.benefits.faster.body') }}</span>
                        </div>
                    </li>
                </ul>

                {{-- Le bouton REEL. Masque par CSS tant que `beforeinstallprompt`
                     n'a pas ete capture : jamais de bouton qui ne fait rien. --}}
                <button type="button" class="bp-pwa__cta" data-bp-pwa-action="install">
                    {{ __('pwa.install') }}
                </button>

                {{-- Les instructions, etat par DEFAUT. Chaque bloc est revele par
                     le script selon la plateforme reellement observee. --}}
                <div class="bp-pwa__manual" data-bp-pwa-manual>
                    <div data-bp-pwa-platform="ios-safari" hidden>
                        <p class="bp-pwa__manual-intro">{{ __('pwa.ios.intro') }}</p>
                        <ol class="bp-pwa__steps">
                            <li class="bp-pwa__step">
                                <span>
                                    {{ __('pwa.ios.step_share') }}
                                    <svg class="bp-pwa__glyph" viewBox="0 0 24 24" fill="none"
                                         stroke="currentColor" stroke-width="1.8" stroke-linecap="round"
                                         stroke-linejoin="round" aria-hidden="true">
                                        <path d="M12 3v11"/><path d="M8 7l4-4 4 4"/>
                                        <path d="M5 13v6a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-6"/>
                                    </svg>
                                </span>
                            </li>
                            <li class="bp-pwa__step"><span>{{ __('pwa.ios.step_add') }}</span></li>
                            <li class="bp-pwa__step"><span>{{ __('pwa.ios.step_confirm') }}</span></li>
                        </ol>
                        <p class="bp-pwa__disclaimer">{{ __('pwa.ios.disclaimer') }}</p>
                    </div>

                    <div data-bp-pwa-platform="ios-other" hidden>
                        <p class="bp-pwa__manual-intro">{{ __('pwa.ios_other_browser.title') }}</p>
                        <p class="bp-pwa__disclaimer">{{ __('pwa.ios_other_browser.body') }}</p>
                    </div>

                    <div data-bp-pwa-platform="generic" hidden>
                        <p class="bp-pwa__manual-intro">{{ __('pwa.generic.intro') }}</p>
                        <ol class="bp-pwa__steps">
                            <li class="bp-pwa__step"><span>{{ __('pwa.generic.step_menu') }}</span></li>
                            <li class="bp-pwa__step"><span>{{ __('pwa.generic.step_add') }}</span></li>
                        </ol>
                    </div>

                    <div data-bp-pwa-platform="installing" hidden>
                        <p class="bp-pwa__manual-intro">{{ __('pwa.installing_title') }}</p>
                        <p class="bp-pwa__disclaimer">{{ __('pwa.installing_body') }}</p>
                        {{-- TASK-1745 — ce que la personne rencontrera ENSUITE.
                             Annonce ici plutot que decouverte au lancement :
                             une demande de permission qu'on a vue venir se
                             refuse moins par reflexe (CDC 5.3).

                             Conditionnee a la REALITE du catalogue : CDC 3.2
                             interdit de promettre une capacite non livree, et
                             sur une branche ou le canal `web_push` n'est pas
                             encore ouvert, cette phrase serait un mensonge.
                             Elle apparait d'elle-meme le jour ou il l'est. --}}
                        @if(\App\Support\Pwa\PwaInstallContext::annonceLesNotifications())
                            <p class="bp-pwa__disclaimer">{{ __('pwa.installing_note') }}</p>
                        @endif
                    </div>
                </div>

                {{-- L'action secondaire change de SENS selon le support.

                     Sur `/installer`, cette superposition EST la page : rien
                     dessous. La retirer laissait un ecran noir (recette Cyril,
                     31/10/2026), et poser un renoncement de 30 jours
                     interpretait une curiosite comme un refus — le bandeau
                     disparaissait ensuite de tout le site.

                     Ici c'est donc un RETOUR : un vrai lien, qui fonctionne
                     sans JavaScript, et qui ne pose aucun cookie. Le refus
                     garde sa porte, la croix du bandeau. --}}
                @if ($pwa->estPageDediee())
                    <a class="bp-pwa__dismiss" href="{{ $pwa->urlRetour() }}">
                        {{ __('pwa.back_to_site') }}
                    </a>
                @else
                    <button type="button" class="bp-pwa__dismiss" data-bp-pwa-action="dismiss">
                        {{ __('pwa.continue_in_browser') }}
                    </button>
                @endif
            </div>
        </div>
    @else
        <div class="bp-pwa bp-pwa--banner" data-bp-pwa-install="banner"
             {{-- TASK-1745 point 4 — l'adresse qui dit si CET appareil est
                  deja abonne. Posee seulement pour une personne connectee :
                  la route exige une session, et un visiteur anonyme n'a pas
                  d'appareil a reconnaitre. --}}
             @auth data-bp-pwa-push-state="{{ route('notifications.push.show') }}" @endauth
             role="region" aria-label="{{ __('pwa.dialog_label') }}">
            <div class="bp-pwa__bar">
                <img src="/brand/icon-192.png" width="38" height="38" alt="" aria-hidden="true">
                <div class="bp-pwa__bar-text">
                    <b>{{ __('pwa.banner.title') }}</b>
                    <span>{{ __('pwa.banner.body') }}</span>
                </div>
            {{-- Un LIEN, et il le reste : sans JavaScript, sur iOS et sur
                 tout navigateur qui n'emet pas `beforeinstallprompt`, il mene
                 a l'ecran dedie ou les instructions manuelles vivent.

                 TASK-1745 (demande Cyril, 03/11) — `data-bp-pwa-action` le
                 PROMEUT en bouton d'installation des que l'evenement natif a
                 ete capture. Le gestionnaire ne fait `preventDefault()` que
                 dans ce cas : la navigation reste donc le comportement par
                 defaut, et l'amelioration est progressive.

                 Ce que cela retire : l'ecran intermediaire. Le parcours
                 mesure etait bandeau -> ecran dedie -> dialogue natif, soit
                 trois surfaces dont une seule, la derniere, est obligatoire.
                 Le bandeau ne proposait pas d'installer, il proposait d'aller
                 sur une page qui le proposerait. --}}
                <a class="bp-pwa__bar-action" href="{{ $pwa->urlEcranDedie() }}"
                   data-bp-pwa-action="install">
                    {{ __('pwa.banner.action') }}
                </a>
                <button type="button" class="bp-pwa__bar-close"
                        data-bp-pwa-action="dismiss"
                        aria-label="{{ __('pwa.banner.dismiss') }}">&times;</button>
            </div>

            {{-- TASK-1745 (demande Cyril, 03/11) — la CONFIRMATION pedagogique.

                 Le defaut constate : apres avoir accepte le dialogue natif,
                 la personne reste dans son navigateur et ne comprend pas
                 qu'elle doit maintenant ouvrir l'application depuis son
                 telephone. Rien a l'ecran ne le lui dit.

                 Ma premiere version RETIRAIT le bandeau apres un accord. Elle
                 avait tort : disparaitre, c'est precisement ne rien
                 expliquer. Le bandeau reste donc, et change de role.

                 Ce n'est ni une page, ni une etape de plus : c'est le MEME
                 element, dans un autre etat. Le parcours ne s'allonge pas.

                 `hidden` ET `style="display:none"` : la lecon de T1744 —
                 `hidden` seul ne masque rien face a une classe utilitaire
                 qui declare un `display`. --}}
            {{-- TASK-1745, recette du 03/11 — « trop discret ».

                 La premiere version reutilisait la ligne du bandeau : une
                 icone, deux phrases serrees, 38 px de haut. Au moment ou la
                 personne vient d'accepter une installation, c'est le message
                 le plus important de l'ecran, et il ressemblait a l'invitation
                 qu'il remplace.

                 C'est donc une CARTE : accent colore, titre lisible, et les
                 trois gestes NUMEROTES plutot que noyes dans une phrase.

                 Ce qui ne change pas : toujours le meme element, toujours
                 ancre en bas, toujours non bloquant. Ni page, ni modale, ni
                 piege de focus — la navigation reste entiere derriere. --}}
            <div class="bp-pwa__confirm" data-bp-pwa-confirm hidden style="display: none"
                 role="status" aria-live="polite">
                <div class="bp-pwa__confirm-head">
                    <img src="/brand/icon-192.png" width="44" height="44" alt="" aria-hidden="true">
                    <b>{{ __('pwa.installing_title') }}</b>
                    {{-- Une seule sortie, et elle ne pose AUCUN renoncement :
                         fermer une confirmation n'est pas refuser
                         l'installation qu'on vient d'accepter. --}}
                    <button type="button" class="bp-pwa__bar-close"
                            data-bp-pwa-action="close-confirm"
                            aria-label="{{ __('pwa.banner.dismiss') }}">&times;</button>
                </div>

                <ol class="bp-pwa__confirm-steps">
                    <li>{{ __('pwa.installing_step_wait') }}</li>
                    <li>{{ __('pwa.installing_step_icon') }}</li>
                    <li>{{ __('pwa.installing_step_open') }}</li>
                    {{-- Conditionnee a la realite du catalogue (CDC 3.2), et
                         le script l'echange contre « deja actives » quand
                         l'appareil est deja abonne — une reinstallation ne
                         doit pas redemander ce qui est acquis. --}}
                    @if(\App\Support\Pwa\PwaInstallContext::annonceLesNotifications())
                        <li data-bp-pwa-confirm-notifs>{{ __('pwa.installing_step_notifications') }}</li>
                    @endif
                </ol>
            </div>
        </div>
    @endif

    @once
        <script>
            (function () {
                var racine = document.querySelector('[data-bp-pwa-install]');
                if (!racine) { return; }

                // Ceinture iOS ancien : `navigator.standalone` plutot que la
                // media query. Pose sur <html>, lu par la CSS.
                try {
                    if (window.navigator.standalone === true) {
                        document.documentElement.setAttribute('data-bp-standalone', '1');
                        return;
                    }
                } catch (e) {}

                var ua = navigator.userAgent || '';
                var estIos = /iPhone|iPad|iPod/i.test(ua)
                    || (/Macintosh/i.test(ua) && navigator.maxTouchPoints > 1);
                var navigateurIosTiers = /CriOS|FxiOS|EdgiOS|OPiOS|YaBrowser/i.test(ua);

                // Quel bloc d'instructions correspond a CET appareil.
                var plateforme = estIos
                    ? (navigateurIosTiers ? 'ios-other' : 'ios-safari')
                    : 'generic';

                function montrerPlateforme(clef) {
                    var blocs = racine.querySelectorAll('[data-bp-pwa-platform]');
                    for (var i = 0; i < blocs.length; i++) {
                        blocs[i].hidden = blocs[i].getAttribute('data-bp-pwa-platform') !== clef;
                    }
                }

                montrerPlateforme(plateforme);

                // ── Le prompt natif, s'il existe ────────────────────────────
                //
                // `beforeinstallprompt` arrive APRES la peinture. Jusque-la,
                // les instructions manuelles tiennent l'ecran : aucun bouton
                // ne promet ce qu'il ne peut pas faire.
                var differe = null;

                window.addEventListener('beforeinstallprompt', function (evenement) {
                    evenement.preventDefault();
                    differe = evenement;
                    racine.setAttribute('data-bp-prompt', 'ready');
                });

                /**
                 * TASK-1745 — passer en etat de CONFIRMATION.
                 *
                 * Appelee par DEUX signaux qui ne disent pas la meme chose,
                 * et le texte affiche tient compte de la difference :
                 *
                 * - `userChoice.outcome === 'accepted'` dit que la personne a
                 *   accepte la boite du navigateur. L'installation est
                 *   LANCEE, pas terminee.
                 * - `appinstalled` dit que le systeme a reellement pose
                 *   l'icone. C'est le signal fort, mais il n'est pas emis
                 *   partout.
                 *
                 * D'ou un libelle qui ne promet rien de plus que le plus
                 * faible des deux : « BouclePro s'installe », jamais
                 * « BouclePro est installe ». Meme doctrine qu'en T1740 —
                 * un accuse de reception n'est pas une livraison.
                 *
                 * Idempotente : les deux signaux arrivent souvent tous les
                 * deux, et le second ne doit rien defaire.
                 */
                function confirmer() {
                    racine.removeAttribute('data-bp-prompt');

                    if (racine.getAttribute('data-bp-pwa-install') === 'banner') {
                        var barre = racine.querySelector('.bp-pwa__bar:not([data-bp-pwa-confirm])');
                        var confirmation = racine.querySelector('[data-bp-pwa-confirm]');

                        // `hidden` ET le style en ligne : `hidden` seul ne
                        // masque rien face a une classe qui declare un
                        // `display` — lecon payee en T1744.
                        if (barre) { barre.hidden = true; barre.style.display = 'none'; }
                        if (confirmation) { confirmation.hidden = false; confirmation.style.display = ''; }

                        ajusterLeMotDesNotifications();

                        return;
                    }

                    montrerPlateforme('installing');
                }

                /**
                 * TASK-1745 point 4 — une REINSTALLATION ne redemande pas ce
                 * qui est acquis.
                 *
                 * Le navigateur garde son abonnement Push a travers une
                 * desinstallation : meme origine, meme Service Worker. Une
                 * personne qui reinstalle peut donc etre DEJA abonnee, et lui
                 * annoncer « BouclePro vous proposera d'activer les
                 * notifications » serait promettre une question qui ne sera
                 * pas posee — la proposition du premier lancement se tait
                 * precisement dans ce cas.
                 *
                 * La reponse vient du SERVEUR, jamais d'une supposition
                 * locale : un abonnement que le serveur ignore n'en est pas
                 * un, et c'est exactement l'etat qu'on observe apres une
                 * revocation cote fournisseur.
                 *
                 * Silencieuse en cas d'echec : le texte par defaut annonce la
                 * proposition, ce qui reste vrai si l'on ne sait pas.
                 */
                async function ajusterLeMotDesNotifications() {
                    var ligne = racine.querySelector('[data-bp-pwa-confirm-notifs]');
                    var adresse = racine.getAttribute('data-bp-pwa-push-state');

                    if (!ligne || !adresse || !('serviceWorker' in navigator)) { return; }

                    try {
                        var inscription = await navigator.serviceWorker.ready;
                        var abonnement = await inscription.pushManager.getSubscription();

                        if (abonnement === null) { return; }

                        var reponse = await fetch(
                            adresse + '?endpoint=' + encodeURIComponent(abonnement.endpoint),
                            { headers: { Accept: 'application/json' } }
                        );

                        if (!reponse.ok) { return; }

                        var etat = await reponse.json();

                        if (etat && etat.subscribed === true) {
                            ligne.textContent = @json(__('pwa.installing_step_notifications_done'));
                        }
                    } catch (e) {}
                }

                window.addEventListener('appinstalled', confirmer);

                function poserLeRenoncement() {
                    var jours = {{ (int) config('pwa.install.dismiss_days') }};
                    var expire = new Date(Date.now() + jours * 864e5).toUTCString();
                    try {
                        document.cookie = @json(\App\Support\Pwa\PwaInstallContext::COOKIE_RENONCEMENT)
                            + '=1; expires=' + expire + '; path=/; samesite=lax'
                            + (location.protocol === 'https:' ? '; secure' : '');
                    } catch (e) {}
                }

                // Sur la page dediee, il n'y a RIEN sous la superposition : la
                // retirer laisse un ecran noir. Fermer veut donc dire PARTIR,
                // et partir n'est pas refuser — aucun cookie n'est pose.
                //
                // Ce chemin couvre aussi la touche Echap, qui appelle la meme
                // fonction : sans lui, le defaut reapparaitrait par le clavier
                // apres avoir ete corrige au clic.
                var pageDediee = racine.getAttribute('data-bp-pwa-dedicated') === '1';

                function fermer() {
                    if (pageDediee) {
                        window.location.href = @json($pwa->urlRetour());

                        return;
                    }

                    poserLeRenoncement();
                    if (racine.parentNode) { racine.parentNode.removeChild(racine); }
                }

                racine.addEventListener('click', function (evenement) {
                    var cible = evenement.target.closest('[data-bp-pwa-action]');
                    if (!cible) { return; }

                    var geste = cible.getAttribute('data-bp-pwa-action');

                    if (geste === 'dismiss') {
                        evenement.preventDefault();
                        fermer();
                        return;
                    }

                    // TASK-1745 — fermer la CONFIRMATION n'est pas refuser.
                    // Aucun renoncement n'est pose : la personne a accepte
                    // l'installation, le cookie ferait disparaitre la
                    // proposition sur tout le site pour trente jours alors
                    // qu'elle vient de dire oui.
                    if (geste === 'close-confirm') {
                        evenement.preventDefault();
                        if (racine.parentNode) { racine.parentNode.removeChild(racine); }
                        return;
                    }

                    if (geste === 'install' && differe) {
                        evenement.preventDefault();
                        differe.prompt();
                        // `userChoice` n'est pas une preuve d'installation : un
                        // `accepted` dit que la personne a accepte la boite du
                        // navigateur, pas que le systeme a pose l'icone. On
                        // n'annonce donc jamais « c'est installe » ici.
                        if (differe.userChoice && differe.userChoice.then) {
                            differe.userChoice.then(function (choix) {
                                // TASK-1745 — SEUL un accord confirme.
                                //
                                // Un refus ou une annulation laisse le
                                // bandeau tel quel : expliquer comment
                                // ouvrir une application qu'on vient de ne
                                // pas installer serait absurde, et donnerait
                                // l'impression que le refus n'a pas ete
                                // entendu.
                                if (choix && choix.outcome === 'accepted') {
                                    confirmer();
                                }
                                differe = null;
                            });
                        }
                    }
                });

                // ── Le bandeau se place AU-DESSUS d'une barre basse ─────────
                //
                // `mobile-bottom-nav` et `discovery-bottom-nav` sont toutes
                // deux `fixed bottom-0`, et le bandeau a un `z-index` bien
                // superieur : sans decalage il les COUVRIRAIT, et la navigation
                // de la page deviendrait inatteignable.
                //
                // La mesure passe par `[data-bp-mobile-nav]`, le selecteur que
                // le depot declare STABLE sur les deux barres — plutot que de
                // savoir, page par page, laquelle en porte une.
                if (racine.getAttribute('data-bp-pwa-install') === 'banner') {
                    var decaler = function () {
                        var barre = document.querySelector('[data-bp-mobile-nav]');
                        var hauteur = 0;

                        if (barre) {
                            var rect = barre.getBoundingClientRect();
                            // Une barre `md:hidden` a une hauteur nulle sur
                            // grand ecran : on ne decale que si elle occupe
                            // reellement de la place.
                            if (rect.height > 0) { hauteur = Math.round(rect.height); }
                        }

                        racine.style.setProperty('--bp-pwa-bottom', hauteur + 'px');
                    };

                    decaler();
                    window.addEventListener('resize', decaler);
                }

                // ── Accessibilite de l'ecran plein format ───────────────────
                if (racine.getAttribute('data-bp-pwa-install') !== 'full') { return; }

                var titre = racine.querySelector('#bp-pwa-title');
                if (titre && typeof titre.focus === 'function') { titre.focus(); }

                document.addEventListener('keydown', function (evenement) {
                    if (evenement.key === 'Escape' && racine.parentNode) {
                        fermer();
                    }
                });

                // Piege de focus : tant que l'ecran couvre la page, la
                // tabulation n'en sort pas — sinon un lecteur d'ecran
                // parcourrait un contenu visuellement masque.
                racine.addEventListener('keydown', function (evenement) {
                    if (evenement.key !== 'Tab') { return; }

                    var focusables = racine.querySelectorAll(
                        'button:not([disabled]), a[href], [tabindex]:not([tabindex="-1"])'
                    );
                    if (!focusables.length) { return; }

                    var premier = focusables[0];
                    var dernier = focusables[focusables.length - 1];

                    if (evenement.shiftKey && document.activeElement === premier) {
                        evenement.preventDefault();
                        dernier.focus();
                    } else if (!evenement.shiftKey && document.activeElement === dernier) {
                        evenement.preventDefault();
                        premier.focus();
                    }
                });
            })();
        </script>
    @endonce
@endif
