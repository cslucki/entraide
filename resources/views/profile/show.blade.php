<x-app-layout>
    <x-slot name="title">Profil de {{ $user->name }}</x-slot>

    @php
        $organizationRouteParam = request()->route('organization');
        $agentAiChatUrl = $organizationRouteParam && Route::has('organization.agent-ia.profile.chat')
            ? route('organization.agent-ia.profile.chat', ['organization' => $organizationRouteParam, 'user' => $user])
            : route('agent-ia.profile.chat', $user);
    @endphp

    <!-- Desktop topbar -->
    <div class="hidden md:flex items-center gap-3 px-4 sm:px-6 lg:px-8 py-3 border-b border-gray-200 dark:border-gray-700 bg-[var(--bp-surface)] sticky top-0 z-30">
        <a href="{{ route('members.index') }}" class="inline-flex h-8 w-8 items-center justify-center rounded-full bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-600 flex-shrink-0" aria-label="Retour à l'annuaire">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
        </a>
        <span class="truncate text-sm font-medium text-gray-900 dark:text-gray-100">{{ $user->name }}</span>
    </div>

    <x-page-container>
    <!-- Profile header -->
        <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-6 mb-6 relative">
            @if(auth()->check() && auth()->id() === $user->id)
            <div class="absolute top-4 right-4">
                <a href="{{ route('profile.edit') }}"
                   class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold rounded-lg transition">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/></svg>
                    Modifier mon profil
                </a>
            </div>
            @endif
            <div class="flex flex-col gap-5 sm:flex-row sm:items-start">
                <div class="relative flex-shrink-0">
                    <img src="{{ $user->avatar_url }}" class="h-20 w-20 rounded-full object-cover" alt="">
                    @if(auth()->check() && auth()->id() === $user->id)
                    <a href="{{ route('profile.edit') }}"
                       class="absolute bottom-0 right-0 w-6 h-6 bg-indigo-600 hover:bg-indigo-700 rounded-full flex items-center justify-center shadow-md transition"
                       title="Modifier mon profil">
                        <svg class="w-3 h-3 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/></svg>
                    </a>
                    @endif
                </div>
                <div class="w-full flex-1">
                    <div class="flex items-center gap-3 flex-wrap">
                        <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">{{ $user->name }}</h1>
                        @if($user->is_available)
                        <span class="flex items-center gap-1 px-2 py-0.5 bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-300 rounded-full text-xs font-medium">
                            <span class="w-1.5 h-1.5 bg-green-500 rounded-full"></span>Disponible
                        </span>
                        @endif
                    </div>
                    <div class="flex flex-wrap gap-x-6 gap-y-2 mt-2 text-sm text-gray-500 dark:text-gray-400">
                        @if($user->rating)
                        <span>⭐ {{ number_format($user->rating, 1) }}/5</span>
                        @endif
                        <span>{{ $completedCount }} échange(s) complété(s)</span>
                        <span>{{ $user->points_balance }} pts</span>
                        @if($user->location)
                        <span>📍 {{ $user->location }}</span>
                        @endif
                        @if($user->show_email)
                        <span class="flex items-center gap-1.5">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                            {{ $user->email }}
                        </span>
                        @endif
                        @if($user->show_phone && $user->phone)
                        <span class="flex items-center gap-1.5">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/></svg>
                            {{ $user->phone }}
                        </span>
                        @endif
                    </div>
                    @if($user->bio)
                    <div class="mt-4 text-gray-700 dark:text-gray-300 whitespace-pre-line text-sm leading-relaxed max-w-2xl">
                        {{ $user->bio }}
                    </div>
                    @endif
                    @if($user->website || $user->linkedin_url)
                    <div class="flex flex-wrap gap-3 mt-3">
                        @if($user->website)
                        <a href="{{ $user->website }}" target="_blank" rel="noopener noreferrer"
                           class="inline-flex items-center gap-1.5 text-sm text-indigo-600 dark:text-indigo-400 hover:underline">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9"/></svg>
                            {{ parse_url($user->website, PHP_URL_HOST) ?: $user->website }}
                        </a>
                        @endif
                        @if($user->linkedin_url)
                        <a href="{{ $user->linkedin_url }}" target="_blank" rel="noopener noreferrer"
                           class="inline-flex items-center gap-1.5 text-sm text-blue-600 dark:text-blue-400 hover:underline">
                            <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24"><path d="M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433a2.062 2.062 0 01-2.063-2.065 2.064 2.064 0 112.063 2.065zm1.782 13.019H3.555V9h3.564v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.222 0h.003z"/></svg>
                            LinkedIn
                        </a>
                        @endif
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
                    @if(auth()->guest() || auth()->id() !== $user->id)
                    <div class="mt-5 grid gap-3 sm:grid-cols-2">
                        @if($memberAiProfile)
                        <a href="{{ $agentAiChatUrl }}" class="group flex min-h-24 items-start gap-3 rounded-2xl border border-violet-200 bg-violet-50 p-4 text-left transition hover:border-violet-300 hover:bg-violet-100 dark:border-violet-900/60 dark:bg-violet-950/30 dark:hover:bg-violet-950/50" title="Discuter avec l'agent de profil IA de {{ $user->name }}">
                            <span class="flex h-11 w-11 flex-shrink-0 items-center justify-center rounded-full bg-violet-600 text-white shadow-sm transition group-hover:scale-105">
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                            </span>
                            <span class="min-w-0">
                                <span class="block text-sm font-semibold text-gray-900 dark:text-gray-100">Agent de profil IA</span>
                                <span class="mt-1 block text-sm leading-5 text-gray-600 dark:text-gray-300">Lancer l'agent IA pour poser des questions sur ce profil.</span>
                            </span>
                        </a>
                        @endif

                        <a href="{{ auth()->check() ? route('messages.with', $user) : route('login') }}" class="group flex min-h-24 items-start gap-3 rounded-2xl border border-indigo-200 bg-indigo-50 p-4 text-left transition hover:border-indigo-300 hover:bg-indigo-100 dark:border-indigo-900/60 dark:bg-indigo-950/30 dark:hover:bg-indigo-950/50">
                            <span class="flex h-11 w-11 flex-shrink-0 items-center justify-center rounded-full bg-indigo-600 text-white shadow-sm transition group-hover:scale-105">
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>
                            </span>
                            <span class="min-w-0">
                                <span class="block text-sm font-semibold text-gray-900 dark:text-gray-100">Écrire à {{ $user->name }}</span>
                                <span class="mt-1 block text-sm leading-5 text-gray-600 dark:text-gray-300">Démarrer une conversation humaine directe.</span>
                            </span>
                        </a>
                    </div>

                    @auth
                    <div x-data="{ open: false }" class="mt-4 text-right">
                        <button @click="open = !open" class="text-xs text-gray-400 hover:text-red-500 transition">Signaler</button>
                        <div x-show="open" x-cloak class="mt-2 rounded-xl border border-red-200 bg-red-50 p-4 text-left shadow-lg dark:border-red-800 dark:bg-red-900/20">
                            <form method="POST" action="{{ route('reports.user', $user) }}">
                                @csrf
                                <p class="mb-2 text-xs font-semibold text-red-700 dark:text-red-300">Signaler cet utilisateur</p>
                                <select name="reason" required class="mb-2 w-full rounded-lg border border-red-200 bg-white px-3 py-2 text-sm text-gray-900 dark:border-red-700 dark:bg-gray-800 dark:text-gray-100">
                                    <option value="">Motif...</option>
                                    <option value="Comportement abusif">Comportement abusif</option>
                                    <option value="Arnaque ou fraude">Arnaque ou fraude</option>
                                    <option value="Faux profil">Faux profil</option>
                                    <option value="Autre">Autre</option>
                                </select>
                                <textarea name="details" rows="2" placeholder="Détails (optionnel)..." class="mb-2 w-full resize-none rounded-lg border border-red-200 bg-white px-3 py-2 text-sm text-gray-900 dark:border-red-700 dark:bg-gray-800 dark:text-gray-100"></textarea>
                                <div class="flex gap-2">
                                    <button type="submit" class="flex-1 rounded-lg bg-red-600 px-3 py-1.5 text-xs text-white hover:bg-red-700">Envoyer</button>
                                    <button type="button" @click="open = false" class="rounded-lg border border-red-200 px-3 py-1.5 text-xs text-red-600 hover:bg-red-50 dark:hover:bg-red-900/30">Annuler</button>
                                </div>
                            </form>
                        </div>
                    </div>
                    @endauth
                    @endif
                </div>
            </div>
        </div>

        <!-- Agent de profil IA -->
        @if($memberAiProfile)
            @if(auth()->check() && auth()->id() === $user->id)
                <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-5 mb-6">
                    <div class="flex items-center justify-between gap-4">
                        <div class="flex items-center gap-3">
                            <div class="flex h-10 w-10 items-center justify-center rounded-full bg-gray-100 dark:bg-gray-700">
                                <svg class="h-5 w-5 text-gray-500 dark:text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                                </svg>
                            </div>
                            <div>
                                <p class="text-sm font-semibold text-gray-900 dark:text-gray-100">Agent IA activé</p>
                                <p class="text-xs text-gray-500 dark:text-gray-400">Les visiteurs peuvent interagir avec votre profil via l'agent IA.</p>
                            </div>
                        </div>
                        <a href="{{ route('agent-ia.interactions') }}" class="inline-flex items-center gap-1.5 rounded-lg border border-gray-300 dark:border-gray-600 px-4 py-2 text-sm font-semibold text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700 transition">
                            Voir les échanges
                        </a>
                    </div>
                </div>
            @endif
        @endif

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
        <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">{{ $T['Services'] }} proposés</h2>
        @if($services->isEmpty())
        <p class="text-gray-400 text-sm">Aucun service actif.</p>
        @else
        <div class="grid sm:grid-cols-2 gap-4">
            @foreach($services as $service)
            <a href="{{ route('services.show', $service) }}" class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-4 hover:shadow-md transition">
                <div class="flex items-center justify-between mb-2">
                    <span class="px-2 py-0.5 rounded-full text-xs font-medium text-white" style="background-color:{{ $service->category->color }}">
                        {{ $service->category->displayName('transactions') }}
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
                        {{ $req->category->displayName('transactions') }}
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

        <!-- Articles de blog -->
        <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mt-8 mb-4">Articles publiés</h2>
        @if($blogPosts->isEmpty())
        <p class="text-gray-400 text-sm">Cet utilisateur n'a pas encore publié d'article.</p>
        @else
        <div class="grid sm:grid-cols-2 gap-4">
            @foreach($blogPosts as $post)
            <a href="{{ route('blog.show', $post) }}" class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-4 hover:shadow-md transition">
                <div class="flex items-center justify-between mb-2">
                    @if($post->category)
                    <span class="px-2 py-0.5 rounded-full text-xs font-medium text-white" style="background-color:{{ $post->category->color }}">
                        {{ $post->category->displayName('blog') }}
                    </span>
                    @endif
                    <span class="text-xs text-gray-400">{{ $post->published_at?->format('d/m/Y') }}</span>
                </div>
                <p class="font-medium text-gray-900 dark:text-gray-100 mb-1 line-clamp-2">{{ $post->title }}</p>
                @if($post->summary)
                <p class="text-sm text-gray-500 dark:text-gray-400 line-clamp-2">{{ $post->summary }}</p>
                @endif
            </a>
            @endforeach
        </div>
        @endif
    </x-page-container>
</x-app-layout>
