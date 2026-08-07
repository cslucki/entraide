{{--
    La Progression : une seule Card, deux visages.

    « Ma progression » pour le stagiaire, la matrice pour l'Animateur. L'onglet
    n'apparait que si la personne a les deux droits — sinon il n'y aurait qu'un
    visage a montrer, et un onglet unique n'est pas un onglet.

    Les numeros viennent de `overviewFor()`, calcules depuis le rang. Rien ici
    ne les ecrit, et aucun titre n'est prefixe.

    **Toute la structure reste visible.** Une etape verrouillee se voit venir,
    avec la raison de son verrou : ce n'est pas un mur, c'est un plan. Seul le
    contenu detaille est ferme.
--}}
@php
    $tons = [
        'unavailable' => 'bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-400',
        'available' => 'bg-sky-100 text-sky-700 dark:bg-sky-500/20 dark:text-sky-300',
        'in_progress' => 'bg-amber-100 text-amber-700 dark:bg-amber-500/20 dark:text-amber-300',
        'submitted' => 'bg-violet-100 text-violet-700 dark:bg-violet-500/20 dark:text-violet-300',
        'completed' => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-300',
        'validated' => 'bg-emerald-600 text-white dark:bg-emerald-500',
        'redo' => 'bg-rose-100 text-rose-700 dark:bg-rose-500/20 dark:text-rose-300',
    ];
@endphp

<div class="flex h-full flex-col" data-loop-progression>

@if (! $canView)
    <p class="px-4 py-10 text-center text-sm text-gray-500 dark:text-gray-400">
        {{ __('loops.cards.progression.no_access') }}
    </p>
@else

    @if ($flash)
        <p class="mb-3 rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-800 dark:bg-emerald-500/15 dark:text-emerald-200"
           role="status" aria-live="polite">{{ $flash }}</p>
    @endif

    @if ($canManage)
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <div class="inline-flex rounded-xl bg-gray-100 p-0.5 dark:bg-gray-700" role="tablist">
                <button type="button" wire:click="$set('face', 'mine')" role="tab"
                        aria-selected="{{ $face === 'mine' ? 'true' : 'false' }}"
                        @class([
                            'rounded-lg px-3 py-1.5 text-xs font-semibold transition-colors',
                            'bg-white text-gray-900 shadow-sm dark:bg-gray-800 dark:text-gray-100' => $face === 'mine',
                            'text-gray-600 dark:text-gray-300' => $face !== 'mine',
                        ])>
                    {{ __('loops.cards.progression.my_progress') }}
                </button>
                <button type="button" wire:click="$set('face', 'everyone')" role="tab"
                        aria-selected="{{ $face === 'everyone' ? 'true' : 'false' }}"
                        @class([
                            'rounded-lg px-3 py-1.5 text-xs font-semibold transition-colors',
                            'bg-white text-gray-900 shadow-sm dark:bg-gray-800 dark:text-gray-100' => $face === 'everyone',
                            'text-gray-600 dark:text-gray-300' => $face !== 'everyone',
                        ])>
                    {{ __('loops.cards.progression.everyone') }}
                </button>
            </div>

            {{-- Le mode de parcours. Sequentiel par defaut : une formation
                 conduit quelqu'un quelque part plutot que de l'abandonner
                 devant une bibliotheque. --}}
            <div class="flex items-center gap-2">
                <button type="button" wire:click="setPathMode('{{ $pathMode === 'free' ? 'sequential' : 'free' }}')"
                        class="rounded-lg border border-gray-300 px-2.5 py-1 text-xs font-medium text-gray-700 hover:border-indigo-400 hover:text-indigo-600 dark:border-gray-600 dark:text-gray-300"
                        title="{{ $pathMode === 'free' ? __('loops.cards.progression.path_help_free') : __('loops.cards.progression.path_help_sequential') }}">
                    {{ $pathMode === 'free' ? __('loops.cards.progression.path_free') : __('loops.cards.progression.path_sequential') }}
                </button>
            </div>
        </div>
    @endif

    {{-- ── Visage 1 : ma progression ──────────────────────────────────── --}}
    @if ($face === 'mine')
        @if ($overview->isEmpty())
            <div class="rounded-2xl border border-dashed border-gray-300 px-5 py-10 text-center dark:border-gray-600">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">
                    {{ __('loops.cards.progression.empty_title') }}
                </h3>
                <p class="mx-auto mt-2 max-w-sm text-sm text-gray-500 dark:text-gray-400">
                    {{ __('loops.cards.progression.empty_body') }}
                </p>
            </div>
        @else
            <div class="space-y-3">
                @foreach ($overview as $index => $entree)
                    @php $module = $entree['module']; @endphp
                    <section class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800"
                             aria-labelledby="progress-module-{{ $module->id }}">
                        <header class="mb-3 flex items-center justify-between gap-3">
                            <h3 id="progress-module-{{ $module->id }}" class="flex min-w-0 items-baseline gap-2 text-sm font-semibold text-gray-900 dark:text-gray-100">
                                <span class="font-mono text-xs tabular-nums text-gray-400" aria-hidden="true">{{ str_pad($index + 1, 2, '0', STR_PAD_LEFT) }}</span>
                                <span class="truncate">{{ $module->title }}</span>
                            </h3>
                            <span class="shrink-0 rounded-full px-2 py-0.5 text-[11px] font-semibold {{ $tons[$entree['status']] ?? $tons['unavailable'] }}">
                                {{ __('loops.cards.progression.status_'.$entree['status']) }}
                            </span>
                        </header>

                        <ol class="space-y-1.5">
                            @foreach ($entree['sequences'] as $ligne)
                                @php $sequence = $ligne['sequence']; $etat = $ligne['status']; @endphp
                                <li class="rounded-lg border border-gray-100 px-2.5 py-2 dark:border-gray-700">
                                    <div class="flex items-center gap-2.5">
                                        <span class="shrink-0 font-mono text-[11px] tabular-nums text-gray-400" aria-hidden="true">{{ $ligne['number'] }}</span>

                                        <span class="min-w-0 flex-1 truncate text-sm {{ $etat === 'unavailable' ? 'text-gray-400 dark:text-gray-500' : 'text-gray-800 dark:text-gray-200' }}">
                                            <span class="sr-only">{{ __('dossiers.series_position_label', ['number' => (int) $ligne['number']]) }} —</span>{{ $sequence->displayName() }}
                                        </span>

                                        <span class="shrink-0 rounded-full px-2 py-0.5 text-[10px] font-semibold {{ $tons[$etat] ?? $tons['unavailable'] }}">
                                            {{ __('loops.cards.progression.status_'.$etat) }}
                                        </span>

                                        @if ($etat === 'available')
                                            <button type="button" wire:click="open('{{ $sequence->id }}')"
                                                    class="shrink-0 rounded-lg px-2 py-1 text-xs font-semibold text-indigo-600 hover:bg-indigo-50 dark:text-indigo-400 dark:hover:bg-indigo-500/15">
                                                {{ __('loops.cards.progression.open') }}
                                            </button>
                                        @elseif (in_array($etat, ['in_progress', 'redo'], true))
                                            <button type="button" wire:click="markDone('{{ $sequence->id }}')"
                                                    class="shrink-0 rounded-lg bg-indigo-600 px-2 py-1 text-xs font-semibold text-white hover:bg-indigo-700">
                                                {{ __('loops.cards.progression.mark_done') }}
                                            </button>
                                        @endif
                                    </div>

                                    @if ($etat === 'unavailable')
                                        {{-- La raison du verrou est dite. Une etape qu'on voit
                                             venir n'est pas un mur, c'est un plan. --}}
                                        <p class="mt-1 text-[11px] text-gray-400 dark:text-gray-500">
                                            {{ __('loops.cards.progression.locked_reason') }}
                                        </p>
                                    @endif
                                </li>
                            @endforeach
                        </ol>
                    </section>
                @endforeach
            </div>
        @endif
    @endif

    {{-- ── Visage 2 : tout le monde ───────────────────────────────────── --}}
    @if ($face === 'everyone' && $canManage)
        @if ($matrix->isEmpty())
            <p class="px-4 py-10 text-center text-sm text-gray-500 dark:text-gray-400">
                {{ __('loops.cards.progression.no_members') }}
            </p>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <caption class="sr-only">{{ __('loops.cards.progression.everyone') }}</caption>
                    <thead>
                        <tr class="border-b border-gray-200 dark:border-gray-700">
                            <th scope="col" class="py-2 pr-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400">
                                {{ __('loops.cards.members.label') }}
                            </th>
                            @foreach ($matrix->first()['modules'] as $colonne)
                                <th scope="col" class="px-2 py-2 text-left text-xs font-semibold text-gray-500 dark:text-gray-400">
                                    <span class="block max-w-[8rem] truncate">{{ $colonne['module']->title }}</span>
                                </th>
                            @endforeach
                            <th scope="col" class="px-2 py-2 text-left text-xs font-semibold text-gray-500 dark:text-gray-400">
                                {{ __('loops.cards.progression.status_submitted') }}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($matrix as $ligne)
                            <tr class="border-b border-gray-100 dark:border-gray-700/60">
                                <th scope="row" class="py-2 pr-3 text-left font-medium text-gray-800 dark:text-gray-200">
                                    <span class="block max-w-[10rem] truncate">{{ $ligne['user']->first_name ?? $ligne['user']->name }}</span>
                                </th>
                                @foreach ($ligne['modules'] as $cellule)
                                    <td class="px-2 py-2">
                                        {{-- La cellule se deplie : la matrice donne l'etat par
                                             Module, mais valider ou debloquer se fait sur une
                                             **Sequence**. --}}
                                        <button type="button"
                                                wire:click="toggleCell('{{ $cellule['module']->id }}', '{{ $ligne['user']->id }}')"
                                                aria-expanded="{{ $openModuleId === $cellule['module']->id && $openUserId === $ligne['user']->id ? 'true' : 'false' }}"
                                                class="inline-block rounded-full px-2 py-0.5 text-[10px] font-semibold transition-opacity hover:opacity-80 {{ $tons[$cellule['status']] ?? $tons['unavailable'] }}">
                                            {{ __('loops.cards.progression.status_'.$cellule['status']) }}
                                        </button>
                                    </td>
                                @endforeach
                                <td class="px-2 py-2 text-xs text-gray-600 dark:text-gray-300">
                                    {{ trans_choice('loops.cards.progression.awaiting_one', $ligne['awaiting'], ['count' => $ligne['awaiting']]) }}
                                </td>
                            </tr>

                            @if ($openUserId === $ligne['user']->id && $cellDetail->isNotEmpty())
                                <tr class="bg-gray-50 dark:bg-gray-900/40">
                                    <td colspan="{{ $ligne['modules']->count() + 2 }}" class="px-3 py-3">
                                        <ol class="space-y-1.5">
                                            @foreach ($cellDetail as $detail)
                                                @php $seq = $detail['sequence']; $etatSeq = $detail['status']; @endphp
                                                <li class="flex flex-wrap items-center gap-2 rounded-lg bg-white px-2.5 py-2 dark:bg-gray-800">
                                                    <span class="shrink-0 font-mono text-[11px] tabular-nums text-gray-400" aria-hidden="true">{{ $detail['number'] }}</span>
                                                    <span class="min-w-0 flex-1 truncate text-sm text-gray-800 dark:text-gray-200">{{ $seq->displayName() }}</span>
                                                    <span class="shrink-0 rounded-full px-2 py-0.5 text-[10px] font-semibold {{ $tons[$etatSeq] ?? $tons['unavailable'] }}">
                                                        {{ __('loops.cards.progression.status_'.$etatSeq) }}
                                                    </span>

                                                    @if ($etatSeq === 'submitted')
                                                        <button type="button" wire:click="validateFor('{{ $seq->id }}', '{{ $ligne['user']->id }}')"
                                                                class="shrink-0 rounded-lg bg-emerald-600 px-2 py-1 text-[11px] font-semibold text-white hover:bg-emerald-700">
                                                            {{ __('loops.cards.progression.validate') }}
                                                        </button>
                                                        <button type="button" wire:click="requestRedoFor('{{ $seq->id }}', '{{ $ligne['user']->id }}')"
                                                                class="shrink-0 rounded-lg border border-rose-300 px-2 py-1 text-[11px] font-semibold text-rose-700 hover:bg-rose-50 dark:border-rose-500/40 dark:text-rose-300">
                                                            {{ __('loops.cards.progression.request_redo') }}
                                                        </button>
                                                    @elseif ($etatSeq === 'unavailable')
                                                        <button type="button" wire:click="unlockFor('{{ $seq->id }}', '{{ $ligne['user']->id }}')"
                                                                class="shrink-0 rounded-lg border border-gray-300 px-2 py-1 text-[11px] font-semibold text-gray-700 hover:border-indigo-400 hover:text-indigo-600 dark:border-gray-600 dark:text-gray-300">
                                                            {{ __('loops.cards.progression.unlock') }}
                                                        </button>
                                                    @endif
                                                </li>
                                            @endforeach
                                        </ol>
                                    </td>
                                </tr>
                            @endif
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    @endif

@endif
</div>
