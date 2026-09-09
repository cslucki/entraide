{{-- TASK-1487 — le resume, et la SEULE regle qui compte sur cet ecran.

     Tant qu'aucun verdict n'existe, AUCUN pourcentage de qualite n'est
     affiche. « 0 % utile » se lirait « l'IA n'aide personne », alors que la
     verite est « personne n'a jamais ete interroge ». Les deux phrases n'ont
     rien a voir, et le CDC interdit explicitement de les confondre. --}}
<div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5" data-ai-quality-summary>
    @foreach([
        ['key' => 'interactions', 'label' => 'quality_summary_interactions', 'value' => $quality['interactions']],
        ['key' => 'evaluable', 'label' => 'quality_summary_evaluable', 'value' => $quality['evaluable']],
        ['key' => 'evaluated', 'label' => 'quality_summary_evaluated', 'value' => $quality['evaluated']],
        ['key' => 'helpful', 'label' => 'quality_summary_helpful', 'value' => $quality['helpful']],
        ['key' => 'improve', 'label' => 'quality_summary_improve', 'value' => $quality['improve']],
    ] as $cell)
        <div class="rounded-xl border border-gray-200 bg-white p-3 dark:border-gray-700 dark:bg-gray-800" data-ai-quality-cell="{{ $cell['key'] }}">
            <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('ai.'.$cell['label']) }}</p>
            {{-- Les trois derniers comptes ne se rendent que s'ils ont un sens :
                 sans aucun tour evaluable, « 0 juge utile » serait un faux zero. --}}
            <p class="mt-1 text-xl font-semibold tabular-nums text-gray-900 dark:text-gray-100">
                {{ in_array($cell['key'], ['evaluated', 'helpful', 'improve'], true) && $quality['evaluable'] === 0
                    ? '—'
                    : number_format($cell['value']) }}
            </p>
        </div>
    @endforeach
</div>

@if(! $quality['has_any_feedback'])
    <div class="mt-4 rounded-xl border border-amber-200 bg-amber-50 p-4 dark:border-amber-800/50 dark:bg-amber-900/20" data-ai-quality-no-feedback>
        <p class="text-sm font-semibold text-amber-900 dark:text-amber-200">{{ __('ai.quality_no_feedback_title') }}</p>
        <p class="mt-1 text-xs leading-5 text-amber-800 dark:text-amber-300">{{ __('ai.quality_no_feedback_body') }}</p>
    </div>
@endif

{{-- Fiabilite et refus : mesure faite le 2026-09-09, 402 invocations toutes en
     `success` et aucun refus economique journalise. Un bloc « 100 % de
     succes / 0 refus » serait vrai par accident et faux par nature. --}}
<p class="mt-4 text-xs text-gray-500 dark:text-gray-400" data-ai-quality-reliability-unavailable>{{ __('ai.quality_reliability_unavailable') }}</p>
