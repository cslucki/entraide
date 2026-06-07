@php $currentLoop = $loop; @endphp
@php $analysis = session('help_request_analysis'); @endphp

@push('head')
<style>
    @media (max-width: 767px) {
        body:has(.loops-show-container) > header[class*="fixed"],
        body:has(.loops-show-container) > nav[class*="fixed"],
        body:has(.loops-show-container) > [class*="md:hidden"]:has([class*="fixed"]) {
            display: none !important;
        }
        body:has(.loops-show-container) > .min-h-screen {
            padding-top: 0 !important;
            padding-bottom: 0 !important;
        }
        body:has(.loops-show-container) .min-h-screen > footer {
            display: none !important;
        }
        body:has(.loops-show-container) .loops-show-wrapper {
            padding: 0 !important;
        }
    }
    @media (min-width: 768px) {
        .loops-show-container {
            height: calc(100vh - 5rem);
        }
    }
</style>
@endpush

<x-app-layout :title="$currentLoop->name">
    <x-page-container class="loops-show-wrapper">
        <div class="loops-show-container h-dvh flex flex-col bg-white dark:bg-gray-800">

        {{-- Topbar --}}
        <div class="flex items-center gap-3 px-4 py-3 border-b border-gray-200 dark:border-gray-700 flex-shrink-0">
            <a href="{{ route('loops.index') }}"
               class="inline-flex h-9 w-9 items-center justify-center rounded-full bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-600 transition"
               aria-label="Retour aux boucles">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
                </svg>
            </a>
            <div class="flex-1 min-w-0">
                <h1 class="text-lg font-semibold text-gray-900 dark:text-gray-100 truncate">{{ $currentLoop->name }}</h1>
                @if($currentLoop->description)
                    <p class="text-xs text-gray-500 dark:text-gray-400 truncate">{{ $currentLoop->description }}</p>
                @endif
            </div>
            <span class="flex-shrink-0 inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-medium {{ $currentLoop->isPublic() ? 'bg-green-50 dark:bg-green-900/20 text-green-700 dark:text-green-300' : 'bg-gray-100 dark:bg-gray-700 text-gray-500 dark:text-gray-400' }}">
                <span class="w-1.5 h-1.5 rounded-full {{ $currentLoop->isPublic() ? 'bg-green-500' : 'bg-gray-400' }}"></span>
                <span>{{ $currentLoop->isPublic() ? 'Publique' : 'Privée' }}</span>
            </span>
        </div>

        {{-- Session messages --}}
        @if(session('success') && session('success') !== 'Message envoyé.')
            <div x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 3000)"
                 class="flex-shrink-0 bg-green-50 dark:bg-green-900/20 border-b border-green-200 dark:border-green-800 text-green-700 dark:text-green-300 px-4 py-2 text-sm">
                {{ session('success') }}
            </div>
        @endif
        @if(session('error'))
            <div x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 4000)"
                 class="flex-shrink-0 bg-red-50 dark:bg-red-900/20 border-b border-red-200 dark:border-red-800 text-red-700 dark:text-red-300 px-4 py-2 text-sm">
                {{ session('error') }}
            </div>
        @endif
        @if(session('help_request_error'))
            <div x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 6000)"
                 class="flex-shrink-0 bg-red-50 dark:bg-red-900/20 border-b border-red-200 dark:border-red-800 text-red-700 dark:text-red-300 px-4 py-2 text-sm">
                {{ session('help_request_error') }}
            </div>
        @endif

        {{-- Messages + Composer (Livewire) --}}
        @livewire('loop-chat', ['loop' => $loop], key('loop-chat-'.$loop->id))

        {{-- Composer --}}
        <div class="flex-shrink-0 border-t border-gray-200 dark:border-gray-700">
            @if(!$isMember && $currentLoop->isPublic())
                <div class="px-4 py-3">
                    <form method="POST" action="{{ route('loops.join', $currentLoop) }}">
                        @csrf
                        <button type="submit"
                            class="w-full flex items-center justify-center gap-2 px-4 py-3 text-sm font-semibold text-white bg-indigo-600 hover:bg-indigo-700 rounded-xl transition">
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"/>
                            </svg>
                            Rejoindre cette boucle
                        </button>
                    </form>
                </div>

            @elseif($analysis)
                <div class="px-4 py-3 space-y-4">
                    <div class="flex items-center gap-2">
                        <svg class="w-5 h-5 text-amber-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"/>
                        </svg>
                        <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">Votre demande clarifiée</h3>
                    </div>

                    @php
                        $deadline = $analysis['deadline'] ?? [];
                        $tone = $analysis['tone'] ?? [];
                        $suggestedLoop = $analysis['suggested_loop'] ?? null;
                        $needsFallback = $analysis['fallback']['needed'] ?? false;
                        $fallbackReason = $analysis['fallback']['reason'] ?? null;
                        $fallbackQuestions = $analysis['fallback']['questions'] ?? [];
                    @endphp

                    @if($needsFallback)
                        <div class="bg-orange-50 dark:bg-orange-900/20 border border-orange-200 dark:border-orange-700/50 rounded-lg p-3 text-sm text-orange-700 dark:text-orange-300">
                            <p class="font-medium mb-1">Précision nécessaire</p>
                            <p>{{ $fallbackReason }}</p>
                            @if(count($fallbackQuestions))
                                <ul class="list-disc list-inside mt-1 space-y-0.5">
                                    @foreach($fallbackQuestions as $q)
                                        <li>{{ $q }}</li>
                                    @endforeach
                                </ul>
                            @endif
                        </div>
                    @endif

                    <form method="POST" action="{{ route('loops.help-request.publish', $currentLoop) }}" class="space-y-3">
                        @csrf
                        <div>
                            <label for="hr-title" class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Titre</label>
                            <input type="text" name="title" id="hr-title" value="{{ old('title', $analysis['title'] ?? '') }}" maxlength="120"
                                class="w-full px-3 py-2 text-sm border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 placeholder-gray-400 focus:ring-2 focus:ring-indigo-500 focus:border-transparent">
                            @error('title')<p class="text-red-500 text-xs mt-1">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label for="hr-need" class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Ce dont j'ai besoin</label>
                            <textarea name="need" id="hr-need" rows="3" maxlength="2000"
                                class="w-full resize-none px-3 py-2 text-sm border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 placeholder-gray-400 focus:ring-2 focus:ring-indigo-500 focus:border-transparent">{{ old('need', $analysis['need'] ?? '') }}</textarea>
                            @error('need')<p class="text-red-500 text-xs mt-1">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label for="hr-context" class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Contexte (optionnel)</label>
                            <textarea name="context" id="hr-context" rows="2" maxlength="3000"
                                class="w-full resize-none px-3 py-2 text-sm border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 placeholder-gray-400 focus:ring-2 focus:ring-indigo-500 focus:border-transparent">{{ old('context', $analysis['context'] ?? '') }}</textarea>
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label for="hr-help-type" class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Aide attendue</label>
                                <input type="text" name="expected_help_type" id="hr-help-type" value="{{ old('expected_help_type', $analysis['expected_help_type'] ?? '') }}" maxlength="500"
                                    class="w-full px-3 py-2 text-sm border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 placeholder-gray-400 focus:ring-2 focus:ring-indigo-500 focus:border-transparent">
                            </div>
                            <div>
                                <label for="hr-deadline" class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Deadline (optionnel)</label>
                                <input type="text" name="deadline" id="hr-deadline" value="{{ old('deadline', $deadline['label'] ?? '') }}" maxlength="500" placeholder="ex: avant vendredi"
                                    class="w-full px-3 py-2 text-sm border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 placeholder-gray-400 focus:ring-2 focus:ring-indigo-500 focus:border-transparent">
                            </div>
                        </div>

                        @if($suggestedLoop)
                            <div class="flex items-center gap-2 text-xs text-gray-500 dark:text-gray-400 bg-gray-50 dark:bg-gray-700/50 rounded-lg px-3 py-2">
                                <svg class="w-3.5 h-3.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/>
                                </svg>
                                <span>Boucle conseillée : <strong>{{ $suggestedLoop['label'] ?? $currentLoop->name }}</strong></span>
                                @if(!empty($suggestedLoop['reason']))
                                    <span class="text-gray-400">— {{ $suggestedLoop['reason'] }}</span>
                                @endif
                            </div>
                        @else
                            <div class="flex items-center gap-2 text-xs text-gray-500 dark:text-gray-400 bg-gray-50 dark:bg-gray-700/50 rounded-lg px-3 py-2">
                                <svg class="w-3.5 h-3.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/>
                                </svg>
                                <span>Publié dans <strong>{{ $currentLoop->name }}</strong></span>
                            </div>
                        @endif

                        <div class="flex items-center gap-2 text-xs text-indigo-600 dark:text-indigo-400">
                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                            </svg>
                            <span>Rien n'est publié sans votre validation</span>
                        </div>

                        <div class="flex gap-3 pt-1">
                            <a href="{{ route('loops.show', $currentLoop) }}"
                               class="flex-1 text-center px-4 py-2.5 text-sm font-medium text-gray-700 dark:text-gray-300 bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 rounded-xl transition">
                                Annuler
                            </a>
                            <button type="submit"
                                class="flex-1 px-4 py-2.5 bg-amber-500 hover:bg-amber-600 text-white text-sm font-semibold rounded-xl transition flex items-center justify-center gap-1.5">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                                </svg>
                                Publier dans la boucle
                            </button>
                        </div>
                    </form>
                </div>

            @elseif(session('help_request_error'))
                <div class="px-4 py-3">
                    <div class="flex items-center gap-2 text-sm text-red-600 dark:text-red-400 mb-3">
                        <span>{{ session('help_request_error') }}</span>
                    </div>
                    <div class="flex gap-2">
                        <a href="{{ route('loops.show', $currentLoop) }}"
                           class="px-4 py-2 text-sm font-medium text-gray-700 dark:text-gray-300 bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 rounded-xl transition">
                            Revenir
                        </a>
                    </div>
                </div>

            @else
                <div class="px-4 py-3">
                    <div x-data="{ showHelpForm: false }" class="space-y-3">
                        <button @click="showHelpForm = !showHelpForm"
                            class="w-full flex items-center justify-center gap-2 px-4 py-2.5 text-sm font-medium text-amber-700 dark:text-amber-300 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-700/50 rounded-xl hover:bg-amber-100 dark:hover:bg-amber-900/30 transition">
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/>
                            </svg>
                            <span x-text="showHelpForm ? 'Annuler' : 'Qui peut m\'aider ?'"></span>
                        </button>

                        <div x-show="showHelpForm">
                            <form method="POST" action="{{ route('loops.help-request.analyze', $currentLoop) }}" class="space-y-3">
                                @csrf
                                <label for="intention" class="block text-xs font-medium text-gray-500 dark:text-gray-400">
                                    Décrivez votre besoin en quelques mots
                                </label>
                                <textarea name="intention" id="intention" rows="3"
                                    placeholder="Ex: Je cherche des conseils pour trouver mes premiers clients..."
                                    class="w-full resize-none px-3.5 py-2.5 text-sm border border-gray-300 dark:border-gray-600 rounded-xl bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 placeholder-gray-400 dark:placeholder-gray-500 focus:ring-2 focus:ring-amber-400 focus:border-transparent"
                                    required minlength="3"></textarea>
                                <button type="submit"
                                    class="w-full px-4 py-2.5 bg-amber-500 hover:bg-amber-600 text-white text-sm font-semibold rounded-xl transition flex items-center justify-center gap-1.5">
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"/>
                                    </svg>
                                    Clarifier ma demande
                                </button>
                            </form>
                            <p class="text-xs text-gray-400 dark:text-gray-500 mt-2 text-center">BouclePro vous aide à reformuler votre demande avant publication</p>
                        </div>

                    </div>
                </div>
            @endif
        </div>
    </div>
    </x-page-container>
</x-app-layout>
