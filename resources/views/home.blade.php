<x-app-layout>
    <!-- Hero -->
    <div class="bg-gradient-to-br from-indigo-600 to-purple-700 text-white py-20">
        <div class="max-w-4xl mx-auto px-4 text-center">
            <h1 class="text-4xl md:text-5xl font-bold mb-4">BouclePro</h1>
            <p class="text-xl text-indigo-100 mb-3">Proposez vos {{ $T['services'] }}, trouvez ce dont vous avez besoin, et échangez avec des points.</p>
            <p class="text-indigo-200 mb-10 text-base">Au sein de votre réseau professionnel — votre <span class="font-semibold text-white">boucle</span>.</p>
            <div class="flex flex-col sm:flex-row gap-4 justify-center mb-12">
                <a href="{{ route('explorer') }}" class="px-8 py-3 bg-white text-indigo-700 font-semibold rounded-lg hover:bg-indigo-50 transition">Voir les {{ $T['services'] }}</a>
                @auth
                <a href="{{ route('explorer') }}#demandes" class="px-8 py-3 bg-indigo-500 text-white font-semibold rounded-lg hover:bg-indigo-400 border border-indigo-400 transition">Voir les {{ $T['requests'] }}</a>
                @else
                <a href="{{ route('register') }}" class="px-8 py-3 bg-indigo-500 text-white font-semibold rounded-lg hover:bg-indigo-400 border border-indigo-400 transition">Rejoindre gratuitement</a>
                @endauth
            </div>

            <!-- Stats live cliquables -->
            <div class="inline-flex flex-wrap justify-center items-center gap-0 bg-white/10 backdrop-blur-sm rounded-2xl px-8 py-4">
                <a href="{{ route('members.index') }}" class="text-center px-6 hover:bg-white/10 rounded-xl py-2 transition group">
                    <p class="text-3xl font-bold group-hover:scale-110 transition-transform">{{ $stats['users'] }}</p>
                    <p class="text-indigo-200 text-sm">Membres</p>
                </a>
                <div class="w-px h-10 bg-white/30"></div>
                <a href="{{ route('explorer') }}" class="text-center px-6 hover:bg-white/10 rounded-xl py-2 transition group">
                    <p class="text-3xl font-bold group-hover:scale-110 transition-transform">{{ $stats['services'] }}</p>
                    <p class="text-indigo-200 text-sm">{{ $T['Services'] }}</p>
                </a>
                <div class="w-px h-10 bg-white/30"></div>
                <a href="{{ route('explorer') }}#demandes" class="text-center px-6 hover:bg-white/10 rounded-xl py-2 transition group">
                    <p class="text-3xl font-bold group-hover:scale-110 transition-transform">{{ $stats['requests'] }}</p>
                    <p class="text-indigo-200 text-sm">{{ $T['Requests'] }}</p>
                </a>
                <div class="w-px h-10 bg-white/30"></div>
                <a href="{{ route('exchanges.index') }}" class="text-center px-6 hover:bg-white/10 rounded-xl py-2 transition group">
                    <p class="text-3xl font-bold group-hover:scale-110 transition-transform">{{ $stats['exchanges'] }}</p>
                    <p class="text-indigo-200 text-sm">Échanges réalisés</p>
                </a>
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
                    <h3 class="font-semibold text-lg mb-2 dark:text-gray-100">Publiez vos {{ $T['services'] }}</h3>
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

    <!-- Create your boucle CTA -->
    <div class="py-16 bg-indigo-50 dark:bg-indigo-950/40">
        <div class="max-w-5xl mx-auto px-4">
            <div class="flex flex-col md:flex-row items-center gap-10">
                <div class="flex-1">
                    <span class="inline-block text-xs font-semibold uppercase tracking-widest text-indigo-500 dark:text-indigo-400 mb-3">Créateurs de réseau</span>
                    <h2 class="text-2xl font-bold text-gray-900 dark:text-gray-100 mb-4">Créez votre propre boucle</h2>
                    <p class="text-gray-600 dark:text-gray-400 mb-6 leading-relaxed">
                        Vous animez un réseau professionnel, une association ou un groupe de co-travailleurs ?
                        Créez votre espace privé sur BouclePro et invitez vos membres à échanger leurs compétences.
                    </p>
                    <div class="flex flex-wrap gap-4 text-sm text-gray-500 dark:text-gray-400 mb-8">
                        <div class="flex items-center gap-2">
                            <svg class="w-4 h-4 text-indigo-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                            Espace privé personnalisé
                        </div>
                        <div class="flex items-center gap-2">
                            <svg class="w-4 h-4 text-indigo-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                            Gratuit
                        </div>
                        <div class="flex items-center gap-2">
                            <svg class="w-4 h-4 text-indigo-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                            Accompagnement à la mise en place
                        </div>
                    </div>
                    <a href="{{ route('boucles.request.create') }}"
                       class="inline-flex items-center gap-2 px-6 py-3 bg-indigo-600 hover:bg-indigo-700 text-white font-semibold rounded-lg transition">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                        </svg>
                        Créer ma boucle
                    </a>
                </div>
                <div class="hidden md:flex flex-col gap-3 w-56">
                    <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-4 shadow-sm">
                        <div class="flex items-center gap-3 mb-2">
                            <div class="w-8 h-8 rounded-lg bg-indigo-600 flex items-center justify-center text-white text-xs font-bold">BNI</div>
                            <span class="text-sm font-semibold text-gray-800 dark:text-gray-100">Alumnis Ecole de Design</span>
                        </div>
                        <p class="text-xs text-gray-500 dark:text-gray-400">32 membres · 120 échanges</p>
                    </div>
                    <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-4 shadow-sm">
                        <div class="flex items-center gap-3 mb-2">
                            <div class="w-8 h-8 rounded-lg bg-purple-600 flex items-center justify-center text-white text-xs font-bold">AMT</div>
                            <span class="text-sm font-semibold text-gray-800 dark:text-gray-100">AMT Télétravail</span>
                        </div>
                        <p class="text-xs text-gray-500 dark:text-gray-400">18 membres · 45 échanges</p>
                    </div>
                    <div class="bg-white dark:bg-gray-800 rounded-xl border border-indigo-200 dark:border-indigo-700 p-4 shadow-sm border-dashed opacity-60">
                        <div class="flex items-center gap-3">
                            <div class="w-8 h-8 rounded-lg border-2 border-dashed border-indigo-300 dark:border-indigo-600 flex items-center justify-center">
                                <svg class="w-4 h-4 text-indigo-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                            </div>
                            <span class="text-sm text-indigo-500 dark:text-indigo-400">Votre boucle ici</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Featured services -->
    @if($featuredServices->isNotEmpty())
    <div class="py-16 bg-gray-50 dark:bg-gray-800">
        <div class="max-w-6xl mx-auto px-4">
            <div class="flex items-center justify-between mb-8">
                <h2 class="text-2xl font-bold text-gray-900 dark:text-gray-100">{{ $T['Services'] }} à la une</h2>
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
