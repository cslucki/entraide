<x-app-layout>
    <div class="py-12">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg border border-gray-200 dark:border-gray-700">
                <div class="p-6">
                    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 mb-8">
                        <div>
                            <h1 class="text-2xl font-bold text-gray-900 dark:text-white">Notifications</h1>
                            <p class="text-sm text-gray-600 dark:text-gray-400 mt-1">Gérez vos alertes et activités récentes</p>
                        </div>

                        @if(auth()->user()->unreadNotifications()->where('data->community_id', session('community_id'))->count() > 0)
                            <form action="{{ route('community.notifications.mark-all-read', ['community' => session('community_slug')]) }}" method="POST">
                                @csrf
                                <button type="submit" class="inline-flex items-center px-4 py-2 bg-indigo-50 dark:bg-indigo-900/30 text-indigo-700 dark:text-indigo-300 text-sm font-medium rounded-lg hover:bg-indigo-100 dark:hover:bg-indigo-900/50 transition duration-150">
                                    <svg class="w-4 h-4 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                                    </svg>
                                    Tout marquer comme lu
                                </button>
                            </form>
                        @endif
                    </div>

                    <div class="space-y-4">
                        @forelse($notifications as $notification)
                            <div class="group relative flex items-start gap-4 p-4 rounded-xl border border-gray-100 dark:border-gray-700 transition duration-150 {{ $notification->read_at ? 'bg-gray-50/50 dark:bg-gray-800/50' : 'bg-white dark:bg-gray-800 border-l-4 border-l-indigo-600 shadow-sm' }}">
                                <div class="shrink-0 mt-1">
                                    @switch($notification->data['type'] ?? '')
                                        @case('message')
                                            <div class="p-2 bg-blue-100 dark:bg-blue-900/30 rounded-lg text-blue-600 dark:text-blue-400">
                                                <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-3 3-3-3z"/></svg>
                                            </div>
                                            @break
                                        @case('transaction')
                                            <div class="p-2 bg-indigo-100 dark:bg-indigo-900/30 rounded-lg text-indigo-600 dark:text-indigo-400">
                                                <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/></svg>
                                            </div>
                                            @break
                                        @case('badge')
                                            <div class="p-2 bg-yellow-100 dark:bg-yellow-900/30 rounded-lg text-yellow-600 dark:text-yellow-400">
                                                <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-7.714 2.143L11 21l-2.286-6.857L1 12l7.714-2.143L11 3z"/></svg>
                                            </div>
                                            @break
                                        @case('report')
                                            <div class="p-2 bg-red-100 dark:bg-red-900/30 rounded-lg text-red-600 dark:text-red-400">
                                                <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                                            </div>
                                            @break
                                        @default
                                            <div class="p-2 bg-gray-100 dark:bg-gray-900/30 rounded-lg text-gray-600 dark:text-gray-400">
                                                <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                            </div>
                                    @endswitch
                                </div>

                                <div class="flex-1 min-w-0">
                                    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-1 mb-1">
                                        <h3 class="text-sm font-bold text-gray-900 dark:text-white">
                                            {{ $notification->data['title'] ?? 'Notification' }}
                                        </h3>
                                        <time class="text-[11px] text-gray-500 dark:text-gray-500 tabular-nums">
                                            {{ $notification->created_at->translatedFormat('d M Y, H:i') }}
                                        </time>
                                    </div>
                                    <p class="text-sm text-gray-600 dark:text-gray-400 leading-relaxed mb-3">
                                        {{ $notification->data['message'] ?? '' }}
                                    </p>

                                    <div class="flex items-center gap-3">
                                        @if(isset($notification->data['action_url']))
                                            <a href="{{ $notification->data['action_url'] }}"
                                               class="inline-flex items-center text-xs font-semibold text-indigo-600 dark:text-indigo-400 hover:text-indigo-500 transition">
                                                Voir les détails
                                                <svg class="w-3.5 h-3.5 ml-1" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                                            </a>
                                        @endif

                                        @if(!$notification->read_at)
                                            <form action="{{ route('community.notifications.mark-read', ['community' => session('community_slug'), 'id' => $notification->id]) }}" method="POST">
                                                @csrf
                                                <button type="submit" class="text-xs font-medium text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200 transition">
                                                    Marquer comme lu
                                                </button>
                                            </form>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        @empty
                            <div class="text-center py-20 bg-gray-50 dark:bg-gray-900/30 rounded-2xl border-2 border-dashed border-gray-200 dark:border-gray-700">
                                <div class="w-16 h-16 bg-gray-100 dark:bg-gray-800 rounded-full flex items-center justify-center mx-auto mb-4">
                                    <svg class="w-8 h-8 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
                                    </svg>
                                </div>
                                <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Tout est calme ici</h3>
                                <p class="text-gray-500 dark:text-gray-400 max-w-xs mx-auto mt-2">Vous n'avez aucune notification pour le moment.</p>
                            </div>
                        @endforelse
                    </div>

                    <div class="mt-8">
                        {{ $notifications->links() }}
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
