<div>
    <!-- Tabs + bouton publier -->
    <div class="flex items-center justify-between border-b border-gray-200 dark:border-gray-700 mb-6">
        <div class="flex">
            <button wire:click="switchTab('requests')"
                class="px-6 py-3 text-sm font-medium transition {{ $tab === 'requests' ? 'border-b-2 border-indigo-600 text-indigo-600' : 'text-gray-500 hover:text-gray-700 dark:text-gray-400' }}">
                Demandes
            </button>
            <button wire:click="switchTab('services')"
                class="px-6 py-3 text-sm font-medium transition {{ $tab === 'services' ? 'border-b-2 border-indigo-600 text-indigo-600' : 'text-gray-500 hover:text-gray-700 dark:text-gray-400' }}">
                {{ $T['Services'] }}
            </button>
        </div>
        @auth
        <div class="pb-1">
            @if($tab === 'services')
            <a href="{{ route('services.create') }}" class="inline-flex items-center gap-2 px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold rounded-lg transition">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                Proposer un {{ $T['service'] }}
            </a>
            @else
            <a href="{{ route('requests.create') }}" class="inline-flex items-center gap-2 px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold rounded-lg transition">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                Faire une demande
            </a>
            @endif
        </div>
        @endauth
    </div>

    <!-- Filtres -->
    <div class="mb-6 space-y-3">
        <!-- Recherche + Tri -->
        <div class="flex gap-3">
            <div class="relative flex-1">
                <input wire:model.live.debounce.300ms="search" type="text" placeholder="Rechercher..."
                    class="w-full pl-9 pr-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-indigo-500 focus:border-transparent text-sm">
                <svg class="absolute left-3 top-2.5 w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                </svg>
            </div>
            <select wire:model.live="sortBy"
                class="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 text-sm">
                <option value="latest">Plus récents</option>
                <option value="points_asc">Points ↑</option>
                <option value="points_desc">Points ↓</option>
                <option value="rating">Meilleure note</option>
            </select>
            <select wire:model.live="deliveryMode"
                class="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 text-sm">
                <option value="">Tous modes</option>
                <option value="remote">À distance</option>
                <option value="onsite">Sur site</option>
            </select>
            @if($tab === 'services')
            <select wire:model.live="minRating"
                class="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 text-sm">
                <option value="0">Toutes notes</option>
                <option value="1">★ ≥ 1</option>
                <option value="2">★★ ≥ 2</option>
                <option value="3">★★★ ≥ 3</option>
                <option value="4">★★★★ ≥ 4</option>
                <option value="5">★★★★★ = 5</option>
            </select>
            @endif
        </div>

        <!-- Catégories -->
        <div class="flex flex-wrap gap-2">
            @foreach($categories as $cat)
            <button wire:click="toggleCategory('{{ $cat->id }}')"
                class="px-3 py-1 rounded-full text-sm font-medium border transition {{ in_array($cat->id, $selectedCategories) ? 'text-white border-transparent' : 'bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 border-gray-300 dark:border-gray-600 hover:border-indigo-400' }}"
                style="{{ in_array($cat->id, $selectedCategories) ? 'background-color:'.$cat->color.';border-color:'.$cat->color : '' }}">
                {{ $cat->name }}
            </button>
            @endforeach
            @if(!empty($selectedCategories) || $tagFilter || $deliveryMode || $search || $minRating)
            <button wire:click="$set('selectedCategories', []); $set('tagFilter', ''); $set('deliveryMode', ''); $set('search', ''); $set('minRating', 0)"
                class="px-3 py-1 rounded-full text-xs font-medium border border-red-300 text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20">
                Réinitialiser
            </button>
            @endif
        </div>

        <!-- Tag actif -->
        @if($tagFilter)
        <div class="flex items-center gap-2">
            <span class="text-sm text-gray-500 dark:text-gray-400">Tag :</span>
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
                <p class="text-center text-gray-500 dark:text-gray-400 py-16">Aucun service trouvé.</p>
            @else
                <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-6">
                    @foreach($items as $service)
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 hover:shadow-md transition overflow-hidden flex flex-col">
                        <a href="{{ route('services.show', $service) }}" class="block p-5 flex-1">
                            <div class="flex items-center justify-between mb-3">
                                <span class="px-2 py-0.5 rounded-full text-xs font-medium text-white" style="background-color:{{ $service->category->color }}">
                                    {{ $service->category->name }}
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
                        <div class="px-5 pb-4 flex items-center justify-between">
                            <a href="{{ route('profile.show', $service->user) }}"
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
                                <button type="submit" title="{{ $faved ? 'Retirer des favoris' : 'Ajouter aux favoris' }}"
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
                <div class="mt-8 text-center">
                    <button wire:click="loadMore" class="px-6 py-2 border border-gray-300 dark:border-gray-600 rounded-lg text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700 transition">
                        <span wire:loading.remove wire:target="loadMore">Charger plus</span>
                        <span wire:loading wire:target="loadMore">Chargement...</span>
                    </button>
                </div>
                @endif
            @endif
        @else
            @if($items->isEmpty())
                <p class="text-center text-gray-500 dark:text-gray-400 py-16">Aucune demande trouvée.</p>
            @else
                <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-6">
                    @foreach($items as $request)
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 overflow-hidden">
                        <div class="p-5">
                            <div class="flex items-center justify-between mb-3">
                                <span class="px-2 py-0.5 rounded-full text-xs font-medium text-white" style="background-color:{{ $request->category->color }}">
                                    {{ $request->category->name }}
                                </span>
                                <span class="text-green-600 dark:text-green-400 font-bold text-sm">
                                    {{ $request->budget_min }}{{ $request->budget_max ? '–'.$request->budget_max : '+' }} pts
                                </span>
                            </div>
                            <a href="{{ route('requests.show', $request) }}">
                                <h3 class="font-semibold text-gray-900 dark:text-gray-100 mb-1 line-clamp-1 hover:text-indigo-600">{{ $request->title }}</h3>
                            </a>
                            <p class="text-gray-500 dark:text-gray-400 text-sm line-clamp-2 mb-3">{{ $request->description }}</p>
                            <div class="flex items-center gap-2 text-xs text-gray-500 dark:text-gray-400 mb-3">
                                <a href="{{ route('profile.show', $request->user) }}"
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
                            <form method="POST" action="{{ route('transactions.store') }}">
                                @csrf
                                <input type="hidden" name="request_id" value="{{ $request->id }}">
                                <input type="hidden" name="points_proposed" value="{{ $request->budget_min }}">
                                <button type="submit" class="w-full py-2 bg-indigo-600 text-white text-sm rounded-lg hover:bg-indigo-700">
                                    Proposer mon aide
                                </button>
                            </form>
                            @endif
                            @endauth
                        </div>
                    </div>
                    @endforeach
                </div>

                @if($hasMore)
                <div class="mt-8 text-center">
                    <button wire:click="loadMore" class="px-6 py-2 border border-gray-300 dark:border-gray-600 rounded-lg text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700 transition">
                        <span wire:loading.remove wire:target="loadMore">Charger plus</span>
                        <span wire:loading wire:target="loadMore">Chargement...</span>
                    </button>
                </div>
                @endif
            @endif
        @endif
    </div>

    <!-- Skeleton loader pendant les changements de filtre -->
    <div wire:loading.flex wire:target="updatedSearch,toggleCategory,updatedDeliveryMode,updatedSortBy,switchTab"
        class="grid sm:grid-cols-2 lg:grid-cols-3 gap-6 mt-0">
        @for($i = 0; $i < 6; $i++)
        <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-5 animate-pulse">
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
