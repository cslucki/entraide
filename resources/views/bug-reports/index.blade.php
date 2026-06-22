<x-app-layout>
    <div class="max-w-4xl mx-auto px-4 py-8">
        @php
            $organizationRouteParam = request()->route('organization');
            $bugReportStoreRoute = $organizationRouteParam && Route::has('organization.bug-reports.store')
                ? route('organization.bug-reports.store', ['organization' => $organizationRouteParam])
                : route('bug-reports.store');
        @endphp

        <div x-data="{ bugOpen: false }" class="mb-6">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wide text-indigo-600 dark:text-indigo-400">{{ __('bugs.quality') }}</p>
                    <h1 class="mt-1 text-2xl font-bold text-gray-900 dark:text-gray-100">{{ __('bugs.title') }}</h1>
                    <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                        {{ __('bugs.subtitle') }}{{ $organization ? ' '.$organization->name : '' }}{{ $organization ? '' : __('bugs.subtitle_org') }}
                    </p>
                </div>

                @auth
                    <button type="button" @click="bugOpen = !bugOpen" class="inline-flex items-center justify-center rounded-full bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 dark:focus:ring-offset-gray-900">
                        {{ __('navigation.report_bug') }}
                    </button>
                @endauth
            </div>

            @auth
                <form x-show="bugOpen" x-cloak x-transition method="POST" action="{{ $bugReportStoreRoute }}" x-data x-init="$refs.pageUrl.value = window.location.href" class="mt-5 rounded-2xl border border-indigo-100 bg-white p-4 shadow-sm dark:border-indigo-900/50 dark:bg-gray-800">
                    @csrf
                    <input x-ref="pageUrl" type="hidden" name="page_url" value="{{ request()->fullUrl() }}">
                    <div class="grid gap-3 sm:grid-cols-2">
                        <select name="reason" required class="rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm text-gray-900 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                            <option value="">{{ __('navigation.bug_type_placeholder') }}</option>
                            <option value="Affichage mobile">{{ __('navigation.bug_type_mobile') }}</option>
                            <option value="Fonctionnement">{{ __('navigation.bug_type_functionality') }}</option>
                            <option value="Navigation">{{ __('navigation.bug_type_navigation') }}</option>
                            <option value="Autre">{{ __('navigation.bug_type_other') }}</option>
                        </select>
                        <div class="flex items-center gap-2 sm:justify-end">
                            <button type="submit" class="rounded-lg bg-gray-900 px-4 py-2 text-sm font-semibold text-white transition hover:bg-gray-700 dark:bg-gray-100 dark:text-gray-900 dark:hover:bg-white">
                                {{ __('navigation.send_report') }}
                            </button>
                            <button type="button" @click="bugOpen = false" class="rounded-lg border border-gray-200 px-4 py-2 text-sm font-medium text-gray-600 transition hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-700">
                                {{ __('ui.cancel') }}
                            </button>
                        </div>
                    </div>
                    <textarea name="details" rows="3" required placeholder="{{ __('navigation.bug_details_placeholder') }}" class="mt-3 w-full resize-none rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm text-gray-900 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100"></textarea>
                </form>
            @else
                <a href="{{ route('login') }}" class="mt-4 inline-flex items-center rounded-full bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-700">
                    {{ __('navigation.login') }}
                </a>
            @endauth
        </div>

        <div class="space-y-3">
            @forelse($bugReports as $bugReport)
                @php
                    $statusClasses = [
                        'pending' => 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-300',
                        'fixed' => 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-300',
                    ];
                @endphp
                <article class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                        <div>
                            <h2 class="font-semibold text-gray-900 dark:text-gray-100">{{ $bugReport->reason }}</h2>
                            <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $bugReport->details }}</p>
                        </div>
                        <span class="inline-flex w-fit rounded-full px-2 py-0.5 text-xs font-medium {{ $statusClasses[$bugReport->status] ?? 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300' }}">
                            {{ __('bugs.status_' . $bugReport->status, ['default' => $bugReport->status]) }}
                        </span>
                    </div>

                    @if($bugReport->status === 'fixed' && $bugReport->resolution_notes)
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
                <div class="rounded-xl border border-dashed border-gray-300 bg-white px-4 py-10 text-center dark:border-gray-700 dark:bg-gray-800">
                    <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('bugs.empty') }}</p>
                </div>
            @endforelse
        </div>

        @if($bugReports->hasPages())
            <div class="mt-6">{{ $bugReports->links() }}</div>
        @endif
    </div>
</x-app-layout>
