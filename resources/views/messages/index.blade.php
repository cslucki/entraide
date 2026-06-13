<x-app-layout>
    <div class="max-w-7xl mx-auto px-4 py-6" style="height: calc(100vh - 64px)">
        <div class="flex h-full border border-gray-200 dark:border-gray-700 rounded-xl overflow-hidden bg-white dark:bg-gray-800">
            <!-- Conversation list -->
            <div class="w-80 flex-shrink-0 border-r border-gray-200 dark:border-gray-700 overflow-y-auto">
                <div class="p-4 border-b border-gray-200 dark:border-gray-700">
                    <h2 class="font-semibold text-gray-900 dark:text-gray-100">Messages</h2>
                </div>
                @forelse($transactions as $conv)
                    @php
                        $other = auth()->id() === $conv->buyer_id ? $conv->seller : $conv->buyer;
                        $lastMsg = $conv->messages->first();
                        $isActive = isset($transaction) && $transaction->id === $conv->id;
                        $isDirectConversation = $conv->isDirectConversation();
                        $unread = $unreadCounts[$conv->id] ?? 0;
                    @endphp
                    <a href="{{ route('messages.show', $conv) }}"
                        class="flex items-start gap-3 p-4 hover:bg-gray-50 dark:hover:bg-gray-700 border-b border-gray-100 dark:border-gray-700 transition {{ $isActive ? 'bg-indigo-50 dark:bg-indigo-900/30' : '' }}">
                        <div class="relative flex-shrink-0">
                            <img src="{{ $other->avatar_url }}" class="w-10 h-10 rounded-full" alt="">
                            @if($unread > 0 && !$isActive)
                            <span class="absolute -top-1 -right-1 min-w-[18px] h-[18px] px-1 bg-red-500 text-white text-[10px] font-bold rounded-full flex items-center justify-center">
                                {{ $unread > 9 ? '9+' : $unread }}
                            </span>
                            @endif
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center justify-between gap-1">
                                <p class="font-medium text-sm text-gray-900 dark:text-gray-100 truncate {{ $unread > 0 && !$isActive ? 'font-bold' : '' }}">{{ $other->name }}</p>
                                @unless($isDirectConversation)
                                    <span class="text-xs px-1.5 py-0.5 rounded-full flex-shrink-0
                                        {{ match($conv->status) {
                                            'pending' => 'bg-orange-100 text-orange-700',
                                            'accepted' => 'bg-blue-100 text-blue-700',
                                            'buyer_done' => 'bg-purple-100 text-purple-700',
                                            'completed' => 'bg-green-100 text-green-700',
                                            default => 'bg-gray-100 text-gray-600',
                                        } }}">
                                        {{ $conv->status_label }}
                                    </span>
                                @endunless
                            </div>
                            <p class="text-xs text-gray-500 dark:text-gray-400 truncate">{{ $conv->subject }}</p>
                            @if($lastMsg)
                            <p class="text-xs {{ $unread > 0 && !$isActive ? 'text-gray-700 dark:text-gray-300 font-medium' : 'text-gray-400' }} truncate mt-0.5">
                                {{ Str::limit($lastMsg->body, 40) }}
                            </p>
                            @endif
                        </div>
                    </a>
                @empty
                    <div class="p-8 text-center text-gray-400 text-sm">Aucune conversation</div>
                @endforelse
            </div>

            <!-- Message thread -->
            <div class="flex-1 flex flex-col min-h-0">
                @isset($transaction)
                    @php $other = auth()->id() === $transaction->buyer_id ? $transaction->seller : $transaction->buyer; @endphp
                    <!-- Thread header -->
                    <div class="px-4 py-3 border-b border-gray-200 dark:border-gray-700 flex items-center gap-3">
                        <img src="{{ $other->avatar_url }}" class="w-8 h-8 rounded-full" alt="">
                        <div>
                            <p class="font-medium text-gray-900 dark:text-gray-100">{{ $other->name }}</p>
                            <p class="text-xs text-gray-500">
                                @if($transaction->service)
                                <a href="{{ route('services.show', $transaction->service) }}" class="hover:underline text-indigo-500">{{ $transaction->service->title }}</a>
                                @elseif($transaction->serviceRequest)
                                <a href="{{ route('requests.show', $transaction->serviceRequest) }}" class="hover:underline text-indigo-500">{{ $transaction->serviceRequest->title }}</a>
                                @endif
                            </p>
                        </div>
                    </div>
                    <!-- Livewire thread -->
                    <div class="flex-1 min-h-0 flex flex-col">
                        @livewire('message-thread', ['transaction' => $transaction])
                    </div>
                @else
                    <div class="flex-1 flex items-center justify-center text-gray-400 dark:text-gray-500">
                        <div class="text-center">
                            <svg class="w-12 h-12 mx-auto mb-3 opacity-30" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
                            </svg>
                            <p>Sélectionnez une conversation</p>
                        </div>
                    </div>
                @endisset
            </div>
        </div>
    </div>
</x-app-layout>
