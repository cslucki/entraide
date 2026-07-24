<x-app-layout>
    <x-slot name="title">{{ __('profile.back_to_directory') }} — {{ $user->fullName }}</x-slot>

    @php
        $organizationRouteParam = request()->route('organization');
        $agentAiChatUrl = $organizationRouteParam && Route::has('organization.agent-ia.profile.chat')
            ? route('organization.agent-ia.profile.chat', ['organization' => $organizationRouteParam, 'user' => $user])
            : route('agent-ia.profile.chat', $user);

        $displaySkills = $services->flatMap(fn($s) => $s->skills)->unique('id')->take(8);
        $serviceCategories = $services->pluck('category.name')->unique()->take(4);
    @endphp

    <!-- Desktop topbar -->
    <div class="hidden md:flex items-center gap-3 px-4 sm:px-6 lg:px-8 py-3 border-b border-gray-200 dark:border-gray-700 bg-[var(--bp-surface)] sticky top-0 z-30">
        <a href="{{ route('members.index') }}" class="inline-flex h-8 w-8 items-center justify-center rounded-full bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-600 flex-shrink-0" aria-label="{{ __('profile.back_to_directory') }}">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
        </a>
        <span class="truncate text-sm font-medium text-gray-900 dark:text-gray-100">{{ $user->fullName }}</span>
    </div>

    <x-page-container>
        <!-- Profile Header -->
        <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-5 sm:p-7 mb-5 relative">
            @if(auth()->check() && auth()->id() === $user->id)
            <div class="absolute top-4 right-4 z-10">
                <a href="{{ route('profile.edit') }}"
                   class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold rounded-lg transition">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/></svg>
                    {{ __('profile.edit_profile') }}
                </a>
            </div>
            @endif

            <div class="flex flex-col gap-5 sm:flex-row sm:gap-6">
                <!-- Avatar -->
                <div class="relative flex-shrink-0 mx-auto sm:mx-0">
                    <img src="{{ $user->avatar_url }}" class="h-20 w-20 sm:h-24 sm:w-24 rounded-full object-cover ring-2 ring-gray-200 dark:ring-gray-600" alt="">
                    @if(auth()->check() && auth()->id() === $user->id)
                    <a href="{{ route('profile.edit') }}"
                       class="absolute bottom-0 right-0 w-6 h-6 bg-indigo-600 hover:bg-indigo-700 rounded-full flex items-center justify-center shadow-md transition"
                       title="{{ __('profile.edit_profile') }}">
                        <svg class="w-3 h-3 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/></svg>
                    </a>
                    @endif
                </div>

                <!-- Identity + Stats -->
                <div class="flex-1 min-w-0 text-center sm:text-left">
                    <div class="flex flex-col sm:flex-row sm:items-center gap-2 sm:gap-3">
                        <h1 class="text-xl sm:text-2xl font-bold text-gray-900 dark:text-gray-100 truncate">{{ $user->fullName }}</h1>
                        @if($user->is_available)
                        <span class="inline-flex items-center self-center gap-1.5 px-2.5 py-0.5 bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-300 rounded-full text-xs font-medium w-fit">
                            <span class="w-1.5 h-1.5 bg-green-500 rounded-full"></span>{{ __('profile.available') }}
                        </span>
                        @endif
                    </div>

                    <!-- Stats chips -->
                    <div class="flex flex-wrap justify-center sm:justify-start gap-2 mt-3">
                        @if($user->rating)
                        <span class="inline-flex items-center gap-1 px-2.5 py-1 bg-yellow-50 dark:bg-yellow-900/20 text-yellow-700 dark:text-yellow-300 rounded-lg text-xs font-medium">
                            ⭐ {{ number_format($user->rating, 1) }}
                        </span>
                        @endif
                        <span class="inline-flex items-center gap-1 px-2.5 py-1 bg-gray-50 dark:bg-gray-700/50 text-gray-600 dark:text-gray-300 rounded-lg text-xs font-medium">
                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                            {{ trans_choice('profile.exchanges', $completedCount, ['count' => $completedCount]) }}
                        </span>
                        <span class="inline-flex items-center gap-1 px-2.5 py-1 bg-indigo-50 dark:bg-indigo-900/20 text-indigo-600 dark:text-indigo-300 rounded-lg text-xs font-medium">
                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                            {{ $user->points_balance }} pts
                        </span>
                        @if($user->public_location)
                        <span class="inline-flex items-center gap-1 px-2.5 py-1 bg-gray-50 dark:bg-gray-700/50 text-gray-500 dark:text-gray-400 rounded-lg text-xs font-medium">
                            📍 {{ $user->public_location }}
                        </span>
                        @endif
                    </div>

                    <!-- Links row -->
                    @if($user->website || $user->linkedin_url)
                    <div class="flex flex-wrap justify-center sm:justify-start gap-3 mt-3">
                        @if($user->website)
                        <a href="{{ $user->website }}" target="_blank" rel="noopener noreferrer"
                           class="inline-flex items-center gap-1 text-xs text-indigo-600 dark:text-indigo-400 hover:underline">
                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9"/></svg>
                            {{ parse_url($user->website, PHP_URL_HOST) ?: $user->website }}
                        </a>
                        @endif
                        @if($user->linkedin_url)
                        <a href="{{ $user->linkedin_url }}" target="_blank" rel="noopener noreferrer"
                           class="inline-flex items-center gap-1 text-xs text-blue-600 dark:text-blue-400 hover:underline">
                            <svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 24 24"><path d="M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433a2.062 2.062 0 01-2.063-2.065 2.064 2.064 0 112.063 2.065zm1.782 13.019H3.555V9h3.564v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.222 0h.003z"/></svg>
                            LinkedIn
                        </a>
                        @endif
                    </div>
                    @endif

                    <!-- Badges -->
                    @if($badges->isNotEmpty())
                    <div class="flex flex-wrap justify-center sm:justify-start gap-2 mt-3">
                        @foreach($badges as $badge)
                        <span title="{{ $badge->description }}" class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-semibold text-white cursor-default"
                              style="background-color: {{ $badge->color }}">
                            {{ $badge->icon }} {{ $badge->name }}
                        </span>
                        @endforeach
                    </div>
                    @endif

                    <!-- Contact info -->
                    @if(($user->show_email && $user->email) || ($user->show_phone && $user->phone))
                    <div class="flex flex-wrap justify-center sm:justify-start gap-x-4 gap-y-1 mt-3 text-xs text-gray-400 dark:text-gray-500">
                        @if($user->show_email && $user->email)
                        <span class="inline-flex items-center gap-1">{{ $user->email }}</span>
                        @endif
                        @if($user->show_phone && $user->phone)
                        <span class="inline-flex items-center gap-1">{{ $user->phone }}</span>
                        @endif
                    </div>
                    @endif
                </div>
            </div>
        </div>

        <!-- Main Grid -->
        <div class="md:grid md:grid-cols-3 md:gap-6">

            <!-- Left column -->
            <div class="md:col-span-2 space-y-5">

                <!-- About -->
                @if($user->bio)
                <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-5 sm:p-6">
                    <h2 class="text-sm font-semibold text-gray-400 dark:text-gray-500 uppercase tracking-wider mb-3">{{ __('profile.about') }}</h2>
                    <div class="text-sm text-gray-700 dark:text-gray-300 whitespace-pre-line leading-relaxed">
                        {{ $user->bio }}
                    </div>
                </div>
                @endif

                <!-- What this member can offer -->
                @if($services->isNotEmpty() || $displaySkills->isNotEmpty() || $memberAiProfile)
                <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-5 sm:p-6">
                    <h2 class="text-sm font-semibold text-gray-400 dark:text-gray-500 uppercase tracking-wider mb-4">{{ __('profile.can_offer') }}</h2>
                    <div class="grid gap-3 sm:grid-cols-2">
                        @if($displaySkills->isNotEmpty())
                        <div class="flex flex-col gap-2">
                            <span class="text-xs font-medium text-gray-500 dark:text-gray-400 flex items-center gap-1.5">
                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"/></svg>
                                {{ __('profile.skills') }}
                            </span>
                            <div class="flex flex-wrap gap-1.5">
                                @foreach($displaySkills as $skill)
                                <span class="px-2 py-1 bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-lg text-xs font-medium">{{ $skill->name }}</span>
                                @endforeach
                            </div>
                        </div>
                        @endif

                        @if($serviceCategories->isNotEmpty())
                        <div class="flex flex-col gap-2">
                            <span class="text-xs font-medium text-gray-500 dark:text-gray-400 flex items-center gap-1.5">
                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 13.255A23.931 23.931 0 0112 15c-3.183 0-6.22-.62-9-1.745M16 6V4a2 2 0 00-2-2h-4a2 2 0 00-2 2v2m4 6h.01M5 20h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                                {{ __('profile.services_offered') }}
                            </span>
                            <div class="flex flex-wrap gap-1.5">
                                @foreach($serviceCategories as $catName)
                                <span class="px-2 py-1 bg-indigo-50 dark:bg-indigo-900/20 text-indigo-600 dark:text-indigo-300 rounded-lg text-xs font-medium">{{ $catName }}</span>
                                @endforeach
                            </div>
                        </div>
                        @endif

                        @if($memberAiProfile)
                        <div class="flex flex-col gap-2 sm:col-span-2">
                            <span class="text-xs font-medium text-gray-500 dark:text-gray-400 flex items-center gap-1.5">
                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                                {{ __('profile.ai_agent_available') }}
                            </span>
                            <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('profile.ai_agent_enabled') }}</p>
                        </div>
                        @endif
                    </div>
                </div>
                @endif

                <!-- AI profile owner -->
                @if($memberAiProfile && auth()->check() && auth()->id() === $user->id)
                <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-5">
                    <div class="flex items-center justify-between gap-4">
                        <div class="flex items-center gap-3">
                            <div class="flex h-10 w-10 items-center justify-center rounded-full bg-violet-100 dark:bg-violet-900/30">
                                <svg class="h-5 w-5 text-violet-600 dark:text-violet-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                                </svg>
                            </div>
                            <div>
                                <p class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ __('profile.ai_agent_available') }}</p>
                                <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('profile.ai_agent_enabled') }}</p>
                            </div>
                        </div>
                        <a href="{{ route('agent-ia.interactions') }}" class="inline-flex items-center gap-1.5 rounded-lg border border-gray-300 dark:border-gray-600 px-4 py-2 text-sm font-semibold text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700 transition">
                            {{ __('profile.view_exchanges') }}
                        </a>
                    </div>
                </div>
                @endif

                <!-- Mobile CTAs -->
                <div class="space-y-3 md:hidden">
                    @if($memberAiProfile && (auth()->guest() || auth()->id() !== $user->id))
                    <a href="{{ $agentAiChatUrl }}"
                       class="group flex items-start gap-4 rounded-xl border-2 border-indigo-300 dark:border-indigo-700 bg-white dark:bg-gray-800 p-4 transition hover:border-indigo-400 hover:shadow-lg">
                        <span class="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-lg bg-indigo-600 text-white shadow-md ring-1 ring-indigo-500/30 transition group-hover:scale-110 group-hover:bg-indigo-700">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                        </span>
                        <span class="min-w-0 flex-1">
                            <span class="block text-sm font-semibold text-gray-900 dark:text-gray-100">{{ __('profile.agent_cta_title') }}</span>
                            <span class="mt-0.5 block text-xs leading-relaxed text-gray-600 dark:text-gray-400">{{ __('profile.agent_cta_hint') }}</span>
                        </span>
                        <svg class="w-4 h-4 flex-shrink-0 self-center text-indigo-500 transition group-hover:translate-x-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                    </a>
                    @endif

                    @if(auth()->guest() || auth()->id() !== $user->id)
                    <a href="{{ auth()->check() ? route('messages.with', $user) : route('login') }}"
                       class="group flex items-start gap-4 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-4 transition hover:border-gray-300 dark:hover:border-gray-600 hover:shadow-md">
                        <span class="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-lg bg-gray-100 dark:bg-gray-700 text-gray-500 dark:text-gray-400 transition group-hover:bg-indigo-100 dark:group-hover:bg-indigo-900/30 group-hover:text-indigo-600 dark:group-hover:text-indigo-400">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>
                        </span>
                        <span class="min-w-0 flex-1">
                            <span class="block text-sm font-semibold text-gray-900 dark:text-gray-100">{{ __('profile.write_to', ['name' => $user->full_name]) }}</span>
                            <span class="mt-0.5 block text-xs leading-relaxed text-gray-500 dark:text-gray-400">{{ __('profile.start_conversation') }}</span>
                        </span>
                        <svg class="w-4 h-4 flex-shrink-0 self-center text-gray-400 transition group-hover:translate-x-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                    </a>
                    @endif
                </div>

                <!-- Services -->
                <section>
                    <div class="flex items-center justify-between mb-3">
                        <h2 class="text-sm font-semibold text-gray-400 dark:text-gray-500 uppercase tracking-wider">{{ __('profile.services_offered') }}</h2>
                        @if($services->isNotEmpty())
                        <span class="text-xs text-gray-400">{{ __('profile.services_count', ['count' => $services->count()]) }}</span>
                        @endif
                    </div>
                    @if($services->isEmpty())
                    <p class="text-gray-400 text-sm italic">{{ __('profile.no_services') }}</p>
                    @else
                    <div class="grid gap-3 sm:grid-cols-2">
                        @foreach($services as $service)
                        <a href="{{ route('services.show', $service) }}" class="group block bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-4 hover:shadow-md hover:border-indigo-200 dark:hover:border-indigo-700 transition">
                            <div class="flex items-start justify-between mb-2">
                                <span class="px-2.5 py-0.5 rounded-full text-xs font-medium text-white" style="background-color:{{ $service->category->color }}">
                                    {{ $service->category->displayName('transactions') }}
                                </span>
                                <span class="font-bold text-indigo-600 dark:text-indigo-400 text-sm whitespace-nowrap ml-2">{{ $service->points_cost }} pts</span>
                            </div>
                            <p class="font-medium text-gray-900 dark:text-gray-100 text-sm mb-2 group-hover:text-indigo-600 dark:group-hover:text-indigo-400 transition">{{ $service->title }}</p>
                            @if($service->skills->isNotEmpty())
                            <div class="flex flex-wrap gap-1">
                                @foreach($service->skills->take(3) as $skill)
                                <span class="px-2 py-0.5 bg-gray-100 dark:bg-gray-700 text-gray-500 dark:text-gray-400 rounded text-xs">{{ $skill->name }}</span>
                                @endforeach
                                @if($service->skills->count() > 3)
                                <span class="px-2 py-0.5 text-gray-400 text-xs">+{{ $service->skills->count() - 3 }}</span>
                                @endif
                            </div>
                            @endif
                        </a>
                        @endforeach
                    </div>
                    @endif
                </section>

                <!-- Open requests -->
                @if($openRequests->isNotEmpty())
                <section>
                    <div class="flex items-center justify-between mb-3">
                        <h2 class="text-sm font-semibold text-gray-400 dark:text-gray-500 uppercase tracking-wider">{{ __('profile.help_section') }}</h2>
                        <span class="text-xs text-gray-400">{{ __('profile.requests_count', ['count' => $openRequests->count()]) }}</span>
                    </div>
                    <div class="grid gap-3 sm:grid-cols-2">
                        @foreach($openRequests as $req)
                        <a href="{{ route('requests.show', $req) }}" class="group block bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-4 hover:shadow-md hover:border-green-200 dark:hover:border-green-700 transition">
                            <div class="flex items-start justify-between mb-2">
                                <span class="px-2.5 py-0.5 rounded-full text-xs font-medium text-white" style="background-color:{{ $req->category->color }}">
                                    {{ $req->category->displayName('transactions') }}
                                </span>
                                <span class="font-bold text-green-600 dark:text-green-400 text-sm whitespace-nowrap ml-2">
                                    {{ $req->budget_min }}{{ $req->budget_max ? '–'.$req->budget_max : '+' }} pts
                                </span>
                            </div>
                            <p class="font-medium text-gray-900 dark:text-gray-100 text-sm mb-1 group-hover:text-green-600 dark:group-hover:text-green-400 transition">{{ $req->title }}</p>
                            @if($req->deadline)
                            <p class="text-xs text-gray-400 flex items-center gap-1">⏰ {{ __('profile.before_deadline', ['date' => $req->deadline->format('d/m/Y')]) }}</p>
                            @endif
                        </a>
                        @endforeach
                    </div>
                </section>
                @endif

                <!-- Blog posts -->
                <section>
                    <div class="flex items-center justify-between mb-3">
                        <h2 class="text-sm font-semibold text-gray-400 dark:text-gray-500 uppercase tracking-wider">{{ __('profile.blog_section') }}</h2>
                        @if($blogPosts->isNotEmpty())
                        <span class="text-xs text-gray-400">{{ __('profile.blog_count', ['count' => $blogPosts->count()]) }}</span>
                        @endif
                    </div>
                    @if($blogPosts->isEmpty())
                    <p class="text-gray-400 text-sm italic">{{ __('profile.no_posts') }}</p>
                    @else
                    <div class="grid gap-3 sm:grid-cols-2">
                        @foreach($blogPosts as $post)
                        <a href="{{ route('blog.show', $post) }}" class="group block bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-4 hover:shadow-md transition">
                            <div class="flex items-center justify-between mb-2">
                                @if($post->category)
                                <span class="px-2 py-0.5 rounded-full text-xs font-medium text-white" style="background-color:{{ $post->category->color }}">
                                    {{ $post->category->displayName('blog') }}
                                </span>
                                @endif
                                <span class="text-xs text-gray-400">{{ $post->published_at?->format('d/m/Y') }}</span>
                            </div>
                            <p class="font-medium text-gray-900 dark:text-gray-100 text-sm mb-1 line-clamp-2">{{ $post->title }}</p>
                            @if($post->summary)
                            <p class="text-xs text-gray-500 dark:text-gray-400 line-clamp-2">{{ $post->summary }}</p>
                            @endif
                        </a>
                        @endforeach
                    </div>
                    @endif
                </section>

                <!-- Reviews -->
                @if($reviews->isNotEmpty())
                <section>
                    <h2 class="text-sm font-semibold text-gray-400 dark:text-gray-500 uppercase tracking-wider mb-3">
                        {{ __('profile.reviews_section') }}
                        <span class="text-gray-400 font-normal normal-case">({{ $reviews->count() }})</span>
                    </h2>
                    <div class="space-y-3">
                        @foreach($reviews as $review)
                        <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-4">
                            <div class="flex items-center justify-between mb-2">
                                <div class="flex items-center gap-2">
                                    <img src="{{ $review->reviewer->avatar_url }}" class="w-6 h-6 rounded-full" alt="">
                                    <span class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ $review->reviewer->full_name }}</span>
                                </div>
                                <div class="flex items-center gap-0.5">
                                    @for($i = 1; $i <= 5; $i++)
                                    <span class="text-sm {{ $i <= $review->rating ? 'text-yellow-400' : 'text-gray-300 dark:text-gray-600' }}">★</span>
                                    @endfor
                                    <span class="text-xs text-gray-400 ml-1.5">{{ $review->created_at->diffForHumans() }}</span>
                                </div>
                            </div>
                            @if($review->comment)
                            <p class="text-sm text-gray-600 dark:text-gray-400 italic leading-relaxed">"{{ $review->comment }}"</p>
                            @endif
                        </div>
                        @endforeach
                    </div>
                </section>
                @endif

            </div>

            <!-- Right column (desktop) -->
            <aside class="hidden md:block md:col-span-1 space-y-4">

                <!-- AI agent CTA -->
                @if($memberAiProfile && (auth()->guest() || auth()->id() !== $user->id))
                <a href="{{ $agentAiChatUrl }}"
                   class="group flex flex-col gap-3 rounded-xl border-2 border-indigo-300 dark:border-indigo-700 bg-white dark:bg-gray-800 p-5 transition hover:border-indigo-400 hover:shadow-lg">
                    <div class="flex items-center gap-3">
                        <span class="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-lg bg-indigo-600 text-white shadow-md ring-1 ring-indigo-500/30 transition group-hover:scale-110 group-hover:bg-indigo-700">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                        </span>
                        <span class="font-semibold text-gray-900 dark:text-gray-100 text-base">{{ __('profile.agent_cta_title') }}</span>
                    </div>
                    <p class="text-sm leading-relaxed text-gray-600 dark:text-gray-400">{{ __('profile.agent_cta_hint') }}</p>
                    <span class="inline-flex items-center self-start gap-1 text-xs font-medium text-indigo-600 dark:text-indigo-400 group-hover:underline">
                        {{ __('profile.start') }}
                        <svg class="w-3.5 h-3.5 transition group-hover:translate-x-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                    </span>
                </a>
                @endif

                <!-- Write to CTA -->
                @if(auth()->guest() || auth()->id() !== $user->id)
                <a href="{{ auth()->check() ? route('messages.with', $user) : route('login') }}"
                   class="group flex flex-col gap-2 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-5 transition hover:border-gray-300 dark:hover:border-gray-600 hover:shadow-md">
                    <div class="flex items-center gap-3">
                        <span class="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-lg bg-gray-100 dark:bg-gray-700 text-gray-500 dark:text-gray-400 transition group-hover:bg-indigo-100 dark:group-hover:bg-indigo-900/30 group-hover:text-indigo-600 dark:group-hover:text-indigo-400">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>
                        </span>
                        <span class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ __('profile.write_to', ['name' => $user->full_name]) }}</span>
                    </div>
                    <p class="text-xs leading-relaxed text-gray-500 dark:text-gray-400">{{ __('profile.start_conversation') }}</p>
                </a>
                @endif

                <!-- Report -->
                @auth
                @if(auth()->id() !== $user->id)
                <div x-data="{ open: false }" class="pt-2">
                    <button @click="open = !open" class="text-xs text-gray-400 hover:text-red-500 transition flex items-center gap-1">
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 21v-4m0 0V5a2 2 0 012-2h6.5l1 1H21l-3 6 3 6h-8.5l-1-1H5a2 2 0 00-2 2zm9-13.5V9"/></svg>
                        {{ __('profile.report') }}
                    </button>
                    <div x-show="open" x-cloak class="mt-2 rounded-xl border border-red-200 bg-red-50 p-4 text-left shadow-lg dark:border-red-800 dark:bg-red-900/20">
                        <form method="POST" action="{{ route('reports.user', $user) }}">
                            @csrf
                            <p class="mb-2 text-xs font-semibold text-red-700 dark:text-red-300">{{ __('profile.report_user') }}</p>
                            <select name="reason" required class="mb-2 w-full rounded-lg border border-red-200 bg-white px-3 py-2 text-sm text-gray-900 dark:border-red-700 dark:bg-gray-800 dark:text-gray-100">
                                <option value="">{{ __('profile.reason') }}...</option>
                                <option value="Comportement abusif">{{ __('profile.abuse') }}</option>
                                <option value="Arnaque ou fraude">{{ __('profile.scam') }}</option>
                                <option value="Faux profil">{{ __('profile.fake') }}</option>
                                <option value="Autre">{{ __('profile.other') }}</option>
                            </select>
                            <textarea name="details" rows="2" placeholder="{{ __('profile.details') }}..." class="mb-2 w-full resize-none rounded-lg border border-red-200 bg-white px-3 py-2 text-sm text-gray-900 dark:border-red-700 dark:bg-gray-800 dark:text-gray-100"></textarea>
                            <div class="flex gap-2">
                                <button type="submit" class="flex-1 rounded-lg bg-red-600 px-3 py-1.5 text-xs text-white hover:bg-red-700">{{ __('profile.send') }}</button>
                                <button type="button" @click="open = false" class="rounded-lg border border-red-200 px-3 py-1.5 text-xs text-red-600 hover:bg-red-50 dark:hover:bg-red-900/30">{{ __('profile.cancel') }}</button>
                            </div>
                        </form>
                    </div>
                </div>
                @endif
                @endauth

            </aside>

        </div>
    </x-page-container>
</x-app-layout>
