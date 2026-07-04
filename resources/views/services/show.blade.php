<x-app-layout :title="$service->title">
    @php
        $_serviceOrgSlug = request()->route('organization');
        $_serviceExplorerHref = $_serviceOrgSlug && Route::has('organization.explorer') ? route('organization.explorer', ['organization' => $_serviceOrgSlug]) : route('explorer');
        $_serviceProfileHref = $_serviceOrgSlug && Route::has('organization.profile.show') ? route('organization.profile.show', ['organization' => $_serviceOrgSlug, 'user' => $service->user]) : route('profile.show', $service->user);
        $_serviceReportAction = $_serviceOrgSlug && Route::has('organization.reports.service') ? route('organization.reports.service', ['organization' => $_serviceOrgSlug, 'service' => $service]) : route('reports.service', $service);
        $_serviceTxStoreAction = $_serviceOrgSlug && Route::has('organization.transactions.store') ? route('organization.transactions.store', ['organization' => $_serviceOrgSlug]) : route('transactions.store');
        $_serviceEditHref = $_serviceOrgSlug && Route::has('organization.services.edit') ? route('organization.services.edit', ['organization' => $_serviceOrgSlug, 'service' => $service]) : route('services.edit', $service);
    @endphp
    {{-- Desktop topbar --}}
    <div class="hidden md:flex items-center gap-3 px-4 sm:px-6 lg:px-8 py-3 border-b border-gray-200 dark:border-gray-700 bg-[var(--bp-surface)] sticky top-0 z-30">
        <a href="{{ $_serviceExplorerHref }}" class="inline-flex h-8 w-8 items-center justify-center rounded-full bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-600 flex-shrink-0" aria-label="{{ __('services.show.back') }}">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
        </a>
        <span class="truncate text-sm font-medium text-gray-900 dark:text-gray-100">{{ $service->title }}</span>
        <span class="ml-auto px-2 py-0.5 rounded-full text-xs font-medium text-white shrink-0" style="background-color:{{ $service->category->color }}">{{ $service->category->displayName('transactions') }}</span>
    </div>

    <x-page-container width="7xl">
        @if($isPaused)
        <div class="mb-4 bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-200 dark:border-yellow-800 text-yellow-800 dark:text-yellow-300 px-4 py-3 rounded-lg text-sm flex items-center gap-2">
            <svg class="w-4 h-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            {{ __('services.show.paused_banner') }}
            <a href="{{ $_serviceEditHref }}" class="ml-auto font-medium underline">{{ __('services.show.edit') }}</a>
        </div>
        @endif

        <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
            @if($service->images->isNotEmpty())
            <div class="border-b border-gray-100 dark:border-gray-700 bg-gray-50 dark:bg-gray-900/50" x-data="{ active: 0 }">
                <div class="relative aspect-video">
                    @foreach($service->images as $index => $img)
                    <img x-show="active === {{ $index }}" src="{{ $img->url }}" class="w-full h-full object-cover">
                    @endforeach

                    @if($service->images->count() > 1)
                    <button @click="active = (active > 0) ? active - 1 : {{ $service->images->count() - 1 }}" class="absolute left-4 top-1/2 -translate-y-1/2 bg-black/30 hover:bg-black/50 text-white p-2 rounded-full backdrop-blur-sm transition">
                        <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                    </button>
                    <button @click="active = (active < {{ $service->images->count() - 1 }}) ? active + 1 : 0" class="absolute right-4 top-1/2 -translate-y-1/2 bg-black/30 hover:bg-black/50 text-white p-2 rounded-full backdrop-blur-sm transition">
                        <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                    </button>
                    @endif
                </div>
                @if($service->images->count() > 1)
                <div class="flex gap-2 p-4 overflow-x-auto">
                    @foreach($service->images as $index => $img)
                    <button @click="active = {{ $index }}" class="flex-shrink-0 w-20 aspect-video rounded-lg border-2 transition overflow-hidden" :class="active === {{ $index }} ? 'border-indigo-500' : 'border-transparent'">
                        <img src="{{ $img->url }}" class="w-full h-full object-cover">
                    </button>
                    @endforeach
                </div>
                @endif
            </div>
            @endif

            <div class="p-6">
                <!-- Header -->
                <div class="flex items-start justify-between mb-4">
                    <div class="flex-1">
                        <span class="px-2 py-0.5 rounded-full text-xs font-medium text-white" style="background-color:{{ $service->category->color }}">
                            {{ $service->category->displayName('transactions') }}
                        </span>
                        <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100 mt-2">{{ $service->title }}</h1>
                    </div>
                    <div class="flex items-start gap-3 ml-4">
                        @auth
                        @if(auth()->id() !== $service->user_id)
                        <form method="POST" action="{{ $_serviceOrgSlug ? route('organization.favorites.toggle', ['organization' => $_serviceOrgSlug, 'service' => $service]) : route('favorites.toggle', $service) }}" class="flex-shrink-0 mt-1">
                            @csrf
                            <button type="submit" title="{{ $isFavorited ? __('explorer.remove_favorite') : __('explorer.add_favorite') }}"
                                class="p-2 rounded-lg border {{ $isFavorited ? 'border-red-300 bg-red-50 dark:bg-red-900/20 text-red-500' : 'border-gray-200 dark:border-gray-600 text-gray-400 hover:text-red-400 hover:border-red-300' }} transition">
                                <svg class="w-5 h-5" fill="{{ $isFavorited ? 'currentColor' : 'none' }}" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z" />
                                </svg>
                            </button>
                        </form>
                        @endif
                        @endauth
                        <div class="text-right">
                            <p class="text-3xl font-bold text-indigo-600 dark:text-indigo-400">{{ $service->points_cost }}</p>
                            <p class="text-xs text-gray-500">{{ __('services.show.points') }}</p>
                        </div>
                    </div>
                </div>

                <!-- Author -->
                <div class="flex items-center gap-3 mb-6 pb-6 border-b border-gray-100 dark:border-gray-700">
                    <img src="{{ $service->user->avatar_url }}" class="w-10 h-10 rounded-full" alt="">
                    <div>
                        <a href="{{ $_serviceProfileHref }}" class="font-medium text-gray-900 dark:text-gray-100 hover:text-indigo-600">{{ $service->user->name }}</a>
                        <div class="flex items-center gap-2 text-xs text-gray-500">
                            <span>{{ __('marketplace.delivery.' . $service->delivery_mode) }}</span>
                            @if($service->user->is_available)
                            <span class="flex items-center gap-1"><span class="w-1.5 h-1.5 bg-green-500 rounded-full"></span>{{ __('profile.available') }}</span>
                            @endif
                        </div>
                    </div>
                </div>

                <!-- Description -->
                <div class="prose dark:prose-invert max-w-none mb-6">
                    <p class="text-gray-700 dark:text-gray-300 whitespace-pre-wrap">{{ $service->description }}</p>
                </div>

                <!-- Skills & Tags -->
                @if($service->skills->isNotEmpty())
                <div class="mb-4">
                    <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2">{{ __('services.show.skills') }}</p>
                    <div class="flex flex-wrap gap-2">
                        @foreach($service->skills as $skill)
                        <span class="px-3 py-1 bg-indigo-50 dark:bg-indigo-900/30 text-indigo-700 dark:text-indigo-300 rounded-full text-sm">{{ $skill->name }}</span>
                        @endforeach
                    </div>
                </div>
                @endif

                @if($service->tags->isNotEmpty())
                <div class="mb-6">
                    <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2">{{ __('services.show.tags') }}</p>
                    <div class="flex flex-wrap gap-2">
                        @foreach($service->tags as $tag)
                        <span class="px-2 py-0.5 bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300 rounded text-xs">#{{ $tag->name }}</span>
                        @endforeach
                    </div>
                </div>
                @endif

                <!-- CTA -->
                @auth
                @if(auth()->id() !== $service->user_id)
                <!-- Signalement -->
                <div class="mb-4" x-data="{ open: false }">
                    <button @click="open = !open" class="text-xs text-gray-400 hover:text-red-500 transition">{{ __('services.show.report_button') }}</button>
                    <div x-show="open" x-cloak class="mt-2 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg p-4">
                        <form method="POST" action="{{ $_serviceReportAction }}">
                            @csrf
                            <select name="reason" required class="w-full mb-2 px-3 py-2 border border-red-200 dark:border-red-700 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm">
                                <option value="">{{ __('services.show.report_placeholder') }}</option>
                                <option value="Contenu inapproprié">{{ __('services.show.report_inappropriate') }}</option>
                                <option value="Arnaque ou fraude">{{ __('services.show.report_scam') }}</option>
                                <option value="Spam">{{ __('services.show.report_spam') }}</option>
                                <option value="Autre">{{ __('services.show.report_other') }}</option>
                            </select>
                            <textarea name="details" rows="2" placeholder="{{ __('services.show.report_details') }}"
                                class="w-full px-3 py-2 border border-red-200 dark:border-red-700 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm mb-2 resize-none"></textarea>
                            <button type="submit" class="px-3 py-1.5 bg-red-600 text-white text-xs rounded-lg hover:bg-red-700">{{ __('services.show.report_submit') }}</button>
                        </form>
                    </div>
                </div>

                <div class="border-t border-gray-100 dark:border-gray-700 pt-6">
                    <form method="POST" action="{{ $_serviceTxStoreAction }}" class="flex items-center gap-4">
                        @csrf
                        <input type="hidden" name="service_id" value="{{ $service->id }}">
                        <input type="number" name="points_proposed" value="{{ $service->points_cost }}" min="1"
                            class="w-32 px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-indigo-500">
                        <button type="submit" class="px-6 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 font-medium">
                            {{ __('services.show.propose_exchange') }}
                        </button>
                        <span class="text-xs text-gray-500">{{ __('services.show.your_balance', ['points' => auth()->user()->points_balance]) }}</span>
                    </form>
                    @if($errors->any())
                    <p class="text-red-500 text-sm mt-2">{{ $errors->first() }}</p>
                    @endif
                </div>
                @else
                <div class="border-t border-gray-100 dark:border-gray-700 pt-6 flex gap-3">
                    <a href="{{ $_serviceEditHref }}" class="px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700">{{ __('services.show.edit') }}</a>
                </div>
                @endif
                @endauth
            </div>
        </div>
    </x-page-container>
</x-app-layout>
