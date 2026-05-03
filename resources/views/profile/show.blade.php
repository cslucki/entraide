<x-app-layout>
    <div class="max-w-4xl mx-auto px-4 py-8">
        <!-- Profile header -->
        <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-6 mb-6 relative">
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
                        @if($user->location)
                        <span>📍 {{ $user->location }}</span>
                        @endif
                    </div>
                    @if($user->bio)
                    <div class="mt-4 text-gray-700 dark:text-gray-300 whitespace-pre-wrap text-sm leading-relaxed max-w-2xl">
                        {{ $user->bio }}
                    </div>
                    @endif
                    @if($badges->isNotEmpty())
                    <div class="flex flex-wrap gap-2 mt-4">
                        @foreach($badges as $badge)
                        <span title="{{ $badge->description }}" class="flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-semibold text-white cursor-default"
                              style="background-color: {{ $badge->color }}">
                            {{ $badge->icon }} {{ $badge->name }}
                        </span>
                        @endforeach
                    </div>
                    @endif
                </div>
                @auth
                @if(auth()->id() !== $user->id)
                <div x-data="{ open: false }" class="flex-shrink-0">
                    <button @click="open = !open" class="text-xs text-gray-400 hover:text-red-500 transition">Signaler</button>
                    <div x-show="open" x-cloak class="absolute right-6 mt-2 w-72 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-xl p-4 shadow-lg z-10">
                        <form method="POST" action="{{ route('reports.user', $user) }}">
                            @csrf
                            <p class="text-xs font-semibold text-red-700 dark:text-red-300 mb-2">Signaler cet utilisateur</p>
                            <select name="reason" required class="w-full mb-2 px-3 py-2 border border-red-200 dark:border-red-700 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm">
                                <option value="">Motif...</option>
                                <option value="Comportement abusif">Comportement abusif</option>
                                <option value="Arnaque ou fraude">Arnaque ou fraude</option>
                                <option value="Faux profil">Faux profil</option>
                                <option value="Autre">Autre</option>
                            </select>
                            <textarea name="details" rows="2" placeholder="Détails (optionnel)..."
                                class="w-full px-3 py-2 border border-red-200 dark:border-red-700 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm mb-2 resize-none"></textarea>
                            <div class="flex gap-2">
                                <button type="submit" class="flex-1 px-3 py-1.5 bg-red-600 text-white text-xs rounded-lg hover:bg-red-700">Envoyer</button>
                                <button type="button" @click="open = false" class="px-3 py-1.5 border border-red-200 text-red-600 text-xs rounded-lg hover:bg-red-50 dark:hover:bg-red-900/30">Annuler</button>
                            </div>
                        </form>
                    </div>
                </div>
                @endif
                @endauth
            </div>
        </div>

        <!-- Évaluations reçues -->
        @if($reviews->isNotEmpty())
        <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">Évaluations reçues</h2>
        <div class="space-y-3 mb-8">
            @foreach($reviews as $review)
            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-4">
                <div class="flex items-center justify-between mb-2">
                    <div class="flex items-center gap-2">
                        <img src="{{ $review->reviewer->avatar_url }}" class="w-7 h-7 rounded-full" alt="">
                        <span class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ $review->reviewer->name }}</span>
                    </div>
                    <div class="flex items-center gap-1">
                        @for($i = 1; $i <= 5; $i++)
                        <span class="text-lg {{ $i <= $review->rating ? 'text-yellow-400' : 'text-gray-300 dark:text-gray-600' }}">★</span>
                        @endfor
                        <span class="text-xs text-gray-500 ml-1">{{ $review->created_at->diffForHumans() }}</span>
                    </div>
                </div>
                @if($review->comment)
                <p class="text-sm text-gray-600 dark:text-gray-400 italic">"{{ $review->comment }}"</p>
                @endif
            </div>
            @endforeach
        </div>
        @endif

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

        <!-- Demandes ouvertes -->
        @if($openRequests->isNotEmpty())
        <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mt-8 mb-4">Recherche d'aide</h2>
        <div class="grid sm:grid-cols-2 gap-4">
            @foreach($openRequests as $req)
            <a href="{{ route('requests.show', $req) }}" class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-4 hover:shadow-md transition">
                <div class="flex items-center justify-between mb-2">
                    <span class="px-2 py-0.5 rounded-full text-xs font-medium text-white" style="background-color:{{ $req->category->color }}">
                        {{ $req->category->name }}
                    </span>
                    <span class="font-bold text-green-600 dark:text-green-400 text-sm">
                        {{ $req->budget_min }}{{ $req->budget_max ? '–'.$req->budget_max : '+' }} pts
                    </span>
                </div>
                <p class="font-medium text-gray-900 dark:text-gray-100 mb-1">{{ $req->title }}</p>
                @if($req->deadline)
                <p class="text-xs text-gray-400">⏰ Avant le {{ $req->deadline->format('d/m/Y') }}</p>
                @endif
            </a>
            @endforeach
        </div>
        @endif
    </div>
</x-app-layout>
