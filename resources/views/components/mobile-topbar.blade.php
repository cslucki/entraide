@auth
<header class="md:hidden fixed top-0 inset-x-0 z-40 bg-white dark:bg-gray-900 border-b border-gray-200 dark:border-gray-700 pt-[env(safe-area-inset-top)]">
    <div class="flex items-center justify-between h-14 px-4">
        <h1 class="text-lg font-semibold text-gray-900 dark:text-gray-100 tracking-tight">{{ $title ?? 'BouclePro' }}</h1>
        <div class="flex items-center gap-2.5">
            <button class="relative w-9 h-9 rounded-full bg-gray-100 dark:bg-gray-800 flex items-center justify-center" aria-label="Notifications">
                <svg class="w-4.5 h-4.5 text-gray-600 dark:text-gray-400" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                    <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>
                    <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
                </svg>
                @php $unread = auth()->user()->unreadMessagesCount(); @endphp
                @if($unread > 0)
                <span class="absolute -top-0.5 -right-0.5 min-w-[16px] h-[16px] px-1 bg-red-500 text-white text-[10px] font-bold rounded-full flex items-center justify-center">{{ min($unread, 9) }}</span>
                @endif
            </button>
            <a href="{{ route('profile.show', auth()->user()) }}" class="w-9 h-9 rounded-full bg-indigo-100 dark:bg-indigo-900 border-2 border-indigo-300 dark:border-indigo-700 flex items-center justify-center text-sm font-semibold text-indigo-700 dark:text-indigo-300">
                {{ strtoupper(substr(auth()->user()->name, 0, 2)) }}
            </a>
        </div>
    </div>
</header>
@endauth
