{{--
    Enveloppe de pop-up pour une Card de l'éditeur.

    Les quatre Cards concernées — Boucle, Dossier, Tâches, Co-auteurs — ont
    gardé **leur état Alpine et tout leur comportement** : seul leur contenant
    change, d'un bloc repliable de la colonne latérale à une modale ouverte
    depuis la barre au-dessus de l'article.

    C'est délibéré. Réécrire leur logique en même temps que leur présentation
    aurait mélangé deux risques ; ici, si quelque chose casse, c'est le
    contenant.

    Le contenu reste monté en permanence, jamais détruit à la fermeture : une
    Card qui a chargé ses données ou un message en cours de saisie ne doit pas
    les perdre parce qu'on a refermé le pop-up.
--}}
@props(['name', 'title', 'icon' => null, 'width' => 'max-w-2xl'])

{{-- Teleporte dans <body>.

     Sans cela, `fixed inset-0` etait neutralise : un ancetre de la colonne
     laterale cree un bloc conteneur, et le pop-up se retrouvait large de zero
     pixel — present dans le DOM, invisible a l'ecran. --}}
<template x-teleport="body">
<div x-show="$store.editorPanels.isOpen(@js($name))"
     x-cloak
     class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-gray-900/50 p-4 sm:p-8"
     x-on:keydown.escape.window="$store.editorPanels.close()"
     {{-- Fermeture par le fond, pas par `@click.outside` : ce dernier voyait le
          clic du bouton de la barre comme un clic exterieur et refermait le
          pop-up dans la foulee de son ouverture. --}}
     @click.self="$store.editorPanels.close()"
     role="dialog" aria-modal="true" :aria-label="@js($title)">

    <div class="w-full {{ $width }} rounded-2xl bg-white shadow-xl dark:bg-gray-800">

        <div class="flex items-center justify-between gap-3 border-b border-gray-200 px-5 py-3 dark:border-gray-700">
            <p class="flex items-center gap-2 text-sm font-semibold text-gray-800 dark:text-gray-100">
                {!! $icon !!}
                {{ $title }}
            </p>
            <button type="button" @click="$store.editorPanels.close()"
                    class="inline-flex h-9 w-9 items-center justify-center rounded-full border border-gray-200 text-gray-500 transition hover:bg-gray-100 hover:text-gray-800 dark:border-gray-700 dark:hover:bg-gray-700 dark:hover:text-gray-200"
                    aria-label="{{ __('blog.preview_close') }}">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/></svg>
            </button>
        </div>

        <div class="px-5 py-4">
            {{ $slot }}
        </div>
    </div>
</div>
</template>
