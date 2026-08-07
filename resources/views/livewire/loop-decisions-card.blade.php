{{--
    Les Décisions : ce qui a été tranché, quand, et pourquoi.

    Une Décision promue **reprend** le message, elle ne le copie pas : son texte
    est lu sur le message à chaque affichage. Corriger le message corrige la
    Décision.

    Une Décision remplacée **reste lisible**, barrée et renvoyant vers celle qui
    fait foi. C'est une mémoire, pas un état courant.

    Chaque geste du composant a un chemin ici — c'est l'invariant « capacité =
    backend + chemin utilisateur ».
--}}
<div class="flex h-full flex-col" data-loop-decisions>

@if (! $canView)
    <p class="px-4 py-10 text-center text-sm text-gray-500 dark:text-gray-400">
        {{ __('loops.cards.decisions.no_access') }}
    </p>
@else

    @if ($problem)
        <p class="mb-3 rounded-lg bg-rose-50 px-3 py-2 text-sm text-rose-800 dark:bg-rose-500/15 dark:text-rose-200" role="alert">{{ $problem }}</p>
    @elseif ($flash)
        <p class="mb-3 rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-800 dark:bg-emerald-500/15 dark:text-emerald-200"
           role="status" aria-live="polite">{{ $flash }}</p>
    @endif

    @if ($decisions->isEmpty())
        <div class="rounded-2xl border border-dashed border-gray-300 px-5 py-10 text-center dark:border-gray-600">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ __('loops.cards.decisions.empty_title') }}</h3>
            <p class="mx-auto mt-2 max-w-sm text-sm text-gray-500 dark:text-gray-400">{{ __('loops.cards.decisions.empty_body') }}</p>
        </div>
    @else
        <ol class="space-y-3">
            @foreach ($decisions as $decision)
                <li class="rounded-2xl border p-4 {{ $decision->isSuperseded()
                        ? 'border-gray-200 bg-gray-50 dark:border-gray-700 dark:bg-gray-900/40'
                        : 'border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800' }}">

                    <header class="flex flex-wrap items-baseline justify-between gap-2">
                        <p class="text-xs font-semibold text-gray-500 dark:text-gray-400">
                            <time datetime="{{ $decision->decided_on?->toDateString() }}">
                                {{ $decision->decided_on?->translatedFormat('d/m/Y') }}
                            </time>
                            @if ($decision->author)
                                · {{ __('loops.cards.decisions.by', ['name' => $decision->author->first_name ?? $decision->author->name]) }}
                            @endif
                        </p>

                        <div class="flex shrink-0 items-center gap-1.5">
                            @if ($decision->isPromoted())
                                <span class="rounded-full bg-sky-100 px-2 py-0.5 text-[10px] font-semibold text-sky-700 dark:bg-sky-500/20 dark:text-sky-300">
                                    {{ __('loops.cards.decisions.promoted_note') }}
                                </span>
                            @endif

                            @if ($decision->isSuperseded())
                                <span class="rounded-full bg-gray-200 px-2 py-0.5 text-[10px] font-semibold text-gray-600 dark:bg-gray-700 dark:text-gray-300">
                                    {{ __('loops.cards.decisions.superseded_badge') }}
                                </span>
                            @endif

                            @if ($canRecord && ($canManage || $decision->author_id === auth()->id()))
                                {{-- Une Décision remplacée est de l'histoire : elle ne
                                     se corrige plus, et ne lance plus rien. --}}
                                @unless ($decision->isSuperseded())
                                    <button type="button" wire:click="startEditing('{{ $decision->id }}')"
                                            class="rounded-lg p-1.5 text-gray-400 hover:bg-gray-100 hover:text-gray-700 dark:hover:bg-gray-700"
                                            aria-label="{{ __('loops.cards.decisions.edit') }}">
                                        <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z"/></svg>
                                    </button>
                                @endunless

                                <button type="button" wire:click="remove('{{ $decision->id }}')"
                                        wire:confirm="{{ __('loops.cards.decisions.delete_confirm') }}"
                                        aria-label="{{ __('loops.cards.decisions.remove', ['name' => Str::limit($decision->title, 40)]) }}"
                                        class="rounded-lg p-1.5 text-gray-400 hover:bg-rose-50 hover:text-rose-600 dark:hover:bg-rose-500/15">
                                    <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                                </button>
                            @endif
                        </div>
                    </header>

                    <p class="mt-2 text-sm font-semibold {{ $decision->isSuperseded()
                            ? 'text-gray-500 line-through dark:text-gray-400'
                            : 'text-gray-900 dark:text-gray-100' }}">{{ $decision->title }}</p>

                    @if ($decision->rationale)
                        <p class="mt-1 whitespace-pre-line text-sm text-gray-700 dark:text-gray-300">{{ $decision->rationale }}</p>
                    @endif

                    @if ($decision->isPromoted())
                        <blockquote class="mt-2 border-l-2 border-sky-300 pl-3 text-xs italic text-gray-600 dark:border-sky-700 dark:text-gray-400">
                            {{ $decision->displayMessage() }}
                        </blockquote>
                    @endif

                    @if ($decision->isSuperseded() && $decision->supersededBy)
                        <p class="mt-2 text-xs font-medium text-gray-500 dark:text-gray-400">
                            {{ __('loops.cards.decisions.superseded_by', ['title' => Str::limit($decision->supersededBy->title, 60)]) }}
                        </p>
                    @endif

                    {{-- Les actions engagées. « Une décision n'est pas transformée
                         en action » est la perte que cette Card évite. --}}
                    @if ($decision->actions->isNotEmpty())
                        <div class="mt-3 rounded-xl bg-gray-50 px-3 py-2 dark:bg-gray-900/50">
                            <p class="text-[11px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                {{ __('loops.cards.decisions.actions_title') }}
                            </p>
                            <ul class="mt-1 space-y-0.5">
                                @foreach ($decision->actions as $action)
                                    <li class="text-xs text-gray-700 dark:text-gray-300">· {{ $action->title }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    @if ($canRecord && ! $decision->isSuperseded() && ($canManage || $decision->author_id === auth()->id()))
                        <div class="mt-3 flex flex-wrap gap-2">
                            @if ($canAct)
                                <button type="button" wire:click="startAction('{{ $decision->id }}')"
                                        class="rounded-lg border border-gray-300 px-2.5 py-1 text-xs font-semibold text-gray-600 hover:border-indigo-400 hover:text-indigo-600 dark:border-gray-600 dark:text-gray-300">
                                    {{ __('loops.cards.decisions.start_action') }}
                                </button>
                            @endif

                            <button type="button" wire:click="startSuperseding('{{ $decision->id }}')"
                                    class="rounded-lg border border-gray-300 px-2.5 py-1 text-xs font-semibold text-gray-600 hover:border-amber-400 hover:text-amber-600 dark:border-gray-600 dark:text-gray-300">
                                {{ __('loops.cards.decisions.supersede') }}
                            </button>
                        </div>
                    @endif

                    {{-- La saisie de l'action, sous la Décision qu'elle sert. --}}
                    @if ($actionForId === $decision->id)
                        <div class="mt-2 space-y-2 rounded-xl border border-gray-200 p-3 dark:border-gray-700">
                            <label class="block">
                                <span class="text-xs font-medium text-gray-700 dark:text-gray-300">{{ __('loops.cards.decisions.action_placeholder') }}</span>
                                <input type="text" wire:model="actionTitle" class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-800" />
                            </label>
                            @error('actionTitle') <p class="text-xs text-rose-600">{{ $message }}</p> @enderror
                            <div class="flex gap-2">
                                <button type="button" wire:click="saveAction" class="rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-indigo-700">
                                    {{ __('loops.cards.decisions.save') }}
                                </button>
                                <button type="button" wire:click="cancel" class="rounded-lg px-3 py-1.5 text-xs text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700">
                                    {{ __('loops.cards.decisions.cancel') }}
                                </button>
                            </div>
                        </div>
                    @endif

                    {{-- Le choix de ce que cette Décision remplace. --}}
                    @if ($supersedingId === $decision->id)
                        <div class="mt-2 rounded-xl bg-amber-50 p-3 dark:bg-amber-500/10">
                            <p class="text-xs font-semibold text-amber-900 dark:text-amber-200">{{ __('loops.cards.decisions.supersede_pick') }}</p>

                            @if ($supersedable->isEmpty())
                                <p class="mt-2 text-xs text-gray-600 dark:text-gray-400">{{ __('loops.cards.decisions.nothing_to_supersede') }}</p>
                            @else
                                <ul class="mt-2 space-y-1.5">
                                    @foreach ($supersedable as $candidate)
                                        <li>
                                            <button type="button" wire:click="supersede('{{ $candidate->id }}')"
                                                    class="flex w-full items-center gap-2 rounded-lg bg-white px-2.5 py-2 text-left text-sm text-gray-800 hover:bg-amber-100 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-amber-500/20">
                                                <span class="min-w-0 flex-1 truncate">{{ $candidate->title }}</span>
                                                <span class="shrink-0 text-[10px] text-gray-500 dark:text-gray-400">{{ $candidate->decided_on?->translatedFormat('d/m/Y') }}</span>
                                            </button>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif

                            <button type="button" wire:click="cancel" class="mt-2 rounded-lg px-2 py-1 text-xs text-gray-600 hover:bg-white/60 dark:text-gray-300">
                                {{ __('loops.cards.decisions.cancel') }}
                            </button>
                        </div>
                    @endif
                </li>
            @endforeach
        </ol>
    @endif

    @if ($canRecord)
        <div class="mt-4 space-y-2">
            @if ($showForm)
                <div class="space-y-2 rounded-xl border border-gray-200 p-3 dark:border-gray-700">
                    <label class="block">
                        <span class="text-xs font-medium text-gray-700 dark:text-gray-300">{{ __('loops.cards.decisions.title_label') }}</span>
                        <input type="text" wire:model="title" class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-800" />
                    </label>
                    @error('title') <p class="text-xs text-rose-600">{{ $message }}</p> @enderror

                    <label class="block">
                        <span class="text-xs font-medium text-gray-700 dark:text-gray-300">{{ __('loops.cards.decisions.rationale_label') }}</span>
                        <textarea wire:model="rationale" rows="2" class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-800"></textarea>
                    </label>

                    <label class="block">
                        <span class="text-xs font-medium text-gray-700 dark:text-gray-300">{{ __('loops.cards.decisions.date_label') }}</span>
                        <input type="date" wire:model="decidedOn" class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-800" />
                    </label>
                    @error('decidedOn') <p class="text-xs text-rose-600">{{ $message }}</p> @enderror

                    <div class="flex flex-wrap gap-2">
                        <button type="button" wire:click="save" class="rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-indigo-700">
                            {{ __('loops.cards.decisions.save') }}
                        </button>

                        {{-- Garder un message du ChatLoop : le North Star le décrit —
                             « une Interaction peut devenir […] une décision ». Le
                             titre est saisi d'abord ; le message vient l'appuyer. --}}
                        @unless ($editingId)
                            <button type="button" wire:click="$toggle('showPicker')"
                                    class="rounded-lg border border-gray-300 px-3 py-1.5 text-xs font-semibold text-gray-600 hover:border-indigo-400 hover:text-indigo-600 dark:border-gray-600 dark:text-gray-300">
                                {{ __('loops.cards.decisions.promote') }}
                            </button>
                        @endunless

                        {{-- `cancel()` et non `$set('showForm', false)` : celui-ci
                             laisserait `editingId` posé, et la Décision suivante
                             écraserait celle qu'on venait de renoncer à corriger. --}}
                        <button type="button" wire:click="cancel" class="rounded-lg px-3 py-1.5 text-xs text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700">
                            {{ __('loops.cards.decisions.cancel') }}
                        </button>
                    </div>

                    @if ($showPicker)
                        <div class="rounded-xl bg-gray-50 p-3 dark:bg-gray-900/50">
                            <p class="text-xs font-semibold text-gray-700 dark:text-gray-300">{{ __('loops.cards.decisions.promote_pick') }}</p>

                            @if ($promotable->isEmpty())
                                <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ __('loops.cards.decisions.no_message') }}</p>
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
            @else
                <button type="button" wire:click="$set('showForm', true)"
                        class="w-full rounded-xl border border-dashed border-gray-300 px-4 py-2.5 text-sm font-semibold text-gray-600 hover:border-indigo-400 hover:text-indigo-600 dark:border-gray-600 dark:text-gray-300">
                    + {{ __('loops.cards.decisions.add') }}
                </button>
            @endif
        </div>
    @endif

@endif
</div>
