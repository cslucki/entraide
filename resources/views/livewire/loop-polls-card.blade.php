{{--
    Card Sondages.

    Une liste, un formulaire, et par Sondage : le vote ou le resultat, jamais les
    deux en meme temps. Le detail nominatif est replie par defaut — il est le
    seul chargement couteux de cet ecran, et il ne se paie que si on le demande.
--}}
<div class="space-y-3" data-polls-root>

    @if($readOnly)
        <p class="rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-xs leading-5 text-amber-800 dark:border-amber-800/60 dark:bg-amber-900/20 dark:text-amber-200">
            {{ __('polls.read_only') }}
        </p>
    @endif

    @if($errorMessage)
        <p class="rounded-xl border border-red-200 bg-red-50 px-3 py-2 text-xs leading-5 text-red-700 dark:border-red-800/60 dark:bg-red-900/20 dark:text-red-300" role="alert">
            {{ $errorMessage }}
        </p>
    @endif

    @if($successMessage)
        <p class="rounded-xl border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs leading-5 text-emerald-700 dark:border-emerald-800/60 dark:bg-emerald-900/20 dark:text-emerald-300">
            {{ $successMessage }}
        </p>
    @endif

    @if($canCreate && ! $showForm)
        <button type="button" wire:click="openCreateForm"
                class="inline-flex w-full items-center justify-center gap-2 rounded-xl bg-violet-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-violet-700">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
            {{ __('polls.create') }}
        </button>
    @endif

    {{-- ── Formulaire ─────────────────────────────────────────────────── --}}
    @if($showForm)
        <div class="rounded-2xl border border-violet-200 bg-white p-4 dark:border-violet-800/60 dark:bg-gray-900">
            <p class="text-sm font-semibold text-gray-900 dark:text-gray-100">
                {{ $editingId ? __('polls.form_title_edit') : __('polls.form_title_create') }}
            </p>

            <label class="mt-3 block text-xs font-medium text-gray-500 dark:text-gray-400" for="poll-question">{{ __('polls.question') }}</label>
            <input id="poll-question" type="text" wire:model="question" maxlength="500"
                   placeholder="{{ __('polls.question_placeholder') }}"
                   class="mt-1 w-full rounded-xl border-gray-300 bg-white text-sm text-gray-900 focus:border-violet-500 focus:ring-violet-500 dark:border-gray-600 dark:bg-gray-950 dark:text-gray-100">

            <label class="mt-3 block text-xs font-medium text-gray-500 dark:text-gray-400" for="poll-description">{{ __('polls.description') }}</label>
            <textarea id="poll-description" wire:model="description" rows="2" maxlength="1000"
                      placeholder="{{ __('polls.description_placeholder') }}"
                      class="mt-1 w-full resize-none rounded-xl border-gray-300 bg-white text-sm text-gray-900 focus:border-violet-500 focus:ring-violet-500 dark:border-gray-600 dark:bg-gray-950 dark:text-gray-100"></textarea>

            <fieldset class="mt-3">
                <legend class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('polls.selection_type') }}</legend>
                <div class="mt-1.5 flex flex-wrap gap-2">
                    @foreach([\App\Models\LoopPoll::TYPE_SINGLE => __('polls.type_single'), \App\Models\LoopPoll::TYPE_MULTIPLE => __('polls.type_multiple')] as $value => $label)
                        <label class="flex cursor-pointer items-center gap-2 rounded-lg border border-gray-300 px-3 py-1.5 text-xs text-gray-700 transition has-[:checked]:border-violet-500 has-[:checked]:bg-violet-50 dark:border-gray-600 dark:text-gray-200 dark:has-[:checked]:bg-violet-900/20">
                            <input type="radio" wire:model="selectionType" value="{{ $value }}" class="text-violet-600 focus:ring-violet-500">
                            {{ $label }}
                        </label>
                    @endforeach
                </div>
            </fieldset>

            <p class="mt-3 text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('polls.options') }}</p>
            <div class="mt-1.5 space-y-2">
                @foreach($options as $index => $option)
                    <div class="flex items-center gap-2" wire:key="poll-option-{{ $index }}">
                        <input type="text" wire:model="options.{{ $index }}" maxlength="255"
                               placeholder="{{ __('polls.option_placeholder', ['number' => $index + 1]) }}"
                               class="w-full rounded-xl border-gray-300 bg-white text-sm text-gray-900 focus:border-violet-500 focus:ring-violet-500 dark:border-gray-600 dark:bg-gray-950 dark:text-gray-100">
                        @if(count($options) > \App\Models\LoopPoll::MIN_OPTIONS)
                            <button type="button" wire:click="removeOption({{ $index }})"
                                    class="shrink-0 rounded-lg p-1.5 text-gray-400 transition hover:bg-gray-100 hover:text-red-600 dark:hover:bg-gray-800"
                                    aria-label="{{ __('polls.remove_option') }}">
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/></svg>
                            </button>
                        @endif
                    </div>
                @endforeach
            </div>

            @if(count($options) < \App\Models\LoopPoll::MAX_OPTIONS)
                <button type="button" wire:click="addOption"
                        class="mt-2 text-xs font-semibold text-violet-700 transition hover:underline dark:text-violet-300">
                    + {{ __('polls.add_option') }}
                </button>
            @endif

            <div class="mt-4 flex flex-wrap justify-end gap-2">
                <button type="button" wire:click="closeForm"
                        class="rounded-lg border border-gray-300 px-3 py-1.5 text-xs font-semibold text-gray-600 transition hover:bg-gray-100 dark:border-gray-600 dark:text-gray-300 dark:hover:bg-gray-800">
                    {{ __('polls.cancel') }}
                </button>
                <button type="button" wire:click="save"
                        class="rounded-lg bg-violet-600 px-4 py-1.5 text-xs font-semibold text-white transition hover:bg-violet-700">
                    {{ $editingId ? __('polls.save') : __('polls.publish') }}
                </button>
            </div>
        </div>
    @endif

    {{-- ── Liste ──────────────────────────────────────────────────────── --}}
    @if($polls->isEmpty() && ! $showForm)
        <div class="rounded-2xl border border-dashed border-gray-300 bg-white p-5 text-center dark:border-gray-700 dark:bg-gray-900">
            <p class="text-sm font-semibold text-gray-700 dark:text-gray-200">{{ __('polls.empty') }}</p>
            <p class="mt-1 text-xs leading-5 text-gray-500 dark:text-gray-400">{{ __('polls.empty_hint') }}</p>
        </div>
    @endif

    @foreach($polls as $poll)
        <div class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900"
             wire:key="poll-{{ $poll['id'] }}" data-poll-id="{{ $poll['id'] }}">

            <div class="flex flex-wrap items-start justify-between gap-2">
                <p class="min-w-0 flex-1 text-sm font-semibold text-gray-900 dark:text-gray-100">{{ $poll['question'] }}</p>
                <span class="shrink-0 rounded-full px-2 py-0.5 text-[11px] font-semibold
                             {{ $poll['is_open']
                                ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300'
                                : 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400' }}">
                    {{ $poll['is_open'] ? __('polls.status_open') : __('polls.status_closed') }}
                </span>
            </div>

            @if($poll['description'])
                <p class="mt-1 text-xs leading-5 text-gray-600 dark:text-gray-300">{{ $poll['description'] }}</p>
            @endif

            <p class="mt-1.5 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-[11px] text-gray-400 dark:text-gray-500">
                <span>{{ __('polls.by', ['name' => $poll['author']]) }}</span>
                <span>·</span>
                <span>{{ $poll['multiple'] ? __('polls.type_multiple') : __('polls.type_single') }}</span>
                <span>·</span>
                <span>{{ __('polls.participants', ['count' => $poll['participants']]) }}</span>
                @if(! $poll['is_open'] && $poll['closed_at'])
                    <span>·</span>
                    <span>{{ __('polls.closed_notice', ['date' => $poll['closed_at']->isoFormat('LL')]) }}</span>
                @endif
            </p>

            {{-- Vote, ou resultat : jamais les deux. Voir sa propre voix pendant
                 qu'on la change n'aide pas, et voir le resultat avant d'avoir
                 vote oriente le vote. --}}
            @if($poll['is_open'] && $canVote && ($editingVote[$poll['id']] ?? false))
                <div class="mt-3 space-y-1.5">
                    @foreach($poll['options'] as $option)
                        @php($chosen = in_array($option->id, $draftVotes[$poll['id']] ?? [], true))
                        <button type="button" wire:click="toggleChoice('{{ $poll['id'] }}', '{{ $option->id }}')"
                                class="flex w-full items-center gap-2 rounded-xl border px-3 py-2 text-left text-sm transition
                                       {{ $chosen
                                          ? 'border-violet-500 bg-violet-50 text-violet-900 dark:border-violet-500 dark:bg-violet-900/25 dark:text-violet-100'
                                          : 'border-gray-300 text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-800' }}"
                                :aria-pressed="{{ $chosen ? 'true' : 'false' }}">
                            <span class="flex h-4 w-4 shrink-0 items-center justify-center border-2 {{ $poll['multiple'] ? 'rounded' : 'rounded-full' }} {{ $chosen ? 'border-violet-600 bg-violet-600' : 'border-gray-400' }}">
                                @if($chosen)
                                    <svg class="h-2.5 w-2.5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="4"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/></svg>
                                @endif
                            </span>
                            <span class="min-w-0 flex-1">{{ $option->label }}</span>
                        </button>
                    @endforeach
                </div>

                <div class="mt-3 flex flex-wrap justify-end gap-2">
                    <button type="button" wire:click="cancelVote('{{ $poll['id'] }}')"
                            class="rounded-lg border border-gray-300 px-3 py-1.5 text-xs font-semibold text-gray-600 transition hover:bg-gray-100 dark:border-gray-600 dark:text-gray-300 dark:hover:bg-gray-800">
                        {{ __('polls.cancel') }}
                    </button>
                    <button type="button" wire:click="submitVote('{{ $poll['id'] }}')"
                            class="rounded-lg bg-violet-600 px-4 py-1.5 text-xs font-semibold text-white transition hover:bg-violet-700">
                        {{ __('polls.vote') }}
                    </button>
                </div>
            @else
                @if($poll['my_option_labels'])
                    <p class="mt-2.5 text-xs text-gray-600 dark:text-gray-300">
                        <span class="font-semibold">{{ __('polls.your_vote') }}</span>
                        {{ implode(', ', $poll['my_option_labels']) }}
                    </p>
                @endif

                @if($poll['sees_results'] && $poll['results'])
                    <div class="mt-3 space-y-2">
                        @foreach($poll['results']['options'] as $result)
                            <div>
                                <div class="flex items-baseline justify-between gap-2 text-xs">
                                    <span class="min-w-0 truncate text-gray-700 dark:text-gray-200">{{ $result['label'] }}</span>
                                    <span class="shrink-0 text-gray-500 dark:text-gray-400">
                                        {{ __('polls.result_line', ['votes' => $result['votes'], 'percentage' => $result['percentage']]) }}
                                    </span>
                                </div>
                                <div class="mt-1 h-1.5 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-800">
                                    <div class="h-full rounded-full bg-violet-500" style="width: {{ $result['percentage'] }}%"></div>
                                </div>
                            </div>
                        @endforeach
                    </div>

                    <p class="mt-2 text-[11px] text-gray-400 dark:text-gray-500">
                        {{ __('polls.total_participants', ['count' => $poll['results']['participants']]) }}
                    </p>

                    <button type="button" wire:click="toggleDetail('{{ $poll['id'] }}')"
                            class="mt-1.5 text-[11px] font-semibold text-violet-700 transition hover:underline dark:text-violet-300">
                        {{ ($showDetail[$poll['id']] ?? false) ? __('polls.detail_hide') : __('polls.detail_show') }}
                    </button>

                    @if($poll['detail'] !== null)
                        <ul class="mt-2 space-y-1 border-t border-gray-100 pt-2 dark:border-gray-800">
                            @forelse($poll['detail'] as $voter)
                                <li class="flex flex-wrap items-baseline gap-x-2 text-[11px]">
                                    <span class="font-semibold text-gray-700 dark:text-gray-200">{{ $voter['name'] }}</span>
                                    <span class="text-gray-500 dark:text-gray-400">{{ implode(', ', $voter['options']) }}</span>
                                </li>
                            @empty
                                <li class="text-[11px] text-gray-400 dark:text-gray-500">{{ __('polls.detail_empty') }}</li>
                            @endforelse
                        </ul>
                    @endif
                @elseif($poll['is_open'])
                    <p class="mt-2.5 text-[11px] italic text-gray-400 dark:text-gray-500">{{ __('polls.results_after_vote') }}</p>
                @endif

                <div class="mt-3 flex flex-wrap items-center gap-2">
                    @if($poll['is_open'] && $canVote)
                        <button type="button" wire:click="startVote('{{ $poll['id'] }}')"
                                class="rounded-lg bg-violet-600 px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-violet-700">
                            {{ $poll['my_option_labels'] ? __('polls.change_vote') : __('polls.vote') }}
                        </button>
                    @endif

                    @if($poll['can_edit'])
                        <button type="button" wire:click="openEditForm('{{ $poll['id'] }}')"
                                class="rounded-lg border border-gray-300 px-3 py-1.5 text-xs font-semibold text-gray-600 transition hover:bg-gray-100 dark:border-gray-600 dark:text-gray-300 dark:hover:bg-gray-800">
                            {{ __('polls.edit') }}
                        </button>
                    @endif

                    @if($poll['can_manage'] && $poll['is_open'])
                        <button type="button" wire:click="confirmClose('{{ $poll['id'] }}')"
                                class="rounded-lg border border-amber-300 px-3 py-1.5 text-xs font-semibold text-amber-700 transition hover:bg-amber-50 dark:border-amber-800 dark:text-amber-300 dark:hover:bg-amber-900/30">
                            {{ __('polls.close') }}
                        </button>
                    @endif

                    @if($poll['can_delete'])
                        <button type="button" wire:click="confirmDelete('{{ $poll['id'] }}')"
                                class="rounded-lg border border-red-300 px-3 py-1.5 text-xs font-semibold text-red-700 transition hover:bg-red-50 dark:border-red-800 dark:text-red-300 dark:hover:bg-red-900/30">
                            {{ __('polls.delete') }}
                        </button>
                    @endif
                </div>
            @endif
        </div>
    @endforeach

    {{-- ── Confirmations ──────────────────────────────────────────────────
         Modales Alpine et jamais `confirm()` : une alerte native ne sait pas
         dire ce qui est conserve, ce qui est precisement l'information utile. --}}
    @if($confirmingCloseId || $confirmingDeleteId)
        @php($deleting = (bool) $confirmingDeleteId)
        <template x-teleport="body">
            <div class="fixed inset-0 z-50 flex items-center justify-center bg-gray-900/50 p-4"
                 x-on:keydown.escape.window="$wire.cancelConfirmation()"
                 x-on:click.self="$wire.cancelConfirmation()"
                 role="dialog" aria-modal="true">
                <div class="w-full max-w-md rounded-2xl bg-white p-5 shadow-xl dark:bg-gray-800">
                    <h2 class="text-base font-bold text-gray-900 dark:text-gray-100">
                        {{ $deleting ? __('polls.delete_confirm_title') : __('polls.close_confirm_title') }}
                    </h2>
                    <p class="mt-2 text-sm leading-6 text-gray-600 dark:text-gray-300">
                        {{ $deleting ? __('polls.delete_confirm_body') : __('polls.close_confirm_body') }}
                    </p>
                    <div class="mt-5 flex flex-wrap justify-end gap-2">
                        <button type="button" wire:click="cancelConfirmation"
                                class="rounded-lg border border-gray-300 px-4 py-2 text-sm text-gray-600 transition hover:bg-gray-100 dark:border-gray-600 dark:text-gray-300 dark:hover:bg-gray-700">
                            {{ __('polls.cancel') }}
                        </button>
                        <button type="button"
                                wire:click="{{ $deleting ? "delete('".$confirmingDeleteId."')" : "close('".$confirmingCloseId."')" }}"
                                class="rounded-lg px-4 py-2 text-sm font-semibold text-white transition {{ $deleting ? 'bg-red-600 hover:bg-red-700' : 'bg-amber-600 hover:bg-amber-700' }}">
                            {{ $deleting ? __('polls.delete_confirm_cta') : __('polls.close_confirm_cta') }}
                        </button>
                    </div>
                </div>
            </div>
        </template>
    @endif
</div>
