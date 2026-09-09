<x-org-admin-layout :title="__('ai.quality_title')" :organization="$organization">
    {{-- TASK-1487 (AI Quality Q2) — la console SŒUR de la consommation.

         L'une dit COMBIEN l'IA a consomme. Celle-ci dit si l'on SAIT qu'elle
         aide. Ce sont deux questions differentes, et les confondre est le
         defaut que le CDC nomme explicitement.

         Aujourd'hui, la reponse honnete de cet ecran est surtout « on ne sait
         pas encore, et voici precisement pourquoi ». C'est peu, et c'est vrai.
         Un cockpit qui aurait affiche « 0 % utile » aurait dit beaucoup plus,
         et faux. --}}
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">{{ __('ai.quality_title') }}</h1>
        <p class="mt-1 max-w-3xl text-sm text-gray-500 dark:text-gray-400">{{ __('ai.quality_intro') }}</p>
        <p class="mt-1 text-xs text-gray-400 dark:text-gray-500" data-ai-quality-period>{{ __('ai.quality_period') }}</p>
    </div>

    @include('admin.partials.ai-quality-summary', ['quality' => $quality])

    <div class="mt-6 rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800">
        @include('admin.partials.ai-quality-table', ['quality' => $quality])
    </div>

    <p class="mt-4 max-w-3xl text-xs text-gray-500 dark:text-gray-400" data-ai-quality-footer>{{ __('ai.quality_footer') }}</p>
</x-org-admin-layout>
