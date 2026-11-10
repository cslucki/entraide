{{--
    TASK-1801 correctif 05 — « Derniers messages », en page (pas en popup).

    La Boucle est mise en avant (visuel + nom), l'inverse de la premiere
    version en modale qui mettait l'auteur en avant : une section par Boucle
    ou cette personne est membre et qui porte au moins un message, chacune
    listant ses 3 messages humains les plus recents (avatar, nom, date et
    heure), chacun cliquable pour aller DIRECTEMENT au message dans la Boucle.

    TASK-1801 correctif 08 (demande de Cyril) — cette page est une
    SOUS-PAGE de « Boucles » : elle gagne donc la meme barre de navigation
    qu'une sous-page sur BUREAU (`<x-page-topbar>`, deja posee pour dix
    autres ecrans par TASK-1749 — favoris, points, profil...), au lieu d'un
    `<h1>` et d'un lien de retour fait a la main. Sur smartphone, rien ne
    change : `<x-page-topbar>` est `hidden md:flex` par construction, la
    barre mobile (correctif 07) reste la seule a s'afficher.

    TASK-1801 correctif 08 (suite, precision de Cyril) — la barre de
    sous-page porte « Boucles » (`$heading`, section PARENTE), pas
    « Derniers messages » : c'est la MEME etiquette que le `back-label` et
    que la barre mobile. Le titre PROPRE a cette page, « Derniers
    messages », redevient un `<h1>` dans le corps — mais SEULEMENT sur
    bureau (`hidden md:block`) : sur smartphone, la barre mobile l'a deja
    dit a sa maniere (correctif 07), et ce `<h1>` y ferait doublon.
--}}
@php
    $_org = request()->route('organization');
    $loopsIndexHref = $_org && request()->routeIs('organization.*')
        ? route('organization.loops.index', ['organization' => $_org])
        : route('loops.index');
    // `$boucle`, jamais `$loop` : Blade pose AUTOMATIQUEMENT une variable
    // `$loop` (metadonnees d'iteration — `$loop->first`, `$loop->last`...)
    // a CHAQUE `@foreach`, y compris imbrique. Un modele Loop nomme pareil
    // se ferait ecraser des le `@foreach` interne — mesure en recette (la
    // page rendait « stdClass::$slug introuvable »).
    $boucleMessageHref = function ($boucle, $message) use ($_org) {
        $params = ['loop' => $boucle->slug ?: $boucle->id, 'message' => $message->id];

        if ($_org && request()->routeIs('organization.*') && \Illuminate\Support\Facades\Route::has('organization.loops.show')) {
            return route('organization.loops.show', ['organization' => $_org] + $params);
        }

        return route('loops.show', $params);
    };
@endphp

<x-page :title="__('loops.latest_messages_title')"
        :heading="__('loops.index_title')"
        :back="$loopsIndexHref"
        :back-label="__('loops.index_title')"
        width="3xl">
    <div data-loops-latest-messages-page>
        <h1 class="hidden md:block mb-6 text-2xl font-bold tracking-tight text-gray-900 dark:text-gray-100">
            {{ __('loops.latest_messages_title') }}
        </h1>

        @if(! $aAuMoinsUneBoucle)
            {{-- Personne membre d'aucune Boucle : le lien renvoie sur CETTE
                 meme liste de Boucles (demande explicite de Cyril). --}}
            <p class="text-sm text-gray-600 dark:text-gray-400">
                {{ __('loops.latest_messages_empty_before') }}
                <a href="{{ $loopsIndexHref }}"
                   class="font-semibold text-indigo-600 hover:underline dark:text-indigo-400">{{ __('loops.latest_messages_empty_link') }}</a>{{ __('loops.latest_messages_empty_after') }}
            </p>
        @elseif($loopsAvecMessages->isNotEmpty())
            {{-- Une Boucle membre mais sans aucun message n'a simplement pas
                 de section : rien a construire pour elle (demande explicite
                 de Cyril, « on affiche rien »). --}}
            <div class="space-y-6">
                @foreach($loopsAvecMessages as $entree)
                    @php($boucle = $entree['loop'])
                    <section class="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800" data-loops-latest-messages-section="{{ $boucle->id }}">
                        <div class="relative">
                            <x-loops.cover :loop="$boucle" ratio="16 / 5" />
                            <div class="absolute inset-0 flex items-end bg-gradient-to-t from-black/70 via-black/10 to-transparent p-4">
                                <h2 class="text-lg font-bold text-white drop-shadow-sm">{{ $boucle->name }}</h2>
                            </div>
                            <a href="{{ $boucleMessageHref($boucle, $entree['messages']->first()) }}" class="absolute inset-0"
                               aria-label="{{ $boucle->name }}"></a>
                        </div>

                        <ul class="divide-y divide-gray-100 dark:divide-gray-700">
                            @foreach($entree['messages'] as $message)
                                {{-- TASK-1801 correctif 05 (suite, demande de Cyril) —
                                     une image SANS commentaire ne doit pas rendre la
                                     ligne vide : sa vignette tient lieu de contenu. --}}
                                @php($imageUrl = $message->imageUrl())
                                <li>
                                    <a href="{{ $boucleMessageHref($boucle, $message) }}"
                                       data-loops-latest-message-row="{{ $message->id }}"
                                       class="flex gap-3 p-4 transition hover:bg-gray-50 dark:hover:bg-gray-700/40">
                                        <img src="{{ $message->sender->avatar_url }}" alt=""
                                             class="h-10 w-10 shrink-0 rounded-full object-cover ring-1 ring-gray-200 dark:ring-gray-600">
                                        <div class="min-w-0 flex-1">
                                            <div class="flex flex-wrap items-baseline gap-x-2">
                                                <span class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ $message->sender->publicDisplayName() }}</span>
                                                <span class="text-xs text-gray-400 dark:text-gray-500">{{ $message->created_at->isoFormat('D MMM, HH:mm') }}</span>
                                            </div>
                                            <div class="mt-1 flex items-center gap-2">
                                                @if($imageUrl)
                                                    <img src="{{ $imageUrl }}" alt=""
                                                         class="h-10 w-10 shrink-0 rounded-lg object-cover ring-1 ring-gray-200 dark:ring-gray-600">
                                                @endif
                                                @if(filled(trim((string) $message->body)))
                                                    <p class="min-w-0 break-words text-sm text-gray-600 dark:text-gray-300">{{ Str::limit(trim($message->body), 140) }}</p>
                                                @elseif($imageUrl)
                                                    <p class="min-w-0 text-sm italic text-gray-400 dark:text-gray-500">{{ __('loops.latest_messages_image_only') }}</p>
                                                @endif
                                            </div>
                                        </div>
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </section>
                @endforeach
            </div>
        @endif
    </div>
</x-page>
