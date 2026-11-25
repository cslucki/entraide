<x-app-layout :title="__('bugs.title')">
    {{-- TASK-1749 — la barre du haut standard porte le titre.

         TASK-1904 — « Signalement » : point d'entree UNIQUE et CONFIDENTIEL
         (bug, comportement, contenu, securite des enfants...). Fin de la
         transparence publique de TASK-1493 (decision MASTER D6) :
         - invite : une invitation a se connecter, AUCUNE liste ;
         - membre : le formulaire, puis SES seuls signalements ($bugReports est
           deja borne a l'auteur et a l'organisation par le controleur). --}}
    <x-page-topbar :back="organizationScopedUrl('dashboard', 'organization.dashboard')"
                   :title="__('bugs.title')"
                   :label="__('dashboard.back_to_dashboard')" />

    <div class="max-w-4xl mx-auto px-4 py-8" data-report-page>
        @php
            $organizationRouteParam = request()->route('organization');
            $bugReportStoreRoute = $organizationRouteParam && Route::has('organization.bug-reports.store')
                ? route('organization.bug-reports.store', ['organization' => $organizationRouteParam])
                : route('bug-reports.store');
        @endphp

        <p class="mb-6 max-w-2xl text-sm text-gray-600 dark:text-gray-300">{{ __('bugs.intro') }}</p>

        @auth
            <form method="POST" action="{{ $bugReportStoreRoute }}" data-report-form
                  class="mb-8 rounded-2xl border border-indigo-100 bg-white p-4 shadow-sm dark:border-indigo-900/50 dark:bg-gray-800">
                @csrf
                <input type="hidden" name="page_url" value="{{ $pageUrl }}">

                <label for="report-category" class="block text-sm font-semibold text-gray-900 dark:text-gray-100">{{ __('bugs.category_label') }}</label>
                <select id="report-category" name="category" required
                        class="mt-1 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm text-gray-900 sm:w-auto dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                    <option value="">{{ __('bugs.category_placeholder') }}</option>
                    @foreach (\App\Models\BugReport::CATEGORIES as $category)
                        <option value="{{ $category }}" @selected(old('category') === $category)>{{ __('bugs.categories.'.$category) }}</option>
                    @endforeach
                </select>
                @error('category')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror

                <label for="report-details" class="mt-4 block text-sm font-semibold text-gray-900 dark:text-gray-100">{{ __('bugs.details_label') }}</label>
                <textarea id="report-details" name="details" rows="4" required maxlength="2000"
                          placeholder="{{ __('bugs.details_placeholder') }}"
                          class="mt-1 w-full resize-y rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm text-gray-900 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">{{ old('details') }}</textarea>
                @error('details')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror

                @if ($pageUrl)
                    <p class="mt-2 break-all text-xs text-gray-500 dark:text-gray-400">{{ __('bugs.page_concerned', ['url' => $pageUrl]) }}</p>
                @endif

                <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">
                    {{ __('bugs.confidential_note') }}
                    <a href="{{ organizationScopedUrl('securite-enfants', 'organization.securite-enfants') }}" class="font-medium text-indigo-600 hover:underline dark:text-indigo-400">{{ __('bugs.child_safety_link') }}</a>
                </p>

                <div class="mt-4">
                    <button type="submit" class="rounded-lg bg-gray-900 px-4 py-2 text-sm font-semibold text-white transition hover:bg-gray-700 dark:bg-gray-100 dark:text-gray-900 dark:hover:bg-white">
                        {{ __('bugs.submit') }}
                    </button>
                </div>
            </form>

            <h2 class="mb-3 text-base font-semibold text-gray-900 dark:text-gray-100">{{ __('bugs.mine_title') }}</h2>
            <div class="space-y-3" data-my-reports>
                @forelse(($bugReports ?? []) as $bugReport)
                    @php
                        $statusClasses = [
                            'pending' => 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-300',
                            'fixed' => 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-300',
                            'resolved' => 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-300',
                        ];
                    @endphp
                    <article class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
                        <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                            <div>
                                <h3 class="font-semibold text-gray-900 dark:text-gray-100">{{ __('bugs.categories.'.$bugReport->category) }}</h3>
                                <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $bugReport->details }}</p>
                            </div>
                            <span class="inline-flex w-fit rounded-full px-2 py-0.5 text-xs font-medium {{ $statusClasses[$bugReport->status] ?? 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300' }}">
                                {{ __('bugs.status_' . $bugReport->status) }}
                            </span>
                        </div>
                        @if($bugReport->resolution_notes)
                            <div class="mt-3 rounded-lg bg-green-50 px-3 py-2 text-sm text-green-800 dark:bg-green-900/20 dark:text-green-200">
                                <span class="font-semibold">{{ __('bugs.resolution') }}</span> {{ $bugReport->resolution_notes }}
                            </div>
                        @endif
                        <p class="mt-3 text-xs text-gray-400 dark:text-gray-500">
                            {{ __('bugs.reported_on', ['date' => $bugReport->created_at->format('d/m/Y')]) }}
                            @if($bugReport->fixed_at)
                                · {{ __('bugs.fixed_on', ['date' => $bugReport->fixed_at->format('d/m/Y')]) }}
                            @endif
                        </p>
                    </article>
                @empty
                    <div class="rounded-xl border border-dashed border-gray-300 bg-white px-4 py-8 text-center dark:border-gray-700 dark:bg-gray-800">
                        <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('bugs.empty') }}</p>
                    </div>
                @endforelse
            </div>
            @if($bugReports && $bugReports->hasPages())
                <div class="mt-6">{{ $bugReports->links() }}</div>
            @endif
        @else
            <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-700 dark:bg-gray-800" data-report-guest>
                <p class="text-sm text-gray-700 dark:text-gray-200">{{ __('bugs.guest_text') }}</p>
                <a href="{{ organizationScopedUrl('login', 'organization.login') }}" class="mt-4 inline-flex items-center rounded-full bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-700">
                    {{ __('navigation.login') }}
                </a>
                <p class="mt-4 text-xs text-gray-500 dark:text-gray-400">
                    {{ __('bugs.guest_child_safety') }}
                    <a href="{{ organizationScopedUrl('securite-enfants', 'organization.securite-enfants') }}" class="font-medium text-indigo-600 hover:underline dark:text-indigo-400">{{ __('bugs.child_safety_link') }}</a>
                </p>
            </div>
        @endauth
    </div>
</x-app-layout>
