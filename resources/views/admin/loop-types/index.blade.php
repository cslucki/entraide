{{--
    What each Loop type is made of — super-admin only.

    One card per type, so a type is read as a whole: its composition, whether it
    may be chosen, and how many Loops already carry it. Nothing saved here is
    retroactive, and the screen says so rather than leaving it to be discovered.
--}}
<x-admin-layout :title="__('loops.types_admin_title')">
    <div class="mx-auto max-w-4xl px-4 py-8">

        <header class="mb-6">
            <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">{{ __('loops.types_admin_title') }}</h1>
            <p class="mt-1 max-w-3xl text-sm text-gray-500 dark:text-gray-400">{{ __('loops.types_admin_intro') }}</p>
        </header>

        @if(session('success'))
            <div class="mb-4 rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700 dark:border-green-800 dark:bg-green-900/20 dark:text-green-300">
                {{ session('success') }}
            </div>
        @endif

        @error('cards')
            <div class="mb-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-800 dark:bg-red-900/20 dark:text-red-300">
                {{ $message }}
            </div>
        @enderror

        <div class="mb-5 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-xs text-amber-800 dark:border-amber-700/60 dark:bg-amber-900/20 dark:text-amber-200">
            {{ __('loops.types_admin_not_retroactive') }}
        </div>

        <div class="space-y-4">
            @foreach($types as $key => $type)
                <form method="POST" action="{{ route('admin.loop-types.update', $key) }}"
                      class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-700 dark:bg-gray-800">
                    @csrf @method('PUT')

                    <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0">
                            <h2 class="flex flex-wrap items-center gap-2 text-base font-semibold text-gray-900 dark:text-gray-100">
                                {{ $type['label'] }}
                                @if(! $type['available'])
                                    <span class="rounded-full bg-gray-200 px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wide text-gray-600 dark:bg-gray-700 dark:text-gray-300">
                                        {{ __('loops.types_admin_unavailable') }}
                                    </span>
                                @endif
                                @if($type['customised'])
                                    <span class="rounded-full bg-indigo-100 px-2 py-0.5 text-[11px] font-semibold text-indigo-700 dark:bg-indigo-900/50 dark:text-indigo-300">
                                        {{ __('loops.types_admin_customised') }}
                                    </span>
                                @endif
                            </h2>
                            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $type['description'] }}</p>
                            <p class="mt-1 text-xs text-gray-400">
                                <code>{{ $type['key'] }}</code>
                                · {{ trans_choice('loops.types_admin_loops_count', $type['loops'], ['count' => $type['loops']]) }}
                            </p>
                        </div>
                    </div>

                    <fieldset class="mb-4">
                        <legend class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-400">{{ __('loops.types_admin_cards_legend') }}</legend>
                        <div class="grid gap-2 sm:grid-cols-2">
                            @foreach($catalogue as $cardKey => $card)
                                <label class="flex min-h-[44px] cursor-pointer items-center gap-2.5 rounded-xl border border-gray-200 px-3 py-2 transition hover:bg-gray-50 dark:border-gray-600 dark:hover:bg-gray-700/50">
                                    <input type="checkbox" name="cards[]" value="{{ $cardKey }}"
                                           @checked(in_array($cardKey, $type['cards'], true))
                                           class="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500 dark:border-gray-500 dark:bg-gray-900">
                                    <span class="min-w-0">
                                        <span class="block text-sm font-medium text-gray-800 dark:text-gray-100">{{ __($card['label_key']) }}</span>
                                        <span class="block truncate text-xs text-gray-500 dark:text-gray-400">{{ __($card['description_key']) }}</span>
                                    </span>
                                </label>
                            @endforeach
                        </div>
                    </fieldset>

                    <div class="flex flex-wrap items-center justify-between gap-3 border-t border-gray-100 pt-4 dark:border-gray-700">
                        <label class="flex min-h-[44px] cursor-pointer items-center gap-2.5">
                            {{-- An unchecked checkbox posts nothing, so the hidden
                                 field is what makes "unavailable" expressible. --}}
                            <input type="hidden" name="available" value="0">
                            <input type="checkbox" name="available" value="1" @checked($type['available'])
                                   class="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500 dark:border-gray-500 dark:bg-gray-900">
                            <span class="text-sm text-gray-700 dark:text-gray-200">{{ __('loops.types_admin_available_label') }}</span>
                        </label>

                        <div class="flex flex-wrap items-center gap-2">
                            @if($type['customised'])
                                <button type="submit" form="reset-{{ $key }}"
                                        class="inline-flex min-h-[44px] items-center rounded-xl border border-gray-300 px-4 text-sm text-gray-600 transition hover:bg-gray-50 dark:border-gray-600 dark:text-gray-300 dark:hover:bg-gray-700">
                                    {{ __('loops.types_admin_reset') }}
                                </button>
                            @endif
                            <button type="submit"
                                    class="inline-flex min-h-[44px] items-center rounded-xl bg-indigo-600 px-4 text-sm font-semibold text-white transition hover:bg-indigo-700">
                                {{ __('loops.types_admin_save') }}
                            </button>
                        </div>
                    </div>
                </form>

                {{-- Outside the card's form: nested forms are invalid HTML. --}}
                @if($type['customised'])
                    <form id="reset-{{ $key }}" method="POST" action="{{ route('admin.loop-types.reset', $key) }}" class="hidden">
                        @csrf @method('DELETE')
                    </form>
                @endif
            @endforeach
        </div>
    </div>
</x-admin-layout>
