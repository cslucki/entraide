<x-app-layout>
    {{--
        TASK-1130 (doctrine Cyril, derniere passe) : « Documents » nomme le
        module ; « Mes documents » est la racine documentaire personnelle,
        ouverte par defaut. Trois portees de gouvernance, jamais melangees :

            Mes documents · Partagés · Boucles

        « Partagés » est une VUE (ce que d'autres m'ont partage), jamais un
        Dossier physique : partager n'est pas deplacer, le contenu garde son
        emplacement et son proprietaire.

        La liste desktop porte de vraies colonnes, revelees progressivement
        (lg : Type/Partage/Modifie/Taille ; xl : + Proprietaire/Cree). Sous
        `lg`, chaque ligne redevient un resume compact : nom, puis
        « Type · Partage · Modifie ». Le nom reste la donnee principale.
    --}}
    @php
        $organizationRouteParam = request()->route('organization');
        $csrfToken = csrf_token();
        $ownedData = $dossiers->map(fn($d) => ['id' => $d->getKey(), 'name' => $d->name])->values()->all();

        // Une seule anatomie pour les trois portees : chaque entree porte de
        // quoi remplir toutes les colonnes, quelle que soit son origine.
        $lignes = collect();
        foreach ($dossiers as $d) {
            $lignes->push([
                'portee' => 'miens',
                'dossier' => $d,
                'nom' => $d->name,
                'renommable' => true,
                'supprimable' => true,
                'proprietaire' => __('dossiers.owner_me'),
                'partage' => $d->dossier_members_count > 0 ? __('dossiers.share_shared') : __('dossiers.share_private'),
                'partagePar' => null,
                'lectureSeule' => false,
                'boucle' => false,
            ]);
        }
        foreach ($sharedDossiers as $d) {
            $membre = $d->dossierMembers->first();
            $nomProprietaire = $d->owner?->isDisplayableIn(currentOrganization())
                ? $d->owner->publicDisplayName()
                : __('profile.deactivated_user');
            $lignes->push([
                'portee' => 'partages',
                'dossier' => $d,
                'nom' => $d->name,
                'renommable' => false,
                'supprimable' => false,
                'proprietaire' => $nomProprietaire,
                'partage' => __('dossiers.share_shared'),
                'partagePar' => $nomProprietaire,
                'lectureSeule' => ($membre?->role ?? 'reader') === 'reader',
                'boucle' => false,
            ]);
        }
        foreach ($loopDossiers as $d) {
            $lignes->push([
                'portee' => 'boucles',
                'dossier' => $d,
                'nom' => $d->loop?->name ?? $d->name,
                'renommable' => false,
                'supprimable' => false,
                // Le nom de la ligne EST deja celui de la Boucle qui detient
                // ce Drive : le repeter en « Proprietaire » n'ajoute rien.
                'proprietaire' => '—',
                'partage' => __('dossiers.share_loop'),
                'partagePar' => null,
                'lectureSeule' => false,
                'boucle' => true,
            ]);
        }

        // Colonnes revelees progressivement — les cellules masquees sortent du
        // flux grid (`display:none`), le gabarit correspond donc exactement au
        // nombre de cellules visibles a chaque palier.
        $grille = 'grid grid-cols-[minmax(0,1fr)_2.75rem] items-center gap-x-3'
            .' lg:grid-cols-[minmax(0,2.4fr)_5.5rem_6rem_6.5rem_6.5rem_2.75rem]'
            .' xl:grid-cols-[minmax(0,2.4fr)_5.5rem_6rem_9rem_6.5rem_6.5rem_6.5rem_2.75rem]';
        $celluleLg = 'hidden lg:block min-w-0 truncate text-xs text-gray-500 dark:text-gray-400';
        $celluleXl = 'hidden xl:block min-w-0 truncate text-xs text-gray-500 dark:text-gray-400';
    @endphp

    <x-slot name="title">{{ __('dossiers.documents_title') }} — {{ $brandOrganizationName ?? 'BouclePro' }}</x-slot>

    @php
        // Index leger de toutes les lignes : « aucun resultat » devient un
        // calcul, pas une lecture du DOM.
        $lignesPourJs = $lignes->map(fn ($l) => ['portee' => $l['portee'], 'nom' => mb_strtolower($l['nom'])])->values()->all();
    @endphp
    <div x-data="documentsIndex({{ json_encode($ownedData) }}, '{{ $csrfToken }}', '{{ $organizationRouteParam }}', {{ json_encode($lignesPourJs) }})" x-init="init()" class="relative">
    <x-page-container>
        {{-- Sur 390px, chaque pixel avant la premiere ligne compte : le
             sous-titre repete ce que les trois portees disent deja, il ne
             s'affiche donc qu'a partir de `sm`. --}}
        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between sm:gap-4">
            <div class="min-w-0">
                <h1 class="text-2xl font-bold text-gray-900 sm:text-3xl dark:text-gray-100">{{ __('dossiers.documents_title') }}</h1>
                <p class="mt-2 hidden max-w-2xl text-sm text-gray-600 sm:block dark:text-gray-300">{{ __('dossiers.index_subtitle') }}</p>
            </div>
            {{-- Un seul CTA de creation : sur mobile il remplace le FAB global,
                 neutralise sur ce module (voir components/mobile-fab). --}}
            <a href="{{ route('organization.dossiers.create', ['organization' => $organizationRouteParam]) }}"
               x-show="portee === 'miens'"
               class="inline-flex min-h-11 shrink-0 items-center justify-center gap-2 rounded-lg bg-indigo-600 px-4 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-700">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" /></svg>
                {{ __('dossiers.create') }}
            </a>
        </div>

        @if(session('success'))
            <div class="mt-6 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800 dark:border-emerald-900/60 dark:bg-emerald-950/40 dark:text-emerald-200">
                {{ session('success') }}
            </div>
        @endif

        {{-- Toast ancre en bas, comme dans le Drive — jamais dans le flux. --}}
        <div x-show="flash" x-transition x-cloak role="status" aria-live="polite"
             :class="flashType === 'error' ? 'border-red-200 bg-red-50 text-red-800 dark:border-red-900/60 dark:bg-red-950/40 dark:text-red-200' : 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-900/60 dark:bg-emerald-950/40 dark:text-emerald-200'"
             class="fixed bottom-24 left-1/2 z-[70] w-max max-w-[calc(100vw-2rem)] -translate-x-1/2 rounded-xl border px-4 py-3 text-sm font-medium shadow-lg sm:bottom-6"
             x-text="flash"></div>

        <section class="mt-4 rounded-3xl border border-gray-200 bg-white p-3 shadow-sm dark:border-gray-700 dark:bg-gray-800 sm:mt-6 sm:p-6">
            {{-- Barre : la portee a gauche (une seule ligne, defilante au doigt
                 plutot qu'un retour a la ligne maladroit), la recherche et la
                 forme a droite. --}}
            <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                <div class="-mx-1 flex snap-x gap-1.5 overflow-x-auto px-1 [-ms-overflow-style:none] [scrollbar-width:none] [&::-webkit-scrollbar]:hidden"
                     role="group" aria-label="{{ __('dossiers.documents_title') }}">
                    @foreach([
                        'miens' => __('dossiers.filter_mine'),
                        'partages' => __('dossiers.filter_shared'),
                        'boucles' => __('dossiers.filter_loops'),
                    ] as $cle => $libelle)
                        <button type="button" @click="portee = '{{ $cle }}'" :aria-pressed="portee === '{{ $cle }}'"
                                class="inline-flex min-h-11 shrink-0 snap-start items-center whitespace-nowrap rounded-full border px-4 text-sm font-medium transition"
                                :class="portee === '{{ $cle }}' ? 'border-indigo-200 bg-indigo-50 text-indigo-700 dark:border-indigo-800 dark:bg-indigo-950/50 dark:text-indigo-300' : 'border-transparent text-gray-500 hover:text-gray-800 dark:text-gray-400 dark:hover:text-gray-200'">
                            {{ $libelle }}
                        </button>
                    @endforeach
                </div>

                <div class="flex items-center gap-3">
                    <div class="relative min-w-0 flex-1 lg:w-72 lg:flex-none">
                        <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
                            <svg class="h-4 w-4 text-gray-400 dark:text-gray-500" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z"/></svg>
                        </div>
                        <input x-model="recherche" type="search" :placeholder="placeholderRecherche"
                               class="block h-11 w-full rounded-xl border border-gray-300 bg-white pl-10 pr-3 text-sm text-gray-900 placeholder-gray-500 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 dark:placeholder-gray-400">
                    </div>

                    {{-- Meme toggle, meme cle localStorage que dans un Dossier. --}}
                    <div class="flex shrink-0 gap-0.5 rounded-lg border border-gray-200 p-0.5 dark:border-gray-700">
                        <button type="button" @click="setViewMode('list')" :aria-pressed="viewMode === 'list'"
                                class="flex h-10 w-10 items-center justify-center rounded-md transition"
                                :class="viewMode === 'list' ? 'bg-indigo-50 text-indigo-600 dark:bg-indigo-950/50 dark:text-indigo-300' : 'text-gray-400 hover:text-gray-600 dark:hover:text-gray-200'"
                                aria-label="{{ __('dossiers.file_view_list') }}" title="{{ __('dossiers.file_view_list') }}">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5M3.75 17.25h16.5"/></svg>
                        </button>
                        <button type="button" @click="setViewMode('grid')" :aria-pressed="viewMode === 'grid'"
                                class="flex h-10 w-10 items-center justify-center rounded-md transition"
                                :class="viewMode === 'grid' ? 'bg-indigo-50 text-indigo-600 dark:bg-indigo-950/50 dark:text-indigo-300' : 'text-gray-400 hover:text-gray-600 dark:hover:text-gray-200'"
                                aria-label="{{ __('dossiers.file_view_grid') }}" title="{{ __('dossiers.file_view_grid') }}">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6a2.25 2.25 0 0 1 2.25-2.25h.75A2.25 2.25 0 0 1 9 6v.75a2.25 2.25 0 0 1-2.25 2.25H6A2.25 2.25 0 0 1 3.75 6.75V6Zm0 11.25A2.25 2.25 0 0 1 6 15h.75a2.25 2.25 0 0 1 2.25 2.25V18a2.25 2.25 0 0 1-2.25 2.25H6A2.25 2.25 0 0 1 3.75 18v-.75ZM13.5 6a2.25 2.25 0 0 1 2.25-2.25h.75A2.25 2.25 0 0 1 18.75 6v.75a2.25 2.25 0 0 1-2.25 2.25h-.75a2.25 2.25 0 0 1-2.25-2.25V6Zm0 11.25A2.25 2.25 0 0 1 15.75 15h.75a2.25 2.25 0 0 1 2.25 2.25V18a2.25 2.25 0 0 1-2.25 2.25h-.75A2.25 2.25 0 0 1 13.5 18v-.75Z"/></svg>
                        </button>
                    </div>
                </div>
            </div>

            {{-- ── Liste ── --}}
            <div class="mt-4" x-show="viewMode === 'list'">
                {{-- L'en-tete de colonnes n'a de sens qu'au-dessus de lignes :
                     il disparait avec elles. --}}
                <div class="hidden lg:block" x-show="aResultats" x-cloak>
                    <div class="{{ $grille }} border-b border-gray-200 px-4 pb-2 text-[11px] font-semibold uppercase tracking-wide text-gray-400 dark:border-gray-700 dark:text-gray-500">
                        <div>{{ __('dossiers.col_name') }}</div>
                        <div>{{ __('dossiers.col_type') }}</div>
                        <div>{{ __('dossiers.col_share') }}</div>
                        <div class="hidden xl:block">{{ __('dossiers.col_owner') }}</div>
                        <div class="hidden xl:block">{{ __('dossiers.col_created') }}</div>
                        <div>{{ __('dossiers.col_modified') }}</div>
                        <div>{{ __('dossiers.col_size') }}</div>
                        <div><span class="sr-only">{{ __('dossiers.col_name') }}</span></div>
                    </div>
                </div>

                <ul class="divide-y divide-gray-100 dark:divide-gray-700/60">
                    @foreach($lignes as $ligne)
                        @php
                            $d = $ligne['dossier'];
                            $url = route('organization.dossiers.show', ['organization' => $organizationRouteParam, 'dossier' => $d->getKey()]);
                            $nbElements = ($d->files_count ?? 0) + ($d->dossier_blog_posts_count ?? 0) + ($d->children_count ?? 0);
                            $nomMinuscule = mb_strtolower($ligne['nom']);
                        @endphp
                        <li class="{{ $grille }} rounded-lg px-4 py-2.5 transition {{ $ligne['boucle'] ? 'hover:bg-indigo-50/40 dark:hover:bg-indigo-500/5' : 'hover:bg-amber-50/40 dark:hover:bg-amber-500/5' }}"
                            x-show="portee === '{{ $ligne['portee'] }}' && (!recherche || {{ \Illuminate\Support\Js::from($nomMinuscule) }}.includes(recherche.toLowerCase()))">
                            {{-- 1. Nom --}}
                            <div class="flex min-w-0 items-center gap-3">
                                <span class="relative flex h-8 w-8 shrink-0 items-center justify-center rounded-lg {{ $ligne['boucle'] ? 'bg-indigo-100 text-indigo-600 dark:bg-indigo-500/20 dark:text-indigo-300' : 'bg-amber-100 text-amber-600 dark:bg-amber-500/20 dark:text-amber-300' }}" aria-hidden="true">
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12.75V12A2.25 2.25 0 0 1 4.5 9.75h15A2.25 2.25 0 0 1 21.75 12v.75m-8.69-6.44-2.12-2.12a1.5 1.5 0 0 0-1.061-.44H4.5A2.25 2.25 0 0 0 2.25 6v12a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18V9a2.25 2.25 0 0 0-2.25-2.25h-5.379a1.5 1.5 0 0 1-1.06-.44Z"/></svg>
                                    @if($ligne['partagePar'])
                                        <span class="absolute -bottom-0.5 -right-0.5 flex h-3.5 w-3.5 items-center justify-center rounded-full bg-white ring-1 ring-amber-300 dark:bg-gray-800 dark:ring-amber-500/50">
                                            <svg class="h-2 w-2 text-amber-600 dark:text-amber-300" fill="currentColor" viewBox="0 0 20 20"><path d="M10 8a3 3 0 1 0 0-6 3 3 0 0 0 0 6ZM3.465 14.493a1.23 1.23 0 0 0 .41 1.412A9.957 9.957 0 0 0 10 18c2.31 0 4.438-.784 6.131-2.1.43-.333.604-.903.408-1.41a7.002 7.002 0 0 0-13.074.003Z"/></svg>
                                        </span>
                                    @endif
                                </span>
                                <div class="min-w-0 flex-1">
                                    @if($ligne['renommable'])
                                        <div x-show="editingId !== '{{ $d->getKey() }}'">
                                            <a href="{{ $url }}" class="block truncate text-sm font-medium text-gray-900 hover:text-indigo-600 dark:text-gray-100 dark:hover:text-indigo-300" x-text="getName('{{ $d->getKey() }}')"></a>
                                        </div>
                                        <div x-show="editingId === '{{ $d->getKey() }}'" x-cloak>
                                            <input type="text" x-model="editingName"
                                                   @keydown.enter="saveRename('{{ $d->getKey() }}')"
                                                   @keydown.escape="cancelRename()"
                                                   class="h-9 w-full max-w-sm rounded-lg border border-indigo-300 px-2 text-sm font-medium text-gray-900 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 dark:border-indigo-600 dark:bg-gray-900 dark:text-gray-100"
                                                   placeholder="{{ __('dossiers.rename_placeholder') }}">
                                        </div>
                                    @else
                                        <a href="{{ $url }}" class="block truncate text-sm font-medium text-gray-900 hover:text-indigo-600 dark:text-gray-100 dark:hover:text-indigo-300">{{ $ligne['nom'] }}</a>
                                    @endif
                                    {{-- Resume compact : ce que les colonnes diront a partir de `lg`. --}}
                                    <span class="block truncate text-xs text-gray-500 lg:hidden dark:text-gray-400">
                                        {{ __('dossiers.drive_type_folder') }} · {{ $ligne['partage'] }} · {{ $d->updated_at?->isoFormat('L') }}@if($ligne['partagePar']) · {{ __('dossiers.drive_shared_by', ['name' => $ligne['partagePar']]) }}@endif
                                    </span>
                                </div>
                            </div>
                            {{-- 2. Type --}}
                            <div class="{{ $celluleLg }}">{{ __('dossiers.drive_type_folder') }}</div>
                            {{-- 3. Partage --}}
                            <div class="{{ $celluleLg }}">
                                <span class="inline-flex items-center rounded-full px-2 py-0.5 {{ $ligne['boucle'] ? 'bg-indigo-50 text-indigo-700 dark:bg-indigo-950/60 dark:text-indigo-200' : ($ligne['partage'] === __('dossiers.share_private') ? 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300' : 'bg-amber-50 text-amber-700 dark:bg-amber-950/60 dark:text-amber-200') }}">{{ $ligne['partage'] }}</span>
                            </div>
                            {{-- 4. Proprietaire --}}
                            <div class="{{ $celluleXl }}">{{ $ligne['proprietaire'] }}</div>
                            {{-- 5. Cree le --}}
                            <div class="{{ $celluleXl }} tabular-nums">{{ $d->created_at?->isoFormat('L') }}</div>
                            {{-- 6. Modifie le --}}
                            <div class="{{ $celluleLg }} tabular-nums">{{ $d->updated_at?->isoFormat('L') }}</div>
                            {{-- 7. Taille / elements --}}
                            <div class="{{ $celluleLg }} tabular-nums">{{ trans_choice('dossiers.drive_folder_items', $nbElements, ['count' => $nbElements]) }}</div>
                            {{-- 8. Actions --}}
                            <div class="relative justify-self-end" x-data="{ open: false }" x-on:keydown.escape.window="open = false">
                                <button type="button" @click="open = !open" x-bind:aria-expanded="open"
                                        class="flex h-11 w-11 items-center justify-center rounded-full text-gray-400 transition hover:bg-gray-100 hover:text-gray-700 dark:hover:bg-gray-700 dark:hover:text-gray-200"
                                        aria-label="{{ $ligne['nom'] }}">
                                    <svg class="h-4 w-4" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 8.25a1.5 1.5 0 1 1 0-3 1.5 1.5 0 0 1 0 3Zm0 5.25a1.5 1.5 0 1 1 0-3 1.5 1.5 0 0 1 0 3Zm0 5.25a1.5 1.5 0 1 1 0-3 1.5 1.5 0 0 1 0 3Z"/></svg>
                                </button>
                                <div x-show="open" x-cloak @click.outside="open = false"
                                     class="absolute right-0 top-full z-30 mt-1 w-44 rounded-xl border border-gray-200 bg-white p-1 text-left shadow-lg dark:border-gray-700 dark:bg-gray-800">
                                    <a href="{{ $url }}" class="block rounded-lg px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-700/60">{{ __('dossiers.drive_open') }}</a>
                                    @if($ligne['renommable'])
                                        <button type="button" @click="open = false; startRename('{{ $d->getKey() }}')"
                                                class="block w-full rounded-lg px-3 py-2 text-left text-sm font-medium text-gray-700 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-700/60">{{ __('dossiers.rename') }}</button>
                                    @endif
                                    @if($ligne['supprimable'])
                                        <button type="button" @click="open = false; openDeleteModal('{{ $d->getKey() }}', @js($d->name))"
                                                class="block w-full rounded-lg px-3 py-2 text-left text-sm font-medium text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-900/20">{{ __('dossiers.delete') }}</button>
                                    @endif
                                </div>
                            </div>
                        </li>
                    @endforeach
                </ul>
            </div>

            {{-- ── Grille ── --}}
            <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5" x-show="viewMode === 'grid'" x-cloak>
                @foreach($lignes as $ligne)
                    @php
                        $d = $ligne['dossier'];
                        $url = route('organization.dossiers.show', ['organization' => $organizationRouteParam, 'dossier' => $d->getKey()]);
                        $nbElements = ($d->files_count ?? 0) + ($d->dossier_blog_posts_count ?? 0) + ($d->children_count ?? 0);
                        $nomMinuscule = mb_strtolower($ligne['nom']);
                    @endphp
                    <div class="group relative flex flex-col items-center rounded-xl border border-gray-200 bg-white p-4 text-center transition hover:shadow-sm dark:border-gray-700 dark:bg-gray-800 {{ $ligne['boucle'] ? 'hover:border-indigo-300' : 'hover:border-amber-300' }}"
                         x-show="portee === '{{ $ligne['portee'] }}' && (!recherche || {{ \Illuminate\Support\Js::from($nomMinuscule) }}.includes(recherche.toLowerCase()))">
                        <a href="{{ $url }}" class="flex w-full flex-col items-center gap-2">
                            <span class="relative flex h-12 w-12 shrink-0 items-center justify-center rounded-xl {{ $ligne['boucle'] ? 'bg-indigo-100 text-indigo-600 dark:bg-indigo-500/20 dark:text-indigo-300' : 'bg-amber-100 text-amber-600 dark:bg-amber-500/20 dark:text-amber-300' }}" aria-hidden="true">
                                <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12.75V12A2.25 2.25 0 0 1 4.5 9.75h15A2.25 2.25 0 0 1 21.75 12v.75m-8.69-6.44-2.12-2.12a1.5 1.5 0 0 0-1.061-.44H4.5A2.25 2.25 0 0 0 2.25 6v12a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18V9a2.25 2.25 0 0 0-2.25-2.25h-5.379a1.5 1.5 0 0 1-1.06-.44Z"/></svg>
                                @if($ligne['partagePar'])
                                    <span class="absolute -bottom-1 -right-1 flex h-4 w-4 items-center justify-center rounded-full bg-white ring-1 ring-amber-300 dark:bg-gray-800 dark:ring-amber-500/50">
                                        <svg class="h-2.5 w-2.5 text-amber-600 dark:text-amber-300" fill="currentColor" viewBox="0 0 20 20"><path d="M10 8a3 3 0 1 0 0-6 3 3 0 0 0 0 6ZM3.465 14.493a1.23 1.23 0 0 0 .41 1.412A9.957 9.957 0 0 0 10 18c2.31 0 4.438-.784 6.131-2.1.43-.333.604-.903.408-1.41a7.002 7.002 0 0 0-13.074.003Z"/></svg>
                                    </span>
                                @endif
                            </span>
                            <span class="line-clamp-2 w-full text-sm font-medium text-gray-900 dark:text-gray-100" @if($ligne['renommable']) x-text="getName('{{ $d->getKey() }}')" @endif>{{ $ligne['nom'] }}</span>
                            <span class="w-full truncate text-xs text-gray-500 dark:text-gray-400">{{ $ligne['partage'] }} · {{ trans_choice('dossiers.drive_folder_items', $nbElements, ['count' => $nbElements]) }}</span>
                        </a>
                        <div class="absolute right-1 top-1" x-data="{ open: false }" x-on:keydown.escape.window="open = false">
                            <button type="button" @click="open = !open" x-bind:aria-expanded="open"
                                    class="flex h-11 w-11 items-center justify-center rounded-full text-gray-400 transition hover:bg-gray-100 hover:text-gray-700 focus:opacity-100 group-hover:opacity-100 dark:hover:bg-gray-700 dark:hover:text-gray-200 max-sm:opacity-100 sm:h-8 sm:w-8 sm:opacity-0"
                                    :class="open && 'opacity-100'"
                                    aria-label="{{ $ligne['nom'] }}">
                                <svg class="h-4 w-4" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 8.25a1.5 1.5 0 1 1 0-3 1.5 1.5 0 0 1 0 3Zm0 5.25a1.5 1.5 0 1 1 0-3 1.5 1.5 0 0 1 0 3Zm0 5.25a1.5 1.5 0 1 1 0-3 1.5 1.5 0 0 1 0 3Z"/></svg>
                            </button>
                            <div x-show="open" x-cloak @click.outside="open = false"
                                 class="absolute right-0 top-full z-30 mt-1 w-44 rounded-xl border border-gray-200 bg-white p-1 text-left shadow-lg dark:border-gray-700 dark:bg-gray-800">
                                <a href="{{ $url }}" class="block rounded-lg px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-700/60">{{ __('dossiers.drive_open') }}</a>
                                @if($ligne['renommable'])
                                    <button type="button" @click="open = false; setViewMode('list'); startRename('{{ $d->getKey() }}')"
                                            class="block w-full rounded-lg px-3 py-2 text-left text-sm font-medium text-gray-700 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-700/60">{{ __('dossiers.rename') }}</button>
                                @endif
                                @if($ligne['supprimable'])
                                    <button type="button" @click="open = false; openDeleteModal('{{ $d->getKey() }}', @js($d->name))"
                                            class="block w-full rounded-lg px-3 py-2 text-left text-sm font-medium text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-900/20">{{ __('dossiers.delete') }}</button>
                                @endif
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>

            {{-- Etats vides : par portee, et pour une recherche sans resultat. --}}
            @foreach([
                'miens' => [$dossiers, __('dossiers.index_empty_mine'), __('dossiers.index_empty_mine_help')],
                'partages' => [$sharedDossiers, __('dossiers.index_empty_shared'), null],
                'boucles' => [$loopDossiers, __('dossiers.index_empty_loops'), null],
            ] as $cle => [$collection, $titre, $aide])
                @if($collection->isEmpty())
                    <div x-show="portee === '{{ $cle }}'" x-cloak class="mt-4 rounded-xl border border-dashed border-gray-300 px-5 py-10 text-center dark:border-gray-700">
                        <p class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ $titre }}</p>
                        @if($aide)
                            <p class="mx-auto mt-1 max-w-md text-sm text-gray-500 dark:text-gray-400">{{ $aide }}</p>
                        @endif
                    </div>
                @endif
            @endforeach
            <div x-show="recherche.trim() && !aResultats" x-cloak class="mt-4 rounded-xl border border-dashed border-gray-300 px-5 py-8 text-center dark:border-gray-700">
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('dossiers.search_no_results') }}</p>
            </div>
        </section>

        {{-- Le FAB global est neutralise sur ce module : aucune garde d'espace
             n'est plus necessaire ici. --}}
    </x-page-container>

    {{-- Delete confirmation modal --}}
    <div x-show="showDeleteModal" x-cloak x-transition.opacity class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
        <div @click.away="showDeleteModal = false" x-transition class="w-full max-w-md rounded-2xl bg-white p-6 shadow-xl dark:bg-gray-800">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ __('dossiers.dossier_confirm_delete_title') }}</h3>
            <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">{{ __('dossiers.dossier_confirm_delete_body') }}</p>
            <p class="mt-1 text-sm font-medium text-gray-900 dark:text-gray-100" x-text="deleteTargetName"></p>
            <div class="mt-6 flex justify-end gap-3">
                <button @click="showDeleteModal = false" type="button" class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 transition hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">
                    {{ __('dossiers.dossier_confirm_delete_cancel') }}
                </button>
                <button @click="confirmDelete()" :disabled="saving" type="button" class="rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-red-700 disabled:opacity-50">
                    {{ __('dossiers.dossier_confirm_delete_confirm') }}
                </button>
            </div>
        </div>
    </div>
    </div>

    <script>
    function documentsIndex(owned, csrfToken, orgParam, toutesLignes) {
        return {
            owned,
            toutesLignes,
            // « Mes documents » est la racine par defaut : on n'arrive jamais
            // sur un catalogue de racines melangees.
            portee: 'miens',
            recherche: '',
            viewMode: 'list',
            editingId: null,
            editingName: '',
            showDeleteModal: false,
            deleteTargetId: null,
            deleteTargetName: '',
            saving: false,
            flash: '',
            flashType: 'success',

            init() {
                // La MEME preference que dans le Drive : une seule cle pour
                // toute la surface Documents.
                try {
                    const stored = window.localStorage.getItem('bp-dossier-view-mode');
                    if (stored === 'list' || stored === 'grid') this.viewMode = stored;
                } catch (e) { /* navigation privee, quota : reste sur 'list' */ }

                document.addEventListener('keydown', (ev) => {
                    if (ev.key === 'Escape') {
                        if (this.showDeleteModal) this.showDeleteModal = false;
                        else if (this.editingId) this.cancelRename();
                    }
                });
            },

            get placeholderRecherche() {
                if (this.portee === 'partages') return @js(__('dossiers.search_in_shared'));
                if (this.portee === 'boucles') return @js(__('dossiers.search_in_loops'));
                return @js(__('dossiers.search_in_my_documents'));
            },

            // Reste-t-il une ligne visible dans la portee courante ? Meme
            // regle que le `x-show` de chaque ligne, appliquee aux memes
            // donnees — jamais une lecture du DOM.
            get aResultats() {
                const q = this.recherche.trim().toLowerCase();
                return this.toutesLignes.some(l => l.portee === this.portee && (!q || l.nom.includes(q)));
            },

            setViewMode(mode) {
                this.viewMode = mode;
                try { window.localStorage.setItem('bp-dossier-view-mode', mode); } catch (e) { /* meme garde */ }
            },

            getName(id) {
                const item = this.owned.find(d => d.id === id);
                return item ? item.name : '';
            },

            startRename(id) {
                this.editingId = id;
                this.editingName = this.getName(id);
                this.setViewMode('list');
                this.$nextTick(() => {
                    const input = this.$el.querySelector(`input[x-model="editingName"]`);
                    if (input) input.focus();
                });
            },

            cancelRename() {
                this.editingId = null;
                this.editingName = '';
            },

            saveRename(id) {
                if (!this.editingName.trim()) return;
                this.saving = true;
                fetch(`/org/${orgParam}/dossiers/${id}`, {
                    method: 'PATCH',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify({ name: this.editingName.trim() }),
                })
                .then(r => r.json().then(d => ({ ok: r.ok, data: d })))
                .then(({ ok, data }) => {
                    if (!ok) {
                        this.showFlash(data.message || data.name?.[0] || 'Error', 'error');
                        return;
                    }
                    const item = this.owned.find(d => d.id === id);
                    if (item) item.name = this.editingName.trim();
                    this.cancelRename();
                    this.showFlash(data.message || @js(__('dossiers.updated')));
                })
                .catch(() => this.showFlash(@js(__('dossiers.network_error')), 'error'))
                .finally(() => { this.saving = false; });
            },

            openDeleteModal(id, name) {
                this.deleteTargetId = id;
                this.deleteTargetName = name;
                this.showDeleteModal = true;
            },

            confirmDelete() {
                this.saving = true;
                fetch(`/org/${orgParam}/dossiers/${this.deleteTargetId}`, {
                    method: 'DELETE',
                    headers: {
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                })
                .then(r => r.json().then(d => ({ ok: r.ok, data: d })))
                .then(({ ok, data }) => {
                    this.showDeleteModal = false;
                    if (!ok) {
                        // 422 « dossier non vide » compris : le message serveur
                        // est deja actionnable (suppression sure, etape A).
                        this.showFlash(data.message || 'Error', 'error');
                        return;
                    }
                    // Les lignes sont rendues cote serveur : recharger est le
                    // geste honnete, comme dans le Drive.
                    window.location.reload();
                })
                .catch(() => {
                    this.showDeleteModal = false;
                    this.showFlash(@js(__('dossiers.network_error')), 'error');
                })
                .finally(() => { this.saving = false; });
            },

            showFlash(message, type = 'success') {
                this.flash = message;
                this.flashType = type;
                setTimeout(() => { this.flash = ''; }, 4000);
            },
        };
    }
    </script>
</x-app-layout>
