@props([
    'messages',
    // TASK-1871 — le nom du champ decrit. Quand il est donne, ce bloc porte
    // l'`id` que `x-text-input` vise par `aria-describedby`. Sans lui, rien
    // ne change : le composant rend exactement ce qu'il rendait avant.
    'field' => null,
])

@if ($messages)
    {{-- TASK-1871 — un pictogramme devant le message.
         Ce bloc sert dix-huit ecrans : il reste donc sobre. Le signe tient
         lieu de redondance pour qui ne distingue pas le rouge du gris, et il
         aligne le texte sur le rail d'etat du champ juste au-dessus. --}}
    <ul @if($field) id="{{ $field }}-error" @endif {{ $attributes->merge(['class' => 'space-y-1 text-sm text-red-600 dark:text-red-400']) }}>
        @foreach ((array) $messages as $message)
            <li class="flex items-start gap-1.5">
                <svg class="mt-0.5 h-3.5 w-3.5 shrink-0" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path fill-rule="evenodd" d="M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16Zm-1-5a1 1 0 1 1 2 0 1 1 0 0 1-2 0Zm.25-7.25a.75.75 0 0 1 1.5 0v4.5a.75.75 0 0 1-1.5 0v-4.5Z" clip-rule="evenodd" />
                </svg>
                <span>{{ $message }}</span>
            </li>
        @endforeach
    </ul>
@endif
