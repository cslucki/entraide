@extends('layouts.app')

@section('content')
<div class="min-h-screen">
    {{-- Hero Section --}}
    <section class="relative overflow-hidden" style="background: linear-gradient(135deg, {{ $community->accent_color }} 0%, {{ $community->accent_color }}dd 100%);">
        <div class="absolute inset-0 bg-black/30"></div>
        @if($community->hero_image)
        <img src="{{ $community->getHeroImageUrl() }}" alt="" class="absolute inset-0 w-full h-full object-cover mix-blend-overlay opacity-40">
        @endif
        <div class="relative max-w-4xl mx-auto px-4 py-24 sm:py-32 text-center">
            <h1 class="text-4xl sm:text-5xl font-bold text-white mb-4">
                {{ $community->hero_title ?: $community->name }}
            </h1>
            @if($community->hero_description)
            <p class="text-xl text-white/90 max-w-2xl mx-auto mb-8">{{ $community->hero_description }}</p>
            @endif
            <div class="flex flex-wrap justify-center gap-3">
                @auth
                    <a href="{{ route('community.dashboard', ['community' => $community->slug]) }}"
                       class="px-6 py-3 bg-white text-gray-900 font-semibold rounded-lg hover:bg-gray-100 transition shadow-lg">
                        Accéder au tableau de bord
                    </a>
                    <a href="{{ route('community.explorer', ['community' => $community->slug]) }}"
                       class="px-6 py-3 bg-white/20 text-white font-semibold rounded-lg hover:bg-white/30 transition shadow-lg border border-white/30">
                        Explorer les {{ $T['services'] }}
                    </a>
                @else
                    <a href="{{ route('community.register', ['community' => $community->slug]) }}"
                       class="px-6 py-3 bg-white text-gray-900 font-semibold rounded-lg hover:bg-gray-100 transition shadow-lg">
                        Rejoindre la communauté
                    </a>
                    <a href="{{ route('community.login', ['community' => $community->slug]) }}"
                       class="px-6 py-3 bg-white/20 text-white font-semibold rounded-lg hover:bg-white/30 transition shadow-lg border border-white/30">
                        Se connecter
                    </a>
                @endauth
            </div>
        </div>
    </section>

    {{-- Stats Section --}}
    <section class="bg-white dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700">
        <div class="max-w-4xl mx-auto px-4 py-8">
            <div class="grid grid-cols-3 gap-6 text-center">
                <div>
                    <p class="text-3xl font-bold text-indigo-600 dark:text-indigo-400">{{ $memberCount }}</p>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Membres</p>
                </div>
                <div>
                    <p class="text-3xl font-bold text-indigo-600 dark:text-indigo-400">{{ $serviceCount }}</p>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">{{ $T['Services'] }}</p>
                </div>
                <div>
                    <p class="text-3xl font-bold text-indigo-600 dark:text-indigo-400">{{ $transactionCount }}</p>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Échanges réussis</p>
                </div>
            </div>
        </div>
    </section>

    {{-- Recent Services Section --}}
    <section class="max-w-6xl mx-auto px-4 py-12">
        <h2 class="text-2xl font-bold text-gray-900 dark:text-gray-100 mb-6">Derniers services publiés</h2>

        @if($recentServices->isEmpty())
            <div class="text-center py-12 text-gray-500 dark:text-gray-400">
                <p>Aucun service publié pour le moment.</p>
                <a href="{{ route('community.register', ['community' => $community->slug]) }}" class="text-indigo-600 hover:text-indigo-800 mt-2 inline-block">
                    Rejoindre la communauté pour publier votre premier service
                </a>
            </div>
        @else
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
                @foreach($recentServices as $service)
                <a href="{{ route('community.services.show', ['community' => $community->slug, 'service' => $service]) }}"
                   class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-5 hover:shadow-lg transition group">
                    @if($service->category)
                        <span class="inline-block px-2 py-1 text-xs font-medium rounded-full mb-3"
                              style="background-color: {{ $service->category->color }}20; color: {{ $service->category->color }};">
                            {{ $service->category->name }}
                        </span>
                    @endif
                    <h3 class="font-semibold text-gray-900 dark:text-gray-100 group-hover:text-indigo-600 dark:group-hover:text-indigo-400 transition truncate">
                        {{ $service->title }}
                    </h3>
                    @if($service->description)
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1 line-clamp-2">{{ Str::limit($service->description, 100) }}</p>
                    @endif
                    <div class="flex items-center justify-between mt-4 pt-3 border-t border-gray-100 dark:border-gray-700">
                        <div class="flex items-center gap-2">
                            <img src="{{ $service->user->avatar_url }}" class="w-6 h-6 rounded-full" alt="">
                            <span class="text-xs text-gray-500 dark:text-gray-400">{{ $service->user->name }}</span>
                        </div>
                        <span class="text-sm font-bold text-indigo-600 dark:text-indigo-400">{{ $service->cost }} pts</span>
                    </div>
                </a>
                @endforeach
            </div>

            <div class="text-center mt-8">
                <a href="{{ route('community.explorer', ['community' => $community->slug]) }}"
                   class="inline-flex items-center gap-2 px-5 py-2 bg-indigo-600 text-white text-sm font-medium rounded-lg hover:bg-indigo-700 transition">
                    Voir tous les services
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"/></svg>
                </a>
            </div>
        @endif
    </section>

    {{-- Footer --}}
    <section class="bg-gray-50 dark:bg-gray-900 border-t border-gray-200 dark:border-gray-700">
        <div class="max-w-4xl mx-auto px-4 py-8 text-center">
            <p class="text-sm text-gray-500 dark:text-gray-400">
                Communauté <strong class="text-gray-700 dark:text-gray-300">{{ $community->name }}</strong> — Propulsée par BouclePro
            </p>
        </div>
    </section>
</div>
@endsection
