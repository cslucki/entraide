<x-app-layout>
    <div class="max-w-5xl mx-auto px-4 py-8">
        <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100 mb-6">Mes favoris</h1>

        @if($favorites->isEmpty())
        <div class="text-center py-16 text-gray-400">
            <svg class="w-12 h-12 mx-auto mb-3 opacity-30" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z" />
            </svg>
            <p>Aucun favori pour l'instant.</p>
            <a href="{{ route('explorer') }}" class="mt-3 inline-block text-indigo-600 hover:underline text-sm">Explorer les {{ $T['services'] }}</a>
        </div>
        @else
        <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-6">
            @foreach($favorites as $fav)
            @php $service = $fav->service; @endphp
            @if($service)
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 overflow-hidden">
                <div class="p-5">
                    <div class="flex items-center justify-between mb-3">
                        <span class="px-2 py-0.5 rounded-full text-xs font-medium text-white" style="background-color:{{ $service->category->color }}">
                            {{ $service->category->name }}
                        </span>
                        <div class="flex items-center gap-2">
                            <span class="text-indigo-600 dark:text-indigo-400 font-bold text-sm">{{ $service->points_cost }} pts</span>
                            <form method="POST" action="{{ route('favorites.toggle', $service) }}">
                                @csrf
                                <button class="text-red-400 hover:text-red-600" title="Retirer des favoris">
                                    <svg class="w-5 h-5 fill-current" viewBox="0 0 24 24"><path d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"/></svg>
                                </button>
                            </form>
                        </div>
                    </div>
                    <a href="{{ route('services.show', $service) }}" class="block">
                        <h3 class="font-semibold text-gray-900 dark:text-gray-100 mb-1 hover:text-indigo-600 line-clamp-1">{{ $service->title }}</h3>
                        <p class="text-gray-500 dark:text-gray-400 text-sm line-clamp-2 mb-3">{{ $service->description }}</p>
                    </a>
                    <div class="flex flex-wrap gap-1 mb-3">
                        @foreach($service->skills->take(3) as $skill)
                        <span class="px-2 py-0.5 bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300 rounded text-xs">{{ $skill->name }}</span>
                        @endforeach
                    </div>
                    <a href="{{ route('profile.show', $service->user) }}"
                       class="flex items-center gap-2 text-xs text-gray-500 dark:text-gray-400 hover:text-indigo-600 dark:hover:text-indigo-400 transition">
                        <img src="{{ $service->user->avatar_url }}" class="w-5 h-5 rounded-full" alt="">
                        {{ $service->user->name }}
                    </a>
                </div>
            </div>
            @endif
            @endforeach
        </div>
        <div class="mt-6">{{ $favorites->links() }}</div>
        @endif
    </div>
</x-app-layout>
