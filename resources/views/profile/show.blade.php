<x-app-layout>
    <x-slot name="title">{{ __('profile.back_to_directory') }} — {{ $user->fullName }}</x-slot>

    @php
        $organizationRouteParam = request()->route('organization');
        $agentAiChatUrl = $organizationRouteParam && Route::has('organization.agent-ia.profile.chat')
            ? route('organization.agent-ia.profile.chat', ['organization' => $organizationRouteParam, 'user' => $user])
            : route('agent-ia.profile.chat', $user);

        // TASK-1693 bug AA — « Ecrire a cette personne » doit viser la surface
        // de l'Organization. Sans prefixe, `ResolveUrlOrganization` lie
        // l'Organization PAR DEFAUT et la garde inter-tenant refuse : on
        // atterrissait sur la liste des messages sans pouvoir ecrire.
        $ecrireUrl = $organizationRouteParam && Route::has('organization.messages.with')
            ? route('organization.messages.with', ['organization' => $organizationRouteParam, 'user' => $user->profileRouteKey()])
            : route('messages.with', $user->profileRouteKey());

        // TASK-1697 — les SEPT derniers liens de cette page qui sortaient du
        // locataire.
        //
        // Troisieme passage du MEME defaut sur ce seul fichier : le bug AA de
        // T1693 (« Ecrire a cette personne »), puis la fleche de l'annuaire au
        // correctif 13, et maintenant les sept restants. Sans prefixe
        // d'Organization, `ResolveUrlOrganization` lie l'Organization PAR
        // DEFAUT de la plateforme : on change de locataire sans s'en
        // apercevoir. Le dernier des sept est un `<form method="POST">`
        // (signalement) — c'est une ECRITURE qui partait au mauvais endroit.
        //
        // MEME idiome que `mobile-topbar.blade.php` : un helper, pas sept
        // ternaires recopiees.
        $urlOrg = function (string $route, array $params = []) use ($organizationRouteParam): string {
            $scopee = 'organization.'.$route;

            if ($organizationRouteParam && Route::has($scopee)) {
                return route($scopee, ['organization' => $organizationRouteParam] + $params);
            }

            return route($route, $params);
        };

        // TASK-1697 correctif 13 — meme defaut que le bug AA ci-dessus : sans
        // prefixe d'Organization, `ResolveUrlOrganization` lie l'Organization
        // PAR DEFAUT et la fleche ramenait a l'annuaire d'un AUTRE tenant.
        $annuaireUrl = $organizationRouteParam && Route::has('organization.members.index')
            ? route('organization.members.index', ['organization' => $organizationRouteParam])
            : route('members.index');

        $displaySkills = $services->flatMap(fn($s) => $s->skills)->unique('id')->take(8);
        $serviceCategories = $services->pluck('category.name')->unique()->take(4);
    @endphp

    <!-- Desktop topbar -->
    <div class="hidden md:flex items-center gap-3 px-4 sm:px-6 lg:px-8 py-3 border-b border-gray-200 dark:border-gray-700 bg-[var(--bp-surface)] sticky top-0 z-30">
        <a href="{{ $annuaireUrl }}" class="inline-flex h-8 w-8 items-center justify-center rounded-full bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-600 flex-shrink-0" aria-label="{{ __('profile.back_to_directory') }}">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
        </a>
        <span class="truncate text-sm font-medium text-gray-900 dark:text-gray-100">{{ __('navigation.directory') }}</span>
    </div>

    <x-page-container>
        {{-- TASK-1788 correctif 04 — `data-profile-entete` delimite l'en-tete.

             Sans lui, aucun test ne pourrait dire « ce marqueur n'est PLUS dans
             l'en-tete » : il le trouverait ailleurs dans la page et conclurait a
             tort. Un marqueur de frontiere, pas de decoration. --}}
        <!-- Profile Header -->
        <div data-profile-entete
             class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-5 sm:p-7 mb-5 relative">
            @if(auth()->check() && auth()->id() === $user->id)
            <div class="absolute top-4 right-4 z-10 flex items-center gap-2">
                {{-- TASK-1788 correctif 12 (demande Cyril) — a cote de « Modifier
                     mon profil », pas dedans : deux intentions differentes
                     (corriger sa fiche / publier un article), deux boutons. --}}
                <a href="{{ $urlOrg('blog.create') }}" data-profile-publish-blog
                   class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 hover:bg-gray-50 dark:hover:bg-gray-600 text-gray-700 dark:text-gray-200 text-sm font-semibold rounded-lg transition">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                    {{ __('profile.publish_to_blog') }}
                </a>
                <a href="{{ $urlOrg('profile.edit') }}" data-profile-edit
                   class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold rounded-lg transition">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/></svg>
                    {{ __('profile.edit_profile') }}
                </a>
            </div>
            @endif

            {{-- TASK-1762 point 6 — la structure, a deux endroits selon la largeur.

                 La condition est calculee UNE fois : les deux exemplaires ne
                 peuvent pas diverger sur « faut-il l'afficher ». --}}
            @php $afficheStructure = structureIsShown() && filled($user->structure); @endphp

            <div class="flex flex-col gap-5 sm:flex-row sm:gap-6">
                <!-- Avatar -->
                {{-- TASK-1788 correctif 02 — `sm:self-start`, et c'est TOUTE la
                     correction du crayon.

                     Le crayon est pose `absolute bottom-0 right-0` sur ce bloc.
                     Sur mobile (`flex-col`) le bloc a la hauteur de la photo, le
                     crayon tombait donc sur la photo. Sur desktop (`sm:flex-row`)
                     le conteneur flex applique `align-items: stretch` par defaut :
                     ce bloc s'etirait sur TOUTE la hauteur de la colonne voisine —
                     nom, slogan, pastilles, statistiques, badges — et `bottom-0`
                     deposait le crayon tout en bas, a cote des badges, a plus de
                     300 px de la photo qu'il pretend modifier.

                     `self-start` ramene la hauteur du bloc a celle de son contenu,
                     donc a celle de la photo. Prefixe `sm:` parce que sur mobile
                     il n'y a rien a corriger, et qu'un `self-start` nu y
                     remplacerait le `mx-auto` qui centre la photo. --}}
                <div class="relative flex-shrink-0 mx-auto sm:mx-0 sm:self-start">
                    <img src="{{ $user->avatar_url }}" class="h-20 w-20 sm:h-24 sm:w-24 rounded-full object-cover ring-2 ring-gray-200 dark:ring-gray-600" alt="">
                    @if(auth()->check() && auth()->id() === $user->id)
                    {{-- TASK-1749 (demande Cyril, 04/11) — le crayon de l'avatar ouvre
                         la fenetre « changer ma photo », il ne part plus vers le
                         formulaire complet.

                         Il PARAISSAIT deja promettre ce geste : il est pose sur la
                         photo, pas sur la carte. Il menait pourtant vers
                         `/profile/edit`, ou le champ Avatar se trouve entre « Profil
                         LinkedIn » et la case de disponibilite, sans rien qui le
                         signale. Le bouton « Modifier le profil » en haut a droite,
                         lui, garde sa destination : c'est LUI qui promet le
                         formulaire.

                         Le crayon devient donc un `<button>` et non plus un lien :
                         il n'a plus de destination, il ouvre. --}}
                    <button type="button" x-data @click="$dispatch('open-modal', 'photo-profil')"
                            data-avatar-edit
                            class="absolute bottom-0 right-0 w-6 h-6 bg-indigo-600 hover:bg-indigo-700 rounded-full flex items-center justify-center shadow-md transition"
                            title="{{ __('profile.avatar_change') }}">
                        <svg class="w-3 h-3 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/></svg>
                        <span class="sr-only">{{ __('profile.avatar_change') }}</span>
                    </button>
                    @endif
                </div>

                <!-- Identity + Stats -->
                <div class="flex-1 min-w-0 text-center sm:text-left">
                    <div class="flex flex-col sm:flex-row sm:items-center gap-2 sm:gap-3">
                        <h1 class="text-xl sm:text-2xl font-bold text-gray-900 dark:text-gray-100 truncate">{{ $user->fullName }}</h1>
                        {{-- TASK-1694 bug AE — pastille conditionnee a la fonctionnalite. --}}
                        @if(availabilityIsShown() && $user->is_available)
                        <span class="inline-flex items-center self-center gap-1.5 px-2.5 py-0.5 bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-300 rounded-full text-xs font-medium w-fit">
                            <span class="w-1.5 h-1.5 bg-green-500 rounded-full"></span>{{ __('profile.available') }}
                        </span>
                        @endif
                    </div>

                    {{-- TASK-1762 point 6 — sous le prenom et le nom, a TOUTES les
                         largeurs.

                         Premiere version : a droite de la carte sur bureau, a la
                         taille du nom. Cyril, en recette : « le placement sur la
                         droite n'est pas bon, fait en dessous du prenom nom, un peu
                         comme la card de l'annuaire ». Un seul exemplaire, donc —
                         ce qui supprime au passage tout risque de divergence entre
                         deux copies.

                         Plus grande que sur la carte de l'annuaire (`text-xs`) :
                         ici c'est l'en-tete de la fiche, pas une vignette. Mais
                         sous la taille du nom, qui reste le premier mot de la page.

                         Le bloc d'identite est centre sous `sm` et aligne a gauche
                         au-dela : la structure suit, sans rien declarer. --}}
                    @if($afficheStructure)
                    <p class="mt-1 text-base sm:text-lg font-bold text-[var(--bp-accent)] break-words"
                       data-profile-structure>{{ $user->structure }}</p>
                    @endif

                    {{-- TASK-1762 point 17 — le slogan, EN DESSOUS de la
                         structure (qu'elle s'affiche ou non — le slogan, lui,
                         est obligatoire pour tout le monde). --}}
                    @if(filled($user->slogan))
                    <p class="mt-1 text-sm sm:text-base italic text-gray-600 dark:text-gray-300 break-words"
                       data-profile-slogan>{{ $user->slogan }}</p>
                    @endif

                    {{-- TASK-1788 correctif 04 — l'en-tete ne porte plus que
                         l'IDENTITE.

                         Les pastilles de note, d'echanges et de temps, le bilan
                         d'entraide, les remerciements et les badges sont partis
                         dans la colonne de droite (`partials/apercu-public`) :
                         ils noyaient ce qu'on vient chercher en premier sur une
                         fiche — qui est cette personne.

                         La LOCALISATION reste, elle : elle dit ou se trouve la
                         personne, pas ce qu'elle a accompli. Elle etait melee aux
                         compteurs dans la meme rangee ; elle a desormais la
                         sienne. --}}
                    @if($user->public_location)
                    <div class="flex flex-wrap justify-center sm:justify-start gap-2 mt-3">
                        <span class="inline-flex items-center gap-1 px-2.5 py-1 bg-gray-50 dark:bg-gray-700/50 text-gray-500 dark:text-gray-400 rounded-lg text-xs font-medium"
                              data-profile-localisation>
                            📍 {{ $user->public_location }}
                        </span>
                    </div>
                    @endif

                    {{-- TASK-1750 — les liens declares s'ajoutent a la rangee
                         existante. La condition les prend en compte, sinon la
                         rangee resterait invisible pour qui n'aurait rempli que
                         la liste et aucun des deux champs du haut. --}}
                    @php($_liensDeclares = $user->liensDeProfil())

                    <!-- Links row -->
                    @if($user->website || $user->linkedin_url || $_liensDeclares)
                    <div class="flex flex-wrap justify-center sm:justify-start gap-3 mt-3">
                        @if($user->website)
                        <a href="{{ $user->website }}" target="_blank" rel="noopener noreferrer"
                           class="inline-flex items-center gap-1 text-xs text-indigo-600 dark:text-indigo-400 hover:underline">
                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9"/></svg>
                            {{ parse_url($user->website, PHP_URL_HOST) ?: $user->website }}
                        </a>
                        @endif
                        @if($user->linkedin_url)
                        <a href="{{ $user->linkedin_url }}" target="_blank" rel="noopener noreferrer"
                           class="inline-flex items-center gap-1 text-xs text-blue-600 dark:text-blue-400 hover:underline">
                            <svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 24 24"><path d="M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433a2.062 2.062 0 01-2.063-2.065 2.064 2.064 0 112.063 2.065zm1.782 13.019H3.555V9h3.564v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.222 0h.003z"/></svg>
                            LinkedIn
                        </a>
                        @endif

                        {{-- Les liens supplementaires. `ProfileLinks::hote()`
                             retombe sur l'URL complete quand `parse_url()` ne
                             rend pas d'hote, pour ne jamais afficher une
                             pastille sans texte.

                             TASK-1762 point 18 — chaque TYPE porte desormais
                             son propre pictogramme (demande de Cyril : « pour
                             Instagram, l'icone d'Instagram »), plus seulement
                             sa couleur. `iconeRemplie()` choisit le mode de
                             rendu : un logo de marque est plein, une icone de
                             trait (site web, blog, app) garde le gabarit
                             Heroicons du reste de l'application. --}}
                        @foreach($_liensDeclares as $_lien)
                        @php($_remplie = \App\Support\ProfileLinks::iconeRemplie($_lien['type']))
                        <a href="{{ $_lien['url'] }}" target="_blank" rel="noopener noreferrer"
                           data-profile-link="{{ $_lien['type'] }}"
                           class="inline-flex items-center gap-1 text-xs hover:underline {{ \App\Support\ProfileLinks::couleur($_lien['type']) }}">
                            <svg class="w-3.5 h-3.5" fill="{{ $_remplie ? 'currentColor' : 'none' }}" viewBox="0 0 24 24" @unless($_remplie) stroke="currentColor" @endunless>{!! \App\Support\ProfileLinks::icone($_lien['type']) !!}</svg>
                            <span class="sr-only">{{ \App\Support\ProfileLinks::libelle($_lien['type']) }} —</span>
                            {{ \App\Support\ProfileLinks::hote($_lien['url']) }}
                        </a>
                        @endforeach
                    </div>
                    @endif

                    <!-- Contact info -->
                    @if(($user->show_email && $user->email) || ($user->show_phone && $user->phone))
                    <div class="flex flex-wrap justify-center sm:justify-start gap-x-4 gap-y-1 mt-3 text-xs text-gray-400 dark:text-gray-500">
                        @if($user->show_email && $user->email)
                        <span class="inline-flex items-center gap-1">{{ $user->email }}</span>
                        @endif
                        @if($user->show_phone && $user->phone)
                        <span class="inline-flex items-center gap-1">{{ $user->phone }}</span>
                        @endif
                    </div>
                    @endif
                </div>

            </div>
        </div>

        <!-- Main Grid -->
        <div class="md:grid md:grid-cols-3 md:gap-6">

            <!-- Left column -->
            <div class="md:col-span-2 space-y-5">

                <!-- About -->
                @if($user->bio)
                <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-5 sm:p-6">
                    <h2 class="text-sm font-semibold text-gray-400 dark:text-gray-500 uppercase tracking-wider mb-3">{{ __('profile.about') }}</h2>
                    {{-- TASK-1762 point 16 — la presentation est du Markdown
                         depuis que l'edition passe par TipTap. Rendue en texte
                         brut, elle afficherait « **gras** » en clair.

                         `markdown()` est le meme helper que la description des
                         services (`services/show`), avec le meme reglage de
                         saut de ligne. `whitespace-pre-line` disparait : le
                         Markdown gouverne desormais les paragraphes. --}}
                    {{-- TASK-1788 correctif 12 — les memes variantes arbitraires
                         que `blog/show` pour `[data-media-embed]` : une video
                         YouTube inseree depuis la barre d'outils de la bio suit
                         le MEME rendu que dans un article de blog. --}}
                    <div class="prose prose-sm dark:prose-invert max-w-none text-gray-700 dark:text-gray-300 [&_div[data-media-embed]]:rounded-lg [&_div[data-media-embed]]:overflow-hidden [&_div[data-media-embed]]:my-4 [&_div[data-media-embed]_iframe]:w-full [&_div[data-media-embed]_iframe]:h-full [&_div[data-media-embed]_iframe]:border-0" data-profile-bio>
                        {!! markdown($user->bio, ['renderer' => ['soft_break' => "<br />\n"]]) !!}
                    </div>
                </div>
                @endif

                <!-- AI profile owner -->
                @if($memberAiProfile && auth()->check() && auth()->id() === $user->id)
                <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-5">
                    <div class="flex items-center justify-between gap-4">
                        <div class="flex items-center gap-3">
                            <div class="flex h-10 w-10 items-center justify-center rounded-full bg-violet-100 dark:bg-violet-900/30">
                                <svg class="h-5 w-5 text-violet-600 dark:text-violet-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                                </svg>
                            </div>
                            <div>
                                <p class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ __('profile.ai_agent_available') }}</p>
                                <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('profile.ai_agent_enabled') }}</p>
                            </div>
                        </div>
                        <a href="{{ $urlOrg('agent-ia.interactions') }}" class="inline-flex items-center gap-1.5 rounded-lg border border-gray-300 dark:border-gray-600 px-4 py-2 text-sm font-semibold text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700 transition">
                            {{ __('profile.view_exchanges') }}
                        </a>
                    </div>
                </div>
                @endif

                <!-- Mobile CTAs -->
                <div class="space-y-3 md:hidden">
                    @if($memberAiProfile && (auth()->guest() || auth()->id() !== $user->id))
                    <a href="{{ $agentAiChatUrl }}"
                       class="group flex items-start gap-4 rounded-xl border-2 border-indigo-300 dark:border-indigo-700 bg-white dark:bg-gray-800 p-4 transition hover:border-indigo-400 hover:shadow-lg">
                        <span class="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-lg bg-indigo-600 text-white shadow-md ring-1 ring-indigo-500/30 transition group-hover:scale-110 group-hover:bg-indigo-700">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                        </span>
                        <span class="min-w-0 flex-1">
                            <span class="block text-sm font-semibold text-gray-900 dark:text-gray-100">{{ __('profile.agent_cta_title') }}</span>
                            <span class="mt-0.5 block text-xs leading-relaxed text-gray-600 dark:text-gray-400">{{ __('profile.agent_cta_hint') }}</span>
                        </span>
                        <svg class="w-4 h-4 flex-shrink-0 self-center text-indigo-500 transition group-hover:translate-x-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                    </a>
                    @endif

                    @if(auth()->guest() || auth()->id() !== $user->id)
                    <a href="{{ auth()->check() ? $ecrireUrl : route('login') }}"
                       class="group flex items-start gap-4 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-4 transition hover:border-gray-300 dark:hover:border-gray-600 hover:shadow-md">
                        <span class="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-lg bg-gray-100 dark:bg-gray-700 text-gray-500 dark:text-gray-400 transition group-hover:bg-indigo-100 dark:group-hover:bg-indigo-900/30 group-hover:text-indigo-600 dark:group-hover:text-indigo-400">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>
                        </span>
                        <span class="min-w-0 flex-1">
                            <span class="block text-sm font-semibold text-gray-900 dark:text-gray-100">{{ __('profile.write_to', ['name' => $user->full_name]) }}</span>
                            <span class="mt-0.5 block text-xs leading-relaxed text-gray-500 dark:text-gray-400">{{ __('profile.start_conversation') }}</span>
                        </span>
                        <svg class="w-4 h-4 flex-shrink-0 self-center text-gray-400 transition group-hover:translate-x-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                    </a>
                    @endif
                </div>

                <!-- Services -->
                <section>
                    <div class="flex items-center justify-between mb-3">
                        <h2 class="text-sm font-semibold text-gray-400 dark:text-gray-500 uppercase tracking-wider">{{ trans_choice('profile.services_offered', $services->count()) }}</h2>
                        @if($services->isNotEmpty())
                        <span class="text-xs text-gray-400">{{ trans_choice('profile.services_count', $services->count(), ['count' => $services->count()]) }}</span>
                        @endif
                    </div>
                    @if($services->isEmpty())
                    <p class="text-gray-400 text-sm italic">{{ __('profile.no_services') }}</p>
                    @else
                    <div class="grid gap-3 sm:grid-cols-2">
                        @foreach($services as $service)
                        <a href="{{ $urlOrg('services.show', ['service' => $service]) }}" class="group block bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-4 hover:shadow-md hover:border-indigo-200 dark:hover:border-indigo-700 transition">
                            <div class="flex items-start justify-between mb-2">
                                <span class="px-2.5 py-0.5 rounded-full text-xs font-medium text-white" style="background-color:{{ $service->category->color }}">
                                    {{ $service->category->displayName('transactions') }}
                                </span>
                                <span class="font-bold text-indigo-600 dark:text-indigo-400 text-sm whitespace-nowrap ml-2" data-entraide-temps>{{ \App\Support\Entraide\TempsPartage::libelle($service->points_cost) }}</span>
                            </div>
                            <p class="font-medium text-gray-900 dark:text-gray-100 text-sm mb-2 group-hover:text-indigo-600 dark:group-hover:text-indigo-400 transition">{{ $service->title }}</p>
                            @if($service->skills->isNotEmpty())
                            <div class="flex flex-wrap gap-1">
                                @foreach($service->skills->take(3) as $skill)
                                <span class="px-2 py-0.5 bg-gray-100 dark:bg-gray-700 text-gray-500 dark:text-gray-400 rounded text-xs">{{ $skill->name }}</span>
                                @endforeach
                                @if($service->skills->count() > 3)
                                <span class="px-2 py-0.5 text-gray-400 text-xs">+{{ $service->skills->count() - 3 }}</span>
                                @endif
                            </div>
                            @endif
                        </a>
                        @endforeach
                    </div>
                    @endif
                </section>

                <!-- Open requests -->
                @if($openRequests->isNotEmpty())
                <section>
                    <div class="flex items-center justify-between mb-3">
                        <h2 class="text-sm font-semibold text-gray-400 dark:text-gray-500 uppercase tracking-wider">{{ trans_choice('profile.help_section', $openRequests->count()) }}</h2>
                        <span class="text-xs text-gray-400">{{ trans_choice('profile.requests_count', $openRequests->count(), ['count' => $openRequests->count()]) }}</span>
                    </div>
                    <div class="grid gap-3 sm:grid-cols-2">
                        @foreach($openRequests as $req)
                        <a href="{{ $urlOrg('requests.show', ['request' => $req]) }}" class="group block bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-4 hover:shadow-md hover:border-green-200 dark:hover:border-green-700 transition">
                            <div class="flex items-start justify-between mb-2">
                                <span class="px-2.5 py-0.5 rounded-full text-xs font-medium text-white" style="background-color:{{ $req->category->color }}">
                                    {{ $req->category->displayName('transactions') }}
                                </span>
                                <span class="font-bold text-green-600 dark:text-green-400 text-sm whitespace-nowrap ml-2">
                                    {{ $req->budget_min }}{{ $req->budget_max ? '–'.$req->budget_max : '+' }} pts
                                </span>
                            </div>
                            <p class="font-medium text-gray-900 dark:text-gray-100 text-sm mb-1 group-hover:text-green-600 dark:group-hover:text-green-400 transition">{{ $req->title }}</p>
                            @if($req->deadline)
                            <p class="text-xs text-gray-400 flex items-center gap-1">⏰ {{ __('profile.before_deadline', ['date' => $req->deadline->format('d/m/Y')]) }}</p>
                            @endif
                        </a>
                        @endforeach
                    </div>
                </section>
                @endif

                {{-- TASK-1697 — les Boucles dont cette personne est membre,
                     animateur ou proprietaire. Perimetre : voir le controleur.
                     Nommer une Boucle ne revele rien de plus que le catalogue
                     (T1075 : « privee » veut dire « contenu reserve aux
                     membres », pas « cachee »). Masquee si vide, comme « Avis »
                     et « Demandes ». --}}
                @if($boucles->isNotEmpty())
                <section data-profile-loops>
                    <div class="flex items-center justify-between mb-3">
                        <h2 class="text-sm font-semibold text-gray-400 dark:text-gray-500 uppercase tracking-wider">{{ __('profile.loops_section') }}</h2>
                        <span class="text-xs text-gray-400">{{ trans_choice('profile.loops_count', $boucles->count(), ['count' => $boucles->count()]) }}</span>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        @foreach($boucles as $boucle)
                        <a href="{{ $urlOrg('loops.show', ['loop' => $boucle->slug ?: $boucle->getKey()]) }}"
                           data-profile-loop="{{ $boucle->id }}"
                           class="px-3 py-1.5 bg-violet-50 dark:bg-violet-900/20 text-violet-700 dark:text-violet-300 rounded-lg text-sm font-medium hover:bg-violet-100 dark:hover:bg-violet-900/40 transition">
                            {{ $boucle->name }}
                        </a>
                        @endforeach
                    </div>
                </section>
                @endif

                {{-- TASK-1697 — ses derniers commentaires de blog, avec le lien
                     vers l'article. Ceux qu'il a ECRITS : la fiche presente ce
                     que cette personne apporte. Les articles d'accueil sont
                     filtres publies au controleur, donc aucun lien mort. --}}
                @if($commentaires->isNotEmpty())
                <section data-profile-comments>
                    <div class="flex items-center justify-between mb-3">
                        <h2 class="text-sm font-semibold text-gray-400 dark:text-gray-500 uppercase tracking-wider">{{ __('profile.comments_section') }}</h2>
                        <span class="text-xs text-gray-400">{{ trans_choice('profile.comments_count', $commentaires->count(), ['count' => $commentaires->count()]) }}</span>
                    </div>
                    <div class="space-y-3">
                        @foreach($commentaires as $commentaire)
                        <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-4">
                            <p class="text-sm text-gray-700 dark:text-gray-300 line-clamp-3">{{ $commentaire->content }}</p>
                            <div class="mt-2 flex flex-wrap items-center gap-2 text-xs text-gray-400">
                                <span>{{ $commentaire->created_at->diffForHumans() }}</span>
                                <span class="text-gray-300 dark:text-gray-600">·</span>
                                {{-- Pas d'ancre vers le commentaire : la section des
                                     commentaires de l'article est encore MASQUEE par
                                     defaut (`showComments: false`), un fragment n'y
                                     mene a rien. L'ancre arrive avec le chantier des
                                     commentaires. --}}
                                <a href="{{ $urlOrg('blog.show', ['post' => $commentaire->post]) }}"
                                   data-profile-comment="{{ $commentaire->id }}"
                                   class="font-medium text-indigo-600 hover:underline dark:text-indigo-400">
                                    {{ $commentaire->post->title }}
                                </a>
                            </div>
                        </div>
                        @endforeach
                    </div>
                </section>
                @endif

                <!-- Reviews -->
                @if($reviews->isNotEmpty())
                <section>
                    <h2 class="text-sm font-semibold text-gray-400 dark:text-gray-500 uppercase tracking-wider mb-3">
                        {{ __('profile.reviews_section') }}
                        <span class="text-gray-400 font-normal normal-case">({{ $reviews->count() }})</span>
                    </h2>
                    <div class="space-y-3">
                        @foreach($reviews as $review)
                        <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-4">
                            <div class="flex items-center justify-between mb-2">
                                <div class="flex items-center gap-2">
                                    <img src="{{ $review->reviewer->avatar_url }}" class="w-6 h-6 rounded-full" alt="">
                                    <span class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ $review->reviewer->full_name }}</span>
                                </div>
                                <div class="flex items-center gap-0.5">
                                    @for($i = 1; $i <= 5; $i++)
                                    <span class="text-sm {{ $i <= $review->rating ? 'text-yellow-400' : 'text-gray-300 dark:text-gray-600' }}">★</span>
                                    @endfor
                                    <span class="text-xs text-gray-400 ml-1.5">{{ $review->created_at->diffForHumans() }}</span>
                                </div>
                            </div>
                            @if($review->comment)
                            <p class="text-sm text-gray-600 dark:text-gray-400 italic leading-relaxed">"{{ $review->comment }}"</p>
                            @endif
                        </div>
                        @endforeach
                    </div>
                </section>
                @endif

            </div>

            {{-- TASK-1788 correctif 04 — la colonne de droite n'est plus reservee
                 au bureau.

                 Elle l'etait (`hidden md:block`) parce qu'elle ne portait que des
                 appels a l'action, que le smartphone rend deja ailleurs (bloc
                 « Mobile CTAs » plus haut). Elle accueille desormais aussi
                 l'apercu chiffre, qui doit se voir aux DEUX largeurs.

                 D'ou le partage : les appels a l'action gardent leur
                 `hidden md:block` — sinon le smartphone les afficherait deux fois
                 —, l'apercu reste visible. Sous `md` la grille ne s'applique pas,
                 les deux colonnes redeviennent des blocs empiles, et l'apercu
                 tombe donc tout seul en fin d'ecran. --}}
            <!-- Right column -->
            <aside class="md:col-span-1 space-y-4">

                {{-- TASK-1788 correctif 04 (suite) — le Blog passe a DROITE, et
                     AVANT l'apercu chiffre. Demande de Cyril.

                     `sm:grid-cols-2` est retire : c'est une media query sur la
                     largeur de l'ECRAN, pas sur celle du conteneur. Dans une
                     colonne qui fait un tiers de la page, elle aurait coupe les
                     cartes en deux des 640 px.

                     `id="articles"` est CONSERVE : c'est la cible de l'ancre
                     posee par l'annuaire (T1697 correctif 15). Le deplacer sans
                     lui aurait casse un lien qui ne se plaint jamais. --}}
                <!-- Blog posts -->
                @if($blogPosts->isNotEmpty())
                {{-- `id` : cible de l'ancre posee par l'annuaire (T1697 correctif 15). --}}
                <section id="articles">
                    <div class="flex items-center justify-between mb-3">
                        <h2 class="text-sm font-semibold text-gray-400 dark:text-gray-500 uppercase tracking-wider">{{ __('profile.blog_section') }}</h2>
                        <span class="text-xs text-gray-400">{{ trans_choice('profile.blog_count', $blogPosts->count(), ['count' => $blogPosts->count()]) }}</span>
                    </div>
                    <div class="grid gap-3">
                        @foreach($blogPosts as $post)
                        <a href="{{ $urlOrg('blog.show', ['post' => $post]) }}" class="group block bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-4 hover:shadow-md transition">
                            <div class="flex items-center justify-between mb-2">
                                @if($post->category)
                                <span class="px-2 py-0.5 rounded-full text-xs font-medium text-white" style="background-color:{{ $post->category->color }}">
                                    {{ $post->category->displayName('blog') }}
                                </span>
                                @endif
                                <span class="text-xs text-gray-400">{{ $post->published_at?->format('d/m/Y') }}</span>
                            </div>
                            <p class="font-medium text-gray-900 dark:text-gray-100 text-sm mb-1 line-clamp-2">{{ $post->title }}</p>
                            @if($post->summary)
                            <p class="text-xs text-gray-500 dark:text-gray-400 line-clamp-2">{{ $post->summary }}</p>
                            @endif
                        </a>
                        @endforeach
                    </div>
                </section>
                @endif

                @include('profile.partials.apercu-public')

                <div class="hidden md:block space-y-4">

                <!-- AI agent CTA -->
                @if($memberAiProfile && (auth()->guest() || auth()->id() !== $user->id))
                <a href="{{ $agentAiChatUrl }}"
                   class="group flex flex-col gap-3 rounded-xl border-2 border-indigo-300 dark:border-indigo-700 bg-white dark:bg-gray-800 p-5 transition hover:border-indigo-400 hover:shadow-lg">
                    <div class="flex items-center gap-3">
                        <span class="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-lg bg-indigo-600 text-white shadow-md ring-1 ring-indigo-500/30 transition group-hover:scale-110 group-hover:bg-indigo-700">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                        </span>
                        <span class="font-semibold text-gray-900 dark:text-gray-100 text-base">{{ __('profile.agent_cta_title') }}</span>
                    </div>
                    <p class="text-sm leading-relaxed text-gray-600 dark:text-gray-400">{{ __('profile.agent_cta_hint') }}</p>
                    <span class="inline-flex items-center self-start gap-1 text-xs font-medium text-indigo-600 dark:text-indigo-400 group-hover:underline">
                        {{ __('profile.start') }}
                        <svg class="w-3.5 h-3.5 transition group-hover:translate-x-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                    </span>
                </a>
                @endif

                <!-- Write to CTA -->
                @if(auth()->guest() || auth()->id() !== $user->id)
                <a href="{{ auth()->check() ? $ecrireUrl : route('login') }}"
                   class="group flex flex-col gap-2 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-5 transition hover:border-gray-300 dark:hover:border-gray-600 hover:shadow-md">
                    <div class="flex items-center gap-3">
                        <span class="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-lg bg-gray-100 dark:bg-gray-700 text-gray-500 dark:text-gray-400 transition group-hover:bg-indigo-100 dark:group-hover:bg-indigo-900/30 group-hover:text-indigo-600 dark:group-hover:text-indigo-400">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>
                        </span>
                        <span class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ __('profile.write_to', ['name' => $user->full_name]) }}</span>
                    </div>
                    <p class="text-xs leading-relaxed text-gray-500 dark:text-gray-400">{{ __('profile.start_conversation') }}</p>
                </a>
                @endif

                <!-- Report -->
                @auth
                @if(auth()->id() !== $user->id)
                <div x-data="{ open: false }" class="pt-2">
                    <button @click="open = !open" class="text-xs text-gray-400 hover:text-red-500 transition flex items-center gap-1">
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 21v-4m0 0V5a2 2 0 012-2h6.5l1 1H21l-3 6 3 6h-8.5l-1-1H5a2 2 0 00-2 2zm9-13.5V9"/></svg>
                        {{ __('profile.report') }}
                    </button>
                    <div x-show="open" x-cloak class="mt-2 rounded-xl border border-red-200 bg-red-50 p-4 text-left shadow-lg dark:border-red-800 dark:bg-red-900/20">
                        <form method="POST" action="{{ $urlOrg('reports.user', ['user' => $user]) }}">
                            @csrf
                            <p class="mb-2 text-xs font-semibold text-red-700 dark:text-red-300">{{ __('profile.report_user') }}</p>
                            <select name="reason" required class="mb-2 w-full rounded-lg border border-red-200 bg-white px-3 py-2 text-sm text-gray-900 dark:border-red-700 dark:bg-gray-800 dark:text-gray-100">
                                <option value="">{{ __('profile.reason') }}...</option>
                                <option value="Comportement abusif">{{ __('profile.abuse') }}</option>
                                <option value="Arnaque ou fraude">{{ __('profile.scam') }}</option>
                                <option value="Faux profil">{{ __('profile.fake') }}</option>
                                <option value="Autre">{{ __('profile.other') }}</option>
                            </select>
                            <textarea name="details" rows="2" placeholder="{{ __('profile.details') }}..." class="mb-2 w-full resize-none rounded-lg border border-red-200 bg-white px-3 py-2 text-sm text-gray-900 dark:border-red-700 dark:bg-gray-800 dark:text-gray-100"></textarea>
                            <div class="flex gap-2">
                                <button type="submit" class="flex-1 rounded-lg bg-red-600 px-3 py-1.5 text-xs text-white hover:bg-red-700">{{ __('profile.send') }}</button>
                                <button type="button" @click="open = false" class="rounded-lg border border-red-200 px-3 py-1.5 text-xs text-red-600 hover:bg-red-50 dark:hover:bg-red-900/30">{{ __('profile.cancel') }}</button>
                            </div>
                        </form>
                    </div>
                </div>
                @endif
                @endauth

                </div>

            </aside>

        </div>
    </x-page-container>

    {{-- TASK-1749 — « changer ma photo », en UN geste.

         Le composant `x-modal` du depot est reemploye tel quel : il porte deja
         le piege a focus, la fermeture a Echap et au clic exterieur. En ecrire
         un autre ici aurait donne deux fenetres a maintenir.

         Le formulaire poste sur `profile.avatar`, route dediee : `profile.update`
         exige `name`, `email` et `phone`, et aurait refuse un envoi qui ne porte
         qu'une photo (voir la note du controleur).

         La zone de depot n'est PAS un `<div>` avec un `click` simule : c'est un
         `<label for>`. Le clavier et les lecteurs d'ecran y arrivent donc sans
         qu'on ait a le prevoir, et l'`<input type=file>` reste la vraie
         commande — simplement masque visuellement, pas `hidden` (un champ
         `hidden` ne se declenche pas au clavier). --}}
    @if(auth()->check() && auth()->id() === $user->id)
    {{-- TASK-1788 correctif 02 — l'adresse de l'avatar PAR DEFAUT, demandee au
         modele sur une COPIE dont l'avatar est vide plutot que recopiee ici :
         `getAvatarUrlAttribute()` est la seule autorite sur « a quoi ressemble
         quelqu'un sans photo ». `clone` ne touche pas l'original, le tableau
         d'attributs etant copie par valeur. --}}
    <x-modal name="photo-profil" :show="false" focusable maxWidth="md">
        <form method="POST" action="{{ $urlOrg('profile.avatar') }}" enctype="multipart/form-data"
              x-data="{
                  apercu: null,
                  survol: false,
                  nom: null,
                  /* TASK-1788 correctif 02 — la suppression depuis la fenetre.
                     Meme modele que le formulaire de profil : une INTENTION que
                     l'on voit a l'ecran avant de l'envoyer, et qui s'annule. */
                  supprimer: false,
                  get source() {
                      if (this.apercu) return this.apercu;
                      return this.supprimer ? '{{ (clone $user)->setAttribute('avatar', null)->avatar_url }}' : '{{ $user->avatar_url }}';
                  },
                  choisir(fichiers) {
                      const f = fichiers && fichiers[0];
                      if (! f) return;
                      this.nom = f.name;
                      this.apercu = URL.createObjectURL(f);
                      /* Choisir une photo annule la suppression : le dernier
                         geste parle. */
                      this.supprimer = false;
                  },
                  deposer(e) {
                      this.survol = false;
                      /* Le champ doit RECEVOIR le fichier depose : sans cela
                         l'apercu changerait et l'envoi partirait vide. */
                      this.$refs.champ.files = e.dataTransfer.files;
                      this.choisir(e.dataTransfer.files);
                  },
                  basculerSuppression() {
                      this.supprimer = ! this.supprimer;
                      if (this.supprimer) {
                          this.apercu = null;
                          this.nom = null;
                          this.$refs.champ.value = '';
                      }
                  }
              }"
              class="p-6">
            @csrf

            <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ __('profile.avatar_change') }}</h2>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('profile.avatar_modal_help') }}</p>

            <div class="mt-5 flex flex-col items-center">
                <img :src="source"
                     class="h-28 w-28 rounded-full object-cover ring-4 ring-indigo-100 dark:ring-indigo-900/40" alt="">

                <label for="avatar-modal"
                       @dragover.prevent="survol = true"
                       @dragleave.prevent="survol = false"
                       @drop.prevent="deposer($event)"
                       :class="survol
                           ? 'border-indigo-500 bg-indigo-50 dark:bg-indigo-900/20'
                           : 'border-gray-300 dark:border-gray-600 hover:border-indigo-400 hover:bg-gray-50 dark:hover:bg-gray-700/40'"
                       class="mt-5 w-full cursor-pointer rounded-xl border-2 border-dashed px-4 py-6 text-center transition">
                    <svg class="mx-auto h-7 w-7 text-indigo-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5V18a2 2 0 002 2h14a2 2 0 002-2v-1.5M12 15V4m0 0L8 8m4-4l4 4"/></svg>
                    <span class="mt-2 block text-sm font-semibold text-indigo-600 dark:text-indigo-400">{{ __('profile.avatar_drop') }}</span>
                    <span class="mt-0.5 block text-xs text-gray-400" x-text="nom ?? '{{ __('profile.avatar_help') }}'"></span>
                </label>

                {{-- `required` devient CONDITIONNEL : un envoi qui supprime n'a
                     pas de fichier, et un `required` nu le ferait refuser par le
                     navigateur avant meme d'atteindre le serveur. --}}
                <input id="avatar-modal" name="avatar" type="file" accept="image/*"
                       x-bind:required="! supprimer"
                       x-ref="champ" @change="choisir($event.target.files)"
                       class="sr-only">
            </div>

            {{-- TASK-1788 correctif 02 — SUPPRIMER sa photo, depuis la fenetre
                 elle-meme : c'est ici qu'on vient quand on pense a sa photo.
                 Propose seulement si une photo est posee. --}}
            @if($user->avatar)
            <input type="hidden" name="supprimer_avatar" x-bind:value="supprimer ? '1' : '0'">

            <div class="mt-4 text-center">
                <button type="button" @click="basculerSuppression()"
                        data-avatar-remove-modal
                        class="text-xs font-medium underline-offset-2 hover:underline"
                        x-bind:class="supprimer
                            ? 'text-gray-500 dark:text-gray-400'
                            : 'text-red-600 dark:text-red-400'"
                        x-text="supprimer
                            ? @js(__('profile.avatar_remove_cancel'))
                            : @js(__('profile.avatar_remove'))"></button>

                <p x-show="supprimer" x-cloak
                   class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('profile.avatar_remove_pending') }}</p>
            </div>
            @endif

            <x-input-error class="mt-3" :messages="$errors->get('avatar')" />

            <div class="mt-6 flex justify-end gap-3">
                <button type="button" x-on:click="$dispatch('close')"
                        class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 transition hover:bg-gray-50 dark:border-gray-600 dark:text-gray-300 dark:hover:bg-gray-700">
                    {{ __('profile.cancel') }}
                </button>
                {{-- Le bouton sert les DEUX gestes, et il le dit : enregistrer
                     une photo choisie, ou supprimer celle qui est posee. Il
                     reste inerte tant qu'aucun des deux n'est demande. --}}
                <button type="submit"
                        data-avatar-submit
                        class="rounded-lg px-4 py-2 text-sm font-semibold text-white transition disabled:cursor-not-allowed disabled:opacity-50"
                        x-bind:class="supprimer
                            ? 'bg-red-600 hover:bg-red-700'
                            : 'bg-indigo-600 hover:bg-indigo-700'"
                        x-text="supprimer
                            ? @js(__('profile.avatar_remove_confirm'))
                            : @js(__('profile.avatar_save'))"
                        :disabled="! apercu && ! supprimer"></button>
            </div>
        </form>
    </x-modal>
    @endif
</x-app-layout>
