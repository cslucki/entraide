{{--
    Le configurateur d'une Boucle.

    Trois zones, dans l'ordre où on les lit : ce qui est permanent et ne se
    discute pas, les trois emplacements qui font l'identité de la Boucle, et le
    catalogue de ce qu'on peut y poser.

    Aucun geste ici ne supprime de donnée. Une Card retirée est éteinte : ses
    éléments attendent son retour, et l'écran le dit à chaque fois plutôt que de
    le laisser deviner.
--}}
@php
    // La meme vue sert les deux administrations : seules les routes changent.
    // Dupliquer l'ecran aurait garanti qu'ils divergent a la premiere evolution.
    $composeUrl = $scopedRoutes['compose'] ?? route('admin.loops.compose', $loop);
    $presetUrl = $scopedRoutes['preset'] ?? route('admin.loops.preset.apply', $loop);
@endphp
<x-app-layout :title="__('loops.preset_grid_title')">
    <x-page-container>

        <div class="mb-6 flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <a href="{{ $backUrl }}" class="text-xs font-semibold text-violet-700 hover:underline dark:text-violet-300">← {{ $loop->name }}</a>
                <h1 class="mt-1 text-2xl font-bold text-gray-900 dark:text-gray-100">{{ __('loops.preset_grid_title') }}</h1>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    {{ __('loops.preset_grid_hint', ['slots' => $composition['slots']]) }}
                </p>
            </div>
            <span class="shrink-0 rounded-full bg-violet-100 px-3 py-1 text-xs font-semibold text-violet-700 dark:bg-violet-900/40 dark:text-violet-300">
                {{ $composition['preset_label'] }}
            </span>
        </div>

        @if(session('success'))
            <p class="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-2.5 text-sm text-emerald-700 dark:border-emerald-800/60 dark:bg-emerald-900/20 dark:text-emerald-300">{{ session('success') }}</p>
        @endif
        @if(session('error'))
            <p class="mb-4 rounded-xl border border-red-200 bg-red-50 px-4 py-2.5 text-sm text-red-700 dark:border-red-800/60 dark:bg-red-900/20 dark:text-red-300" role="alert">{{ session('error') }}</p>
        @endif

        {{-- ── Le cadre permanent ─────────────────────────────────────── --}}
        <section class="mb-6 rounded-2xl border border-gray-200 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-900/60">
            <p class="text-sm font-semibold text-gray-800 dark:text-gray-100">{{ __('loops.preset_frame_title') }}</p>
            <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ __('loops.preset_frame_hint') }}</p>

            <div class="mt-3 flex flex-wrap gap-2">
                <span class="inline-flex items-center gap-1.5 rounded-xl border border-gray-200 bg-white px-3 py-1.5 text-xs font-semibold text-gray-600 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300">
                    ChatLoop
                </span>
                @foreach($composition['frame'] as $card)
                    <span class="inline-flex items-center gap-1.5 rounded-xl border border-gray-200 bg-white px-3 py-1.5 text-xs font-semibold text-gray-600 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300"
                          title="{{ $card['description'] }}">
                        {{ $card['label'] }}
                        @if($card['data_count'] !== null)
                            <span class="text-[10px] font-normal text-gray-400">{{ __('loops.preset_data_count', ['count' => $card['data_count']]) }}</span>
                        @endif
                    </span>
                @endforeach
            </div>
        </section>

        {{-- ── Les emplacements distinctifs ───────────────────────────── --}}
        <section class="mb-6">
            <p class="mb-3 text-sm font-semibold text-gray-800 dark:text-gray-100">
                {{ __('loops.preset_grid_title') }}
                <span class="ml-1 text-xs font-normal text-gray-400">{{ count($composition['grid']) }} / {{ $composition['slots'] }}</span>
            </p>

            <div class="grid gap-3 sm:grid-cols-3">
                @foreach($composition['grid'] as $card)
                    <div class="rounded-2xl border border-violet-200 bg-white p-4 dark:border-violet-800/60 dark:bg-gray-900">
                        <p class="text-sm font-bold text-gray-900 dark:text-gray-100">{{ $card['label'] }}</p>
                        <p class="mt-1 text-xs leading-5 text-gray-500 dark:text-gray-400">{{ $card['description'] }}</p>

                        <p class="mt-2 flex flex-wrap items-center gap-1.5 text-[11px]">
                            <span class="rounded-full bg-gray-100 px-2 py-0.5 text-gray-500 dark:bg-gray-800 dark:text-gray-400">{{ $card['category'] }}</span>
                            @if($card['origin'] === 'preset')
                                <span class="rounded-full bg-violet-100 px-2 py-0.5 font-semibold text-violet-700 dark:bg-violet-900/40 dark:text-violet-300">{{ __('loops.cards_origin_preset') }}</span>
                            @elseif($card['origin'] === 'local')
                                <span class="rounded-full bg-amber-100 px-2 py-0.5 font-semibold text-amber-700 dark:bg-amber-900/40 dark:text-amber-300">{{ __('loops.cards_origin_local') }}</span>
                            @endif
                            @if($card['data_count'] !== null)
                                <span class="text-gray-400">{{ __('loops.preset_data_count', ['count' => $card['data_count']]) }}</span>
                            @endif
                        </p>

                        @if($card['requires'] !== [])
                            <p class="mt-1.5 text-[11px] text-gray-400 dark:text-gray-500">
                                {{ __('loops.preset_requires_label', ['cards' => collect($card['requires'])->implode(', ')]) }}
                            </p>
                        @endif

                        @unless($card['required'])
                            <form method="POST" action="{{ $composeUrl }}" class="mt-3">
                                @csrf
                                <input type="hidden" name="action" value="disable">
                                <input type="hidden" name="card_key" value="{{ $card['key'] }}">
                                <button type="submit"
                                        class="w-full rounded-lg border border-gray-300 px-3 py-1.5 text-xs font-semibold text-gray-600 transition hover:bg-gray-100 dark:border-gray-600 dark:text-gray-300 dark:hover:bg-gray-800">
                                    {{ __('loops.cards_disable') }}
                                </button>
                            </form>
                        @endunless
                    </div>
                @endforeach

                {{-- Les emplacements libres se voient : une zone vide est une
                     invitation, une zone absente est un oubli. --}}
                @for($i = count($composition['grid']); $i < $composition['slots']; $i++)
                    <div class="flex min-h-[7rem] items-center justify-center rounded-2xl border border-dashed border-gray-300 bg-white p-4 text-xs text-gray-400 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-500">
                        {{ __('loops.preset_slot_empty') }}
                    </div>
                @endfor
            </div>
        </section>

        {{-- ── Le catalogue ───────────────────────────────────────────── --}}
        @if($composition['available'] !== [])
            <section class="mb-6">
                <p class="mb-3 text-sm font-semibold text-gray-800 dark:text-gray-100">{{ __('loops.preset_available_title') }}</p>

                <div class="grid gap-3 sm:grid-cols-3">
                    @foreach($composition['available'] as $card)
                        @php($blocked = $card['blockers']['missing'] !== [] || $card['blockers']['conflicting'] !== [])
                        <div class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900 {{ $blocked ? 'opacity-70' : '' }}">
                            <p class="text-sm font-bold text-gray-900 dark:text-gray-100">{{ $card['label'] }}</p>
                            <p class="mt-1 text-xs leading-5 text-gray-500 dark:text-gray-400">{{ $card['description'] }}</p>

                            @if($card['data_count'] !== null && $card['data_count'] > 0)
                                {{-- Une Card éteinte qui porte des données : on le
                                     dit, pour qu'on sache qu'on retrouve quelque
                                     chose en la rallumant. --}}
                                <p class="mt-1.5 text-[11px] text-amber-600 dark:text-amber-400">
                                    {{ __('loops.preset_data_count', ['count' => $card['data_count']]) }}
                                </p>
                            @endif

                            @if($card['blockers']['missing'] !== [])
                                <p class="mt-1.5 text-[11px] text-gray-400 dark:text-gray-500">
                                    {{ __('loops.preset_requires_label', ['cards' => collect($card['blockers']['missing'])->implode(', ')]) }}
                                </p>
                            @endif

                            <form method="POST" action="{{ $composeUrl }}" class="mt-3">
                                @csrf
                                <input type="hidden" name="action" value="enable">
                                <input type="hidden" name="card_key" value="{{ $card['key'] }}">
                                <button type="submit" @disabled($blocked)
                                        class="w-full rounded-lg bg-violet-600 px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-violet-700 disabled:cursor-not-allowed disabled:bg-gray-300 dark:disabled:bg-gray-700">
                                    {{ __('loops.cards_enable') }}
                                </button>
                            </form>
                        </div>
                    @endforeach
                </div>
            </section>
        @endif

        {{-- ── Changer de preset ──────────────────────────────────────── --}}
        <section class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
            <p class="text-sm font-semibold text-gray-800 dark:text-gray-100">{{ __('loops.preset_change_title') }}</p>

            <form method="POST" action="{{ $presetUrl }}" class="mt-3 space-y-3">
                @csrf
                <select name="type"
                        class="w-full rounded-xl border-gray-300 bg-white text-sm text-gray-900 focus:border-violet-500 focus:ring-violet-500 dark:border-gray-600 dark:bg-gray-950 dark:text-gray-100">
                    {{-- Le registre, jamais la définition : un type créé n'a pas
                         de clé de traduction, et le mot se lit dans la portée de
                         la Boucle. --}}
                    @foreach($types as $key => $definition)
                        <option value="{{ $key }}" @selected($key === $composition['preset'])>{{ $typeRegistry->label($key, $organization) }}</option>
                    @endforeach
                </select>

                {{-- Par défaut, changer de preset AJOUTE et ne retire rien : c'est
                     la règle additive livrée par TASK-1086. Retirer se demande
                     explicitement. --}}
                <label class="flex items-start gap-2 text-xs text-gray-600 dark:text-gray-300">
                    <input type="checkbox" name="deactivate_absent" value="1" class="mt-0.5 rounded text-violet-600 focus:ring-violet-500">
                    <span>{{ __('loops.preset_change_deactivate_absent') }}</span>
                </label>

                <div class="flex flex-wrap gap-2">
                    <button type="submit"
                            class="rounded-lg bg-violet-600 px-4 py-2 text-xs font-semibold text-white transition hover:bg-violet-700">
                        {{ __('loops.preset_change_confirm') }}
                    </button>
                </div>
            </form>

            <form method="POST" action="{{ $composeUrl }}" class="mt-3 border-t border-gray-100 pt-3 dark:border-gray-800">
                @csrf
                <input type="hidden" name="action" value="restore">
                <button type="submit"
                        class="rounded-lg border border-gray-300 px-4 py-2 text-xs font-semibold text-gray-600 transition hover:bg-gray-100 dark:border-gray-600 dark:text-gray-300 dark:hover:bg-gray-800">
                    {{ __('loops.preset_restore') }}
                </button>
            </form>
        </section>

    </x-page-container>
</x-app-layout>
