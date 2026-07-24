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
        <div class="hidden sm:flex sm:items-center sm:justify-between sm:gap-3 sm:mb-8">
            <div class="min-w-0">
                @php $tenant = $currentOrganization ?? null; @endphp
                <h1 class="text-xl sm:text-2xl font-bold text-gray-900 dark:text-gray-100 break-words">
                    @if($tenant)
                        {{ __('dashboard.title_with_tenant', ['name' => $tenant->name]) }}
                    @else
                        {{ __('dashboard.hello', ['name' => $user->fullName]) }}
                    @endif
                </h1>
                <p class="text-gray-500 dark:text-gray-400 text-sm mt-1">{{ __('dashboard.summary') }}</p>
            </div>
        </div>

        <x-user-dashboard-nav class="mb-8" />

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
            <div x-data="{ open: false }" class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
                <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700 flex items-center justify-between">
                    <button type="button" @click="open = !open" class="flex min-w-0 items-center gap-2 text-left font-semibold text-gray-900 dark:text-gray-100">
                        <span class="truncate">{{ __('dashboard.my_services') }}</span>
                        <svg :class="open ? 'rotate-180' : ''" class="h-4 w-4 shrink-0 text-gray-400 transition-transform" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" /></svg>
                    </button>
                    <div class="flex shrink-0 items-center gap-3">
                        <a href="{{ $_dashRoute('dashboard.services') }}" class="text-xs text-indigo-600 hover:underline">{{ __('dashboard.view_all') }}</a>
                        <a href="{{ $_dashServicesCreateHref }}" class="text-xs text-indigo-600 hover:underline">+ {{ __('dashboard.new') }}</a>
                    </div>
                </div>
                <div x-show="open" x-cloak class="divide-y divide-gray-100 dark:divide-gray-700">
                    @forelse($myServices as $service)
                    <div class="px-5 py-3 flex items-start gap-2">
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-medium text-gray-900 dark:text-gray-100 truncate">{{ $service->title }}</p>
                            <p class="text-xs text-gray-500">{{ $service->points_cost }} pts · {{ $service->category->displayName('transactions') }}</p>
                        </div>
                        <div class="flex gap-3 flex-shrink-0 items-center">
                            <a href="{{ $_dashRoute('services.edit', ['service' => $service]) }}" class="text-xs text-gray-500 hover:text-indigo-600 whitespace-nowrap">{{ __('dashboard.edit') }}</a>
                            <form method="POST" action="{{ $_dashRoute('services.destroy', ['service' => $service]) }}" x-data="{ asked: false }">
                                @csrf @method('DELETE')
                                <template x-if="!asked">
                                    <button type="button" @click="asked = true" class="text-xs text-red-500 hover:text-red-700 whitespace-nowrap">{{ __('dashboard.delete') }}</button>
                                </template>
                                <template x-if="asked">
                                    <span class="flex flex-wrap gap-x-1 items-center text-xs">
                                        <span class="text-gray-500 whitespace-nowrap">{{ __('dashboard.sure') }}</span>
                                        <button type="submit" class="text-red-600 font-semibold hover:underline whitespace-nowrap">{{ __('dashboard.yes') }}</button>
                                        <button type="button" @click="asked = false" class="text-gray-400 hover:underline whitespace-nowrap">{{ __('dashboard.no') }}</button>
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
            <div x-data="{ open: false }" class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
                <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700 flex items-center justify-between">
                    <button type="button" @click="open = !open" class="flex min-w-0 items-center gap-2 text-left font-semibold text-gray-900 dark:text-gray-100">
                        <span class="truncate">{{ __('dashboard.my_requests') }}</span>
                        <svg :class="open ? 'rotate-180' : ''" class="h-4 w-4 shrink-0 text-gray-400 transition-transform" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" /></svg>
                    </button>
                    <div class="flex shrink-0 items-center gap-3">
                        <a href="{{ $_dashRoute('dashboard.requests') }}" class="text-xs text-indigo-600 hover:underline">{{ __('dashboard.view_all') }}</a>
                        <a href="{{ $requestCreateUrl }}" class="text-xs text-indigo-600 hover:underline">+ {{ __('dashboard.new') }}</a>
                    </div>
                </div>
                <div x-show="open" x-cloak class="divide-y divide-gray-100 dark:divide-gray-700">
                    @forelse($myRequests as $req)
                    <div class="px-5 py-3 flex items-start gap-2">
                        <div class="min-w-0 flex-1">
                            <a href="{{ $_dashRoute('requests.show', ['request' => $req]) }}" class="text-sm font-medium text-gray-900 dark:text-gray-100 truncate hover:text-indigo-600">{{ $req->title }}</a>
                            <p class="text-xs text-gray-500">{{ $req->budget_min }}{{ $req->budget_max ? '–'.$req->budget_max : '+' }} pts</p>
                        </div>
                        <form method="POST" action="{{ $_dashRoute('requests.destroy', ['request' => $req]) }}" class="flex-shrink-0" x-data="{ asked: false }">
                            @csrf @method('DELETE')
                            <template x-if="!asked">
                                <button type="button" @click="asked = true" class="text-xs text-red-500 hover:text-red-700 whitespace-nowrap">{{ __('dashboard.close_request') }}</button>
                            </template>
                            <template x-if="asked">
                                <span class="flex flex-wrap gap-x-1 items-center text-xs">
                                    <span class="text-gray-500 whitespace-nowrap">{{ __('dashboard.sure') }}</span>
                                    <button type="submit" class="text-red-600 font-semibold hover:underline whitespace-nowrap">{{ __('dashboard.yes') }}</button>
                                    <button type="button" @click="asked = false" class="text-gray-400 hover:underline whitespace-nowrap">{{ __('dashboard.no') }}</button>
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
            <div x-data="{ open: false }" class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
                <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700">
                    <button type="button" @click="open = !open" class="flex w-full items-center justify-between gap-3 text-left font-semibold text-gray-900 dark:text-gray-100">
                        <span class="truncate">{{ __('dashboard.my_current_exchanges') }}</span>
                        <svg :class="open ? 'rotate-180' : ''" class="h-4 w-4 shrink-0 text-gray-400 transition-transform" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" /></svg>
                    </button>
                </div>
                <div x-show="open" x-cloak class="divide-y divide-gray-100 dark:divide-gray-700">
                    @foreach($myProposals as $tx)
                    <a href="{{ $_dashRoute('messages.show', ['transaction' => $tx]) }}" class="px-5 py-3 flex items-center gap-3 hover:bg-gray-50 dark:hover:bg-gray-700 transition">
                        <img src="{{ $tx->seller->avatar_url }}" class="w-8 h-8 rounded-full flex-shrink-0 self-start sm:self-center" alt="">
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-medium text-gray-900 dark:text-gray-100 truncate">{{ $tx->subject }}</p>
                            <p class="text-xs text-gray-500">{{ __('dashboard.to_member', ['name' => $tx->seller->full_name]) }} · {{ $tx->points_proposed }} pts</p>
                        </div>
                        <span class="text-xs px-2 py-0.5 rounded-full flex-shrink-0 self-start sm:self-center
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
            <div x-data="{ open: false }" class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
                <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700">
                    <button type="button" @click="open = !open" class="flex w-full items-center justify-between gap-3 text-left font-semibold text-gray-900 dark:text-gray-100">
                        <span class="truncate">{{ __('dashboard.current_exchanges') }}</span>
                        <svg :class="open ? 'rotate-180' : ''" class="h-4 w-4 shrink-0 text-gray-400 transition-transform" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" /></svg>
                    </button>
                </div>
                <div x-show="open" x-cloak class="divide-y divide-gray-100 dark:divide-gray-700">
                    @forelse($activeExchanges as $tx)
                    @php $other = auth()->id() === $tx->buyer_id ? $tx->seller : $tx->buyer; @endphp
                    <a href="{{ $_dashRoute('messages.show', ['transaction' => $tx]) }}" class="px-5 py-3 flex items-center gap-3 hover:bg-gray-50 dark:hover:bg-gray-700 transition">
                        <img src="{{ $other->avatar_url }}" class="w-8 h-8 rounded-full flex-shrink-0 self-start sm:self-center" alt="">
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-medium text-gray-900 dark:text-gray-100 truncate">{{ $tx->subject }}</p>
                            <p class="text-xs text-gray-500">{{ __('dashboard.with_member', ['name' => $other->name]) }} · {{ $tx->points_agreed }} pts</p>
                        </div>
                        <span class="text-xs px-2 py-0.5 rounded-full bg-blue-100 dark:bg-blue-900 text-blue-700 dark:text-blue-300 flex-shrink-0 self-start sm:self-center">{{ $tx->status_label }}</span>
                    </a>
                    @empty
                    <p class="px-5 py-8 text-sm text-gray-400 text-center">{{ __('dashboard.no_current_exchange') }}</p>
                    @endforelse
                </div>
            </div>

            <!-- Recent messages -->
            <div x-data="{ open: false }" class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
                <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700 flex items-center justify-between">
                    <button type="button" @click="open = !open" class="flex min-w-0 items-center gap-2 text-left font-semibold text-gray-900 dark:text-gray-100">
                        <span class="truncate">{{ __('dashboard.recent_messages') }}</span>
                        <svg :class="open ? 'rotate-180' : ''" class="h-4 w-4 shrink-0 text-gray-400 transition-transform" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" /></svg>
                    </button>
                    <a href="{{ $_dashRoute('messages.index') }}" class="text-xs text-indigo-600 hover:underline">{{ __('dashboard.view_all') }}</a>
                </div>
                <div x-show="open" x-cloak class="divide-y divide-gray-100 dark:divide-gray-700">
                    @forelse($recentMessages as $tx)
                    @php $other = auth()->id() === $tx->buyer_id ? $tx->seller : $tx->buyer; $lastMsg = $tx->messages->first(); @endphp
                    <a href="{{ $_dashRoute('messages.show', ['transaction' => $tx]) }}" class="px-5 py-3 flex items-center gap-3 hover:bg-gray-50 dark:hover:bg-gray-700 transition">
                        <img src="{{ $other->avatar_url }}" class="w-8 h-8 rounded-full flex-shrink-0 self-start sm:self-center" alt="">
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
