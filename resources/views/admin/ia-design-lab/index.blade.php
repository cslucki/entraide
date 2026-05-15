<x-admin-layout title="Lab IA — ChatLoop">
    <div class="max-w-5xl mx-auto space-y-8">

        {{-- Header --}}
        <div class="flex items-start justify-between gap-4">
            <div>
                <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">
                    Lab IA — ChatLoop
                </h2>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                    Assistant de formulation d'action · Test interne avec <code class="text-indigo-600 bg-indigo-50 dark:bg-indigo-900 dark:text-indigo-300 px-1 rounded">FakeAIProvider</code>
                </p>
            </div>
            <span class="text-xs bg-yellow-100 dark:bg-yellow-900 text-yellow-700 dark:text-yellow-300 px-2 py-1 rounded font-medium flex-shrink-0">
                Mode test — rien n'est envoyé ni publié
            </span>
        </div>

        {{-- Input form --}}
        <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-5">
            <form method="POST" action="{{ route('admin.ia-design-lab.test') }}" class="space-y-4">
                @csrf

                <div>
                    <label for="phrase" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">
                        Phrase du membre
                    </label>
                    <textarea name="phrase" id="phrase" rows="3"
                              class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 px-3 py-2 text-sm text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
                              placeholder="Ex : J'ai besoin d'aide pour trouver mes premiers clients.">{{ old('phrase', $inputPhrase ?? '') }}</textarea>
                    @error('phrase')
                    <p class="text-sm text-red-500 mt-1">{{ $message }}</p>
                    @enderror
                </div>

                <div class="flex items-center gap-3">
                    <button type="submit"
                            class="px-5 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold rounded-lg transition">
                        Tester avec Fake AI
                    </button>
                    <a href="{{ route('admin.ia-design-lab') }}"
                       class="text-sm text-gray-500 hover:text-gray-700 dark:hover:text-gray-300 transition">
                        Réinitialiser
                    </a>
                </div>
            </form>
        </div>

        {{-- Quick scenario buttons --}}
        <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-5">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100 mb-3">Scénarios de test rapide</h3>
            <div class="flex flex-wrap gap-2">
                @foreach($scenarios as $key => $scenario)
                    <form method="POST" action="{{ route('admin.ia-design-lab.test') }}" class="inline">
                        @csrf
                        <input type="hidden" name="scenario" value="{{ $key }}">
                        @php
                            $phrases = [
                                'besoin_client_clair' => "J'ai besoin d'aide pour trouver mes premiers clients.",
                                'demande_trop_vague' => 'Je suis bloqué.',
                                'demande_avec_deadline' => 'Je dois trouver quelqu\'un pour relire mon offre avant vendredi.',
                                'mauvais_canal' => 'Je veux vendre mon service à tout le monde.',
                                'donnees_sensibles' => 'Voici le numéro perso d\'un prospect : 06 12 34 56 78.',
                                'loop_ambigue' => 'Je cherche des avis sur mon site et ma stratégie.',
                                'intention_offre' => 'Je peux aider à refaire une page de vente.',
                                'hors_scope' => 'Donne-moi une stratégie juridique pour mon contrat.',
                                'fallback' => 'Une phrase totalement inconnue du système.',
                            ];
                        @endphp
                        <input type="hidden" name="phrase" value="{{ $phrases[$key] ?? 'Phrase de test générique.' }}">
                        <button type="submit"
                                class="text-xs px-3 py-1.5 rounded-lg border border-gray-200 dark:border-gray-600 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700 transition whitespace-nowrap">
                            {{ $scenario['_scenario_label'] }}
                        </button>
                    </form>
                @endforeach
            </div>
        </div>

        {{-- Results --}}
        @isset($result)
            <div class="space-y-6">
                {{-- Preview card --}}
                <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
                    <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700 flex items-center justify-between">
                        <h3 class="font-semibold text-gray-900 dark:text-gray-100">
                            Preview — Demande clarifiée
                        </h3>
                        <span class="text-xs text-gray-400">
                            Scénario : <strong>{{ $result->scenarioLabel }}</strong>
                        </span>
                    </div>

                    <div class="p-5 space-y-5">

                        {{-- Confidence badge --}}
                        <div class="flex items-center gap-3">
                            @php
                                $confColor = $result->isHighConfidence() ? 'bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-300' : ($result->isBlocked() ? 'bg-red-100 text-red-700 dark:bg-red-900 dark:text-red-300' : 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900 dark:text-yellow-300');
                                $confLabel = $result->isHighConfidence() ? 'Confiance haute' : ($result->isLowConfidence() ? 'Confiance faible' : 'Confiance moyenne');
                            @endphp
                            <span class="text-xs font-semibold px-2 py-0.5 rounded {{ $confColor }}">
                                {{ $confLabel }} ({{ number_format($result->confidence * 100, 0) }}%)
                            </span>
                            <span class="text-xs text-gray-400">
                                Intent : <code class="font-mono">{{ $result->intent }}</code>
                            </span>
                        </div>

                        {{-- Title --}}
                        <div>
                            <label class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Titre</label>
                            <p class="text-base font-semibold text-gray-900 dark:text-gray-100 mt-0.5">{{ $result->title }}</p>
                        </div>

                        {{-- Grid --}}
                        <div class="grid md:grid-cols-2 gap-5">
                            <div>
                                <label class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Besoin</label>
                                <p class="text-sm text-gray-800 dark:text-gray-200 mt-0.5">{{ $result->need }}</p>
                            </div>
                            <div>
                                <label class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Contexte</label>
                                <p class="text-sm text-gray-800 dark:text-gray-200 mt-0.5">{{ $result->context }}</p>
                            </div>
                            <div>
                                <label class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Type d'aide attendu</label>
                                <p class="text-sm text-gray-800 dark:text-gray-200 mt-0.5">{{ $result->expectedHelpType }}</p>
                            </div>
                            <div>
                                <label class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Délai</label>
                                <p class="text-sm text-gray-800 dark:text-gray-200 mt-0.5">
                                    @if($result->deadline['has_deadline'])
                                        {{ $result->deadline['label'] ?? 'Date : ' . ($result->deadline['date'] ?? 'Non précisée') }}
                                    @else
                                        <span class="text-gray-400">Non spécifié</span>
                                    @endif
                                </p>
                            </div>
                        </div>

                        {{-- Tone --}}
                        <div>
                            <label class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Ton du message</label>
                            <p class="text-sm text-gray-800 dark:text-gray-200 mt-0.5">
                                <strong>{{ $result->tone['label'] }}</strong>
                                @if(isset($result->tone['rationale']))
                                    — {{ $result->tone['rationale'] }}
                                @endif
                            </p>
                        </div>

                        {{-- Suggested loop --}}
                        <div>
                            <label class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Boucle conseillée</label>
                            @if($result->suggestedLoop)
                                <div class="mt-0.5 flex items-center gap-2">
                                    <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-medium bg-indigo-100 dark:bg-indigo-900 text-indigo-700 dark:text-indigo-300">
                                        <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857A17.983 17.983 0 0112 16c-2.071 0-4.065.332-5.932.943A3 3 0 001 20h5v2a3 3 0 005.356 1.857A17.983 17.983 0 0112 18c2.071 0 4.065.332 5.932.943A3 3 0 0017 20z"/></svg>
                                        {{ $result->suggestedLoop['label'] }}
                                    </span>
                                    <span class="text-xs text-gray-400">{{ $result->suggestedLoop['reason'] }}</span>
                                </div>
                            @else
                                <p class="text-sm text-gray-400 mt-0.5">Aucune Boucle conseillée</p>
                            @endif
                        </div>

                        {{-- Message draft --}}
                        @if($result->messageDraft)
                        <div>
                            <label class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Message proposé</label>
                            <div class="mt-1 p-4 bg-gray-50 dark:bg-gray-900 rounded-lg border border-gray-200 dark:border-gray-700">
                                <p class="text-sm text-gray-800 dark:text-gray-200 italic">{{ $result->messageDraft }}</p>
                            </div>
                        </div>
                        @endif

                        {{-- Fallback --}}
                        @if($result->needsFallback())
                        <div class="p-4 bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-200 dark:border-yellow-700 rounded-lg">
                            <div class="flex items-start gap-3">
                                <svg class="w-5 h-5 text-yellow-600 dark:text-yellow-400 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                                </svg>
                                <div>
                                    <p class="text-sm font-semibold text-yellow-800 dark:text-yellow-300">Fallback activé</p>
                                    <p class="text-sm text-yellow-700 dark:text-yellow-400 mt-0.5">{{ $result->fallback['reason'] }}</p>
                                    @if(!empty($result->fallback['questions']))
                                    <ul class="mt-2 space-y-1">
                                        @foreach($result->fallback['questions'] as $q)
                                        <li class="text-sm text-yellow-700 dark:text-yellow-400 flex items-start gap-2">
                                            <span class="text-yellow-500 mt-0.5">•</span>
                                            {{ $q }}
                                        </li>
                                        @endforeach
                                    </ul>
                                    @endif
                                </div>
                            </div>
                        </div>
                        @endif

                        {{-- Safety --}}
                        @if($result->hasSensitiveData() || $result->isBlocked())
                        <div class="p-4 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-700 rounded-lg">
                            <div class="flex items-start gap-3">
                                <svg class="w-5 h-5 text-red-600 dark:text-red-400 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/>
                                </svg>
                                <div>
                                    <p class="text-sm font-semibold text-red-800 dark:text-red-300">
                                        @if($result->isBlocked()) Demande bloquée @endif
                                        @if($result->hasSensitiveData()) Données sensibles détectées @endif
                                    </p>
                                    @if($result->fallback['reason'] ?? false)
                                    <p class="text-sm text-red-700 dark:text-red-400 mt-0.5">{{ $result->fallback['reason'] }}</p>
                                    @endif
                                </div>
                            </div>
                        </div>
                        @endif

                        {{-- Human validation reminder --}}
                        <div class="p-3 bg-indigo-50 dark:bg-indigo-900/20 border border-indigo-100 dark:border-indigo-800 rounded-lg">
                            <p class="text-sm text-indigo-700 dark:text-indigo-300 flex items-center gap-2">
                                <svg class="w-4 h-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                </svg>
                                Rien n'est envoyé sans votre validation.
                            </p>
                        </div>

                        {{-- Action buttons (test only) --}}
                        <div class="flex items-center gap-3 pt-2 border-t border-gray-100 dark:border-gray-700">
                            <button disabled
                                    class="px-5 py-2 bg-gray-300 dark:bg-gray-600 text-gray-500 dark:text-gray-400 text-sm font-semibold rounded-lg cursor-not-allowed">
                                {{ $result->humanValidation['primary_label'] }} (mode test)
                            </button>
                            <button disabled
                                    class="px-5 py-2 border border-gray-300 dark:border-gray-600 text-gray-400 dark:text-gray-500 text-sm font-semibold rounded-lg cursor-not-allowed">
                                {{ $result->humanValidation['secondary_label'] }} (mode test)
                            </button>
                            <span class="text-xs text-gray-400">Boutons désactivés — preview uniquement</span>
                        </div>

                    </div>
                </div>

                {{-- Raw JSON panel --}}
                <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
                    <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700">
                        <h3 class="font-semibold text-gray-900 dark:text-gray-100">JSON brut</h3>
                    </div>
                    <div class="p-5">
                        <pre class="text-xs text-gray-800 dark:text-gray-200 bg-gray-50 dark:bg-gray-900 p-4 rounded-lg border border-gray-200 dark:border-gray-700 overflow-x-auto max-h-96"><code>{{ json_encode($result->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</code></pre>
                    </div>
                </div>

                {{-- Fallback/safety panel summary --}}
                <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
                    <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700">
                        <h3 class="font-semibold text-gray-900 dark:text-gray-100">Fallback & Safety</h3>
                    </div>
                    <div class="p-5">
                        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                            @php
                                $checks = [
                                    ['label' => 'Confiance >= 65%', 'pass' => $result->isHighConfidence()],
                                    ['label' => 'Fallback nécessaire', 'pass' => !$result->needsFallback()],
                                    ['label' => 'Données sensibles', 'pass' => !$result->hasSensitiveData()],
                                    ['label' => 'Demande autorisée', 'pass' => !$result->isBlocked()],
                                ];
                            @endphp
                            @foreach($checks as $check)
                            <div class="p-3 rounded-lg border {{ $check['pass'] ? 'border-green-200 dark:border-green-800 bg-green-50 dark:bg-green-900/20' : 'border-red-200 dark:border-red-800 bg-red-50 dark:bg-red-900/20' }}">
                                <div class="flex items-center gap-2">
                                    @if($check['pass'])
                                    <svg class="w-4 h-4 text-green-600 dark:text-green-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                    @else
                                    <svg class="w-4 h-4 text-red-600 dark:text-red-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                                    @endif
                                    <span class="text-xs font-medium {{ $check['pass'] ? 'text-green-700 dark:text-green-300' : 'text-red-700 dark:text-red-300' }}">{{ $check['label'] }}</span>
                                </div>
                            </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>
        @else
            {{-- Empty state --}}
            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-12 text-center">
                <svg class="w-12 h-12 text-gray-300 dark:text-gray-600 mx-auto mb-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"/>
                </svg>
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    Saisissez une phrase ou sélectionnez un scénario pour tester le <code class="text-indigo-600 dark:text-indigo-400">FakeAIProvider</code>.
                </p>
            </div>
        @endisset

        {{-- Footer --}}
        <p class="text-xs text-gray-400 text-center pb-6">
            Lab IA interne · Aucun appel externe · Aucune publication · Aucune création en base
        </p>

    </div>
</x-admin-layout>
