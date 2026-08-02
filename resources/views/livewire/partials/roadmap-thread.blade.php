{{-- Per-action comment thread. Uses $detail, $detailMessages, $isPrivileged. --}}
<div class="space-y-3">
    {{-- Composer --}}
    <form wire:submit="addMessage" class="space-y-2">
        <textarea wire:model="newMessage" rows="2" maxlength="5000" placeholder="{{ __('loops.roadmap_comment_placeholder') }}"
                  class="w-full rounded-xl border-gray-300 bg-white text-xs text-gray-900 focus:border-violet-500 focus:ring-violet-500 dark:border-gray-600 dark:bg-gray-950 dark:text-gray-100"></textarea>
        <div class="flex justify-end">
            <button type="submit" wire:loading.attr="disabled" wire:target="addMessage"
                    class="rounded-lg bg-violet-600 px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-violet-700 disabled:opacity-50">
                {{ __('loops.roadmap_comment_send') }}
            </button>
        </div>
    </form>

    {{-- Messages --}}
    @forelse($detailMessages as $message)
        @php
            $author = $message->user;
            $canEditMsg = $author && $author->id === auth()->id();
            $canDeleteMsg = $canEditMsg || $isPrivileged;
            $photo = $author && $author->isDisplayableIn(currentOrganization()) ? $author->avatar_url : null;
        @endphp
        <div wire:key="msg-{{ $message->id }}" class="flex gap-2">
            @if($photo)
                <img src="{{ $photo }}" alt="" class="h-6 w-6 shrink-0 rounded-full object-cover">
            @else
                <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-violet-200 text-[10px] font-bold text-violet-800 dark:bg-violet-500/30 dark:text-violet-100">{{ mb_strtoupper(mb_substr($author?->publicDisplayName() ?? '?', 0, 1)) }}</span>
            @endif
            <div class="min-w-0 flex-1">
                <div class="flex items-baseline gap-2">
                    <span class="truncate text-xs font-semibold text-gray-800 dark:text-gray-100">{{ $author?->publicDisplayName() ?? '—' }}</span>
                    <span class="text-[10px] text-gray-400">{{ $message->created_at?->isoFormat('D MMM · HH:mm') }}</span>
                </div>
                @if($editingMessageId === $message->id)
                    <div class="mt-1 space-y-1">
                        <textarea wire:model="editingMessageBody" rows="2" maxlength="5000"
                                  class="w-full rounded-lg border-gray-300 bg-white text-xs dark:border-gray-600 dark:bg-gray-950 dark:text-gray-100"></textarea>
                        <div class="flex justify-end gap-2">
                            <button type="button" wire:click="cancelEditMessage" class="text-[11px] text-gray-500 hover:text-gray-800 dark:text-gray-400">{{ __('loops.cancel') }}</button>
                            <button type="button" wire:click="saveMessage" class="rounded bg-violet-600 px-2 py-1 text-[11px] font-semibold text-white hover:bg-violet-700">{{ __('loops.roadmap_save') }}</button>
                        </div>
                    </div>
                @else
                    <p class="whitespace-pre-line break-words text-xs text-gray-700 dark:text-gray-300">{{ $message->body }}</p>
                    @if($canEditMsg || $canDeleteMsg)
                        <div class="mt-0.5 flex gap-2 text-[10px] text-gray-400">
                            @if($canEditMsg)
                                <button type="button" wire:click="startEditMessage('{{ $message->id }}')" class="hover:text-violet-600 dark:hover:text-violet-300">{{ __('loops.roadmap_edit') }}</button>
                            @endif
                            @if($canDeleteMsg)
                                <button type="button" wire:click="deleteMessage('{{ $message->id }}')"
                                        wire:confirm="{{ __('loops.roadmap_delete_confirm') }}"
                                        class="hover:text-red-500 dark:hover:text-red-400">{{ __('loops.roadmap_delete') }}</button>
                            @endif
                        </div>
                    @endif
                @endif
            </div>
        </div>
    @empty
        <p class="text-[11px] text-gray-400 dark:text-gray-500">{{ __('loops.roadmap_no_comments') }}</p>
    @endforelse
</div>
