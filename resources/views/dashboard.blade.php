<x-app-layout>
    @php
        $_dashOrgSlug = $currentOrganization?->slug;
        $_dashRoute = function (string $name, array $params = []) use ($_dashOrgSlug): string {
            $orgRoute = 'organization.' . $name;
            return $_dashOrgSlug && Route::has($orgRoute)
                ? route($orgRoute, ['organization' => $_dashOrgSlug] + $params)
                : route($name, $params);
        };
        $_dashServicesCreateHref = $_dashRoute('services.create');
        $_dashRequestsCreateHref = $_dashRoute('requests.create');
    @endphp
    <div class="max-w-7xl mx-auto px-4 py-8">
        <!-- Header -->
        <div class="flex items-center justify-between mb-8">
            <div>
                @php $tenant = $currentOrganization ?? null; @endphp
                <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">
                    @if($tenant)
                        {{ __('dashboard.title_with_tenant', ['name' => $tenant->name]) }}
                    @else
                        {{ __('dashboard.hello', ['name' => $user->fullName]) }}
                    @endif
                </h1>
                <p class="text-gray-500 dark:text-gray-400 text-sm mt-1">{{ __('dashboard.summary') }}</p>
            </div>
            <form method="POST" action="{{ route('profile.availability') }}">
                @csrf @method('PATCH')
                <button type="submit" class="flex items-center gap-2 px-4 py-2 rounded-lg border {{ $user->is_available ? 'border-green-400 bg-green-50 dark:bg-green-900/20 text-green-700 dark:text-green-300' : 'border-gray-300 dark:border-gray-600 text-gray-500 dark:text-gray-400' }} text-sm font-medium hover:shadow-sm transition">
                    <span class="w-2 h-2 rounded-full {{ $user->is_available ? 'bg-green-500' : 'bg-gray-400' }}"></span>
                    {{ $user->is_available ? __('dashboard.available') : __('dashboard.unavailable') }}
                </button>
            </form>
        </div>

        <!-- Onboarding accordion -->
        @php $stepsDoneCount = collect($onboardingSteps)->filter(fn($s) => $s['status'] === 'done')->count(); @endphp
        <div x-data="{ open: false }" class="mb-8 bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 overflow-hidden">
            <button type="button" @click="open = !open"
                class="w-full flex items-center justify-between gap-4 px-5 sm:px-6 py-4 text-left hover:bg-gray-50 dark:hover:bg-gray-700 transition">
                <div class="flex items-center gap-3 min-w-0">
                    <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-indigo-100 dark:bg-indigo-900/50 text-indigo-600 dark:text-indigo-400 text-xs font-bold">
                        {{ $stepsDoneCount }}/4
                    </div>
                    <div class="min-w-0">
                        <p class="text-xs font-semibold uppercase tracking-wide text-indigo-600 dark:text-indigo-400">{{ __('dashboard.onboarding_badge') }}</p>
                        <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ __('dashboard.onboarding_title') }}</h2>
                    </div>
                </div>
                <svg x-show="!open" class="h-5 w-5 shrink-0 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" /></svg>
                <svg x-show="open" class="h-5 w-5 shrink-0 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" x-cloak><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 15.75l7.5-7.5 7.5 7.5" /></svg>
            </button>
            <div x-show="open" x-cloak class="divide-y divide-gray-100 dark:divide-gray-700 border-t border-gray-100 dark:border-gray-700">
                @foreach($onboardingSteps as $step)
                <div class="px-5 sm:px-6 py-4 flex items-start gap-3 @if($step['status'] === 'done') bg-green-50/30 dark:bg-green-950/10 @endif">
                    <div class="mt-0.5 flex h-7 w-7 shrink-0 items-center justify-center rounded-full
                        @if($step['status'] === 'done')
                            bg-green-600 text-white
                        @elseif($step['status'] === 'disabled')
                            bg-gray-200 text-gray-400 dark:bg-gray-700 dark:text-gray-500
                        @else
                            bg-white text-gray-400 ring-1 ring-gray-200 dark:bg-gray-800 dark:ring-gray-700
                        @endif">
                        @if($step['status'] === 'done')
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.4" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
                        @elseif($step['status'] === 'disabled')
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" /></svg>
                        @else
                        <span class="h-2 w-2 rounded-full bg-current"></span>
                        @endif
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-col gap-1 sm:flex-row sm:items-start sm:justify-between">
                            <div>
                                <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ $step['title'] }}</h3>
                                <p class="text-xs text-gray-500 dark:text-gray-400">{{ $step['description'] }}</p>
                            </div>
                            <span class="inline-flex w-fit shrink-0 rounded-full px-2 py-0.5 text-xs font-medium
                                @if($step['status'] === 'done')
                                    bg-green-100 text-green-700 dark:bg-green-900/50 dark:text-green-300
                                @elseif($step['status'] === 'disabled')
                                    bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400
                                @else
                                    bg-white text-gray-600 ring-1 ring-gray-200 dark:bg-gray-800 dark:text-gray-300 dark:ring-gray-700
                                @endif">
                                {{ $step['status_label'] }}
                            </span>
                        </div>
                        @if($step['cta_url'])
                        <a href="{{ $step['cta_url'] }}" class="mt-2 inline-flex items-center text-xs font-medium
                            @if($step['status'] === 'disabled') text-gray-500 cursor-not-allowed @else text-indigo-600 hover:text-indigo-700 hover:underline dark:text-indigo-400 dark:hover:text-indigo-300 @endif">
                            {{ $step['cta_label'] }}
                        </a>
                        @else
                        <span class="mt-2 inline-flex items-center text-xs font-medium text-gray-400">
                            {{ $step['cta_label'] }}
                        </span>
                        @endif
                    </div>
                </div>
                @endforeach
            </div>
        </div>

        <!-- Metrics -->
        <div class="grid grid-cols-2 md:grid-cols-5 gap-4 mb-8">
            <div class="bg-white dark:bg-gray-800 rounded-xl p-4 border border-gray-200 dark:border-gray-700 text-center">
                <p class="text-2xl font-bold text-indigo-600 dark:text-indigo-400">{{ $user->points_balance }}</p>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">{{ __('dashboard.metrics.balance') }}</p>
            </div>
            <div class="bg-white dark:bg-gray-800 rounded-xl p-4 border border-gray-200 dark:border-gray-700 text-center">
                <p class="text-2xl font-bold text-green-600 dark:text-green-400">{{ $earned }}</p>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">{{ __('dashboard.metrics.earned') }}</p>
            </div>
            <div class="bg-white dark:bg-gray-800 rounded-xl p-4 border border-gray-200 dark:border-gray-700 text-center">
                <p class="text-2xl font-bold text-red-500 dark:text-red-400">{{ $spent }}</p>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">{{ __('dashboard.metrics.spent') }}</p>
            </div>
            <div class="bg-white dark:bg-gray-800 rounded-xl p-4 border border-gray-200 dark:border-gray-700 text-center">
                <p class="text-2xl font-bold text-blue-600 dark:text-blue-400">{{ $completedCount }}</p>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">{{ __('dashboard.metrics.completed') }}</p>
            </div>
            <div class="bg-white dark:bg-gray-800 rounded-xl p-4 border border-gray-200 dark:border-gray-700 text-center">
                <p class="text-2xl font-bold text-yellow-500 dark:text-yellow-400">{{ $user->rating ? number_format($user->rating, 1).'/5' : '—' }}</p>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">{{ __('dashboard.metrics.rating') }}</p>
            </div>
        </div>

        @if($referralLink)
        <div id="invitations" class="mb-6 bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-5">
            <h2 class="font-semibold text-gray-900 dark:text-gray-100 mb-2">{{ __('dashboard.invitations') }}</h2>
            <p class="text-sm text-gray-500 dark:text-gray-400 mb-3">
                {{ __('dashboard.invitation_help') }}
            </p>
            <div class="flex gap-2 mb-4" x-data="{ copied: false, link: @js($referralLink) }">
                <input type="text" readonly value="{{ $referralLink }}" data-referral-link
                       class="flex-1 px-3 py-2 text-sm border border-gray-300 dark:border-gray-600 rounded-lg bg-gray-50 dark:bg-gray-700 text-gray-700 dark:text-gray-300 select-all">
                <button type="button" @click="
                    const input = $root.querySelector('[data-referral-link]');
                    if (navigator.clipboard && window.isSecureContext) {
                        navigator.clipboard.writeText(link);
                    } else if (input) {
                        input.select();
                        document.execCommand('copy');
                    }
                    copied = true;
                    setTimeout(() => copied = false, 2000);
                " class="px-4 py-2 text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700 rounded-lg transition whitespace-nowrap">
                    <span x-show="!copied">{{ __('dashboard.copy') }}</span>
                    <span x-show="copied">{{ __('dashboard.copied') }}</span>
                </button>
            </div>
            <div class="flex items-center justify-between">
                <div class="flex gap-6 text-sm text-gray-600 dark:text-gray-400">
                    <div>
                        <span class="font-semibold text-gray-900 dark:text-gray-100">{{ $sentReferralsCount }}</span>
                        <span class="ml-1">{{ __('dashboard.invitation_count') }}</span>
                    </div>
                    <div>
                        <span class="font-semibold text-gray-900 dark:text-gray-100">{{ $activatedReferralsCount }}</span>
                        <span class="ml-1">{{ __('dashboard.activation_count') }}</span>
                    </div>
                    <div>
                        <span class="font-semibold text-gray-900 dark:text-gray-100">{{ $referralPointsEarned }}</span>
                        <span class="ml-1">{{ __('dashboard.points_received') }}</span>
                    </div>
                </div>
                <a href="{{ $_dashRoute('points.index') }}#invitations" class="text-xs text-indigo-600 hover:underline">{{ __('dashboard.view_history') }}</a>
            </div>
        </div>
        @endif

        <!-- Nav shortcuts -->
        <div class="mb-6 flex flex-wrap gap-2">
            <a href="{{ $_dashRoute('points.index') }}" class="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-medium border border-gray-200 dark:border-gray-700 rounded-lg text-gray-600 dark:text-gray-400 hover:bg-white dark:hover:bg-gray-800 transition">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v12m-3-2.818l.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                {{ __('dashboard.points_history') }}
            </a>
            <a href="{{ $_dashRoute('favorites.index') }}" class="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-medium border border-gray-200 dark:border-gray-700 rounded-lg text-gray-600 dark:text-gray-400 hover:bg-white dark:hover:bg-gray-800 transition">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M21 8.25c0-2.485-2.099-4.5-4.688-4.5-1.935 0-3.597 1.126-4.312 2.733-.715-1.607-2.377-2.733-4.313-2.733C5.1 3.75 3 5.765 3 8.25c0 7.22 9 12 9 12s9-4.78 9-12z" /></svg>
                {{ __('dashboard.my_favorites') }}
            </a>
            <a href="{{ $_dashRoute('profile.show', ['user' => $user]) }}" class="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-medium border border-gray-200 dark:border-gray-700 rounded-lg text-gray-600 dark:text-gray-400 hover:bg-white dark:hover:bg-gray-800 transition">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z" /></svg>
                {{ __('dashboard.public_profile') }}
            </a>
            <a href="{{ $_dashRoute('points.index') }}#invitations" class="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-medium border border-gray-200 dark:border-gray-700 rounded-lg text-gray-600 dark:text-gray-400 hover:bg-white dark:hover:bg-gray-800 transition">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75" /></svg>
                {{ __('dashboard.invitations') }}
            </a>
        </div>

        @if(session('success'))
        <div x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 3000)"
            class="mb-6 bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 text-green-700 dark:text-green-300 px-4 py-3 rounded-lg text-sm">
            {{ session('success') }}
        </div>
        @endif
        @if(session('error'))
        <div x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 4000)"
            class="mb-6 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 text-red-700 dark:text-red-300 px-4 py-3 rounded-lg text-sm">
            {{ session('error') }}
        </div>
        @endif

        @if(! $aiProfileDisabled && (! $aiProfile || $aiProfile->status !== 'published'))
        <div class="mb-6 bg-gradient-to-br from-indigo-50 to-white dark:from-indigo-950/30 dark:to-gray-800 rounded-2xl border border-indigo-200 dark:border-indigo-800 p-6">
            <div class="flex items-start gap-4 sm:items-center flex-col sm:flex-row">
                <div class="w-12 h-12 bg-indigo-100 dark:bg-indigo-900/50 rounded-xl flex items-center justify-center shrink-0">
                    <svg class="w-6 h-6 text-indigo-600 dark:text-indigo-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M9.75 3.104v5.714a2.25 2.25 0 01-.659 1.591L5 14.5M9.75 3.104c-.251.023-.501.05-.75.082m.75-.082a24.301 24.301 0 014.5 0m0 0v5.714c0 .597.237 1.17.659 1.591L19.8 15.3M14.25 3.104c.251.023.501.05.75.082M19.8 15.3l-1.57.393A9.065 9.065 0 0112 15a9.065 9.065 0 00-6.23.693L5 14.5m14.8.8l1.402 1.402c1.232 1.232.65 3.318-1.067 3.611A48.309 48.309 0 0112 21c-2.773 0-5.491-.235-8.135-.687-1.718-.293-2.3-2.379-1.067-3.61L5 14.5"/></svg>
                </div>
                <div class="flex-1">
                    <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">
                        @if($aiProfile && $aiProfile->status === 'draft')
                            {{ __('dashboard.resume_ai_profile') }}
                        @else
                            {{ __('dashboard.create_ai_profile') }}
                        @endif
                    </h2>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                        @if($aiProfile && $aiProfile->status === 'draft')
                            {{ __('dashboard.resume_ai_profile_body') }}
                        @else
                            {{ __('dashboard.create_ai_profile_body') }}
                        @endif
                    </p>
                </div>
                <a href="{{ $_dashRoute('agent-ia.wizard') }}"
                   class="inline-flex items-center px-5 py-2.5 bg-indigo-600 text-white text-sm font-semibold rounded-xl hover:bg-indigo-700 transition active:scale-95 shadow-sm whitespace-nowrap">
                    {{ $aiProfile && $aiProfile->status === 'draft' ? __('dashboard.continue') : __('dashboard.configure') }}
                </a>
            </div>
        </div>
        @endif

        <div class="grid md:grid-cols-2 gap-6">
            <!-- Services -->
            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
                <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700 flex items-center justify-between">
                    <h2 class="font-semibold text-gray-900 dark:text-gray-100">{{ __('dashboard.my_services') }}</h2>
                    <div class="flex items-center gap-3">
                        <a href="{{ $_dashRoute('dashboard.services') }}" class="text-xs text-indigo-600 hover:underline">{{ __('dashboard.view_all') }}</a>
                        <a href="{{ $_dashServicesCreateHref }}" class="text-xs text-indigo-600 hover:underline">+ {{ __('dashboard.new') }}</a>
                    </div>
                </div>
                <div class="divide-y divide-gray-100 dark:divide-gray-700">
                    @forelse($myServices as $service)
                    <div class="px-5 py-3 flex items-center justify-between">
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-gray-900 dark:text-gray-100 truncate">{{ $service->title }}</p>
                            <p class="text-xs text-gray-500">{{ $service->points_cost }} pts · {{ $service->category->displayName('transactions') }}</p>
                        </div>
                        <div class="flex gap-3 ml-3 flex-shrink-0">
                            <a href="{{ $_dashRoute('services.edit', ['service' => $service]) }}" class="text-xs text-gray-500 hover:text-indigo-600">{{ __('dashboard.edit') }}</a>
                            <form method="POST" action="{{ $_dashRoute('services.destroy', ['service' => $service]) }}" x-data="{ asked: false }">
                                @csrf @method('DELETE')
                                <template x-if="!asked">
                                    <button type="button" @click="asked = true" class="text-xs text-red-500 hover:text-red-700">{{ __('dashboard.delete') }}</button>
                                </template>
                                <template x-if="asked">
                                    <span class="flex gap-1 items-center text-xs">
                                        <span class="text-gray-500">{{ __('dashboard.sure') }}</span>
                                        <button type="submit" class="text-red-600 font-semibold hover:underline">{{ __('dashboard.yes') }}</button>
                                        <button type="button" @click="asked = false" class="text-gray-400 hover:underline">{{ __('dashboard.no') }}</button>
                                    </span>
                                </template>
                            </form>
                        </div>
                    </div>
                    @empty
                    <p class="px-5 py-8 text-sm text-gray-400 text-center">{!! __('dashboard.no_active_service') !!}<br><a href="{{ $_dashServicesCreateHref }}" class="text-indigo-600 hover:underline">{{ __('dashboard.create_service') }}</a></p>
                    @endforelse
                </div>
            </div>

            <!-- Requests -->
            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
                <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700 flex items-center justify-between">
                    <h2 class="font-semibold text-gray-900 dark:text-gray-100">{{ __('dashboard.my_requests') }}</h2>
                    <div class="flex items-center gap-3">
                        <a href="{{ $_dashRoute('dashboard.requests') }}" class="text-xs text-indigo-600 hover:underline">{{ __('dashboard.view_all') }}</a>
                        <a href="{{ $requestCreateUrl }}" class="text-xs text-indigo-600 hover:underline">+ {{ __('dashboard.new') }}</a>
                    </div>
                </div>
                <div class="divide-y divide-gray-100 dark:divide-gray-700">
                    @forelse($myRequests as $req)
                    <div class="px-5 py-3 flex items-center justify-between">
                        <div class="min-w-0">
                            <a href="{{ $_dashRoute('requests.show', ['request' => $req]) }}" class="text-sm font-medium text-gray-900 dark:text-gray-100 truncate hover:text-indigo-600">{{ $req->title }}</a>
                            <p class="text-xs text-gray-500">{{ $req->budget_min }}{{ $req->budget_max ? '–'.$req->budget_max : '+' }} pts</p>
                        </div>
                        <form method="POST" action="{{ $_dashRoute('requests.destroy', ['request' => $req]) }}" class="ml-3" x-data="{ asked: false }">
                            @csrf @method('DELETE')
                            <template x-if="!asked">
                                <button type="button" @click="asked = true" class="text-xs text-red-500 hover:text-red-700">{{ __('dashboard.close_request') }}</button>
                            </template>
                            <template x-if="asked">
                                <span class="flex gap-1 items-center text-xs">
                                    <span class="text-gray-500">{{ __('dashboard.sure') }}</span>
                                    <button type="submit" class="text-red-600 font-semibold hover:underline">{{ __('dashboard.yes') }}</button>
                                    <button type="button" @click="asked = false" class="text-gray-400 hover:underline">{{ __('dashboard.no') }}</button>
                                </span>
                            </template>
                        </form>
                    </div>
                    @empty
                    <p class="px-5 py-8 text-sm text-gray-400 text-center">{!! __('dashboard.no_open_request') !!}<br><a href="{{ $requestCreateUrl }}" class="text-indigo-600 hover:underline">{{ __('dashboard.ask_help') }}</a></p>
                    @endforelse
                </div>
            </div>

            <!-- Buyer proposals -->
            @if($myProposals->isNotEmpty())
            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
                <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700">
                    <h2 class="font-semibold text-gray-900 dark:text-gray-100">{{ __('dashboard.my_current_exchanges') }}</h2>
                </div>
                <div class="divide-y divide-gray-100 dark:divide-gray-700">
                    @foreach($myProposals as $tx)
                    <a href="{{ $_dashRoute('messages.show', ['transaction' => $tx]) }}" class="px-5 py-3 flex items-center gap-3 hover:bg-gray-50 dark:hover:bg-gray-700 transition">
                        <img src="{{ $tx->seller->avatar_url }}" class="w-8 h-8 rounded-full flex-shrink-0" alt="">
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-medium text-gray-900 dark:text-gray-100 truncate">{{ $tx->subject }}</p>
                            <p class="text-xs text-gray-500">{{ __('dashboard.to_member', ['name' => $tx->seller->name]) }} · {{ $tx->points_proposed }} pts</p>
                        </div>
                        <span class="text-xs px-2 py-0.5 rounded-full flex-shrink-0
                            {{ match($tx->status) {
                                'pending'    => 'bg-orange-100 dark:bg-orange-900 text-orange-700 dark:text-orange-300',
                                'accepted'   => 'bg-blue-100 dark:bg-blue-900 text-blue-700 dark:text-blue-300',
                                'buyer_done' => 'bg-purple-100 dark:bg-purple-900 text-purple-700 dark:text-purple-300',
                                default      => 'bg-gray-100 text-gray-600',
                            } }}">{{ $tx->status_label }}</span>
                    </a>
                    @endforeach
                </div>
            </div>
            @endif

            <!-- Active exchanges -->
            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
                <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700">
                    <h2 class="font-semibold text-gray-900 dark:text-gray-100">{{ __('dashboard.current_exchanges') }}</h2>
                </div>
                <div class="divide-y divide-gray-100 dark:divide-gray-700">
                    @forelse($activeExchanges as $tx)
                    @php $other = auth()->id() === $tx->buyer_id ? $tx->seller : $tx->buyer; @endphp
                    <a href="{{ $_dashRoute('messages.show', ['transaction' => $tx]) }}" class="px-5 py-3 flex items-center gap-3 hover:bg-gray-50 dark:hover:bg-gray-700 transition">
                        <img src="{{ $other->avatar_url }}" class="w-8 h-8 rounded-full flex-shrink-0" alt="">
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-medium text-gray-900 dark:text-gray-100 truncate">{{ $tx->subject }}</p>
                            <p class="text-xs text-gray-500">{{ __('dashboard.with_member', ['name' => $other->name]) }} · {{ $tx->points_agreed }} pts</p>
                        </div>
                        <span class="text-xs px-2 py-0.5 rounded-full bg-blue-100 dark:bg-blue-900 text-blue-700 dark:text-blue-300 flex-shrink-0">{{ $tx->status_label }}</span>
                    </a>
                    @empty
                    <p class="px-5 py-8 text-sm text-gray-400 text-center">{{ __('dashboard.no_current_exchange') }}</p>
                    @endforelse
                </div>
            </div>

            @if($user->is_admin || ($user->organization && $user->organization->admin_id === $user->id))
            <!-- Feed posts -->
            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
                <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700 flex items-center justify-between">
                    <h2 class="font-semibold text-gray-900 dark:text-gray-100">{{ __('dashboard.my_announcements') }}</h2>
                    @if($canCreateFeedPost)
                    <a href="{{ $feedCreateUrl }}" class="text-xs text-indigo-600 hover:underline">+ {{ __('dashboard.new') }}</a>
                    @endif
                </div>
                <div class="divide-y divide-gray-100 dark:divide-gray-700">
                    @forelse($myFeedPosts as $post)
                    <a href="{{ $feedUrl }}" class="px-5 py-3 flex items-center justify-between hover:bg-gray-50 dark:hover:bg-gray-700 transition">
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-medium text-gray-900 dark:text-gray-100 truncate">{{ $post->title ?: __('dashboard.untitled') }}</p>
                            <p class="text-xs text-gray-500">{{ $post->created_at->isoFormat('D MMM YYYY') }}</p>
                        </div>
                        <span class="text-xs px-2 py-0.5 rounded-full flex-shrink-0 ml-3
                            {{ $post->status === 'published' ? 'bg-green-100 dark:bg-green-900 text-green-700 dark:text-green-300' : 'bg-gray-100 dark:bg-gray-700 text-gray-500 dark:text-gray-400' }}">
                            {{ $post->status === 'published' ? __('dashboard.published') : __('dashboard.draft') }}
                        </span>
                    </a>
                    @empty
                    <p class="px-5 py-8 text-sm text-gray-400 text-center">
                        {{ __('dashboard.no_announcement') }}
                        @if($canCreateFeedPost)
                        <br><a href="{{ $feedCreateUrl }}" class="text-indigo-600 hover:underline">{{ __('dashboard.create_announcement') }}</a>
                        @endif
                    </p>
                    @endforelse
                </div>
                @if($myFeedPosts->isNotEmpty())
                <div class="px-5 py-3 border-t border-gray-100 dark:border-gray-700 text-center">
                    <a href="{{ $myFeedPostsUrl }}" class="text-xs text-indigo-600 hover:underline">{{ __('dashboard.view_all_announcements') }}</a>
                </div>
                @endif
            </div>
            @endif

            <!-- Recent messages -->
            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
                <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700 flex items-center justify-between">
                    <h2 class="font-semibold text-gray-900 dark:text-gray-100">{{ __('dashboard.recent_messages') }}</h2>
                    <a href="{{ $_dashRoute('messages.index') }}" class="text-xs text-indigo-600 hover:underline">{{ __('dashboard.view_all') }}</a>
                </div>
                <div class="divide-y divide-gray-100 dark:divide-gray-700">
                    @forelse($recentMessages as $tx)
                    @php $other = auth()->id() === $tx->buyer_id ? $tx->seller : $tx->buyer; $lastMsg = $tx->messages->first(); @endphp
                    <a href="{{ $_dashRoute('messages.show', ['transaction' => $tx]) }}" class="px-5 py-3 flex items-center gap-3 hover:bg-gray-50 dark:hover:bg-gray-700 transition">
                        <img src="{{ $other->avatar_url }}" class="w-8 h-8 rounded-full flex-shrink-0" alt="">
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ $other->name }}</p>
                            @if($lastMsg)
                            <p class="text-xs text-gray-400 truncate">{{ Str::limit($lastMsg->body, 45) }}</p>
                            @endif
                        </div>
                    </a>
                    @empty
                    <p class="px-5 py-8 text-sm text-gray-400 text-center">{{ __('dashboard.no_recent_message') }}</p>
                    @endforelse
                </div>
            </div>
        </div>

        <!-- Admin shortcuts -->
        @if($user->is_admin || ($user->organization && $user->organization->admin_id === $user->id))
        <div class="mt-6 flex flex-wrap gap-3">
            @if($user->is_admin)
            <a href="{{ route('admin.dashboard') }}" class="px-4 py-2 text-sm border border-purple-300 dark:border-purple-700 rounded-lg text-purple-600 dark:text-purple-400 hover:bg-purple-50 dark:hover:bg-purple-900/20 transition font-medium">
                {{ __('dashboard.admin_dashboard') }}
            </a>
            @elseif($user->organization && $user->organization->admin_id === $user->id)
            <a href="{{ route('organization.admin.dashboard', ['organization' => $user->organization->slug]) }}" class="px-4 py-2 text-sm border border-purple-300 dark:border-purple-700 rounded-lg text-purple-600 dark:text-purple-400 hover:bg-purple-50 dark:hover:bg-purple-900/20 transition font-medium">
                {{ __('dashboard.org_admin_shortcut') }}
            </a>
            @endif
        </div>
        @endif
    </div>
</x-app-layout>
