@php
    $user = auth()->user();
    $organizationRouteParam = request()->route('organization') ?: currentOrganization()?->slug ?: $user?->organization?->slug;
    $currentOrganization = currentOrganization() ?: $user?->organization;

    $routeUrl = function (string $rootRoute, ?string $organizationRoute = null, array $params = []) use ($organizationRouteParam): string {
        if ($organizationRouteParam && $organizationRoute && Route::has($organizationRoute)) {
            return route($organizationRoute, ['organization' => $organizationRouteParam] + $params);
        }

        return route($rootRoute, $params);
    };

    $items = [
        [
            'key' => 'dashboard',
            'label' => __('dashboard.dashboard_shortcut'),
            'url' => $routeUrl('dashboard', 'organization.dashboard'),
            'active' => request()->routeIs('dashboard', 'dashboard.*', 'organization.dashboard', 'organization.dashboard.*'),
            'icon' => 'M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 4.125C9.75 3.504 10.254 3 10.875 3h2.25c.621 0 1.125.504 1.125 1.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125zM16.5 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625z',
            'visible' => true,
        ],
        [
            'key' => 'profile',
            'label' => __('dashboard.public_profile'),
            'url' => $organizationRouteParam && Route::has('organization.profile.show')
                ? route('organization.profile.show', ['organization' => $organizationRouteParam, 'user' => $user])
                : route('profile.show', $user),
            'active' => request()->routeIs('profile.show', 'organization.profile.show'),
            'icon' => 'M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z',
            'visible' => true,
        ],
        [
            'key' => 'ai-agent',
            'label' => __('dashboard.ai_agent'),
            'url' => $routeUrl('agent-ia.wizard', 'organization.agent-ia.wizard'),
            'active' => request()->routeIs('agent-ia.*', 'organization.agent-ia.*'),
            'icon' => 'M9.75 3.104v5.714a2.25 2.25 0 01-.659 1.591L5 14.5M14.25 3.104v5.714c0 .597.237 1.17.659 1.591L19.8 15.3M5 14.5l-1.402 1.402c-1.232 1.232-.65 3.318 1.067 3.611A48.309 48.309 0 0012 21c2.773 0 5.491-.235 8.135-.687 1.718-.293 2.3-2.379 1.067-3.61L19.8 15.3',
            'visible' => ! $currentOrganization || $currentOrganization->ai_profiles_enabled,
        ],
        [
            'key' => 'favorites',
            'label' => __('dashboard.my_favorites'),
            'url' => $routeUrl('favorites.index', 'organization.favorites.index'),
            'active' => request()->routeIs('favorites.*', 'organization.favorites.*'),
            'icon' => 'M11.48 3.499a.562.562 0 011.04 0l2.125 5.111 5.52.442a.563.563 0 01.32.988l-4.205 3.602 1.285 5.385a.562.562 0 01-.84.61L12 16.75l-4.725 2.887a.562.562 0 01-.84-.61l1.285-5.385-4.205-3.602a.563.563 0 01.32-.988l5.52-.442 2.125-5.111z',
            'visible' => true,
        ],
        [
            'key' => 'invitations',
            'label' => __('dashboard.invitations'),
            'url' => $routeUrl('invitations.index', 'organization.invitations.index'),
            'active' => request()->routeIs('invitations.*', 'organization.invitations.*'),
            'icon' => 'M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75',
            'visible' => true,
        ],
        [
            'key' => 'points',
            'label' => __('dashboard.points_history'),
            'url' => $routeUrl('points.index', 'organization.points.index'),
            'active' => request()->routeIs('points.*', 'organization.points.*'),
            'icon' => 'M12 6v12m-3-2.818l.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
            'visible' => true,
        ],
    ];
@endphp

<nav {{ $attributes->merge(['class' => 'flex flex-nowrap gap-2 overflow-x-auto pb-2 sm:flex-wrap sm:overflow-visible sm:pb-0']) }} aria-label="{{ __('dashboard.user_shortcuts') }}">
    @foreach($items as $item)
        @if($item['visible'] && ! $item['active'])
            <a href="{{ $item['url'] }}"
               data-user-dashboard-nav-link="{{ $item['key'] }}"
               class="inline-flex shrink-0 items-center gap-1.5 rounded-lg border border-gray-200 bg-white px-4 py-2 text-sm font-medium text-gray-700 transition hover:border-indigo-200 hover:bg-indigo-50 hover:text-indigo-700 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200 dark:hover:border-indigo-700 dark:hover:bg-indigo-950/50 dark:hover:text-indigo-200">
                <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="{{ $item['icon'] }}" />
                </svg>
                <span class="whitespace-nowrap">{{ $item['label'] }}</span>
            </a>
        @endif
    @endforeach
</nav>
