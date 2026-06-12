<div x-data="{ showSaved: false }" x-on:profile-saved.window="showSaved = true; setTimeout(() => showSaved = false, 2000)" class="max-w-3xl mx-auto">

    <!-- Step progress bar -->
    <div class="mb-8 sm:mb-10">
        <div class="flex items-center justify-between px-1">
            @foreach($steps as $s)
            <div class="flex items-center">
                <button type="button" wire:click="goToStep({{ $s['number'] }})"
                    class="relative flex items-center justify-center w-11 h-11 sm:w-10 sm:h-10 rounded-full text-sm font-semibold border-2 transition
                        {{ in_array($s['number'], $completedSteps) ? 'bg-indigo-600 border-indigo-600 text-white' : ($step === $s['number'] ? 'border-indigo-500 text-indigo-600' : 'border-gray-300 dark:border-gray-600 text-gray-400 dark:text-gray-500') }}
                        {{ ! in_array($s['number'], $visitedSteps) && $step !== $s['number'] ? 'cursor-default' : 'hover:bg-indigo-50 dark:hover:bg-indigo-900/20' }}">
                    @if(in_array($s['number'], $completedSteps))
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                    @else
                    {{ $s['number'] }}
                    @endif
                </button>
                @if(! $loop->last)
                <div class="w-8 sm:w-20 h-0.5 mx-0.5 sm:mx-2 rounded transition
                    {{ in_array($s['number'], $completedSteps) ? 'bg-indigo-500' : 'bg-gray-200 dark:bg-gray-700' }}">
                </div>
                @endif
            </div>
            @endforeach
        </div>
        <div class="text-center mt-3">
            <p class="text-sm font-medium text-gray-900 dark:text-gray-100">
                {{ $steps[$step - 1]['label'] ?? '' }}
            </p>
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                {{ $steps[$step - 1]['subtitle'] ?? '' }}
            </p>
        </div>
    </div>

    <!-- Auto-save indicator -->
    <div class="flex items-center justify-end h-5 mb-2">
        <div wire:loading.delay wire:target="saveAndContinue,saveDraft,addGoodExample,removeGoodExample,addBadExample,removeBadExample"
            class="flex items-center gap-1.5 text-xs text-gray-400">
            <svg class="w-3.5 h-3.5 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
            Sauvegarde…
        </div>
        <div x-show="showSaved" x-cloak class="flex items-center gap-1 text-xs text-green-600 dark:text-green-400">
            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
            Sauvegardé
        </div>
    </div>

    <!-- Card container -->
    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-6 sm:p-8">

        <!-- Step 1 : Qui êtes-vous ? -->
        @if($step === 1)
        <div class="space-y-6">
            <div>
                <label for="member_profile_summary" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">
                    Présentez-vous en quelques mots
                    <span class="text-red-400">*</span>
                </label>
                <textarea id="member_profile_summary" wire:model="member_profile_summary" rows="4"
                    class="w-full rounded-xl border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 px-4 py-3 text-sm text-gray-900 dark:text-gray-100 placeholder-gray-400 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 transition resize-none"
                    placeholder="Je suis consultant en marketing digital spécialisé dans l'accompagnement des TPE/PME..."></textarea>
                <p class="text-xs text-gray-400 mt-1">{{ strlen($member_profile_summary ?? '') }}/500</p>
                @error('member_profile_summary') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
            </div>

            <div>
                    <span class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">
                        À qui vous adressez-vous ?
                        <span class="text-red-400">*</span>
                    </span>
                <div class="flex flex-wrap gap-2">
                    @foreach($targetAudienceOptions as $value)
                    @if($value !== 'autre')
                    <button type="button" wire:click="toggleTargetAudience('{{ $value }}')"
                        class="px-4 py-2 rounded-full text-sm font-medium border transition active:scale-95
                            {{ in_array($value, $target_audience) ? 'bg-indigo-600 text-white border-indigo-600 shadow-sm' : 'bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-300 border-gray-300 dark:border-gray-600 hover:border-indigo-400' }}">
                        {{ $value }}
                    </button>
                    @endif
                    @endforeach
                </div>
                <div class="mt-3">
                    <input type="text" wire:model.live.debounce.300ms="target_audience_other" placeholder="Autre (précisez)"
                        class="w-full rounded-xl border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 px-4 py-2.5 text-sm text-gray-900 dark:text-gray-100 placeholder-gray-400 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 transition">
                </div>
                @error('target_audience') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="problems_helped_raw" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">
                    Quels types de problèmes aidez-vous à résoudre ?
                    <span class="text-red-400">*</span>
                </label>
                <textarea id="problems_helped_raw" wire:model="problems_helped_raw" rows="4"
                    class="w-full rounded-xl border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 px-4 py-3 text-sm text-gray-900 dark:text-gray-100 placeholder-gray-400 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 transition resize-none"
                    placeholder="Exemples : stratégie de marque, optimisation SEO, création de contenus..."></textarea>
                <p class="text-xs text-gray-400 mt-1">{{ strlen($problems_helped_raw ?? '') }}/1000</p>
                @error('problems_helped_raw') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
            </div>
        </div>

        <!-- Step 2 : Ce que vous apportez -->
        @elseif($step === 2)
        <div class="space-y-6">
            <div>
                <label for="service_scope" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">
                    Dans quel cadre proposez-vous votre aide ?
                    <span class="text-red-400">*</span>
                </label>
                <textarea id="service_scope" wire:model="service_scope" rows="3"
                    class="w-full rounded-xl border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 px-4 py-3 text-sm text-gray-900 dark:text-gray-100 placeholder-gray-400 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 transition resize-none"
                    placeholder="Je peux aider sur des missions ponctuelles ou un accompagnement régulier..."></textarea>
                <p class="text-xs text-gray-400 mt-1">{{ strlen($service_scope ?? '') }}/500</p>
                @error('service_scope') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="skillsInput" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">
                    Quelles sont vos compétences clés ?
                    <span class="text-red-400">*</span>
                </label>
                <input id="skillsInput" type="text" wire:model="skillsInput"
                    class="w-full rounded-xl border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 px-4 py-2.5 text-sm text-gray-900 dark:text-gray-100 placeholder-gray-400 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 transition"
                    placeholder="Marketing digital, SEO, Rédaction web, Stratégie de marque...">
                <p class="text-xs text-gray-400 mt-1">Séparez les compétences par des virgules (max 10)</p>
                @error('skillsInput') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="experience_context" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">
                    Parle-nous de ton expérience
                    <span class="text-red-400">*</span>
                </label>
                <textarea id="experience_context" wire:model="experience_context" rows="3"
                    class="w-full rounded-xl border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 px-4 py-3 text-sm text-gray-900 dark:text-gray-100 placeholder-gray-400 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 transition resize-none"
                    placeholder="J'accompagne des entrepreneurs depuis 5 ans..."></textarea>
                <p class="text-xs text-gray-400 mt-1">{{ strlen($experience_context ?? '') }}/1000</p>
                @error('experience_context') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
            </div>

            <div>
                    <span class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">
                        Sous quelle forme proposez-vous votre aide ?
                        <span class="text-red-400">*</span>
                    </span>
                <div class="flex flex-wrap gap-2">
                    @foreach($helpTypeOptions as $value)
                    <button type="button" wire:click="toggleHelpType('{{ $value }}')"
                        class="px-4 py-2 rounded-full text-sm font-medium border transition active:scale-95
                            {{ in_array($value, $help_types) ? 'bg-indigo-600 text-white border-indigo-600 shadow-sm' : 'bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-300 border-gray-300 dark:border-gray-600 hover:border-indigo-400' }}">
                        {{ $value }}
                    </button>
                    @endforeach
                </div>
                @error('help_types') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
            </div>
        </div>

        <!-- Step 3 : Cadre et limites -->
        @elseif($step === 3)
        <div class="space-y-6">
            <div>
                    <span class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">
                        Quelles sont vos limites ?
                        <span class="text-red-400">*</span>
                    </span>
                <div class="flex flex-wrap gap-2">
                    @foreach($boundaryOptions as $value)
                    <button type="button" wire:click="toggleBoundary('{{ $value }}')"
                        class="px-4 py-2 rounded-full text-sm font-medium border transition active:scale-95
                            {{ in_array($value, $boundaries) ? 'bg-indigo-600 text-white border-indigo-600 shadow-sm' : 'bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-300 border-gray-300 dark:border-gray-600 hover:border-indigo-400' }}">
                        {{ $value }}
                    </button>
                    @endforeach
                </div>
                @error('boundaries') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="preferred_contact_action" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">
                    Comment souhaitez-vous qu'on vous contacte ?
                    <span class="text-red-400">*</span>
                </label>
                <div class="space-y-2">
                    @foreach($contactOptions as $key => $label)
                    <label class="flex items-center gap-3 p-3 rounded-xl border transition cursor-pointer
                        {{ $preferred_contact_action === $key ? 'border-indigo-500 bg-indigo-50/50 dark:bg-indigo-900/20' : 'border-gray-200 dark:border-gray-700 hover:border-gray-300 dark:hover:border-gray-600' }}">
                        <input type="radio" name="preferred_contact_action" value="{{ $key }}" wire:model="preferred_contact_action"
                            class="w-4 h-4 text-indigo-600 border-gray-300 focus:ring-indigo-500">
                        <span class="text-sm text-gray-700 dark:text-gray-300">{{ $label }}</span>
                    </label>
                    @endforeach
                </div>
                @error('preferred_contact_action') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="tone" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">
                    Quel ton souhaitez-vous pour votre profil ?
                    <span class="text-red-400">*</span>
                </label>
                <div class="space-y-2">
                    @foreach($tones as $key => $label)
                    <label class="flex items-center gap-3 p-3 rounded-xl border transition cursor-pointer
                        {{ $tone === $key ? 'border-indigo-500 bg-indigo-50/50 dark:bg-indigo-900/20' : 'border-gray-200 dark:border-gray-700 hover:border-gray-300 dark:hover:border-gray-600' }}">
                        <input type="radio" name="tone" value="{{ $key }}" wire:model="tone"
                            class="w-4 h-4 text-indigo-600 border-gray-300 focus:ring-indigo-500">
                        <span class="text-sm text-gray-700 dark:text-gray-300">{{ $label }}</span>
                    </label>
                    @endforeach
                </div>
                @error('tone') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
            </div>
        </div>

        <!-- Step 4 : Exemples -->
        @elseif($step === 4)
        <div class="space-y-6">
            <div>
                <span class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">
                    Exemples de demandes pertinentes
                    <span class="text-red-400">*</span>
                </span>
                <p class="text-xs text-gray-500 dark:text-gray-400 mb-3">Donnez des exemples de demandes qui correspondent bien à votre offre.</p>
                <div class="space-y-2 mb-3">
                    @foreach($good_request_examples as $idx => $example)
                    <div class="flex items-start gap-2 p-3 bg-green-50 dark:bg-green-900/10 rounded-xl border border-green-200 dark:border-green-800">
                        <svg class="w-5 h-5 text-green-500 mt-0.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        <p class="text-sm text-gray-700 dark:text-gray-300 flex-1">{{ $example }}</p>
                        <button type="button" wire:click="removeGoodExample({{ $idx }})" class="text-gray-400 hover:text-red-500 transition p-1">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                        </button>
                    </div>
                    @endforeach
                </div>
                @if(count($good_request_examples) < 3)
                <div class="flex gap-2">
                    <input type="text" wire:model="goodExampleInput" wire:keydown.enter="addGoodExample"
                        class="flex-1 rounded-xl border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 px-4 py-2.5 text-sm text-gray-900 dark:text-gray-100 placeholder-gray-400 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 transition"
                        placeholder="Exemple de demande adaptée…">
                    <button type="button" wire:click="addGoodExample"
                        class="px-4 py-2.5 bg-indigo-600 text-white text-sm font-medium rounded-xl hover:bg-indigo-700 transition active:scale-95">
                        Ajouter
                    </button>
                </div>
                @endif
                <p class="text-xs text-gray-400 mt-2">{{ count($good_request_examples) }}/3 exemples</p>
                @error('good_request_examples') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
            </div>

            <div>
                <span class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">
                    Exemples de demandes NON pertinentes
                </span>
                <p class="text-xs text-gray-500 dark:text-gray-400 mb-3">Donnez des contre-exemples pour clarifier votre périmètre.</p>
                <div class="space-y-2 mb-3">
                    @foreach($bad_request_examples as $idx => $example)
                    <div class="flex items-start gap-2 p-3 bg-red-50 dark:bg-red-900/10 rounded-xl border border-red-200 dark:border-red-800">
                        <svg class="w-5 h-5 text-red-400 mt-0.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        <p class="text-sm text-gray-700 dark:text-gray-300 flex-1">{{ $example }}</p>
                        <button type="button" wire:click="removeBadExample({{ $idx }})" class="text-gray-400 hover:text-red-500 transition p-1">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                        </button>
                    </div>
                    @endforeach
                </div>
                @if(count($bad_request_examples) < 3)
                <div class="flex gap-2">
                    <input type="text" wire:model="badExampleInput" wire:keydown.enter="addBadExample"
                        class="flex-1 rounded-xl border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 px-4 py-2.5 text-sm text-gray-900 dark:text-gray-100 placeholder-gray-400 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 transition"
                        placeholder="Contre-exemple…">
                    <button type="button" wire:click="addBadExample"
                        class="px-4 py-2.5 bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-300 text-sm font-medium rounded-xl border border-gray-300 dark:border-gray-600 hover:bg-gray-50 dark:hover:bg-gray-700 transition active:scale-95">
                        Ajouter
                    </button>
                </div>
                @endif
                <p class="text-xs text-gray-400 mt-2">{{ count($bad_request_examples) }}/3 exemples</p>
            </div>
        </div>

        <!-- Step 5 : Relecture et publication -->
        @elseif($step === 5)
        <div class="space-y-6">
            <div class="text-center">
                <div class="w-16 h-16 mx-auto mb-4 bg-indigo-100 dark:bg-indigo-900/40 rounded-full flex items-center justify-center">
                    <svg class="w-8 h-8 text-indigo-600 dark:text-indigo-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                </div>
                <h2 class="text-xl font-semibold text-gray-900 dark:text-gray-100">Votre profil est presque prêt</h2>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Vérifiez vos réponses avant de publier.</p>
            </div>

            <div class="space-y-4">
                <div class="p-4 bg-gray-50 dark:bg-gray-900/50 rounded-xl">
                    <h3 class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400 mb-2">Présentation</h3>
                    <p class="text-sm text-gray-900 dark:text-gray-100">{{ $member_profile_summary ?? '—' }}</p>
                </div>

                <div class="p-4 bg-gray-50 dark:bg-gray-900/50 rounded-xl">
                    <h3 class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400 mb-2">Public cible</h3>
                    <div class="flex flex-wrap gap-1.5">
                        @forelse($targetAudienceOptions as $value)
                        @if(in_array($value, $target_audience))
                        <span class="px-2.5 py-1 bg-indigo-100 dark:bg-indigo-900/40 text-indigo-700 dark:text-indigo-300 rounded-full text-xs font-medium">{{ $value }}</span>
                        @endif
                        @empty
                        @endforelse
                        @if($target_audience_other)
                        <span class="px-2.5 py-1 bg-amber-100 dark:bg-amber-900/40 text-amber-700 dark:text-amber-300 rounded-full text-xs font-medium">{{ $target_audience_other }}</span>
                        @endif
                        @if(empty($target_audience) && !$target_audience_other) <span class="text-sm text-gray-400">—</span> @endif
                    </div>
                </div>

                <div class="p-4 bg-gray-50 dark:bg-gray-900/50 rounded-xl">
                    <h3 class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400 mb-2">Problèmes résolus</h3>
                    <div class="flex flex-wrap gap-1.5">
                        @forelse(explode("\n", $problems_helped_raw ?? '') as $problem)
                        @if(trim($problem))
                        <span class="px-2.5 py-1 bg-cyan-100 dark:bg-cyan-900/40 text-cyan-700 dark:text-cyan-300 rounded-full text-xs font-medium">{{ trim($problem) }}</span>
                        @endif
                        @empty
                        <span class="text-sm text-gray-400">—</span>
                        @endforelse
                    </div>
                </div>

                <div class="p-4 bg-gray-50 dark:bg-gray-900/50 rounded-xl">
                    <h3 class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400 mb-2">Cadre d'intervention</h3>
                    <p class="text-sm text-gray-900 dark:text-gray-100">{{ $service_scope ?? '—' }}</p>
                </div>

                <div class="p-4 bg-gray-50 dark:bg-gray-900/50 rounded-xl">
                    <h3 class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400 mb-2">Compétences</h3>
                    <div class="flex flex-wrap gap-1.5">
                        @forelse(array_filter(array_map('trim', explode(',', $skillsInput))) as $skill)
                        <span class="px-2.5 py-1 bg-purple-100 dark:bg-purple-900/40 text-purple-700 dark:text-purple-300 rounded-full text-xs font-medium">{{ $skill }}</span>
                        @empty
                        <span class="text-sm text-gray-400">—</span>
                        @endforelse
                    </div>
                </div>

                <div class="p-4 bg-gray-50 dark:bg-gray-900/50 rounded-xl">
                    <h3 class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400 mb-2">Expérience</h3>
                    <p class="text-sm text-gray-900 dark:text-gray-100">{{ $experience_context ?? '—' }}</p>
                </div>

                <div class="p-4 bg-gray-50 dark:bg-gray-900/50 rounded-xl">
                    <h3 class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400 mb-2">Types d'aide</h3>
                    <div class="flex flex-wrap gap-1.5">
                        @forelse($helpTypeOptions as $value)
                        @if(in_array($value, $help_types))
                        <span class="px-2.5 py-1 bg-teal-100 dark:bg-teal-900/40 text-teal-700 dark:text-teal-300 rounded-full text-xs font-medium">{{ $value }}</span>
                        @endif
                        @empty
                        @endforelse
                        @if(empty($help_types)) <span class="text-sm text-gray-400">—</span> @endif
                    </div>
                </div>

                <div class="p-4 bg-gray-50 dark:bg-gray-900/50 rounded-xl">
                    <h3 class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400 mb-2">Limites</h3>
                    <div class="flex flex-wrap gap-1.5">
                        @forelse($boundaryOptions as $value)
                        @if(in_array($value, $boundaries))
                        <span class="px-2.5 py-1 bg-rose-100 dark:bg-rose-900/40 text-rose-700 dark:text-rose-300 rounded-full text-xs font-medium">{{ $value }}</span>
                        @endif
                        @empty
                        @endforelse
                        @if(empty($boundaries)) <span class="text-sm text-gray-400">—</span> @endif
                    </div>
                </div>

                <div class="p-4 bg-gray-50 dark:bg-gray-900/50 rounded-xl">
                    <h3 class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400 mb-2">Contact préféré</h3>
                    <p class="text-sm text-gray-900 dark:text-gray-100">{{ $contactOptions[$preferred_contact_action] ?? $preferred_contact_action ?? '—' }}</p>
                </div>

                <div class="p-4 bg-gray-50 dark:bg-gray-900/50 rounded-xl">
                    <h3 class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400 mb-2">Ton</h3>
                    <p class="text-sm text-gray-900 dark:text-gray-100">{{ $tones[$tone] ?? $tone ?? '—' }}</p>
                </div>

                <div class="p-4 bg-gray-50 dark:bg-gray-900/50 rounded-xl">
                    <h3 class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400 mb-2">Exemples pertinents</h3>
                    <div class="space-y-1.5">
                        @forelse($good_request_examples as $ex)
                        <div class="flex items-start gap-2 text-sm text-gray-700 dark:text-gray-300">
                            <svg class="w-4 h-4 text-green-500 mt-0.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                            <span>{{ $ex }}</span>
                        </div>
                        @empty
                        <span class="text-sm text-gray-400">—</span>
                        @endforelse
                    </div>
                </div>

                @if(!empty($bad_request_examples))
                <div class="p-4 bg-gray-50 dark:bg-gray-900/50 rounded-xl">
                    <h3 class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400 mb-2">Contre-exemples</h3>
                    <div class="space-y-1.5">
                        @foreach($bad_request_examples as $ex)
                        <div class="flex items-start gap-2 text-sm text-gray-700 dark:text-gray-300">
                            <svg class="w-4 h-4 text-red-400 mt-0.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                            <span>{{ $ex }}</span>
                        </div>
                        @endforeach
                    </div>
                </div>
                @endif
            </div>

            <!-- Publish section -->
            @if($profile && $profile->status === 'pending_validation')
            <div class="text-center p-4 bg-amber-50 dark:bg-amber-900/10 rounded-xl border border-amber-200 dark:border-amber-800">
                <p class="text-sm text-amber-700 dark:text-amber-300 font-medium">En attente de validation</p>
                <p class="text-xs text-amber-600 dark:text-amber-400 mt-1">Un administrateur doit valider votre profil avant publication.</p>
            </div>
            @elseif($profile && $profile->status === 'published')
            <div class="text-center p-4 bg-green-50 dark:bg-green-900/10 rounded-xl border border-green-200 dark:border-green-800">
                <p class="text-sm text-green-700 dark:text-green-300 font-medium">Profil publié ✓</p>
                <p class="text-xs text-green-600 dark:text-green-400 mt-1">Votre profil IA est visible sur la plateforme.</p>
            </div>
            @endif
        </div>
        @endif
    </div>

    <!-- Actions footer -->
    <div class="flex flex-col-reverse sm:flex-row items-stretch sm:items-center justify-between mt-6 gap-3">
        <div class="flex justify-center sm:justify-start">
            @if($step > 1)
            <button type="button" wire:click="previousStep"
                class="px-5 py-3 sm:py-2.5 text-sm font-medium text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-100 transition flex items-center justify-center gap-1.5">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                Retour
            </button>
            @endif
        </div>

        <div class="flex flex-col-reverse sm:flex-row items-stretch sm:items-center gap-2 sm:gap-3">
            <button type="button" wire:click="saveDraft"
                class="px-4 py-3 sm:py-2.5 text-sm font-medium text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-100 border border-gray-200 dark:border-gray-700 rounded-xl hover:bg-gray-50 dark:hover:bg-gray-800 transition active:scale-95 text-center">
                Brouillon
            </button>

            @if($step < 5)
            <button type="button" wire:click="saveAndContinue"
                class="px-6 py-3 sm:py-2.5 bg-indigo-600 text-white text-sm font-semibold rounded-xl hover:bg-indigo-700 transition active:scale-95 shadow-sm text-center">
                Continuer
            </button>
            @elseif($step === 5 && $profile && $profile->status !== 'published' && $profile->status !== 'pending_validation')
            <button type="button" wire:click="publish"
                class="px-6 py-3 sm:py-2.5 bg-indigo-600 text-white text-sm font-semibold rounded-xl hover:bg-indigo-700 transition active:scale-95 shadow-sm text-center">
                Publier
            </button>
            @endif
        </div>
    </div>

</div>
