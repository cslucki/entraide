{{--
    TASK-1706 — organiser une rencontre depuis l'agenda.

    Cyril : « il faudrait avoir un bouton, qui permet de creer un nouvel
    evenement, ceci comme sur une boucle. Et bien entendu on selectionne la
    boucle. »

    Le selecteur ne propose QUE les Boucles ou le serveur a deja repondu oui
    (`LoopEventService::creationContextFor`). Cette page ne redevine aucun
    droit, et le service revalide tout a l'enregistrement : une option forgee
    a la main ne passerait pas.

    Le fuseau est pose par le navigateur, comme dans la Card : le serveur
    REFUSE un fuseau vide plutot que d'en deviner un.
--}}
<x-app-layout :title="__('events.agenda_create_title')">
    <x-page-container>
        <div class="mx-auto max-w-2xl">
            <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">{{ __('events.agenda_create_title') }}</h1>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('events.agenda_create_intro') }}</p>

            @if(session('error'))
                <div data-agenda-create-error class="mt-4 rounded-xl border border-red-200 bg-red-50 p-3 text-sm text-red-700 dark:border-red-900/50 dark:bg-red-900/20 dark:text-red-300">
                    {{ session('error') }}
                </div>
            @endif

            <form method="POST"
                  action="{{ route('organization.events.agenda.store', ['organization' => $organization->slug]) }}"
                  x-data="{ format: '{{ old('format', 'in_person') }}' }"
                  class="mt-6 space-y-5 rounded-2xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-800">
                @csrf

                {{-- Le fuseau du navigateur, pose a l'ouverture. Sans lui, le
                     service refuse : une chaine vide n'est pas un lieu. --}}
                <input type="hidden" name="timezone" value="{{ old('timezone') }}" data-agenda-timezone
                       x-init="if (! $el.value) { $el.value = Intl.DateTimeFormat().resolvedOptions().timeZone }">

                <div>
                    <label for="loop_id" class="block text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('events.agenda_create_loop') }}</label>
                    <select id="loop_id" name="loop_id" required data-agenda-loop-select
                            class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
                        {{-- `$boucle`, et surtout PAS `$loop` : Blade reassigne `$loop`
                             a son objet de metadonnees (`$loop->index`,
                             `$loop->first`…) a chaque tour de `@foreach`. La
                             variable d'iteration serait ecrasee, et
                             `$loop->id` leverait « Undefined property:
                             stdClass::$id ». C'est ce qui est arrive. --}}
                        @foreach($loops as $boucle)
                            <option value="{{ $boucle->id }}" @selected(old('loop_id') === $boucle->id)>{{ $boucle->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label for="title" class="block text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('events.event_title') }}</label>
                    <input id="title" name="title" type="text" required maxlength="255"
                           value="{{ old('title') }}" placeholder="{{ __('events.event_title_placeholder') }}"
                           class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
                </div>

                <div>
                    <label for="description" class="block text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('events.description') }}</label>
                    <textarea id="description" name="description" rows="3" placeholder="{{ __('events.description_placeholder') }}"
                              class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">{{ old('description') }}</textarea>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="starts_at" class="block text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('events.starts_at') }}</label>
                        <input id="starts_at" name="starts_at" type="datetime-local" required value="{{ old('starts_at') }}"
                               class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
                    </div>
                    <div>
                        <label for="ends_at" class="block text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('events.ends_at') }}</label>
                        <input id="ends_at" name="ends_at" type="datetime-local" value="{{ old('ends_at') }}"
                               class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
                    </div>
                </div>

                <div>
                    <label for="format" class="block text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('events.format') }}</label>
                    <select id="format" name="format" x-model="format"
                            class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
                        <option value="in_person">{{ __('events.format_in_person') }}</option>
                        <option value="online">{{ __('events.format_online') }}</option>
                        <option value="hybrid">{{ __('events.format_hybrid') }}</option>
                    </select>
                </div>

                {{-- Lieu et lien suivent le format, exactement comme le service
                     l'exige : sur place ou hybride demandent un lieu, en ligne
                     ou hybride demandent une URL. --}}
                <div x-show="format === 'in_person' || format === 'hybrid'" x-cloak>
                    <label for="location" class="block text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('events.location') }}</label>
                    <input id="location" name="location" type="text" maxlength="255"
                           value="{{ old('location') }}" placeholder="{{ __('events.location_placeholder') }}"
                           class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
                </div>

                <div x-show="format === 'online' || format === 'hybrid'" x-cloak>
                    <label for="meeting_url" class="block text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('events.meeting_url') }}</label>
                    <input id="meeting_url" name="meeting_url" type="url"
                           value="{{ old('meeting_url') }}" placeholder="{{ __('events.meeting_url_placeholder') }}"
                           class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
                </div>

                {{-- TASK-1706 — « l'admin de l'organisation peut creer des
                     evenements pour toute l'organisation ».

                     `loop_events.loop_id` etant NOT NULL, « pour toute
                     l'organisation » designe la VISIBILITE, jamais un evenement
                     sans Boucle. Le choix n'apparait que si le serveur l'accorde
                     sur au moins une Boucle — et il le REVALIDE a
                     l'enregistrement, Boucle par Boucle : une Boucle privee ne
                     remonte jamais, quel que soit le rang du demandeur. --}}
                @if($canPublishToOrganization)
                    <div data-agenda-visibility>
                        <label for="visibility" class="block text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('events.agenda_create_visibility') }}</label>
                        <select id="visibility" name="visibility"
                                class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
                            <option value="loop" @selected(old('visibility') !== 'organization')>{{ __('events.agenda_create_visibility_loop') }}</option>
                            <option value="organization" @selected(old('visibility') === 'organization')>{{ __('events.agenda_create_visibility_organization') }}</option>
                        </select>
                    </div>
                @endif

                <div class="flex justify-end gap-3 pt-2">
                    <a href="{{ route('organization.events.agenda', ['organization' => $organization->slug]) }}"
                       class="rounded-lg px-4 py-2 text-sm font-medium text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700">
                        {{ __('events.cancel') }}
                    </a>
                    <button type="submit" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">
                        {{ __('events.save') }}
                    </button>
                </div>
            </form>
        </div>
    </x-page-container>
</x-app-layout>
