{{-- TASK-1487 (AI Quality Q2) — le tableau, partage par la console
     Organization et la console plateforme.

     UN seul rendu pour les deux : elles repondent a la meme question, avec le
     meme vocabulaire. En ecrire deux les aurait laissees diverger au premier
     changement.

     La regle qui gouverne chaque cellule est celle de TASK-1219, transposee du
     cout a la qualite : « 0 » dit « personne n'a trouve ca utile », « — » dit
     « on ne sait pas ». Une couverture sans denominateur vaut donc `null` et
     se rend « — », jamais « 0 % ». --}}
@php
    $qStatusClass = [
        \App\Support\Ai\AiQualityInstrumentation::STATUS_MEASURED => 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300',
        \App\Support\Ai\AiQualityInstrumentation::STATUS_NO_FEEDBACK_YET => 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300',
        \App\Support\Ai\AiQualityInstrumentation::STATUS_NOT_YET_MEASURABLE => 'bg-sky-100 text-sky-800 dark:bg-sky-900/40 dark:text-sky-300',
        \App\Support\Ai\AiQualityInstrumentation::STATUS_NOT_INSTRUMENTED => 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300',
    ];
@endphp

<div class="overflow-x-auto">
    <table class="w-full text-sm" data-ai-quality-table>
        <thead class="bg-gray-50 dark:bg-gray-700">
            <tr>
                <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('ai.quality_col_feature') }}</th>
                <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('ai.quality_col_interactions') }}</th>
                <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('ai.quality_col_evaluable') }}</th>
                <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('ai.quality_col_evaluated') }}</th>
                <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('ai.quality_col_coverage') }}</th>
                <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('ai.quality_col_status') }}</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
            @foreach($quality['rows'] as $row)
                <tr data-ai-quality-row="{{ $row['feature'] }}" data-ai-quality-status="{{ $row['status'] }}">
                    <td class="px-3 py-2 font-mono text-xs text-gray-900 dark:text-gray-100">{{ $row['feature'] }}</td>
                    <td class="px-3 py-2 text-right tabular-nums text-gray-700 dark:text-gray-300">{{ number_format($row['interactions']) }}</td>
                    <td class="px-3 py-2 text-right tabular-nums text-gray-700 dark:text-gray-300">{{ number_format($row['evaluable']) }}</td>
                    <td class="px-3 py-2 text-right tabular-nums text-gray-700 dark:text-gray-300">{{ number_format($row['evaluated']) }}</td>
                    {{-- Sans denominateur, la couverture n'est pas zero : elle n'existe pas. --}}
                    <td class="px-3 py-2 text-right tabular-nums text-gray-700 dark:text-gray-300" data-ai-quality-coverage>
                        {{ $row['coverage'] === null ? '—' : number_format($row['coverage'] * 100, 0).' %' }}
                    </td>
                    <td class="px-3 py-2">
                        <span class="rounded px-2 py-0.5 text-xs font-semibold {{ $qStatusClass[$row['status']] }}">
                            {{ __('ai.quality_status_'.$row['status']) }}
                        </span>
                        @if($row['status'] === \App\Support\Ai\AiQualityInstrumentation::STATUS_NOT_INSTRUMENTED)
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('ai.quality_status_not_instrumented_hint') }}</p>
                        @elseif($row['status'] === \App\Support\Ai\AiQualityInstrumentation::STATUS_NOT_YET_MEASURABLE)
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                {{ __('ai.quality_status_not_yet_measurable_hint') }}
                                @if($row['since'])
                                    <span class="block">{{ __('ai.quality_since', ['date' => $row['since']->format('d/m/Y H:i')]) }}</span>
                                @endif
                            </p>
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
