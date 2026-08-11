{{--
    L'apercu pedagogique d'un outil — a quoi il ressemble, avant de l'ajouter.

    De petites silhouettes en pur Blade/CSS, inspirees du langage visuel des
    vraies Cards : listes a puces, barres de progression, dates en badge. Rien
    d'autre. Elles n'enregistrent rien, n'appellent aucun service, ne simulent
    aucune donnee reelle — les mots viennent de `loops.tool_previews`, et ce
    sont des mots d'exemple, pas du contenu.

    `aria-hidden` + `pointer-events-none` : c'est une illustration. Le sens
    reste porte par le titre et la description de la carte, jamais par
    l'apercu seul.
--}}
@props(['tool', 'variant' => 'card'])

@php
    $t = fn (string $suffixe) => __('loops.tool_previews.'.$suffixe);

    // `band` : l'apercu occupe le tiers haut d'une carte de catalogue — plus
    // grand, sans son propre cadre, centre verticalement. `card` : la vignette
    // bordee d'origine.
    $cadre = $variant === 'band'
        ? 'pointer-events-none flex min-h-[8.5rem] select-none flex-col justify-center text-xs leading-snug'
        : 'pointer-events-none select-none rounded-xl border border-gray-100 bg-gray-50/80 p-3 text-[11px] leading-tight dark:border-gray-700/60 dark:bg-gray-900/40';

    // Une silhouette par outil grid. Un outil sans apercu ne montre rien :
    // pas de cadre vide, la carte se contente de son icone et de son texte.
    $silhouettes = [
        'core.polls', 'core.events', 'core.roadmap', 'core.decisions',
        'core.dossiers', 'training.course_material', 'training.progression',
        'training.assignments', 'training.quiz', 'core.journal',
        'core.article', 'core.marketplace',
    ];
@endphp

@if(in_array($tool, $silhouettes, true))
    <div aria-hidden="true" {{ $attributes->class([$cadre]) }}>

        @switch($tool)
            @case('core.polls')
                <p class="font-semibold text-gray-700 dark:text-gray-200">{{ $t('polls_question') }}</p>
                <div class="mt-2 space-y-1.5">
                    @foreach(['polls_option_1' => 'w-4/5', 'polls_option_2' => 'w-3/5', 'polls_option_3' => 'w-2/5'] as $option => $largeur)
                        <div class="flex items-center gap-2">
                            <span class="h-2.5 w-2.5 shrink-0 rounded-full border border-fuchsia-300 dark:border-fuchsia-500/60"></span>
                            <span class="text-gray-500 dark:text-gray-400">{{ $t($option) }}</span>
                            <span class="h-1.5 {{ $largeur }} rounded-full bg-fuchsia-200 dark:bg-fuchsia-500/30"></span>
                        </div>
                    @endforeach
                </div>
                @break

            @case('core.events')
                <div class="flex items-center gap-3">
                    <div class="flex h-10 w-10 shrink-0 flex-col items-center justify-center rounded-lg bg-indigo-100 dark:bg-indigo-500/20">
                        <span class="text-sm font-bold leading-none text-indigo-600 dark:text-indigo-300">{{ $t('events_day') }}</span>
                        <span class="text-[9px] uppercase text-indigo-500 dark:text-indigo-400">{{ $t('events_month') }}</span>
                    </div>
                    <div>
                        <p class="font-semibold text-gray-700 dark:text-gray-200">{{ $t('events_title') }}</p>
                        <p class="text-gray-500 dark:text-gray-400">{{ $t('events_note') }}</p>
                    </div>
                </div>
                @break

            @case('core.roadmap')
                <ol class="space-y-1.5">
                    @foreach(['roadmap_step_1', 'roadmap_step_2', 'roadmap_step_3'] as $i => $etape)
                        <li class="flex items-center gap-2">
                            <span class="flex h-4 w-4 shrink-0 items-center justify-center rounded-full text-[9px] font-bold {{ $i === 0 ? 'bg-emerald-200 text-emerald-700 dark:bg-emerald-500/30 dark:text-emerald-300' : 'bg-gray-200 text-gray-500 dark:bg-gray-700 dark:text-gray-400' }}">{{ $i + 1 }}</span>
                            <span class="{{ $i === 0 ? 'font-semibold text-gray-700 dark:text-gray-200' : 'text-gray-500 dark:text-gray-400' }}">{{ $t($etape) }}</span>
                        </li>
                    @endforeach
                </ol>
                @break

            @case('core.decisions')
                <div class="flex items-start gap-2">
                    <span class="mt-0.5 flex h-4 w-4 shrink-0 items-center justify-center rounded-full bg-emerald-200 dark:bg-emerald-500/30">
                        <svg class="h-2.5 w-2.5 text-emerald-700 dark:text-emerald-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/></svg>
                    </span>
                    <div>
                        <p class="font-semibold text-gray-700 dark:text-gray-200">{{ $t('decisions_title') }}</p>
                        <p class="text-gray-500 dark:text-gray-400">{{ $t('decisions_date') }}</p>
                    </div>
                </div>
                @break

            @case('core.dossiers')
                <div class="space-y-1.5">
                    <p class="flex items-center gap-2 font-semibold text-gray-700 dark:text-gray-200">
                        <x-loops.card-icon icon="folder" size="sm" /> {{ $t('dossiers_folder') }}
                    </p>
                    <p class="flex items-center gap-2 pl-4 text-gray-500 dark:text-gray-400">
                        <x-loops.card-icon icon="pencil" size="sm" /> {{ $t('dossiers_article') }}
                    </p>
                    <p class="flex items-center gap-2 pl-4 text-gray-500 dark:text-gray-400">
                        <x-loops.card-icon icon="document" size="sm" /> {{ $t('dossiers_file') }}
                    </p>
                </div>
                @break

            @case('training.course_material')
                <p class="font-semibold text-gray-700 dark:text-gray-200">{{ $t('course_module') }}</p>
                <div class="mt-2 space-y-1.5">
                    @foreach(['course_seq_1', 'course_seq_2'] as $seq)
                        <div class="flex items-center gap-2 pl-2">
                            <span class="h-1.5 w-1.5 shrink-0 rounded-full bg-blue-300 dark:bg-blue-500/50"></span>
                            <span class="text-gray-500 dark:text-gray-400">{{ $t($seq) }}</span>
                        </div>
                    @endforeach
                </div>
                @break

            @case('training.progression')
                <div class="flex items-center justify-between">
                    <p class="font-semibold text-gray-700 dark:text-gray-200">{{ $t('progression_name') }}</p>
                    <p class="text-gray-500 dark:text-gray-400">{{ $t('progression_done') }}</p>
                </div>
                <div class="mt-2 h-2 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700">
                    <div class="h-full w-2/3 rounded-full bg-teal-400 dark:bg-teal-500/70"></div>
                </div>
                @break

            @case('training.assignments')
                <p class="font-semibold text-gray-700 dark:text-gray-200">{{ $t('assignments_title') }}</p>
                <p class="mt-1.5 inline-flex rounded-full bg-orange-100 px-2 py-0.5 font-semibold text-orange-700 dark:bg-orange-500/20 dark:text-orange-300">{{ $t('assignments_state') }}</p>
                @break

            @case('training.quiz')
                <p class="font-semibold text-gray-700 dark:text-gray-200">{{ $t('quiz_question') }}</p>
                <div class="mt-2 space-y-1.5">
                    <div class="flex items-center gap-2">
                        <span class="flex h-3 w-3 shrink-0 items-center justify-center rounded-full border-2 border-purple-400 dark:border-purple-500/70"><span class="h-1.5 w-1.5 rounded-full bg-purple-400 dark:bg-purple-500/70"></span></span>
                        <span class="text-gray-600 dark:text-gray-300">{{ $t('quiz_choice_1') }}</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="h-3 w-3 shrink-0 rounded-full border-2 border-gray-300 dark:border-gray-600"></span>
                        <span class="text-gray-500 dark:text-gray-400">{{ $t('quiz_choice_2') }}</span>
                    </div>
                </div>
                @break

            @case('core.journal')
                <p class="text-[10px] font-semibold uppercase tracking-wide text-stone-500 dark:text-stone-400">{{ $t('journal_date') }}</p>
                <p class="mt-1 text-gray-600 dark:text-gray-300">{{ $t('journal_entry') }}</p>
                @break

            @case('core.article')
                <p class="font-semibold text-gray-700 dark:text-gray-200">{{ $t('article_title') }}</p>
                <p class="mt-1 text-gray-500 dark:text-gray-400">{{ $t('article_body') }}</p>
                <div class="mt-2 space-y-1">
                    <div class="h-1 w-full rounded-full bg-gray-200 dark:bg-gray-700"></div>
                    <div class="h-1 w-3/4 rounded-full bg-gray-200 dark:bg-gray-700"></div>
                </div>
                @break

            @case('core.marketplace')
                <div class="space-y-1.5">
                    <p class="inline-flex items-center gap-1.5 rounded-full bg-lime-100 px-2 py-0.5 font-semibold text-lime-700 dark:bg-lime-500/20 dark:text-lime-300">→ {{ $t('marketplace_ask') }}</p>
                    <br>
                    <p class="inline-flex items-center gap-1.5 rounded-full bg-amber-100 px-2 py-0.5 font-semibold text-amber-700 dark:bg-amber-500/20 dark:text-amber-300">← {{ $t('marketplace_offer') }}</p>
                </div>
                @break
        @endswitch
    </div>
@endif
