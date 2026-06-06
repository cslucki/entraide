<x-page :title="$service->title" width="4xl">
        <div class="mb-6">
            <a href="{{ route('explorer') }}" class="text-sm text-gray-500 hover:text-indigo-600">← Retour à l'explorateur</a>
        </div>

        @if($isPaused)
        <div class="mb-4 bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-200 dark:border-yellow-800 text-yellow-800 dark:text-yellow-300 px-4 py-3 rounded-lg text-sm flex items-center gap-2">
            <svg class="w-4 h-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            Ce service est en pause — il n'est pas visible par les autres utilisateurs.
            <a href="{{ route('services.edit', $service) }}" class="ml-auto font-medium underline">Modifier</a>
        </div>
        @endif

        <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
            @if($service->images->isNotEmpty())
            <div class="border-b border-gray-100 dark:border-gray-700 bg-gray-50 dark:bg-gray-900/50" x-data="{ active: 0 }">
                <div class="relative aspect-video">
                    @foreach($service->images as $index => $img)
                    <img x-show="active === {{ $index }}" src="{{ $img->url }}" class="w-full h-full object-cover">
                    @endforeach

                    @if($service->images->count() > 1)
                    <button @click="active = (active > 0) ? active - 1 : {{ $service->images->count() - 1 }}" class="absolute left-4 top-1/2 -translate-y-1/2 bg-black/30 hover:bg-black/50 text-white p-2 rounded-full backdrop-blur-sm transition">
                        <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                    </button>
                    <button @click="active = (active < {{ $service->images->count() - 1 }}) ? active + 1 : 0" class="absolute right-4 top-1/2 -translate-y-1/2 bg-black/30 hover:bg-black/50 text-white p-2 rounded-full backdrop-blur-sm transition">
                        <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                    </button>
                    @endif
                </div>
                @if($service->images->count() > 1)
                <div class="flex gap-2 p-4 overflow-x-auto">
                    @foreach($service->images as $index => $img)
                    <button @click="active = {{ $index }}" class="flex-shrink-0 w-20 aspect-video rounded-lg border-2 transition overflow-hidden" :class="active === {{ $index }} ? 'border-indigo-500' : 'border-transparent'">
                        <img src="{{ $img->url }}" class="w-full h-full object-cover">
                    </button>
                    @endforeach
                </div>
                @endif
            </div>
            @endif

            <div class="p-6">
                <!-- Header -->
                <div class="flex items-start justify-between mb-4">
                    <div class="flex-1">
                        <span class="px-2 py-0.5 rounded-full text-xs font-medium text-white" style="background-color:{{ $service->category->color }}">
                            {{ $service->category->displayName('transactions') }}
                        </span>
                        <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100 mt-2">{{ $service->title }}</h1>
                    </div>
                    <div class="flex items-start gap-3 ml-4">
                        @auth
                        @if(auth()->id() !== $service->user_id)
                        <form method="POST" action="{{ route('favorites.toggle', $service) }}" class="flex-shrink-0 mt-1">
                            @csrf
                            <button type="submit" title="{{ $isFavorited ? 'Retirer des favoris' : 'Ajouter aux favoris' }}"
                                class="p-2 rounded-lg border {{ $isFavorited ? 'border-red-300 bg-red-50 dark:bg-red-900/20 text-red-500' : 'border-gray-200 dark:border-gray-600 text-gray-400 hover:text-red-400 hover:border-red-300' }} transition">
                                <svg class="w-5 h-5" fill="{{ $isFavorited ? 'currentColor' : 'none' }}" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z" />
                                </svg>
                            </button>
                        </form>
                        @endif
                        @endauth
                        <div class="text-right">
                            <p class="text-3xl font-bold text-indigo-600 dark:text-indigo-400">{{ $service->points_cost }}</p>
                            <p class="text-xs text-gray-500">points</p>
                        </div>
                    </div>
                </div>

                <!-- Author -->
                <div class="flex items-center gap-3 mb-6 pb-6 border-b border-gray-100 dark:border-gray-700">
                    <img src="{{ $service->user->avatar_url }}" class="w-10 h-10 rounded-full" alt="">
                    <div>
                        <a href="{{ route('profile.show', $service->user) }}" class="font-medium text-gray-900 dark:text-gray-100 hover:text-indigo-600">{{ $service->user->name }}</a>
                        <div class="flex items-center gap-2 text-xs text-gray-500">
                            <span>{{ match($service->delivery_mode) { 'remote' => '🌐 À distance', 'onsite' => '📍 Sur site', 'both' => '🌐📍 Distance ou sur site' } }}</span>
                            @if($service->user->is_available)
                            <span class="flex items-center gap-1"><span class="w-1.5 h-1.5 bg-green-500 rounded-full"></span>Disponible</span>
                            @endif
                        </div>
                    </div>
                </div>

                <!-- Description -->
                <div class="prose dark:prose-invert max-w-none mb-6">
                    <p class="text-gray-700 dark:text-gray-300 whitespace-pre-wrap">{{ $service->description }}</p>
                </div>

                <!-- Skills & Tags -->
                @if($service->skills->isNotEmpty())
                <div class="mb-4">
                    <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2">Compétences</p>
                    <div class="flex flex-wrap gap-2">
                        @foreach($service->skills as $skill)
                        <span class="px-3 py-1 bg-indigo-50 dark:bg-indigo-900/30 text-indigo-700 dark:text-indigo-300 rounded-full text-sm">{{ $skill->name }}</span>
                        @endforeach
                    </div>
                </div>
                @endif

                @if($service->tags->isNotEmpty())
                <div class="mb-6">
                    <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2">Tags</p>
                    <div class="flex flex-wrap gap-2">
                        @foreach($service->tags as $tag)
                        <span class="px-2 py-0.5 bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300 rounded text-xs">#{{ $tag->name }}</span>
                        @endforeach
                    </div>
                </div>
                @endif

                <!-- CTA -->
                @auth
                @if(auth()->id() !== $service->user_id)
                <!-- Signalement -->
                <div class="mb-4" x-data="{ open: false }">
                    <button @click="open = !open" class="text-xs text-gray-400 hover:text-red-500 transition">Signaler ce service</button>
                    <div x-show="open" x-cloak class="mt-2 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg p-4">
                        <form method="POST" action="{{ route('reports.service', $service) }}">
                            @csrf
                            <select name="reason" required class="w-full mb-2 px-3 py-2 border border-red-200 dark:border-red-700 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm">
                                <option value="">Motif du signalement...</option>
                                <option value="Contenu inapproprié">Contenu inapproprié</option>
                                <option value="Arnaque ou fraude">Arnaque ou fraude</option>
                                <option value="Spam">Spam</option>
                                <option value="Autre">Autre</option>
                            </select>
                            <textarea name="details" rows="2" placeholder="Détails (optionnel)..."
                                class="w-full px-3 py-2 border border-red-200 dark:border-red-700 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm mb-2 resize-none"></textarea>
                            <button type="submit" class="px-3 py-1.5 bg-red-600 text-white text-xs rounded-lg hover:bg-red-700">Envoyer le signalement</button>
                        </form>
                    </div>
                </div>

                <div class="border-t border-gray-100 dark:border-gray-700 pt-6">
                    <form method="POST" action="{{ route('transactions.store') }}" class="flex items-center gap-4">
                        @csrf
                        <input type="hidden" name="service_id" value="{{ $service->id }}">
                        <input type="number" name="points_proposed" value="{{ $service->points_cost }}" min="1"
                            class="w-32 px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-indigo-500">
                        <button type="submit" class="px-6 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 font-medium">
                            Proposer cet échange
                        </button>
                        <span class="text-xs text-gray-500">Votre solde : {{ auth()->user()->points_balance }} pts</span>
                    </form>
                    @if($errors->any())
                    <p class="text-red-500 text-sm mt-2">{{ $errors->first() }}</p>
                    @endif
                </div>
                @else
                <div class="border-t border-gray-100 dark:border-gray-700 pt-6 flex gap-3">
                    <a href="{{ route('services.edit', $service) }}" class="px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700">Modifier</a>
                </div>
                @endif
                @endauth
            </div>
        </div>
    </div>
</x-page>
