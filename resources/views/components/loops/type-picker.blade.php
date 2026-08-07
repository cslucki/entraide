{{--
    Choosing a Loop type at creation.

    Shared by the three creation forms — global admin, Organization admin and
    member — so the three always offer the same types with the same wording. A
    type is a card composition, so the composition is what the choice shows:
    picking "Dialogue" over "Projets" is picking one workspace over another, and
    the form should not make that look like picking a label.

    Only available types are passed in; a type under construction is never
    offered here.
--}}
@props(['types', 'selected' => null, 'name' => 'type'])

@php
    $catalogue = config('loop_cards.cards', []);
    $registry = app(\App\Support\Loops\LoopTypeRegistry::class);
    $current = $selected ?: array_key_first($types);
@endphp

<fieldset>
    <legend class="mb-1 block text-xs font-medium text-gray-700 dark:text-gray-300">{{ __('loops.type_choose_label') }}</legend>
    <p class="mb-2 text-xs text-gray-500 dark:text-gray-400">{{ __('loops.type_choose_hint') }}</p>

    <div class="grid gap-2 sm:grid-cols-2">
        @foreach($types as $key => $definition)
            <label class="relative flex cursor-pointer flex-col rounded-xl border border-gray-300 p-3 transition has-[:checked]:border-indigo-500 has-[:checked]:bg-indigo-50/70 has-[:checked]:ring-1 has-[:checked]:ring-indigo-400 hover:bg-gray-50 dark:border-gray-600 dark:has-[:checked]:bg-indigo-900/25 dark:hover:bg-gray-700/50">
                <span class="flex items-start gap-2.5">
                    <input type="radio" name="{{ $name }}" value="{{ $key }}" @checked($current === $key)
                           class="mt-0.5 h-4 w-4 border-gray-300 text-indigo-600 focus:ring-indigo-500 dark:border-gray-500 dark:bg-gray-900">
                    <span class="min-w-0">
                        <span class="block text-sm font-semibold text-gray-900 dark:text-gray-100">{{ __($definition['label_key']) }}</span>
                        <span class="mt-0.5 block text-xs text-gray-500 dark:text-gray-400">{{ __($definition['description_key']) }}</span>
                    </span>
                </span>

                {{-- What this type actually builds. --}}
                <span class="mt-2 flex flex-wrap gap-1 pl-6">
                    @foreach($registry->cardsFor($key) as $cardKey)
                        <span class="rounded bg-gray-100 px-1.5 py-0.5 text-[11px] font-medium text-gray-600 dark:bg-gray-700 dark:text-gray-300">
                            {{-- Par le type : cet ecran annonce ce que le preset
                                 construit, et « Engagements » est le mot que la
                                 Pair-aidance verra. --}}
                            {{ isset($catalogue[$cardKey]) ? app(\App\Support\Loops\LoopCardRegistry::class)->labelForType($key, $cardKey) : $cardKey }}
                        </span>
                    @endforeach
                </span>
            </label>
        @endforeach
    </div>

    @error($name)<p class="mt-1 text-xs text-red-500">{{ $message }}</p>@enderror
</fieldset>
