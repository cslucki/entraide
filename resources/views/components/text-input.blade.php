@props(['disabled' => false])

@php
    /* TASK-1871 — un champ en faute le DIT, sans qu'on ait a le repeter.
       Le nom du champ suffit a savoir s'il porte une erreur : inutile de
       poser `aria-invalid` a la main sur chacun des vingt champs du profil,
       ce qui aurait fait vingt occasions d'en oublier un.
       `aria-describedby` pointe vers l'`id` que `x-input-error` pose a partir
       du meme nom — les deux composants se rejoignent sur cette convention et
       sur rien d'autre.
       Un champ sans `name`, ou sans erreur, ne recoit aucun de ces attributs :
       le rendu est alors identique a ce qu'il etait. */
    $_nom = $attributes->get('name');
    $_enFaute = $_nom && $errors->has($_nom);
@endphp

<input @disabled($disabled)
    @if($_enFaute) aria-invalid="true" aria-describedby="{{ $_nom }}-error" @endif
    {{ $attributes->merge(['class' => 'border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-indigo-500 dark:focus:border-indigo-600 focus:ring-indigo-500 dark:focus:ring-indigo-600 rounded-md shadow-sm']) }}>
