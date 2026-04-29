<div class="flex flex-col h-full" wire:poll.3000ms>
    <!-- Status banner -->
    <div class="px-4 py-3 border-b border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-900">
        <div class="flex items-center justify-between flex-wrap gap-2">
            <div class="flex items-center gap-2">
                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium
                    {{ match($transaction->status) {
                        'pending' => 'bg-orange-100 text-orange-800 dark:bg-orange-900 dark:text-orange-200',
                        'accepted' => 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200',
                        'buyer_done' => 'bg-purple-100 text-purple-800 dark:bg-purple-900 dark:text-purple-200',
                        'completed' => 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200',
                        default => 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200',
                    } }}">
                    {{ $transaction->status_label }}
                </span>
                <span class="text-sm text-gray-600 dark:text-gray-400">
                    {{ $transaction->points_agreed ?? $transaction->points_proposed }} pts
                </span>
            </div>

            <!-- Action buttons -->
            <div class="flex flex-wrap gap-2">
                @php $user = auth()->user(); @endphp

                @if($transaction->status === 'pending' && $user->id === $transaction->seller_id)
                    <form method="POST" action="{{ route('transactions.approve', $transaction) }}">
                        @csrf @method('PATCH')
                        <button class="px-3 py-1 bg-green-600 text-white text-xs rounded hover:bg-green-700">Accepter</button>
                    </form>
                    <form method="POST" action="{{ route('transactions.refuse', $transaction) }}">
                        @csrf @method('PATCH')
                        <button class="px-3 py-1 bg-red-600 text-white text-xs rounded hover:bg-red-700">Refuser</button>
                    </form>
                @endif

                @if($transaction->status === 'pending' && in_array($user->id, [$transaction->buyer_id, $transaction->seller_id]))
                    <form method="POST" action="{{ route('transactions.adjust', $transaction) }}" class="flex gap-1" x-data>
                        @csrf @method('PATCH')
                        <input type="number" name="points_proposed" min="1" value="{{ $transaction->points_proposed }}"
                            class="w-20 px-2 py-1 border rounded text-xs dark:bg-gray-700 dark:border-gray-600 dark:text-white">
                        <button class="px-3 py-1 bg-yellow-500 text-white text-xs rounded hover:bg-yellow-600">Ajuster</button>
                    </form>
                @endif

                @if(in_array($transaction->status, ['pending', 'accepted']) && in_array($user->id, [$transaction->buyer_id, $transaction->seller_id]))
                    <form method="POST" action="{{ route('transactions.cancel', $transaction) }}">
                        @csrf @method('PATCH')
                        <button class="px-3 py-1 bg-gray-500 text-white text-xs rounded hover:bg-gray-600">Annuler</button>
                    </form>
                @endif

                @if($transaction->status === 'accepted' && $user->id === $transaction->buyer_id)
                    <form method="POST" action="{{ route('transactions.complete', $transaction) }}">
                        @csrf @method('PATCH')
                        <button class="px-3 py-1 bg-blue-600 text-white text-xs rounded hover:bg-blue-700">Déclarer terminé</button>
                    </form>
                @endif

                @if($transaction->status === 'buyer_done' && $user->id === $transaction->seller_id)
                    <form method="POST" action="{{ route('transactions.confirm', $transaction) }}">
                        @csrf @method('PATCH')
                        <button class="px-3 py-1 bg-green-600 text-white text-xs rounded hover:bg-green-700">Confirmer</button>
                    </form>
                    <form method="POST" action="{{ route('transactions.contest', $transaction) }}">
                        @csrf @method('PATCH')
                        <button class="px-3 py-1 bg-orange-500 text-white text-xs rounded hover:bg-orange-600">Contester</button>
                    </form>
                @endif
            </div>
        </div>
    </div>

    <!-- Messages -->
    <div class="flex-1 overflow-y-auto p-4 space-y-3" id="messages-container">
        @foreach($messages as $message)
            @if($message->isSystem())
                <div class="flex justify-center">
                    <span class="px-3 py-1 bg-gray-100 dark:bg-gray-700 text-gray-500 dark:text-gray-400 text-xs rounded-full">
                        {{ $message->body }}
                    </span>
                </div>
            @elseif($message->sender_id === auth()->id())
                <div class="flex justify-end">
                    <div class="max-w-xs lg:max-w-md">
                        <div class="bg-indigo-600 text-white rounded-2xl rounded-tr-sm px-4 py-2">
                            <p class="text-sm">{{ $message->body }}</p>
                        </div>
                        <p class="text-xs text-gray-400 text-right mt-1">{{ $message->created_at->format('H:i') }}</p>
                    </div>
                </div>
            @else
                <div class="flex justify-start gap-2">
                    <img src="{{ $message->sender?->avatar_url }}" class="w-7 h-7 rounded-full mt-1 flex-shrink-0" alt="">
                    <div class="max-w-xs lg:max-w-md">
                        <div class="bg-white dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-2xl rounded-tl-sm px-4 py-2">
                            <p class="text-sm text-gray-900 dark:text-gray-100">{{ $message->body }}</p>
                        </div>
                        <p class="text-xs text-gray-400 mt-1">{{ $message->created_at->format('H:i') }}</p>
                    </div>
                </div>
            @endif
        @endforeach
    </div>

    <!-- Review form — visible after completion si pas encore noté -->
    @if($transaction->status === 'completed' && !$transaction->hasReviewFrom(auth()->id()))
    <div class="border-t-2 border-indigo-200 dark:border-indigo-800 bg-indigo-50 dark:bg-indigo-900/20 p-4"
         x-data="{ rating: 0, hovered: 0 }">
        <p class="text-sm font-semibold text-indigo-700 dark:text-indigo-300 mb-3">
            ⭐ Évaluez cet échange
        </p>
        <form method="POST" action="{{ route('reviews.store', $transaction) }}">
            @csrf

            <!-- Étoiles interactives -->
            <div class="flex gap-1 mb-3">
                @for($i = 1; $i <= 5; $i++)
                <button type="button"
                    @click="rating = {{ $i }}"
                    @mouseenter="hovered = {{ $i }}"
                    @mouseleave="hovered = 0"
                    class="text-2xl transition-transform hover:scale-110 focus:outline-none">
                    <span x-text="(hovered || rating) >= {{ $i }} ? '★' : '☆'"
                          :class="(hovered || rating) >= {{ $i }} ? 'text-yellow-400' : 'text-gray-300 dark:text-gray-600'"></span>
                </button>
                @endfor
                <input type="hidden" name="rating" :value="rating">
            </div>

            <textarea name="comment" rows="2" placeholder="Commentaire (optionnel)..."
                class="w-full px-3 py-2 border border-indigo-200 dark:border-indigo-700 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-indigo-500 mb-3 resize-none"></textarea>

            <button type="submit"
                :disabled="rating === 0"
                :class="rating === 0 ? 'opacity-50 cursor-not-allowed' : 'hover:bg-indigo-700'"
                class="px-4 py-2 bg-indigo-600 text-white text-sm rounded-lg font-medium transition">
                Envoyer l'évaluation
            </button>
        </form>
    </div>
    @endif

    <!-- Input -->
    @if(!in_array($transaction->status, ['completed', 'refused', 'cancelled']))
    <div class="border-t border-gray-200 dark:border-gray-700 p-4">
        <form wire:submit="sendMessage" class="flex gap-2">
            <input wire:model="newMessage"
                type="text"
                placeholder="Votre message..."
                class="flex-1 px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-indigo-500 focus:border-transparent text-sm" />
            <button type="submit" class="px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 text-sm font-medium">
                Envoyer
            </button>
        </form>
        @error('newMessage') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
    </div>
    @else
    <div class="border-t border-gray-200 dark:border-gray-700 p-4 text-center text-sm text-gray-500 dark:text-gray-400">
        Cette conversation est terminée.
    </div>
    @endif
</div>

<script>
    document.addEventListener('livewire:updated', () => {
        const container = document.getElementById('messages-container');
        if (container) container.scrollTop = container.scrollHeight;
    });
    window.addEventListener('load', () => {
        const container = document.getElementById('messages-container');
        if (container) container.scrollTop = container.scrollHeight;
    });
</script>
