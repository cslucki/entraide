{{--
    Le Journal : ce qui s'est passe, date et signe.

    Une entree promue **reprend** le message, elle ne le copie pas : son texte
    est lu sur le message a chaque affichage. Corriger le message corrige
    l'entree, et il n'y a rien a garder d'accord.

    Chaque geste du composant a un chemin ici.
--}}
<div class="flex h-full flex-col" data-loop-journal>

@if (! $canView)
    <p class="px-4 py-10 text-center text-sm text-gray-500 dark:text-gray-400">
        {{ __('loops.cards.journal.no_access') }}
    </p>
@else

    @if ($problem)
        <p class="mb-3 rounded-lg bg-rose-50 px-3 py-2 text-sm text-rose-800 dark:bg-rose-500/15 dark:text-rose-200" role="alert">{{ $problem }}</p>
    @elseif ($flash)
        <p class="mb-3 rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-800 dark:bg-emerald-500/15 dark:text-emerald-200"
           role="status" aria-live="polite">{{ $flash }}</p>
    @endif

    @if ($entries->isEmpty())
        <div class="rounded-2xl border border-dashed border-gray-300 px-5 py-10 text-center dark:border-gray-600">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ __('loops.cards.journal.empty_title') }}</h3>
            <p class="mx-auto mt-2 max-w-sm text-sm text-gray-500 dark:text-gray-400">{{ __('loops.cards.journal.empty_body') }}</p>
        </div>
    @else
        <ol class="space-y-3">
            @foreach ($entries as $entree)
                <li class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
                    <header class="flex flex-wrap items-baseline justify-between gap-2">
                        <p class="text-xs font-semibold text-gray-500 dark:text-gray-400">
                            <time datetime="{{ $entree->occurred_on?->toDateString() }}">
                                {{ $entree->occurred_on?->translatedFormat('d/m/Y') }}
                            </time>
                            @if ($entree->author)
                                · {{ __('loops.cards.journal.by', ['name' => $entree->author->first_name ?? $entree->author->name]) }}
                            @endif
                        </p>

                        <div class="flex shrink-0 items-center gap-1.5">
                            @if ($entree->sourceType() === 'message')
                                <span class="rounded-full bg-sky-100 px-2 py-0.5 text-[10px] font-semibold text-sky-700 dark:bg-sky-500/20 dark:text-sky-300">
                                    {{ __('loops.cards.journal.promoted_note') }}
                                </span>
                            @endif

                            @if ($canWrite && ($canManage || $entree->author_id === auth()->id()))
                                {{-- Une entree promue n'a pas de texte a corriger : le sien
                                     est celui du message. --}}
                                @if ($entree->sourceType() !== 'message')
                                    <button type="button" wire:click="startEditing('{{ $entree->id }}')"
                                            class="rounded-lg p-1.5 text-gray-400 hover:bg-gray-100 hover:text-gray-700 dark:hover:bg-gray-700"
                                            aria-label="{{ __('loops.cards.journal.edit') }}">
                                        <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z"/></svg>
                                    </button>
                                @endif
                                <button type="button" wire:click="remove('{{ $entree->id }}')"
                                        wire:confirm="{{ __('loops.cards.journal.delete_confirm') }}"
                                        aria-label="{{ __('loops.cards.journal.remove', ['name' => Str::limit($entree->displayBody(), 40)]) }}"
                                        class="rounded-lg p-1.5 text-gray-400 hover:bg-rose-50 hover:text-rose-600 dark:hover:bg-rose-500/15">
                                    <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                                </button>
                            @endif
                        </div>
                    </header>

                    <p class="mt-2 whitespace-pre-line text-sm text-gray-800 dark:text-gray-200">{{ $entree->displayBody() }}</p>
                </li>
            @endforeach
        </ol>
    @endif

    @if ($canWrite)
        <div class="mt-4 space-y-2">
            @if ($showForm)
                <div class="space-y-2 rounded-xl border border-gray-200 p-3 dark:border-gray-700">
                    <label class="block">
                        <span class="text-xs font-medium text-gray-700 dark:text-gray-300">{{ __('loops.cards.journal.body_label') }}</span>
                        <textarea wire:model="body" rows="3" class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-800"></textarea>
                    </label>
                    @error('body') <p class="text-xs text-rose-600">{{ $message }}</p> @enderror
                    <label class="block">
                        <span class="text-xs font-medium text-gray-700 dark:text-gray-300">{{ __('loops.cards.journal.date_label') }}</span>
                        <input type="date" wire:model="occurredOn" class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-800" />
                    </label>
                    @error('occurredOn') <p class="text-xs text-rose-600">{{ $message }}</p> @enderror
                    <div class="flex gap-2">
                        <button type="button" wire:click="save" class="rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-indigo-700">
                            {{ __('loops.cards.journal.save') }}
                        </button>
                        {{-- `cancel()` et non `$set('showForm', false)` : celui-ci
                             laissait `editingId` pose, et la note suivante
                             ecrasait celle qu'on venait de renoncer a corriger. --}}
                        <button type="button" wire:click="cancel" class="rounded-lg px-3 py-1.5 text-xs text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700">
                            {{ __('loops.cards.journal.cancel') }}
                        </button>
                    </div>
                </div>
            @else
                <div class="flex flex-wrap gap-2">
                    <button type="button" wire:click="$set('showForm', true)"
                            class="flex-1 rounded-xl border border-dashed border-gray-300 px-4 py-2.5 text-sm font-semibold text-gray-600 hover:border-indigo-400 hover:text-indigo-600 dark:border-gray-600 dark:text-gray-300">
                        + {{ __('loops.cards.journal.add') }}
                    </button>
                    <button type="button" wire:click="$toggle('showPicker')"
                            class="rounded-xl border border-gray-300 px-4 py-2.5 text-sm font-semibold text-gray-600 hover:border-indigo-400 hover:text-indigo-600 dark:border-gray-600 dark:text-gray-300">
                        {{ __('loops.cards.journal.promote') }}
                    </button>
                </div>
            @endif

            {{-- Garder un message du ChatLoop : c'est le geste que le North Star
                 decrit — « une Interaction peut devenir une entree de Journal
                 apres validation humaine ». La validation est ce clic. --}}
            @if ($showPicker)
                <div class="rounded-xl bg-gray-50 p-3 dark:bg-gray-900/50">
                    <p class="text-xs font-semibold text-gray-700 dark:text-gray-300">{{ __('loops.cards.journal.promote_pick') }}</p>

                    @if ($promotable->isEmpty())
                        <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ __('loops.cards.journal.no_message') }}</p>
                    @else
                        <ul class="mt-2 space-y-1.5">
                            @foreach ($promotable as $message)
                                <li>
                                    <button type="button" wire:click="promote('{{ $message->id }}')"
                                            class="flex w-full items-center gap-2 rounded-lg bg-white px-2.5 py-2 text-left text-sm text-gray-800 hover:bg-indigo-50 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-indigo-500/15">
                                        <span class="min-w-0 flex-1 truncate">{{ $message->body }}</span>
                                        <span class="shrink-0 text-[10px] text-gray-500 dark:text-gray-400">
                                            {{ $message->sender?->first_name ?? $message->sender?->name }}
                                        </span>
                                    </button>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            @endif
        </div>
    @endif

@endif
</div>
