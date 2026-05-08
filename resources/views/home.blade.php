<x-app-layout>
    @php
        $T = [
            'service' => 'micro-service',
            'services' => 'micro-services',
            'request' => 'demande d’aide',
            'requests' => 'demandes d’aide',
            'Services' => 'Micro-services',
        ];
    @endphp

    <!-- Hero Section -->
    <div class="relative bg-indigo-600 dark:bg-indigo-950 pt-20 pb-16 overflow-hidden">
        {{-- Background Decoration --}}
        <div class="absolute inset-0 opacity-10 pointer-events-none">
            <div class="absolute -top-24 -left-24 w-96 h-96 bg-white rounded-full blur-3xl"></div>
            <div class="absolute top-1/2 -right-24 w-64 h-64 bg-indigo-400 rounded-full blur-3xl"></div>
        </div>

        <div class="max-w-6xl mx-auto px-4 relative z-10 text-center">
            <h1 class="text-4xl sm:text-5xl lg:text-6xl font-extrabold text-white tracking-tight mb-6">
                Échangez vos compétences,<br>
                <span class="text-indigo-200">propulsez vos projets.</span>
            </h1>
            <p class="text-lg sm:text-xl text-indigo-100 max-w-2xl mx-auto mb-10 leading-relaxed">
                La plateforme de troc de services entre professionnels. Échangez sans argent, gagnez en efficacité.
            </p>

            <div class="flex flex-wrap justify-center gap-4 mb-12 sm:hidden">
                @guest
                    <a href="{{ route('register') }}" class="px-8 py-3 bg-white text-indigo-600 font-bold rounded-xl shadow-lg hover:bg-indigo-50 transition">
                        Commencer
                    </a>
                @endguest
            </div>

            {{-- Stats (Hidden on Mobile for cleaner entry) --}}
            <div class="hidden sm:flex justify-center gap-12 text-white border-t border-white/10 pt-10">
                <div class="text-center">
                    <p class="text-3xl font-bold">{{ $stats['users'] }}</p>
                    <p class="text-indigo-200 text-sm">Membres</p>
                </div>
                <div class="text-center">
                    <p class="text-3xl font-bold">{{ $stats['services'] }}</p>
                    <p class="text-indigo-200 text-sm">Services</p>
                </div>
                <div class="text-center">
                    <p class="text-3xl font-bold">{{ $stats['exchanges'] }}</p>
                    <p class="text-indigo-200 text-sm">Échanges</p>
                </div>
            </div>
        </div>
    </div>

    {{-- AI Conversational Block --}}
    <div class="relative -mt-8 z-20">
        <livewire:home-ai-input />
    </div>

    <!-- How it works (Hidden on Mobile) -->
    <div class="hidden sm:block py-20 bg-white dark:bg-gray-900">
        <div class="max-w-5xl mx-auto px-4">
            <h2 class="text-3xl font-bold text-center text-gray-900 dark:text-gray-100 mb-12">Comment ça marche ?</h2>
            <div class="grid md:grid-cols-3 gap-12">
                <div class="text-center">
                    <div class="w-16 h-16 bg-indigo-50 dark:bg-indigo-900/30 rounded-2xl flex items-center justify-center mx-auto mb-6">
                        <span class="text-2xl font-bold text-indigo-600">1</span>
                    </div>
                    <h3 class="font-bold text-xl mb-3 dark:text-gray-100 text-gray-900">Publiez vos {{ $T['services'] }}</h3>
                    <p class="text-gray-500 dark:text-gray-400 leading-relaxed">Décrivez vos compétences et fixez un prix en points BouclePro.</p>
                </div>
                <div class="text-center">
                    <div class="w-16 h-16 bg-indigo-50 dark:bg-indigo-900/30 rounded-2xl flex items-center justify-center mx-auto mb-6">
                        <span class="text-2xl font-bold text-indigo-600">2</span>
                    </div>
                    <h3 class="font-bold text-xl mb-3 dark:text-gray-100 text-gray-900">Échangez & collaborez</h3>
                    <p class="text-gray-500 dark:text-gray-400 leading-relaxed">Trouvez ce dont vous avez besoin et proposez vos services en retour.</p>
                </div>
                <div class="text-center">
                    <div class="w-16 h-16 bg-indigo-50 dark:bg-indigo-900/30 rounded-2xl flex items-center justify-center mx-auto mb-6">
                        <span class="text-2xl font-bold text-indigo-600">3</span>
                    </div>
                    <h3 class="font-bold text-xl mb-3 dark:text-gray-100 text-gray-900">Cumulez des points</h3>
                    <p class="text-gray-500 dark:text-gray-400 leading-relaxed">Utilisez vos points pour obtenir n'importe quel service sur la plateforme.</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Create your boucle CTA (Hidden on Mobile) -->
    <div class="hidden sm:block py-20 bg-gray-50 dark:bg-zinc-900/50">
        <div class="max-w-5xl mx-auto px-4">
            <div class="bg-white dark:bg-zinc-800 rounded-3xl p-8 md:p-12 shadow-sm border border-gray-100 dark:border-zinc-700 flex flex-col md:flex-row items-center gap-12">
                <div class="flex-1 text-center md:text-left">
                    <span class="inline-block px-3 py-1 bg-indigo-50 dark:bg-indigo-900/30 text-indigo-600 dark:text-indigo-400 text-xs font-bold uppercase tracking-wider rounded-full mb-4">Espaces Privés</span>
                    <h2 class="text-3xl font-bold text-gray-900 dark:text-gray-100 mb-6">Créez votre propre boucle</h2>
                    <p class="text-gray-600 dark:text-gray-400 mb-8 text-lg leading-relaxed">
                        Animez votre réseau professionnel ou votre association avec un espace privé et sécurisé.
                    </p>
                    <a href="{{ route('boucles.request.create') }}"
                       class="inline-flex items-center gap-2 px-8 py-4 bg-indigo-600 hover:bg-indigo-700 text-white font-bold rounded-2xl shadow-lg shadow-indigo-200 dark:shadow-none transition transform hover:-translate-y-0.5">
                        Lancer ma boucle
                    </a>
                </div>
                <div class="hidden lg:grid grid-cols-1 gap-4 w-64">
                    <div class="bg-gray-50 dark:bg-zinc-700/50 rounded-2xl p-4 border border-gray-100 dark:border-zinc-600">
                        <div class="flex items-center gap-3 mb-2">
                            <div class="w-8 h-8 rounded-lg bg-indigo-600 flex items-center justify-center text-white text-xs font-bold">BNI</div>
                            <span class="text-sm font-bold text-gray-900 dark:text-white">BNI Marseille</span>
                        </div>
                        <p class="text-[11px] text-gray-500 uppercase font-bold tracking-tight">42 membres actif</p>
                    </div>
                    <div class="bg-gray-50 dark:bg-zinc-700/50 rounded-2xl p-4 border border-gray-100 dark:border-zinc-600">
                        <div class="flex items-center gap-3 mb-2">
                            <div class="w-8 h-8 rounded-lg bg-emerald-600 flex items-center justify-center text-white text-xs font-bold">CO</div>
                            <span class="text-sm font-bold text-gray-900 dark:text-white">Coworking Space</span>
                        </div>
                        <p class="text-[11px] text-gray-500 uppercase font-bold tracking-tight">120 échanges le mois dernier</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Featured services (Hidden on Mobile) -->
    @if($featuredServices->isNotEmpty())
    <div class="hidden sm:block py-20 bg-white dark:bg-gray-900">
        <div class="max-w-6xl mx-auto px-4">
            <div class="flex items-center justify-between mb-12">
                <h2 class="text-3xl font-bold text-gray-900 dark:text-gray-100">À découvrir</h2>
                <a href="{{ route('explorer') }}" class="font-bold text-indigo-600 hover:text-indigo-700 flex items-center gap-2">
                    Voir tout
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3"/></svg>
                </a>
            </div>
            <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-8">
                @foreach($featuredServices as $service)
                <a href="{{ route('services.show', $service) }}"
                   class="bg-white dark:bg-zinc-800 rounded-3xl border border-gray-100 dark:border-zinc-700 p-6 hover:shadow-xl hover:border-indigo-100 dark:hover:border-indigo-900/50 transition-all group">
                    <div class="flex items-center justify-between mb-4">
                        <span class="px-3 py-1 rounded-full text-[10px] font-bold uppercase tracking-widest text-white" style="background-color:{{ $service->category->color }}">
                            {{ $service->category->name }}
                        </span>
                        <span class="font-bold text-indigo-600 dark:text-indigo-400">{{ $service->points_cost }} pts</span>
                    </div>
                    <h3 class="text-lg font-bold text-gray-900 dark:text-white group-hover:text-indigo-600 transition mb-4 line-clamp-2">{{ $service->title }}</h3>
                    <div class="flex items-center gap-3 pt-4 border-t border-gray-50 dark:border-zinc-700">
                        <img src="{{ $service->user->avatar_url }}" class="w-8 h-8 rounded-full bg-gray-100" alt="">
                        <span class="text-sm font-medium text-gray-600 dark:text-gray-400">{{ $service->user->name }}</span>
                    </div>
                </a>
                @endforeach
            </div>
        </div>
    </div>
    @endif

    <!-- CTA bottom (Hidden on Mobile) -->
    @guest
    <div class="hidden sm:block py-24 bg-indigo-600 text-white text-center relative overflow-hidden">
        <div class="absolute inset-0 opacity-10 pointer-events-none">
            <div class="absolute top-0 right-0 w-64 h-64 bg-white rounded-full blur-3xl -mr-32 -mt-32"></div>
        </div>
        <div class="max-w-2xl mx-auto px-4 relative z-10">
            <h2 class="text-4xl font-extrabold mb-6">Prêt à échanger ?</h2>
            <p class="text-xl text-indigo-100 mb-10">Rejoignez BouclePro aujourd'hui et recevez 100 points de bienvenue.</p>
            <a href="{{ route('register') }}" class="inline-block px-10 py-4 bg-white text-indigo-600 font-bold text-lg rounded-2xl shadow-xl hover:bg-indigo-50 transition transform hover:scale-105">
                Créer mon compte gratuitement
            </a>
        </div>
    </div>
    @endguest
</x-app-layout>
