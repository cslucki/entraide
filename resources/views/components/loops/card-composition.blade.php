{{--
    Local card composition of one Loop.

    Shared by the platform admin and the Organization admin: the two screens
    must not be able to drift on what they offer, so they render the same
    component and post to routes that call the same service.

    ChatLoop is absent, and not by omission — it is not a card. A Loop without
    conversation does not exist, so it is never switchable.

    Switching a card off is never destructive: the row keeps `enabled = false`,
    its content waits, and a preset synchronisation will not switch it back on.
    The confirmation says so, because "disable" reads like "delete" to most
    people and it is worth spending a sentence to say it is not.
--}}
@props(['loop', 'composition', 'action'])

<section class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-700 dark:bg-gray-800">
    <div class="mb-1 flex flex-wrap items-center justify-between gap-2">
        <h2 class="text-sm font-semibold text-gray-800 dark:text-gray-100">{{ __('loops.cards_composition_title') }}</h2>
        <span class="text-xs text-gray-400">{{ __('loops.type_label') }} · {{ app(\App\Support\Loops\LoopTypeRegistry::class)->label($loop->type, $loop->organization) }}</span>
    </div>
    <p class="mb-4 text-xs leading-5 text-gray-500 dark:text-gray-400">{{ __('loops.cards_composition_help') }}</p>

    <div class="space-y-2" x-data="{ pending: null, pendingLabel: '', pendingCount: null }">
        @foreach($composition as $card)
            <div @class([
                'flex flex-wrap items-center gap-x-3 gap-y-2 rounded-xl border px-3 py-2.5',
                'border-gray-200 dark:border-gray-700' => $card['enabled'],
                'border-dashed border-gray-300 bg-gray-50/60 dark:border-gray-600 dark:bg-gray-900/40' => ! $card['enabled'],
            ])>
                <div class="min-w-0 flex-1">
                    <p class="flex flex-wrap items-center gap-2 text-sm font-medium text-gray-900 dark:text-gray-100">
                        {{ $card['label'] }}

                        @if($card['enabled'] && $card['origin'] === 'preset')
                            <span class="rounded bg-indigo-100 px-1.5 py-0.5 text-[10px] font-semibold text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-300">
                                {{ __('loops.cards_origin_preset') }}
                            </span>
                        @elseif($card['enabled'] && $card['origin'] === 'local')
                            <span class="rounded bg-emerald-100 px-1.5 py-0.5 text-[10px] font-semibold text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300">
                                {{ __('loops.cards_origin_local') }}
                            </span>
                        @elseif($card['exists'])
                            <span class="rounded bg-amber-100 px-1.5 py-0.5 text-[10px] font-semibold text-amber-800 dark:bg-amber-900/40 dark:text-amber-200">
                                {{ __('loops.cards_origin_disabled') }}
                            </span>
                        @else
                            <span class="rounded bg-gray-100 px-1.5 py-0.5 text-[10px] font-semibold text-gray-500 dark:bg-gray-700 dark:text-gray-300">
                                {{ __('loops.cards_origin_available') }}
                            </span>
                        @endif

                        @if($card['protected'])
                            <span class="rounded bg-gray-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase text-gray-500 dark:bg-gray-700 dark:text-gray-300">
                                {{ __('loops.cards_protected') }}
                            </span>
                        @endif
                    </p>
                    <p class="mt-0.5 truncate text-xs text-gray-500 dark:text-gray-400">
                        {{ $card['description'] }}
                        @if($card['data_count'] !== null && $card['data_count'] > 0)
                            · {{ trans_choice('loops.cards_data_count', $card['data_count'], ['count' => $card['data_count']]) }}
                        @endif
                    </p>
                </div>

                @if($card['protected'])
                    <span class="shrink-0 text-xs text-gray-400">{{ __('loops.cards_always_on') }}</span>
                @elseif($card['enabled'])
                    {{-- Disabling asks first, because the word frightens more
                         than the act deserves. --}}
                    <button type="button"
                            @click="pending = @js($card['key']); pendingLabel = @js($card['label']); pendingCount = @js($card['data_count'])"
                            class="shrink-0 rounded-lg border border-gray-300 px-3 py-1.5 text-xs font-semibold text-gray-600 transition hover:border-amber-300 hover:text-amber-700 dark:border-gray-600 dark:text-gray-300">
                        {{ __('loops.cards_disable') }}
                    </button>
                @else
                    <form method="POST" action="{{ $action }}" class="shrink-0">
                        @csrf @method('PUT')
                        <input type="hidden" name="card_key" value="{{ $card['key'] }}">
                        <input type="hidden" name="enabled" value="1">
                        <button class="rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-indigo-700">
                            {{ __('loops.cards_enable') }}
                        </button>
                    </form>
                @endif
            </div>
        @endforeach

        {{-- Confirmation. An Alpine modal, never window.confirm(). Cancelling
             writes nothing at all. --}}
        <div x-show="pending" x-cloak
             class="fixed inset-0 z-50 flex items-center justify-center bg-gray-900/50 p-4"
             x-on:keydown.escape.window="pending = null">
            <div class="w-full max-w-md rounded-2xl bg-white p-5 shadow-xl dark:bg-gray-800" role="dialog" aria-modal="true">
                <h3 class="text-base font-semibold text-gray-900 dark:text-gray-100">{{ __('loops.cards_disable_title') }}</h3>
                <p class="mt-1 text-sm font-medium text-indigo-700 dark:text-indigo-300" x-text="pendingLabel"></p>

                <p class="mt-3 text-sm leading-6 text-gray-600 dark:text-gray-300"
                   x-show="pendingCount > 0">{{ __('loops.cards_disable_has_data') }}</p>
                <p class="mt-3 text-sm leading-6 text-gray-600 dark:text-gray-300"
                   x-show="!pendingCount">{{ __('loops.cards_disable_no_data') }}</p>

                <div class="mt-5 flex justify-end gap-2">
                    <button type="button" @click="pending = null"
                            class="min-h-[44px] rounded-xl border border-gray-300 px-4 text-sm font-medium text-gray-600 dark:border-gray-600 dark:text-gray-300">
                        {{ __('loops.cancel') }}
                    </button>
                    <form method="POST" action="{{ $action }}">
                        @csrf @method('PUT')
                        <input type="hidden" name="card_key" x-bind:value="pending">
                        <input type="hidden" name="enabled" value="0">
                        <button class="min-h-[44px] rounded-xl bg-amber-600 px-4 text-sm font-semibold text-white transition hover:bg-amber-700">
                            {{ __('loops.cards_disable_confirm') }}
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</section>
