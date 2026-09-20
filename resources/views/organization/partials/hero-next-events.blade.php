{{-- TASK-1611 — LE prochain atelier, dans le HERO.

     UN SEUL composant, trois etats rendus automatiquement selon la donnee :

       0 session a venir      => ce partial ne rend RIEN, et `hero-v2` garde
                                 son orbite d'origine a droite (la zone n'est
                                 jamais vide) ;
       1 session, sans flyer  => etat A « solo » : quantieme en grand, heures,
                                 statut, titre, promesse, duree ;
       1 session, AVEC flyer  => etat B « media » : le visuel a gauche (au
                                 dessus sur mobile), la date en toutes lettres
                                 et le titre a droite ;
       2 sessions ou plus     => etat C « liste » : une carte unique, une ligne
                                 compacte par date, la plus proche d'abord —
                                 et AUCUN flyer, meme quand les ateliers en
                                 portent : la compacite prime.

     L'etat n'est jamais choisi a la main : il se deduit du nombre de dates a
     venir et de la presence d'un visuel.

     SELECTION : rien n'est decide dans cette vue. Les sessions viennent de
     `PublicWorkshopListing::heroSessions()`, c'est-a-dire de la selection
     publique deja prouvee (atelier PUBLIE de CETTE Organization, session
     PUBLIEE et A VENIR, triee par date). Aucun brouillon, aucun atelier
     retire, aucune session annulee ou passee, aucun atelier d'une autre
     Organization.

     DONNEES PRIVEES : aucune. Ni `meeting_url`, ni capacite, ni inscrits, ni
     avatars d'inscrits — la maquette les montre, la doctrine publique de
     TASK-1463 les refuse, et une inscription est une donnee de membre.

     « VOIR TOUS » : ABSENT, faute de destination. Le domaine n'expose aucun
     index public des ateliers — `organization.workshop.show` est la seule
     route publique (routes/web.php), `/org/{slug}/admin/ateliers` et
     `/admin/workshops` sont des ecrans d'administration. Le mandat interdit
     d'inventer la destination ; le lien apparaitra le jour ou l'index existe.

     LIENS : comme le bloc « Ateliers ouverts » qu'il remplace, chaque lien
     transporte SEULEMENT le code de Shortcut et les UTM bornes de la page
     courante (GuestAttribution::carry). Lecture pure : aucun cookie, aucune
     identite. --}}
{{-- Bloc `@php … @endphp` et jamais `@php(…)` en ligne : Blade extrait les
     blocs bruts AVANT les directives, avec une regex non gourmande de `@php`
     a `@endphp`. Un `@php(…)` en ligne place plus haut que le premier
     `@endphp` du fichier ouvre ce bloc, et tout ce qui les separe — ici le
     `@if` — est recopie tel quel dans du PHP. --}}
@php
    $heroSessions = collect($heroSessions ?? []);
@endphp
@if($heroSessions->isNotEmpty())
@php
    $carry = \App\Services\Acquisition\GuestAttribution::carry(request()->query());
    $solo = $heroSessions->count() === 1;
    // L'etat B n'est pas un choix de style : il s'allume quand l'UNIQUE
    // evenement a venir porte reellement un visuel. En liste, jamais — la
    // compacite prime, meme si les ateliers ont des flyers.
    $media = $solo && $heroSessions->first()->workshop->hasFlyer();
    $timeFormat = __('workshops.public_session_time_format');
    $href = fn ($workshop) => route('organization.workshop.show', ['organization' => $organization->slug, 'workshop' => $workshop->slug] + $carry);
@endphp
<section class="bp-next {{ $media ? 'bp-next--media' : ($solo ? 'bp-next--solo' : 'bp-next--list') }}" data-hero-next aria-labelledby="bp-next-title">
    <img class="bp-next-rings" src="{{ asset('img/boucle-rings.svg') }}" alt="" width="600" height="600" aria-hidden="true">

    <div class="bp-next-card" data-anim>
        <div class="bp-next-head">
            <span class="bp-next-headic" aria-hidden="true"><i class="ti ti-calendar-event"></i></span>
            <h2 class="bp-next-headtitle" id="bp-next-title">{{ $solo ? org_trans('hero.next_workshop') : org_trans('hero.upcoming_events') }}</h2>
        </div>

        @if($solo)
            @php
                $session = $heroSessions->first();
                $workshop = $session->workshop;
                $start = $session->localStartsAt();
                $end = $session->localEndsAt();
            @endphp
            @if($media)
            {{-- ETAT B — un evenement AVEC visuel. La date s'ecrit en toutes
                 lettres sur une ligne, parce que le flyer occupe deja la place
                 qu'aurait prise le quantieme en grand. --}}
            <a class="bp-next-item bp-next-item--media" href="{{ $href($workshop) }}" data-hero-next-link data-hero-next-workshop="{{ $workshop->id }}">
                <span class="bp-next-media">
                    <img src="{{ $workshop->flyerUrl() }}"
                         alt="{{ __('workshops.flyer_alt', ['title' => $workshop->title]) }}"
                         @if($workshop->flyer_width) width="{{ $workshop->flyer_width }}" @endif
                         @if($workshop->flyer_height) height="{{ $workshop->flyer_height }}" @endif
                         loading="lazy" decoding="async" data-hero-next-flyer>
                </span>
                <span class="bp-next-body">
                    <span class="bp-next-line">
                        <i class="ti ti-calendar-event" aria-hidden="true"></i>
                        <time datetime="{{ $session->localStartsAt()->toIso8601String() }}">{{ $start->translatedFormat(__('workshops.hero_date_format')) }}</time>
                    </span>
                    <span class="bp-next-line">
                        {{ $start->translatedFormat($timeFormat) }}@if($end) – {{ $end->translatedFormat($timeFormat) }}@endif
                        <span class="bp-next-sep" aria-hidden="true">·</span>
                        <span class="bp-next-badge bp-next-badge--{{ $workshop->format }}">
                            <span class="bp-next-dot" aria-hidden="true"></span>{{ __('workshops.format_'.$workshop->format) }}
                        </span>
                    </span>
                    <span class="bp-next-title">{{ $workshop->title }}</span>
                    @if(trim((string) $workshop->promise) !== '')
                        <span class="bp-next-promise">{{ $workshop->promise }}</span>
                    @endif
                </span>
                <span class="bp-next-go" aria-hidden="true"><i class="ti ti-arrow-right"></i></span>
            </a>
            @else
            {{-- ETAT A — un evenement SANS visuel. --}}
            <a class="bp-next-item" href="{{ $href($workshop) }}" data-hero-next-link data-hero-next-workshop="{{ $workshop->id }}">
                <span class="bp-next-when">
                    <time class="bp-next-date" datetime="{{ $session->localStartsAt()->toIso8601String() }}">
                        <span class="bp-next-dow">{{ $start->translatedFormat('D') }}</span>
                        <span class="bp-next-day">{{ $start->format('j') }}</span>
                        <span class="bp-next-month">{{ $start->translatedFormat('M Y') }}</span>
                    </time>
                    <span class="bp-next-hours">
                        <i class="ti ti-clock" aria-hidden="true"></i>
                        {{ $start->translatedFormat($timeFormat) }}@if($end) – {{ $end->translatedFormat($timeFormat) }}@endif
                    </span>
                    <span class="bp-next-badge bp-next-badge--{{ $workshop->format }}">
                        <span class="bp-next-dot" aria-hidden="true"></span>{{ __('workshops.format_'.$workshop->format) }}
                    </span>
                </span>
                <span class="bp-next-body">
                    <span class="bp-next-title">{{ $workshop->title }}</span>
                    @if(trim((string) $workshop->promise) !== '')
                        <span class="bp-next-promise">{{ $workshop->promise }}</span>
                    @endif
                    @if($workshop->duration_minutes)
                        <span class="bp-next-meta">{{ __('workshops.hero_duration', ['minutes' => $workshop->duration_minutes]) }}</span>
                    @endif
                </span>
                <span class="bp-next-go" aria-hidden="true"><i class="ti ti-arrow-right"></i></span>
            </a>
            @endif
        @else
            {{-- ETAT C — plusieurs dates. Aucun flyer ici, meme quand les
                 ateliers en portent un : la compacite est la priorite. --}}
            <ul class="bp-next-list">
                @foreach($heroSessions as $session)
                    @php
                        $workshop = $session->workshop;
                        $start = $session->localStartsAt();
                    @endphp
                    <li>
                        <a class="bp-next-row" href="{{ $href($workshop) }}" data-hero-next-link data-hero-next-workshop="{{ $workshop->id }}">
                            <time class="bp-next-rowdate" datetime="{{ $session->localStartsAt()->toIso8601String() }}">
                                <span class="bp-next-day">{{ $start->format('j') }}</span>
                                <span class="bp-next-month">{{ $start->translatedFormat('M') }}</span>
                                <span class="bp-next-hour">{{ $start->translatedFormat($timeFormat) }}</span>
                            </time>
                            <span class="bp-next-rowbody">
                                <span class="bp-next-rowtitle">{{ $workshop->title }}</span>
                                <span class="bp-next-rowmeta">
                                    <span class="bp-next-badge bp-next-badge--{{ $workshop->format }}">
                                        <span class="bp-next-dot" aria-hidden="true"></span>{{ __('workshops.format_'.$workshop->format) }}
                                    </span>
                                    @if($workshop->duration_minutes)
                                        <span class="bp-next-sep" aria-hidden="true">·</span>
                                        <span>{{ __('workshops.hero_duration', ['minutes' => $workshop->duration_minutes]) }}</span>
                                    @endif
                                </span>
                            </span>
                            <span class="bp-next-chev" aria-hidden="true"><i class="ti ti-chevron-right"></i></span>
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</section>
@endif
