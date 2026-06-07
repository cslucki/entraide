<x-page title="Mes boucles" heading="Mes boucles">
    <x-slot name="headingActions">
        @if($canCreate)
            <a href="{{ route('loops.create') }}"
               class="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700 rounded-lg transition whitespace-nowrap">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                </svg>
                <span>Nouvelle</span>
            </a>
        @endif
    </x-slot>

    <p class="text-gray-500 dark:text-gray-400 text-sm mb-6">Vos espaces de collaboration</p>

        @if(session('success'))
            <div x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 3000)"
                 class="mb-6 bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 text-green-700 dark:text-green-300 px-4 py-3 rounded-lg text-sm">
                {{ session('success') }}
            </div>
        @endif

        @if($loops->isEmpty())
            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 py-16 px-6 text-center">
                <svg class="w-10 h-10 mx-auto text-gray-300 dark:text-gray-600 mb-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/>
                </svg>
                <p class="text-gray-400 dark:text-gray-500 mb-4">Vous n'avez encore aucune boucle.</p>
                @if($canCreate)
                    <a href="{{ route('loops.create') }}"
                       class="inline-flex items-center gap-1.5 px-4 py-2.5 text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700 rounded-xl transition">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                        </svg>
                        Créer votre première boucle
                    </a>
                @endif
            </div>
        @else
            <div class="grid gap-3 md:gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach($loops as $item)
                    <a href="{{ route('loops.show', $item) }}"
                       class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-4 md:p-5 hover:shadow-md hover:border-indigo-300 dark:hover:border-indigo-600 transition block active:scale-[0.98]">
                        <h3 class="font-semibold text-gray-900 dark:text-gray-100 truncate">{{ $item->name }}</h3>
                        @if($item->description)
                            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1 line-clamp-2">{{ $item->description }}</p>
                        @endif
                        <div class="flex items-center gap-4 mt-3 text-xs text-gray-400">
                            <span class="flex items-center gap-1">
                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/>
                                </svg>
                                {{ $item->active_members_count }}
                            </span>
                            <span>{{ $item->type === 'system' ? 'Système' : 'Personnalisée' }}</span>
                            @if($lastMessageAt = $item->last_message_at ? \Carbon\Carbon::parse($item->last_message_at) : null)
                                @php
                                    $recent = $lastMessageAt->gt(now()->subHours(24));
                                @endphp
                                <span class="flex items-center gap-1 @if($recent) text-indigo-500 dark:text-indigo-400 @endif">
                                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                    </svg>
                                    {{ $lastMessageAt->diffForHumans() }}
                                </span>
                            @else
                                <span class="text-gray-300 dark:text-gray-600">—</span>
                            @endif
                        </div>
                    </a>
                @endforeach
            </div>
        @endif
    </div>
</x-page>
