<nav class="md:hidden fixed bottom-0 inset-x-0 z-40 bg-white/95 dark:bg-gray-900/95 backdrop-blur-sm border-t border-gray-200 dark:border-gray-700 pb-[env(safe-area-inset-bottom)] shadow-[0_-2px_8px_rgba(0,0,0,0.06)] dark:shadow-[0_-2px_8px_rgba(0,0,0,0.3)]">
    <div class="flex justify-around items-center h-16 px-2">
        @php
            $currentRoute = request()->route()?->getName() ?? '';
            $organizationRouteParam = request()->route('organization');
            $tabUrl = function (string $rootRoute, ?string $organizationRoute = null) use ($organizationRouteParam): string {
                if ($organizationRouteParam && $organizationRoute && Route::has($organizationRoute)) {
                    return route($organizationRoute, ['organization' => $organizationRouteParam]);
                }

                return route($rootRoute);
            };
            $tabs = auth()->check() ? [
                ['url' => $tabUrl('loops.index', 'organization.loops.index'), 'active' => 'loops', 'label' => 'Boucles', 'icon' => 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z'],
                ['url' => $tabUrl('explorer', 'organization.explorer'), 'active' => 'explorer', 'label' => 'Échanges', 'icon' => 'M7 16V4m0 0L3 8m4-4 4 4m6 0v12m0 0l4-4m-4 4l-4-4'],
                ['url' => $tabUrl('dashboard', 'organization.dashboard'), 'active' => 'dashboard', 'label' => 'Objectifs', 'icon' => 'M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z'],
                ['url' => route('blog.index'), 'active' => 'blog', 'label' => 'Actus', 'icon' => 'M19 20H5a2 2 0 01-2-2V6a2 2 0 012-2h10a2 2 0 012 2v1m2 13a2 2 0 01-2-2V7m2 13a2 2 0 002-2V9a2 2 0 00-2-2h-2m-4-3H9M7 16h6M7 8h6v4H7V8z'],
            ] : [
                ['url' => route('boucles.index'), 'active' => 'boucles', 'label' => 'Boucles', 'icon' => 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z'],
                ['url' => $tabUrl('explorer', 'organization.explorer'), 'active' => 'explorer', 'label' => 'Échanges', 'icon' => 'M7 16V4m0 0L3 8m4-4 4 4m6 0v12m0 0l4-4m-4 4l-4-4'],
                ['url' => $tabUrl('login', 'organization.login'), 'active' => 'login', 'label' => 'Objectifs', 'icon' => 'M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z'],
                ['url' => route('blog.index'), 'active' => 'blog', 'label' => 'Actus', 'icon' => 'M19 20H5a2 2 0 01-2-2V6a2 2 0 012-2h10a2 2 0 012 2v1m2 13a2 2 0 01-2-2V7m2 13a2 2 0 002-2V9a2 2 0 00-2-2h-2m-4-3H9M7 16h6M7 8h6v4H7V8z'],
            ];
        @endphp
        @foreach($tabs as $tab)
        @php $isActive = str_starts_with($currentRoute, $tab['active']) || str_starts_with($currentRoute, 'organization.' . $tab['active']); @endphp
        <a href="{{ $tab['url'] }}"
           class="flex flex-col items-center gap-0.5 flex-1 py-1 transition">
            <span class="relative">
                <svg class="block w-6 h-6 {{ $isActive ? 'text-indigo-600' : 'text-gray-400 dark:text-gray-500' }}" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true">
                    <path d="{{ $tab['icon'] }}" />
                </svg>
            </span>
            <span class="text-[10px] font-medium {{ $isActive ? 'text-indigo-600 font-semibold' : 'text-gray-400 dark:text-gray-500' }}">{{ $tab['label'] }}</span>
            @if($isActive)
            <span class="w-1 h-1 rounded-full bg-indigo-600"></span>
            @endif
        </a>
        @endforeach
    </div>
</nav>
