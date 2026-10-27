{{--
    TASK-1693 bug R — cette page sert desormais les DEUX familles de messages.

    Elle ne connaissait que les conversations d'echange, alors que l'onglet
    ChatLoop est celui par defaut de la liste : le lien « visualiser » n'aurait
    existe que pour une minorite de lignes. `$isLoop` choisit le contexte a
    nommer — la Boucle, ou les deux parties de la transaction — et le
    voisinage est deja calcule par le controleur dans l'un ou l'autre fil.

    Ce qui ne change PAS : la page reste en LECTURE SEULE, decision ecrite
    T074.9 rappelee plus bas. Le seul geste offert est « Modifier », qui mene
    a la page d'edition du bug Q — il ne modifie rien ici.
--}}
<x-admin-layout title="Détail du message">
    <div class="mb-4 flex items-center justify-between gap-3">
        <a href="{{ route('admin.messages', ['filter' => $isLoop ? 'chatloop' : 'exchanges']) }}"
           class="text-sm text-indigo-600 hover:underline">← Retour à la liste</a>
        <a href="{{ $isLoop ? route('admin.loop-messages.edit', $message) : route('admin.messages.edit', $message) }}"
           data-message-detail-edit-link="{{ $message->id }}"
           class="rounded-lg border border-indigo-300 px-3 py-1.5 text-sm font-medium text-indigo-700 transition hover:bg-indigo-50 dark:border-indigo-800 dark:text-indigo-300 dark:hover:bg-indigo-900/25">
            {{ __('admin.message_edit_action') }}
        </a>
    </div>

    <!-- Message ciblé -->
    <div class="bg-white dark:bg-gray-800 rounded-xl border border-indigo-400 dark:border-indigo-500 p-5 mb-6">
        <div class="flex items-start justify-between mb-3">
            <div>
                <p class="text-xs text-gray-500 mb-1">{{ $message->created_at->format('d/m/Y à H:i') }}</p>
                <p class="font-semibold text-gray-900 dark:text-gray-100">
                    {{ $message->sender->full_name ?? 'Système' }}
                    @if($message->sender)
                    <span class="text-xs text-gray-500 font-normal ml-1">{{ $message->sender->email }}</span>
                    @endif
                </p>
                @if($isLoop)
                <p class="text-xs text-gray-500 mt-0.5" data-message-detail-context>
                    <span class="rounded-full bg-indigo-100 px-2 py-0.5 text-xs font-medium text-indigo-800 dark:bg-indigo-900 dark:text-indigo-200">ChatLoop</span>
                    {{ $message->loop->name ?? '—' }}
                </p>
                @elseif($message->transaction)
                <p class="text-xs text-gray-500 mt-0.5" data-message-detail-context>
                    Transaction :
                    <a href="{{ route('admin.transactions') }}" class="text-indigo-600 hover:underline">
                        {{ $message->transaction->buyer->full_name ?? '?' }} ↔ {{ $message->transaction->seller->full_name ?? '?' }}
                    </a>
                </p>
                @endif
                @if($message->edited_at)
                <p class="text-xs text-gray-400 mt-0.5" data-message-detail-edited>
                    {{ __('admin.message_edit_last_edit') }} :
                    {{ $message->edited_at->format('d/m/Y H:i') }}@if($message->editedBy) — {{ $message->editedBy->full_name }}@endif
                </p>
                @endif
            </div>
            <!-- Delete button removed: T074.9 is strictly read-only -->
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
                <span class="text-xs font-medium text-gray-600 dark:text-gray-400">{{ $ctx->sender->full_name ?? 'Système' }}</span>
                <span class="text-xs text-gray-400">{{ $ctx->created_at->format('d/m/Y H:i') }}</span>
            </div>
            <p class="text-sm text-gray-700 dark:text-gray-300">{{ Str::limit($ctx->body, 200) }}</p>
        </div>
        @endforeach

        <!-- Le message ciblé dans le fil -->
        <div class="bg-indigo-50 dark:bg-indigo-900/20 rounded-lg border-2 border-indigo-400 px-4 py-3">
            <div class="flex items-center justify-between mb-1">
                <span class="text-xs font-semibold text-indigo-700 dark:text-indigo-300">{{ $message->sender->full_name ?? 'Système' }} ← message ciblé</span>
                <span class="text-xs text-gray-400">{{ $message->created_at->format('d/m/Y H:i') }}</span>
            </div>
            <p class="text-sm text-gray-700 dark:text-gray-300">{{ Str::limit($message->body, 200) }}</p>
        </div>

        @foreach($after as $ctx)
        <div class="bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 px-4 py-3 opacity-75">
            <div class="flex items-center justify-between mb-1">
                <span class="text-xs font-medium text-gray-600 dark:text-gray-400">{{ $ctx->sender->full_name ?? 'Système' }}</span>
                <span class="text-xs text-gray-400">{{ $ctx->created_at->format('d/m/Y H:i') }}</span>
            </div>
            <p class="text-sm text-gray-700 dark:text-gray-300">{{ Str::limit($ctx->body, 200) }}</p>
        </div>
        @endforeach
    </div>
    @endif
</x-admin-layout>
