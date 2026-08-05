{{--
    L'agenda d'une Organization.

    Une page de lecture : elle rassemble, elle n'organise pas. Proposer une
    rencontre se fait dans la Boucle, la ou vivent les gens qui y viendront.

    Chaque ligne est le meme partiel que dans la Card, en mode non interactif :
    les deux ecrans montrent la meme chose parce qu'ils partagent le balisage.
--}}
<x-app-layout :title="__('events.agenda_title')">
    <x-page-container>
        <div class="mb-6">
            <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">{{ __('events.agenda_title') }}</h1>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('events.agenda_subtitle') }}</p>
        </div>

        {{-- Filtres en GET : une vue filtree se partage par son URL. --}}
        <form method="GET" class="mb-5 flex flex-wrap items-center gap-2">
            <div class="inline-flex rounded-lg border border-gray-300 p-0.5 dark:border-gray-600">
                @foreach(['upcoming' => __('events.filter_upcoming'), 'past' => __('events.filter_past')] as $value => $label)
                    <a href="{{ request()->fullUrlWithQuery(['when' => $value]) }}"
                       class="rounded-md px-3 py-1 text-xs font-semibold transition {{ $when === $value
                            ? 'bg-sky-600 text-white'
                            : 'text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800' }}">
                        {{ $label }}
                    </a>
                @endforeach
            </div>

            <label class="sr-only" for="agenda-loop">{{ __('events.filter_all_loops') }}</label>
            <select id="agenda-loop" name="loop" onchange="this.form.submit()"
                    class="rounded-xl border-gray-300 bg-white text-xs text-gray-900 focus:border-sky-500 focus:ring-sky-500 dark:border-gray-600 dark:bg-gray-950 dark:text-gray-100">
                <option value="">{{ __('events.filter_all_loops') }}</option>
                @foreach($loops as $id => $name)
                    <option value="{{ $id }}" @selected($loopFilter === $id)>{{ $name }}</option>
                @endforeach
            </select>

            <label class="sr-only" for="agenda-format">{{ __('events.filter_all_formats') }}</label>
            <select id="agenda-format" name="format" onchange="this.form.submit()"
                    class="rounded-xl border-gray-300 bg-white text-xs text-gray-900 focus:border-sky-500 focus:ring-sky-500 dark:border-gray-600 dark:bg-gray-950 dark:text-gray-100">
                <option value="">{{ __('events.filter_all_formats') }}</option>
                @foreach([\App\Models\LoopEvent::FORMAT_IN_PERSON, \App\Models\LoopEvent::FORMAT_ONLINE, \App\Models\LoopEvent::FORMAT_HYBRID] as $value)
                    <option value="{{ $value }}" @selected($formatFilter === $value)>{{ __('events.format_'.$value) }}</option>
                @endforeach
            </select>

            <input type="hidden" name="when" value="{{ $when }}">
        </form>

        @if($events->isEmpty())
            <div class="rounded-2xl border border-dashed border-gray-300 bg-white p-8 text-center dark:border-gray-700 dark:bg-gray-900">
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('events.agenda_empty') }}</p>
            </div>
        @else
            <div class="space-y-3">
                @foreach($events as $event)
                    <div>
                        @include('loops.partials.event-row', [
                            'event' => $event,
                            'interactive' => false,
                            'showLoopName' => true,
                        ])
                        <p class="mt-1 pl-1">
                            <a href="{{ route('organization.loops.show', ['organization' => $organization->slug, 'loop' => $event['loop_id']]) }}"
                               class="text-[11px] font-semibold text-sky-700 hover:underline dark:text-sky-300">
                                {{ __('events.open_loop') }} →
                            </a>
                        </p>
                    </div>
                @endforeach
            </div>
        @endif
    </x-page-container>
</x-app-layout>
