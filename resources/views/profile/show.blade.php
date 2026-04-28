<x-app-layout>
    <div class="max-w-4xl mx-auto px-4 py-8">
        <!-- Profile header -->
        <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-6 mb-6">
            <div class="flex items-start gap-5">
                <img src="{{ $user->avatar_url }}" class="w-20 h-20 rounded-full" alt="">
                <div class="flex-1">
                    <div class="flex items-center gap-3 flex-wrap">
                        <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">{{ $user->name }}</h1>
                        @if($user->is_available)
                        <span class="flex items-center gap-1 px-2 py-0.5 bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-300 rounded-full text-xs font-medium">
                            <span class="w-1.5 h-1.5 bg-green-500 rounded-full"></span>Disponible
                        </span>
                        @endif
                    </div>
                    <div class="flex gap-6 mt-2 text-sm text-gray-500 dark:text-gray-400">
                        @if($user->rating)
                        <span>⭐ {{ number_format($user->rating, 1) }}/5</span>
                        @endif
                        <span>{{ $completedCount }} échange(s) complété(s)</span>
                        <span>{{ $user->points_balance }} pts</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Services -->
        <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">Services proposés</h2>
        @if($services->isEmpty())
        <p class="text-gray-400 text-sm">Aucun service actif.</p>
        @else
        <div class="grid sm:grid-cols-2 gap-4">
            @foreach($services as $service)
            <a href="{{ route('services.show', $service) }}" class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-4 hover:shadow-md transition">
                <div class="flex items-center justify-between mb-2">
                    <span class="px-2 py-0.5 rounded-full text-xs font-medium text-white" style="background-color:{{ $service->category->color }}">
                        {{ $service->category->name }}
                    </span>
                    <span class="font-bold text-indigo-600 dark:text-indigo-400 text-sm">{{ $service->points_cost }} pts</span>
                </div>
                <p class="font-medium text-gray-900 dark:text-gray-100 mb-1">{{ $service->title }}</p>
                <div class="flex flex-wrap gap-1">
                    @foreach($service->skills->take(3) as $skill)
                    <span class="px-2 py-0.5 bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300 rounded text-xs">{{ $skill->name }}</span>
                    @endforeach
                </div>
            </a>
            @endforeach
        </div>
        @endif
    </div>
</x-app-layout>
