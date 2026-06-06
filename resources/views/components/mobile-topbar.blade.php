@auth
<header class="md:hidden fixed top-0 inset-x-0 z-40 bg-white dark:bg-gray-900 border-b border-gray-200 dark:border-gray-700 pt-[env(safe-area-inset-top)]">
    <div class="flex items-center justify-between h-14 px-4">
        <h1 class="text-lg font-semibold text-gray-900 dark:text-gray-100 tracking-tight">{{ $title ?? 'BouclePro' }}</h1>
        <div class="flex items-center gap-2.5">
            <a href="{{ route('messages.index') }}" class="relative w-9 h-9 rounded-full bg-gray-100 dark:bg-gray-800 flex items-center justify-center" aria-label="Messages">
                <svg class="w-5 h-5 text-gray-600 dark:text-gray-400" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                    <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>
                    <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
                </svg>
                @php $unread = auth()->user()->unreadMessagesCount(); @endphp
                @if($unread > 0)
                <span class="absolute -top-0.5 -right-0.5 min-w-[16px] h-[16px] px-1 bg-red-500 text-white text-[10px] font-bold rounded-full flex items-center justify-center">{{ min($unread, 9) }}</span>
                @endif
            </a>

            <x-dropdown align="right" width="w-72" contentClasses="py-2 bg-white dark:bg-gray-800">
                <x-slot name="trigger">
                    <button class="flex items-center gap-1 rounded-full focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 focus:ring-offset-white dark:focus:ring-offset-gray-900" aria-label="Ouvrir le menu utilisateur">
                        <img src="{{ auth()->user()->avatar_url }}" class="w-9 h-9 rounded-full border-2 border-indigo-300 dark:border-indigo-700 object-cover" alt="{{ auth()->user()->name }}">
                        <svg class="w-3.5 h-3.5 text-gray-500 dark:text-gray-400" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true">
                            <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
                        </svg>
                    </button>
                </x-slot>

                <x-slot name="content">
                    <div class="px-4 py-3 border-b border-gray-100 dark:border-gray-700">
                        <div class="text-sm font-semibold text-gray-900 dark:text-gray-100 truncate">{{ auth()->user()->name }}</div>
                        <a href="{{ route('points.index') }}" class="mt-1 inline-flex text-xs font-medium text-indigo-600 dark:text-indigo-400">{{ auth()->user()->points_balance }} pts</a>
                    </div>

                    <x-dropdown-link :href="route('dashboard')" :active="request()->routeIs('dashboard')">Tableau de bord</x-dropdown-link>
                    <x-dropdown-link :href="route('profile.show', auth()->user())">Mon profil public</x-dropdown-link>
                    <div class="border-t border-gray-100 dark:border-gray-700 my-1"></div>
                    <x-dropdown-link :href="route('services.create')">Proposer un {{ $T['service'] ?? 'service' }}</x-dropdown-link>
                    <x-dropdown-link :href="route('requests.create')">Faire une {{ $T['request'] ?? 'demande' }}</x-dropdown-link>
                    <div class="border-t border-gray-100 dark:border-gray-700 my-1"></div>
                    <x-dropdown-link :href="route('points.index')">Historique des points</x-dropdown-link>
                    <x-dropdown-link :href="route('points.index') . '#invitations'">Invitations</x-dropdown-link>
                    <x-dropdown-link :href="route('favorites.index')">Mes favoris</x-dropdown-link>
                    <x-dropdown-link :href="route('blog.my-posts')">Mes articles</x-dropdown-link>
                    <x-dropdown-link :href="route('profile.edit')">Profil et paramètres</x-dropdown-link>
                    @if(auth()->user()->is_admin)
                    <div class="border-t border-gray-100 dark:border-gray-700 my-1"></div>
                    <x-dropdown-link :href="route('admin.dashboard')"><span class="text-purple-600 dark:text-purple-400 font-medium">Administration</span></x-dropdown-link>
                    @endif
                    <div class="border-t border-gray-100 dark:border-gray-700 my-1"></div>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <x-dropdown-link :href="route('logout')" onclick="event.preventDefault(); this.closest('form').submit();">Déconnexion</x-dropdown-link>
                    </form>
                </x-slot>
            </x-dropdown>
        </div>
    </div>
</header>
@endauth
