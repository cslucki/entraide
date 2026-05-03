<x-app-layout>
    <!-- Hero -->
    <div class="bg-gradient-to-br from-indigo-600 to-purple-700 text-white py-20">
        <div class="max-w-4xl mx-auto px-4 text-center">
            <h1 class="text-4xl md:text-5xl font-bold mb-4">Plateforme d'entraide</h1>
            <p class="text-xl text-indigo-100 mb-10">Proposez vos services, trouvez ce dont vous avez besoin, et échangez avec des points.</p>
            <div class="flex flex-col sm:flex-row gap-4 justify-center mb-12">
                <a href="{{ route('explorer') }}" class="px-8 py-3 bg-white text-indigo-700 font-semibold rounded-lg hover:bg-indigo-50 transition">Explorer les services</a>
                @guest
                <a href="{{ route('register') }}" class="px-8 py-3 bg-indigo-500 text-white font-semibold rounded-lg hover:bg-indigo-400 border border-indigo-400 transition">Rejoindre gratuitement</a>
                @endguest
            </div>

            <!-- Stats live -->
            <div class="inline-flex items-center gap-8 bg-white/10 backdrop-blur-sm rounded-2xl px-8 py-4">
                <div class="text-center">
                    <p class="text-3xl font-bold">{{ $stats['users'] }}</p>
                    <p class="text-indigo-200 text-sm">Membres</p>
                </div>
                <div class="w-px h-10 bg-white/30"></div>
                <div class="text-center">
                    <p class="text-3xl font-bold">{{ $stats['services'] }}</p>
                    <p class="text-indigo-200 text-sm">Services actifs</p>
                </div>
                <div class="w-px h-10 bg-white/30"></div>
                <div class="text-center">
                    <p class="text-3xl font-bold">{{ $stats['exchanges'] }}</p>
                    <p class="text-indigo-200 text-sm">Échanges réalisés</p>
                </div>
            </div>
        </div>
    </div>

    <!-- How it works -->
    <div class="py-16 bg-white dark:bg-gray-900">
        <div class="max-w-5xl mx-auto px-4">
            <h2 class="text-2xl font-bold text-center text-gray-900 dark:text-gray-100 mb-10">Comment ça marche ?</h2>
            <div class="grid md:grid-cols-3 gap-8">
                <div class="text-center">
                    <div class="w-14 h-14 bg-indigo-100 dark:bg-indigo-900 rounded-full flex items-center justify-center mx-auto mb-4">
                        <span class="text-2xl font-bold text-indigo-600">1</span>
                    </div>
                    <h3 class="font-semibold text-lg mb-2 dark:text-gray-100">Publiez vos services</h3>
                    <p class="text-gray-500 dark:text-gray-400">Décrivez ce que vous savez faire et fixez un prix en points.</p>
                </div>
                <div class="text-center">
                    <div class="w-14 h-14 bg-indigo-100 dark:bg-indigo-900 rounded-full flex items-center justify-center mx-auto mb-4">
                        <span class="text-2xl font-bold text-indigo-600">2</span>
                    </div>
                    <h3 class="font-semibold text-lg mb-2 dark:text-gray-100">Négociez & échangez</h3>
                    <p class="text-gray-500 dark:text-gray-400">Proposez, discutez, et finalisez l'échange en messagerie.</p>
                </div>
                <div class="text-center">
                    <div class="w-14 h-14 bg-indigo-100 dark:bg-indigo-900 rounded-full flex items-center justify-center mx-auto mb-4">
                        <span class="text-2xl font-bold text-indigo-600">3</span>
                    </div>
                    <h3 class="font-semibold text-lg mb-2 dark:text-gray-100">Accumulez des points</h3>
                    <p class="text-gray-500 dark:text-gray-400">Les points se transfèrent automatiquement à la validation mutuelle.</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Featured services -->
    @if($featuredServices->isNotEmpty())
    <div class="py-16 bg-gray-50 dark:bg-gray-800">
        <div class="max-w-6xl mx-auto px-4">
            <div class="flex items-center justify-between mb-8">
                <h2 class="text-2xl font-bold text-gray-900 dark:text-gray-100">Services à la une</h2>
                <a href="{{ route('explorer') }}" class="text-sm text-indigo-600 hover:underline">Voir tout →</a>
            </div>
            <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-5">
                @foreach($featuredServices as $service)
                <a href="{{ route('services.show', $service) }}"
                   class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 p-5 hover:shadow-md hover:border-indigo-300 dark:hover:border-indigo-600 transition group">
                    <div class="flex items-center justify-between mb-3">
                        <span class="px-2 py-0.5 rounded-full text-xs font-medium text-white" style="background-color:{{ $service->category->color }}">
                            {{ $service->category->name }}
                        </span>
                        <span class="font-bold text-indigo-600 dark:text-indigo-400 text-sm">{{ $service->points_cost }} pts</span>
                    </div>
                    <h3 class="font-semibold text-gray-900 dark:text-gray-100 group-hover:text-indigo-600 dark:group-hover:text-indigo-400 transition mb-2 line-clamp-2">{{ $service->title }}</h3>
                    <div class="flex items-center gap-2 mt-3">
                        <img src="{{ $service->user->avatar_url }}" class="w-6 h-6 rounded-full" alt="">
                        <span class="text-xs text-gray-500 dark:text-gray-400">{{ $service->user->name }}</span>
                        @if($service->user->rating)
                        <span class="ml-auto text-xs text-yellow-500">★ {{ number_format($service->user->rating, 1) }}</span>
                        @endif
                    </div>
                </a>
                @endforeach
            </div>
        </div>
    </div>
    @endif

    <!-- CTA bottom -->
    @guest
    <div class="py-16 bg-indigo-600 text-white text-center">
        <div class="max-w-xl mx-auto px-4">
            <h2 class="text-2xl font-bold mb-3">Prêt à rejoindre la communauté ?</h2>
            <p class="text-indigo-100 mb-6">Créez votre compte gratuitement et recevez 100 points de bienvenue.</p>
            <a href="{{ route('register') }}" class="px-8 py-3 bg-white text-indigo-700 font-semibold rounded-lg hover:bg-indigo-50 transition">Rejoindre Entraide</a>
        </div>
    </div>
    @endguest
</x-app-layout>
