{{--
    Les Travaux a rendre : une seule Card, deux visages.

    Chaque geste du composant a un chemin ici. C'est la regle apprise sur les
    Cards precedentes : une capacite dont la permission existe, dont la methode
    existe et dont seul le refus est teste **n'est pas livree**.
--}}
@php
    $tons = [
        'draft' => 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300',
        'submitted' => 'bg-violet-100 text-violet-700 dark:bg-violet-500/20 dark:text-violet-300',
        'validated' => 'bg-emerald-600 text-white dark:bg-emerald-500',
        'redo' => 'bg-rose-100 text-rose-700 dark:bg-rose-500/20 dark:text-rose-300',
    ];
@endphp

<div class="flex h-full flex-col" data-loop-assignments>

@if (! $canView)
    <p class="px-4 py-10 text-center text-sm text-gray-500 dark:text-gray-400">
        {{ __('loops.cards.assignments.no_access') }}
    </p>
@else

    @if ($flash)
        <p class="mb-3 rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-800 dark:bg-emerald-500/15 dark:text-emerald-200"
           role="status" aria-live="polite">{{ $flash }}</p>
    @endif

    @if ($canManage)
        <div class="mb-4 inline-flex rounded-xl bg-gray-100 p-0.5 dark:bg-gray-700" role="tablist">
            <button type="button" wire:click="$set('face', 'mine')" role="tab"
                    aria-selected="{{ $face === 'mine' ? 'true' : 'false' }}"
                    @class(['rounded-lg px-3 py-1.5 text-xs font-semibold transition-colors',
                        'bg-white text-gray-900 shadow-sm dark:bg-gray-800 dark:text-gray-100' => $face === 'mine',
                        'text-gray-600 dark:text-gray-300' => $face !== 'mine'])>
                {{ __('loops.cards.assignments.my_assignments') }}
            </button>
            <button type="button" wire:click="$set('face', 'everyone')" role="tab"
                    aria-selected="{{ $face === 'everyone' ? 'true' : 'false' }}"
                    @class(['rounded-lg px-3 py-1.5 text-xs font-semibold transition-colors',
                        'bg-white text-gray-900 shadow-sm dark:bg-gray-800 dark:text-gray-100' => $face === 'everyone',
                        'text-gray-600 dark:text-gray-300' => $face !== 'everyone'])>
                {{ __('loops.cards.assignments.everyone') }}
            </button>
        </div>
    @endif

    {{-- ── Visage 1 : mes Travaux ─────────────────────────────────────── --}}
    @if ($face === 'mine')
        @if ($overview->isEmpty())
            <div class="rounded-2xl border border-dashed border-gray-300 px-5 py-10 text-center dark:border-gray-600">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ __('loops.cards.assignments.empty_title') }}</h3>
                <p class="mx-auto mt-2 max-w-sm text-sm text-gray-500 dark:text-gray-400">{{ __('loops.cards.assignments.empty_body') }}</p>
            </div>
        @else
            <div class="space-y-3">
                @foreach ($overview as $entree)
                    @php $travail = $entree['assignment']; $etat = $entree['status']; @endphp
                    <section class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800"
                             aria-labelledby="assignment-{{ $travail->id }}">
                        <header class="flex flex-wrap items-start justify-between gap-2">
                            <div class="min-w-0">
                                <h3 id="assignment-{{ $travail->id }}" class="truncate text-sm font-semibold text-gray-900 dark:text-gray-100">
                                    {{ $travail->title }}
                                </h3>
                                <p class="mt-0.5 text-xs {{ $entree['overdue'] ? 'text-rose-600 dark:text-rose-400' : 'text-gray-500 dark:text-gray-400' }}">
                                    @if ($travail->due_at)
                                        {{ __('loops.cards.assignments.due_on', ['date' => $travail->due_at->translatedFormat('d/m/Y H:i')]) }}
                                        @if ($entree['overdue']) — {{ __('loops.cards.assignments.overdue') }} @endif
                                    @else
                                        {{ __('loops.cards.assignments.no_due') }}
                                    @endif
                                </p>
                            </div>

                            <div class="flex shrink-0 items-center gap-2">
                                <span class="rounded-full px-2 py-0.5 text-[11px] font-semibold {{ $tons[$etat] ?? $tons['draft'] }}">
                                    {{ __('loops.cards.assignments.status_'.$etat) }}
                                </span>

                                @if ($canManage)
                                    <button type="button" wire:click="startEditing('{{ $travail->id }}')"
                                            class="rounded-lg p-1.5 text-gray-400 hover:bg-gray-100 hover:text-gray-700 dark:hover:bg-gray-700"
                                            aria-label="{{ __('loops.cards.assignments.edit', ['name' => $travail->title]) }}">
                                        <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z"/></svg>
                                    </button>
                                    <button type="button" wire:click="deleteAssignment('{{ $travail->id }}')"
                                            wire:confirm="{{ __('loops.cards.assignments.delete_confirm') }}"
                                            class="rounded-lg p-1.5 text-gray-400 hover:bg-rose-50 hover:text-rose-600 dark:hover:bg-rose-500/15">
                                        <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                                    </button>
                                @endif
                            </div>
                        </header>

                        @if ($travail->brief)
                            <p class="mt-2 whitespace-pre-line text-sm text-gray-700 dark:text-gray-300">{{ $travail->brief }}</p>
                        @endif

                        @if ($entree['submission']?->feedback)
                            <p class="mt-2 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900 dark:bg-amber-500/15 dark:text-amber-200">
                                {{ $entree['submission']->feedback }}
                            </p>
                        @endif

                        @if ($openAssignmentId === $travail->id)
                            <div class="mt-3 space-y-2">
                                <label class="block">
                                    <span class="text-xs font-medium text-gray-700 dark:text-gray-300">{{ __('loops.cards.assignments.my_body') }}</span>
                                    <textarea wire:model="body" rows="4" class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-800"></textarea>
                                </label>
                                <div class="flex flex-wrap gap-2">
                                    <button type="button" wire:click="submit('{{ $travail->id }}')"
                                            class="rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-indigo-700">
                                        {{ __('loops.cards.assignments.submit') }}
                                    </button>
                                    <button type="button" wire:click="saveDraft('{{ $travail->id }}')"
                                            class="rounded-lg border border-gray-300 px-3 py-1.5 text-xs font-semibold text-gray-700 hover:border-indigo-400 dark:border-gray-600 dark:text-gray-300">
                                        {{ __('loops.cards.assignments.save_draft') }}
                                    </button>
                                    <button type="button" wire:click="openAssignment('{{ $travail->id }}')"
                                            class="rounded-lg px-3 py-1.5 text-xs text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700">
                                        {{ __('loops.cards.assignments.cancel') }}
                                    </button>
                                </div>
                            </div>
                        @elseif ($etat !== 'validated')
                            <button type="button" wire:click="openAssignment('{{ $travail->id }}')"
                                    class="mt-3 text-xs font-semibold text-indigo-600 hover:text-indigo-700 dark:text-indigo-400">
                                {{ $etat === 'draft' ? __('loops.cards.assignments.submit') : __('loops.cards.assignments.my_body') }}
                            </button>
                        @endif
                    </section>
                @endforeach
            </div>
        @endif

        @if ($canManage)
            <div class="mt-4">
                @if ($showForm)
                    <div class="space-y-2 rounded-xl border border-gray-200 p-3 dark:border-gray-700">
                        <label class="block">
                            <span class="text-xs font-medium text-gray-700 dark:text-gray-300">{{ __('loops.cards.assignments.title_label') }}</span>
                            <input type="text" wire:model="title" class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-800" />
                        </label>
                        @error('title') <p class="text-xs text-rose-600">{{ $message }}</p> @enderror
                        <label class="block">
                            <span class="text-xs font-medium text-gray-700 dark:text-gray-300">{{ __('loops.cards.assignments.brief_label') }}</span>
                            <textarea wire:model="brief" rows="3" class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-800"></textarea>
                        </label>
                        <label class="block">
                            <span class="text-xs font-medium text-gray-700 dark:text-gray-300">{{ __('loops.cards.assignments.due_label') }}</span>
                            <input type="datetime-local" wire:model="dueAt" class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-800" />
                        </label>
                        <div class="flex gap-2">
                            <button type="button" wire:click="saveAssignment"
                                    class="rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-indigo-700">
                                {{ __('loops.cards.assignments.save') }}
                            </button>
                            <button type="button" wire:click="$set('showForm', false)"
                                    class="rounded-lg px-3 py-1.5 text-xs text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700">
                                {{ __('loops.cards.assignments.cancel') }}
                            </button>
                        </div>
                    </div>
                @else
                    <button type="button" wire:click="$set('showForm', true)"
                            class="w-full rounded-xl border border-dashed border-gray-300 px-4 py-2.5 text-sm font-semibold text-gray-600 hover:border-indigo-400 hover:text-indigo-600 dark:border-gray-600 dark:text-gray-300">
                        + {{ __('loops.cards.assignments.add') }}
                    </button>
                @endif
            </div>
        @endif
    @endif

    {{-- ── Visage 2 : tout le monde ───────────────────────────────────── --}}
    @if ($face === 'everyone' && $canManage)
        @if ($matrix->isEmpty())
            <p class="px-4 py-10 text-center text-sm text-gray-500 dark:text-gray-400">{{ __('loops.cards.assignments.no_members') }}</p>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <caption class="sr-only">{{ __('loops.cards.assignments.everyone') }}</caption>
                    <thead>
                        <tr class="border-b border-gray-200 dark:border-gray-700">
                            <th scope="col" class="py-2 pr-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400">{{ __('loops.cards.members.label') }}</th>
                            @foreach ($matrix->first()['cells'] as $colonne)
                                <th scope="col" class="px-2 py-2 text-left text-xs font-semibold text-gray-500 dark:text-gray-400">
                                    <span class="block max-w-[8rem] truncate">{{ $colonne['assignment']->title }}</span>
                                </th>
                            @endforeach
                            <th scope="col" class="px-2 py-2 text-left text-xs font-semibold text-gray-500 dark:text-gray-400">{{ __('loops.cards.assignments.status_submitted') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($matrix as $ligne)
                            <tr class="border-b border-gray-100 dark:border-gray-700/60">
                                <th scope="row" class="py-2 pr-3 text-left font-medium text-gray-800 dark:text-gray-200">
                                    <span class="block max-w-[10rem] truncate">{{ $ligne['user']->first_name ?? $ligne['user']->name }}</span>
                                </th>
                                @foreach ($ligne['cells'] as $cellule)
                                    <td class="px-2 py-2">
                                        <button type="button"
                                                wire:click="toggleReview('{{ $cellule['assignment']->id }}', '{{ $ligne['user']->id }}')"
                                                aria-expanded="{{ $reviewAssignmentId === $cellule['assignment']->id && $reviewUserId === $ligne['user']->id ? 'true' : 'false' }}"
                                                class="inline-block rounded-full px-2 py-0.5 text-[10px] font-semibold transition-opacity hover:opacity-80 {{ $tons[$cellule['status']] ?? $tons['draft'] }}">
                                            {{ __('loops.cards.assignments.status_'.$cellule['status']) }}
                                        </button>
                                    </td>
                                @endforeach
                                <td class="px-2 py-2 text-xs text-gray-600 dark:text-gray-300">
                                    {{ trans_choice('loops.cards.assignments.awaiting', $ligne['awaiting'], ['count' => $ligne['awaiting']]) }}
                                </td>
                            </tr>

                            @if ($reviewUserId === $ligne['user']->id && $reviewAssignmentId)
                                @php $cible = $ligne['cells']->firstWhere(fn ($c) => $c['assignment']->id === $reviewAssignmentId); @endphp
                                @if ($cible)
                                    <tr class="bg-gray-50 dark:bg-gray-900/40">
                                        <td colspan="{{ $ligne['cells']->count() + 2 }}" class="px-3 py-3">
                                            <p class="text-xs font-semibold text-gray-700 dark:text-gray-300">{{ $cible['assignment']->title }}</p>

                                            @if ($cible['submission']?->body)
                                                <p class="mt-1.5 whitespace-pre-line rounded-lg bg-white px-3 py-2 text-sm text-gray-800 dark:bg-gray-800 dark:text-gray-200">
                                                    {{ $cible['submission']->body }}
                                                </p>
                                            @else
                                                <p class="mt-1.5 text-sm text-gray-500 dark:text-gray-400">{{ __('loops.cards.assignments.status_draft') }}</p>
                                            @endif

                                            <label class="mt-2 block">
                                                <span class="text-xs font-medium text-gray-700 dark:text-gray-300">{{ __('loops.cards.assignments.feedback_label') }}</span>
                                                <textarea wire:model="feedback" rows="2" class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-800"></textarea>
                                            </label>

                                            <div class="mt-2 flex flex-wrap gap-2">
                                                <button type="button" wire:click="validateSubmission('{{ $cible['assignment']->id }}', '{{ $ligne['user']->id }}')"
                                                        class="rounded-lg bg-emerald-600 px-3 py-1.5 text-[11px] font-semibold text-white hover:bg-emerald-700">
                                                    {{ __('loops.cards.assignments.validate') }}
                                                </button>
                                                <button type="button" wire:click="requestRedo('{{ $cible['assignment']->id }}', '{{ $ligne['user']->id }}')"
                                                        class="rounded-lg border border-rose-300 px-3 py-1.5 text-[11px] font-semibold text-rose-700 hover:bg-rose-50 dark:border-rose-500/40 dark:text-rose-300">
                                                    {{ __('loops.cards.assignments.request_redo') }}
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                @endif
                            @endif
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    @endif

@endif
</div>
