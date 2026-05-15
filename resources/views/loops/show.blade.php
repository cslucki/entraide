@php $currentLoop = $loop; @endphp

<x-app-layout>
    <div class="max-w-5xl mx-auto px-4 py-6 md:py-8">

        {{-- Header --}}
        <div class="mb-6">
            <a href="{{ route('loops.index') }}" class="text-sm text-indigo-600 dark:text-indigo-400 hover:underline">&larr; Mes boucles</a>
            <h1 class="text-xl md:text-2xl font-bold text-gray-900 dark:text-gray-100 mt-2">{{ $currentLoop->name }}</h1>
            @if($currentLoop->description)
                <p class="text-gray-500 dark:text-gray-400 text-sm mt-1">{{ $currentLoop->description }}</p>
            @endif
            <div class="flex flex-wrap items-center gap-1.5 mt-2 text-xs text-gray-400">
                <span class="whitespace-nowrap">{{ $currentLoop->members->count() }} membre(s)</span>
                <span class="text-gray-300 dark:text-gray-600">·</span>
                <span class="whitespace-nowrap">{{ $currentLoop->type === 'system' ? 'Système' : 'Personnalisée' }}</span>
                <span class="text-gray-300 dark:text-gray-600">·</span>
                <span class="whitespace-nowrap">{{ $currentLoop->status === 'active' ? 'Active' : 'Archivée' }}</span>
            </div>
        </div>

        {{-- Session messages --}}
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

        {{-- Main layout: chat column + sidebar --}}
        <div class="grid md:grid-cols-3 gap-6">

            {{-- Chat column --}}
            <div class="md:col-span-2 flex flex-col">

                {{-- Messages --}}
                <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden flex flex-col">
                    <div class="px-4 md:px-5 py-4 border-b border-gray-100 dark:border-gray-700">
                        <h2 class="font-semibold text-gray-900 dark:text-gray-100 text-sm">Discussion</h2>
                    </div>

                    <div class="flex-1 px-4 md:px-5 py-4 space-y-4 max-h-[60vh] md:max-h-[70vh] overflow-y-auto">
                        @forelse($messages as $msg)
                            @php $isOwn = $msg->sender_id === auth()->id(); @endphp
                            <div class="flex gap-3 {{ $isOwn ? 'flex-row-reverse' : '' }}">
                                @if($msg->sender)
                                    <img src="{{ $msg->sender->avatar_url }}" alt=""
                                         class="w-7 h-7 rounded-full flex-shrink-0 mt-0.5">
                                @else
                                    <div class="w-7 h-7 rounded-full flex-shrink-0 mt-0.5 bg-gray-200 dark:bg-gray-600 flex items-center justify-center">
                                        <svg class="w-3.5 h-3.5 text-gray-400" fill="currentColor" viewBox="0 0 20 20">
                                            <path fill-rule="evenodd" d="M10 9a3 3 0 100-6 3 3 0 000 6zm-7 9a7 7 0 1114 0H3z" clip-rule="evenodd"/>
                                        </svg>
                                    </div>
                                @endif
                                <div class="max-w-[85%] md:max-w-[75%] min-w-0">
                                    <div class="flex items-baseline gap-2 mb-0.5 {{ $isOwn ? 'justify-end' : '' }}">
                                        <span class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ $isOwn ? 'Moi' : ($msg->sender?->name ?? 'BouclePro') }}</span>
                                        <span class="text-[10px] text-gray-400 dark:text-gray-500">{{ $msg->created_at->diffForHumans() }}</span>
                                    </div>
                                    <div class="rounded-2xl px-3.5 py-2.5 text-sm leading-relaxed {{ $isOwn ? 'bg-indigo-600 text-white rounded-br-sm' : 'bg-gray-100 dark:bg-gray-700 text-gray-900 dark:text-gray-100 rounded-bl-sm' }}">
                                        {{ $msg->body }}
                                    </div>
                                </div>
                            </div>
                        @empty
                            <div class="flex flex-col items-center justify-center py-12 text-center">
                                <svg class="w-10 h-10 text-gray-300 dark:text-gray-600 mb-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/>
                                </svg>
                                <p class="text-sm text-gray-400 dark:text-gray-500">Aucun message pour le moment.</p>
                                <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">Écrivez le premier message de cette boucle.</p>
                            </div>
                        @endforelse
                    </div>

                    {{-- Message form --}}
                    <div class="border-t border-gray-100 dark:border-gray-700 px-4 md:px-5 py-4">
                        <form method="POST" action="{{ route('loops.messages.store', $currentLoop) }}" class="flex gap-3 items-end">
                            @csrf
                            <div class="flex-1 min-w-0">
                                <label for="body" class="sr-only">Votre message</label>
                                <textarea name="body" id="body" rows="2"
                                    placeholder="Écrivez un message..."
                                    class="w-full resize-none px-3.5 py-2.5 text-sm border border-gray-300 dark:border-gray-600 rounded-xl bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 placeholder-gray-400 dark:placeholder-gray-500 focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
                                    required></textarea>
                                @error('body')
                                    <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                                @enderror
                            </div>
                            <button type="submit"
                                class="flex-shrink-0 px-4 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold rounded-xl transition flex items-center gap-1.5">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/>
                                </svg>
                                <span class="hidden sm:inline">Envoyer</span>
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            {{-- Sidebar: members + referrals --}}
            <div class="space-y-6">
                {{-- Members card --}}
                <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
                    <div class="px-4 md:px-5 py-4 border-b border-gray-100 dark:border-gray-700">
                        <h2 class="font-semibold text-gray-900 dark:text-gray-100 text-sm">Membres</h2>
                    </div>
                    <div class="divide-y divide-gray-100 dark:divide-gray-700">
                        @forelse($currentLoop->members as $member)
                            <div class="px-4 md:px-5 py-3 flex items-center gap-3">
                                <img src="{{ $member->user->avatar_url }}" class="w-7 h-7 rounded-full flex-shrink-0" alt="">
                                <div class="min-w-0 flex-1">
                                    <p class="text-sm font-medium text-gray-900 dark:text-gray-100 truncate">{{ $member->user->name }}</p>
                                    <p class="text-xs text-gray-400">
                                        {{ match($member->role) { 'owner' => 'Créateur', 'moderator' => 'Modérateur', default => 'Membre' } }}
                                        @if($member->joined_at)
                                            · {{ $member->joined_at->diffForHumans() }}
                                        @endif
                                    </p>
                                </div>
                                <span class="text-xs px-2 py-0.5 rounded-full whitespace-nowrap
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
                            <p class="px-4 py-8 text-sm text-gray-400 text-center">Aucun membre.</p>
                        @endforelse
                    </div>
                </div>

                {{-- Eligible referrals --}}
                @if($eligibleReferrals->isNotEmpty())
                    <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
                        <div class="px-4 md:px-5 py-4 border-b border-gray-100 dark:border-gray-700">
                            <h2 class="font-semibold text-gray-900 dark:text-gray-100 text-sm">Mes invités à ajouter</h2>
                            <p class="text-xs text-gray-400 mt-0.5">Personnes que vous avez invitées et qui peuvent rejoindre cette boucle</p>
                        </div>
                        <div class="divide-y divide-gray-100 dark:divide-gray-700">
                            @foreach($eligibleReferrals as $referral)
                                <div class="px-4 md:px-5 py-3 flex items-center gap-3">
                                    <img src="{{ $referral->referred->avatar_url }}" class="w-7 h-7 rounded-full flex-shrink-0" alt="">
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
    </div>
</x-app-layout>
