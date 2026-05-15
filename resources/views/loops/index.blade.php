<x-app-layout>
    <div class="max-w-7xl mx-auto px-4 py-8">
        <div class="flex items-center justify-between mb-8">
            <div>
                <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">Mes boucles</h1>
                <p class="text-gray-500 dark:text-gray-400 text-sm mt-1">Gérez vos boucles de collaboration</p>
            </div>
            <a href="{{ route('loops.create') }}"
               class="px-4 py-2 text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700 rounded-lg transition">
                + Nouvelle boucle
            </a>
        </div>

        @if(session('success'))
            <div x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 3000)"
                 class="mb-6 bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 text-green-700 dark:text-green-300 px-4 py-3 rounded-lg text-sm">
                {{ session('success') }}
            </div>
        @endif

        @if($loops->isEmpty())
            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-12 text-center">
                <p class="text-gray-400 dark:text-gray-500 mb-4">Vous n'avez encore aucune boucle.</p>
                <a href="{{ route('loops.create') }}"
                   class="text-indigo-600 hover:underline font-medium">Créer votre première boucle</a>
            </div>
        @else
            <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                @foreach($loops as $item)
                    <a href="{{ route('loops.show', $item) }}"
                       class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-5 hover:shadow-md transition block">
                        <h3 class="font-semibold text-gray-900 dark:text-gray-100 truncate">{{ $item->name }}</h3>
                        @if($item->description)
                            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1 line-clamp-2">{{ $item->description }}</p>
                        @endif
                        <div class="flex items-center gap-4 mt-3 text-xs text-gray-400">
                            <span>{{ $item->active_members_count }} membre(s)</span>
                            <span>{{ $item->type === 'system' ? 'Système' : 'Personnalisée' }}</span>
                        </div>
                    </a>
                @endforeach
            </div>
        @endif
    </div>
</x-app-layout>
