@php
    use App\Support\Loops\LoopCatchUpDigest;
    use App\Support\Loops\LoopCatchUpWindow;

    $_org = request()->route('organization');
    $_loopRoute = function ($name, $params = []) use ($_org) {
        if ($_org && request()->routeIs('organization.*') && Route::has('organization.loops.'.$name)) {
            return route('organization.loops.'.$name, array_merge(['organization' => $_org], $params));
        }
        return route('loops.'.$name, $params);
    };
    $_dossierRoute = function ($id) use ($_org) {
        if ($_org && Route::has('organization.dossiers.show')) {
            return route('organization.dossiers.show', ['organization' => $_org, 'dossier' => $id]);
        }
        return Route::has('dossiers.show') ? route('dossiers.show', ['dossier' => $id]) : null;
    };
    $loopUrl = $_loopRoute('show', ['loop' => $loop->id]);
@endphp

<x-app-layout>
    <x-slot name="title">{{ __('loops.catch_up_title') }} — {{ $loop->name }}</x-slot>

    <div class="mx-auto w-full max-w-3xl px-4 py-6 sm:px-6 lg:px-8" data-catch-up-page>
        <a href="{{ $loopUrl }}" class="inline-flex items-center gap-1.5 text-sm text-gray-500 hover:text-gray-800 dark:text-gray-400 dark:hover:text-gray-200">
            &larr; {{ $loop->name }}
        </a>

        <h1 class="mt-3 text-2xl font-bold tracking-tight text-gray-900 dark:text-gray-100">
            {{ __('loops.catch_up_title') }}
        </h1>
        <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
            {{ __('loops.catch_up_subtitle', ['loop' => $loop->name]) }}
        </p>

        {{-- La periode. Toujours choisie, jamais devinee : c'est le coeur du contrat. --}}
        <form method="GET" action="{{ url()->current() }}" class="mt-5 rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800" data-catch-up-period>
            <fieldset>
                <legend class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ __('loops.catch_up_period_legend') }}</legend>

                <div class="mt-3 flex flex-wrap gap-2">
                    @foreach (LoopCatchUpWindow::ALLOWED_DAYS as $days)
                        <button type="submit" name="days" value="{{ $days }}"
                                data-catch-up-days="{{ $days }}"
                                aria-pressed="{{ $window->days === $days ? 'true' : 'false' }}"
                                class="bp-catchup-chip min-h-11 rounded-full border border-gray-300 px-4 text-sm font-medium text-gray-700 transition hover:border-gray-400 dark:border-gray-600 dark:text-gray-200">
                            {{ __('loops.catch_up_days_'.$days) }}
                        </button>
                    @endforeach
                </div>

                <div class="mt-3 flex flex-wrap items-end gap-2">
                    <label class="text-xs text-gray-600 dark:text-gray-400">
                        <span class="block">{{ __('loops.catch_up_since_label') }}</span>
                        <input type="date" name="since" data-catch-up-since
                               value="{{ $window->days === null ? $window->since->format('Y-m-d') : '' }}"
                               max="{{ $window->until->format('Y-m-d') }}"
                               class="mt-1 min-h-11 rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                    </label>
                    <button type="submit" class="bp-catchup-apply min-h-11 rounded-lg px-4 text-sm font-semibold text-white">
                        {{ __('loops.catch_up_apply') }}
                    </button>
                </div>
            </fieldset>
        </form>

        {{-- La fenetre est NOMMEE. Sans cette phrase, l'ecran laisserait croire qu'il sait ce qui a ete lu. --}}
        <p class="mt-3 text-sm font-medium text-gray-800 dark:text-gray-200" data-catch-up-window>
            @if ($window->isDefault)
                {{ __('loops.catch_up_window_default', ['since' => $window->since->isoFormat('LL'), 'until' => $window->until->isoFormat('LL')]) }}
            @else
                {{ __('loops.catch_up_window', ['since' => $window->since->isoFormat('LL'), 'until' => $window->until->isoFormat('LL')]) }}
            @endif
        </p>
        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400" data-catch-up-disclaimer>
            {{ __('loops.catch_up_no_reading_position') }}
        </p>

        @if ($isEmpty)
            <div class="mt-6 rounded-xl border border-dashed border-gray-300 p-6 text-center dark:border-gray-600" data-catch-up-empty>
                <p class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('loops.catch_up_empty') }}</p>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('loops.catch_up_empty_hint') }}</p>
            </div>
        @else
            <div class="mt-6 space-y-6">
                @foreach (LoopCatchUpDigest::SECTIONS as $key)
                    @php($section = $sections[$key])
                    <section data-catch-up-section="{{ $key }}" class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
                        <h2 class="flex items-baseline gap-2 text-sm font-bold uppercase tracking-wide text-gray-900 dark:text-gray-100">
                            {{ __('loops.catch_up_section_'.$key) }}
                            <span class="text-xs font-normal text-gray-500 dark:text-gray-400" data-catch-up-total="{{ $section['total'] }}">{{ $section['total'] }}</span>
                        </h2>

                        @if ($section['total'] === 0)
                            <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">{{ __('loops.catch_up_section_'.$key.'_empty') }}</p>
                        @else
                            <ul class="mt-3 space-y-3">
                                @foreach ($section['items'] as $item)
                                    <li class="border-l-2 border-gray-200 pl-3 dark:border-gray-600" data-catch-up-item>
                                        <p class="text-sm text-gray-900 dark:text-gray-100">{{ $item['title'] }}</p>

                                        <p class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-gray-500 dark:text-gray-400">
                                            @if (!empty($item['pinned']))
                                                <span class="rounded bg-amber-100 px-1.5 py-0.5 font-medium text-amber-900 dark:bg-amber-900/40 dark:text-amber-200">{{ __('loops.catch_up_pinned') }}</span>
                                            @endif

                                            @if (!empty($item['kind']) && $item['kind'] !== 'user')
                                                <span class="rounded bg-gray-100 px-1.5 py-0.5 font-medium text-gray-700 dark:bg-gray-700 dark:text-gray-200">{{ __('loops.catch_up_kind_'.$item['kind']) }}</span>
                                            @endif

                                            @if (!empty($item['author']))
                                                <span>{{ __('loops.catch_up_by', ['name' => $item['author']]) }}</span>
                                            @endif

                                            @if (!empty($item['at']))
                                                <time datetime="{{ $item['at']->toIso8601String() }}">{{ $item['at']->isoFormat('LL') }}</time>
                                            @endif

                                            @if (!empty($item['from_message']))
                                                <span>{{ __('loops.catch_up_decision_from_message') }}</span>
                                            @endif
                                        </p>

                                        {{-- La source. Un lien n'est propose que s'il mene quelque part ou ce lecteur a le droit d'aller. --}}
                                        @if ($key === LoopCatchUpDigest::SECTION_DOCUMENTS)
                                            @if (!empty($item['dossier_readable']) && ($dossierUrl = $_dossierRoute($item['dossier_id'])))
                                                <a href="{{ $dossierUrl }}" data-catch-up-source="dossier"
                                                   class="mt-1 inline-block text-xs font-medium underline decoration-dotted underline-offset-2 text-gray-600 hover:text-gray-900 dark:text-gray-300 dark:hover:text-white">
                                                    {{ __('loops.catch_up_source_dossier', ['name' => $item['dossier']]) }}
                                                </a>
                                            @else
                                                <span class="mt-1 inline-block text-xs text-gray-400 dark:text-gray-500" data-catch-up-source="dossier-locked">
                                                    {{ __('loops.catch_up_source_dossier_locked', ['name' => $item['dossier']]) }}
                                                </span>
                                            @endif
                                        @else
                                            <a href="{{ $loopUrl }}" data-catch-up-source="loop"
                                               class="mt-1 inline-block text-xs font-medium underline decoration-dotted underline-offset-2 text-gray-600 hover:text-gray-900 dark:text-gray-300 dark:hover:text-white">
                                                {{ __('loops.catch_up_source_loop') }}
                                            </a>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>

                            @if ($section['total'] > $section['shown'])
                                <p class="mt-3 text-xs text-gray-500 dark:text-gray-400" data-catch-up-more>
                                    {{ trans_choice('loops.catch_up_more', $section['total'] - $section['shown'], ['count' => $section['total'] - $section['shown']]) }}
                                </p>
                            @endif
                        @endif
                    </section>
                @endforeach
            </div>
        @endif

        <p class="mt-6 text-xs text-gray-500 dark:text-gray-400" data-catch-up-no-ai>
            {{ __('loops.catch_up_no_ai') }}
        </p>
    </div>

    {{-- Feuille locale : une classe utilitaire arbitraire absente du build laisserait ces
         boutons TRANSPARENTS sans un mot. Le repli protege le cas ou le token manquerait. --}}
    <style>
        .bp-catchup-apply{background:var(--bp-primary,#4f46e5)}
        .bp-catchup-apply:hover{background:var(--bp-primary-deep,#4338ca)}
        .bp-catchup-chip[aria-pressed="true"]{background:var(--bp-primary,#4f46e5);border-color:var(--bp-primary,#4f46e5);color:#fff}
    </style>
</x-app-layout>
