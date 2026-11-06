@props([
    'avatar' => null,
    'isAi' => false,
    // Classes de TAILLE uniquement. L'en-tete de bulle veut une pastille de
    // 20 px, la gouttiere detachee une de 32 : c'est la seule chose qui
    // change entre les deux emplois.
    'size' => 'h-5 w-5',
    'iconSize' => 'h-3 w-3',
])
{{-- TASK-1772 (recette Cyril, 06/11) — la marque d'auteur, EXTRAITE.

     Elle vivait en double dans `message-bubble` : une fois dans l'en-tete de
     bulle, et l'avatar detache allait en faire une troisieme. Le symbole de
     marque fait quinze lignes de SVG — recopiees, elles auraient divergé au
     premier ajustement.

     Ce composant ne décide RIEN : ni la taille, ni le clic, ni la visibilité.
     Il rend la pastille, et c'est tout. --}}
@if($avatar)
<img src="{{ $avatar }}" alt="" class="{{ $size }} shrink-0 rounded-full object-cover">
@elseif($isAi)
{{-- TASK-1340 : symbole de marque (design/brand/bouclepro-symbol.svg) en
     monochrome blanc, même traitement que le bouton FAB BouclePro IA. --}}
<span class="{{ $size }} inline-flex shrink-0 items-center justify-center rounded-full bg-indigo-600 text-white shadow-sm shadow-violet-500/20 ring-1 ring-white/60 dark:ring-violet-300/20" aria-hidden="true">
    <svg class="{{ $iconSize }}" viewBox="0 0 512 512" fill="none" stroke="white" stroke-width="46" stroke-linecap="round" stroke-opacity="0.92">
        <path d="M 213.1 142 A 56 56 0 1 1 298.9 142"/>
        <path d="M 306.28 145.05 A 56 56 0 1 1 366.95 205.72"/>
        <path d="M 370 213.1 A 56 56 0 1 1 370 298.9"/>
        <path d="M 366.95 306.28 A 56 56 0 1 1 306.28 366.95"/>
        <path d="M 298.9 370 A 56 56 0 1 1 213.1 370"/>
        <path d="M 205.72 366.95 A 56 56 0 1 1 145.05 306.28"/>
        <path d="M 142 298.9 A 56 56 0 1 1 142 213.1"/>
        <path d="M 145.05 205.72 A 56 56 0 1 1 205.72 145.05"/>
        <path d="M 213.1 142 A 56 56 0 0 1 213.1 70"/>
    </svg>
</span>
@endif
