{{--
    TASK-1550 : les DEUX boutons qui ouvrent le formulaire de correction.

    Extraits parce que la mise en page dense les place dans la ligne des méta,
    et la mise en page complète sur une ligne à elle — mais ce sont les mêmes
    boutons, la même action serveur et les mêmes clés. Les dupliquer aurait
    suffi à ce qu'ils divergent au premier correctif appliqué d'un seul côté.

    `$ancre` voyage explicitement dans l'appel : c'est elle qui décide contre
    QUELLE carte figée la soumission se revalidera.
--}}
<button type="button" wire:click="startCorrection('{{ $entry['ref'] }}', 'update', '{{ $ancre }}')" {{ $correctPrefix }}-open-update
        class="rounded-full border border-violet-300 bg-white px-2.5 py-0.5 text-[10px] font-semibold text-violet-700 transition hover:bg-violet-50 dark:border-violet-700 dark:bg-gray-900 dark:text-violet-300 dark:hover:bg-violet-950/40">
    {{ __('loops.correct_action_update') }}
</button>
<button type="button" wire:click="startCorrection('{{ $entry['ref'] }}', 'retract', '{{ $ancre }}')" {{ $correctPrefix }}-open-retract
        class="rounded-full border border-gray-200 bg-white px-2.5 py-0.5 text-[10px] font-semibold text-gray-600 transition hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-300 dark:hover:bg-gray-800">
    {{ __('loops.correct_action_retract') }}
</button>
