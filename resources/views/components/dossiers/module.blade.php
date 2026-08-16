@props(['espace' => 'documents'])

@php
    $orgParam = request()->route('organization');

    // Les trois espaces du module. « Mes documents » est la racine reelle ;
    // « Partages » et « Boucles » sont des vues d'agregation, jamais des
    // Dossiers physiques (TASK-1130, decision finale).
    $espaces = [
        [
            'cle' => 'documents',
            'label' => __('dossiers.space_my_documents'),
            'url' => route('organization.dossiers.index', ['organization' => $orgParam]),
            'icone' => 'M3.5 7.5v11a1.5 1.5 0 0 0 1.5 1.5h14a1.5 1.5 0 0 0 1.5-1.5v-9A1.5 1.5 0 0 0 19 8h-8L9 5.5H5a1.5 1.5 0 0 0-1.5 1.5Z',
        ],
        [
            'cle' => 'partages',
            'label' => __('dossiers.space_shared'),
            'url' => route('organization.dossiers.index', ['organization' => $orgParam, 'espace' => 'partages']),
            'icone' => 'M10 8a3.4 3.4 0 1 1-6.8 0 3.4 3.4 0 0 1 6.8 0ZM1 20c.8-3.4 3.2-5 6-5s5.2 1.6 6 5M18.5 5v6M15.5 8h6',
        ],
        [
            'cle' => 'boucles',
            'label' => __('dossiers.space_loops'),
            'url' => route('organization.dossiers.index', ['organization' => $orgParam, 'espace' => 'boucles']),
            // La MEME icone que « Boucles » dans le rail global : deux dessins
            // pour une seule destination faisaient douter qu'il s'agisse du
            // meme endroit.
            'icone' => 'M8 10h8M8 14h5m8-2a9 9 0 11-18 0 9 9 0 0118 0z',
        ],
    ];
@endphp

<div class="flex w-full items-start">
    {{-- La sidebar du module (TASK-1130, reference canonique drive-v2) : le
         contexte documentaire, a cote du rail global qu'elle ne repete jamais.
         Pas de second logo, pas de reprise de la navigation produit.

         Emplacements futurs, hors scope : « Recents » et « Favoris » sous
         Boucles ; « Corbeille » et la jauge de stockage en bas de colonne. --}}
    {{-- `--bp-page`, pas `--bp-surface` : en clair les deux valent la meme
         couleur, donc la colonne se fondait deja dans la page ; en sombre
         `surface` est plus clair que `page` et la colonne se detachait en bloc.
         Le fond suit desormais la page dans les deux themes (TASK-1146). --}}
    <aside class="hidden w-[198px] shrink-0 self-stretch border-r border-[var(--bp-border)] bg-[var(--bp-page)] px-2.5 py-4 md:block"
           aria-label="{{ __('dossiers.module_navigation') }}">
        {{-- `z-40` : `position: sticky` cree un contexte d'empilement, et sans
             cote explicite la surface — placee apres dans le DOM — recouvrait
             le menu deroulant de « + Nouveau ». --}}
        <div class="sticky top-4 z-40">
            {{ $nouveau ?? '' }}

            <nav class="mt-3 flex flex-col gap-0.5">
                @foreach($espaces as $item)
                    <a href="{{ $item['url'] }}"
                       @if($espace === $item['cle']) aria-current="page" @endif
                       class="flex min-h-11 items-center gap-3 whitespace-nowrap rounded-lg px-3 text-sm transition {{ $espace === $item['cle']
                            ? 'bg-indigo-50 font-semibold text-indigo-700 dark:bg-indigo-950/50 dark:text-indigo-200'
                            : 'text-[var(--bp-text)] hover:bg-[var(--bp-panel)]' }}">
                        <svg class="h-[18px] w-[18px] shrink-0 {{ $espace === $item['cle'] ? 'text-indigo-600 dark:text-indigo-300' : 'text-[var(--bp-muted)]' }}"
                             fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="{{ $item['icone'] }}" />
                        </svg>
                        <span>{{ $item['label'] }}</span>
                    </a>
                @endforeach
            </nav>
        </div>
    </aside>

    <div class="min-w-0 flex-1">
        {{-- Mobile : la sidebar devient une navigation secondaire d'UNE ligne,
             scrollable horizontalement, jamais en wrap. Le bas de l'ecran
             appartient a la navigation globale BouclePro — le module n'y ajoute
             aucune seconde barre. --}}
        <nav class="-mx-4 flex gap-2 overflow-x-auto border-b border-[var(--bp-border)] px-4 pb-2 [scrollbar-width:none] md:hidden [&::-webkit-scrollbar]:hidden"
             aria-label="{{ __('dossiers.module_navigation') }}">
            @foreach($espaces as $item)
                <a href="{{ $item['url'] }}"
                   @if($espace === $item['cle']) aria-current="page" @endif
                   class="inline-flex min-h-11 shrink-0 items-center gap-2 whitespace-nowrap rounded-full px-4 text-sm transition {{ $espace === $item['cle']
                        ? 'bg-indigo-50 font-semibold text-indigo-700 dark:bg-indigo-950/50 dark:text-indigo-200'
                        : 'text-[var(--bp-muted)] hover:bg-[var(--bp-panel)]' }}">
                    <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="{{ $item['icone'] }}" />
                    </svg>
                    <span>{{ $item['label'] }}</span>
                </a>
            @endforeach
        </nav>

        <div class="min-w-0 md:pl-5">
            {{ $slot }}
        </div>
    </div>
</div>

