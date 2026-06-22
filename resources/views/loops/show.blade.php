@php $currentLoop = $loop; @endphp
@php $analysis = session('help_request_analysis'); @endphp

@push('head')
<style>
    @media (max-width: 767px) {
        body:has(.loops-show-container) > header[class*="fixed"],
        body:has(.loops-show-container) > [class*="md:hidden"]:has(button[class*="bottom-20"]) {
            display: none !important;
        }
        body:has(.loops-show-container) > .min-h-screen {
            padding-top: 0 !important;
            padding-bottom: 4rem !important;
        }
        body:has(.loops-show-container) .min-h-screen > .md\:hidden,
        body:has(.loops-show-container) .min-h-screen > footer {
            display: none !important;
        }
        body:has(.loops-show-container) .loops-show-wrapper {
            padding: 0 !important;
        }
        body:has(.loops-show-container) .loops-show-container {
            height: calc(100dvh - 4rem);
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
            @php $backRoute = app()->bound('current_organization') && app('current_organization')->isMonoLoop() ? 'home' : 'loops.index'; @endphp
            <a href="{{ route($backRoute) }}"
               class="inline-flex h-9 w-9 items-center justify-center rounded-full bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-600 transition"
               aria-label="{{ $backRoute === 'home' ? 'Retour à l\'accueil' : 'Retour aux boucles' }}">
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
        <div class="flex-1 flex flex-col min-h-0">
            @livewire('loop-chat', ['loop' => $loop], key('loop-chat-'.$loop->id))
        </div>

        <x-conversation.image-lightbox key="loop-chat" />

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

            @elseif($clarificationEnabled || $analysis)
                <div x-data="{ open: @js($analysis ? true : false) }" class="px-4 py-3">
                    <button x-show="!open" @click="open = true"
                        class="w-full flex items-center justify-center gap-2 px-4 py-2.5 text-sm font-medium text-amber-700 dark:text-amber-300 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-700/50 rounded-xl hover:bg-amber-100 dark:hover:bg-amber-900/30 transition">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/>
                        </svg>
                        <span>{{ __('loops.who_can_help') }}</span>
                    </button>

                    <template x-teleport="body">
                        <div x-show="open" x-cloak
                            class="fixed inset-0 z-50 flex items-center justify-center"
                            x-effect="document.body.style.overflow = open ? 'hidden' : ''"
                            @keydown.escape.window="open = false">
                            <div x-show="open" @click="open = false" class="fixed inset-0 bg-black/50 transition-opacity"></div>
                            <div x-show="open" @click.away="open = false"
                                class="relative w-full max-w-lg bg-white dark:bg-gray-800 rounded-2xl shadow-xl flex flex-col max-h-[80vh] mx-3">
                                <div class="flex items-center justify-between px-4 py-3 border-b border-gray-200 dark:border-gray-700 flex-shrink-0">
                                    <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">
                                        @if($analysis)
                                            {{ __('loops.clarified_request') }}
                                        @else
                                            {{ __('loops.who_can_help') }}
                                        @endif
                                    </h3>
                                    @if($analysis)
                                        <a href="{{ route('loops.show', $currentLoop) }}" class="p-1 rounded-md text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700">
                                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                                        </a>
                                    @else
                                        <button @click="open = false" class="p-1 rounded-md text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700">
                                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                                        </button>
                                    @endif
                                </div>
                                <div class="overflow-y-auto px-4 py-3 min-h-0 flex-1">
                                    @if($analysis)
                                        @php
                                            $needsFallback = $analysis['fallback']['needed'] ?? false;
                                            $fallbackReason = $analysis['fallback']['reason'] ?? null;
                                            $fallbackQuestions = $analysis['fallback']['questions'] ?? [];
                                            $originalPhrase = $analysis['original_phrase'] ?? session('help_request_intention', '');
                                            $fallbackNeedEmpty = $needsFallback && empty($analysis['need']) && $originalPhrase;
                                            $needValue = $fallbackNeedEmpty ? $originalPhrase : ($analysis['need'] ?? '');
                                            $selectedHelpType = old('help_type', ($analysis['intent'] ?? '') === 'offer' ? 'service' : 'request');
                                        @endphp

                                        @if($needsFallback)
                                            <div class="bg-orange-50 dark:bg-orange-900/20 border border-orange-200 dark:border-orange-700/50 rounded-lg p-3 text-sm text-orange-700 dark:text-orange-300 mb-3">
                                                <p class="font-medium mb-1">{{ __('loops.precision_needed') }}</p>
                                                <p class="mb-1">{{ $fallbackReason }}</p>
                                                <p class="mb-2 text-xs">{{ __('loops.ia_ko_message') }}</p>
                                                @if($originalPhrase)
                                                    <div class="p-2 bg-white dark:bg-gray-800 rounded border border-orange-200 dark:border-orange-700 text-gray-600 dark:text-gray-400 text-xs italic">
                                                        « {{ $originalPhrase }} »
                                                    </div>
                                                @endif
                                                @if(count($fallbackQuestions))
                                                    <ul class="list-disc list-inside mt-2 space-y-0.5">
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
                                                <label for="hr-title" class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">{{ __('loops.form_title') }}</label>
                                                <input type="text" name="title" id="hr-title" value="{{ old('title', $analysis['title'] ?? '') }}" maxlength="120"
                                                    class="w-full px-3 py-2 text-sm border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 placeholder-gray-400 focus:ring-2 focus:ring-indigo-500 focus:border-transparent">
                                                @error('title')<p class="text-red-500 text-xs mt-1">{{ $message }}</p>@enderror
                                            </div>
                                            <div>
                                                <label for="hr-need" class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">{{ __('loops.description') }}</label>
                                                <textarea name="need" id="hr-need" rows="3" maxlength="2000"
                                                    class="w-full resize-none px-3 py-2 text-sm border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 placeholder-gray-400 focus:ring-2 focus:ring-indigo-500 focus:border-transparent">{{ old('need', $needValue) }}</textarea>
                                                @error('need')<p class="text-red-500 text-xs mt-1">{{ $message }}</p>@enderror
                                            </div>
                                            <div>
                                                <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-2">{{ __('loops.exchange_type') }}</label>
                                                <div class="flex gap-3">
                                                    <label class="flex items-center gap-2 px-3 py-2 text-sm border border-gray-300 dark:border-gray-600 rounded-lg cursor-pointer has-[:checked]:border-indigo-500 has-[:checked]:bg-indigo-50 dark:has-[:checked]:bg-indigo-900/20 has-[:checked]:ring-1 has-[:checked]:ring-indigo-500">
                                                        <input type="radio" name="help_type" value="request" @checked($selectedHelpType === 'request')
                                                            class="text-indigo-600 focus:ring-indigo-500">
                                                        {{ __('loops.help_type_request') }}
                                                    </label>
                                                    <label class="flex items-center gap-2 px-3 py-2 text-sm border border-gray-300 dark:border-gray-600 rounded-lg cursor-pointer has-[:checked]:border-indigo-500 has-[:checked]:bg-indigo-50 dark:has-[:checked]:bg-indigo-900/20 has-[:checked]:ring-1 has-[:checked]:ring-indigo-500">
                                                        <input type="radio" name="help_type" value="service" @checked($selectedHelpType === 'service')
                                                            class="text-indigo-600 focus:ring-indigo-500">
                                                        {{ __('loops.help_type_service') }}
                                                    </label>
                                                </div>
                                            </div>
                                            <div class="flex gap-3 pt-1">
                                                <a href="{{ route('loops.show', $currentLoop) }}"
                                                   class="flex-1 text-center px-4 py-2.5 text-sm font-medium text-gray-700 dark:text-gray-300 bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 rounded-xl transition">
                                                    {{ __('loops.cancel') }}
                                                </a>
                                                <button type="submit"
                                                    class="flex-1 px-4 py-2.5 bg-amber-500 hover:bg-amber-600 text-white text-sm font-semibold rounded-xl transition flex items-center justify-center gap-1.5">
                                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                                                    </svg>
                                                    {{ __('loops.continue_to_exchanges') }}
                                                </button>
                                            </div>
                                        </form>
                                    @else
                                        <form method="POST" action="{{ route('loops.help-request.analyze', $currentLoop) }}" class="space-y-3">
                                            @csrf
                                            <label for="intention" class="block text-xs font-medium text-gray-500 dark:text-gray-400">
                                                {{ __('loops.describe_need') }}
                                            </label>
                                            <textarea name="intention" id="intention" rows="3"
                                                placeholder="{{ __('loops.intention_placeholder') }}"
                                                class="w-full resize-none px-3.5 py-2.5 text-sm border border-gray-300 dark:border-gray-600 rounded-xl bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 placeholder-gray-400 dark:placeholder-gray-500 focus:ring-2 focus:ring-amber-400 focus:border-transparent"
                                                required minlength="3"></textarea>
                                            <button type="submit"
                                                class="w-full px-4 py-2.5 bg-amber-500 hover:bg-amber-600 text-white text-sm font-semibold rounded-xl transition flex items-center justify-center gap-1.5">
                                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"/>
                                                </svg>
                                                {{ __('loops.clarify_request') }}
                                            </button>
                                        </form>
                                        <p class="text-xs text-gray-400 dark:text-gray-500 mt-2 text-center">{{ __('loops.help_booster_ai') }}</p>
                                    @endif
                                </div>
                            </div>
                        </div>
                    </template>
                </div>

            @elseif(session('help_request_error'))
                <div class="px-4 py-3">
                    <div class="flex items-center gap-2 text-sm text-red-600 dark:text-red-400 mb-3">
                        <span>{{ session('help_request_error') }}</span>
                    </div>
                    <div class="flex gap-2">
                        <a href="{{ route('loops.show', $currentLoop) }}"
                           class="px-4 py-2 text-sm font-medium text-gray-700 dark:text-gray-300 bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 rounded-xl transition">
                            {{ __('loops.back') }}
                        </a>
                    </div>
                </div>

            @endif
        </div>
    </div>
    </x-page-container>
</x-app-layout>
