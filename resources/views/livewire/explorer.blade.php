<div>
    <style>
        .no-scrollbar::-webkit-scrollbar { display: none; }
    </style>
    <!-- Tabs + bouton publier -->
    <div class="flex items-center justify-between border-b border-gray-200 dark:border-gray-700 mb-6">
        <div class="flex">
            <button wire:click="switchTab('requests')"
                class="px-6 py-3 text-sm font-medium transition {{ $tab === 'requests' ? 'border-b-2 border-indigo-600 text-indigo-600' : 'text-gray-500 hover:text-gray-700 dark:text-gray-400' }}">
                {{ __('explorer.requests') }}
            </button>
            <button wire:click="switchTab('services')"
                class="px-6 py-3 text-sm font-medium transition {{ $tab === 'services' ? 'border-b-2 border-indigo-600 text-indigo-600' : 'text-gray-500 hover:text-gray-700 dark:text-gray-400' }}">
                {{ __('explorer.services') }}
            </button>
        </div>
        @php
            $_orgSlug = $organization?->slug;
            $_servicesCreateHref = $_orgSlug ? route('organization.services.create', ['organization' => $_orgSlug]) : route('services.create');
            $_requestsCreateHref = $_orgSlug ? route('organization.requests.create', ['organization' => $_orgSlug]) : route('requests.create');
        @endphp
        @auth
        <div class="pb-1">
            @if($tab === 'services')
            <a href="{{ $_servicesCreateHref }}" class="inline-flex items-center justify-center gap-1 sm:gap-2 sm:px-4 sm:py-2 p-2.5 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold rounded-lg transition active:scale-95">
                <svg class="w-5 h-5 sm:w-4 sm:h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                <span class="hidden sm:inline">{{ org_trans('explorer.offer_service', $organization, ['service' => __('explorer.service')]) }}</span>
            </a>
            @else
            <a href="{{ $_requestsCreateHref }}" class="inline-flex items-center justify-center gap-1 sm:gap-2 sm:px-4 sm:py-2 p-2.5 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold rounded-lg transition active:scale-95">
                <svg class="w-5 h-5 sm:w-4 sm:h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                <span class="hidden sm:inline">{{ __('explorer.create_request', ['request' => __('explorer.request')]) }}</span>
            </a>
            @endif
        </div>
        @endauth
    </div>

    <!-- Filtres -->
    <div class="mb-6 space-y-3">
        <!-- Recherche + Tri -->
        <div class="flex gap-3 overflow-x-auto md:overflow-visible pb-2 md:pb-0 -mx-2 md:mx-0 px-2 md:px-0 no-scrollbar" style="scrollbar-width: none; -ms-overflow-style: none;">
            <div class="relative shrink-0 w-48 sm:flex-1 sm:min-w-[200px]">
                <input wire:model.live.debounce.300ms="search" type="text" placeholder="{{ __('explorer.search_placeholder') }}"
                    class="w-full pl-9 pr-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-indigo-500 focus:border-transparent text-sm">
                <svg class="absolute left-3 top-2.5 w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                </svg>
            </div>
            <select wire:model.live="sortBy"
                class="shrink-0 px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 text-sm">
                <option value="latest">{{ __('explorer.sort_latest') }}</option>
                <option value="points_asc">{{ __('explorer.sort_points_asc') }}</option>
                <option value="points_desc">{{ __('explorer.sort_points_desc') }}</option>
                <option value="rating">{{ __('explorer.sort_rating') }}</option>
            </select>
            <select wire:model.live="deliveryMode"
                class="shrink-0 px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 text-sm">
                <option value="">{{ __('explorer.delivery_all') }}</option>
                <option value="remote">{{ __('explorer.delivery_remote') }}</option>
                <option value="onsite">{{ __('explorer.delivery_onsite') }}</option>
            </select>
            @if($tab === 'services')
            <select wire:model.live="minRating"
                class="shrink-0 px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 text-sm">
                <option value="0">{{ __('explorer.ratings_all') }}</option>
                <option value="1">★ ≥ 1</option>
                <option value="2">★★ ≥ 2</option>
                <option value="3">★★★ ≥ 3</option>
                <option value="4">★★★★ ≥ 4</option>
                <option value="5">★★★★★ = 5</option>
            </select>
            @endif
        </div>

        <!-- Catégories -->
        <div class="flex items-center gap-2"
            x-data="{
                canScrollLeft: false,
                canScrollRight: false,
                updateCategoryScroll() {
                    const el = this.$refs.categoryScroller;
                    this.canScrollLeft = el.scrollLeft > 1;
                    this.canScrollRight = el.scrollLeft + el.clientWidth < el.scrollWidth - 1;
                },
                scrollCategories(direction) {
                    const el = this.$refs.categoryScroller;
                    el.scrollBy({ left: direction * Math.round(el.clientWidth * 0.75), behavior: 'smooth' });
                    window.setTimeout(() => this.updateCategoryScroll(), 250);
                },
            }"
            x-init="$nextTick(() => updateCategoryScroll()); window.addEventListener('resize', () => updateCategoryScroll())"
            wire:key="category-scroller-{{ $tab }}-{{ count($selectedCategories) }}-{{ count($selectedSkills) }}-{{ $search }}-{{ $deliveryMode }}-{{ $minRating }}">
            <button type="button"
                :class="canScrollLeft ? 'hidden md:inline-flex' : 'hidden'"
                class="h-9 w-9 shrink-0 items-center justify-center rounded-full border border-gray-200 bg-white text-gray-500 shadow-sm transition hover:border-indigo-300 hover:text-indigo-600 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400 dark:hover:border-indigo-500 dark:hover:text-indigo-300"
                aria-label="{{ __('ui.scroll_left') }}"
                @click="scrollCategories(-1)">
                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
                </svg>
            </button>

            <div x-ref="categoryScroller" @scroll="updateCategoryScroll()" class="flex min-w-0 flex-1 overflow-x-auto snap-x gap-2 pb-2 -mx-2 px-2 no-scrollbar" style="scrollbar-width: none; -ms-overflow-style: none;">
                @foreach($categories as $cat)
                <button wire:click="toggleCategory('{{ $cat->id }}')"
                    class="shrink-0 px-4 py-2 h-10 rounded-full text-sm font-medium border transition whitespace-nowrap {{ in_array($cat->id, $selectedCategories) ? 'text-white border-transparent shadow-sm' : 'bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 border-gray-300 dark:border-gray-600 hover:border-indigo-400 active:scale-95' }}"
                    style="{{ in_array($cat->id, $selectedCategories) ? 'background-color:'.$cat->color.';border-color:'.$cat->color : '' }}">
                    {{ $cat->displayName('transactions') }}
                </button>
                @endforeach
                @if(!empty($selectedCategories) || $tagFilter || $deliveryMode || $search || $minRating || !empty($selectedSkills))
                <button wire:click="$set('selectedCategories', []); $set('tagFilter', ''); $set('deliveryMode', ''); $set('search', ''); $set('minRating', 0); $set('selectedSkills', [])"
                    class="shrink-0 px-4 py-2 h-10 rounded-full text-xs font-medium border border-red-300 text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20 whitespace-nowrap active:scale-95">
                    {{ __('explorer.reset_filters') }}
                </button>
                @endif
            </div>

            <button type="button"
                :class="canScrollRight ? 'hidden md:inline-flex' : 'hidden'"
                class="h-9 w-9 shrink-0 items-center justify-center rounded-full border border-gray-200 bg-white text-gray-500 shadow-sm transition hover:border-indigo-300 hover:text-indigo-600 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400 dark:hover:border-indigo-500 dark:hover:text-indigo-300"
                aria-label="{{ __('ui.scroll_right') }}"
                @click="scrollCategories(1)">
                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                </svg>
            </button>
        </div>

        <!-- Compétences (sous-filtre quand une catégorie est sélectionnée) -->
        @if(!empty($selectedCategories))
        @php $visibleSkills = $categories->whereIn('id', $selectedCategories)->flatMap(fn($c) => $c->skills); @endphp
        @if($visibleSkills->isNotEmpty())
        <div class="flex overflow-x-auto snap-x gap-2 pb-2 pl-2 ml-2 border-l-2 border-indigo-200 dark:border-indigo-700 -mx-2 px-2 no-scrollbar" style="scrollbar-width: none; -ms-overflow-style: none;">
            <span class="self-center text-xs text-gray-400 dark:text-gray-500 mr-1 shrink-0">{{ __('explorer.skills_label') }}</span>
            @foreach($visibleSkills as $skill)
            <button wire:click="toggleSkill('{{ $skill->id }}')"
                class="shrink-0 px-3 py-1.5 h-9 rounded-full text-xs font-medium border transition whitespace-nowrap {{ in_array($skill->id, $selectedSkills) ? 'bg-indigo-600 text-white border-indigo-600 shadow-sm active:scale-95' : 'bg-white dark:bg-gray-800 text-gray-600 dark:text-gray-400 border-gray-200 dark:border-gray-600 hover:border-indigo-400 active:scale-95' }}">
                {{ $skill->name }}
            </button>
            @endforeach
        </div>
        @endif
        @endif

        <!-- Tag actif -->
        @if($tagFilter)
        <div class="flex items-center gap-2">
            <span class="text-sm text-gray-500 dark:text-gray-400">{{ __('explorer.tag_label') }}</span>
            <span class="flex items-center gap-1 px-2 py-0.5 bg-indigo-100 dark:bg-indigo-900/40 text-indigo-700 dark:text-indigo-300 rounded text-sm">
                #{{ $tagFilter }}
                <button wire:click="$set('tagFilter', '')" class="ml-1 text-indigo-400 hover:text-indigo-700">×</button>
            </span>
        </div>
        @endif


    </div>

    <!-- Résultats -->
    <div wire:loading.class="opacity-50 transition-opacity">
        @if($tab === 'services')
            @if($items->isEmpty())
                <p class="text-center text-gray-500 dark:text-gray-400 py-16">{{ __('explorer.no_services') }}</p>
            @else
                <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-4 sm:gap-6">
                    @foreach($items as $service)
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 hover:shadow-md transition overflow-hidden flex flex-col active:scale-[0.98]">
                        <a href="{{ $_orgSlug ? route('organization.services.show', ['organization' => $_orgSlug, 'service' => $service]) : route('services.show', $service) }}" class="block p-4 sm:p-5 flex-1">
                            <div class="flex items-center justify-between mb-3">
                                <span class="px-2 py-0.5 rounded-full text-xs font-medium text-white" style="background-color:{{ $service->category->color }}">
                                    {{ $service->category->displayName('transactions') }}
                                </span>
                                <span class="text-indigo-600 dark:text-indigo-400 font-bold text-sm">{{ $service->points_cost }} pts</span>
                            </div>
                            <h3 class="font-semibold text-gray-900 dark:text-gray-100 mb-1 line-clamp-1">{{ $service->title }}</h3>
                            <p class="text-gray-500 dark:text-gray-400 text-sm line-clamp-2 mb-3">{{ $service->description }}</p>
                            <div class="flex flex-wrap gap-1 mb-3">
                                @foreach($service->skills->take(3) as $skill)
                                <span class="px-2 py-0.5 bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300 rounded text-xs">{{ $skill->name }}</span>
                                @endforeach
                            </div>
                            <!-- Tags cliquables -->
                            @if($service->tags->isNotEmpty())
                            <div class="flex flex-wrap gap-1 mb-3">
                                @foreach($service->tags as $tag)
                                <button wire:click.prevent="filterByTag('{{ $tag->slug }}')" type="button"
                                    class="px-2 py-0.5 rounded text-xs transition {{ $tagFilter === $tag->slug ? 'bg-indigo-600 text-white' : 'bg-indigo-50 dark:bg-indigo-900/30 text-indigo-600 dark:text-indigo-300 hover:bg-indigo-100' }}">
                                    #{{ $tag->name }}
                                </button>
                                @endforeach
                            </div>
                            @endif
                        </a>
                        <div class="px-4 sm:px-5 pb-4 flex items-center justify-between">
                            <a href="{{ $_orgSlug ? route('organization.profile.show', ['organization' => $_orgSlug, 'user' => $service->user]) : route('profile.show', $service->user) }}"
                               class="flex items-center gap-2 text-xs text-gray-500 dark:text-gray-400 hover:text-indigo-600 dark:hover:text-indigo-400 transition">
                                <img src="{{ $service->user->avatar_url }}" class="w-5 h-5 rounded-full" alt="">
                                {{ $service->user->name }}
                                @if($service->user->rating)
                                <span class="text-yellow-500">★ {{ number_format($service->user->rating, 1) }}</span>
                                @endif
                            </a>
                            @auth
                            @if(auth()->id() !== $service->user_id)
                            <form method="POST" action="{{ route('favorites.toggle', $service) }}">
                                @csrf
                                @php $faved = isset($favoritedIds[$service->id]); @endphp
                                <button type="submit" title="{{ $faved ? __('explorer.remove_favorite') : __('explorer.add_favorite') }}"
                                    class="{{ $faved ? 'text-red-500' : 'text-gray-300 hover:text-red-400' }} transition">
                                    <svg class="w-5 h-5 fill-current" viewBox="0 0 24 24"><path d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"/></svg>
                                </button>
                            </form>
                            @endif
                            @endauth
                        </div>
                    </div>
                    @endforeach
                </div>

                <!-- Scroll infini -->
                @if($hasMore)
                <div class="mt-6 sm:mt-8 text-center">
                    <button wire:click="loadMore" class="w-full sm:w-auto px-6 py-3 sm:py-2 border border-gray-300 dark:border-gray-600 rounded-lg text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700 transition active:scale-[0.98]">
                        <span wire:loading.remove wire:target="loadMore">{{ __('explorer.load_more') }}</span>
                        <span wire:loading wire:target="loadMore">{{ __('ui.loading') }}</span>
                    </button>
                </div>
                @endif
            @endif
        @else
            @if($items->isEmpty())
                <p class="text-center text-gray-500 dark:text-gray-400 py-16">{{ __('explorer.no_requests') }}</p>
            @else
                <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-4 sm:gap-6">
                    @foreach($items as $request)
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 overflow-hidden active:scale-[0.98]">
                        <div class="p-4 sm:p-5">
                            <div class="flex items-center justify-between mb-3">
                                <span class="px-2 py-0.5 rounded-full text-xs font-medium text-white" style="background-color:{{ $request->category->color }}">
                                    {{ $request->category->displayName('transactions') }}
                                </span>
                                <span class="text-green-600 dark:text-green-400 font-bold text-sm">
                                    {{ $request->budget_min }}{{ $request->budget_max ? '–'.$request->budget_max : '+' }} pts
                                </span>
                            </div>
                            <a href="{{ $_orgSlug ? route('organization.requests.show', ['organization' => $_orgSlug, 'request' => $request]) : route('requests.show', $request) }}">
                                <h3 class="font-semibold text-gray-900 dark:text-gray-100 mb-1 line-clamp-1 hover:text-indigo-600">{{ $request->title }}</h3>
                            </a>
                            <p class="text-gray-500 dark:text-gray-400 text-sm line-clamp-2 mb-3">{{ $request->description }}</p>
                            <div class="flex items-center gap-2 text-xs text-gray-500 dark:text-gray-400 mb-3">
                                <a href="{{ $_orgSlug ? route('organization.profile.show', ['organization' => $_orgSlug, 'user' => $request->user]) : route('profile.show', $request->user) }}"
                                   class="flex items-center gap-2 hover:text-indigo-600 dark:hover:text-indigo-400 transition">
                                    <img src="{{ $request->user->avatar_url }}" class="w-5 h-5 rounded-full" alt="">
                                    {{ $request->user->name }}
                                </a>
                                @if($request->deadline)
                                <span class="ml-auto">⏰ {{ $request->deadline->format('d/m/Y') }}</span>
                                @endif
                            </div>
                            @auth
                            @if(auth()->id() !== $request->user_id)
                            <form method="POST" action="{{ $_orgSlug ? route('organization.transactions.store', ['organization' => $_orgSlug]) : route('transactions.store') }}">
                                @csrf
                                <input type="hidden" name="request_id" value="{{ $request->id }}">
                                <input type="hidden" name="points_proposed" value="{{ $request->budget_min }}">
                                <button type="submit" class="w-full py-2.5 sm:py-2 bg-indigo-600 text-white text-sm rounded-lg hover:bg-indigo-700 active:scale-[0.98]">
                                    {{ __('explorer.offer_help') }}
                                </button>
                            </form>
                            @endif
                            @endauth
                        </div>
                    </div>
                    @endforeach
                </div>

                @if($hasMore)
                <div class="mt-6 sm:mt-8 text-center">
                    <button wire:click="loadMore" class="w-full sm:w-auto px-6 py-3 sm:py-2 border border-gray-300 dark:border-gray-600 rounded-lg text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700 transition active:scale-[0.98]">
                        <span wire:loading.remove wire:target="loadMore">{{ __('explorer.load_more') }}</span>
                        <span wire:loading wire:target="loadMore">{{ __('ui.loading') }}</span>
                    </button>
                </div>
                @endif
            @endif
        @endif
    </div>

    <!-- Skeleton loader pendant les changements de filtre -->
    <div wire:loading.flex wire:target="updatedSearch,toggleCategory,updatedDeliveryMode,updatedSortBy,switchTab"
        class="grid sm:grid-cols-2 lg:grid-cols-3 gap-4 sm:gap-6 mt-0">
        @for($i = 0; $i < 6; $i++)
        <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-5 animate-pulse {{ $i >= 3 ? 'hidden sm:block' : '' }}">
            <div class="flex justify-between mb-3">
                <div class="h-5 bg-gray-200 dark:bg-gray-700 rounded-full w-24"></div>
                <div class="h-5 bg-gray-200 dark:bg-gray-700 rounded w-12"></div>
            </div>
            <div class="h-4 bg-gray-200 dark:bg-gray-700 rounded w-3/4 mb-2"></div>
            <div class="h-3 bg-gray-200 dark:bg-gray-700 rounded w-full mb-1"></div>
            <div class="h-3 bg-gray-200 dark:bg-gray-700 rounded w-5/6 mb-4"></div>
            <div class="flex gap-1">
                <div class="h-5 bg-gray-200 dark:bg-gray-700 rounded w-16"></div>
                <div class="h-5 bg-gray-200 dark:bg-gray-700 rounded w-20"></div>
            </div>
        </div>
        @endfor
    </div>
</div>
