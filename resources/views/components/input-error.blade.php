@props([
    'messages',
    // TASK-1871 — le nom du champ decrit. Quand il est donne, ce bloc porte
    // l'`id` que `x-text-input` vise par `aria-describedby`.
    //
    // C'est le SEUL ajout de cette TASK a ce composant, et il est invisible :
    // ce fichier sert dix-huit ecrans, et l'habillage y reste celui d'avant.
    // Une premiere version y ajoutait un pictogramme ; retiree a la revue
    // MASTER, qui demande d'isoler l'evolution visuelle au seul formulaire
    // de profil.
    'field' => null,
])

@if ($messages)
    <ul @if($field) id="{{ $field }}-error" @endif {{ $attributes->merge(['class' => 'text-sm text-red-600 dark:text-red-400 space-y-1']) }}>
        @foreach ((array) $messages as $message)
            <li>{{ $message }}</li>
        @endforeach
    </ul>
@endif
