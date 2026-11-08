@php
    $organizationRouteParam = request()->route('organization') ?: currentOrganization()?->slug;
    $usesDefaultOrganizationRoute = (bool) currentOrganization()?->is_default;
    $createUrl = $usesDefaultOrganizationRoute && Route::has('flux.create')
        ? route('flux.create')
        : route('organization.flux.create', ['organization' => $organizationRouteParam]);
    $myPostsUrl = $usesDefaultOrganizationRoute && Route::has('flux.my')
        ? route('flux.my')
        : route('organization.flux.my', ['organization' => $organizationRouteParam]);
@endphp

{{-- TASK-1788 correctif 09 (point 10) — le fil se rafraichit tout seul.

     Jusqu'ici, un commentaire ou une reaction de quelqu'un d'autre n'arrivait
     qu'au rechargement de la page. Le depot a deja un precedent, pose en
     T1786 sur les commentaires d'entraide : `wire:poll.visible`.

     `.visible` et pas un `wire:poll` nu : l'onglet en arriere-plan cesse
     d'interroger le serveur. Sur une PWA posee sur un telephone, c'est la
     difference entre un fil vivant et une batterie qui se vide.

     15 s, comme T1786 : meme cadence pour le meme geste, plutot qu'un second
     rythme a expliquer. Le cout d'un passage est celui d'un rendu du fil —
     les deux requetes de la page plus leurs chargements groupes.

     ⚠️ Ce n'est PAS du temps reel : il n'y a ni WebSocket ni Redis en
     production (infra mesuree le 05/11). Un sondage est ce que cette
     architecture permet aujourd'hui. --}}
<div class="mx-auto max-w-3xl px-4 py-6 sm:px-6 lg:px-8" wire:poll.visible.15s data-feed-poll>
    {{-- TASK-1788 correctif 09 — l'en-tete du Flux sur smartphone.

         Il etait `flex items-start justify-between` SANS point de rupture, et
         le bloc des deux boutons portait `shrink-0`. Sur un telephone, les deux
         pastilles — « Mes annonces » et « Nouvelle publication », texte plus
         icone — prenaient donc toute la largeur utile, et la colonne de titre
         etait ecrasee a ce qu'il restait : le titre se cassait mot par mot.

         Ici : on EMPILE sous `sm` (titre d'abord, boutons dessous sur toute la
         largeur), et on retrouve la rangee d'origine au-dela. Le `shrink-0` ne
         s'applique plus qu'a partir de `sm`, la ou il a un sens.

         `min-h-[44px]` sur les deux boutons : `py-2` donnait 36 px de haut,
         sous la cible tactile que le reste du produit respecte deja (voir
         l'etat vide des Boucles). Sur une PWA au doigt, 36 px se rate.

         `min-w-0` sur la colonne de titre : sans lui, un mot long d'un titre
         traduit deborderait de la carte au lieu de se couper. --}}
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        {{-- TASK-1788 correctif 09 — « Organisation » et le sous-titre sont
             RETIRES, sur demande de Cyril : trois lignes avant le premier
             contenu sur un ecran de telephone, pour dire ce que le titre dit
             deja. Les cles `feed.organization` et `feed.subtitle` restent
             definies — ce correctif est un changement d'ecran, pas un menage
             de lexique. --}}
        <div class="min-w-0">
            {{-- TASK-1788 (demande Cyril) — le lien « Mon tableau de bord » est
                 RETIRE, sur les deux largeurs.

                 Le retour au tableau de bord est deja offert par la navigation
                 elle-meme : le rail sur bureau, la barre du bas sur telephone.
                 Un lien de plus en tete de page reprenait une destination que
                 l'ecran porte en permanence, et coutait une ligne la ou l'on
                 vient lire un fil.

                 La cle `feed.dashboard` reste DEFINIE : c'est un changement
                 d'ecran, pas un menage de lexique. --}}
            <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">{{ __('feed.title') }}</h1>
        </div>
        @can('create', App\Models\FeedPost::class)
            <div class="flex w-full items-center gap-2 sm:w-auto sm:shrink-0" data-feed-actions>
                <a href="{{ $myPostsUrl }}"
                   class="inline-flex min-h-[44px] flex-1 items-center justify-center gap-1 rounded-full border border-indigo-600 px-4 py-2 text-sm font-semibold text-indigo-600 transition hover:bg-indigo-50 active:scale-95 sm:flex-none dark:border-indigo-400 dark:text-indigo-400 dark:hover:bg-indigo-950">
                    <svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                    {{ __('feed.my_posts') }}
                </a>
                <a href="{{ $createUrl }}"
                   class="inline-flex min-h-[44px] flex-1 items-center justify-center gap-1.5 rounded-full bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-700 active:scale-95 sm:flex-none">
                    <svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                    {{ __('feed.new_post') }}
                </a>
            </div>
        @endcan
    </div>

    @if($pinned->isNotEmpty())
        <div class="mb-6 space-y-4">
            <h2 class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('feed.pinned') }}</h2>
            @foreach($pinned as $post)
                @include('livewire.partials.feed-post-card', ['post' => $post, 'emojiMap' => $emojiMap])
            @endforeach
        </div>
    @endif

    <div class="space-y-4">
        @if($pinned->isEmpty() && $items->isEmpty())
            <div class="text-center py-12">
                <svg class="w-12 h-12 mx-auto text-gray-300 dark:text-gray-600 mb-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19 20H5a2 2 0 01-2-2V6a2 2 0 012-2h10a2 2 0 012 2v1m2 13a2 2 0 01-2-2V7m2 13a2 2 0 002-2V9a2 2 0 00-2-2h-2m-4-3H9M7 16h6M7 8h6v4H7V8z"/></svg>
                <p class="text-gray-500 dark:text-gray-400">{{ __('feed.empty') }}</p>
                @can('create', App\Models\FeedPost::class)
                    <a href="{{ $createUrl }}" class="mt-4 inline-flex items-center gap-1.5 text-indigo-600 dark:text-indigo-400 text-sm font-semibold hover:underline">
                        {{ __('feed.create_first') }}
                    </a>
                @endcan
            </div>
        @else
            @foreach($items as $post)
                @include('livewire.partials.feed-post-card', ['post' => $post, 'emojiMap' => $emojiMap])
            @endforeach

            @if($hasMore)
                <div class="text-center py-6">
                    {{-- TASK-1788 correctif 09 — cible tactile : `py-2.5` donnait
                         40 px, sous la barre des 44 px. C'est le bouton qu'on
                         presse le plus souvent sur un fil. --}}
                    <button wire:click="loadMore"
                            class="inline-flex min-h-[44px] items-center justify-center px-6 py-2.5 bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 text-sm font-medium rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition active:scale-95">
                        {{ __('feed.load_more') }}
                    </button>
                </div>
            @endif
        @endif
    </div>
</div>
