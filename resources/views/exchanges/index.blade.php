<x-app-layout>
    <div class="max-w-4xl mx-auto px-4 py-10">

        <div class="mb-8">
            <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">Échanges réalisés</h1>
            <p class="text-gray-500 dark:text-gray-400 mt-1 text-sm">{{ $exchanges->total() }} échange{{ $exchanges->total() > 1 ? 's' : '' }} complété{{ $exchanges->total() > 1 ? 's' : '' }} sur la plateforme</p>
        </div>

        <div class="space-y-4">
            @forelse($exchanges as $exchange)
            @php
                $rating = $exchange->reviews->avg('rating');
            @endphp
            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-5">
                <div class="flex items-start justify-between gap-4">

                    <!-- Titre + catégorie -->
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-2 mb-1 flex-wrap">
                            @if($exchange->service?->category)
                            <span class="px-2 py-0.5 rounded-full text-xs font-medium text-white" style="background-color:{{ $exchange->service->category->color }}">
                                {{ $exchange->service->category->name }}
                            </span>
                            @endif
                            <span class="font-semibold text-gray-900 dark:text-gray-100 truncate">{{ $exchange->subject }}</span>
                        </div>

                        <!-- Participants -->
                        <div class="flex items-center gap-3 mt-2">
                            <a href="{{ route('profile.show', $exchange->seller) }}" class="flex items-center gap-1.5 hover:opacity-75 transition">
                                <img src="{{ $exchange->seller->avatar_url }}" class="w-6 h-6 rounded-full" alt="">
                                <span class="text-xs text-gray-600 dark:text-gray-300">{{ $exchange->seller->name }}</span>
                            </a>
                            <svg class="w-4 h-4 text-gray-300 dark:text-gray-600 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/></svg>
                            <a href="{{ route('profile.show', $exchange->buyer) }}" class="flex items-center gap-1.5 hover:opacity-75 transition">
                                <img src="{{ $exchange->buyer->avatar_url }}" class="w-6 h-6 rounded-full" alt="">
                                <span class="text-xs text-gray-600 dark:text-gray-300">{{ $exchange->buyer->name }}</span>
                            </a>
                        </div>
                    </div>

                    <!-- Points + note + date -->
                    <div class="flex-shrink-0 text-right">
                        <p class="font-bold text-indigo-600 dark:text-indigo-400 text-sm">{{ $exchange->points_agreed }} pts</p>
                        @if($rating)
                        <p class="text-yellow-500 text-sm mt-1">{{ str_repeat('★', round($rating)) }}{{ str_repeat('☆', 5 - round($rating)) }}</p>
                        @endif
                        <p class="text-xs text-gray-400 mt-1">{{ $exchange->updated_at->diffForHumans() }}</p>
                    </div>

                </div>

                <!-- Avis publics -->
                @foreach($exchange->reviews->whereNotNull('comment') as $review)
                <p class="mt-3 pt-3 border-t border-gray-100 dark:border-gray-700 text-sm text-gray-500 dark:text-gray-400 italic line-clamp-2">"{{ $review->comment }}"</p>
                @endforeach
            </div>
            @empty
            <p class="text-center text-gray-400 py-16">Aucun échange complété pour le moment.</p>
            @endforelse
        </div>

        <div class="mt-8">
            {{ $exchanges->links() }}
        </div>

    </div>
</x-app-layout>
