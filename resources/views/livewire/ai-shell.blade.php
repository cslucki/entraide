{{--
    TASK-1315 — Shell « BouclePro IA ».

    Ce qui survit a la navigation n'est PAS l'etat de ce composant : chaque page
    est un rechargement complet (aucun `wire:navigate` dans l'application), donc
    le composant est remonte a chaque fois. Ce qui survit, c'est le FIL, relu en
    base a chaque montage. L'ouverture du panneau, elle, est une commodite de
    confort : memorisee en `localStorage` sur desktop uniquement — sur mobile le
    Shell est une feuille plein ecran, et la rouvrir automatiquement a chaque
    page emprisonnerait l'utilisateur devant sa propre navigation.

    T1244.BUG : aucun `x-transition` sur un `x-show`. Alpine ferait alors
    dependre le basculement de `display` d'une sequence requestAnimationFrame,
    gelee tant que `document.hidden` est vrai — le panneau resterait
    `display: none` malgre `open = true`.
--}}
<div data-bp-shell-mount>
@if($shell === null)
    <span data-ai-shell-disabled hidden></span>
@else
<div x-data="{
        open: false,
        desktop() { return window.matchMedia('(min-width: 768px)').matches; },
        init() {
            try { this.open = this.desktop() && window.localStorage.getItem('bp-ai-shell-open') === '1'; } catch (e) { this.open = false; }
            this.$watch('open', (value) => {
                try { window.localStorage.setItem('bp-ai-shell-open', value && this.desktop() ? '1' : '0'); } catch (e) {}
                if (value) { this.toEnd(); }
            });
            if (this.open) { this.toEnd(); }
        },
        toEnd() {
            this.$nextTick(() => { if (this.$refs.log) { this.$refs.log.scrollTop = this.$refs.log.scrollHeight; } });
        },
        show() { this.open = true; this.$nextTick(() => this.$refs.composer?.focus()); },
        close() { this.open = false; },
    }"
    @bp-open-ai-shell.window="show()"
    @ai-shell-updated.window="toEnd()"
    @keydown.escape.window="close()"
    data-ai-shell
    :data-ai-shell-open="open ? 'true' : 'false'"
    data-ai-shell-context-kind="{{ $shell['context']['kind'] ?? 'other' }}"
    data-ai-shell-conversation="{{ $shell['conversation_id'] }}"
    data-ai-shell-thread-count="{{ $shell['messages']->count() }}"
    class="print:hidden">

    <div x-show="open" x-cloak
         role="dialog"
         aria-modal="false"
         aria-labelledby="ai-shell-title"
         data-ai-shell-panel
         class="fixed inset-x-0 bottom-0 top-14 z-50 flex flex-col overflow-hidden rounded-t-2xl border border-gray-200 bg-white shadow-2xl dark:border-gray-700 dark:bg-gray-800
                md:inset-x-auto md:top-auto md:right-6 md:bottom-40 md:h-[34rem] md:w-[26rem] md:rounded-2xl">

        {{-- En-tete : qui parle, et OU l'on se trouve. --}}
        <div class="flex items-start justify-between gap-3 border-b border-gray-100 px-4 pt-4 pb-3 dark:border-gray-700">
            <div class="min-w-0">
                <p id="ai-shell-title" class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ __('ai.shell_title') }}</p>
                <p class="mt-0.5 flex items-center gap-1.5 text-xs text-gray-500 dark:text-gray-400" data-ai-shell-context-label>
                    <svg class="h-3.5 w-3.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 21s7-4.35 7-10a7 7 0 1 0-14 0c0 5.65 7 10 7 10Z"/><circle cx="12" cy="11" r="2.5"/></svg>
                    <span class="truncate">{{ $shell['context']['label'] ?? '' }}</span>
                </p>
            </div>
            <button type="button" @click="close()" data-ai-shell-close
                    class="rounded-md p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-600 dark:hover:bg-gray-700 dark:hover:text-gray-200"
                    aria-label="{{ __('ai.shell_close') }}">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/></svg>
            </button>
        </div>

        {{-- Le fil. --}}
        <div class="flex-1 overflow-y-auto px-4 py-3" x-ref="log" data-ai-shell-log>
            @if($shell['messages']->isEmpty())
                <div class="py-8 text-center" data-ai-shell-empty>
                    <p class="text-sm font-medium text-gray-700 dark:text-gray-200">{{ __('ai.shell_empty_title') }}</p>
                    <p class="mx-auto mt-1 max-w-xs text-xs text-gray-500 dark:text-gray-400">{{ __('ai.shell_empty_hint') }}</p>
                </div>
            @else
                <ul class="space-y-3">
                    @foreach($shell['messages'] as $message)
                        <li wire:key="ai-shell-msg-{{ $message->id }}"
                            data-ai-shell-message="{{ $message->role }}"
                            class="flex {{ $message->role === \App\Models\AiShellMessage::ROLE_USER ? 'justify-end' : 'justify-start' }}">
                            <div class="max-w-[85%] rounded-2xl px-3 py-2 text-sm leading-5 {{ $message->role === \App\Models\AiShellMessage::ROLE_USER
                                    ? 'bg-indigo-600 text-white'
                                    : 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-100' }}">
                                <span class="sr-only">{{ $message->role === \App\Models\AiShellMessage::ROLE_USER ? __('ai.shell_you') : __('ai.shell_assistant') }} :</span>
                                @if($message->role === \App\Models\AiShellMessage::ROLE_ASSISTANT && filled($message->metadata['title'] ?? null))
                                    <span class="mb-1 block font-semibold" data-ai-shell-answer-title>{{ $message->metadata['title'] }}</span>
                                @endif
                                <span class="block whitespace-pre-line">{{ $message->content }}</span>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
            {{-- Les actions contextuelles. Elles ne dependent PAS du fil : sur une
                 page Boucle, « interroger les Dossiers » vaut des l'ouverture du
                 Shell, meme avant le premier tour. --}}
            @if(count($shell['actions']) > 0)
                <div class="mt-3 flex flex-wrap gap-2" data-ai-shell-actions>
                    @foreach($shell['actions'] as $action)
                        @if($action['kind'] === 'link')
                            <a href="{{ $action['url'] }}" data-ai-shell-action="{{ $action['key'] }}"
                               class="inline-flex items-center gap-1.5 rounded-full border border-indigo-200 bg-indigo-50 px-3 py-1.5 text-xs font-semibold text-indigo-700 hover:bg-indigo-100 dark:border-indigo-800/60 dark:bg-indigo-900/30 dark:text-indigo-200">
                                {{ $action['label'] }}
                            </a>
                        @elseif($action['kind'] === 'method')
                            <button type="button" wire:click="{{ $action['method'] }}" data-ai-shell-action="{{ $action['key'] }}"
                                    class="inline-flex items-center gap-1.5 rounded-full border border-gray-200 bg-white px-3 py-1.5 text-xs font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 dark:hover:bg-gray-600">
                                {{ $action['label'] }}
                            </button>
                        @else
                            <button type="button"
                                    @click="close(); window.dispatchEvent(new CustomEvent('{{ $action['event'] }}', { detail: {} }))"
                                    data-ai-shell-action="{{ $action['key'] }}"
                                    class="inline-flex items-center gap-1.5 rounded-full border border-gray-200 bg-white px-3 py-1.5 text-xs font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 dark:hover:bg-gray-600">
                                {{ $action['label'] }}
                            </button>
                        @endif
                    @endforeach
                </div>
            @endif

            <div wire:loading wire:target="send" class="mt-3 text-xs italic text-gray-500 dark:text-gray-400" data-ai-shell-pending>
                {{ __('ai.shell_sending') }}
            </div>
        </div>

        {{-- Pied : refus economique, ou composeur. --}}
        <div class="border-t border-gray-100 px-4 py-3 dark:border-gray-700">
            @if($shell['refusal'])
                <div class="rounded-xl border border-rose-200 bg-rose-50 p-3 text-sm leading-5 text-rose-800 dark:border-rose-800/50 dark:bg-rose-900/20 dark:text-rose-200" data-ai-shell-refusal>
                    {{ $shell['refusal'] }}
                </div>
                @if($shell['offers_url'])
                    <a href="{{ $shell['offers_url'] }}" data-ai-shell-offers
                       class="mt-2 inline-flex w-full items-center justify-center rounded-xl bg-indigo-600 px-3 py-2 text-sm font-semibold text-white hover:bg-indigo-700">
                        {{ __('ai.credit_see_offers') }}
                    </a>
                @endif
            @else
                @if($notice)
                    <p class="mb-2 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:bg-amber-900/25 dark:text-amber-200" data-ai-shell-notice>{{ $notice }}</p>
                @endif

                <form wire:submit="send" class="flex items-end gap-2">
                    <label for="ai-shell-composer" class="sr-only">{{ __('ai.shell_placeholder') }}</label>
                    <textarea id="ai-shell-composer"
                              x-ref="composer"
                              wire:model="draft"
                              rows="2"
                              maxlength="{{ $shell['max_input_chars'] }}"
                              data-ai-shell-composer
                              placeholder="{{ __('ai.shell_placeholder') }}"
                              class="min-h-[2.75rem] flex-1 resize-none rounded-xl border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100"></textarea>
                    <button type="submit"
                            wire:loading.attr="disabled"
                            wire:target="send"
                            data-ai-shell-send
                            class="inline-flex h-11 w-11 flex-shrink-0 items-center justify-center rounded-xl bg-indigo-600 text-white hover:bg-indigo-700 disabled:opacity-50">
                        <span class="sr-only">{{ __('ai.shell_send') }}</span>
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m5 12 14-7-4 7 4 7-14-7Z"/></svg>
                    </button>
                </form>

                <div class="mt-2 flex items-center justify-between gap-3">
                    <p class="text-[11px] leading-4 text-gray-500 dark:text-gray-400" data-ai-shell-no-publication>{{ __('ai.shell_no_publication_note') }}</p>

                    @if(! $shell['messages']->isEmpty())
                        @if($confirmingClear)
                            <span class="flex flex-shrink-0 items-center gap-2 text-[11px]">
                                <span class="text-gray-600 dark:text-gray-300">{{ __('ai.shell_clear_confirm') }}</span>
                                <button type="button" wire:click="clearThread" data-ai-shell-clear-confirm class="font-semibold text-rose-600 hover:underline dark:text-rose-300">{{ __('ai.shell_clear_yes') }}</button>
                                <button type="button" wire:click="cancelClear" data-ai-shell-clear-cancel class="text-gray-500 hover:underline dark:text-gray-400">{{ __('ai.shell_clear_no') }}</button>
                            </span>
                        @else
                            <button type="button" wire:click="askForClear" data-ai-shell-clear class="flex-shrink-0 text-[11px] text-gray-500 hover:underline dark:text-gray-400">{{ __('ai.shell_clear') }}</button>
                        @endif
                    @endif
                </div>

                <p class="mt-1 text-[11px] leading-4 text-gray-400 dark:text-gray-500" data-ai-shell-context-note>{{ __('ai.shell_context_note') }}</p>
            @endif
        </div>
    </div>
</div>
@endif
</div>
