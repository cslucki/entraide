<x-app-layout>
    <!-- Hero with Search -->
    <div class="bg-indigo-700 text-white py-24 relative overflow-hidden">
        <div class="max-w-5xl mx-auto px-4 text-center relative z-10">
            <h1 class="text-5xl font-extrabold mb-6">Trouvez l'aide dont vous avez besoin</h1>
            <p class="text-xl text-indigo-100 mb-10 block">Échangez vos compétences sans sortir votre carte bleue. Rejoignez la plus grande communauté de troc de services.</p>

            <div class="max-w-2xl mx-auto relative group">
                <form action="{{ route('search') }}" method="GET">
                    <div class="relative">
                        <input type="text" name="q" placeholder="Que recherchez-vous ? (ex: logo, jardinage, cours d'anglais...)"
                               class="w-full pl-14 pr-4 py-5 rounded-2xl text-gray-900 text-lg shadow-2xl border-none focus:ring-4 focus:ring-indigo-400 transition-all">
                        <div class="absolute left-5 top-1/2 -translate-y-1/2">
                            <svg class="w-6 h-6 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                            </svg>
                        </div>
                    </div>
                </form>
            </div>

            <div class="mt-8 flex flex-wrap justify-center gap-3">
                <span class="text-indigo-200 text-sm">Populaire :</span>
                @foreach($categories->take(4) as $cat)
                <a href="{{ route('explorer', ['category' => $cat->id]) }}" class="text-sm bg-white/10 hover:bg-white/20 px-3 py-1 rounded-full transition border border-white/20">{{ $cat->name }}</a>
                @endforeach
            </div>
        </div>
    </div>

    <!-- Category Grid -->
    <div class="py-20 bg-white dark:bg-gray-900">
        <div class="max-w-6xl mx-auto px-4">
            <h2 class="text-3xl font-bold text-center text-gray-900 dark:text-gray-100 mb-12">Parcourir par catégorie</h2>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-6">
                @foreach($categories as $category)
                <a href="{{ route('explorer', ['category' => $category->id]) }}" class="group p-8 bg-gray-50 dark:bg-gray-800 rounded-3xl text-center hover:bg-indigo-50 dark:hover:bg-indigo-900/30 border border-transparent hover:border-indigo-200 transition-all duration-300">
                    <div class="w-16 h-16 mx-auto mb-4 rounded-2xl flex items-center justify-center transition-transform group-hover:scale-110" style="background-color: {{ $category->color }}20">
                         <svg class="w-8 h-8" fill="none" stroke="{{ $category->color }}" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path>
                         </svg>
                    </div>
                    <span class="font-bold text-gray-900 dark:text-gray-100 block">{{ $category->name }}</span>
                    <span class="text-xs text-gray-500">{{ $category->services_count ?? 0 }} services</span>
                </a>
                @endforeach
            </div>
        </div>
    </div>

    <!-- Featured services -->
    @if($featuredServices->isNotEmpty())
    <div class="py-16 bg-gray-50 dark:bg-gray-800">
        <div class="max-w-6xl mx-auto px-4">
            <div class="flex items-center justify-between mb-8">
                <h2 class="text-2xl font-bold text-gray-900 dark:text-gray-100">Derniers services ajoutés</h2>
                <a href="{{ route('explorer') }}" class="text-sm font-semibold text-indigo-600 hover:text-indigo-500">Voir tout →</a>
            </div>
            <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-6">
                @foreach($featuredServices as $service)
                <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-sm border border-gray-100 dark:border-gray-700 overflow-hidden hover:shadow-md transition">
                    <div class="p-5">
                        <div class="flex items-center justify-between mb-4">
                             <span class="px-2 py-1 rounded text-xs font-bold uppercase tracking-wider text-white" style="background-color:{{ $service->category->color }}">
                                {{ $service->category->name }}
                            </span>
                            <span class="font-bold text-indigo-600 dark:text-indigo-400">{{ $service->points_cost }} pts</span>
                        </div>
                        <h3 class="font-bold text-lg mb-2 text-gray-900 dark:text-gray-100"><a href="{{ route('services.show', $service) }}" class="hover:text-indigo-600">{{ $service->title }}</a></h3>
                        <p class="text-sm text-gray-500 line-clamp-2 mb-4">{{ $service->description }}</p>
                        <div class="flex items-center gap-2 pt-4 border-t border-gray-50 dark:border-gray-800">
                            <img src="{{ $service->user->avatar_url }}" class="w-8 h-8 rounded-full border-2 border-white shadow-sm" alt="">
                            <span class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ $service->user->name }}</span>
                        </div>
                    </div>
                </div>
                @endforeach
            </div>
        </div>
    </div>
    @endif
</x-app-layout>
