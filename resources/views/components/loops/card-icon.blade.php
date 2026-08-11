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

    TASK-1127 : chaque outil a desormais **sa** silhouette. Quinze Cards se
    partageaient sept dessins — cinq outils differents portaient le meme
    `document` — et le catalogue demandait de lire les titres pour s'y
    retrouver. Les traces restent des Heroicons outline, epaisseur 2 : aucune
    bibliotheque ajoutee, le meme vocabulaire graphique qu'avant.
--}}
@props([
    'icon' => null,
    'size' => 'md',   // md = tuile de barre, sm = ligne compacte, lg = carte de catalogue
])

@php
    // Traits Heroicons, epaisseur 2 : le meme vocabulaire graphique que le
    // reste du produit.
    $paths = [
        // Resume IA — l'etincelle.
        'sparkles' => 'M9.813 15.904 11.18 18.55a.75.75 0 0 0 1.38-.031l1.745-3.83a.75.75 0 0 1 .322-.36l3.746-2.25a.75.75 0 0 0 0-1.27l-3.746-2.25a.75.75 0 0 1-.322-.36L12.56 5.48a.75.75 0 0 0-1.38-.031l-1.367 2.647a.75.75 0 0 1-.5.369L4.88 9.373a.75.75 0 0 0 0 1.463l3.432.92a.75.75 0 0 1 .5.368z',
        // Le dessin generique d'une page : c'est le repli par defaut, plus
        // l'identite d'aucun outil.
        'document' => 'M19.5 14.25v-6a2.25 2.25 0 0 0-.659-1.591l-3-3A2.25 2.25 0 0 0 14.25 3H6.75A2.25 2.25 0 0 0 4.5 5.25v13.5A2.25 2.25 0 0 0 6.75 21h10.5a2.25 2.25 0 0 0 2.25-2.25v-4.5z M14.25 3v4.5h4.5',
        // Manifeste — le cap, les principes.
        'flag' => 'M3 3v18 M3 4.5c1.5-1 3.5-1 5.25 0s3.75 1 5.25 0 3.5-1 5.25 0v9c-1.75-1-3.75-1-5.25 0s-3.75 1-5.25 0-3.75-1-5.25 0',
        // Roadmap — trois jalons relies. L'ancien trace (deux fleches vers le
        // bas) ne racontait rien et servait aussi aux Travaux a rendre.
        'map' => 'M5.25 6.75a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3Z M12 13.5a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3Z M18.75 20.25a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3Z M6.5 6.5l4.25 4.25 M13.25 13.25l4.25 4.25',
        // Sondages — les barres horizontales des reponses.
        'bars' => 'M3.75 6.75h10.5 M3.75 12h16.5 M3.75 17.25h7.5',
        // Evenements.
        'calendar' => 'M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0V11.25A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5',
        // Dossiers.
        'folder' => 'M2.25 12.75V12A2.25 2.25 0 0 1 4.5 9.75h15A2.25 2.25 0 0 1 21.75 12v.75m-8.69-6.44-2.12-2.12a1.5 1.5 0 0 0-1.061-.44H4.5A2.25 2.25 0 0 0 2.25 6v12a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18V9a2.25 2.25 0 0 0-2.25-2.25h-5.379a1.5 1.5 0 0 1-1.06-.44Z',
        // Support de cours — la toque.
        'academic' => 'M4.26 10.147a60.438 60.438 0 0 0-.491 6.347A48.62 48.62 0 0 1 12 20.904a48.62 48.62 0 0 1 8.232-4.41 60.46 60.46 0 0 0-.491-6.347m-15.482 0a50.636 50.636 0 0 0-2.658-.813A59.906 59.906 0 0 1 12 3.493a59.903 59.903 0 0 1 10.399 5.84c-.896.248-1.783.52-2.658.814m-15.482 0A50.717 50.717 0 0 1 12 13.489a50.702 50.702 0 0 1 7.74-3.342M6.75 15a.75.75 0 1 0 0-1.5.75.75 0 0 0 0 1.5Zm0 0v-3.675A55.378 55.378 0 0 1 12 8.443m-7.007 11.55A5.981 5.981 0 0 0 6.75 15.75v-1.5',
        // Progression — la courbe qui monte.
        'trending' => 'M2.25 18 9 11.25l4.306 4.306a11.95 11.95 0 0 1 5.814-5.518l2.74-1.22m0 0-5.94-2.281m5.94 2.28-2.28 5.941',
        // Travaux a rendre — la consigne cochee.
        'clipboard' => 'M11.35 3.836c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 0 0 .75-.75 2.25 2.25 0 0 0-.1-.664m-5.8 0A2.251 2.251 0 0 1 13.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m8.9-4.414c.376.023.75.05 1.124.08 1.131.094 1.976 1.057 1.976 2.192V16.5A2.25 2.25 0 0 1 18 18.75h-2.25m-7.5-10.5H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V18.75m-7.5-10.5h6.375c.621 0 1.125.504 1.125 1.125v9.375m-8.25-3 1.5 1.5 3-3.75',
        // QCM — la question.
        'question' => 'M9.879 7.519c1.171-1.025 3.071-1.025 4.242 0 1.172 1.025 1.172 2.687 0 3.712-.203.179-.43.326-.67.442-.745.361-1.45.999-1.45 1.827v.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 5.25h.008v.008H12v-.008Z',
        // Journal — les pages datees.
        'book' => 'M12 6.042A8.967 8.967 0 0 0 6 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 0 1 6 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 0 1 6-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0 0 18 18a8.967 8.967 0 0 0-6 2.292m0-14.25v14.25',
        // Decisions — la balance.
        'scale' => 'M12 3v17.25m0 0c-1.472 0-2.882.265-4.185.75M12 20.25c1.472 0 2.882.265 4.185.75M18.75 4.97A48.416 48.416 0 0 0 12 4.5c-2.291 0-4.545.16-6.75.47m13.5 0c1.01.143 2.01.317 3 .52m-3-.52 2.62 10.726c.122.499-.106 1.028-.589 1.202a5.988 5.988 0 0 1-2.031.352 5.988 5.988 0 0 1-2.031-.352c-.483-.174-.711-.703-.59-1.202L18.75 4.971Zm-16.5.52c.99-.203 1.99-.377 3-.52m0 0 2.62 10.726c.122.499-.106 1.028-.589 1.202a5.989 5.989 0 0 1-2.031.352 5.989 5.989 0 0 1-2.031-.352c-.483-.174-.711-.703-.59-1.202L5.25 4.971Z',
        // Demande-Offre — l'echange.
        'swap' => 'M7.5 21 3 16.5m0 0L7.5 12M3 16.5h13.5m0-13.5L21 7.5m0 0L16.5 12M21 7.5H7.5',
        // Article — l'atelier d'ecriture.
        'pencil' => 'M16.862 4.487l1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Z M16.862 4.487 19.5 7.125 M18 14v4.75A2.25 2.25 0 0 1 15.75 21H5.25A2.25 2.25 0 0 1 3 18.75V8.25A2.25 2.25 0 0 1 5.25 6H10',
        // Membres.
        'users' => 'M18 18.72a9.094 9.094 0 0 0 3.741-.479 3 3 0 0 0-4.682-2.72m.94 3.198.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0 1 12 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 0 1 6 18.719m12 0a5.971 5.971 0 0 0-.941-3.197m0 0A5.995 5.995 0 0 0 12 12.75a5.995 5.995 0 0 0-5.058 2.772m0 0a3 3 0 0 0-4.681 2.72 8.986 8.986 0 0 0 3.74.477m.94-3.197a5.971 5.971 0 0 0-.94 3.197M15 6.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm6 3a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Zm-13.5 0a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Z',
    ];

    // La teinte suit l'icone, donc la nature de l'outil, et non sa cle.
    $tones = [
        'sparkles' => 'bg-violet-100 text-violet-600 dark:bg-violet-500/20 dark:text-violet-300',
        'document' => 'bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-300',
        'flag' => 'bg-sky-100 text-sky-600 dark:bg-sky-500/20 dark:text-sky-300',
        'map' => 'bg-emerald-100 text-emerald-600 dark:bg-emerald-500/20 dark:text-emerald-300',
        'bars' => 'bg-fuchsia-100 text-fuchsia-600 dark:bg-fuchsia-500/20 dark:text-fuchsia-300',
        'calendar' => 'bg-indigo-100 text-indigo-600 dark:bg-indigo-500/20 dark:text-indigo-300',
        'folder' => 'bg-amber-100 text-amber-600 dark:bg-amber-500/20 dark:text-amber-300',
        'academic' => 'bg-blue-100 text-blue-600 dark:bg-blue-500/20 dark:text-blue-300',
        'trending' => 'bg-teal-100 text-teal-600 dark:bg-teal-500/20 dark:text-teal-300',
        'clipboard' => 'bg-orange-100 text-orange-600 dark:bg-orange-500/20 dark:text-orange-300',
        'question' => 'bg-purple-100 text-purple-600 dark:bg-purple-500/20 dark:text-purple-300',
        'book' => 'bg-stone-200 text-stone-600 dark:bg-stone-500/20 dark:text-stone-300',
        'scale' => 'bg-slate-200 text-slate-600 dark:bg-slate-500/20 dark:text-slate-300',
        'swap' => 'bg-lime-100 text-lime-700 dark:bg-lime-500/20 dark:text-lime-300',
        'pencil' => 'bg-rose-100 text-rose-600 dark:bg-rose-500/20 dark:text-rose-300',
        'users' => 'bg-cyan-100 text-cyan-600 dark:bg-cyan-500/20 dark:text-cyan-300',
    ];

    $key = $icon ?: 'document';
    $path = $paths[$key] ?? $paths['document'];
    $tone = $tones[$key] ?? 'bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-300';

    [$box, $glyph] = match ($size) {
        'sm' => ['h-6 w-6 rounded-lg', 'h-3.5 w-3.5'],
        'lg' => ['h-12 w-12 rounded-2xl', 'h-6 w-6'],
        default => ['h-8 w-8 rounded-xl', 'h-4 w-4'],
    };
@endphp

<span {{ $attributes->class(['inline-flex shrink-0 items-center justify-center', $box, $tone]) }} aria-hidden="true">
    <svg class="{{ $glyph }}" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        @foreach(explode(' M', ltrim($path, 'M')) as $i => $segment)
            <path stroke-linecap="round" stroke-linejoin="round" d="M{{ $segment }}" />
        @endforeach
    </svg>
</span>
