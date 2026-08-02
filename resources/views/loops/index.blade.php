<x-page :title="__('loops.my_loops')" :heading="__('loops.my_loops')">
    @php
        $organizationRouteParam = request()->route('organization');
        $loopsCreateHref = $organizationRouteParam && request()->routeIs('organization.*')
            ? route('organization.loops.create', ['organization' => $organizationRouteParam])
            : route('loops.create');
        $loopShowHref = function ($loop) use ($organizationRouteParam) {
            if ($organizationRouteParam && request()->routeIs('organization.*')) {
                return route('organization.loops.show', ['organization' => $organizationRouteParam, 'loop' => $loop]);
            }

            return route('loops.show', $loop);
        };
        $loopJoinAction = function ($loop) use ($organizationRouteParam) {
            if ($organizationRouteParam && request()->routeIs('organization.*')) {
                return route('organization.loops.join', ['organization' => $organizationRouteParam, 'loop' => $loop]);
            }

            return route('loops.join', $loop);
        };
        $loopEditHref = function ($loop) use ($organizationRouteParam) {
            if ($organizationRouteParam && request()->routeIs('organization.*')) {
                return route('organization.loops.edit', ['organization' => $organizationRouteParam, 'loop' => $loop]);
            }

            return route('loops.edit', $loop);
        };
        // Set by LoopController::update() so the freshly edited card is easy to spot.
        $highlightedLoopId = request()->query('updated');
        $mine = $loops->where('is_member', true)->values();
        $discover = $loops->where('is_member', false)->values();
    @endphp

    <x-slot name="headingActions">
        @if($canCreate)
            <a href="{{ $loopsCreateHref }}"
               class="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700 rounded-lg transition whitespace-nowrap">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                </svg>
                <span>{{ __('loops.new') }}</span>
            </a>
        @endif
    </x-slot>

    <div x-data="{ q: '' }">
        <div class="flex items-center justify-between gap-3 mb-6 md:block">
            <p class="text-gray-500 dark:text-gray-400 text-sm">{{ __('loops.collaboration_spaces') }}</p>
            @if($canCreate)
                {{-- Mobile-only access: the desktop button lives in headingActions (hidden md:flex),
                     which is not reachable on small viewports. Local to this page only. --}}
                <a href="{{ $loopsCreateHref }}"
                   class="md:hidden shrink-0 inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700 rounded-lg transition whitespace-nowrap">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                    </svg>
                    <span>{{ __('loops.new') }}</span>
                </a>
            @endif
        </div>

        @if(session('success'))
            <div x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 3000)"
                 class="mb-6 bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 text-green-700 dark:text-green-300 px-4 py-3 rounded-lg text-sm">
                {{ session('success') }}
            </div>
        @endif
        @if(session('info'))
            <div x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 3000)"
                 class="mb-6 bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 text-blue-700 dark:text-blue-300 px-4 py-3 rounded-lg text-sm">
                {{ session('info') }}
            </div>
        @endif
        @if(session('error'))
            <div class="mb-6 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 text-red-700 dark:text-red-300 px-4 py-3 rounded-lg text-sm">
                {{ session('error') }}
            </div>
        @endif

        @if(isset($noPrimaryLoopWarning) && $noPrimaryLoopWarning)
            <div class="mb-6 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 text-amber-700 dark:text-amber-300 px-4 py-3 rounded-lg text-sm">
                <strong>{{ __('loops.default_missing_title') }}</strong> {{ __('loops.default_missing_body') }}
            </div>
        @endif

        @if($loops->isEmpty())
            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 py-16 px-6 text-center">
                <svg class="w-10 h-10 mx-auto text-gray-300 dark:text-gray-600 mb-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/>
                </svg>
                <p class="text-gray-400 dark:text-gray-500 mb-4">{{ __('loops.empty') }}</p>
                @if($canCreate)
                    <a href="{{ $loopsCreateHref }}"
                       class="inline-flex items-center gap-1.5 px-4 py-2.5 text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700 rounded-xl transition">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                        </svg>
                        {{ __('loops.create_first') }}
                    </a>
                @endif
            </div>
        @else
            @if($loops->count() > 6)
                <div class="relative mb-6 max-w-sm">
                    <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z"/>
                    </svg>
                    <input type="search" x-model="q" placeholder="{{ __('loops.search_placeholder') }}"
                           class="w-full rounded-xl border border-gray-300 bg-white py-2 pl-9 pr-3 text-sm text-gray-900 focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100">
                </div>
            @endif

            @if($mine->isNotEmpty())
                <h2 class="mb-3 text-xs font-bold uppercase tracking-wide text-gray-400 dark:text-gray-500">{{ __('loops.my_loops_title') }}</h2>
                <div class="mb-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach($mine as $item)
                        @include('loops.partials.catalog-card', ['item' => $item])
                    @endforeach
                </div>
            @endif

            @if($discover->isNotEmpty())
                <h2 class="mb-3 text-xs font-bold uppercase tracking-wide text-gray-400 dark:text-gray-500">{{ __('loops.discover_title') }}</h2>
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach($discover as $item)
                        @include('loops.partials.catalog-card', ['item' => $item])
                    @endforeach
                </div>
            @endif
        @endif
    </div>
</x-page>
