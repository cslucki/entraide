{{--
    Le Support de cours : des Modules, et dans chacun des Sequences.

    Les numeros sont **calcules** par `numberedSequences()` et affiches tels
    quels. Rien ici ne les ecrit, et aucun titre n'est prefixe : un numero
    recopie devient faux au premier deplacement.

    Le classement se fait par des boutons nommes, atteignables au clavier. Il
    n'y a pas de glissement dans cette Card, et c'est deliberé : le panneau
    lateral du workspace est etroit, et un chemin unique qui marche partout vaut
    mieux qu'un chemin confortable double d'un repli.
--}}
<div class="flex h-full flex-col" data-loop-course-material>

@if (! $canView)
    {{-- Sans droit de lecture, rien du contenu n'est rendu. Le filtre de la
         grille s'applique au montage ; celui-ci s'applique a chaque rendu. --}}
    <p class="px-4 py-10 text-center text-sm text-gray-500 dark:text-gray-400">
        {{ __('loops.cards.course_material.no_access') }}
    </p>
@else

    @if ($flash)
        <p class="mb-3 rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-800 dark:bg-emerald-500/15 dark:text-emerald-200"
           role="status" aria-live="polite">{{ $flash }}</p>
    @endif

    @if ($modules->isEmpty())
        <div class="rounded-2xl border border-dashed border-gray-300 px-5 py-10 text-center dark:border-gray-600">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">
                {{ __('loops.cards.course_material.empty_title') }}
            </h3>
            <p class="mx-auto mt-2 max-w-sm text-sm text-gray-500 dark:text-gray-400">
                {{ __('loops.cards.course_material.empty_body') }}
            </p>
        </div>
    @else
        <div class="space-y-4">
            @foreach ($modules as $index => $module)
                <section class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800"
                         aria-labelledby="module-{{ $module->id }}-title">
                    <header class="mb-3 flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <h3 id="module-{{ $module->id }}-title" class="flex items-baseline gap-2 text-sm font-semibold text-gray-900 dark:text-gray-100">
                                <span class="font-mono text-xs tabular-nums text-gray-400" aria-hidden="true">{{ str_pad($index + 1, 2, '0', STR_PAD_LEFT) }}</span>
                                <span class="truncate">{{ $module->title }}</span>
                            </h3>
                            @if ($module->summary)
                                <p class="mt-1 truncate text-xs text-gray-500 dark:text-gray-400">{{ $module->summary }}</p>
                            @endif
                        </div>

                        @if ($canManage)
                            <div class="flex shrink-0 items-center gap-1">
                                <button type="button" wire:click="moveModule('{{ $module->id }}', -1)" @disabled($index === 0)
                                        class="rounded-lg p-1.5 text-gray-400 hover:bg-gray-100 hover:text-gray-700 disabled:opacity-30 dark:hover:bg-gray-700"
                                        aria-label="{{ __('loops.cards.course_material.move_up', ['name' => $module->title]) }}">
                                    <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 15.75l7.5-7.5 7.5 7.5"/></svg>
                                </button>
                                <button type="button" wire:click="moveModule('{{ $module->id }}', 1)" @disabled($index === $modules->count() - 1)
                                        class="rounded-lg p-1.5 text-gray-400 hover:bg-gray-100 hover:text-gray-700 disabled:opacity-30 dark:hover:bg-gray-700"
                                        aria-label="{{ __('loops.cards.course_material.move_down', ['name' => $module->title]) }}">
                                    <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5"/></svg>
                                </button>
                                <button type="button" wire:click="startEditingModule('{{ $module->id }}')"
                                        class="rounded-lg p-1.5 text-gray-400 hover:bg-gray-100 hover:text-gray-700 dark:hover:bg-gray-700"
                                        aria-label="{{ __('loops.cards.course_material.module_edit', ['name' => $module->title]) }}">
                                    <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z"/></svg>
                                </button>
                                <button type="button" wire:click="deleteModule('{{ $module->id }}')"
                                        wire:confirm="{{ __('loops.cards.course_material.module_delete_confirm') }}"
                                        class="rounded-lg p-1.5 text-gray-400 hover:bg-rose-50 hover:text-rose-600 dark:hover:bg-rose-500/15">
                                    <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                                </button>
                            </div>
                        @endif
                    </header>

                    @if ($canManage && $editingModuleId === $module->id)
                        <div class="mb-3 space-y-2 rounded-xl bg-gray-50 p-3 dark:bg-gray-900/50">
                            <label class="block">
                                <span class="text-xs font-medium text-gray-700 dark:text-gray-300">{{ __('loops.cards.course_material.module_title') }}</span>
                                <input type="text" wire:model="moduleTitle" class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-800" />
                            </label>
                            @error('moduleTitle') <p class="text-xs text-rose-600">{{ $message }}</p> @enderror
                            <label class="block">
                                <span class="text-xs font-medium text-gray-700 dark:text-gray-300">{{ __('loops.cards.course_material.module_summary') }}</span>
                                <input type="text" wire:model="moduleSummary" class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-800" />
                            </label>
                            <div class="flex gap-2">
                                <button type="button" wire:click="saveModule"
                                        class="rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-indigo-700">
                                    {{ __('loops.cards.course_material.module_save') }}
                                </button>
                                <button type="button" wire:click="$set('editingModuleId', null)"
                                        class="rounded-lg px-3 py-1.5 text-xs text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700">
                                    {{ __('loops.cards.course_material.cancel') }}
                                </button>
                            </div>
                        </div>
                    @endif

                    @php $sequences = $module->numberedSequences(); @endphp

                    @if ($sequences->isEmpty())
                        <p class="px-2 py-4 text-center text-xs text-gray-500 dark:text-gray-400">
                            {{ __('loops.cards.course_material.module_empty') }}
                        </p>
                    @else
                        <ol class="space-y-1.5">
                            {{-- Les bornes viennent du rang calcule, **pas** de
                                 `$loop->first` / `$loop->last` : dans un
                                 `@foreach`, Blade installe son propre `$loop`
                                 et masque la propriete `$loop` du composant,
                                 qui est la Boucle. Le rang, lui, ne depend de
                                 rien d'autre que de la Sequence. --}}
                            @foreach ($sequences as $entry)
                                @php $sequence = $entry['sequence']; @endphp
                                <li class="rounded-lg border border-gray-100 px-2.5 py-2 dark:border-gray-700">
                                    <div class="flex items-center gap-2.5">
                                    <span class="shrink-0 font-mono text-[11px] tabular-nums text-gray-400" aria-hidden="true">{{ $entry['number'] }}</span>

                                    <x-loops.card-icon :icon="$sequence->contentType() === 'file' ? 'folder' : 'document'" size="sm" />

                                    @if (filled($sequence->body))
                                        {{-- Le texte est saisi ici : il doit se relire ici. --}}
                                        <button type="button" wire:click="toggleSequence('{{ $sequence->id }}')"
                                                class="min-w-0 flex-1 truncate text-left text-sm text-gray-800 hover:text-indigo-600 dark:text-gray-200 dark:hover:text-indigo-400"
                                                aria-expanded="{{ $openSequenceId === $sequence->id ? 'true' : 'false' }}"
                                                aria-controls="sequence-{{ $sequence->id }}-body">
                                            <span class="sr-only">{{ __('dossiers.series_position_label', ['number' => $entry['rank']]) }} —</span>{{ $sequence->displayName() }}
                                        </button>
                                    @else
                                        <span class="min-w-0 flex-1 truncate text-sm text-gray-800 dark:text-gray-200">
                                            <span class="sr-only">{{ __('dossiers.series_position_label', ['number' => $entry['rank']]) }} —</span>{{ $sequence->displayName() }}
                                        </span>
                                    @endif

                                    @if ($canManage)
                                        <span class="flex shrink-0 items-center gap-0.5">
                                            <button type="button" wire:click="moveSequence('{{ $sequence->id }}', -1)" @disabled($entry['rank'] === 1)
                                                    class="rounded p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-700 disabled:opacity-30 dark:hover:bg-gray-700"
                                                    aria-label="{{ __('loops.cards.course_material.move_up', ['name' => $sequence->displayName()]) }}">
                                                <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 15.75l7.5-7.5 7.5 7.5"/></svg>
                                            </button>
                                            <button type="button" wire:click="moveSequence('{{ $sequence->id }}', 1)" @disabled($entry['rank'] === $sequences->count())
                                                    class="rounded p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-700 disabled:opacity-30 dark:hover:bg-gray-700"
                                                    aria-label="{{ __('loops.cards.course_material.move_down', ['name' => $sequence->displayName()]) }}">
                                                <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5"/></svg>
                                            </button>
                                            <button type="button" wire:click="deleteSequence('{{ $sequence->id }}')"
                                                    wire:confirm="{{ __('loops.cards.course_material.sequence_delete_confirm') }}"
                                                    class="rounded p-1 text-gray-400 hover:bg-rose-50 hover:text-rose-600 dark:hover:bg-rose-500/15">
                                                <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                                            </button>
                                        </span>
                                    @endif
                                    </div>

                                    @if ($openSequenceId === $sequence->id && filled($sequence->body))
                                        {{-- Dans le meme `<li>` que sa Sequence : une `<ol>` ne
                                             doit compter que des Sequences, sinon un lecteur
                                             d'ecran annonce deux fois plus d'elements qu'il n'y
                                             en a. --}}
                                        <div id="sequence-{{ $sequence->id }}-body"
                                             class="mt-1.5 whitespace-pre-line rounded-lg bg-gray-50 px-3 py-2.5 text-sm text-gray-700 dark:bg-gray-900/50 dark:text-gray-300">
                                            {{ $sequence->body }}
                                        </div>
                                    @endif
                                </li>
                            @endforeach
                        </ol>
                    @endif

                    @if ($canManage)
                        @if ($openSequenceFormFor === $module->id)
                            <div class="mt-3 space-y-2 rounded-xl bg-gray-50 p-3 dark:bg-gray-900/50">
                                <label class="block">
                                    <span class="text-xs font-medium text-gray-700 dark:text-gray-300">{{ __('loops.cards.course_material.sequence_title') }}</span>
                                    <input type="text" wire:model="sequenceTitle" class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-800" />
                                </label>
                                @error('sequenceTitle') <p class="text-xs text-rose-600">{{ $message }}</p> @enderror
                                <label class="block">
                                    <span class="text-xs font-medium text-gray-700 dark:text-gray-300">{{ __('loops.cards.course_material.sequence_body') }}</span>
                                    <textarea wire:model="sequenceBody" rows="3" class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-800"></textarea>
                                </label>
                                <div class="flex gap-2">
                                    <button type="button" wire:click="addSequence('{{ $module->id }}')"
                                            class="rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-indigo-700">
                                        {{ __('loops.cards.course_material.sequence_add') }}
                                    </button>
                                    <button type="button" wire:click="$set('openSequenceFormFor', null)"
                                            class="rounded-lg px-3 py-1.5 text-xs text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700">
                                        {{ __('loops.cards.course_material.cancel') }}
                                    </button>
                                </div>
                            </div>
                        @else
                            <button type="button" wire:click="$set('openSequenceFormFor', '{{ $module->id }}')"
                                    class="mt-3 text-xs font-semibold text-indigo-600 hover:text-indigo-700 dark:text-indigo-400">
                                + {{ __('loops.cards.course_material.sequence_add') }}
                            </button>
                        @endif
                    @endif
                </section>
            @endforeach
        </div>
    @endif

    @if ($canManage)
        <div class="mt-4">
            @if ($showModuleForm)
                <div class="space-y-2 rounded-xl border border-gray-200 p-3 dark:border-gray-700">
                    <label class="block">
                        <span class="text-xs font-medium text-gray-700 dark:text-gray-300">{{ __('loops.cards.course_material.module_title') }}</span>
                        <input type="text" wire:model="moduleTitle" class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-800" />
                    </label>
                    @error('moduleTitle') <p class="text-xs text-rose-600">{{ $message }}</p> @enderror
                    <label class="block">
                        <span class="text-xs font-medium text-gray-700 dark:text-gray-300">{{ __('loops.cards.course_material.module_summary') }}</span>
                        <input type="text" wire:model="moduleSummary" class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-800" />
                    </label>
                    <div class="flex gap-2">
                        <button type="button" wire:click="createModule"
                                class="rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-indigo-700">
                            {{ __('loops.cards.course_material.module_add') }}
                        </button>
                        <button type="button" wire:click="$set('showModuleForm', false)"
                                class="rounded-lg px-3 py-1.5 text-xs text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700">
                            {{ __('loops.cards.course_material.cancel') }}
                        </button>
                    </div>
                </div>
            @else
                <button type="button" wire:click="$set('showModuleForm', true)"
                        class="w-full rounded-xl border border-dashed border-gray-300 px-4 py-2.5 text-sm font-semibold text-gray-600 hover:border-indigo-400 hover:text-indigo-600 dark:border-gray-600 dark:text-gray-300">
                    + {{ __('loops.cards.course_material.module_add') }}
                </button>
            @endif
        </div>
    @endif
@endif
</div>
