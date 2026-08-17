{{--
    « Mes usages IA » (TASK-1223) — transparence, pas FinOps.

    Chaque ligne vient du ledger canonique (TASK-1220), telle quelle :
    un cout mesure s'affiche, un cout absent s'affiche « — », JAMAIS « 0 ».
    Aucun prompt, aucune reponse, aucun document, aucune cle.
--}}
@php
    $cost = static function ($value): string {
        return $value === null ? '—' : '$'.number_format((float) $value, 10);
    };
    $typeLabel = static function ($row): string {
        if ($row->operation === \App\Models\AiProviderInvocation::OPERATION_GENERATION) {
            return __('ai.usage_type_generation');
        }

        return match ($row->embedding_operation) {
            \App\Models\AiProviderInvocation::EMBEDDING_OPERATION_INGESTION => __('ai.usage_type_embedding_ingestion'),
            \App\Models\AiProviderInvocation::EMBEDDING_OPERATION_QUERY => __('ai.usage_type_embedding_query'),
            default => __('ai.usage_type_embedding'),
        };
    };
@endphp

<x-app-layout>
    <x-slot name="title">{{ __('ai.my_ai_usage_title') }}</x-slot>

    <x-page-container>
        <div class="max-w-5xl mx-auto py-8 px-4">
            <div class="mb-6">
                <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">{{ __('ai.my_ai_usage_title') }}</h1>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">{{ __('ai.my_ai_usage_intro') }}</p>
            </div>

            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden" data-my-ai-usage>
                @if($rows->isEmpty())
                    <div class="px-6 py-12 text-center text-gray-400" data-my-ai-usage-empty>
                        {{ __('ai.my_ai_usage_empty') }}
                    </div>
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="bg-gray-50 dark:bg-gray-900/40 text-xs uppercase text-gray-500 dark:text-gray-400">
                                <tr>
                                    <th class="px-4 py-3 text-left">{{ __('ai.usage_col_date') }}</th>
                                    <th class="px-4 py-3 text-left">{{ __('ai.usage_col_action') }}</th>
                                    <th class="px-4 py-3 text-left">{{ __('ai.usage_col_type') }}</th>
                                    <th class="px-4 py-3 text-left">{{ __('ai.usage_col_provider_model') }}</th>
                                    <th class="px-4 py-3 text-right">{{ __('ai.usage_col_tokens') }}</th>
                                    <th class="px-4 py-3 text-right">{{ __('ai.usage_col_cost') }}</th>
                                    <th class="px-4 py-3 text-right">{{ __('ai.usage_col_status') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                                @foreach($rows as $row)
                                    <tr data-my-ai-usage-row>
                                        <td class="px-4 py-3 text-xs text-gray-500 whitespace-nowrap">
                                            {{ $row->created_at?->format('d/m/Y H:i') ?? '—' }}
                                        </td>
                                        <td class="px-4 py-3 text-gray-900 dark:text-gray-100">
                                            {{ $row->capability ?? $row->process ?? '—' }}
                                        </td>
                                        <td class="px-4 py-3 text-gray-600 dark:text-gray-400">
                                            {{ $typeLabel($row) }}
                                        </td>
                                        <td class="px-4 py-3 text-gray-600 dark:text-gray-400 font-mono text-xs">
                                            {{ $row->provider ?? '—' }}{{ $row->model ? ' / '.$row->model : '' }}
                                        </td>
                                        <td class="px-4 py-3 text-right font-mono text-xs text-gray-900 dark:text-gray-100">
                                            {{ $row->total_tokens !== null ? number_format($row->total_tokens) : '—' }}
                                        </td>
                                        <td class="px-4 py-3 text-right font-mono text-xs">
                                            @if($row->cost_status === \App\Models\AiProviderInvocation::COST_KNOWN)
                                                <span class="text-gray-900 dark:text-gray-100">{{ $cost($row->provider_cost) }}</span>
                                            @else
                                                <span class="text-gray-400">—</span>
                                            @endif
                                        </td>
                                        <td class="px-4 py-3 text-right">
                                            @if($row->status === \App\Models\AiProviderInvocation::STATUS_SUCCESS)
                                                <span class="text-xs text-emerald-600 dark:text-emerald-400">{{ __('ai.usage_status_success') }}</span>
                                            @else
                                                <span class="text-xs text-red-500">{{ __('ai.usage_status_failed') }}</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>

            <p class="text-xs text-gray-400 dark:text-gray-500 mt-4" data-my-ai-usage-note>
                {{ __('ai.my_ai_usage_ledger_note') }}
            </p>
        </div>
    </x-page-container>
</x-app-layout>
