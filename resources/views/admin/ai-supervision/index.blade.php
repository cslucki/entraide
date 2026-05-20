<x-admin-layout title="Supervision IA">
    <div class="max-w-5xl mx-auto space-y-6">

        {{-- Header --}}
        <div class="flex items-start justify-between gap-4">
            <div>
                <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">
                    Centre de supervision IA
                </h2>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                    Analyse structurée d'un extrait de contenu via OpenAI
                    <code class="text-indigo-600 bg-indigo-50 dark:bg-indigo-900 dark:text-indigo-300 px-1 rounded">{{ $model }}</code>.
                    Aucune donnée n'est conservée côté OpenAI (<code>store: false</code>).
                </p>
            </div>
            @unless($enabled)
                <span class="text-xs bg-red-100 dark:bg-red-900 text-red-700 dark:text-red-300 px-2 py-1 rounded font-medium flex-shrink-0">
                    Désactivé
                </span>
            @endunless
        </div>

        {{-- Errors --}}
        @if (!empty($supervisionError))
            <div class="bg-red-50 dark:bg-red-900/30 border border-red-200 dark:border-red-800 text-red-700 dark:text-red-300 px-4 py-3 rounded-lg text-sm">
                {{ $supervisionError }}
            </div>
        @endif

        @if ($errors->any())
            <div class="bg-red-50 dark:bg-red-900/30 border border-red-200 dark:border-red-800 text-red-700 dark:text-red-300 px-4 py-3 rounded-lg text-sm">
                <ul class="list-disc list-inside space-y-1">
                    @foreach ($errors->all() as $message)
                        <li>{{ $message }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        {{-- Form --}}
        <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-5">
            <form method="POST" action="{{ route('admin.ai-supervision.analyze') }}" class="space-y-4">
                @csrf

                <div>
                    <label for="content" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">
                        Contenu à superviser
                    </label>
                    <textarea name="content" id="content" rows="6" maxlength="5000"
                              class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 px-3 py-2 text-sm text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
                              placeholder="Coller ici un message, une demande ou un post à analyser…"
                              required>{{ old('content', $content ?? '') }}</textarea>
                    <p class="text-xs text-gray-400 mt-1">
                        Limite : 5 000 caractères. La sortie est strictement JSON (schéma <code>json_schema</code> strict).
                    </p>
                </div>

                <div class="flex items-center gap-3">
                    <button type="submit"
                            {{ $enabled ? '' : 'disabled' }}
                            class="px-5 py-2 bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50 disabled:cursor-not-allowed text-white text-sm font-semibold rounded-lg transition">
                        Lancer l'analyse
                    </button>
                    <a href="{{ route('admin.ai-supervision') }}"
                       class="text-sm text-gray-500 hover:text-gray-700 dark:hover:text-gray-300 transition">
                        Réinitialiser
                    </a>
                </div>
            </form>
        </div>

        {{-- Result --}}
        @isset($result)
            @php
                $riskColor = match ($result->riskLevel) {
                    'high' => 'bg-red-100 text-red-700 dark:bg-red-900 dark:text-red-300',
                    'medium' => 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900 dark:text-yellow-300',
                    default => 'bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-300',
                };
                $riskLabel = match ($result->riskLevel) {
                    'high' => 'Risque élevé',
                    'medium' => 'Risque modéré',
                    default => 'Risque faible',
                };
            @endphp

            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
                <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700 flex items-center justify-between gap-3 flex-wrap">
                    <h3 class="font-semibold text-gray-900 dark:text-gray-100">Résultat de supervision</h3>
                    <div class="flex items-center gap-2">
                        <span class="text-xs px-2 py-1 rounded font-medium {{ $riskColor }}">{{ $riskLabel }}</span>
                        @if ($result->moderationFlag)
                            <span class="text-xs px-2 py-1 rounded font-medium bg-orange-100 text-orange-700 dark:bg-orange-900 dark:text-orange-300">
                                Modération suggérée
                            </span>
                        @endif
                    </div>
                </div>

                <div class="p-5 space-y-5 text-sm text-gray-800 dark:text-gray-200">

                    <div>
                        <h4 class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1">Résumé</h4>
                        <p>{{ $result->summary }}</p>
                    </div>

                    <div>
                        <h4 class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-2">Catégorie principale</h4>
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="text-xs px-2 py-1 rounded font-medium bg-indigo-100 dark:bg-indigo-900 text-indigo-700 dark:text-indigo-300">
                                {{ $result->category['label'] ?? $result->category['slug'] }}
                            </span>
                            <code class="text-xs text-gray-400 dark:text-gray-500">{{ $result->category['slug'] }}</code>
                            @if ($result->needsHumanCategoryReview)
                                <span class="text-xs px-2 py-1 rounded font-medium bg-amber-100 text-amber-700 dark:bg-amber-900 dark:text-amber-300">
                                    Validation humaine suggérée
                                </span>
                            @endif
                        </div>
                        @if ($result->needsHumanCategoryReview && $result->categoryReviewReason !== '')
                            <p class="text-xs text-amber-600 dark:text-amber-400 mt-1.5">{{ $result->categoryReviewReason }}</p>
                        @endif
                    </div>

                    @if (!empty($result->skills))
                        <div>
                            <h4 class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-2">Compétences associées</h4>
                            <div class="flex flex-wrap gap-1.5">
                                @foreach ($result->skills as $skill)
                                    <span class="text-xs px-2 py-1 rounded bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300">
                                        {{ $skill['label'] ?? $skill['slug'] }}
                                    </span>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    @if (!empty($result->unmatchedTerms))
                        <div>
                            <h4 class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-2">Termes non mappés</h4>
                            <div class="flex flex-wrap gap-1.5">
                                @foreach ($result->unmatchedTerms as $term)
                                    <span class="text-xs px-2 py-1 rounded border border-gray-300 dark:border-gray-600 text-gray-500 dark:text-gray-400 italic">
                                        {{ $term }}
                                    </span>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    @if (!empty($result->recommendations))
                        <div>
                            <h4 class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-2">Recommandations</h4>
                            <ul class="list-disc list-inside space-y-1">
                                @foreach ($result->recommendations as $recommendation)
                                    <li>{{ $recommendation }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    @if ($result->notes !== '')
                        <div>
                            <h4 class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1">Notes</h4>
                            <p class="text-gray-600 dark:text-gray-400">{{ $result->notes }}</p>
                        </div>
                    @endif
                </div>

                {{-- Telemetry --}}
                <div class="px-5 py-3 border-t border-gray-100 dark:border-gray-700 bg-gray-50 dark:bg-gray-900/40 text-xs text-gray-500 dark:text-gray-400 flex flex-wrap items-center gap-x-6 gap-y-1">
                    <span>Modèle : <strong>{{ $result->model }}</strong></span>
                    <span>Tokens : {{ $result->inputTokens }} in / {{ $result->outputTokens }} out</span>
                    <span>Coût estimé : ${{ number_format($result->estimatedCostUsd, 6) }}</span>
                    <span>Latence : {{ $result->latencyMs }} ms</span>
                </div>
            </div>
        @endisset
    </div>
</x-admin-layout>
