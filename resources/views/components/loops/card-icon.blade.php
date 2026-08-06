{{--
    L'icone d'une Card, et sa teinte.

    Une seule declaration pour les deux. Elles vivaient jusqu'ici dans deux
    listes tenues a la main dans les vues — un `@switch` pour le dessin, un
    tableau `$cardAccents` pour la couleur — et ces listes divergeaient du
    catalogue a chaque Card ajoutee : Sondages, Evenements et Dossiers
    tombaient toutes les trois sur l'icone par defaut, en gris.

    Le catalogue declare `icon` ; ce composant sait le dessiner. Une Card
    nouvelle n'a donc qu'a nommer son icone pour etre correctement rendue,
    partout ou elle s'affiche.
--}}
@props([
    'icon' => null,
    'size' => 'md',   // md = tuile de barre, sm = ligne compacte
])

@php
    // Traits Heroicons, epaisseur 2 : le meme vocabulaire graphique que le
    // reste du produit.
    $paths = [
        'sparkles' => 'M9.813 15.904 11.18 18.55a.75.75 0 0 0 1.38-.031l1.745-3.83a.75.75 0 0 1 .322-.36l3.746-2.25a.75.75 0 0 0 0-1.27l-3.746-2.25a.75.75 0 0 1-.322-.36L12.56 5.48a.75.75 0 0 0-1.38-.031l-1.367 2.647a.75.75 0 0 1-.5.369L4.88 9.373a.75.75 0 0 0 0 1.463l3.432.92a.75.75 0 0 1 .5.368z',
        'document' => 'M19.5 14.25v-6a2.25 2.25 0 0 0-.659-1.591l-3-3A2.25 2.25 0 0 0 14.25 3H6.75A2.25 2.25 0 0 0 4.5 5.25v13.5A2.25 2.25 0 0 0 6.75 21h10.5a2.25 2.25 0 0 0 2.25-2.25v-4.5z M14.25 3v4.5h4.5',
        'map' => 'M9 6.75V15m0 0 3-3m-3 3-3-3M15 9v8.25M15 17.25l3-3m-3 3-3-3',
        'chart' => 'M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V4.125Z',
        'calendar' => 'M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0V11.25A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5',
        'folder' => 'M2.25 12.75V12A2.25 2.25 0 0 1 4.5 9.75h15A2.25 2.25 0 0 1 21.75 12v.75m-8.69-6.44-2.12-2.12a1.5 1.5 0 0 0-1.061-.44H4.5A2.25 2.25 0 0 0 2.25 6v12a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18V9a2.25 2.25 0 0 0-2.25-2.25h-5.379a1.5 1.5 0 0 1-1.06-.44Z',
        'users' => 'M18 18.72a9.094 9.094 0 0 0 3.741-.479 3 3 0 0 0-4.682-2.72m.94 3.198.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0 1 12 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 0 1 6 18.719m12 0a5.971 5.971 0 0 0-.941-3.197m0 0A5.995 5.995 0 0 0 12 12.75a5.995 5.995 0 0 0-5.058 2.772m0 0a3 3 0 0 0-4.681 2.72 8.986 8.986 0 0 0 3.74.477m.94-3.197a5.971 5.971 0 0 0-.94 3.197M15 6.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm6 3a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Zm-13.5 0a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Z',
    ];

    // La teinte suit l'icone, donc la nature de la Card, et non sa cle : deux
    // Cards documentaires se ressemblent, c'est voulu.
    $tones = [
        'sparkles' => 'bg-violet-100 text-violet-600 dark:bg-violet-500/20 dark:text-violet-300',
        'document' => 'bg-sky-100 text-sky-600 dark:bg-sky-500/20 dark:text-sky-300',
        'map' => 'bg-emerald-100 text-emerald-600 dark:bg-emerald-500/20 dark:text-emerald-300',
        'chart' => 'bg-fuchsia-100 text-fuchsia-600 dark:bg-fuchsia-500/20 dark:text-fuchsia-300',
        'calendar' => 'bg-indigo-100 text-indigo-600 dark:bg-indigo-500/20 dark:text-indigo-300',
        'folder' => 'bg-amber-100 text-amber-600 dark:bg-amber-500/20 dark:text-amber-300',
        'users' => 'bg-rose-100 text-rose-600 dark:bg-rose-500/20 dark:text-rose-300',
    ];

    $key = $icon ?: 'document';
    $path = $paths[$key] ?? $paths['document'];
    $tone = $tones[$key] ?? 'bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-300';

    $box = $size === 'sm' ? 'h-6 w-6 rounded-lg' : 'h-8 w-8 rounded-xl';
    $glyph = $size === 'sm' ? 'h-3.5 w-3.5' : 'h-4 w-4';
@endphp

<span {{ $attributes->class(['inline-flex shrink-0 items-center justify-center', $box, $tone]) }} aria-hidden="true">
    <svg class="{{ $glyph }}" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        @foreach(explode(' M', ltrim($path, 'M')) as $i => $segment)
            <path stroke-linecap="round" stroke-linejoin="round" d="M{{ $segment }}" />
        @endforeach
    </svg>
</span>
