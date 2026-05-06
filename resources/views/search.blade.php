<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            Résultats pour « {{ $q }} »
        </h2>
    </x-slot>

    <div class="py-10">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 space-y-10">

            @if($q === '')
            <p class="text-gray-500 dark:text-gray-400 text-center py-16">Saisissez un terme pour lancer la recherche.</p>
            @elseif($services->isEmpty() && $requests->isEmpty() && $users->isEmpty() && $posts->isEmpty())
            <p class="text-gray-500 dark:text-gray-400 text-center py-16">Aucun résultat pour « {{ $q }} ».</p>
            @else

            {{-- Services --}}
            @if($services->isNotEmpty())
            <section>
                <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4 flex items-center gap-2">
                    <span class="w-2 h-2 rounded-full bg-indigo-500 inline-block"></span> Services
                </h3>
                <div class="space-y-3">
                    @foreach($services as $service)
                    <a href="{{ route('services.show', $service) }}"
                       class="flex items-center justify-between p-4 bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 hover:shadow-md transition">
                        <div>
                            <p class="font-medium text-gray-900 dark:text-gray-100">{{ $service->title }}</p>
                            <p class="text-sm text-gray-500 dark:text-gray-400">
                                {{ $service->category->name }} · {{ $service->user->name }}
                            </p>
                        </div>
                        <span class="text-indigo-600 dark:text-indigo-400 font-semibold text-sm">{{ $service->points_cost }} pts</span>
                    </a>
                    @endforeach
                </div>
            </section>
            @endif

            {{-- Demandes --}}
            @if($requests->isNotEmpty())
            <section>
                <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4 flex items-center gap-2">
                    <span class="w-2 h-2 rounded-full bg-orange-400 inline-block"></span> Demandes
                </h3>
                <div class="space-y-3">
                    @foreach($requests as $request)
                    <a href="{{ route('requests.show', $request) }}"
                       class="flex items-center justify-between p-4 bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 hover:shadow-md transition">
                        <div>
                            <p class="font-medium text-gray-900 dark:text-gray-100">{{ $request->title }}</p>
                            <p class="text-sm text-gray-500 dark:text-gray-400">
                                {{ $request->category->name }} · {{ $request->user->name }}
                            </p>
                        </div>
                        <span class="text-orange-500 font-semibold text-sm">{{ $request->budget_min }}–{{ $request->budget_max ?? '?' }} pts</span>
                    </a>
                    @endforeach
                </div>
            </section>
            @endif

            {{-- Utilisateurs --}}
            @if($users->isNotEmpty())
            <section>
                <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4 flex items-center gap-2">
                    <span class="w-2 h-2 rounded-full bg-green-500 inline-block"></span> Utilisateurs
                </h3>
                <div class="space-y-3">
                    @foreach($users as $user)
                    <a href="{{ route('profile.show', $user) }}"
                       class="flex items-center gap-4 p-4 bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 hover:shadow-md transition">
                        <img src="{{ $user->avatar_url }}" class="w-10 h-10 rounded-full flex-shrink-0" alt="">
                        <div>
                            <p class="font-medium text-gray-900 dark:text-gray-100">{{ $user->name }}</p>
                            <p class="text-sm text-gray-500 dark:text-gray-400">
                                {{ $user->location ?? 'Localisation non renseignée' }}
                                @if($user->rating)
                                · ★ {{ number_format($user->rating, 1) }}
                                @endif
                            </p>
                        </div>
                    </a>
                    @endforeach
                </div>
            </section>
            @endif

            {{-- Articles de blog --}}
            @if($posts->isNotEmpty())
            <section>
                <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4 flex items-center gap-2">
                    <span class="w-2 h-2 rounded-full bg-purple-500 inline-block"></span> Articles de blog
                </h3>
                <div class="space-y-3">
                    @foreach($posts as $post)
                    <a href="{{ route('blog.show', $post) }}"
                       class="flex items-center justify-between p-4 bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 hover:shadow-md transition">
                        <div>
                            <p class="font-medium text-gray-900 dark:text-gray-100">{{ $post->title }}</p>
                            <p class="text-sm text-gray-500 dark:text-gray-400">
                                {{ $post->user->name }}
                                @if($post->categories->isNotEmpty())
                                · {{ $post->categories->first()->name }}
                                @endif
                                @if($post->read_time)
                                · {{ $post->read_time }} min
                                @endif
                            </p>
                        </div>
                        <svg class="w-4 h-4 text-gray-300 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                    </a>
                    @endforeach
                </div>
            </section>
            @endif

            @endif
        </div>
    </div>
</x-app-layout>
