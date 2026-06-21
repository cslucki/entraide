<div class="max-w-4xl mx-auto space-y-6">
    @if($error && ! $profile)
        <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-6 sm:p-8 text-center">
            <div class="w-14 h-14 mx-auto mb-4 bg-gray-100 dark:bg-gray-700 rounded-full flex items-center justify-center">
                <svg class="w-7 h-7 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
            </div>
            <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-1">Profil non disponible</h2>
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ $error }}</p>
        </div>
    @elseif($profile)
        <!-- Member header -->
        <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-6 sm:p-8">
            <div class="flex items-center gap-4">
                <div class="w-12 h-12 rounded-full bg-indigo-100 dark:bg-indigo-900/40 flex items-center justify-center text-indigo-600 dark:text-indigo-400 text-lg font-semibold shrink-0">
                    {{ strtoupper(substr($targetUser->name, 0, 1)) }}
                </div>
                <div>
                    <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ $targetUser->name }}</h2>
                    <p class="text-sm text-gray-500 dark:text-gray-400">Agent IA de présentation</p>
                </div>
            </div>
        </div>

        <!-- Profile data grid -->
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            @if($profile->member_profile_summary)
            <div class="sm:col-span-2 bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-5 sm:p-6">
                <h3 class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400 mb-2">Résumé</h3>
                <p class="text-sm text-gray-800 dark:text-gray-200">{{ $profile->member_profile_summary }}</p>
            </div>
            @endif

            @if($profile->skills && count($profile->skills) > 0)
            <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-5 sm:p-6">
                <h3 class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400 mb-2">Compétences</h3>
                <div class="flex flex-wrap gap-1.5">
                    @foreach($profile->skills as $skill)
                    <span class="px-2.5 py-1 bg-purple-100 dark:bg-purple-900/40 text-purple-700 dark:text-purple-300 rounded-full text-xs font-medium">{{ $skill }}</span>
                    @endforeach
                </div>
            </div>
            @endif

            @if($profile->help_types && count($profile->help_types) > 0)
            <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-5 sm:p-6">
                <h3 class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400 mb-2">Aide proposée</h3>
                <div class="flex flex-wrap gap-1.5">
                    @php $helpOptions = config('member_ai_profile.help_type_options', []); @endphp
                    @foreach($profile->help_types as $type)
                    <span class="px-2.5 py-1 bg-teal-100 dark:bg-teal-900/40 text-teal-700 dark:text-teal-300 rounded-full text-xs font-medium">{{ $helpOptions[$type] ?? $type }}</span>
                    @endforeach
                </div>
            </div>
            @endif

            @if($profile->boundaries && count($profile->boundaries) > 0)
            <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-5 sm:p-6">
                <h3 class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400 mb-2">Limites</h3>
                <div class="flex flex-wrap gap-1.5">
                    @php $boundaryOptions = config('member_ai_profile.boundary_options', []); @endphp
                    @foreach($profile->boundaries as $boundary)
                    <span class="px-2.5 py-1 bg-rose-100 dark:bg-rose-900/40 text-rose-700 dark:text-rose-300 rounded-full text-xs font-medium">{{ $boundaryOptions[$boundary] ?? $boundary }}</span>
                    @endforeach
                </div>
            </div>
            @endif

            @if($profile->preferred_contact_action)
            <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-5 sm:p-6">
                <h3 class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400 mb-2">Contact préféré</h3>
                @php $contactOptions = config('member_ai_profile.contact_options', []); @endphp
                <p class="text-sm text-gray-800 dark:text-gray-200">{{ $contactOptions[$profile->preferred_contact_action] ?? $profile->preferred_contact_action }}</p>
            </div>
            @endif

            @if($profile->tone)
            <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-5 sm:p-6">
                <h3 class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400 mb-2">Ton du profil</h3>
                @php $tones = config('member_ai_profile.tones', []); @endphp
                <p class="text-sm text-gray-800 dark:text-gray-200">{{ $tones[$profile->tone] ?? $profile->tone }}</p>
            </div>
            @endif
        </div>

        <!-- Question input -->
        <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-6 sm:p-8">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100 mb-3">Poser une question sur ce membre</h3>
            <div class="space-y-3">
                <textarea wire:model="question" rows="3"
                    class="w-full rounded-xl border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 px-4 py-3 text-sm text-gray-900 dark:text-gray-100 placeholder-gray-400 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 transition resize-none"
                    placeholder="Que souhaitez-vous savoir sur ce membre ? (compétences, aide proposée, limites, contact…)"></textarea>
                @error('question') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror

                <div class="flex items-center justify-end">
                    <button type="button" wire:click="askQuestion" wire:loading.attr="disabled"
                        class="px-5 py-2.5 bg-indigo-600 text-white text-sm font-semibold rounded-xl hover:bg-indigo-700 transition active:scale-95 shadow-sm flex items-center gap-2 disabled:opacity-50">
                        <svg wire:loading wire:target="askQuestion" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                        </svg>
                        Posez votre question
                    </button>
                </div>
            </div>
        </div>

        <!-- Response area -->
        @if($response)
        <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-6 sm:p-8">
            <h3 class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400 mb-3">Réponse</h3>
            <div class="prose prose-sm max-w-none text-gray-800 dark:text-gray-200 whitespace-pre-wrap">
                {{ $response }}
            </div>
        </div>
        @endif

        @if($error && $profile)
        <div class="bg-red-50 dark:bg-red-900/10 rounded-2xl border border-red-200 dark:border-red-800 p-4 sm:p-6">
            <p class="text-sm text-red-700 dark:text-red-300">{{ $error }}</p>
        </div>
        @endif
    @endif
</div>
