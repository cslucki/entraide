<x-app-layout :title="__('loops.invite_title')">
    @php
        // Aliased: no @foreach shadowing risk, and consistent with show/edit.
        $currentLoop = $loop;
        $_org = request()->route('organization');
        $_loopRoute = function ($name, $params = []) use ($_org) {
            if ($_org && request()->routeIs('organization.*') && Route::has('organization.loops.'.$name)) {
                return route('organization.loops.'.$name, array_merge(['organization' => $_org], $params));
            }
            return route('loops.'.$name, $params);
        };
    @endphp

    <div class="mx-auto max-w-2xl px-4 py-8">
        @if(session('success'))
            <div class="mb-6 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700 dark:border-green-800 dark:bg-green-900/20 dark:text-green-300">
                {{ session('success') }}
            </div>
        @endif

        <div class="mb-6 text-center">
            <span class="mb-3 inline-flex h-11 w-11 items-center justify-center rounded-full bg-emerald-100 text-emerald-600 dark:bg-emerald-900/30 dark:text-emerald-400">
                <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/>
                </svg>
            </span>
            <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">{{ __('loops.invite_title') }}</h1>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('loops.invite_subtitle') }}</p>
        </div>

        {{-- La liste des membres et l'ajout depuis l'Organization vivent
             maintenant dans un seul composant, partage avec la Card Membres et
             l'ecran d'edition. Le formulaire POST + redirection qui etait ici
             rechargeait la page sans jamais montrer qui venait d'etre ajoute.
             La route reste servie : elle est la garde serveur du meme geste. --}}
        @livewire('loop-members-card', ['loop' => $currentLoop])

        <div class="mt-6 flex flex-col gap-3 sm:flex-row sm:justify-between">
            <a href="{{ $_loopRoute('index') }}"
               class="inline-flex items-center justify-center rounded-xl border border-gray-300 px-4 py-2.5 text-sm font-semibold text-gray-600 transition hover:bg-gray-50 dark:border-gray-600 dark:text-gray-300 dark:hover:bg-gray-800">
                {{ __('loops.invite_later') }}
            </a>
            <a href="{{ $_loopRoute('show', ['loop' => $currentLoop]) }}"
               class="inline-flex items-center justify-center gap-2 rounded-xl bg-gray-900 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-gray-700 dark:bg-gray-100 dark:text-gray-900 dark:hover:bg-white">
                {{ __('loops.cta_open_workspace') }}
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3"/>
                </svg>
            </a>
        </div>
    </div>
</x-app-layout>
