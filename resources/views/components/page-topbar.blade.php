{{--
    TASK-1749 (demande Cyril, 04/11) — LA barre du haut, en un exemplaire.

    ## Pourquoi un composant, et pas dix copies

    Le modele est celui de `services/show` : barre collante en pleine largeur,
    fleche ronde, titre sur la meme ligne. Dix pages devaient l'adopter —
    `dashboard`, ses deux listes, les deux formulaires de creation, les favoris,
    les invitations, les points, le profil, les bugs.

    Recopier quinze lignes de balisage dix fois aurait garanti qu'elles
    divergent : a la premiere retouche d'espacement, de couleur ou de bordure,
    une page serait restee en arriere sans que rien ne le dise. C'est exactement
    ce que ce correctif repare — ces pages portaient chacune SON en-tete, et
    c'est pour cela qu'elles ne se ressemblaient pas.

    ## Ce qu'il ne fait pas

    Il ne rend rien sur MOBILE (`hidden md:flex`). `x-mobile-topbar` y est deja
    monte par le layout et nomme ces routes une a une : une seconde barre y
    ferait doublon.

    Il ne decide d'AUCUNE destination. Le `back` est passe par la page, qui
    seule sait d'ou elle releve — et elle doit le construire SCOPE sur
    l'Organization, sans quoi le retour sort du locataire (defaut corrige neuf
    fois par TASK-1697 sur la seule fiche profil).

    ## Les emplacements

    - `title`    : le libelle, deja traduit.
    - `back`     : l'URL de retour.
    - `label`    : le texte d'accessibilite de la fleche ; retombe sur `title`.
    - `$action`  : emplacement FACULTATIF, aligne a droite — le geste principal
                   de la page quand elle en a un (« + Nouveau »).
--}}
@props(['back', 'title', 'label' => null])

<div {{ $attributes->merge(['class' => 'hidden md:flex items-center gap-3 px-4 sm:px-6 lg:px-8 py-3 border-b border-gray-200 dark:border-gray-700 bg-[var(--bp-surface)] sticky top-0 z-30']) }}
     data-page-topbar>
    <a href="{{ $back }}" data-page-topbar-back
       class="inline-flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-full bg-gray-100 text-gray-600 transition hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600"
       aria-label="{{ $label ?? $title }}">
        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
    </a>

    <span class="truncate text-sm font-medium text-gray-900 dark:text-gray-100" data-page-topbar-title>{{ $title }}</span>

    @isset($action)
        <div class="ml-auto flex shrink-0 items-center gap-3">{{ $action }}</div>
    @endisset
</div>
