{{--
    Une ligne d'une Serie : son numero, son icone, son nom.

    Le numero est **calcule** par `ArticleSeries::numberedContents()` et passe
    ici tel quel. Ce composant ne le deduit pas lui-meme et ne l'ecrit nulle
    part : il l'affiche. Deux endroits qui calculeraient le meme numero
    finiraient par ne plus dire la meme chose.

    L'icone vient de `x-loops.card-icon`, qui associe deja un trace et une
    teinte a `document` et a `folder` : un Article et un fichier se
    reconnaissent donc ici avec le meme vocabulaire graphique qu'ailleurs.

    Ecrit une fois pour Dossiers, l'Article et le Support de cours.
--}}
@props([
    'number',
    'type' => 'article',   // root | article | file
    'name',
    'url' => null,
    'draggable' => false,
])

@php
    // Un Article et une racine se dessinent en `document`, un fichier en
    // `folder` : c'est la nature du contenu qui choisit, pas son rang.
    $icon = $type === 'file' ? 'folder' : 'document';
    $isRoot = $type === 'root';
@endphp

<div {{ $attributes->merge([
        'class' => 'group flex items-center gap-3 rounded-xl border border-gray-200 bg-white px-3 py-2.5 transition-colors hover:border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:hover:border-gray-600',
    ]) }}>

    @if ($draggable)
        {{-- La poignee est un complement, jamais le seul chemin : les boutons
             « Monter » et « Descendre » font le meme travail au clavier. Elle
             est donc masquee aux lecteurs d'ecran plutot que d'annoncer une
             action qu'ils ne peuvent pas accomplir. --}}
        <span class="hidden cursor-grab text-gray-300 group-hover:text-gray-400 dark:text-gray-600 sm:block" data-series-handle aria-hidden="true">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor"><circle cx="9" cy="6" r="1.5"/><circle cx="15" cy="6" r="1.5"/><circle cx="9" cy="12" r="1.5"/><circle cx="15" cy="12" r="1.5"/><circle cx="9" cy="18" r="1.5"/><circle cx="15" cy="18" r="1.5"/></svg>
        </span>
    @endif

    {{-- `aria-hidden` : le numero est deja dit dans le nom, par le libelle
         « Position n » lu avant lui. L'annoncer deux fois ferait dire
         « zero-un, Position un, La racine ». --}}
    <span data-series-number class="shrink-0 font-mono text-xs font-semibold tabular-nums text-gray-400 dark:text-gray-500" aria-hidden="true">{{ $number }}</span>

    <x-loops.card-icon :icon="$icon" size="sm" />

    <div class="min-w-0 flex-1">
        @if ($url)
            <a href="{{ $url }}" class="block truncate text-sm font-medium text-gray-900 hover:text-indigo-600 dark:text-gray-100 dark:hover:text-indigo-400">
                <span class="sr-only">{{ __('dossiers.series_position_label', ['number' => (int) $number]) }} —</span>{{ $name }}
            </a>
        @else
            <span class="block truncate text-sm font-medium text-gray-900 dark:text-gray-100">
                <span class="sr-only">{{ __('dossiers.series_position_label', ['number' => (int) $number]) }} —</span>{{ $name }}
            </span>
        @endif
    </div>

    @if ($isRoot)
        <span class="shrink-0 rounded-full bg-indigo-50 px-2 py-0.5 text-[11px] font-semibold text-indigo-700 dark:bg-indigo-500/15 dark:text-indigo-300">
            {{ __('dossiers.series_root') }}
        </span>
    @endif

    {{ $slot }}
</div>
