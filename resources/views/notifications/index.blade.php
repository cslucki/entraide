<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('Mes Notifications') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900 dark:text-gray-100">
                    <div class="flex justify-between items-center mb-6">
                        <h3 class="text-lg font-medium">Historique des notifications</h3>
                        @if(auth()->user()->unreadNotifications()->where('data->community_id', session('community_id'))->count() > 0)
                            <form action="{{ route('community.notifications.mark-all-read', session('community_slug')) }}" method="POST">
                                @csrf
                                <button type="submit" class="text-sm text-indigo-600 dark:text-indigo-400 hover:underline">
                                    Tout marquer comme lu
                                </button>
                            </form>
                        @endif
                    </div>

                    <div class="space-y-4">
                        @forelse($notifications as $notification)
                            <div class="p-4 rounded-lg border {{ $notification->read_at ? 'border-gray-100 dark:border-gray-700 opacity-60' : 'border-indigo-100 dark:border-indigo-900 bg-indigo-50/30 dark:bg-indigo-900/10' }}">
                                <div class="flex items-start gap-4">
                                    <div class="mt-1">
                                        @switch($notification->data['type'] ?? '')
                                            @case('message')
                                                <svg class="w-6 h-6 text-blue-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-3 3-3-3z"/></svg>
                                                @break
                                            @case('transaction')
                                                <svg class="w-6 h-6 text-indigo-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/></svg>
                                                @break
                                            @case('badge')
                                                <svg class="w-6 h-6 text-yellow-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-7.714 2.143L11 21l-2.286-6.857L1 12l7.714-2.143L11 3z"/></svg>
                                                @break
                                            @case('report')
                                                <svg class="w-6 h-6 text-red-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                                                @break
                                            @default
                                                <svg class="w-6 h-6 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                        @endswitch
                                    </div>
                                    <div class="flex-1">
                                        <div class="flex justify-between items-start">
                                            <h4 class="font-semibold">{{ $notification->data['title'] ?? 'Notification' }}</h4>
                                            <span class="text-xs text-gray-500">{{ $notification->created_at->diffForHumans() }}</span>
                                        </div>
                                        <p class="text-sm text-gray-600 dark:text-gray-400 mt-1">
                                            {{ $notification->data['message'] ?? '' }}
                                        </p>
                                        <div class="mt-3 flex gap-3">
                                            @if(isset($notification->data['action_url']) && $notification->data['action_url'] !== '#')
                                                <a href="{{ $notification->data['action_url'] }}" class="text-xs font-semibold text-indigo-600 dark:text-indigo-400 hover:underline">
                                                    Voir les détails
                                                </a>
                                            @endif
                                            @if(!$notification->read_at)
                                                <form action="{{ route('community.notifications.mark-read', [session('community_slug'), $notification->id]) }}" method="POST">
                                                    @csrf
                                                    <button type="submit" class="text-xs font-semibold text-gray-500 hover:text-gray-700 dark:hover:text-gray-300">
                                                        Marquer comme lu
                                                    </button>
                                                </form>
                                            @endif
                                        </div>
                                    </div>
                                </div>
                            </div>
                        @empty
                            <div class="text-center py-12">
                                <p class="text-gray-500">Vous n'avez aucune notification pour le moment.</p>
                            </div>
                        @endforelse

                        <div class="mt-6">
                            {{ $notifications->links() }}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
