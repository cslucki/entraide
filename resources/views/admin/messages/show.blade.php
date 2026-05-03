<x-admin-layout title="Détail du message">
    <div class="mb-4">
        <a href="{{ route('admin.messages') }}" class="text-sm text-indigo-600 hover:underline">← Retour à la liste</a>
    </div>

    <!-- Message ciblé -->
    <div class="bg-white dark:bg-gray-800 rounded-xl border border-indigo-400 dark:border-indigo-500 p-5 mb-6">
        <div class="flex items-start justify-between mb-3">
            <div>
                <p class="text-xs text-gray-500 mb-1">{{ $message->created_at->format('d/m/Y à H:i') }}</p>
                <p class="font-semibold text-gray-900 dark:text-gray-100">
                    {{ $message->sender->name ?? 'Système' }}
                    @if($message->sender)
                    <span class="text-xs text-gray-500 font-normal ml-1">{{ $message->sender->email }}</span>
                    @endif
                </p>
                @if($message->transaction)
                <p class="text-xs text-gray-500 mt-0.5">
                    Transaction :
                    <a href="{{ route('admin.transactions') }}" class="text-indigo-600 hover:underline">
                        {{ $message->transaction->buyer->name ?? '?' }} ↔ {{ $message->transaction->seller->name ?? '?' }}
                    </a>
                </p>
                @endif
            </div>
            <form method="POST" action="{{ route('admin.messages.destroy', $message) }}"
                  onsubmit="return confirm('Supprimer définitivement ce message ?')">
                @csrf @method('DELETE')
                <button class="px-3 py-1.5 bg-red-600 hover:bg-red-700 text-white text-xs rounded-lg transition">
                    Supprimer
                </button>
            </form>
        </div>
        <div class="bg-gray-50 dark:bg-gray-700/50 rounded-lg p-4">
            <p class="text-gray-900 dark:text-gray-100 whitespace-pre-wrap">{{ $message->body }}</p>
        </div>
        @if($message->type === 'system')
        <p class="text-xs text-gray-400 italic mt-2">Message système</p>
        @endif
    </div>

    <!-- Contexte -->
    @if($before->count() > 0 || $after->count() > 0)
    <h2 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-3">Contexte de la conversation</h2>

    <div class="space-y-2">
        @foreach($before as $ctx)
        <div class="bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 px-4 py-3 opacity-75">
            <div class="flex items-center justify-between mb-1">
                <span class="text-xs font-medium text-gray-600 dark:text-gray-400">{{ $ctx->sender->name ?? 'Système' }}</span>
                <span class="text-xs text-gray-400">{{ $ctx->created_at->format('d/m/Y H:i') }}</span>
            </div>
            <p class="text-sm text-gray-700 dark:text-gray-300">{{ Str::limit($ctx->body, 200) }}</p>
        </div>
        @endforeach

        <!-- Le message ciblé dans le fil -->
        <div class="bg-indigo-50 dark:bg-indigo-900/20 rounded-lg border-2 border-indigo-400 px-4 py-3">
            <div class="flex items-center justify-between mb-1">
                <span class="text-xs font-semibold text-indigo-700 dark:text-indigo-300">{{ $message->sender->name ?? 'Système' }} ← message ciblé</span>
                <span class="text-xs text-gray-400">{{ $message->created_at->format('d/m/Y H:i') }}</span>
            </div>
            <p class="text-sm text-gray-700 dark:text-gray-300">{{ Str::limit($message->body, 200) }}</p>
        </div>

        @foreach($after as $ctx)
        <div class="bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 px-4 py-3 opacity-75">
            <div class="flex items-center justify-between mb-1">
                <span class="text-xs font-medium text-gray-600 dark:text-gray-400">{{ $ctx->sender->name ?? 'Système' }}</span>
                <span class="text-xs text-gray-400">{{ $ctx->created_at->format('d/m/Y H:i') }}</span>
            </div>
            <p class="text-sm text-gray-700 dark:text-gray-300">{{ Str::limit($ctx->body, 200) }}</p>
        </div>
        @endforeach
    </div>
    @endif
</x-admin-layout>
