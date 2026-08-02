{{-- Reusable premium confirmation modal (Boucle/Cards perimeter).
     Placed INSIDE a Livewire component; opens via a window event and, on confirm,
     calls a $wire method. Replaces native wire:confirm / window.confirm.

     Open it from any trigger:
       x-on:click="$dispatch('open-confirm', {
           title: '…', body: '…', confirmLabel: '…', danger: true,
           accept: 'deleteItem', params: ['{{ $item->id }}']
       })"
--}}
<div
    x-data="{
        open: false,
        title: '', body: '', confirmLabel: '', danger: false,
        accept: '', params: [], loading: false, trigger: null,
        show(d) {
            this.title = d.title || '';
            this.body = d.body || '';
            this.confirmLabel = d.confirmLabel || @js(__('loops.roadmap_delete'));
            this.danger = !!d.danger;
            this.accept = d.accept || '';
            this.params = d.params || [];
            this.loading = false;
            this.trigger = document.activeElement;
            this.open = true;
            this.$nextTick(() => this.$refs.confirmBtn && this.$refs.confirmBtn.focus());
        },
        close() {
            this.open = false;
            this.accept = ''; this.params = [];
            if (this.trigger && this.trigger.isConnected) this.trigger.focus();
            this.trigger = null;
        },
        confirm() {
            if (!this.accept || this.loading) return;
            this.loading = true;
            Promise.resolve($wire.call(this.accept, ...this.params))
                .then(() => this.close())
                .catch(() => { this.loading = false; });
        },
        trap(e) {
            if (!this.open || e.key !== 'Tab') return;
            const nodes = [...this.$refs.panel.querySelectorAll('button:not([disabled])')];
            if (!nodes.length) return;
            const first = nodes[0], last = nodes[nodes.length - 1];
            if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
            else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
        }
    }"
    x-on:open-confirm.window="show($event.detail)"
    x-on:keydown.escape.window="open && close()"
    x-on:keydown.window="trap($event)"
>
    <template x-if="open">
        <div class="fixed inset-0 z-[70] flex items-end justify-center p-0 sm:items-center sm:p-4" role="dialog" aria-modal="true" x-bind:aria-label="title">
            <div class="absolute inset-0 bg-black/50" x-on:click="close()"></div>
            <div x-ref="panel"
                 class="relative w-full rounded-t-2xl bg-white p-5 shadow-xl dark:bg-gray-900 sm:max-w-sm sm:rounded-2xl"
                 x-transition:enter="transition ease-out duration-150"
                 x-transition:enter-start="opacity-0 translate-y-2 sm:scale-95"
                 x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100">
                <h3 class="text-base font-semibold text-gray-900 dark:text-gray-100" x-text="title"></h3>
                <p class="mt-2 text-sm leading-6 text-gray-600 dark:text-gray-300" x-text="body"></p>
                <div class="mt-5 flex items-center justify-end gap-2">
                    <button type="button" x-on:click="close()"
                            class="rounded-xl px-4 py-2 text-sm font-semibold text-gray-600 transition hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800">
                        {{ __('loops.cancel') }}
                    </button>
                    <button type="button" x-ref="confirmBtn" x-on:click="confirm()" x-bind:disabled="loading"
                            x-bind:class="danger
                                ? 'bg-red-600 hover:bg-red-700 text-white'
                                : 'bg-violet-600 hover:bg-violet-700 text-white'"
                            class="inline-flex items-center gap-2 rounded-xl px-4 py-2 text-sm font-semibold transition disabled:opacity-50">
                        <svg x-show="loading" class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                        <span x-text="confirmLabel"></span>
                    </button>
                </div>
            </div>
        </div>
    </template>
</div>
