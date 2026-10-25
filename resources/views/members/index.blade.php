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
                   placeholder="{{ __('directory.search_placeholder') }}"
                   class="w-full sm:w-80 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 px-3 py-2 text-sm text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-indigo-500">
            <button type="submit" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">{{ __('directory.search_submit') }}</button>
            @if($recherche !== '')
            <a href="{{ url()->current() }}" class="text-sm text-gray-500 hover:text-indigo-600">{{ __('directory.search_clear') }}</a>
            @endif
        </form>

        @if($recherche !== '' && $members->total() === 0)
        <p class="py-16 text-center text-sm text-gray-500 dark:text-gray-400" data-directory-empty>{{ __('directory.search_no_result') }}</p>
        @endif

        <div class="grid sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-5">
            @foreach($members as $member)
            {{-- TASK-1693 bug AA — l'annuaire fabriquait son URL A LA MAIN, avec
                 l'identifiant, en contournant `route()`. C'est pour cela qu'il
                 est reste en UUID la ou les autres ecrans suivaient. --}}
            @php
                $orgParamAnnuaire = request()->route('organization');
                $nombreArticles = (int) ($articleCounts[$member->id] ?? 0);
                $boucles = $loopsByUser[$member->id] ?? collect();
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
            @endphp
            {{-- TASK-1697 correctif 15 — la carte n'est PLUS un seul lien.
                 Elle en portait un, englobant tout ; elle doit desormais porter
                 des liens internes (site web, LinkedIn, articles, Boucles). Un
                 lien imbrique dans un lien est invalide en HTML et le
                 navigateur le deplace — meme famille que le formulaire
                 imbrique corrige en T1668. Le lien vers le profil se resserre
                 donc sur l'avatar et le nom, et le bouton « ecrire » reste
                 pose A COTE, en superposition. --}}
            <div class="relative h-full bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-5 hover:shadow-md hover:border-indigo-300 dark:hover:border-indigo-600 transition flex flex-col gap-3">

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
                        @if($member->public_location)
                        <p class="text-xs text-gray-400 truncate">📍 {{ $member->public_location }}</p>
                        @endif
                    </div>
                </a>

                <!-- Bio tronquée -->
                @if($member->bio)
                <p class="text-xs text-gray-500 dark:text-gray-400 leading-relaxed line-clamp-3">{{ $member->bio }}</p>
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
                <div class="mt-auto pt-2 border-t border-gray-100 dark:border-gray-700 flex items-center gap-3 text-xs text-gray-400">
                    {{-- TASK-1697 correctif 15 — « micro-services » devient
                         « propositions ». Le terme global `$T['service']` vient
                         de `config/terms.php`, surchargeable par Organization :
                         le changer ici aurait renomme QUATRE autres surfaces que
                         Cyril n'a pas demandees. L'annuaire porte donc sa propre
                         clef. --}}
                    <span title="{{ __('directory.active_services_title') }}">
                        <span class="font-semibold text-indigo-600 dark:text-indigo-400">{{ $member->active_services_count }}</span> {{ trans_choice('directory.service_label', $member->active_services_count) }}
                    </span>
                    <span class="text-gray-300 dark:text-gray-600">·</span>
                    <span title="{{ __('directory.open_requests') }}">
                        <span class="font-semibold text-green-600 dark:text-green-400">{{ $member->open_requests_count }}</span> {{ trans_choice('directory.request_label', $member->open_requests_count) }}
                    </span>
                    {{-- TASK-1697 correctif 15 — articles publies, et le lien
                         qui y mene. Le produit n'a AUCUNE page « articles de
                         cet auteur » : le seul ecran qui les liste est la
                         section « Articles publies » de sa fiche profil, d'ou
                         l'ancre. Affiche seulement s'il en a. --}}
                    @if($nombreArticles > 0)
                    <span class="text-gray-300 dark:text-gray-600">·</span>
                    <a href="{{ $profileRoute }}#articles" data-member-articles="{{ $member->id }}"
                       title="{{ __('directory.articles_title') }}"
                       class="hover:text-indigo-600 dark:hover:text-indigo-400">
                        <span class="font-semibold text-amber-600 dark:text-amber-400">{{ $nombreArticles }}</span> {{ trans_choice('directory.article_label', $nombreArticles) }}
                    </a>
                    @endif
                </div>

                {{-- TASK-1697 correctif 15 — les Boucles dont cette personne
                     est membre, animateur ou proprietaire.
                     Perimetre et confidentialite : voir le commentaire du
                     controleur. Nommer une Boucle ne revele rien de plus que
                     le catalogue (TASK-1075). --}}
                @if($boucles->isNotEmpty())
                <div class="flex flex-wrap gap-1" title="{{ __('directory.loops_title') }}">
                    @foreach($boucles as $boucle)
                    <a href="{{ $orgParamAnnuaire && Route::has('organization.loops.show')
                            ? route('organization.loops.show', ['organization' => $orgParamAnnuaire, 'loop' => $boucle->slug ?: $boucle->getKey()])
                            : route('loops.show', $boucle->slug ?: $boucle->getKey()) }}"
                       data-member-loop="{{ $boucle->id }}"
                       class="px-2 py-0.5 bg-violet-50 dark:bg-violet-900/20 text-violet-700 dark:text-violet-300 rounded text-xs font-medium hover:bg-violet-100 dark:hover:bg-violet-900/40 transition">
                        {{ $boucle->name }}
                    </a>
                    @endforeach
                </div>
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

            @auth
            @if(auth()->id() !== $member->id)
            <a href="{{ $ecrireUrl }}"
               data-member-write="{{ $member->id }}"
               title="{{ __('directory.write_to', ['name' => $member->fullName]) }}"
               aria-label="{{ __('directory.write_to', ['name' => $member->fullName]) }}"
               class="absolute top-3 right-3 z-10 flex h-8 w-8 items-center justify-center rounded-lg border border-gray-200 bg-white/90 text-gray-500 shadow-sm transition hover:border-indigo-300 hover:text-indigo-600 dark:border-gray-600 dark:bg-gray-800/90 dark:text-gray-400 dark:hover:text-indigo-400">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>
            </a>
            @endif
            @endauth
            </div>
            @endforeach
        </div>

        <div class="mt-8">
            {{ $members->links() }}
        </div>

    </x-page-container>
</x-app-layout>
