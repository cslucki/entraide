<x-app-layout>
    <div class="max-w-7xl mx-auto px-4 py-8">
        <!-- Header -->
        <div class="flex items-center justify-between mb-8">
            <div>
                @php $tenant = $currentOrganization ?? null; @endphp
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

        <!-- Onboarding progressif beta -->
        <div class="mb-8 bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 p-5 sm:p-6">
            <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between sm:gap-6 mb-5">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wide text-indigo-600 dark:text-indigo-400">Onboarding beta</p>
                    <h2 class="mt-1 text-lg font-semibold text-gray-900 dark:text-gray-100">Votre progression dans la boucle</h2>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Avancez étape par étape pour demander de l’aide, proposer votre aide et commencer à interagir.</p>
                </div>
            </div>

            <div class="grid grid-cols-1 gap-3 lg:grid-cols-2">
                @foreach($onboardingSteps as $step)
                <div class="rounded-xl border {{ $step['status'] === 'done' ? 'border-green-200 bg-green-50/70 dark:border-green-900 dark:bg-green-950/20' : 'border-gray-200 bg-gray-50 dark:border-gray-700 dark:bg-gray-900/40' }} p-4">
                    <div class="flex items-start gap-3">
                        <div class="mt-0.5 flex h-7 w-7 shrink-0 items-center justify-center rounded-full {{ $step['status'] === 'done' ? 'bg-green-600 text-white' : 'bg-white text-gray-400 ring-1 ring-gray-200 dark:bg-gray-800 dark:ring-gray-700' }}">
                            @if($step['status'] === 'done')
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.4" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
                            @else
                            <span class="h-2 w-2 rounded-full bg-current"></span>
                            @endif
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                                <div>
                                    <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ $step['title'] }}</h3>
                                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $step['description'] }}</p>
                                </div>
                                <span class="inline-flex w-fit shrink-0 rounded-full px-2.5 py-1 text-xs font-medium {{ $step['status'] === 'done' ? 'bg-green-100 text-green-700 dark:bg-green-900/50 dark:text-green-300' : 'bg-white text-gray-600 ring-1 ring-gray-200 dark:bg-gray-800 dark:text-gray-300 dark:ring-gray-700' }}">
                                    {{ $step['status_label'] }}
                                </span>
                            </div>
                            <a href="{{ $step['cta_url'] }}" class="mt-3 inline-flex items-center text-sm font-medium text-indigo-600 hover:text-indigo-700 hover:underline dark:text-indigo-400 dark:hover:text-indigo-300">
                                {{ $step['cta_label'] }}
                            </a>
                        </div>
                    </div>
                </div>
                @endforeach
            </div>
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

        @if($referralLink)
        <div id="invitations" class="mb-6 bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-5">
            <h2 class="font-semibold text-gray-900 dark:text-gray-100 mb-2">Mes invitations</h2>
            <p class="text-sm text-gray-500 dark:text-gray-400 mb-3">
                Partagez ce lien avec une personne que vous souhaitez faire entrer dans la boucle.
            </p>
            <div class="flex gap-2 mb-4" x-data="{ copied: false, link: @js($referralLink) }">
                <input type="text" readonly value="{{ $referralLink }}" data-referral-link
                       class="flex-1 px-3 py-2 text-sm border border-gray-300 dark:border-gray-600 rounded-lg bg-gray-50 dark:bg-gray-700 text-gray-700 dark:text-gray-300 select-all">
                <button type="button" @click="
                    const input = $root.querySelector('[data-referral-link]');
                    if (navigator.clipboard && window.isSecureContext) {
                        navigator.clipboard.writeText(link);
                    } else if (input) {
                        input.select();
                        document.execCommand('copy');
                    }
                    copied = true;
                    setTimeout(() => copied = false, 2000);
                " class="px-4 py-2 text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700 rounded-lg transition whitespace-nowrap">
                    <span x-show="!copied">Copier</span>
                    <span x-show="copied">Copié !</span>
                </button>
            </div>
            <div class="flex items-center justify-between">
                <div class="flex gap-6 text-sm text-gray-600 dark:text-gray-400">
                    <div>
                        <span class="font-semibold text-gray-900 dark:text-gray-100">{{ $sentReferralsCount }}</span>
                        <span class="ml-1">invitation(s)</span>
                    </div>
                    <div>
                        <span class="font-semibold text-gray-900 dark:text-gray-100">{{ $activatedReferralsCount }}</span>
                        <span class="ml-1">activation(s)</span>
                    </div>
                    <div>
                        <span class="font-semibold text-gray-900 dark:text-gray-100">{{ $referralPointsEarned }}</span>
                        <span class="ml-1">pts reçus</span>
                    </div>
                </div>
                <a href="{{ route('points.index') }}#invitations" class="text-xs text-indigo-600 hover:underline">Voir l'historique</a>
            </div>
        </div>
        @endif

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

        @if(! $aiProfile || $aiProfile->status !== 'published')
        <div class="mb-6 bg-gradient-to-br from-indigo-50 to-white dark:from-indigo-950/30 dark:to-gray-800 rounded-2xl border border-indigo-200 dark:border-indigo-800 p-6">
            <div class="flex items-start gap-4 sm:items-center flex-col sm:flex-row">
                <div class="w-12 h-12 bg-indigo-100 dark:bg-indigo-900/50 rounded-xl flex items-center justify-center shrink-0">
                    <svg class="w-6 h-6 text-indigo-600 dark:text-indigo-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M9.75 3.104v5.714a2.25 2.25 0 01-.659 1.591L5 14.5M9.75 3.104c-.251.023-.501.05-.75.082m.75-.082a24.301 24.301 0 014.5 0m0 0v5.714c0 .597.237 1.17.659 1.591L19.8 15.3M14.25 3.104c.251.023.501.05.75.082M19.8 15.3l-1.57.393A9.065 9.065 0 0112 15a9.065 9.065 0 00-6.23.693L5 14.5m14.8.8l1.402 1.402c1.232 1.232.65 3.318-1.067 3.611A48.309 48.309 0 0112 21c-2.773 0-5.491-.235-8.135-.687-1.718-.293-2.3-2.379-1.067-3.61L5 14.5"/></svg>
                </div>
                <div class="flex-1">
                    <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">
                        @if($aiProfile && $aiProfile->status === 'draft')
                            Reprenez votre profil IA
                        @else
                            Créez votre profil IA
                        @endif
                    </h2>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                        @if($aiProfile && $aiProfile->status === 'draft')
                            Vous avez commencé à renseigner votre profil. Finalisez-le pour être mieux orienté.
                        @else
                            Configurez votre profil pour être mieux orienté par l'IA et apparaître dans les recherches.
                        @endif
                    </p>
                </div>
                <a href="{{ route('agent-ia.wizard') }}"
                   class="inline-flex items-center px-5 py-2.5 bg-indigo-600 text-white text-sm font-semibold rounded-xl hover:bg-indigo-700 transition active:scale-95 shadow-sm whitespace-nowrap">
                    {{ $aiProfile && $aiProfile->status === 'draft' ? 'Continuer' : 'Configurer' }}
                </a>
            </div>
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
                            <p class="text-xs text-gray-500">{{ $service->points_cost }} pts · {{ $service->category->displayName('transactions') }}</p>
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
            @if($referralLink)
            <a href="{{ route('points.index') }}#invitations" class="px-4 py-2 text-sm border border-gray-200 dark:border-gray-700 rounded-lg text-gray-600 dark:text-gray-400 hover:bg-white dark:hover:bg-gray-800 transition">
                Points d'invitation
            </a>
            @endif
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
