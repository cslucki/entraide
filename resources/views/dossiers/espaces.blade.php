<x-app-layout>
    @php
        $orgParam = $organizationRouteParam;
        $titre = $espace === 'partages' ? __('dossiers.space_shared') : __('dossiers.space_loops');

        // Memes colonnes calmes que le Drive, contextuelles a la vue
        // (reference canonique drive-v2) : jamais de colonne « Type », l'icone
        // la porte deja.
        $grille = 'grid grid-cols-[minmax(0,1fr)_2.75rem] items-center gap-x-3 lg:grid-cols-[minmax(0,2.4fr)_9rem_7rem_7rem_2.75rem]';
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
                    <nav class="mt-3 flex gap-2" aria-label="{{ __('dossiers.space_shared') }}">
                        @foreach([['avec-moi', __('dossiers.shared_with_me')], ['par-moi', __('dossiers.shared_by_me')]] as [$cle, $label])
                            <a href="{{ route('organization.dossiers.index', ['organization' => $orgParam, 'espace' => 'partages', 'vue' => $cle]) }}"
                               @if($vue === $cle) aria-current="page" @endif
                               class="inline-flex min-h-11 items-center rounded-full border px-4 text-sm transition {{ $vue === $cle
                                    ? 'border-indigo-200 bg-indigo-50 font-semibold text-indigo-700 dark:border-indigo-800 dark:bg-indigo-950/50 dark:text-indigo-200'
                                    : 'border-transparent text-[var(--bp-muted)] hover:bg-[var(--bp-panel)]' }}">{{ $label }}</a>
                        @endforeach
                    </nav>

                    @php $lignes = $vue === 'avec-moi' ? $avecMoi : $parMoi; @endphp

                    <div class="mt-4">
                        @if($lignes->isEmpty())
                            <p class="py-14 text-center text-sm text-[var(--bp-muted)]">
                                {{ $vue === 'avec-moi' ? __('dossiers.shared_with_me_empty') : __('dossiers.shared_by_me_empty') }}
                            </p>
                        @else
                            <div class="{{ $grille }} border-b border-[var(--bp-border)] px-2.5 pb-2 text-xs text-[var(--bp-muted)]">
                                <span>{{ __('dossiers.col_name') }}</span>
                                <span class="{{ $cellule }}">{{ $vue === 'avec-moi' ? __('dossiers.col_owner') : __('dossiers.col_shared_with') }}</span>
                                <span class="{{ $cellule }}">{{ $vue === 'avec-moi' ? __('dossiers.col_role') : '' }}</span>
                                <span class="{{ $cellule }}">{{ __('dossiers.col_modified') }}</span>
                                <span></span>
                            </div>

                            @foreach($lignes as $ligne)
                                @php
                                    $role = $vue === 'avec-moi'
                                        ? ($ligne->dossierMembers->first()?->role === 'editor' ? __('dossiers.role_editor') : __('dossiers.role_reader'))
                                        : null;
                                    $proprio = $ligne->owner?->isDisplayableIn(currentOrganization())
                                        ? $ligne->owner->publicDisplayName()
                                        : __('profile.deactivated_user');
                                    $partageAvec = $ligne->shared_with_loop_id
                                        ? ($ligne->sharedWithLoop?->name ?? __('dossiers.share_loop'))
                                        : trans_choice('dossiers.shared_with_people', $ligne->dossier_members_count, ['count' => $ligne->dossier_members_count]);
                                    $contenu = $ligne->files_count + $ligne->dossier_blog_posts_count + $ligne->children_count;
                                @endphp
                                <a href="{{ route('organization.dossiers.show', ['organization' => $orgParam, 'dossier' => $ligne->getKey()]) }}"
                                   x-show="q === '' || @js(mb_strtolower($ligne->name)).includes(q.toLowerCase())"
                                   class="{{ $grille }} min-h-[3.25rem] border-b border-[var(--bp-border)]/60 px-2.5 transition hover:bg-[var(--bp-panel)]">
                                    <span class="flex min-w-0 items-center gap-3">
                                        <svg class="h-5 w-5 shrink-0 text-amber-500" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                            <path d="M3.5 7.5v11a1.5 1.5 0 0 0 1.5 1.5h14a1.5 1.5 0 0 0 1.5-1.5v-9A1.5 1.5 0 0 0 19 8h-8L9 5.5H5a1.5 1.5 0 0 0-1.5 1.5Z" />
                                        </svg>
                                        <span class="min-w-0">
                                            <span class="block truncate text-sm font-medium text-[var(--bp-text)]">{{ $ligne->name }}</span>
                                            <span class="block truncate text-xs text-[var(--bp-muted)]">
                                                <span class="lg:hidden">{{ $vue === 'avec-moi' ? $proprio : $partageAvec }} · </span>{{ trans_choice('dossiers.drive_folder_items', $contenu, ['count' => $contenu]) }} · {{ $ligne->updated_at?->translatedFormat('j M') }}
                                            </span>
                                        </span>
                                    </span>
                                    <span class="{{ $cellule }}">{{ $vue === 'avec-moi' ? $proprio : $partageAvec }}</span>
                                    <span class="{{ $cellule }}">{{ $role ?? '' }}</span>
                                    <span class="{{ $cellule }}">{{ $ligne->updated_at?->translatedFormat('j M Y') }}</span>
                                    <span class="flex justify-end">
                                        <svg class="h-4 w-4 text-[var(--bp-muted)]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                            <path d="m9 6 6 6-6 6" />
                                        </svg>
                                    </span>
                                </a>
                            @endforeach
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
                            <p class="py-14 text-center text-sm text-[var(--bp-muted)]">{{ __('dossiers.loops_empty') }}</p>
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
