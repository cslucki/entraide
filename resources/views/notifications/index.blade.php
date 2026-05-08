<x-app-layout>
    <div class="py-12 px-4">
        <div class="max-w-3xl mx-auto">
            {{-- Header section with airy spacing --}}
            <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-6 mb-12">
                <div>
                    <h1 class="text-3xl font-bold text-gray-900 dark:text-white tracking-tight">Notifications</h1>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-2">Gérez vos alertes et activités récentes</p>
                </div>

                @if(auth()->user()->unreadNotifications()->where('data->community_id', session('community_id'))->count() > 0)
                    <form action="{{ route('community.notifications.mark-all-read', ['community' => session('community_slug')]) }}" method="POST">
                        @csrf
                        <button type="submit" class="inline-flex items-center px-5 py-2.5 bg-indigo-50 dark:bg-indigo-900/20 text-indigo-600 dark:text-indigo-400 text-xs font-bold uppercase tracking-widest rounded-full hover:bg-indigo-100 dark:hover:bg-indigo-900/40 transition-all duration-300">
                            Tout marquer comme lu
                        </button>
                    </form>
                @endif
            </div>

            {{-- Notifications List --}}
            <div class="space-y-6">
                @forelse($notifications as $notification)
                    <div class="group relative flex items-start gap-5 p-6 rounded-[2rem] border border-white/20 dark:border-gray-700/30 backdrop-blur-xl transition-all duration-500 {{ $notification->read_at ? 'bg-white/40 dark:bg-gray-800/20' : 'bg-white/80 dark:bg-gray-800/50 shadow-xl shadow-indigo-500/5' }}">

                        {{-- Status dot for unread --}}
                        @if(!$notification->read_at)
                            <div class="absolute top-6 right-6 w-2 h-2 rounded-full bg-indigo-500 shadow-[0_0_10px_rgba(99,102,241,0.5)]"></div>
                        @endif

                        <div class="shrink-0">
                            @php
                                $iconConfig = match($notification->data['type'] ?? '') {
                                    'message' => ['bg' => 'bg-blue-500/10', 'text' => 'text-blue-500', 'icon' => 'M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-3 3-3-3z'],
                                    'transaction' => ['bg' => 'bg-emerald-500/10', 'text' => 'text-emerald-500', 'icon' => 'M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4'],
                                    'badge' => ['bg' => 'bg-amber-500/10', 'text' => 'text-amber-500', 'icon' => 'M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-7.714 2.143L11 21l-2.286-6.857L1 12l7.714-2.143L11 3z'],
                                    'report' => ['bg' => 'bg-rose-500/10', 'text' => 'text-rose-500', 'icon' => 'M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z'],
                                    default => ['bg' => 'bg-gray-500/10', 'text' => 'text-gray-500', 'icon' => 'M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z'],
                                };
                            @endphp
                            <div class="w-12 h-12 flex items-center justify-center rounded-2xl {{ $iconConfig['bg'] }} {{ $iconConfig['text'] }}">
                                <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $iconConfig['icon'] }}"/>
                                </svg>
                            </div>
                        </div>

                        <div class="flex-1 min-w-0">
                            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-1 mb-2">
                                <h3 class="text-base font-bold text-gray-900 dark:text-white tracking-tight">
                                    {{ $notification->data['title'] ?? 'Notification' }}
                                </h3>
                                <time class="text-[11px] font-medium text-gray-400 dark:text-gray-500 uppercase tracking-tighter tabular-nums">
                                    {{ $notification->created_at->diffForHumans() }}
                                </time>
                            </div>
                            <p class="text-sm text-gray-600 dark:text-gray-400 leading-relaxed mb-4">
                                {{ $notification->data['message'] ?? '' }}
                            </p>

                            <div class="flex items-center gap-4">
                                @if(isset($notification->data['action_url']))
                                    <a href="{{ $notification->data['action_url'] }}"
                                       class="inline-flex items-center text-xs font-bold text-indigo-600 dark:text-indigo-400 hover:text-indigo-700 transition-colors">
                                        Voir les détails
                                        <svg class="w-4 h-4 ml-1" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M13 7l5 5m0 0l-5 5m5-5H6"/></svg>
                                    </a>
                                @endif

                                @if(!$notification->read_at)
                                    <form action="{{ route('community.notifications.mark-read', ['community' => session('community_slug'), 'id' => $notification->id]) }}" method="POST">
                                        @csrf
                                        <button type="submit" class="text-[10px] font-bold text-gray-400 hover:text-gray-600 dark:text-gray-500 dark:hover:text-gray-300 uppercase tracking-widest transition-colors">
                                            Marquer comme lu
                                        </button>
                                    </form>
                                @endif
                            </div>
                        </div>
                    </div>
                @empty
                    {{-- Empty State - Gemini Aesthetic --}}
                    <div class="text-center py-32 bg-white/40 dark:bg-gray-800/20 backdrop-blur-md rounded-[3rem] border border-white/20 dark:border-gray-700/30">
                        <div class="w-20 h-20 bg-indigo-500/5 rounded-full flex items-center justify-center mx-auto mb-6">
                            <svg class="w-10 h-10 text-indigo-500/30" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
                            </svg>
                        </div>
                        <h3 class="text-xl font-bold text-gray-900 dark:text-white tracking-tight">Tout est calme ici</h3>
                        <p class="text-sm text-gray-500 dark:text-gray-400 max-w-xs mx-auto mt-3">Nous vous préviendrons dès que quelque chose de nouveau arrivera.</p>
                    </div>
                @endforelse
            </div>

            @if($notifications->hasPages())
                <div class="mt-12">
                    {{ $notifications->links() }}
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
