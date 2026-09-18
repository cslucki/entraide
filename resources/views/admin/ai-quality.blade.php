<x-admin-layout :title="__('ai.quality_platform_title')">
    {{-- TASK-1487 (AI Quality Q2) — la MEME lecture, agregee plateforme.

         Le filtre Organization est une LECTURE, pas un droit : le SuperAdmin a
         deja l'acces transverse qu'`AdminMiddleware` accorde. Restreindre
         l'affichage ne lui ouvre rien de plus, et n'ouvre rien a personne
         d'autre.

         Aucune conversation, aucune cle, aucun secret : uniquement des comptes
         par fonction. --}}
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">{{ __('ai.quality_platform_title') }}</h1>
        <p class="mt-1 max-w-3xl text-sm text-gray-500 dark:text-gray-400">{{ __('ai.quality_intro') }}</p>
        <p class="mt-1 text-xs text-gray-400 dark:text-gray-500">{{ __('ai.quality_period') }}</p>
    </div>

    <form method="GET" class="mb-5 flex flex-wrap items-end gap-2" data-ai-quality-filter>
        <div>
            <label for="ai-quality-org" class="block text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('ai.quality_platform_filter') }}</label>
            <select id="ai-quality-org" name="organization"
                    class="mt-1 min-h-11 rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
                <option value="">{{ __('ai.quality_platform_all') }}</option>
                @foreach($organizations as $org)
                    <option value="{{ $org->slug }}" @selected($selected?->id === $org->id)>{{ $org->name }}</option>
                @endforeach
            </select>
        </div>
        <button type="submit" class="min-h-11 rounded-lg bg-indigo-600 px-4 text-sm font-semibold text-white hover:bg-indigo-700">
            {{ __('ai.quality_platform_filter') }}
        </button>
    </form>

    @include('admin.partials.ai-quality-summary', ['quality' => $quality])

    <div class="mt-6 rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800">
        @include('admin.partials.ai-quality-table', ['quality' => $quality])
    </div>

    <p class="mt-4 max-w-3xl text-xs text-gray-500 dark:text-gray-400">{{ __('ai.quality_footer') }}</p>
</x-admin-layout>
