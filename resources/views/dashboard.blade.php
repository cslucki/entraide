<x-app-layout>
    <div class="max-w-7xl mx-auto px-4 py-8">
        <!-- Header -->
        <div class="flex items-center justify-between mb-8">
            <div>
                @php $tenant = $currentCommunity ?? $currentOrganization ?? null; @endphp
                <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">
                    @isset($tenant)Tableau de bord — {{ $tenant->name }}@elseBonjour, {{ $user->name }}@endisset
                    @empty($tenant) 👋@endempty
                </h1>
                <p class="text-gray-500 dark:text-gray-400 text-sm mt-1">Voici un résumé de votre activité</p>
            </div>
            <form method="POST" action="{{ route('profile.availability') }}">
                @csrf @method('PATCH')
                <button type="submit" class="flex items-center gap-2 px-4 py-2 rounded-lg border {{ $user->is_available ? 'border-green-400 bg-green-50 dark:bg-green-900/20 text-green-700 dark:text-green-300' : 'border-gray-300 dark:border-gray-600 text-gray-500 dark:text-gray-400' }} text-sm font-medium hover:shadow-sm transition">
                    <span class="w-2 h-2 rounded-full {{ $user->is_available ? 'bg-green-500' : 'bg-gray-400' }}"></span>
                    {{ $user->is_available ? 'Disponible' : 'Indisponible' }}
                </button>
            </form>
        </div>

        <!-- Metrics -->
        <div class="grid grid-cols-2 md:grid-cols-5 gap-4 mb-8">
            <div class="bg-white dark:bg-gray-800 rounded-xl p-4 border border-gray-200 dark:border-gray-700 text-center">
                <p class="text-2xl font-bold text-indigo-600 dark:text-indigo-400">{{ $user->points_balance }}</p>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Solde (pts)</p>
            </div>
            <div class="bg-white dark:bg-gray-800 rounded-xl p-4 border border-gray-200 dark:border-gray-700 text-center">
                <p class="text-2xl font-bold text-green-600 dark:text-green-400">{{ $earned }}</p>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Points gagnés</p>
            </div>
            <div class="bg-white dark:bg-gray-800 rounded-xl p-4 border border-gray-200 dark:border-gray-700 text-center">
                <p class="text-2xl font-bold text-red-500 dark:text-red-400">{{ $spent }}</p>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Points dépensés</p>
            </div>
            <div class="bg-white dark:bg-gray-800 rounded-xl p-4 border border-gray-200 dark:border-gray-700 text-center">
                <p class="text-2xl font-bold text-blue-600 dark:text-blue-400">{{ $completedCount }}</p>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Échanges complétés</p>
            </div>
            <div class="bg-white dark:bg-gray-800 rounded-xl p-4 border border-gray-200 dark:border-gray-700 text-center">
                <p class="text-2xl font-bold text-yellow-500 dark:text-yellow-400">{{ $user->rating ? number_format($user->rating, 1).'/5' : '—' }}</p>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Note moyenne</p>
            </div>
        </div>

        @if(session('success'))
        <div x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 3000)"
            class="mb-6 bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 text-green-700 dark:text-green-300 px-4 py-3 rounded-lg text-sm">
            {{ session('success') }}
        </div>
        @endif
        @if(session('error'))
        <div x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 4000)"
            class="mb-6 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 text-red-700 dark:text-red-300 px-4 py-3 rounded-lg text-sm">
            {{ session('error') }}
        </div>
        @endif

        <div class="grid md:grid-cols-2 gap-6">
            <!-- Mes micro-services -->
            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
                <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700 flex items-center justify-between">
                    <h2 class="font-semibold text-gray-900 dark:text-gray-100">Mes {{ $T['services'] }}</h2>
                    <a href="{{ route('services.create') }}" class="text-xs text-indigo-600 hover:underline">+ Nouveau</a>
                </div>
                <div class="divide-y divide-gray-100 dark:divide-gray-700">
                    @forelse($myServices as $service)
                    <div class="px-5 py-3 flex items-center justify-between">
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-gray-900 dark:text-gray-100 truncate">{{ $service->title }}</p>
                            <p class="text-xs text-gray-500">{{ $service->points_cost }} pts · {{ $service->category->name }}</p>
                        </div>
                        <div class="flex gap-3 ml-3 flex-shrink-0">
                            <a href="{{ route('services.edit', $service) }}" class="text-xs text-gray-500 hover:text-indigo-600">Modifier</a>
                            <form method="POST" action="{{ route('services.destroy', $service) }}" x-data="{ asked: false }">
                                @csrf @method('DELETE')
                                <template x-if="!asked">
                                    <button type="button" @click="asked = true" class="text-xs text-red-500 hover:text-red-700">Supprimer</button>
                                </template>
                                <template x-if="asked">
                                    <span class="flex gap-1 items-center text-xs">
                                        <span class="text-gray-500">Sûr ?</span>
                                        <button type="submit" class="text-red-600 font-semibold hover:underline">Oui</button>
                                        <button type="button" @click="asked = false" class="text-gray-400 hover:underline">Non</button>
                                    </span>
                                </template>
                            </form>
                        </div>
                    </div>
                    @empty
                    <p class="px-5 py-8 text-sm text-gray-400 text-center">Aucun {{ $T['service'] }} actif.<br><a href="{{ route('services.create') }}" class="text-indigo-600 hover:underline">Créer un {{ $T['service'] }}</a></p>
                    @endforelse
                </div>
            </div>

            <!-- Mes demandes -->
            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
                <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700 flex items-center justify-between">
                    <h2 class="font-semibold text-gray-900 dark:text-gray-100">Mes demandes d'aide</h2>
                    <a href="{{ route('requests.create') }}" class="text-xs text-indigo-600 hover:underline">+ Nouvelle</a>
                </div>
                <div class="divide-y divide-gray-100 dark:divide-gray-700">
                    @forelse($myRequests as $req)
                    <div class="px-5 py-3 flex items-center justify-between">
                        <div class="min-w-0">
                            <a href="{{ route('requests.show', $req) }}" class="text-sm font-medium text-gray-900 dark:text-gray-100 truncate hover:text-indigo-600">{{ $req->title }}</a>
                            <p class="text-xs text-gray-500">{{ $req->budget_min }}{{ $req->budget_max ? '–'.$req->budget_max : '+' }} pts</p>
                        </div>
                        <form method="POST" action="{{ route('requests.destroy', $req) }}" class="ml-3" x-data="{ asked: false }">
                            @csrf @method('DELETE')
                            <template x-if="!asked">
                                <button type="button" @click="asked = true" class="text-xs text-red-500 hover:text-red-700">Fermer</button>
                            </template>
                            <template x-if="asked">
                                <span class="flex gap-1 items-center text-xs">
                                    <span class="text-gray-500">Sûr ?</span>
                                    <button type="submit" class="text-red-600 font-semibold hover:underline">Oui</button>
                                    <button type="button" @click="asked = false" class="text-gray-400 hover:underline">Non</button>
                                </span>
                            </template>
                        </form>
                    </div>
                    @empty
                    <p class="px-5 py-8 text-sm text-gray-400 text-center">Aucune {{ $T['request'] }} ouverte.<br><a href="{{ route('requests.create') }}" class="text-indigo-600 hover:underline">Faire une {{ $T['request'] }}</a></p>
                    @endforelse
                </div>
            </div>

            <!-- Mes échanges (buyer) -->
            @if($myProposals->isNotEmpty())
            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
                <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700">
                    <h2 class="font-semibold text-gray-900 dark:text-gray-100">Mes échanges en cours</h2>
                </div>
                <div class="divide-y divide-gray-100 dark:divide-gray-700">
                    @foreach($myProposals as $tx)
                    <a href="{{ route('messages.show', $tx) }}" class="px-5 py-3 flex items-center gap-3 hover:bg-gray-50 dark:hover:bg-gray-700 transition">
                        <img src="{{ $tx->seller->avatar_url }}" class="w-8 h-8 rounded-full flex-shrink-0" alt="">
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-medium text-gray-900 dark:text-gray-100 truncate">{{ $tx->subject }}</p>
                            <p class="text-xs text-gray-500">à {{ $tx->seller->name }} · {{ $tx->points_proposed }} pts</p>
                        </div>
                        <span class="text-xs px-2 py-0.5 rounded-full flex-shrink-0
                            {{ match($tx->status) {
                                'pending'    => 'bg-orange-100 dark:bg-orange-900 text-orange-700 dark:text-orange-300',
                                'accepted'   => 'bg-blue-100 dark:bg-blue-900 text-blue-700 dark:text-blue-300',
                                'buyer_done' => 'bg-purple-100 dark:bg-purple-900 text-purple-700 dark:text-purple-300',
                                default      => 'bg-gray-100 text-gray-600',
                            } }}">{{ $tx->status_label }}</span>
                    </a>
                    @endforeach
                </div>
            </div>
            @endif

            <!-- Échanges en cours -->
            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
                <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700">
                    <h2 class="font-semibold text-gray-900 dark:text-gray-100">Échanges en cours</h2>
                </div>
                <div class="divide-y divide-gray-100 dark:divide-gray-700">
                    @forelse($activeExchanges as $tx)
                    @php $other = auth()->id() === $tx->buyer_id ? $tx->seller : $tx->buyer; @endphp
                    <a href="{{ route('messages.show', $tx) }}" class="px-5 py-3 flex items-center gap-3 hover:bg-gray-50 dark:hover:bg-gray-700 transition">
                        <img src="{{ $other->avatar_url }}" class="w-8 h-8 rounded-full flex-shrink-0" alt="">
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-medium text-gray-900 dark:text-gray-100 truncate">{{ $tx->subject }}</p>
                            <p class="text-xs text-gray-500">avec {{ $other->name }} · {{ $tx->points_agreed }} pts</p>
                        </div>
                        <span class="text-xs px-2 py-0.5 rounded-full bg-blue-100 dark:bg-blue-900 text-blue-700 dark:text-blue-300 flex-shrink-0">{{ $tx->status_label }}</span>
                    </a>
                    @empty
                    <p class="px-5 py-8 text-sm text-gray-400 text-center">Aucun échange en cours.</p>
                    @endforelse
                </div>
            </div>

            <!-- Messages récents -->
            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
                <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700 flex items-center justify-between">
                    <h2 class="font-semibold text-gray-900 dark:text-gray-100">Messages récents</h2>
                    <a href="{{ route('messages.index') }}" class="text-xs text-indigo-600 hover:underline">Voir tout</a>
                </div>
                <div class="divide-y divide-gray-100 dark:divide-gray-700">
                    @forelse($recentMessages as $tx)
                    @php $other = auth()->id() === $tx->buyer_id ? $tx->seller : $tx->buyer; $lastMsg = $tx->messages->first(); @endphp
                    <a href="{{ route('messages.show', $tx) }}" class="px-5 py-3 flex items-center gap-3 hover:bg-gray-50 dark:hover:bg-gray-700 transition">
                        <img src="{{ $other->avatar_url }}" class="w-8 h-8 rounded-full flex-shrink-0" alt="">
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ $other->name }}</p>
                            @if($lastMsg)
                            <p class="text-xs text-gray-400 truncate">{{ Str::limit($lastMsg->body, 45) }}</p>
                            @endif
                        </div>
                    </a>
                    @empty
                    <p class="px-5 py-8 text-sm text-gray-400 text-center">Aucun message récent.</p>
                    @endforelse
                </div>
            </div>
        </div>

        <!-- Raccourcis secondaires -->
        <div class="mt-6 flex flex-wrap gap-3">
            <a href="{{ route('points.index') }}" class="px-4 py-2 text-sm border border-gray-200 dark:border-gray-700 rounded-lg text-gray-600 dark:text-gray-400 hover:bg-white dark:hover:bg-gray-800 transition">
                Historique des points
            </a>
            <a href="{{ route('favorites.index') }}" class="px-4 py-2 text-sm border border-gray-200 dark:border-gray-700 rounded-lg text-gray-600 dark:text-gray-400 hover:bg-white dark:hover:bg-gray-800 transition">
                Mes favoris
            </a>
            <a href="{{ route('profile.show', $user) }}" class="px-4 py-2 text-sm border border-gray-200 dark:border-gray-700 rounded-lg text-gray-600 dark:text-gray-400 hover:bg-white dark:hover:bg-gray-800 transition">
                Mon profil public
            </a>
            @if($user->is_admin)
            <a href="{{ route('admin.dashboard') }}" class="px-4 py-2 text-sm border border-purple-300 dark:border-purple-700 rounded-lg text-purple-600 dark:text-purple-400 hover:bg-purple-50 dark:hover:bg-purple-900/20 transition font-medium">
                Tableau de bord admin
            </a>
            @endif
        </div>
    </div>
</x-app-layout>
