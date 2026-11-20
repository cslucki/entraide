@props([
    'messages',
    // TASK-1871 — le nom du champ decrit. Quand il est donne, ce bloc porte
    // l'`id` que `x-text-input` vise par `aria-describedby`. Sans lui, rien
    // ne change : le composant rend exactement ce qu'il rendait avant.
    'field' => null,
])

@if ($messages)
    <ul @if($field) id="{{ $field }}-error" @endif {{ $attributes->merge(['class' => 'text-sm text-red-600 dark:text-red-400 space-y-1']) }}>
        @foreach ((array) $messages as $message)
            <li>{{ $message }}</li>
        @endforeach
    </ul>
@endif
