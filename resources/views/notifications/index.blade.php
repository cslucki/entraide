{{--
    TASK-1373 — le Centre de notifications.

    Une notification ne porte que des references (`object_type` + `object_id`) :
    aucun texte, aucune URL. Le libelle vient du catalogue et la destination est
    resolue AU CLIC, sous les permissions du moment — jamais lue dans la ligne.

    C'est ce qui fait qu'une notification vieille d'un mois n'ouvre aucune porte
    fermee depuis. Quand la cible n'est plus accessible, l'ecran le dit
    (`data-notification-unreachable`) plutot que de rediriger vers une page qui
    refuserait.

    TASK-1382 — ce bloc affirmait « cet ecran n'ouvre aucun objet », ce qui a
    cesse d'etre vrai des TASK-1374 : le bouton « Ouvrir » est juste en dessous
    depuis, et le resolveur de cible existe. Un commentaire qui contredit le
    code sous lui est pire qu'aucun commentaire — il oriente la relecture
    suivante dans la mauvaise direction.
--}}
@php
    $filtreActif = fn (string $f) => $filtre === $f;
    // TASK-1780 — le filtre est TOUJOURS transmis explicitement.
    //
    // Ce lien omettait le parametre pour « Toutes », et c'etait juste tant que
    // `NotificationCenterController::filtre()` rendait `FILTRE_TOUTES` en
    // l'absence de parametre. T1775 a inverse ce defaut vers
    // `FILTRE_NON_LUES` sans toucher a ce constructeur d'URL : cliquer sur
    // « Toutes » renvoyait donc vers « Non lues », et **l'historique devenait
    // inatteignable depuis l'interface**.
    //
    // La lecon est dans la forme du defaut, pas seulement dans sa correction :
    // un lien qui s'appuie sur le DEFAUT d'un controleur se casse en silence
    // le jour ou ce defaut change, et aucun test ne le voit si l'on se
    // contente de verifier que l'onglet est RENDU. Transmettre la valeur
    // rend le lien independant du defaut — y compris du prochain.
    $urlFiltre = fn (string $f) => request()->url().'?'.http_build_query(['filtre' => $f]);

    // Le libelle vient du catalogue, jamais de la ligne : le stockage ne
    // contient aucun texte. Les points de la cle deviennent des tirets bas.
    //
    // TASK-1382 — le troisieme argument `false` DESACTIVE le repli de locale, et
    // il est load-bearing. `Lang::has()` honore la locale de repli par defaut :
    // une cle presente en francais seulement repondrait `true` sous une
    // interface anglaise, et le membre lirait du francais au lieu du repli
    // generique — que ce code existe precisement pour afficher.
    //
    // Le cas est aujourd'hui inatteignable : la garde de TASK-1379 exige un
    // libelle dans les DEUX locales pour toute cle du catalogue. C'est bien
    // pour cela que le repli doit etre juste — il ne sert que le jour ou cette
    // garde aura ete contournee ou une locale ajoutee.
    $libelle = function (string $cle) {
        $traduction = 'notifications.keys.'.str_replace('.', '_', $cle);

        return \Illuminate\Support\Facades\Lang::has($traduction, null, false)
            ? __($traduction)
            : __('notifications.key_fallback');
    };

    // TASK-1725 (demande Cyril, 29/10) — nommer la Boucle quand on la connait.
    //
    // Deux formes coexistent : `keys_named` nomme, `keys` ne nomme pas. Le choix
    // se fait sur ce que le controleur a REELLEMENT pu resoudre — une Boucle
    // supprimee, ou d'un autre tenant, ne donne aucun nom, et la ligne retombe
    // alors sur la forme sans objet, qui reste juste.
    //
    // La forme nommee n'existe que pour les cles qui portent une Boucle :
    // `Lang::has()` garde donc le pas, sans repli de locale pour la meme raison
    // que ci-dessus — une cle presente dans une seule langue ne doit pas
    // s'afficher dans l'autre.
    //
    // TASK-1757 — le sujet peut desormais etre une CHAINE (le nom d'une Boucle,
    // forme T1725) ou un TABLEAU de parametres (une entraide : prenom, titre,
    // temps). On normalise ici plutot que d'ajouter une seconde fermeture : les
    // deux cas partagent exactement la meme discipline de repli, et la
    // dupliquer, c'est la laisser diverger.
    $libelleNomme = function (string $cle, string|array|null $sujet) use ($libelle) {
        $params = is_array($sujet)
            ? $sujet
            : (($sujet !== null && $sujet !== '') ? ['loop' => $sujet] : []);

        if ($params === []) {
            return $libelle($cle);
        }

        // TASK-1797 — « tous » bascule sur une cle VOISINE, pas sur un
        // troisieme texte superpose au meme endroit : une mention collective
        // ne nomme personne nommement, le dire ainsi serait une
        // interpellation nominative abusive (`sujetsDesMentions()` pose ce
        // marqueur en lisant `mention_all` sur le message lui-meme).
        // Cas explicite plutot qu'un suffixe generique : une seule cle du
        // catalogue a aujourd'hui une variante collective.
        $cleTraduction = str_replace('.', '_', $cle);

        if (($params['tous'] ?? false) && $cleTraduction === 'loop_mention_created') {
            $cleTraduction = 'loop_mention_all_created';
        }

        $traduction = 'notifications.keys_named.'.$cleTraduction;

        return \Illuminate\Support\Facades\Lang::has($traduction, null, false)
            ? __($traduction, $params)
            : $libelle($cle);
    };

    // TASK-1725 — « Ouvrir » ne disait pas quoi ouvrir. Le libelle suit l'OBJET,
    // et non la presence d'un nom : une notification de Boucle mene a une
    // Boucle meme quand la Boucle n'a pas pu etre nommee. Les autres objets —
    // un commentaire, un article — gardent « Ouvrir », qui reste exact pour eux.
    $objetsDeBoucle = [
        \App\Support\Notifications\NotificationCatalogue::OBJECT_LOOP,
        \App\Support\Notifications\NotificationCatalogue::OBJECT_LOOP_INVITATION,
        \App\Support\Notifications\NotificationCatalogue::OBJECT_LOOP_JOIN_REQUEST,
    ];

    $libelleOuvrir = fn (?string $typeDObjet) => in_array($typeDObjet, $objetsDeBoucle, true)
        ? __('notifications.open_loop')
        : __('notifications.open');
@endphp

<x-app-layout>
    <x-slot name="title">{{ __('notifications.title') }}</x-slot>

    <x-page-container>
        @if(session('notification_unreachable'))
            {{-- L'etat honnete : generique, sans un fragment de ce que la cible
                 contenait. Il n'y a rien d'ancien a reafficher — le stockage ne
                 retient aucun contenu. --}}
            <div data-notification-unreachable
                 class="mb-5 rounded-xl border border-[var(--bp-border)] bg-[var(--bp-panel)] px-4 py-3 text-sm text-[var(--bp-muted)]">
                {{ session('notification_unreachable') }}
            </div>
        @endif

        <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
            <div>
                <h1 class="text-3xl font-bold text-[var(--bp-text)]">{{ __('notifications.title') }}</h1>
                <p class="mt-1 text-sm text-[var(--bp-muted)]">{{ __('notifications.subtitle') }}</p>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                {{-- TASK-1739 — l'acces aux reglages, qui MANQUAIT.

                     Mesure du 01/11 : `notifications.preferences.edit` existait
                     depuis T1375 mais AUCUNE vue ne pointait dessus — ni le
                     menu avatar, ni ce centre, ni la barre laterale. L'ecran
                     n'etait atteignable qu'en tapant son URL, et la page de
                     reglages porte pourtant un lien de RETOUR vers ici : le
                     chemin existait dans un seul sens.

                     Ce lien appartient a cette TASK parce que sans lui son
                     bloc « Notifications sur cet appareil » est inatteignable,
                     donc la fonctionnalite n'existe pas pour la personne.

                     Rendu avant « Tout marquer comme lu » et SANS condition :
                     le bouton voisin disparait quand tout est lu, et accrocher
                     les reglages a la meme condition les ferait disparaitre
                     precisement quand on vient les regler. --}}
                <a href="{{ route($routePreferences, $routeParams) }}"
                   data-notifications-preferences
                   class="inline-flex min-h-11 items-center gap-2 rounded-xl border border-[var(--bp-border)] px-5 text-sm font-semibold text-[var(--bp-text)] transition hover:bg-[var(--bp-hover)]">
                    {{ __('notifications.preferences_title') }}
                </a>

                @if($nonLues > 0)
                    <form method="POST" action="{{ route($routeReadAll, $routeParams) }}">
                        @csrf
                        <button type="submit"
                                data-notifications-mark-all
                                class="inline-flex min-h-11 items-center gap-2 rounded-xl bg-indigo-600 px-5 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-700">
                            {{ __('notifications.mark_all_read') }}
                        </button>
                    </form>
                @endif
            </div>
        </div>

        {{-- Filtres en GET : une vue filtree se partage par son URL. --}}
        <div class="mb-5 inline-flex rounded-lg border border-[var(--bp-border)] p-0.5">
            @foreach([
                \App\Http\Controllers\NotificationCenterController::FILTRE_TOUTES => __('notifications.filter_all'),
                \App\Http\Controllers\NotificationCenterController::FILTRE_NON_LUES => __('notifications.filter_unread'),
            ] as $valeur => $texte)
                <a href="{{ $urlFiltre($valeur) }}"
                   data-notifications-filter="{{ $valeur }}"
                   @if($filtreActif($valeur)) aria-current="page" @endif
                   class="min-h-11 rounded-md px-4 py-2 text-sm font-semibold transition {{ $filtreActif($valeur) ? 'bg-[var(--bp-panel)] text-[var(--bp-text)] shadow-sm' : 'text-[var(--bp-muted)] hover:text-[var(--bp-text)]' }}">
                    {{ $texte }}
                </a>
            @endforeach
        </div>

        @if($notifications->isEmpty())
            {{-- DEUX etats vides, et les confondre serait un mensonge : une boite
                 vide et un filtre sans resultat ne disent pas la meme chose.

                 TASK-1775 — le defaut etant devenu « non-lues », une boite
                 JAMAIS alimentee prend desormais le meme chemin de filtre
                 qu'une boite entierement lue. `$dejaUneNotification` est la
                 question posee EN DEHORS du filtre qui les redistingue. --}}
            @if($filtreActif(\App\Http\Controllers\NotificationCenterController::FILTRE_NON_LUES) && $dejaUneNotification)
                <div data-notifications-filter-empty class="py-14 text-center">
                    <p class="text-base font-semibold text-[var(--bp-text)]">{{ __('notifications.filter_empty_title') }}</p>
                    <p class="mt-1 text-sm text-[var(--bp-muted)]">{{ __('notifications.filter_empty_body') }}</p>
                </div>
            @else
                <div data-notifications-empty class="py-14 text-center">
                    <p class="text-base font-semibold text-[var(--bp-text)]">{{ __('notifications.empty_title') }}</p>
                    <p class="mt-1 text-sm text-[var(--bp-muted)]">{{ __('notifications.empty_body') }}</p>
                </div>
            @endif
        @else
            <ul class="space-y-2">
                @foreach($notifications as $notification)
                    <li data-notification-id="{{ $notification->id }}"
                        @if($notification->isRead()) data-notification-read @else data-notification-unread @endif
                        class="flex flex-col gap-3 rounded-xl border border-[var(--bp-border)] bg-[var(--bp-panel)] px-4 py-3 shadow-sm sm:flex-row sm:flex-wrap sm:items-start sm:justify-between">
                        {{-- Titre + date d'abord, toujours lisibles sans defiler :
                             sur mobile la carte s'empile en colonne, les actions
                             suivent en dessous plutot qu'a cote. --}}
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-semibold text-[var(--bp-text)]">
                                {{ $libelleNomme($notification->notification_key, $sujets[$notification->id] ?? null) }}
                            </p>
                            <p class="mt-0.5 text-xs text-[var(--bp-muted)]">
                                <time datetime="{{ $notification->created_at?->toIso8601String() }}">
                                    {{ $notification->created_at?->diffForHumans() }}
                                </time>
                            </p>
                        </div>

                        {{-- `flex-wrap` ICI AUSSI : trois elements (badge + deux
                             boutons) sur un ecran de 320px ne tiennent pas sur
                             une seule ligne sans elle, et sans defilement
                             horizontal possible, le seul recours honnete est de
                             les laisser retomber sur une deuxieme ligne. --}}
                        <div class="flex flex-wrap items-center gap-3 sm:shrink-0">
                            {{-- Ouvrir est un POST : le geste marque lu, et un GET
                                 qui mute se rejoue — prefetch, scanner de liens,
                                 bouton precedent. --}}
                            <form method="POST" action="{{ route($routeOpen, $routeParams + ['notification' => $notification->id]) }}">
                                @csrf
                                <button type="submit"
                                        data-notification-open="{{ $notification->id }}"
                                        class="min-h-11 text-sm font-semibold text-[var(--bp-primary)] transition hover:underline">
                                    {{ $libelleOuvrir($notification->object_type) }}
                                </button>
                            </form>

                            @if($notification->isRead())
                                {{-- TASK-1775 — l'inverse symmetrique de « Marquer
                                     comme lu », reservee aux lignes DEJA lues :
                                     sur une notification non lue l'action n'a
                                     aucun sens, le bouton « Marquer comme lu »
                                     occupe deja ce role. --}}
                                <form method="POST" action="{{ route($routeUnread, $routeParams + ['notification' => $notification->id]) }}">
                                    @csrf
                                    <button type="submit"
                                            data-notification-mark-unread="{{ $notification->id }}"
                                            class="min-h-11 text-sm font-semibold text-[var(--bp-muted)] transition hover:text-[var(--bp-text)] hover:underline">
                                        {{ __('notifications.mark_unread') }}
                                    </button>
                                </form>
                            @else
                                <span class="rounded-full bg-red-500/10 px-2 py-0.5 text-[11px] font-bold text-red-600 dark:text-red-400">
                                    {{ __('notifications.unread_badge') }}
                                </span>

                                <form method="POST" action="{{ route($routeRead, $routeParams + ['notification' => $notification->id]) }}">
                                    @csrf
                                    <button type="submit"
                                            data-notification-mark-read="{{ $notification->id }}"
                                            class="min-h-11 text-sm font-semibold text-[var(--bp-primary)] transition hover:underline">
                                        {{ __('notifications.mark_read') }}
                                    </button>
                                </form>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>

            <div class="mt-6">{{ $notifications->links() }}</div>
        @endif
    </x-page-container>
</x-app-layout>
