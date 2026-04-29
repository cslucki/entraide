@props(['id', 'title' => 'Confirmer', 'body' => 'Êtes-vous sûr ?', 'confirmText' => 'Confirmer', 'confirmClass' => 'bg-red-600 hover:bg-red-700'])

<div x-data x-show="$store.modal.active === '{{ $id }}'" x-cloak
    class="fixed inset-0 z-50 flex items-center justify-center p-4"
    @keydown.escape.window="$store.modal.close()">
    <div class="absolute inset-0 bg-black/50" @click="$store.modal.close()"></div>
    <div class="relative bg-white dark:bg-gray-800 rounded-xl shadow-xl max-w-md w-full p-6"
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0 scale-95"
         x-transition:enter-end="opacity-100 scale-100">
        <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-2">{{ $title }}</h3>
        <p class="text-sm text-gray-600 dark:text-gray-400 mb-6">{{ $body }}</p>
        <div class="flex gap-3 justify-end">
            <button @click="$store.modal.close()"
                class="px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700">
                Annuler
            </button>
            <button @click="$store.modal.confirm()"
                class="px-4 py-2 {{ $confirmClass }} text-white rounded-lg text-sm font-medium">
                {{ $confirmText }}
            </button>
        </div>
    </div>
</div>
