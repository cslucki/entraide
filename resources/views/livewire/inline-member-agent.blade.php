<div class="@if(!$showCard) hidden @endif" wire:key="inline-member-agent-{{ $targetUser->id }}">
    @if($showCard)
    <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-5 mb-6">
        <div class="flex items-center gap-3 mb-4">
            <div class="w-8 h-8 rounded-full bg-indigo-100 dark:bg-indigo-900/40 flex items-center justify-center text-indigo-600 dark:text-indigo-400 text-sm font-semibold shrink-0">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                </svg>
            </div>
            <div>
                <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">Agent IA de profil</h3>
                <p class="text-xs text-gray-500 dark:text-gray-400">Posez une question sur ce membre</p>
            </div>
        </div>

        @if($profile->member_profile_summary)
        <p class="text-sm text-gray-700 dark:text-gray-300 mb-4">{{ $profile->member_profile_summary }}</p>
        @endif

        <div class="flex flex-wrap gap-1.5 mb-4">
            @if($profile->skills && count($profile->skills) > 0)
                @foreach($profile->skills as $skill)
                <span class="px-2 py-0.5 bg-purple-100 dark:bg-purple-900/40 text-purple-700 dark:text-purple-300 rounded-full text-xs font-medium">{{ $skill }}</span>
                @endforeach
            @endif
        </div>

        <div class="space-y-3">
            <textarea wire:model="question" rows="2"
                class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 px-3 py-2 text-sm text-gray-900 dark:text-gray-100 placeholder-gray-400 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 transition resize-none"
                placeholder="Compétences, aide proposée, limites, contact…"></textarea>
            @error('question') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror

            <div class="flex items-center justify-between">
                @if($response)
                <div class="flex-1 mr-3">
                    <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">Réponse</p>
                    <div class="text-sm text-gray-800 dark:text-gray-200 whitespace-pre-wrap">{{ $response }}</div>
                </div>
                @endif

                <div class="flex-shrink-0">
                    <button type="button" wire:click="askQuestion" wire:loading.attr="disabled"
                        class="px-4 py-2 bg-indigo-600 text-white text-sm font-semibold rounded-lg hover:bg-indigo-700 transition active:scale-95 shadow-sm flex items-center gap-2 disabled:opacity-50">
                        <svg wire:loading wire:target="askQuestion" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                        </svg>
                        <span wire:loading.remove wire:target="askQuestion">Demander</span>
                        <span wire:loading wire:target="askQuestion">…</span>
                    </button>
                </div>
            </div>
        </div>

        @if($error)
        <p class="text-xs text-red-500 mt-3">{{ $error }}</p>
        @endif
    </div>
    @endif
</div>
