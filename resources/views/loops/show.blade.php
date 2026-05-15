@php $currentLoop = $loop; @endphp

<x-app-layout>
    <div class="max-w-4xl mx-auto px-4 py-8">
        <div class="mb-8">
            <a href="{{ route('loops.index') }}" class="text-sm text-indigo-600 hover:underline">&larr; Mes boucles</a>
            <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100 mt-2">{{ $currentLoop->name }}</h1>
            @if($currentLoop->description)
                <p class="text-gray-500 dark:text-gray-400 mt-1">{{ $currentLoop->description }}</p>
            @endif
            <div class="flex items-center gap-3 mt-2 text-xs text-gray-400">
                <span>{{ $currentLoop->members->count() }} membre(s)</span>
                <span>{{ $currentLoop->type === 'system' ? 'Système' : 'Personnalisée' }}</span>
                <span>{{ $currentLoop->status === 'active' ? 'Active' : 'Archivée' }}</span>
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
            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
                <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700">
                    <h2 class="font-semibold text-gray-900 dark:text-gray-100">Membres</h2>
                </div>
                <div class="divide-y divide-gray-100 dark:divide-gray-700">
                    @forelse($currentLoop->members as $member)
                        <div class="px-5 py-3 flex items-center gap-3">
                            <img src="{{ $member->user->avatar_url }}" class="w-8 h-8 rounded-full flex-shrink-0" alt="">
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-medium text-gray-900 dark:text-gray-100 truncate">{{ $member->user->name }}</p>
                                <p class="text-xs text-gray-400">
                                    {{ match($member->role) { 'owner' => 'Créateur', 'moderator' => 'Modérateur', default => 'Membre' } }}
                                    @if($member->joined_at)
                                        · {{ $member->joined_at->diffForHumans() }}
                                    @endif
                                </p>
                            </div>
                            <span class="text-xs px-2 py-0.5 rounded-full
                                {{ match($member->status) {
                                    'active' => 'bg-green-100 dark:bg-green-900 text-green-700 dark:text-green-300',
                                    'invited' => 'bg-orange-100 dark:bg-orange-900 text-orange-700 dark:text-orange-300',
                                    'left' => 'bg-gray-100 dark:bg-gray-700 text-gray-500',
                                    default => 'bg-gray-100 text-gray-600',
                                } }}">
                                {{ match($member->status) { 'active' => 'Actif', 'invited' => 'Invité', 'left' => 'Parti', default => $member->status } }}
                            </span>
                        </div>
                    @empty
                        <p class="px-5 py-8 text-sm text-gray-400 text-center">Aucun membre.</p>
                    @endforelse
                </div>
            </div>

            @if($eligibleReferrals->isNotEmpty())
                <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
                    <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700">
                        <h2 class="font-semibold text-gray-900 dark:text-gray-100">Mes invités à ajouter</h2>
                        <p class="text-xs text-gray-400 mt-0.5">Personnes que vous avez invitées et qui peuvent rejoindre cette boucle</p>
                    </div>
                    <div class="divide-y divide-gray-100 dark:divide-gray-700">
                        @foreach($eligibleReferrals as $referral)
                            <div class="px-5 py-3 flex items-center gap-3">
                                <img src="{{ $referral->referred->avatar_url }}" class="w-8 h-8 rounded-full flex-shrink-0" alt="">
                                <div class="min-w-0 flex-1">
                                    <p class="text-sm font-medium text-gray-900 dark:text-gray-100 truncate">{{ $referral->referred->name }}</p>
                                    <p class="text-xs text-gray-400">{{ $referral->status === 'activated' ? 'Activé' : 'En attente' }}</p>
                                </div>
                                <form method="POST" action="{{ route('loops.members.add', $currentLoop) }}">
                                    @csrf
                                    <input type="hidden" name="referral_id" value="{{ $referral->id }}">
                                    <button type="submit"
                                            class="px-3 py-1.5 text-xs font-medium text-white bg-indigo-600 hover:bg-indigo-700 rounded-lg transition whitespace-nowrap">
                                        Ajouter
                                    </button>
                                </form>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
