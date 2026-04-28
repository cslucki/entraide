<div>
    <!-- Tabs -->
    <div class="flex border-b border-gray-200 dark:border-gray-700 mb-6">
        <button wire:click="switchTab('services')"
            class="px-6 py-3 text-sm font-medium {{ $tab === 'services' ? 'border-b-2 border-indigo-600 text-indigo-600' : 'text-gray-500 hover:text-gray-700 dark:text-gray-400' }}">
            Services
        </button>
        <button wire:click="switchTab('requests')"
            class="px-6 py-3 text-sm font-medium {{ $tab === 'requests' ? 'border-b-2 border-indigo-600 text-indigo-600' : 'text-gray-500 hover:text-gray-700 dark:text-gray-400' }}">
            Demandes
        </button>
    </div>

    <!-- Filters -->
    <div class="mb-6 space-y-4">
        <!-- Search -->
        <input wire:model.live.debounce.300ms="search"
            type="text"
            placeholder="Rechercher..."
            class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-indigo-500 focus:border-transparent" />

        <!-- Category chips -->
        <div class="flex flex-wrap gap-2">
            @foreach($categories as $cat)
            <button wire:click="toggleCategory('{{ $cat->id }}')"
                class="px-3 py-1 rounded-full text-sm font-medium border transition {{ in_array($cat->id, $selectedCategories) ? 'text-white border-transparent' : 'bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 border-gray-300 dark:border-gray-600 hover:border-indigo-400' }}"
                style="{{ in_array($cat->id, $selectedCategories) ? 'background-color:'.$cat->color.';border-color:'.$cat->color : '' }}">
                {{ $cat->name }}
            </button>
            @endforeach
        </div>

        <!-- Delivery mode -->
        <div class="flex gap-3">
            <select wire:model.live="deliveryMode"
                class="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 text-sm">
                <option value="">Tous les modes</option>
                <option value="remote">À distance</option>
                <option value="onsite">Sur site</option>
            </select>

            @auth
            @if($tab === 'services')
            <a href="{{ route('services.create') }}" class="ml-auto px-4 py-2 bg-indigo-600 text-white text-sm rounded-lg hover:bg-indigo-700">
                + Publier un service
            </a>
            @else
            <a href="{{ route('requests.create') }}" class="ml-auto px-4 py-2 bg-indigo-600 text-white text-sm rounded-lg hover:bg-indigo-700">
                + Publier une demande
            </a>
            @endif
            @endauth
        </div>
    </div>

    <!-- Results -->
    <div wire:loading.class="opacity-50">
        @if($tab === 'services')
            @if($items->isEmpty())
                <p class="text-center text-gray-500 dark:text-gray-400 py-16">Aucun service trouvé.</p>
            @else
                <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-6">
                    @foreach($items as $service)
                    <a href="{{ route('services.show', $service) }}" class="block bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 hover:shadow-md transition overflow-hidden">
                        <div class="p-5">
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
                            <div class="flex items-center gap-2 text-xs text-gray-500 dark:text-gray-400">
                                <img src="{{ $service->user->avatar_url }}" class="w-5 h-5 rounded-full" alt="">
                                {{ $service->user->name }}
                                <span class="ml-auto">{{ match($service->delivery_mode) { 'remote' => '🌐 Distance', 'onsite' => '📍 Site', 'both' => '🌐📍 Les deux' } }}</span>
                            </div>
                        </div>
                    </a>
                    @endforeach
                </div>
                <div class="mt-6">{{ $items->links() }}</div>
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
                                    {{ $request->budget_min }}{{ $request->budget_max ? ' – ' . $request->budget_max : '+' }} pts
                                </span>
                            </div>
                            <h3 class="font-semibold text-gray-900 dark:text-gray-100 mb-1 line-clamp-1">{{ $request->title }}</h3>
                            <p class="text-gray-500 dark:text-gray-400 text-sm line-clamp-2 mb-3">{{ $request->description }}</p>
                            <div class="flex items-center gap-2 text-xs text-gray-500 dark:text-gray-400 mb-3">
                                <img src="{{ $request->user->avatar_url }}" class="w-5 h-5 rounded-full" alt="">
                                {{ $request->user->name }}
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
                <div class="mt-6">{{ $items->links() }}</div>
            @endif
        @endif
    </div>
</div>
