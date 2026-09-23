@props(['align' => 'right', 'width' => '48', 'contentClasses' => 'py-1 bg-white dark:bg-gray-700'])

@php
$alignmentClasses = match ($align) {
    'left' => 'ltr:origin-top-left rtl:origin-top-right start-0',
    'left-up' => 'ltr:origin-bottom-left rtl:origin-bottom-right start-0 bottom-full mb-2',
    'top' => 'origin-top',
    default => 'ltr:origin-top-right rtl:origin-top-left end-0',
};

$verticalClasses = $align === 'left-up' ? '' : 'mt-2';

$width = match ($width) {
    '48' => 'w-48',
    '56' => 'w-56',
    default => $width,
};
@endphp

{{-- TASK-1625 — le menu annonce son etat.

     Un panneau `absolute` ne peut pas sortir du contexte d'empilement de son
     parent : quand cet ancetre est `fixed` avec un `z-index` — c'est le cas du
     header mobile — le menu est plafonne a la couche du parent, quelle que
     soit sa propre valeur. La seule reparation possible est de faire monter
     L'ANCETRE, et pour cela il doit savoir quand le menu s'ouvre.

     `x-effect` reevalue a chaque changement de `open` et laisse l'evenement
     remonter ; un ancetre qui ne l'ecoute pas ne voit rien changer. --}}
<div class="relative" x-data="{ open: false }" x-effect="$dispatch('dropdown-open-changed', { open })" @click.outside="open = false" @close.stop="open = false">
    <div @click="open = ! open">
        {{ $trigger }}
    </div>

    <div x-show="open"
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="opacity-0 scale-95"
            x-transition:enter-end="opacity-100 scale-100"
            x-transition:leave="transition ease-in duration-75"
            x-transition:leave-start="opacity-100 scale-100"
            x-transition:leave-end="opacity-0 scale-95"
            {{-- TASK-1625 — le menu comptait ~16 entrees pour une fenetre de
                 844 px : les dernieres (Mentions legales, Signaler un bug,
                 Administration, Deconnexion) etaient physiquement hors de
                 l'ecran, et l'ancetre etant `fixed`, le defilement de la page
                 ne les rattrapait pas. `overscroll-contain` evite que le
                 defilement du menu entraine celui de la page derriere lui. --}}
            class="absolute z-50 {{ $verticalClasses }} {{ $width }} max-h-[calc(100dvh-5rem)] overflow-y-auto overscroll-contain rounded-md shadow-lg {{ $alignmentClasses }}"
            style="display: none;">
        <div class="rounded-md ring-1 ring-black ring-opacity-5 {{ $contentClasses }}">
            {{ $content }}
        </div>
    </div>
</div>
