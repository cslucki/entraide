{{-- TASK-1879 — icone « Repondre » DANS une carte du fil (en bas a droite,
     comme les actions d'une bulle). Le parent doit etre `relative`. --}}
<button type="button" data-action="reply-to-card"
        x-on:click="$dispatch('reply-to-message', { messageId: '{{ $msg->id }}' }); document.querySelector('[data-composer-input]')?.focus()"
        title="{{ __('messages.reply') }}" aria-label="{{ __('messages.reply') }}"
        class="absolute bottom-1.5 right-1.5 z-10 inline-flex h-8 w-8 items-center justify-center rounded-full text-gray-400 transition hover:bg-black/5 hover:text-indigo-600 focus-visible:outline focus-visible:outline-2 focus-visible:outline-indigo-500 dark:text-gray-500 dark:hover:bg-white/10 dark:hover:text-indigo-300">
    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 15 3 9m0 0 6-6M3 9h12a6 6 0 0 1 0 12h-3"/></svg>
</button>
