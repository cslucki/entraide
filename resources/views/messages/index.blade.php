@push('head')
<style>
    @media (max-width: 767px) {
        .messages-index-wrapper {
            padding: 0 !important;
        }

        /* TASK-1703 — POURQUOI LE COMPOSEUR BOUGEAIT, et laissait une bande
           vide sous lui.

           Cyril, capture a l'appui : « il faut que le composer qui est en bas
           soit sticky, car si on fait un geste vers le haut, le composer se
           deplace ».

           C'est MOT POUR MOT le defaut que TASK-1684 MICRO-01 avait nomme et
           corrige sur l'espace d'une Boucle : « `100dvh` est la hauteur
           DYNAMIQUE : elle suit l'apparition et le retrait de la barre d'URL
           du navigateur. Sur Chrome Android, le simple geste de defilement
           vers le haut retracte cette barre, le viewport grandit, le conteneur
           grandit avec lui — et le composeur, ancre en bas de ce conteneur, se
           DEPLACE sous le doigt. Rien n'etait mal ancre : c'est l'unite de
           hauteur qui bougeait. »

           La Messagerie etait restee en `dvh`. Memes deux changements, meme
           principe — que plus rien ne bouge :

             1. le document ne defile plus du tout sur cette page. Sans
                defilement de document, Chrome n'a aucune raison de retracter
                sa barre : le viewport reste constant. C'est aussi ce qui
                supprime le « defilement parasite » qui decouvrait la bande
                grise de la capture ;
             2. la hauteur passe a `100svh`, la plus petite des hauteurs
                possibles, celle qui ne varie JAMAIS. Le repli `100dvh` precede
                la regle pour les navigateurs qui ignorent `svh`.

           `--bp-vvh`, si le script l'a posee, prend le dessus : c'est la seule
           mesure juste quand le clavier virtuel est ouvert.

           La soustraction, elle, ne change pas : `3.5rem` est la barre haute
           de l'application, qui RESTE sur cette page — a la difference d'une
           Boucle, qui la masque. */
        html:has(.messages-index-container),
        body:has(.messages-index-container) {
            height: 100%;
            overflow: hidden;
            overscroll-behavior: none;
        }

        .messages-index-container {
            /* TASK-1693 bug AD — `4rem` etait la hauteur de la barre d'outils,
               recopiee en dur. Le bug AC l'ayant retiree des conversations,
               cette page continuait de la soustraire : d'ou une bande vide
               sous le composeur, qui ne tenait donc plus le bas de l'ecran.
               `--bp-bottom-nav` suit la meme decision que la barre. */
            height: calc(100dvh - 3.5rem - env(safe-area-inset-top, 0px) - var(--bp-bottom-nav, 0px));
            height: calc(100svh - 3.5rem - env(safe-area-inset-top, 0px) - var(--bp-bottom-nav, 0px));
            height: calc(var(--bp-vvh, 100svh) - 3.5rem - env(safe-area-inset-top, 0px) - var(--bp-bottom-nav, 0px));
        }
    }

    @media (min-width: 768px) {
        /* TASK-1703 — la fenetre prend toute la largeur ET toute la hauteur,
           comme l'espace de travail d'une Boucle.

           ## La hauteur

           `calc(100vh - 5rem)` soustrayait la barre d'en-tete de page. Or cet
           ecran ne pose AUCUN `$header` — pas plus que `loops.show`, dont le
           commentaire dit deja : « no top app header on this page, so the
           workspace fills the full viewport height — otherwise ~5rem stays
           empty ». La messagerie avait garde la soustraction : cinq rem de
           vide sous le composeur, et une fenetre d'autant plus courte.

           ## La largeur

           `x-page-container` centre le contenu dans `max-w-7xl` avec ses
           marges laterales. Sur un grand ecran, les deux colonnes se
           retrouvaient dans une colonne etroite tandis que l'espace d'une
           Boucle occupe tout. Memes declarations que `.loops-show-wrapper`,
           au mot pres : c'est le meme besoin, il n'y a pas de raison d'en
           inventer une seconde expression. */
        .messages-index-wrapper {
            max-width: none !important;
            padding-top: 0 !important;
            padding-bottom: 0 !important;
            padding-left: 0 !important;
            padding-right: 0 !important;

            /* La hauteur est portee par le WRAPPER, pas par la fenetre.
               Donner `100dvh` a la fenetre alors qu'une barre haute la
               precede ferait depasser la page de la hauteur de cette barre :
               un ascenseur, et le composeur pousse sous la ligne de
               flottaison. C'est le montage de `loops.show` — conteneur en
               colonne a hauteur d'ecran, barre `flex-shrink-0`, espace de
               travail `flex: 1 1 auto; min-height: 0`. */
            height: 100dvh;
            display: flex;
            flex-direction: column;
        }

        .messages-index-topbar {
            flex-shrink: 0;
        }

        .messages-index-container {
            flex: 1 1 auto;
            min-height: 0;
            height: auto;
            border-radius: 0 !important;
            border-left: 0 !important;
            border-right: 0 !important;
            border-top: 0 !important;
        }
    }
</style>
@endpush

<x-app-layout :title="__('messages.title')">
    @php
        $_messagesOrgSlug = request()->route('organization');
        $_messagesRoute = function (string $routeName, string $organizationRouteName, array $params = []) use ($_messagesOrgSlug) {
            return $_messagesOrgSlug && Route::has($organizationRouteName)
                ? route($organizationRouteName, array_merge(['organization' => $_messagesOrgSlug], $params))
                : route($routeName, $params);
        };
    @endphp
    <x-page-container class="messages-index-wrapper">
        {{-- TASK-1703 — la barre haute, sur DESKTOP uniquement.

             Cyril : « une top navigation bar comme sur la version mobile ».
             Sur telephone, `x-mobile-topbar` en pose une — titre de la page,
             marque, menu. Sur desktop il n'y avait RIEN au-dessus des deux
             colonnes : la page commencait a ras bord, et c'est ce qui la
             faisait paraitre plus pauvre que l'espace d'une Boucle.

             Elle est calquee sur la barre de `loops.show` : meme filet, meme
             rythme vertical, meme jetons de theme. Pas une invention — la
             reference que Cyril nomme.

             `hidden md:flex` : sur telephone elle ferait DOUBLON avec la barre
             de l'application, qui est deja la. --}}
        <div class="messages-index-topbar hidden md:flex flex-nowrap items-center gap-3 border-b border-[var(--bp-border)] bg-[var(--bp-panel)] px-4 py-2.5"
             data-messages-topbar>
            <span class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-xl border border-[var(--bp-border)] text-[var(--bp-muted)]" aria-hidden="true">
                {{-- `navigation_icons.messaging` est un CHEMIN `d=`, pas un
                     `<svg>` : meme enveloppe que le rail, au trait pres. --}}
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="{{ config('navigation_icons.messaging') }}" />
                </svg>
            </span>
            <div class="min-w-0 flex-1">
                <h1 class="truncate text-base font-semibold text-[var(--bp-text)] sm:text-lg">{{ __('messages.title') }}</h1>
                <p class="truncate text-xs text-[var(--bp-muted)]">{{ trans_choice('messages.conversation_count', $transactions->count(), ['count' => $transactions->count()]) }}</p>
            </div>
        </div>

        @php
            // TASK-1755 — l'URL d'une pastille, dans la surface courante. Un
            // echange ouvert est conserve dans l'URL : changer de filtre ne doit
            // pas fermer la conversation qu'on lit.
            $_filtreUrl = function (string $f) use ($_messagesRoute) {
                $params = $f === 'tous' ? [] : ['filtre' => $f];
                if (isset($transaction)) {
                    return $_messagesRoute('messages.show', 'organization.messages.show', array_merge(['transaction' => $transaction], $params));
                }

                return $_messagesRoute('messages.index', 'organization.messages.index', $params);
            };
        @endphp
        <div class="messages-index-container flex flex-col md:flex-row border-0 md:border border-gray-200 dark:border-gray-700 md:rounded-xl overflow-hidden bg-white dark:bg-gray-800">

            {{-- Mobile: conversation list when no thread selected --}}
            <div class="md:hidden flex-1 flex flex-col min-h-0 @isset($transaction) hidden @endisset">
                <x-conversation.filtres :actif="$filtre ?? null" :url="$_filtreUrl" />
                <div class="flex-1 overflow-y-auto">
                    @forelse($transactions as $conv)
                        {{-- TASK-1755 — UN composant, deux volets. Ce balisage
                             existait en DEUX exemplaires byte pour byte ; toute
                             correction devait etre faite deux fois. C'est aussi
                             le terrain du regroupement par personne (Q4) : une
                             liste groupee rendra CE composant sous un en-tete. --}}
                        <x-conversation.list-line
                            :conv="$conv"
                            :href="$_messagesRoute('messages.show', 'organization.messages.show', ['transaction' => $conv])"
                            :actif="isset($transaction) && $transaction->id === $conv->id"
                            :nonLus="$unreadCounts[$conv->id] ?? 0" />
                    @empty
                        <div class="p-8 text-center text-gray-400 text-sm">{{ __('messages.no_conversations') }}</div>
                    @endforelse
                </div>
            </div>

            {{-- Mobile: thread view --}}
                {{-- TASK-1816 — le panneau de conversation MOBILE a ete RETIRE.
                     Il dupliquait, a quelques classes pres, celui des lignes
                     suivantes : meme en-tete, meme lien de profil, meme badge,
                     et surtout le MEME composant Livewire de fil.

                     Les deux etaient rendus par le serveur — le CSS n'en cachait
                     qu'un. Mesure du 13/11, pour UNE conversation ouverte :

                     Avant T1816 : 20 requêtes HTTP/minute regroupant deux
                     composants, environ 560 requêtes SQL/minute. Après T1816 :
                     20 requêtes HTTP/minute avec un seul composant, environ 280
                     requêtes SQL/minute estimées.

                     Le nombre d'appels ne change donc PAS : Livewire groupe les
                     composants d'un meme cycle dans une seule requete. Ce qui
                     est divise par deux, c'est la CHARGE de chaque appel.

                     Le panneau qui reste porte desormais les deux presentations,
                     par classes responsives. Aucun deplacement JavaScript, aucune
                     duplication masquee. --}}

            <x-conversation.image-lightbox key="messages" />

            {{-- Desktop: conversation list sidebar --}}
            <div class="hidden md:flex w-80 flex-shrink-0 flex-col border-r border-gray-200 dark:border-gray-700">
                <div class="pt-4 border-b border-gray-200 dark:border-gray-700">
                    <h2 class="px-4 pb-3 font-semibold text-gray-900 dark:text-gray-100">{{ __('messages.title') }}</h2>
                    <x-conversation.filtres :actif="$filtre ?? null" :url="$_filtreUrl" />
                </div>
                <div class="flex-1 overflow-y-auto">
                    @forelse($transactions as $conv)
                        {{-- TASK-1755 — UN composant, deux volets. Ce balisage
                             existait en DEUX exemplaires byte pour byte ; toute
                             correction devait etre faite deux fois. C'est aussi
                             le terrain du regroupement par personne (Q4) : une
                             liste groupee rendra CE composant sous un en-tete. --}}
                        <x-conversation.list-line
                            :conv="$conv"
                            :href="$_messagesRoute('messages.show', 'organization.messages.show', ['transaction' => $conv])"
                            :actif="isset($transaction) && $transaction->id === $conv->id"
                            :nonLus="$unreadCounts[$conv->id] ?? 0" />
                    @empty
                        <div class="p-8 text-center text-gray-400 text-sm">{{ __('messages.no_conversations') }}</div>
                    @endforelse
                </div>
            </div>

            {{-- Desktop: message thread panel --}}
            {{-- TASK-1816 — le panneau UNIQUE, pour les deux presentations.
                 Sur telephone il ne parait que si une conversation est ouverte :
                 sinon c'est la LISTE qui occupe l'ecran (meme bascule qu'avant,
                 portee par `@isset` des deux cotes). A partir de `md` il est
                 toujours la, aux cotes de la barre laterale. --}}
            <div class="@isset($transaction) flex @else hidden @endisset md:flex flex-1 flex-col min-h-0">
                @isset($transaction)
                    @php
                        $other = auth()->id() === $transaction->buyer_id ? $transaction->seller : $transaction->buyer;
                        $otherDisplayable = $other?->isDisplayableIn(currentOrganization()) ?? false;
                    @endphp
                    <div class="px-3 py-2.5 md:px-4 md:py-3 border-b border-gray-200 dark:border-gray-700 flex items-center gap-3">
                        @php
                            $_profilUrl = $otherDisplayable
                                ? $_messagesRoute('profile.show', 'organization.profile.show', ['user' => $other->profileRouteKey()])
                                : null;
                        @endphp
                        @if($otherDisplayable)
                            <a href="{{ $_profilUrl }}" data-thread-profile-avatar="{{ $other->id }}" class="flex-shrink-0 hover:opacity-80 transition">
                                <img src="{{ $other->avatar_url }}" class="w-8 h-8 rounded-full" alt="">
                            </a>
                        @endif
                        {{-- TASK-1816 — `min-w-0 flex-1` + `truncate` viennent de
                             l'ancien panneau mobile, ou un nom long devait se
                             couper. Sur grand ecran ils ne se voient pas : la
                             troncature ne s'arme qu'au debordement, et le badge
                             `ml-auto flex-shrink-0` garde sa place. C'est meme
                             plus sur qu'avant, ou un nom tres long poussait le
                             badge hors de la ligne.
                             `text-sm md:text-base` rend a chaque presentation la
                             taille qu'elle avait. --}}
                        <div class="min-w-0 flex-1">
                            @if($otherDisplayable)
                            <a href="{{ $_profilUrl }}" data-thread-profile-link="{{ $other->id }}"
                               class="block font-medium text-sm md:text-base text-gray-900 dark:text-gray-100 truncate hover:text-indigo-600 dark:hover:text-indigo-400 transition">{{ $other->publicDisplayName() }}</a>
                            @else
                            <p class="font-medium text-sm md:text-base text-gray-900 dark:text-gray-100 truncate">{{ __('profile.deactivated_user') }}</p>
                            @endif
                            <p class="text-xs text-gray-500 truncate">
                                @if($transaction->service)
                                <a href="{{ $_messagesRoute('services.show', 'organization.services.show', ['service' => $transaction->service]) }}" class="hover:underline text-indigo-500">{{ $transaction->service->title }}</a>
                                @elseif($transaction->serviceRequest)
                                <a href="{{ $_messagesRoute('requests.show', 'organization.requests.show', ['request' => $transaction->serviceRequest]) }}" class="hover:underline text-indigo-500">{{ $transaction->serviceRequest->title }}</a>
                                @endif
                            </p>
                        </div>
                        {{-- TASK-1693 bug AC — le badge rejoint la ligne du
                             pseudo, a droite. Isole sur sa propre ligne il
                             coutait une bande entiere d'un ecran de
                             telephone, pour un mot. --}}
                        @if($transaction->isDirectConversation())
                        <span data-conversation-badge
                              class="ml-auto flex-shrink-0 inline-flex items-center rounded-full bg-indigo-100 px-2.5 py-0.5 text-xs font-medium text-indigo-800 dark:bg-indigo-900 dark:text-indigo-200">
                            {{ __('messages.direct_conversation') }}
                        </span>
                        @endif
                    </div>
                    <div class="flex-1 min-h-0 flex flex-col">
                        @livewire('message-thread', ['transaction' => $transaction])
                    </div>
                @else
                    <div class="flex-1 flex items-center justify-center text-gray-400 dark:text-gray-500">
                        <div class="text-center">
                            <svg class="w-12 h-12 mx-auto mb-3 opacity-30" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
                            </svg>
                            <p>{{ __('messages.select_conversation') }}</p>
                        </div>
                    </div>
                @endisset
            </div>

        </div>
    </x-page-container>

    {{-- TASK-1703 — la hauteur du viewport VISUEL, seule mesure juste quand le
         clavier virtuel est ouvert. Meme composant que l'espace d'une Boucle. --}}
    @once
    <x-visual-viewport-height selector=".messages-index-container" />
    @endonce
</x-app-layout>
