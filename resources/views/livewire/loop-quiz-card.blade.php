{{--
    Les QCM : une seule Card, deux visages.

    Le **score est toujours immediat** — c'est ce que tout le monde attend. Le
    detail des bonnes reponses suit le reglage du QCM : immediat, apres la
    derniere tentative, ou quand le formateur l'ouvre. Le detail immediat
    rendrait les tentatives multiples sans objet, et circulerait d'un stagiaire
    a l'autre.

    Chaque geste du composant a un chemin ici.
--}}
<div class="flex h-full flex-col" data-loop-quiz>

@if (! $canView)
    <p class="px-4 py-10 text-center text-sm text-gray-500 dark:text-gray-400">
        {{ __('loops.cards.quiz.no_access') }}
    </p>
@else

    @if ($problem)
        <p class="mb-3 rounded-lg bg-rose-50 px-3 py-2 text-sm text-rose-800 dark:bg-rose-500/15 dark:text-rose-200" role="alert">{{ $problem }}</p>
    @elseif ($flash)
        <p class="mb-3 rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-800 dark:bg-emerald-500/15 dark:text-emerald-200"
           role="status" aria-live="polite">{{ $flash }}</p>
    @endif

    @if ($canManage)
        <div class="mb-4 inline-flex rounded-xl bg-gray-100 p-0.5 dark:bg-gray-700" role="tablist">
            <button type="button" wire:click="$set('face', 'mine')" role="tab" aria-selected="{{ $face === 'mine' ? 'true' : 'false' }}"
                    @class(['rounded-lg px-3 py-1.5 text-xs font-semibold transition-colors',
                        'bg-white text-gray-900 shadow-sm dark:bg-gray-800 dark:text-gray-100' => $face === 'mine',
                        'text-gray-600 dark:text-gray-300' => $face !== 'mine'])>
                {{ __('loops.cards.quiz.my_quizzes') }}
            </button>
            <button type="button" wire:click="$set('face', 'everyone')" role="tab" aria-selected="{{ $face === 'everyone' ? 'true' : 'false' }}"
                    @class(['rounded-lg px-3 py-1.5 text-xs font-semibold transition-colors',
                        'bg-white text-gray-900 shadow-sm dark:bg-gray-800 dark:text-gray-100' => $face === 'everyone',
                        'text-gray-600 dark:text-gray-300' => $face !== 'everyone'])>
                {{ __('loops.cards.quiz.everyone') }}
            </button>
        </div>
    @endif

    {{-- ── Visage 1 : mes QCM ─────────────────────────────────────────── --}}
    @if ($face === 'mine')
        @if ($overview->isEmpty())
            <div class="rounded-2xl border border-dashed border-gray-300 px-5 py-10 text-center dark:border-gray-600">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ __('loops.cards.quiz.empty_title') }}</h3>
                <p class="mx-auto mt-2 max-w-sm text-sm text-gray-500 dark:text-gray-400">{{ __('loops.cards.quiz.empty_body') }}</p>
            </div>
        @else
            <div class="space-y-3">
                @foreach ($overview as $entree)
                    @php $quiz = $entree['quiz']; $best = $entree['best']; @endphp
                    <section class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800"
                             aria-labelledby="quiz-{{ $quiz->id }}">
                        <header class="flex flex-wrap items-start justify-between gap-2">
                            <div class="min-w-0">
                                <h3 id="quiz-{{ $quiz->id }}" class="truncate text-sm font-semibold text-gray-900 dark:text-gray-100">{{ $quiz->title }}</h3>
                                <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                                    <span class="rounded-full bg-gray-100 px-1.5 py-0.5 dark:bg-gray-700">
                                        {{ $quiz->blocking ? __('loops.cards.quiz.blocking_note') : __('loops.cards.quiz.informative_note') }}
                                    </span>
                                    ·
                                    @if ($entree['left'] === null)
                                        {{ __('loops.cards.quiz.attempts_unlimited') }}
                                    @else
                                        {{ trans_choice('loops.cards.quiz.attempts_left', $entree['left'], ['count' => $entree['left']]) }}
                                    @endif
                                </p>
                            </div>

                            <div class="flex shrink-0 items-center gap-2">
                                {{-- Le score est **toujours** immediat. --}}
                                @if ($best)
                                    <span class="rounded-full px-2 py-0.5 text-[11px] font-semibold {{ $best->passed ? 'bg-emerald-600 text-white dark:bg-emerald-500' : 'bg-rose-100 text-rose-700 dark:bg-rose-500/20 dark:text-rose-300' }}">
                                        {{ __('loops.cards.quiz.score', ['score' => $best->score]) }} — {{ $best->passed ? __('loops.cards.quiz.passed') : __('loops.cards.quiz.failed') }}
                                    </span>
                                @else
                                    <span class="rounded-full bg-gray-100 px-2 py-0.5 text-[11px] font-semibold text-gray-600 dark:bg-gray-700 dark:text-gray-300">
                                        {{ __('loops.cards.quiz.no_attempt_yet') }}
                                    </span>
                                @endif

                                @if ($canManage)
                                    @if ($quiz->reveal_mode === 'after_release' && ! $quiz->released_at)
                                        <button type="button" wire:click="release('{{ $quiz->id }}')"
                                                class="rounded-lg border border-gray-300 px-2 py-1 text-[11px] font-semibold text-gray-700 hover:border-indigo-400 dark:border-gray-600 dark:text-gray-300">
                                            {{ __('loops.cards.quiz.release') }}
                                        </button>
                                    @endif
                                    <button type="button" wire:click="startEditing('{{ $quiz->id }}')"
                                            class="rounded-lg border border-gray-300 px-2 py-1 text-[11px] font-semibold text-gray-700 hover:border-indigo-400 dark:border-gray-600 dark:text-gray-300">
                                        {{ __('loops.cards.quiz.edit') }}
                                    </button>
                                    <button type="button" wire:click="openQuestionForm('{{ $quiz->id }}')"
                                            class="rounded-lg border border-gray-300 px-2 py-1 text-[11px] font-semibold text-gray-700 hover:border-indigo-400 dark:border-gray-600 dark:text-gray-300">
                                        {{ __('loops.cards.quiz.add_question') }}
                                    </button>
                                    <button type="button" wire:click="deleteQuiz('{{ $quiz->id }}')"
                                            wire:confirm="{{ __('loops.cards.quiz.delete_confirm') }}"
                                            aria-label="{{ __('loops.cards.quiz.remove', ['name' => $quiz->title]) }}"
                                            class="rounded-lg p-1.5 text-gray-400 hover:bg-rose-50 hover:text-rose-600 dark:hover:bg-rose-500/15">
                                        <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                                    </button>
                                @endif
                            </div>
                        </header>

                        @if ($quiz->intro)
                            <p class="mt-2 whitespace-pre-line text-sm text-gray-700 dark:text-gray-300">{{ $quiz->intro }}</p>
                        @endif

                        {{-- Le formulaire de question, cote Animateur. --}}
                        @if ($canManage && $questionFormFor === $quiz->id)
                            <div class="mt-3 space-y-2 rounded-xl bg-gray-50 p-3 dark:bg-gray-900/50">
                                <label class="block">
                                    <span class="text-xs font-medium text-gray-700 dark:text-gray-300">{{ __('loops.cards.quiz.question_label') }}</span>
                                    <input type="text" wire:model="questionText" class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-800" />
                                </label>
                                @error('questionText') <p class="text-xs text-rose-600">{{ $message }}</p> @enderror
                                <label class="block">
                                    <span class="text-xs font-medium text-gray-700 dark:text-gray-300">{{ __('loops.cards.quiz.kind_label') }}</span>
                                    <select wire:model="questionKind" class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-800">
                                        <option value="single">{{ __('loops.cards.quiz.kind_single') }}</option>
                                        <option value="multiple">{{ __('loops.cards.quiz.kind_multiple') }}</option>
                                        <option value="true_false">{{ __('loops.cards.quiz.kind_true_false') }}</option>
                                    </select>
                                </label>
                                @foreach ($optionRows as $i => $ligne)
                                    <div class="flex items-center gap-2">
                                        <input type="text" wire:model="optionRows.{{ $i }}.text"
                                               placeholder="{{ __('loops.cards.quiz.option_label') }}"
                                               class="flex-1 rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-800" />
                                        <label class="flex shrink-0 items-center gap-1 text-xs text-gray-600 dark:text-gray-300">
                                            <input type="checkbox" wire:model="optionRows.{{ $i }}.correct" class="rounded border-gray-300" />
                                            {{ __('loops.cards.quiz.option_correct') }}
                                        </label>
                                    </div>
                                @endforeach
                                <div class="flex flex-wrap gap-2">
                                    <button type="button" wire:click="addOptionRow"
                                            class="rounded-lg border border-gray-300 px-2 py-1 text-[11px] text-gray-700 dark:border-gray-600 dark:text-gray-300">+</button>
                                    <button type="button" wire:click="saveQuestion('{{ $quiz->id }}')"
                                            class="rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-indigo-700">
                                        {{ __('loops.cards.quiz.save') }}
                                    </button>
                                    <button type="button" wire:click="startEditing('{{ $quiz->id }}')"
                                            class="rounded-lg border border-gray-300 px-2 py-1 text-[11px] font-semibold text-gray-700 hover:border-indigo-400 dark:border-gray-600 dark:text-gray-300">
                                        {{ __('loops.cards.quiz.edit') }}
                                    </button>
                                    <button type="button" wire:click="openQuestionForm('{{ $quiz->id }}')"
                                            class="rounded-lg px-3 py-1.5 text-xs text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700">
                                        {{ __('loops.cards.quiz.cancel') }}
                                    </button>
                                </div>
                            </div>
                        @endif

                        {{-- Le formateur relit ses questions et son corrige sans
                             avoir a passer son propre QCM — ce qui creait une
                             tentative et le faisait apparaitre dans sa propre
                             matrice. --}}
                        @if ($canManage && $takingId !== $quiz->id)
                            <ul class="mt-2 space-y-1 rounded-xl bg-gray-50 p-3 text-xs dark:bg-gray-900/50">
                                @forelse ($quiz->questions as $q)
                                    <li class="text-gray-700 dark:text-gray-300">
                                        <span class="font-medium">{{ $q->text }}</span> —
                                        {{ __('loops.cards.quiz.correct_answer') }} :
                                        {{ $q->options->where('is_correct', true)->pluck('text')->join(', ') }}
                                    </li>
                                @empty
                                    <li class="text-gray-500 dark:text-gray-400">{{ __('loops.cards.quiz.no_question') }}</li>
                                @endforelse
                            </ul>
                        @endif

                        {{-- Le passage, cote stagiaire. --}}
                        @if ($takingId === $quiz->id && $canAttempt)
                            <ol class="mt-3 space-y-3">
                                @foreach ($quiz->questions as $question)
                                    @php $unique = $question->kind !== 'multiple'; @endphp
                                    <li class="rounded-xl bg-gray-50 p-3 dark:bg-gray-900/50">
                                        <p class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ $question->text }}</p>
                                        <div class="mt-2 space-y-1.5">
                                            @foreach ($question->options as $option)
                                                @php $cochee = in_array($option->id, $answers[$question->id] ?? [], true); @endphp
                                                <button type="button"
                                                        wire:click="toggleAnswer('{{ $question->id }}', '{{ $option->id }}', {{ $unique ? 'true' : 'false' }})"
                                                        aria-pressed="{{ $cochee ? 'true' : 'false' }}"
                                                        @class(['flex w-full items-center gap-2 rounded-lg border px-3 py-2 text-left text-sm transition-colors',
                                                            'border-indigo-500 bg-indigo-50 text-indigo-900 dark:bg-indigo-500/15 dark:text-indigo-200' => $cochee,
                                                            'border-gray-200 text-gray-700 hover:border-gray-300 dark:border-gray-700 dark:text-gray-300' => ! $cochee])>
                                                    {{ $option->text }}
                                                </button>
                                            @endforeach
                                        </div>
                                    </li>
                                @endforeach
                            </ol>
                            <button type="button" wire:click="submitAttempt('{{ $quiz->id }}')"
                                    class="mt-3 rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-indigo-700">
                                {{ __('loops.cards.quiz.submit') }}
                            </button>
                        @elseif ($canAttempt && ($entree['left'] === null || $entree['left'] > 0))
                            <button type="button" wire:click="startQuiz('{{ $quiz->id }}')"
                                    class="mt-3 text-xs font-semibold text-indigo-600 hover:text-indigo-700 dark:text-indigo-400">
                                {{ __('loops.cards.quiz.start') }}
                            </button>
                        @endif

                        {{-- Le detail des bonnes reponses, selon le reglage. --}}
                        @if ($entree['reveals'])
                            <ul class="mt-3 space-y-1 border-t border-gray-100 pt-3 dark:border-gray-700">
                                @foreach ($quiz->questions as $question)
                                    <li class="text-xs text-gray-600 dark:text-gray-400">
                                        <span class="font-medium">{{ $question->text }}</span> —
                                        {{ __('loops.cards.quiz.correct_answer') }} :
                                        {{ $question->options->where('is_correct', true)->pluck('text')->join(', ') }}
                                    </li>
                                @endforeach
                            </ul>
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
                            <span class="text-xs font-medium text-gray-700 dark:text-gray-300">{{ __('loops.cards.quiz.title_label') }}</span>
                            <input type="text" wire:model="title" class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-800" />
                        </label>
                        @error('title') <p class="text-xs text-rose-600">{{ $message }}</p> @enderror
                        <label class="block">
                            <span class="text-xs font-medium text-gray-700 dark:text-gray-300">{{ __('loops.cards.quiz.intro_label') }}</span>
                            <textarea wire:model="intro" rows="2" class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-800"></textarea>
                        </label>
                        <div class="grid grid-cols-2 gap-2">
                            <label class="block">
                                <span class="text-xs font-medium text-gray-700 dark:text-gray-300">{{ __('loops.cards.quiz.pass_score_label') }}</span>
                                <input type="number" min="0" max="100" wire:model="passScore" class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-800" />
                            </label>
                            <label class="block">
                                <span class="text-xs font-medium text-gray-700 dark:text-gray-300">{{ __('loops.cards.quiz.max_attempts_label') }}</span>
                                <input type="number" min="1" wire:model="maxAttempts" class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-800" />
                            </label>
                        </div>
                        @error('pass_score') <p class="text-xs text-rose-600">{{ $message }}</p> @enderror
                        @error('max_attempts') <p class="text-xs text-rose-600">{{ $message }}</p> @enderror
                        <label class="flex items-center gap-2 text-xs text-gray-700 dark:text-gray-300">
                            <input type="checkbox" wire:model.live="blocking" class="rounded border-gray-300" />
                            {{ __('loops.cards.quiz.blocking_label') }}
                        </label>

                        {{-- Sans Sequence, « bloquant » ne bloque **rien** : le
                             service exige une etape a conditionner. Le champ
                             n'apparait donc que quand la case est cochee, et il
                             dit ce qui se passe s'il reste vide. --}}
                        @if ($blocking && ! $editingId)
                            <label class="block">
                                <span class="text-xs font-medium text-gray-700 dark:text-gray-300">{{ __('loops.cards.quiz.sequence_label') }}</span>
                                <select wire:model="sequenceId" class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-800">
                                    <option value="">{{ __('loops.cards.quiz.sequence_none') }}</option>
                                    @foreach ($sequences as $s)
                                        <option value="{{ $s->id }}">{{ $s->module?->title }} — {{ $s->displayName() }}</option>
                                    @endforeach
                                </select>
                            </label>
                            @if ($sequences->isEmpty())
                                <p class="text-xs text-amber-700 dark:text-amber-300">{{ __('loops.cards.quiz.sequence_none_available') }}</p>
                            @endif
                        @endif
                        <label class="block">
                            <span class="text-xs font-medium text-gray-700 dark:text-gray-300">{{ __('loops.cards.quiz.reveal_label') }}</span>
                            <select wire:model="revealMode" class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-800">
                                <option value="immediate">{{ __('loops.cards.quiz.reveal_immediate') }}</option>
                                <option value="after_last_attempt">{{ __('loops.cards.quiz.reveal_after_last_attempt') }}</option>
                                <option value="after_release">{{ __('loops.cards.quiz.reveal_after_release') }}</option>
                            </select>
                        </label>
                        <div class="flex gap-2">
                            <button type="button" wire:click="saveQuiz" class="rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-indigo-700">
                                {{ __('loops.cards.quiz.save') }}
                            </button>
                            <button type="button" wire:click="$set('showForm', false)" class="rounded-lg px-3 py-1.5 text-xs text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700">
                                {{ __('loops.cards.quiz.cancel') }}
                            </button>
                        </div>
                    </div>
                @else
                    <button type="button" wire:click="$set('showForm', true)"
                            class="w-full rounded-xl border border-dashed border-gray-300 px-4 py-2.5 text-sm font-semibold text-gray-600 hover:border-indigo-400 hover:text-indigo-600 dark:border-gray-600 dark:text-gray-300">
                        + {{ __('loops.cards.quiz.add') }}
                    </button>
                @endif
            </div>
        @endif
    @endif

    {{-- ── Visage 2 : tout le monde ───────────────────────────────────── --}}
    @if ($face === 'everyone' && $canManage)
        @if ($matrix->isEmpty())
            <p class="px-4 py-10 text-center text-sm text-gray-500 dark:text-gray-400">{{ __('loops.cards.quiz.no_members') }}</p>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <caption class="sr-only">{{ __('loops.cards.quiz.everyone') }}</caption>
                    <thead>
                        <tr class="border-b border-gray-200 dark:border-gray-700">
                            <th scope="col" class="py-2 pr-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400">{{ __('loops.cards.members.label') }}</th>
                            @foreach ($matrix->first()['cells'] as $colonne)
                                <th scope="col" class="px-2 py-2 text-left text-xs font-semibold text-gray-500 dark:text-gray-400">
                                    <span class="block max-w-[8rem] truncate">{{ $colonne['quiz']->title }}</span>
                                </th>
                            @endforeach
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
                                        @if ($cellule['best'])
                                            <span class="inline-block rounded-full px-2 py-0.5 text-[10px] font-semibold {{ $cellule['best']->passed ? 'bg-emerald-600 text-white dark:bg-emerald-500' : 'bg-rose-100 text-rose-700 dark:bg-rose-500/20 dark:text-rose-300' }}">
                                                {{ $cellule['best']->score }} %
                                            </span>
                                            <span class="ml-1 text-[10px] text-gray-500 dark:text-gray-400">
                                                {{ trans_choice('loops.cards.quiz.tries', $cellule['tries'], ['count' => $cellule['tries']]) }}
                                            </span>
                                        @else
                                            <span class="inline-block rounded-full bg-gray-100 px-2 py-0.5 text-[10px] font-semibold text-gray-500 dark:bg-gray-700 dark:text-gray-400">
                                                {{ __('loops.cards.quiz.no_attempt_yet') }}
                                            </span>
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    @endif

    {{-- ── Les QCM retires ────────────────────────────────────────────── --}}
    @if ($canManage && $archived->isNotEmpty())
        <section class="mt-5 rounded-2xl border border-gray-200 bg-gray-50/60 p-4 dark:border-gray-700 dark:bg-gray-900/40">
            <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300">{{ __('loops.cards.quiz.archived_title') }}</h3>
            <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ __('loops.cards.quiz.archived_help') }}</p>
            <ul class="mt-2 space-y-1.5">
                @foreach ($archived as $retire)
                    <li class="flex items-center gap-2 rounded-lg bg-white px-2.5 py-2 text-sm dark:bg-gray-800">
                        <span class="min-w-0 flex-1 truncate text-gray-700 dark:text-gray-300">{{ $retire->title }}</span>
                        <span class="shrink-0 rounded-full bg-gray-100 px-2 py-0.5 text-[10px] font-semibold text-gray-600 dark:bg-gray-700 dark:text-gray-300">
                            {{ __('loops.cards.quiz.archived_note') }}
                        </span>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

@endif
</div>
