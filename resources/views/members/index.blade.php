<x-app-layout>
    <x-page-container>

        <div class="mb-8">
            <h1 class="text-3xl font-bold text-gray-900 dark:text-gray-100">{{ __('directory.title') }}</h1>
            <p class="text-gray-500 dark:text-gray-400 mt-1 text-sm">{{ trans_choice('directory.member_count', $members->total(), ['count' => $members->total()]) }}</p>
        </div>

        {{-- TASK-1697 correctif 15 — recherche sur le prenom et le nom.
             Formulaire GET : l'URL porte la recherche, donc elle se partage,
             se recharge et se pagine (`withQueryString()` au controleur). --}}
        <form method="GET" action="{{ url()->current() }}" class="mb-6 flex flex-wrap items-center gap-2" data-directory-search>
            <label for="directory-q" class="sr-only">{{ __('directory.search_label') }}</label>
            <input id="directory-q" type="search" name="q" value="{{ $recherche }}"
                   {{-- TASK-1762 point 9 — le texte d'invite suit ce que la
                        recherche couvre reellement. Annoncer « prenom ou nom »
                        alors qu'elle trouve aussi les structures ferait manquer
                        la moitie de ce qu'elle sait faire ; l'annoncer quand le
                        champ est ferme promettrait l'inverse. --}}
                   placeholder="{{ structureIsShown() ? __('directory.search_placeholder_with_structure') : __('directory.search_placeholder') }}"
                   {{-- Demande de Cyril, en recette : plus large sur ordinateur. --}}
                   class="w-full sm:w-[28rem] rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 px-3 py-2 text-sm text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-indigo-500">
            {{-- TASK-1762 point 10 — le filtre « Localisation ».

                 Un MENU et non un second champ libre : arbitrage de Cyril,
                 « j'ai peur que les gens ne trouvent pas le fait qu'on puisse
                 chercher par ville ». Un menu montre ce qui est cherchable.

                 Il ne s'affiche pas quand le parc n'a qu'une ville ou aucune :
                 un filtre qui ne filtre rien est du bruit.

                 `onchange` soumet, et le champ de recherche voyage avec lui
                 puisqu'ils partagent le meme formulaire GET : les deux filtres
                 se combinent au lieu de s'annuler. --}}
            @if($villes->count() > 1)
            <label for="directory-city" class="sr-only">{{ __('directory.city_label') }}</label>
            {{-- Demande de Cyril, en recette : le menu etait trop etroit, le
                 libelle « Localisation » serrait contre la fleche. --}}
            <select id="directory-city" name="city" onchange="this.form.submit()" data-directory-city
                    class="min-w-[11rem] rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 px-3 py-2 text-sm text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-indigo-500">
                {{-- Demande de Cyril : l'icone 📍, la meme que sur les cartes. --}}
                <option value="">📍 {{ __('directory.city_all') }}</option>
                @foreach($villes as $cle => $entree)
                {{-- TASK-1762 point 12 — le LIBELLE est abrege, pas la VALEUR.

                     « pour gagner de la place [...] on ne veut pas que le menu
                     soit trop large ». Un `<option>` soumet son `value` et
                     affiche son texte : abreger le texte seul raccourcit le
                     menu sans toucher au filtre, qui compare toujours le nom
                     complet de la ville.

                     L'infobulle porte le nom entier : rien n'est perdu, c'est
                     seulement replie. --}}
                <option value="{{ $entree['libelle'] }}" @selected(mb_strtolower($ville) === $cle)
                        @if(mb_strlen($entree['libelle']) > 20) title="{{ $entree['libelle'] }}" @endif>{{ Str::limit($entree['libelle'], 20) }} ({{ $entree['total'] }})</option>
                @endforeach
            </select>
            @endif

            <button type="submit" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">{{ __('directory.search_submit') }}</button>

            {{-- TASK-1762 point 11.4 — « Nouveaux arrivés », une BASCULE de
                 presentation. Depuis TASK-1812 correctif 04, c'est aussi une
                 bascule de TRI : la grille par defaut est alphabetique (par
                 prenom), et « Nouveaux arrivés » bascule sur le plus recent
                 d'abord — le libelle du bouton suit donc l'etat, et devient
                 « Ordre alphabetique » pour permettre d'y revenir, plutot que
                 de garder un texte fixe a cote d'un simple style « actif ».

                 Le lien conserve `q` et `city` : changer de vue ne doit pas
                 jeter les filtres. Et il retire `page`, car la page 3 de la
                 grille n'a aucun sens dans la liste. --}}
            <a href="{{ request()->fullUrlWithQuery(['view' => $vueNouveaux ? null : 'latest', 'page' => null]) }}"
               data-directory-latest
               @if($vueNouveaux) aria-pressed="true" @endif
               class="inline-flex items-center gap-1.5 rounded-lg px-4 py-2 text-sm font-semibold transition {{ $vueNouveaux
                    ? 'bg-[var(--bp-accent)] text-white shadow-sm hover:brightness-110'
                    : 'border border-[var(--bp-accent)]/40 text-[var(--bp-accent)] hover:bg-[var(--bp-accent)]/10' }}">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z"/></svg>
                {{ $vueNouveaux ? __('directory.alphabetical_button') : __('directory.latest_button') }}
            </a>
            {{-- « Effacer » doit aussi apparaitre quand SEUL le filtre de ville
                 est pose : sinon on ne sait plus comment revenir a la liste
                 complete. --}}
            @if($recherche !== '' || $ville !== '')
            {{-- TASK-1762 point 11.3 — « Effacer » devient un bouton, dans la
                 couleur « Alerte douce » du theme (`--bp-warning`, #FFB24D sur
                 Zen). Ecrire l'hexadecimal aurait fige la couleur d'un theme
                 sur tous les autres.

                 Texte SOMBRE et non blanc : l'ambre est une couleur claire, et
                 du blanc dessus serait illisible. Le bouton porte son propre
                 fond, donc le meme texte convient en mode clair comme en mode
                 sombre.

                 Cela reste une ancre et non un `<button>` : le geste est une
                 navigation vers la liste sans filtre, et elle doit pouvoir
                 s'ouvrir dans un onglet. --}}
            <a href="{{ url()->current() }}" data-directory-clear
               class="inline-flex items-center gap-1.5 rounded-lg bg-[var(--bp-warning)] px-4 py-2 text-sm font-semibold text-gray-900 transition hover:brightness-95">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                {{ __('directory.search_clear') }}
            </a>
            @endif
        </form>

        @if(($recherche !== '' || $ville !== '') && $members->total() === 0)
        <p class="py-16 text-center text-sm text-gray-500 dark:text-gray-400" data-directory-empty>{{ __('directory.search_no_result') }}</p>
        @endif

        {{-- TASK-1762 point 11.1 — TROIS colonnes au maximum, et non quatre.

             Demande de Cyril : « ainsi cela sera plus aere ». Le palier `xl`
             est simplement retire : au-dela de 1024 px la grille reste a trois
             colonnes, qui s'elargissent au lieu de se multiplier. --}}
        <div class="{{ $vueNouveaux
                ? 'overflow-hidden rounded-2xl border border-gray-200 bg-white divide-y divide-gray-100 dark:divide-gray-700/60 dark:border-gray-700 dark:bg-gray-800'
                : 'grid sm:grid-cols-2 lg:grid-cols-3 gap-5' }}"
             data-directory-grid>
            @foreach($members as $member)
            {{-- TASK-1693 bug AA — l'annuaire fabriquait son URL A LA MAIN, avec
                 l'identifiant, en contournant `route()`. C'est pour cela qu'il
                 est reste en UUID la ou les autres ecrans suivaient. --}}
            @php
                $orgParamAnnuaire = request()->route('organization');
                $nombreArticles = (int) ($articleCounts[$member->id] ?? 0);
                $boucles = $loopsByUser[$member->id] ?? collect();
                // TASK-1697 correctif 16 — zero proposition ET zero demande :
                // la mention entiere disparait. Mais le lien « articles » vit
                // dans la MEME rangee : il doit survivre a cette disparition.
                $aEchanges = $member->active_services_count > 0 || $member->open_requests_count > 0;
            @endphp
            @php $profileRoute = request()->route('organization')
                ? route('organization.profile.show', ['organization' => request()->route('organization'), 'user' => $member->profileRouteKey()])
                : route('profile.show', $member->profileRouteKey()); @endphp
            @php
                // TASK-1693 bug AA — ecrire directement depuis l'annuaire.
                //
                // L'URL vise la surface de l'Organization : sans prefixe,
                // `ResolveUrlOrganization` lie l'Organization PAR DEFAUT et la
                // garde inter-tenant refuse d'ouvrir la conversation.
                $ecrireUrl = request()->route('organization') && Route::has('organization.messages.with')
                    ? route('organization.messages.with', ['organization' => request()->route('organization'), 'user' => $member->profileRouteKey()])
                    : route('messages.with', $member->profileRouteKey());

                // TASK-1697 correctif 19 — les compteurs menent a la LISTE,
                // pas au profil.
                //
                // Au correctif 17 ils visaient une ancre de la fiche profil,
                // faute de filtre par auteur dans l'explorateur. Cyril a
                // tranche : « c'est beaucoup plus ergonomique » d'arriver sur
                // la page des propositions ou des demandes, car la fiche peut
                // etre chargee. Le filtre `member` a donc ete AJOUTE a
                // `Explorer`, et c'est lui qu'on pose ici.
                //
                // La clef est `profileRouteKey()` — le slug, l'uuid a defaut :
                // la meme que les URL de profil, donc lisible.
                //
                // Un compteur a zero n'est PAS lie : la liste d'arrivee serait
                // vide, le lien ne promettrait rien.
                $explorateurUrl = $orgParamAnnuaire && Route::has('organization.explorer')
                    ? route('organization.explorer', ['organization' => $orgParamAnnuaire])
                    : route('explorer');
                $cleMembre = $member->profileRouteKey();

                $lienPropositions = $member->active_services_count > 0
                    ? $explorateurUrl.'?tab=services&member='.urlencode($cleMembre)
                    : null;
                $lienDemandes = $member->open_requests_count > 0
                    ? $explorateurUrl.'?tab=requests&member='.urlencode($cleMembre)
                    : null;
            @endphp
            @if($vueNouveaux)
            {{-- TASK-1762 point 11.4 — LA LIGNE de la vue « Nouveaux arrivés ».

                 Arbitrage de Cyril : « une présentation où l'on voit bien la
                 photo, l'intégralité du texte de présentation, et pas sous
                 forme de card. Mais plutôt une sorte de liste. »

                 Donc : la photo en grand, la bio SANS `line-clamp`, une rangee
                 par personne, et la date d'arrivee en pastille — c'est elle qui
                 donne son sens a la vue. --}}
            <article class="relative flex gap-4 p-5 transition hover:bg-gray-50/80 sm:gap-6 sm:p-7 dark:hover:bg-gray-700/20"
                     data-member-card="{{ $member->id }}" data-member-row>
                <a href="{{ $profileRoute }}" class="relative shrink-0 self-start">
                    <img src="{{ $member->avatar_url }}" alt=""
                         class="h-16 w-16 rounded-2xl object-cover ring-1 ring-gray-200 sm:h-20 sm:w-20 dark:ring-gray-600">
                    @if(availabilityIsShown() && $member->is_available)
                    <span class="absolute -bottom-1 -right-1 h-4 w-4 rounded-full border-2 border-white bg-green-500 dark:border-gray-800"></span>
                    @endif
                </a>

                <div class="min-w-0 flex-1">
                    {{-- `pr-24` et non `pr-10` : le coin porte desormais jusqu'a DEUX
                         boutons (Entraide + Messagerie), et le nom passerait dessous. --}}
                    <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1 pr-24">
                        <a href="{{ $profileRoute }}"
                           class="text-lg font-bold text-gray-900 transition hover:text-[var(--bp-accent)] dark:text-gray-100">{{ $member->fullName }}</a>
                        <span class="rounded-full bg-[var(--bp-accent)]/10 px-2.5 py-0.5 text-xs font-semibold text-[var(--bp-accent)]"
                              data-member-joined>{{ __('directory.joined_ago', ['when' => $member->created_at->diffForHumans()]) }}</span>
                    </div>

                    @if(structureIsShown() && filled($member->structure))
                    <p class="mt-0.5 text-sm font-bold text-[var(--bp-accent)]" data-member-structure>{{ $member->structure }}</p>
                    @endif

                    @if($member->public_location)
                    <p class="mt-0.5 text-xs text-gray-400">📍 {{ $member->public_location }}</p>
                    @endif

                    {{-- TASK-1762 point 17 — « il faudra aussi la mettre bien
                         en avant » : pas tronque, et dans la couleur vive du
                         theme comme la structure juste au-dessus, pour que
                         la phrase se voie sans se confondre avec la bio. --}}
                    @if(filled($member->slogan))
                    <p class="mt-1.5 text-base font-semibold text-[var(--bp-accent)]" data-member-slogan>{{ $member->slogan }}</p>
                    @endif

                    @if($member->bio)
                    {{-- L'INTEGRALITE du texte : c'est le coeur de la demande.
                         `max-w-3xl` borne la ligne a une largeur lisible,
                         `whitespace-pre-line` respecte les retours ecrits. --}}
                    <div class="prose prose-sm dark:prose-invert mt-3 max-w-3xl text-gray-600 dark:text-gray-300"
                         data-member-bio-complete>{!! markdown($member->bio, ['renderer' => ['soft_break' => "<br />\n"]]) !!}</div>
                    @endif

                    @if($boucles->isNotEmpty())
                    {{-- TASK-1762 point 14 — le MEME partiel que la carte. Les
                         deux blocs etaient deja identiques au gap pres : les
                         garder separes aurait fait ecrire le pli deux fois. --}}
                    <div class="mt-3">
                        @include('members.partials.boucles-membre', ['gap' => 'gap-1.5'])
                    </div>
                    @endif
                </div>

                {{-- TASK-1762 point 11.5 — les memes icones de rail que sur la
                     carte : deux presentations, UN langage. --}}
                <div class="absolute right-5 top-5 flex gap-1.5 sm:right-7 sm:top-7">
                    @if($lienPropositions)
                    <a href="{{ $lienPropositions }}"
                       data-member-entraide="{{ $member->id }}"
                       title="{{ __('directory.active_services_title') }}"
                       aria-label="{{ __('directory.active_services_title') }}"
                       class="flex h-9 w-9 items-center justify-center rounded-lg border border-gray-200 bg-white/90 text-gray-500 shadow-sm transition hover:border-[var(--bp-accent)] hover:text-[var(--bp-accent)] dark:border-gray-600 dark:bg-gray-800/90 dark:text-gray-400">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ config('navigation_icons.exchanges') }}"/></svg>
                    </a>
                    @endif
                    @auth
                    @if(auth()->id() !== $member->id)
                    <a href="{{ $ecrireUrl }}"
                       data-member-write="{{ $member->id }}"
                       title="{{ __('directory.write_to', ['name' => $member->fullName]) }}"
                       aria-label="{{ __('directory.write_to', ['name' => $member->fullName]) }}"
                       class="flex h-9 w-9 items-center justify-center rounded-lg border border-gray-200 bg-white/90 text-gray-500 shadow-sm transition hover:border-[var(--bp-accent)] hover:text-[var(--bp-accent)] dark:border-gray-600 dark:bg-gray-800/90 dark:text-gray-400">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ config('navigation_icons.messaging') }}"/></svg>
                    </a>
                    @endif
                    @endauth
                </div>
            </article>
            @else
            {{-- TASK-1697 correctif 15 — la carte n'est PLUS un seul lien.
                 Elle en portait un, englobant tout ; elle doit desormais porter
                 des liens internes (site web, LinkedIn, articles, Boucles). Un
                 lien imbrique dans un lien est invalide en HTML et le
                 navigateur le deplace — meme famille que le formulaire
                 imbrique corrige en T1668. Le lien vers le profil se resserre
                 donc sur l'avatar et le nom, et le bouton « ecrire » reste
                 pose A COTE, en superposition. --}}
            <div class="relative h-full bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-5 hover:shadow-md hover:border-indigo-300 dark:hover:border-indigo-600 transition flex flex-col gap-3"
                 {{-- TASK-1762 — un marqueur sur la CARTE. Mes selecteurs de
                      recette et de test se rabattaient sur `div.relative`, qui
                      attrape aussi des conteneurs voisins : trois mesures
                      fausses en une journee. --}}
                 data-member-card="{{ $member->id }}">

                <!-- Avatar + nom -->
                <a href="{{ $profileRoute }}" class="flex items-center gap-3 group">
                    <div class="relative flex-shrink-0">
                        <img src="{{ $member->avatar_url }}" class="w-12 h-12 rounded-full" alt="">
                        {{-- TASK-1694 bug AE — pastille conditionnee a la fonctionnalite. --}}
                        @if(availabilityIsShown() && $member->is_available)
                        <span class="absolute bottom-0 right-0 w-3 h-3 bg-green-500 rounded-full border-2 border-white dark:border-gray-800"></span>
                        @endif
                    </div>
                    <div class="min-w-0">
                        <p class="font-semibold text-gray-900 dark:text-gray-100 group-hover:text-indigo-600 dark:group-hover:text-indigo-400 transition truncate">{{ $member->fullName }}</p>
                        {{-- TASK-1762 point 5 — la structure, SOUS le nom et AVANT la
                             localisation, en gras et dans la couleur vive du theme.

                             `--bp-accent` (« Accent / Loop ») et non `#8A2CFF` :
                             l'hexadecimal est celui du seul theme Zen, et en mode
                             sombre ce n'est meme pas celui-la qui s'applique.

                             `structureIsShown()` est l'unique definition du drapeau :
                             sept surfaces en dependront, et sept copies finiraient par
                             diverger sur celle qu'on regarde le moins. --}}
                        @if(structureIsShown() && filled($member->structure))
                        <p class="truncate text-xs font-bold text-[var(--bp-accent)]" data-member-structure>{{ $member->structure }}</p>
                        @endif
                        @if($member->public_location)
                        <p class="text-xs text-gray-400 truncate">📍 {{ $member->public_location }}</p>
                        @endif
                    </div>
                </a>

                {{-- TASK-1762 point 17 — le slogan, AVANT le texte de
                     presentation. Couleur du theme, pas en italique — demande
                     de Cyril, meme traitement que sur « Nouveaux arrivés ».
                     `line-clamp-2` par securite : la limite de 120 caracteres
                     au formulaire vise UNE ligne sur la carte, mais un slogan
                     ecrit par le SuperAdmin (meme limite, mais sans le rendu
                     visuel du formulaire sous les yeux) ou sur une carte plus
                     etroite peut occuper une seconde ligne. --}}
                @if(filled($member->slogan))
                <p class="text-sm font-semibold text-[var(--bp-accent)] line-clamp-2" data-member-slogan>{{ $member->slogan }}</p>
                @endif

                <!-- Bio tronquée -->
                @if($member->bio)
                {{-- TASK-1762 point 16 — la vignette rend le Markdown elle aussi,
                     mais `line-clamp-3` la borne : trois lignes RENDUES, pas
                     trois lignes de balisage. --}}
                <div class="prose prose-sm dark:prose-invert max-w-none text-gray-500 dark:text-gray-400 line-clamp-3">{!! markdown($member->bio, ['renderer' => ['soft_break' => "<br />\n"]]) !!}</div>
                @endif

                <!-- Contact info if public -->
                @if($member->show_email || ($member->show_phone && $member->phone))
                <div class="flex flex-col gap-1">
                    @if($member->show_email)
                    <div class="flex items-center gap-1.5 text-xs text-gray-500 truncate">
                        <svg class="w-3.5 h-3.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                        {{ $member->email }}
                    </div>
                    @endif
                    @if($member->show_phone && $member->phone)
                    <div class="flex items-center gap-1.5 text-xs text-gray-500">
                        <svg class="w-3.5 h-3.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/></svg>
                        {{ $member->phone }}
                    </div>
                    @endif
                </div>
                @endif

                {{-- TASK-1697 correctif 15 — site web et LinkedIn.
                     Deja affiches PUBLIQUEMENT sur la fiche profil
                     (`profile/show.blade.php:98-110`) : aucune donnee nouvelle
                     n'est exposee ici. Meme idiome, `parse_url()` pour ne
                     montrer que l'hote, et `rel="noopener noreferrer"`. --}}
                @if($member->website || $member->linkedin_url)
                <div class="flex flex-wrap items-center gap-3 text-xs">
                    @if($member->website)
                    <a href="{{ $member->website }}" target="_blank" rel="noopener noreferrer"
                       data-member-website="{{ $member->id }}"
                       title="{{ __('directory.website_title') }}"
                       class="inline-flex items-center gap-1 text-gray-500 hover:text-indigo-600 dark:text-gray-400 dark:hover:text-indigo-400 truncate">
                        <svg class="w-3.5 h-3.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3.6 9h16.8M3.6 15h16.8M12 3a15 15 0 010 18 15 15 0 010-18z"/></svg>
                        {{ parse_url($member->website, PHP_URL_HOST) ?: $member->website }}
                    </a>
                    @endif
                    @if($member->linkedin_url)
                    <a href="{{ $member->linkedin_url }}" target="_blank" rel="noopener noreferrer"
                       data-member-linkedin="{{ $member->id }}"
                       title="{{ __('directory.linkedin_title') }}"
                       class="inline-flex items-center gap-1 text-gray-500 hover:text-indigo-600 dark:text-gray-400 dark:hover:text-indigo-400">
                        <svg class="w-3.5 h-3.5 flex-shrink-0" fill="currentColor" viewBox="0 0 24 24"><path d="M20.45 20.45h-3.56v-5.57c0-1.33-.03-3.04-1.85-3.04-1.86 0-2.14 1.45-2.14 2.94v5.67H9.35V9h3.41v1.56h.05c.47-.9 1.63-1.85 3.36-1.85 3.6 0 4.27 2.37 4.27 5.45v6.29zM5.34 7.43a2.07 2.07 0 110-4.14 2.07 2.07 0 010 4.14zM7.12 20.45H3.55V9h3.57v11.45z"/></svg>
                        {{ __('directory.linkedin_title') }}
                    </a>
                    @endif
                </div>
                @endif

                <!-- Compteurs + catégories -->
                @if($aEchanges || $nombreArticles > 0)
                <div class="mt-auto pt-2 border-t border-gray-100 dark:border-gray-700 flex items-center gap-3 text-xs text-gray-400" data-member-counters="{{ $member->id }}">
                    @if($aEchanges)
                    {{-- TASK-1812 correctif 05 — demande de Cyril : la meme
                         icone « Entraide » que le rail gauche, EN PETIT,
                         comme repere visuel a cote de la mention des
                         propositions/demandes. Decorative : le libelle de
                         chaque lien porte deja l'information, `aria-hidden`
                         evite de la lire deux fois au lecteur d'ecran. --}}
                    <svg class="h-3.5 w-3.5 shrink-0 text-gray-400 dark:text-gray-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ config('navigation_icons.exchanges') }}"/></svg>
                    {{-- TASK-1697 correctif 15 — « micro-services » devient
                         « propositions ». Le terme global `$T['service']` vient
                         de `config/terms.php`, surchargeable par Organization :
                         le changer ici aurait renomme QUATRE autres surfaces que
                         Cyril n'a pas demandees. L'annuaire porte donc sa propre
                         clef. --}}
                    {{-- Un `<a>` sans `href` est valide et se rend en texte :
                         une seule structure pour les deux cas, plutot que le
                         balisage duplique dans un @if/@else. --}}
                    {{-- TASK-1762 point 11.5 — un compteur a ZERO disparait,
                         individuellement. « 0 proposition · 1 demande » faisait
                         lire un zero a la moitie des cartes ; chaque compteur ne
                         s'affiche plus que s'il a quelque chose a compter, et le
                         separateur ne vient que si les DEUX sont la. Les liens
                         sont desormais toujours porteurs d'un href : ils ne se
                         rendent que la ou `$lienPropositions`/`$lienDemandes`
                         existent, par construction. --}}
                    @if($member->active_services_count > 0)
                    <a href="{{ $lienPropositions }}" data-member-services="{{ $member->id }}" class="hover:text-indigo-600 dark:hover:text-indigo-400"
                       title="{{ __('directory.active_services_title') }}">
                        <span class="font-semibold text-indigo-600 dark:text-indigo-400">{{ $member->active_services_count }}</span> {{ trans_choice('directory.service_label', $member->active_services_count) }}
                    </a>
                    @endif
                    @if($member->active_services_count > 0 && $member->open_requests_count > 0)
                    <span class="text-gray-300 dark:text-gray-600">·</span>
                    @endif
                    @if($member->open_requests_count > 0)
                    <a href="{{ $lienDemandes }}" data-member-requests="{{ $member->id }}" class="hover:text-indigo-600 dark:hover:text-indigo-400"
                       title="{{ __('directory.open_requests') }}">
                        <span class="font-semibold text-green-600 dark:text-green-400">{{ $member->open_requests_count }}</span> {{ trans_choice('directory.request_label', $member->open_requests_count) }}
                    </a>
                    @endif
                    @endif
                    {{-- TASK-1697 correctif 15 — articles publies, et le lien
                         qui y mene. Le produit n'a AUCUNE page « articles de
                         cet auteur » : le seul ecran qui les liste est la
                         section « Articles publies » de sa fiche profil, d'ou
                         l'ancre. Affiche seulement s'il en a. --}}
                    @if($nombreArticles > 0)
                    @if($aEchanges)
                    <span class="text-gray-300 dark:text-gray-600">·</span>
                    @endif
                    <a href="{{ $profileRoute }}#articles" data-member-articles="{{ $member->id }}"
                       title="{{ __('directory.articles_title') }}"
                       class="hover:text-indigo-600 dark:hover:text-indigo-400">
                        <span class="font-semibold text-amber-600 dark:text-amber-400">{{ $nombreArticles }}</span> {{ trans_choice('directory.article_label', $nombreArticles) }}
                    </a>
                    @endif
                </div>
                @endif

                {{-- TASK-1697 correctif 15 — les Boucles dont cette personne
                     est membre, animateur ou proprietaire.
                     Perimetre et confidentialite : voir le commentaire du
                     controleur. Nommer une Boucle ne revele rien de plus que
                     le catalogue (TASK-1075). --}}
                @if($boucles->isNotEmpty())
                @include('members.partials.boucles-membre', ['gap' => 'gap-1'])
                @endif

                <!-- Top skills -->
                @php
                    $skills = $member->services->flatMap->skills->unique('id')->take(4);
                @endphp
                @if($skills->isNotEmpty())
                <div class="flex flex-wrap gap-1">
                    @foreach($skills as $skill)
                    <span class="px-2 py-0.5 bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300 rounded text-xs">{{ $skill->name }}</span>
                    @endforeach
                </div>
                @endif

            {{-- TASK-1762 point 11.5 — les icones du coin sont celles du RAIL.

                 Deux gestes, deux icones, et les MEMES chemins `d=` que la
                 navigation de gauche (`config/navigation_icons.php`) : une
                 icone d'Entraide qui ne ressemble pas a l'entree « Entraide »
                 du rail n'apprend rien a personne.

                 L'icone Entraide ne vient que s'il y a au moins UNE
                 proposition : `$lienPropositions` est nul a zero, par
                 construction, et un bouton vers une liste vide ne promettrait
                 rien. --}}
            <div class="absolute top-3 right-3 z-10 flex gap-1.5">
                @if($lienPropositions)
                <a href="{{ $lienPropositions }}"
                   data-member-entraide="{{ $member->id }}"
                   title="{{ __('directory.active_services_title') }}"
                   aria-label="{{ __('directory.active_services_title') }}"
                   class="flex h-8 w-8 items-center justify-center rounded-lg border border-gray-200 bg-white/90 text-gray-500 shadow-sm transition hover:border-[var(--bp-accent)] hover:text-[var(--bp-accent)] dark:border-gray-600 dark:bg-gray-800/90 dark:text-gray-400">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ config('navigation_icons.exchanges') }}"/></svg>
                </a>
                @endif
                @auth
                @if(auth()->id() !== $member->id)
                <a href="{{ $ecrireUrl }}"
                   data-member-write="{{ $member->id }}"
                   title="{{ __('directory.write_to', ['name' => $member->fullName]) }}"
                   aria-label="{{ __('directory.write_to', ['name' => $member->fullName]) }}"
                   class="flex h-8 w-8 items-center justify-center rounded-lg border border-gray-200 bg-white/90 text-gray-500 shadow-sm transition hover:border-indigo-300 hover:text-indigo-600 dark:border-gray-600 dark:bg-gray-800/90 dark:text-gray-400 dark:hover:text-indigo-400">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ config('navigation_icons.messaging') }}"/></svg>
                </a>
                @endif
                @endauth
            </div>
            </div>
            @endif
            @endforeach
        </div>

        {{-- TASK-1762 point 11.2 — la suite se charge au defilement.

             `{{ $members->links() }}` est retire : c'etait le gabarit Laravel
             par defaut, d'ou la phrase anglaise « Showing 1 to 16 of 17
             results ». Le compte vit deja dans le titre de la page.

             ## Ce n'est PAS du defilement infini

             Arbitrage du 05/11. L'annuaire est une surface de RECHERCHE, pas un
             flux : on y vient pour trouver quelqu'un, et deux filtres sont la
             pour cela. Le defilement infini pur rend le pied de page
             inatteignable, perd la position au retour arriere et coupe l'acces
             au clavier.

             Ce bloc est donc un VRAI LIEN vers la page suivante — il fonctionne
             sans JavaScript, au clavier, et le clic droit « ouvrir dans un
             onglet » marche. Le script ne fait qu'ANTICIPER ce clic quand le
             lien entre dans l'ecran. --}}
        @if($members->hasMorePages())
        <div class="mt-10 flex justify-center" data-directory-more
             data-next-url="{{ $members->nextPageUrl() }}">
            <a href="{{ $members->nextPageUrl() }}"
               class="inline-flex items-center gap-2 rounded-xl border border-gray-200 bg-white px-6 py-3 text-sm font-medium text-gray-600 shadow-sm transition hover:border-[var(--bp-accent)] hover:text-[var(--bp-accent)] dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300"
               data-directory-more-link>
                <svg class="h-4 w-4 animate-spin opacity-0 transition-opacity" data-directory-more-spinner
                     fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/>
                </svg>
                <span data-directory-more-label>{{ trans_choice('directory.load_more', $members->total() - $members->lastItem(), ['count' => $members->total() - $members->lastItem()]) }}</span>
            </a>
        </div>

        @once
        @push('scripts')
        <script>
            // TASK-1762 point 11.2 — anticiper le clic, pas le remplacer.
            //
            // Le bloc reste un lien : sans ce script, il fonctionne. Avec lui,
            // la page suivante est recuperee et ses cartes ajoutees a la
            // grille des que le lien approche de l'ecran.
            //
            // On relit la page rendue plutot que d'ajouter une route JSON : la
            // carte d'un membre est du Blade, et la redecrire en JavaScript
            // aurait garanti que les deux versions divergent.
            document.addEventListener('DOMContentLoaded', () => {
                const grille = document.querySelector('[data-directory-grid]');
                let bloc = document.querySelector('[data-directory-more]');

                if (! grille || ! bloc || ! ('IntersectionObserver' in window)) return;

                let enCours = false;

                const charger = async () => {
                    if (enCours || ! bloc) return;

                    const url = bloc.dataset.nextUrl;
                    if (! url) return;

                    enCours = true;
                    bloc.querySelector('[data-directory-more-spinner]')?.classList.remove('opacity-0');

                    try {
                        const reponse = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                        if (! reponse.ok) throw new Error(reponse.status);

                        const doc = new DOMParser().parseFromString(await reponse.text(), 'text/html');
                        const suivantes = doc.querySelector('[data-directory-grid]');

                        if (suivantes) {
                            grille.append(...suivantes.children);
                        }

                        // Le bloc de la page suivante remplace le notre, ou
                        // disparait s'il n'y a plus rien apres.
                        const blocSuivant = doc.querySelector('[data-directory-more]');

                        if (blocSuivant) {
                            bloc.replaceWith(blocSuivant);
                            bloc = blocSuivant;
                            observateur.observe(bloc);
                        } else {
                            bloc.remove();
                            bloc = null;
                        }
                    } catch (e) {
                        // Echec reseau : on rend au lien son apparence normale.
                        // Il reste cliquable, et il mene a la vraie page.
                        bloc?.querySelector('[data-directory-more-spinner]')?.classList.add('opacity-0');
                    } finally {
                        enCours = false;
                    }
                };

                const observateur = new IntersectionObserver((entrees) => {
                    if (entrees.some((e) => e.isIntersecting)) charger();
                }, { rootMargin: '300px' });

                observateur.observe(bloc);

                // Le clic garde son sens : il charge ici plutot que de changer
                // de page, mais seulement parce que le script est la pour le
                // faire.
                document.addEventListener('click', (e) => {
                    const lien = e.target.closest('[data-directory-more-link]');
                    if (! lien) return;
                    e.preventDefault();
                    charger();
                });
            });
        </script>
        @endpush
        @endonce
        @endif

    </x-page-container>
</x-app-layout>
