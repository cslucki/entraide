<x-app-layout>
    @php
        $orgParam = $organizationRouteParam;
        $titre = $espace === 'partages' ? __('dossiers.space_shared') : __('dossiers.space_loops');

        // Memes colonnes calmes que le Drive, contextuelles a la vue
        // (reference canonique drive-v2) : jamais de colonne « Type », l'icone
        // la porte deja.
        $grille = 'grid grid-cols-[minmax(0,1fr)_4.5rem] items-center gap-x-3 lg:grid-cols-[minmax(0,2.2fr)_13rem_6rem_7rem_6rem]';
        $cellule = 'hidden lg:block min-w-0 truncate text-xs text-gray-500 dark:text-gray-400';
    @endphp

    <x-slot name="title">{{ $titre }} — {{ __('navigation.my_dossiers') }} — {{ $brandOrganizationName ?? 'BouclePro' }}</x-slot>

    <x-page-container>
        <x-dossiers.module :espace="$espace">
            <x-slot name="nouveau">
                {{-- Depuis une vue d'agregation on ne cree pas « dans
                     Partages » : la destination est annoncee, puis on y va.
                     C'est ce que fait Drive depuis « Partages avec moi ». --}}
                <a href="{{ route('organization.dossiers.index', ['organization' => $orgParam]) }}"
                   class="flex min-h-11 w-full items-center justify-center gap-2 rounded-xl border border-[var(--bp-border)] bg-[var(--bp-panel)] px-4 text-sm font-semibold text-[var(--bp-text)] shadow-sm transition hover:bg-[var(--bp-surface)]">
                    <svg class="h-[18px] w-[18px] text-indigo-600 dark:text-indigo-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" aria-hidden="true">
                        <path d="M12 5v14M5 12h14" />
                    </svg>
                    {{ __('dossiers.new_button') }}
                </a>
                <p class="mt-1.5 px-1 text-[11px] leading-tight text-[var(--bp-muted)]">{{ __('dossiers.new_in_my_documents') }}</p>
            </x-slot>

            @if(session('success'))
                <div class="mt-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800 dark:border-emerald-900/60 dark:bg-emerald-950/40 dark:text-emerald-200">
                    {{ session('success') }}
                </div>
            @endif

            <div x-data="{ q: '' }">
                {{-- La ligne d'outils : ou l'on est, puis chercher. Le titre est
                     la position, pas un H1 decoratif au-dessus d'un autre. --}}
                <div class="flex flex-wrap items-center gap-3 pt-4">
                    <h1 class="min-w-0 flex-1 truncate text-lg font-semibold text-[var(--bp-text)] sm:text-xl">{{ $titre }}</h1>

                    <label class="flex min-h-11 w-full items-center gap-2 rounded-full border border-[var(--bp-border)] bg-[var(--bp-panel)] px-4 sm:w-72">
                        <svg class="h-4 w-4 shrink-0 text-[var(--bp-muted)]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" aria-hidden="true">
                            <circle cx="11" cy="11" r="7" /><path d="m20 20-3.8-3.8" />
                        </svg>
                        <span class="sr-only">{{ __('dossiers.search') }}</span>
                        <input type="search" x-model="q"
                               placeholder="{{ $espace === 'partages' ? __('dossiers.search_in_shared') : __('dossiers.search_in_loops') }}"
                               class="w-full border-0 bg-transparent p-0 text-sm text-[var(--bp-text)] placeholder:text-[var(--bp-muted)] focus:ring-0">
                    </label>
                </div>

                @if($espace === 'partages')
                    {{-- Avec moi / Par moi : deux sous-vues de la MEME surface,
                         jamais deux ecrans (OneDrive les nomme mot pour mot). --}}
                    {{-- « Partages avec moi » et « Partages par moi » se
                         ressemblent trop, en FR comme en EN : deux libelles
                         longs qui ne different que par un mot au milieu. La
                         fleche porte le sens avant la lecture — entrant vers
                         moi, sortant depuis moi. Meme jeu d'icones inline que
                         le reste du module, aucune librairie ajoutee. --}}
                    <nav class="mt-3 flex gap-2" aria-label="{{ __('dossiers.space_shared') }}">
                        @foreach([
                            ['avec-moi', __('dossiers.shared_with_me'), 'M12 3.75v12m0 0 4.5-4.5M12 15.75l-4.5-4.5M4.5 19.5h15'],
                            ['par-moi', __('dossiers.shared_by_me'), 'M12 20.25V8.25m0 0 4.5 4.5M12 8.25l-4.5 4.5M4.5 4.5h15'],
                        ] as [$cle, $label, $trace])
                            <a href="{{ route('organization.dossiers.index', ['organization' => $orgParam, 'espace' => 'partages', 'vue' => $cle]) }}"
                               @if($vue === $cle) aria-current="page" @endif
                               class="inline-flex min-h-11 items-center gap-2 rounded-full border px-4 text-sm transition {{ $vue === $cle
                                    ? 'border-indigo-200 bg-indigo-50 font-semibold text-indigo-700 dark:border-indigo-800 dark:bg-indigo-950/50 dark:text-indigo-200'
                                    : 'border-transparent text-[var(--bp-muted)] hover:bg-[var(--bp-panel)]' }}">
                                <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ $trace }}"/></svg>
                                {{ $label }}
                            </a>
                        @endforeach
                    </nav>

                    @php
                        $lignes = $vue === 'avec-moi' ? $avecMoi : $parMoi;
                        // TASK-1695 geste E — les fichiers partages a l'unite.
                        // Une liste A PART : les lignes ci-dessus sont des
                        // Dossiers, et le rendu leur demande
                        // `governingDossier()` et `dossierMembers`.
                        $fichiers = $vue === 'avec-moi' ? $fichiersAvecMoi : $fichiersParMoi;
                    @endphp

                    <div class="mt-4">
                        {{-- Le vide se juge sur les DEUX listes : un espace qui
                             ne contient que des fichiers partages annoncait
                             « aucun dossier partage » au-dessus d'eux. --}}
                        @if($lignes->isEmpty() && $fichiers->isEmpty())
                            <p class="py-14 text-center text-sm text-[var(--bp-muted)]">
                                {{ $vue === 'avec-moi' ? __('dossiers.shared_with_me_empty') : __('dossiers.shared_by_me_empty') }}
                            </p>
                        @else
                            @if($lignes->isNotEmpty())
                            <div class="{{ $grille }} border-b border-[var(--bp-border)] px-2.5 pb-2 text-xs text-[var(--bp-muted)]">
                                <span>{{ __('dossiers.col_name') }}</span>
                                <span class="{{ $cellule }}">{{ $vue === 'avec-moi' ? __('dossiers.col_owner') : __('dossiers.col_shared_with') }}</span>
                                <span class="{{ $cellule }}">{{ $vue === 'avec-moi' ? __('dossiers.col_role') : '' }}</span>
                                <span class="{{ $cellule }}">{{ __('dossiers.col_modified') }}</span>
                                <span></span>
                            </div>

                            @foreach($lignes as $ligne)
                                @php
                                    $proprietaireReel = $ligne->governingDossier()->owner;
                                    $role = $vue === 'avec-moi'
                                        ? ($ligne->dossierMembers->first()?->role === 'editor' ? __('dossiers.role_editor') : __('dossiers.role_reader'))
                                        : null;
                                    $proprio = $proprietaireReel?->isDisplayableIn(currentOrganization())
                                        ? $proprietaireReel->publicDisplayName()
                                        : __('profile.deactivated_user');
                                    $partageAvec = $ligne->shared_with_loop_id
                                        ? ($ligne->sharedWithLoop?->name ?? __('dossiers.share_loop'))
                                        : trans_choice('dossiers.shared_with_people', $ligne->dossier_members_count, ['count' => $ligne->dossier_members_count]);
                                    $contenu = $ligne->files_count + $ligne->dossier_blog_posts_count + $ligne->children_count;
                                @endphp
                                <div x-show="q === '' || @js(mb_strtolower($ligne->name)).includes(q.toLowerCase())"
                                     class="{{ $grille }} min-h-[3.25rem] border-b border-[var(--bp-border)]/60 px-2.5 transition hover:bg-[var(--bp-panel)]">
                                    <a href="{{ route('organization.dossiers.show', ['organization' => $orgParam, 'dossier' => $ligne->getKey()]) }}"
                                       class="flex min-h-11 min-w-0 items-center gap-3">
                                        {{-- Le meme marqueur que dans « Mes documents » :
                                             un dossier partage ne doit pas changer
                                             d'apparence selon l'ecran ou on le
                                             rencontre. Toutes les lignes de cet
                                             espace sont partagees — l'icone le dit,
                                             au lieu de le laisser deviner par la
                                             seule presence dans la liste. --}}
                                        <span class="relative flex h-5 w-5 shrink-0 items-center justify-center" aria-hidden="true">
                                            <svg class="h-5 w-5 text-amber-500" fill="currentColor" viewBox="0 0 24 24">
                                                <path d="M3.5 7.5v11a1.5 1.5 0 0 0 1.5 1.5h14a1.5 1.5 0 0 0 1.5-1.5v-9A1.5 1.5 0 0 0 19 8h-8L9 5.5H5a1.5 1.5 0 0 0-1.5 1.5Z" />
                                            </svg>
                                            <span class="absolute -bottom-1 -right-1 flex h-3.5 w-3.5 items-center justify-center rounded-full bg-[var(--bp-surface)] ring-1 ring-indigo-300 dark:ring-indigo-500/50"
                                                  title="{{ __('dossiers.share_shared_badge') }}">
                                                <svg class="h-2 w-2 text-indigo-600 dark:text-indigo-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><circle cx="9" cy="8" r="3"/><circle cx="16.5" cy="15.5" r="3"/><path d="M4.5 19c.6-2.6 2.4-4 4.5-4"/></svg>
                                            </span>
                                        </span>
                                        <span class="sr-only">{{ __('dossiers.share_shared_badge') }}</span>
                                        <span class="min-w-0">
                                            <span class="block truncate text-sm font-medium text-[var(--bp-text)]">{{ $ligne->name }}</span>
                                            <span class="block truncate text-xs text-[var(--bp-muted)]">
                                                <span class="lg:hidden">{{ $vue === 'avec-moi' ? $proprio : $partageAvec }} · </span>{{ trans_choice('dossiers.drive_folder_items', $contenu, ['count' => $contenu]) }} · {{ $ligne->updated_at?->translatedFormat('j M') }}
                                            </span>
                                        </span>
                                    </a>

                                    {{-- Avec qui : des visages, pas un compte.
                                         Trois au plus, puis le reste en nombre —
                                         la colonne dit « qui », elle ne devient
                                         pas une liste. --}}
                                    <span class="{{ $cellule }} !flex items-center gap-1.5">
                                        @if($vue === 'avec-moi')
                                            <x-user-avatar :user="$proprietaireReel" size="xs" />
                                            <span class="min-w-0 truncate">{{ $proprio }}</span>
                                        @elseif($ligne->shared_with_loop_id)
                                            <svg class="h-4 w-4 shrink-0 text-indigo-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><circle cx="9" cy="8" r="3.2"/><circle cx="16.5" cy="15.5" r="3.2"/><path d="M4.5 19c.6-2.6 2.4-4 4.5-4M19.5 8.5C19 6 17.2 4.8 15.4 4.8"/></svg>
                                            <span class="min-w-0 truncate">{{ $ligne->sharedWithLoop?->name ?? __('dossiers.share_loop') }}</span>
                                        @elseif($ligne->dossierMembers->isNotEmpty())
                                            <span class="flex shrink-0 -space-x-1.5">
                                                @foreach($ligne->dossierMembers->take(3) as $membre)
                                                    <x-user-avatar :user="$membre->user" size="xs" class="ring-2 ring-[var(--bp-surface)]" />
                                                @endforeach
                                            </span>
                                            <span class="min-w-0 truncate">
                                                {{ $ligne->dossierMembers->take(2)->map(fn ($m) => $m->user?->isDisplayableIn(currentOrganization()) ? $m->user->publicDisplayName() : __('profile.deactivated_user'))->implode(', ') }}@if($ligne->dossier_members_count > 2) +{{ $ligne->dossier_members_count - 2 }}@endif
                                            </span>
                                        @else
                                            <span class="truncate">{{ __('dossiers.shared_with_nobody') }}</span>
                                        @endif
                                    </span>
                                    <span class="{{ $cellule }}">{{ $role ?? '' }}</span>
                                    <span class="{{ $cellule }}">{{ $ligne->updated_at?->translatedFormat('j M Y') }}</span>
                                    <span class="flex justify-end">
                                        @if($vue === 'par-moi')
                                            {{-- Du constat au geste sans detour : le
                                                 panneau de partage s'ouvre a
                                                 l'arrivee sur le dossier. --}}
                                            <a href="{{ route('organization.dossiers.show', ['organization' => $orgParam, 'dossier' => $ligne->getKey(), 'partage' => 1]) }}"
                                               class="inline-flex min-h-11 items-center gap-1.5 rounded-lg px-2.5 text-xs font-semibold text-indigo-600 transition hover:bg-[var(--bp-panel)] dark:text-indigo-400"
                                               title="{{ __('dossiers.share_manage') }}">
                                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.9" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M7.217 10.907a2.25 2.25 0 1 0 0 2.186m0-2.186c.18.324.283.696.283 1.093s-.103.77-.283 1.093m0-2.186 9.566-5.314m-9.566 7.5 9.566 5.314m0 0a2.25 2.25 0 1 0 3.935 2.186 2.25 2.25 0 0 0-3.935-2.186Zm0-12.814a2.25 2.25 0 1 0 3.933-2.185 2.25 2.25 0 0 0-3.933 2.185Z"/></svg>
                                                <span class="max-lg:sr-only">{{ __('dossiers.share_manage_short') }}</span>
                                            </a>
                                        @else
                                            <svg class="h-4 w-4 text-[var(--bp-muted)]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                                <path d="m9 6 6 6-6 6" />
                                            </svg>
                                        @endif
                                    </span>
                                </div>
                            @endforeach
                            @endif

                            {{-- TASK-1695 geste E — les fichiers partages a l'unite.

                                 Section distincte, et non des lignes melees aux
                                 Dossiers : un fichier partage seul N'EST PAS un
                                 dossier, et le presenter comme tel ferait croire
                                 a un acces qu'on n'a pas. L'encart le dit en
                                 toutes lettres, parce que personne ne devinerait
                                 une borne aussi etroite.

                                 Les liens visent les routes DEDIEES du geste C
                                 (`dossiers.shared.*`), jamais le chemin du
                                 Dossier : c'est toute leur raison d'etre — le
                                 destinataire n'a aucun droit sur le Dossier
                                 porteur, et ce chemin lui rendrait 403. --}}
                            @if($fichiers->isNotEmpty())
                                {{-- TASK-1695 (points 2 et 3 de Cyril) — un vrai TABLEAU.

                                     Le titre « Fichiers partages » et la mention
                                     « Un fichier partage s'ouvre seul… » ont ete
                                     RETIRES : l'espace s'appelle deja « Partages »
                                     et l'onglet dit « Par moi » / « Avec moi » —
                                     un troisieme titre ne repetait que cela. La
                                     mention, elle, etait jugee trop technique
                                     (meme arbitrage que dans le modal).

                                     Le bouton de telechargement est retire aussi :
                                     cliquer le NOM ouvre deja le fichier. Deux
                                     chemins pour le meme geste encombraient une
                                     colonne qui sert desormais a arreter le
                                     partage.

                                     Les en-tetes trient par LIEN (parametre d'URL)
                                     et non en JS : cela se teste, se partage, et
                                     survit au rechargement. --}}
                                @php
                                    $colonnes = $vue === 'par-moi'
                                        ? ['nom' => 'col_file_name', 'type' => 'col_file_type', 'taille' => 'col_file_size', 'qui' => 'col_shared_with']
                                        : ['nom' => 'col_file_name', 'type' => 'col_file_type', 'taille' => 'col_file_size', 'qui' => 'col_shared_by', 'date' => 'col_shared_at'];
                                    // Le nombre de colonnes SUIT la vue : « Avec
                                    // moi » porte une date de partage que « Par
                                    // moi » n'affiche pas. Une grille unique
                                    // aurait laisse une colonne vide d'un cote.
                                    $grilleF = $vue === 'par-moi'
                                        ? 'grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-3 lg:grid-cols-[minmax(0,2.2fr)_5rem_7rem_minmax(0,12rem)_auto]'
                                        : 'grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-3 lg:grid-cols-[minmax(0,2.2fr)_5rem_7rem_minmax(0,12rem)_8rem_auto]';
                                    $celluleF = 'hidden min-w-0 truncate text-xs text-[var(--bp-muted)] lg:block';
                                    $lienTri = function (string $cle) use ($fichiersTri, $fichiersSens) {
                                        return request()->fullUrlWithQuery([
                                            'ftri' => $cle,
                                            'fsens' => ($fichiersTri === $cle && $fichiersSens === 'asc') ? 'desc' : 'asc',
                                        ]);
                                    };
                                @endphp

                                <div class="mt-8 {{ $grilleF }} border-b border-[var(--bp-border)] px-2.5 pb-2 text-xs font-semibold uppercase tracking-wide text-[var(--bp-muted)]">
                                    @foreach($colonnes as $cle => $libelle)
                                        <a href="{{ $lienTri($cle) }}"
                                           class="{{ $loop->first ? 'flex' : 'hidden lg:flex' }} min-w-0 items-center gap-1 hover:text-[var(--bp-text)]"
                                           data-shared-sort="{{ $cle }}"
                                           @if($fichiersTri === $cle) aria-sort="{{ $fichiersSens === 'asc' ? 'ascending' : 'descending' }}" @endif>
                                            <span class="truncate">{{ __('dossiers.'.$libelle) }}</span>
                                            @if($fichiersTri === $cle)
                                                <svg class="h-3 w-3 shrink-0 {{ $fichiersSens === 'desc' ? 'rotate-180' : '' }}" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 15.75 7.5-7.5 7.5 7.5"/></svg>
                                            @endif
                                        </a>
                                    @endforeach
                                    <span></span>
                                </div>

                                <div>
                                    @foreach($fichiers as $fichier)
                                        @php
                                            $partage = $fichier->shares->first();
                                            $auteur = $partage?->sharedBy;
                                            $destinataire = $partage?->user;
                                            $autre = $vue === 'par-moi' ? $destinataire : $auteur;
                                            $qui = $autre?->isDisplayableIn(currentOrganization())
                                                ? $autre->publicDisplayName()
                                                : __('profile.deactivated_user');
                                        @endphp
                                        <div x-show="q === '' || @js(mb_strtolower($fichier->nomLisible())).includes(q.toLowerCase())"
                                             class="{{ $grilleF }} min-h-[3.25rem] border-b border-[var(--bp-border)]/60 px-2.5 transition hover:bg-[var(--bp-panel)]"
                                             data-shared-file-row="{{ $fichier->id }}">
                                            {{-- Le NOM ouvre le fichier : c'est le seul
                                                 chemin, et il vise la route dediee du
                                                 geste C — jamais le Dossier porteur, sur
                                                 lequel le destinataire n'a aucun droit. --}}
                                            <a href="{{ route('organization.dossiers.shared.preview', ['organization' => $orgParam, 'file' => $fichier->getKey()]) }}"
                                               class="flex min-h-11 min-w-0 items-center gap-3" data-shared-file-link>
                                                <svg class="h-5 w-5 shrink-0 text-indigo-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5A3.375 3.375 0 0 0 10.125 2.25H8.25m6.75 12H9m1.5-12H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z"/></svg>
                                                <span class="min-w-0">
                                                    <span class="block truncate text-sm font-medium text-[var(--bp-text)]">{{ $fichier->nomLisible() }}</span>
                                                    <span class="block truncate text-xs text-[var(--bp-muted)] lg:hidden">{{ $fichier->typeLisible() }} · {{ $fichier->tailleLisible() }} · {{ $qui }}</span>
                                                </span>
                                            </a>
                                            <span class="{{ $celluleF }}">{{ $fichier->typeLisible() }}</span>
                                            <span class="{{ $celluleF }}">{{ $fichier->tailleLisible() }}</span>
                                            <span class="hidden min-w-0 items-center gap-1.5 text-xs text-[var(--bp-muted)] lg:flex">
                                                <x-user-avatar :user="$autre" size="xs" />
                                                <span class="min-w-0 truncate">{{ $qui }}@if($vue === 'par-moi' && ($fichier->shares_count ?? 1) > 1) +{{ $fichier->shares_count - 1 }}@endif</span>
                                            </span>
                                            @if($vue === 'avec-moi')
                                                <span class="{{ $celluleF }}">{{ $partage?->created_at?->translatedFormat('j M Y') }}</span>
                                            @endif
                                            <span class="flex justify-end">
                                                @if($vue === 'par-moi')
                                                    <form method="POST" action="{{ route('organization.dossiers.shared.stop', ['organization' => $orgParam, 'file' => $fichier->getKey()]) }}">
                                                        @csrf
                                                        @method('DELETE')
                                                        <button type="submit"
                                                                class="inline-flex min-h-11 items-center rounded-lg px-2.5 text-xs font-semibold text-red-600 transition hover:bg-[var(--bp-panel)] dark:text-red-400"
                                                                data-shared-file-stop>{{ __('dossiers.file_share_stop') }}</button>
                                                    </form>
                                                @endif
                                            </span>
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        @endif
                    </div>
                @else
                    {{-- Des Boucles, pas des lignes « Type = Dossier » : ce
                         qu'on lit, c'est la Boucle, mon role dedans et ce
                         qu'elle contient. Le clic principal ouvre son Drive ;
                         « Voir la Boucle » sort du module. Aucun menu `...`
                         puisqu'il ne contiendrait qu'« Ouvrir ». --}}
                    <div class="mt-4">
                        @if($loopDossiers->isEmpty())
                            {{-- Constater qu'on n'a pas de Boucle ne dit pas ou
                                 aller en trouver une : la sortie est offerte
                                 ici, pas laissee a chercher dans la
                                 navigation. --}}
                            <div class="py-14 text-center">
                                <p class="text-sm text-[var(--bp-muted)]">{{ __('dossiers.loops_empty') }}</p>
                                <a href="{{ route('organization.loops.index', ['organization' => $orgParam]) }}"
                                   class="mt-4 inline-flex min-h-11 items-center gap-2 rounded-xl bg-indigo-600 px-5 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-700">
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                        <path d="M8 10h8M8 14h5m8-2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                    </svg>
                                    {{ __('dossiers.loops_empty_cta') }}
                                </a>
                            </div>
                        @else
                            @foreach($loopDossiers as $dossierDeBoucle)
                                @php
                                    $boucle = $dossierDeBoucle->loop;
                                    $roleBrut = $boucle?->activeMembers->first()?->role;
                                    $roleLabel = $roleBrut
                                        ? __('dossiers.loop_role_'.app(\App\Support\Loops\LoopRoleRegistry::class)->canonical($roleBrut))
                                        : null;
                                    $contenu = $dossierDeBoucle->files_count + $dossierDeBoucle->dossier_blog_posts_count + $dossierDeBoucle->children_count;
                                @endphp
                                <div x-show="q === '' || @js(mb_strtolower($boucle?->name ?? '')).includes(q.toLowerCase())"
                                     class="flex flex-wrap items-center gap-x-4 gap-y-1 border-b border-[var(--bp-border)]/60 py-3 pl-2.5 pr-2">
                                    <a href="{{ route('organization.dossiers.show', ['organization' => $orgParam, 'dossier' => $dossierDeBoucle->getKey()]) }}"
                                       class="flex min-w-0 flex-1 items-center gap-3.5 rounded-lg transition hover:opacity-80">
                                        <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl border border-indigo-100 bg-indigo-50 text-sm font-bold text-indigo-700 dark:border-indigo-900 dark:bg-indigo-950/60 dark:text-indigo-200">
                                            {{ mb_strtoupper(mb_substr($boucle?->name ?? '?', 0, 2)) }}
                                        </span>
                                        <span class="min-w-0">
                                            <span class="flex flex-wrap items-center gap-2">
                                                <span class="truncate text-sm font-semibold text-[var(--bp-text)]">{{ $boucle?->name ?? __('dossiers.share_loop') }}</span>
                                                @if($roleLabel)
                                                    <span class="rounded-full bg-[var(--bp-panel)] px-2 py-0.5 text-[11px] font-semibold text-[var(--bp-muted)]">{{ $roleLabel }}</span>
                                                @endif
                                            </span>
                                            <span class="mt-0.5 block truncate text-xs text-[var(--bp-muted)]">
                                                {{ trans_choice('dossiers.drive_folder_items', $contenu, ['count' => $contenu]) }} · {{ __('dossiers.loop_activity', ['date' => $dossierDeBoucle->updated_at?->translatedFormat('j M Y')]) }}
                                            </span>
                                        </span>
                                    </a>

                                    @if($boucle)
                                        {{-- Sur mobile l'action secondaire prend sa
                                             propre ligne, alignee sous le nom : la
                                             mettre a cote ecrasait le nom de la
                                             Boucle et sa date d'activite. --}}
                                        <a href="{{ route('organization.loops.show', ['organization' => $orgParam, 'loop' => $boucle->slug ?? $boucle->getKey()]) }}"
                                           class="ml-[3.875rem] inline-flex min-h-11 w-full items-center gap-1.5 rounded-lg text-sm font-medium text-indigo-600 transition hover:underline dark:text-indigo-400 sm:ml-0 sm:w-auto">
                                            {{ __('dossiers.loop_visit') }}
                                            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                                <path d="M7 17 17 7M9 7h8v8" />
                                            </svg>
                                        </a>
                                    @endif
                                </div>
                            @endforeach
                        @endif
                    </div>
                @endif
            </div>
        </x-dossiers.module>
    </x-page-container>
</x-app-layout>
