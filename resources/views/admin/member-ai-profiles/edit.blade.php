<x-admin-layout title="Modifier agent profil IA">
    @php
        $statusLabels = [
            'draft' => 'Brouillon',
            'ready_for_generation' => 'Prêt génération',
            'generated' => 'Généré',
            'pending_validation' => 'En validation',
            'published' => 'Publié',
            'disabled' => 'Désactivé',
        ];
        $asLines = fn ($value) => implode("\n", is_array($value) ? $value : array_filter([(string) $value]));
    @endphp

    <div class="mx-auto max-w-5xl space-y-6">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <a href="{{ route('admin.member-ai-profiles') }}" class="text-sm text-indigo-600 hover:underline dark:text-indigo-400">← Retour aux agents profil IA</a>
                <h2 class="mt-2 text-lg font-semibold text-gray-900 dark:text-gray-100">
                    {{ $profile->user?->full_name ?? 'Utilisateur supprimé' }}
                </h2>
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    {{ $profile->organization?->name ?? 'Organisation inconnue' }} · {{ $profile->user?->email ?? '—' }}
                </p>
            </div>

            <div class="flex flex-wrap gap-2">
                @if($profile->status !== 'published')
                    <form method="POST" action="{{ route('admin.member-ai-profiles.publish', $profile) }}">
                        @csrf
                        @method('PATCH')
                        <button type="submit" class="rounded-lg bg-green-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-green-700">Valider et publier</button>
                    </form>
                @endif
                @if($profile->status !== 'disabled')
                    <form method="POST" action="{{ route('admin.member-ai-profiles.disable', $profile) }}">
                        @csrf
                        @method('PATCH')
                        <button type="submit" class="rounded-lg bg-red-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-red-700">Désactiver</button>
                    </form>
                @endif
            </div>
        </div>

        @if($errors->any())
            <div class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-200">
                <div class="font-medium">Certains champs sont invalides.</div>
                <ul class="mt-2 list-inside list-disc space-y-1">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="rounded-2xl border border-indigo-100 bg-indigo-50/70 p-5 shadow-sm dark:border-indigo-900/60 dark:bg-indigo-950/30">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h3 class="text-base font-semibold text-gray-900 dark:text-gray-100">Tester avec un LLM</h3>
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
                        Simulez la conversation de la fiche membre avec un provider actif. Ce test admin n'est pas une conversation persistante.
                    </p>
                </div>
                <a href="{{ route('admin.ai-config') }}" class="text-sm font-medium text-indigo-700 hover:underline dark:text-indigo-300">Configuration IA</a>
            </div>

            @if(empty($providers))
                <div class="mt-4 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-200">
                    Aucun provider IA actif. Activez Ollama ou OpenRouter dans la configuration IA.
                </div>
            @else
                <form method="POST" action="{{ route('admin.member-ai-profiles.test-llm', $profile) }}" class="mt-5 space-y-4">
                    @csrf
                    <div class="grid gap-4 md:grid-cols-2">
                        <div>
                            <label for="llm_provider" class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Provider</label>
                            <select id="llm_provider" name="provider" class="w-full rounded-lg border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                                @foreach($providers as $key => $provider)
                                    <option value="{{ $key }}" @selected(old('provider', $selectedProvider) === $key)>{{ $provider['label'] }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div>
                            <label for="llm_model" class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Modèle</label>
                            <select id="llm_model" name="model" class="w-full rounded-lg border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100"></select>
                        </div>
                    </div>

                    <div>
                        <label for="llm_question" class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Question de test</label>
                        <textarea id="llm_question" name="question" rows="3" class="w-full rounded-xl border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">{{ old('question', $testQuestion) }}</textarea>
                    </div>

                    <div class="flex justify-end">
                        <button type="submit" class="rounded-lg bg-indigo-600 px-5 py-2 text-sm font-medium text-white transition hover:bg-indigo-700">Tester la réponse</button>
                    </div>
                </form>

                @if($llmTest)
                    <div class="mt-5 rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
                        <div class="mb-4 flex flex-wrap items-center gap-2 text-xs text-gray-500 dark:text-gray-400">
                            <span class="rounded-full bg-gray-100 px-2 py-1 dark:bg-gray-800">{{ $llmTest['providerLabel'] ?? $llmTest['provider'] ?? 'Provider' }}</span>
                            <span class="rounded-full bg-gray-100 px-2 py-1 font-mono dark:bg-gray-800">{{ $llmTest['model'] ?? '—' }}</span>
                            @isset($llmTest['latencyMs'])
                                <span class="rounded-full bg-gray-100 px-2 py-1 dark:bg-gray-800">{{ $llmTest['latencyMs'] }} ms</span>
                            @endisset
                        </div>

                        @if(($llmTest['question'] ?? null))
                            <div class="flex justify-end">
                                <div class="max-w-2xl rounded-2xl rounded-tr-sm bg-indigo-600 px-4 py-3 text-sm text-white shadow-sm">
                                    {{ $llmTest['question'] }}
                                </div>
                            </div>
                        @endif

                        <div class="mt-3 flex justify-start">
                            <div class="max-w-3xl rounded-2xl rounded-tl-sm {{ ($llmTest['status'] ?? null) === 'error' ? 'bg-red-50 text-red-800 dark:bg-red-950 dark:text-red-200' : 'bg-gray-100 text-gray-800 dark:bg-gray-800 dark:text-gray-100' }} px-4 py-3 text-sm leading-relaxed shadow-sm">
                                {!! nl2br(e($llmTest['answer'] ?? $llmTest['error'] ?? 'Aucune réponse.')) !!}
                            </div>
                        </div>
                    </div>
                @endif
            @endif
        </div>

        <form method="POST" action="{{ route('admin.member-ai-profiles.update', $profile) }}" class="space-y-6">
            @csrf
            @method('PUT')

            <div class="rounded-xl border border-gray-200 bg-white p-5 dark:border-gray-700 dark:bg-gray-800">
                <div class="grid gap-5 md:grid-cols-2">
                    <div>
                        <label for="status" class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Statut</label>
                        <select id="status" name="status" class="w-full rounded-lg border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                            @foreach($statuses as $status)
                                <option value="{{ $status }}" @selected(old('status', $profile->status) === $status)>{{ $statusLabels[$status] ?? $status }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label for="tone" class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Ton</label>
                        <input id="tone" type="text" name="tone" value="{{ old('tone', $profile->tone) }}" class="w-full rounded-lg border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                    </div>

                    <div>
                        <label for="preferred_contact_action" class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Action de contact préférée</label>
                        <input id="preferred_contact_action" type="text" name="preferred_contact_action" value="{{ old('preferred_contact_action', $profile->preferred_contact_action) }}" class="w-full rounded-lg border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                    </div>

                    <div class="text-sm text-gray-500 dark:text-gray-400">
                        <div>Validé: {{ $profile->validated_at?->format('d/m/Y H:i') ?? '—' }}</div>
                        <div>Publié: {{ $profile->published_at?->format('d/m/Y H:i') ?? '—' }}</div>
                        <div>Désactivé: {{ $profile->disabled_at?->format('d/m/Y H:i') ?? '—' }}</div>
                    </div>
                </div>
            </div>

            <div class="rounded-xl border border-gray-200 bg-white p-5 dark:border-gray-700 dark:bg-gray-800">
                <div class="space-y-5">
                    <div>
                        <label for="member_profile_summary" class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Résumé du profil membre</label>
                        <textarea id="member_profile_summary" name="member_profile_summary" rows="4" class="w-full rounded-lg border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">{{ old('member_profile_summary', $profile->member_profile_summary) }}</textarea>
                    </div>

                    <div>
                        <label for="service_scope" class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Périmètre d'aide</label>
                        <textarea id="service_scope" name="service_scope" rows="4" class="w-full rounded-lg border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">{{ old('service_scope', $profile->service_scope) }}</textarea>
                    </div>

                    <div>
                        <label for="experience_context" class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Contexte d'expérience</label>
                        <textarea id="experience_context" name="experience_context" rows="5" class="w-full rounded-lg border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">{{ old('experience_context', $profile->experience_context) }}</textarea>
                    </div>

                    <div>
                        <label for="generated_summary" class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Résumé généré</label>
                        <textarea id="generated_summary" name="generated_summary" rows="4" class="w-full rounded-lg border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">{{ old('generated_summary', $profile->generated_summary) }}</textarea>
                    </div>
                </div>
            </div>

            <div class="grid gap-5 md:grid-cols-2">
                <div class="rounded-xl border border-gray-200 bg-white p-5 dark:border-gray-700 dark:bg-gray-800">
                    <label for="skills" class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Compétences</label>
                    <textarea id="skills" name="skills" rows="6" class="w-full rounded-lg border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">{{ old('skills', $asLines($profile->skills)) }}</textarea>
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Une entrée par ligne ou séparée par virgule.</p>
                </div>

                <div class="rounded-xl border border-gray-200 bg-white p-5 dark:border-gray-700 dark:bg-gray-800">
                    <label for="help_types" class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Types d'aide</label>
                    <textarea id="help_types" name="help_types" rows="6" class="w-full rounded-lg border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">{{ old('help_types', $asLines($profile->help_types)) }}</textarea>
                </div>

                <div class="rounded-xl border border-gray-200 bg-white p-5 dark:border-gray-700 dark:bg-gray-800">
                    <label for="boundaries" class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Limites</label>
                    <textarea id="boundaries" name="boundaries" rows="6" class="w-full rounded-lg border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">{{ old('boundaries', $asLines($profile->boundaries)) }}</textarea>
                </div>

                <div class="rounded-xl border border-gray-200 bg-white p-5 dark:border-gray-700 dark:bg-gray-800">
                    <label for="good_request_examples" class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Bons exemples de demande</label>
                    <textarea id="good_request_examples" name="good_request_examples" rows="6" class="w-full rounded-lg border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">{{ old('good_request_examples', $asLines($profile->good_request_examples)) }}</textarea>
                </div>

                <div class="rounded-xl border border-gray-200 bg-white p-5 md:col-span-2 dark:border-gray-700 dark:bg-gray-800">
                    <label for="bad_request_examples" class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Demandes hors périmètre</label>
                    <textarea id="bad_request_examples" name="bad_request_examples" rows="5" class="w-full rounded-lg border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">{{ old('bad_request_examples', $asLines($profile->bad_request_examples)) }}</textarea>
                </div>
            </div>

            <div class="flex items-center justify-between">
                <a href="{{ route('admin.member-ai-profiles') }}" class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 transition hover:bg-gray-50 dark:border-gray-600 dark:text-gray-300 dark:hover:bg-gray-700">Annuler</a>
                <button type="submit" class="rounded-lg bg-indigo-600 px-5 py-2 text-sm font-medium text-white transition hover:bg-indigo-700">Enregistrer</button>
            </div>
        </form>
    </div>

    @push('scripts')
        <script>
            (() => {
                const providerSelect = document.getElementById('llm_provider');
                const modelSelect = document.getElementById('llm_model');
                const providers = @json($providers);
                const selectedModel = @json(old('model', $selectedModel));

                if (!providerSelect || !modelSelect) return;

                function refreshModels() {
                    const models = providers[providerSelect.value]?.models || {};
                    modelSelect.innerHTML = '';

                    for (const [value, label] of Object.entries(models)) {
                        const option = document.createElement('option');
                        option.value = value;
                        option.textContent = label;
                        option.selected = value === selectedModel;
                        modelSelect.appendChild(option);
                    }
                }

                providerSelect.addEventListener('change', refreshModels);
                refreshModels();
            })();
        </script>
    @endpush
</x-admin-layout>
