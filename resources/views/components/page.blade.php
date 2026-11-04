{{--
    TASK-1749 — `back` est FACULTATIF, et c'est ce qui borne le correctif.

    Ce composant sert quatre vues : les deux formulaires de creation que Cyril
    a nommes, plus `loops/index` et `blog/create`. Remplacer l'en-tete pour
    tout le monde aurait change deux pages qu'il n'a pas demandees.

    Avec `back` : la barre du haut standard (`x-page-topbar`), en pleine
    largeur et collante, comme le reste du produit — et le `<h1>` ne sort pas,
    sinon le titre paraitrait deux fois.

    Sans `back` : l'en-tete d'origine, a l'octet pres. Les deux autres vues
    sont donc rigoureusement inchangees.

    `headingActions` continue de servir dans les deux cas : il alimente
    l'emplacement `action` de la barre, ou l'ancien en-tete.
--}}
@props(['title' => '', 'heading' => '', 'width' => '7xl', 'back' => null, 'backLabel' => null])

<x-app-layout :title="filled($title) ? $title : $heading">
    @if($back)
        <x-page-topbar :back="$back" :title="$heading" :label="$backLabel">
            @isset($headingActions)
                <x-slot:action>{{ $headingActions }}</x-slot:action>
            @endisset
        </x-page-topbar>
    @endif

    <x-page-container :width="$width">
        @if($heading && ! $back)
            <div class="hidden md:flex items-center justify-between mb-8">
                <h1 class="text-3xl font-bold text-gray-900 dark:text-gray-100">
                    {{ $heading }}
                </h1>
                @isset($headingActions)
                    {{ $headingActions }}
                @endisset
            </div>
        @endif

        {{ $slot }}
    </x-page-container>
</x-app-layout>
