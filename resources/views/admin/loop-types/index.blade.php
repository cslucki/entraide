{{--
    What each Loop type is made of — super-admin only.

    One card per type, so a type is read as a whole: its composition, whether it
    may be chosen, and how many Loops already carry it. Nothing saved here is
    retroactive, and the screen says so rather than leaving it to be discovered.
--}}
<x-admin-layout :title="__('loops.types_admin_title')">
    <div class="mx-auto max-w-4xl px-4 py-8">

        <header class="mb-6">
            <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">{{ __('loops.types_admin_title') }}</h1>
            <p class="mt-1 max-w-3xl text-sm text-gray-500 dark:text-gray-400">{{ __('loops.types_admin_intro') }}</p>
        </header>

        @if(session('success'))
            <div class="mb-4 rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700 dark:border-green-800 dark:bg-green-900/20 dark:text-green-300">
                {{ session('success') }}
            </div>
        @endif

        @error('cards')
            <div class="mb-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-800 dark:bg-red-900/20 dark:text-red-300">
                {{ $message }}
            </div>
        @enderror

        {{-- Le selecteur de portee : PLATEFORME -> ORGANIZATION.
             Un GET, parce que choisir une portee c'est regarder ailleurs, pas
             enregistrer quoi que ce soit. --}}
        <form method="GET" action="{{ route('admin.loop-types') }}"
              class="mb-5 rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
            <label for="scope" class="block text-xs font-semibold uppercase tracking-wide text-gray-400">
                {{ __('loops.types_admin_scope_legend') }}
            </label>
            <div class="mt-2 flex flex-wrap items-center gap-2">
                <select id="scope" name="scope" onchange="this.form.submit()"
                        class="min-h-[44px] flex-1 rounded-xl border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                    <option value="platform" @selected(! $scope)>{{ __('loops.types_admin_scope_platform') }}</option>
                    @foreach($organizations as $organization)
                        <option value="{{ $organization->id }}" @selected($scope?->is($organization))>{{ $organization->name }}</option>
                    @endforeach
                </select>
                <noscript>
                    <button type="submit" class="min-h-[44px] rounded-xl border border-gray-300 px-4 text-sm dark:border-gray-600 dark:text-gray-200">
                        {{ __('loops.types_admin_scope_go') }}
                    </button>
                </noscript>
            </div>
            <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                {{ $scope ? __('loops.types_admin_scope_org_help', ['organization' => $scope->name]) : __('loops.types_admin_scope_platform_help') }}
            </p>
        </form>

        <div class="mb-5 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-xs text-amber-800 dark:border-amber-700/60 dark:bg-amber-900/20 dark:text-amber-200">
            {{ __('loops.types_admin_not_retroactive') }}
        </div>

        {{-- Creer un type dans la portee affichee.
             La cle n'est pas saisie : elle est forgee depuis le mot et ne
             changera plus. Renommer le type ensuite ne la touche pas. --}}
        <details class="mb-5 rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800" @if($errors->has('label')) open @endif>
            <summary class="cursor-pointer text-sm font-semibold text-gray-900 dark:text-gray-100">
                {{ $scope ? __('loops.types_admin_create_for_org', ['organization' => $scope->name]) : __('loops.types_admin_create_for_platform') }}
            </summary>

            <form method="POST" action="{{ route('admin.loop-types.store') }}" class="mt-4 space-y-3">
                @csrf
                <input type="hidden" name="scope" value="{{ $scope?->id ?? 'platform' }}">

                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="block">
                        <span class="mb-1 block text-xs text-gray-500 dark:text-gray-400">{{ __('loops.types_admin_label_field') }}</span>
                        <input type="text" name="label" maxlength="80" required value="{{ old('label') }}"
                               class="min-h-[44px] w-full rounded-xl border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                        @error('label')<span class="mt-1 block text-xs text-red-600 dark:text-red-400">{{ $message }}</span>@enderror
                    </label>

                    <label class="block">
                        <span class="mb-1 block text-xs text-gray-500 dark:text-gray-400">{{ __('loops.types_admin_based_on') }}</span>
                        <select name="based_on" class="min-h-[44px] w-full rounded-xl border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                            <option value="">{{ __('loops.types_admin_based_on_none') }}</option>
                            @foreach($types as $key => $type)
                                <option value="{{ $key }}" @selected(old('based_on') === $key)>{{ $type['label'] }}</option>
                            @endforeach
                        </select>
                    </label>
                </div>

                <label class="block">
                    <span class="mb-1 block text-xs text-gray-500 dark:text-gray-400">{{ __('loops.types_admin_description_field') }}</span>
                    <input type="text" name="description" maxlength="2000" value="{{ old('description') }}"
                           class="min-h-[44px] w-full rounded-xl border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                </label>

                <p class="text-xs text-gray-400">{{ __('loops.types_admin_create_help') }}</p>

                <button type="submit" class="inline-flex min-h-[44px] items-center rounded-xl bg-indigo-600 px-4 text-sm font-semibold text-white transition hover:bg-indigo-700">
                    {{ __('loops.types_admin_create_submit') }}
                </button>
            </form>
        </details>

        @error('type')
            <div class="mb-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-800 dark:bg-red-900/20 dark:text-red-300">
                {{ $message }}
            </div>
        @enderror

        <div class="space-y-4">
            @foreach($types as $key => $type)
                {{-- Replie par defaut : l'ecran sert d'abord a parcourir les
                     types, et chaque carte depliee est un formulaire entier.
                     `<details>` natif, comme le formulaire de creation :
                     aucune dependance, aucun script. --}}
                <details class="group rounded-2xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800">
                    <summary class="flex cursor-pointer list-none items-center justify-between gap-3 p-5 [&::-webkit-details-marker]:hidden">
                        <div class="min-w-0">
                            <h2 class="flex flex-wrap items-center gap-2 text-base font-semibold text-gray-900 dark:text-gray-100">
                                {{ $type['label'] }}
                                @if(! $type['available'])
                                    <span class="rounded-full bg-gray-200 px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wide text-gray-600 dark:bg-gray-700 dark:text-gray-300">
                                        {{ __('loops.types_admin_unavailable') }}
                                    </span>
                                @endif
                                @if($type['customised'])
                                    <span class="rounded-full bg-indigo-100 px-2 py-0.5 text-[11px] font-semibold text-indigo-700 dark:bg-indigo-900/50 dark:text-indigo-300">
                                        {{ $scope ? __('loops.types_admin_customised_org') : __('loops.types_admin_customised') }}
                                    </span>
                                @else
                                    <span class="rounded-full bg-gray-100 px-2 py-0.5 text-[11px] font-medium text-gray-500 dark:bg-gray-700/60 dark:text-gray-400">
                                        {{ $scope ? __('loops.types_admin_inherited_org') : __('loops.types_admin_inherited_config') }}
                                    </span>
                                @endif
                            </h2>
                            <p class="mt-1 text-xs text-gray-400">
                                <code>{{ $type['key'] }}</code>
                                ·
                                {{-- Le meme contexte que l'ecran : portee globale
                                     -> type seul ; portee Organization ->
                                     Organization + type. Le chiffre est celui de
                                     countLoopsOfType(), alias replies — la liste
                                     ouverte doit rendre exactement ce compte. --}}
                                <a href="{{ route('admin.loops', array_filter(['organization_id' => $scope?->id, 'type' => $key])) }}"
                                   class="underline decoration-dotted underline-offset-2 transition hover:text-indigo-600 dark:hover:text-indigo-400">
                                    {{ trans_choice('loops.types_admin_loops_count', $type['loops'], ['count' => $type['loops']]) }}
                                </a>
                            </p>
                        </div>
                        <svg class="h-5 w-5 shrink-0 text-gray-400 transition-transform group-open:rotate-180" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 11.168l3.71-3.938a.75.75 0 1 1 1.08 1.04l-4.25 4.5a.75.75 0 0 1-1.08 0l-4.25-4.5a.75.75 0 0 1 .02-1.06Z" clip-rule="evenodd" />
                        </svg>
                    </summary>

                    <div class="border-t border-gray-100 px-5 pb-5 pt-4 dark:border-gray-700">
                    @if($type['description'])
                        <p class="mb-4 text-sm text-gray-500 dark:text-gray-400">{{ $type['description'] }}</p>
                    @endif
                    <form method="POST" action="{{ route('admin.loop-types.update', $key) }}">
                    @csrf @method('PUT')
                    <input type="hidden" name="scope" value="{{ $scope?->id ?? 'platform' }}">

                    {{-- Le mot, jamais la cle.
                         `key = training` reste `training` : c'est elle que
                         portent les Boucles, les socles et les permissions. Le
                         champ vide veut dire « comme le niveau au-dessus », et
                         le texte d'aide dit lequel — sinon le SuperAdmin
                         recopierait l'heritage dans la surcharge, et ce type
                         cesserait de suivre la Plateforme sans que personne ne
                         l'ait voulu. --}}
                    <fieldset class="mb-4">
                        <legend class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-400">{{ __('loops.types_admin_naming_legend') }}</legend>
                        <div class="grid gap-3 sm:grid-cols-2">
                            <label class="block">
                                <span class="mb-1 block text-xs text-gray-500 dark:text-gray-400">{{ __('loops.types_admin_label_field') }}</span>
                                <input type="text" name="label" maxlength="80" value="{{ old('label', $type['own_label']) }}"
                                       placeholder="{{ $type['inherited_label'] }}"
                                       class="min-h-[44px] w-full rounded-xl border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                            </label>
                            <label class="block">
                                <span class="mb-1 block text-xs text-gray-500 dark:text-gray-400">{{ __('loops.types_admin_description_field') }}</span>
                                <input type="text" name="description" maxlength="2000" value="{{ old('description', $type['own_description']) }}"
                                       placeholder="{{ $type['description'] }}"
                                       class="min-h-[44px] w-full rounded-xl border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                            </label>
                        </div>
                        <p class="mt-2 text-xs text-gray-400">
                            {{ __('loops.types_admin_naming_help', ['key' => $type['key'], 'inherited' => $type['inherited_label']]) }}
                        </p>
                    </fieldset>

                    <fieldset class="mb-4">
                        <legend class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-400">{{ __('loops.types_admin_cards_legend') }}</legend>
                        <div class="grid gap-2 sm:grid-cols-2">
                            @foreach($catalogue as $cardKey => $card)
                                <label class="flex min-h-[44px] cursor-pointer items-center gap-2.5 rounded-xl border border-gray-200 px-3 py-2 transition hover:bg-gray-50 dark:border-gray-600 dark:hover:bg-gray-700/50">
                                    <input type="checkbox" name="cards[]" value="{{ $cardKey }}"
                                           @checked(in_array($cardKey, $type['cards'], true))
                                           class="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500 dark:border-gray-500 dark:bg-gray-900">
                                    <span class="min-w-0">
                                        <span class="block text-sm font-medium text-gray-800 dark:text-gray-100">{{ __($card['label_key']) }}</span>
                                        <span class="block truncate text-xs text-gray-500 dark:text-gray-400">{{ __($card['description_key']) }}</span>
                                    </span>
                                </label>
                            @endforeach
                        </div>
                    </fieldset>

                    <div class="flex flex-wrap items-center justify-between gap-3 border-t border-gray-100 pt-4 dark:border-gray-700">
                        <label class="flex min-h-[44px] cursor-pointer items-center gap-2.5">
                            {{-- An unchecked checkbox posts nothing, so the hidden
                                 field is what makes "unavailable" expressible. --}}
                            <input type="hidden" name="available" value="0">
                            <input type="checkbox" name="available" value="1" @checked($type['available'])
                                   class="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500 dark:border-gray-500 dark:bg-gray-900">
                            <span class="text-sm text-gray-700 dark:text-gray-200">{{ __('loops.types_admin_available_label') }}</span>
                        </label>

                        <div class="flex flex-wrap items-center gap-2">
                            @if($type['customised'])
                                <button type="submit" form="reset-{{ $key }}"
                                        class="inline-flex min-h-[44px] items-center rounded-xl border border-gray-300 px-4 text-sm text-gray-600 transition hover:bg-gray-50 dark:border-gray-600 dark:text-gray-300 dark:hover:bg-gray-700">
                                    {{ $scope ? __('loops.types_admin_reset_to_platform') : __('loops.types_admin_reset') }}
                                </button>
                            @endif
                            <button type="submit"
                                    class="inline-flex min-h-[44px] items-center rounded-xl bg-indigo-600 px-4 text-sm font-semibold text-white transition hover:bg-indigo-700">
                                {{ __('loops.types_admin_save') }}
                            </button>
                        </div>
                    </div>
                    </form>

                    {{-- Seuls les types crees se suppriment : ceux du fichier
                         n'existent pas en base. Et le refus, quand une Boucle le
                         porte, vient du service — pas d'un `confirm()`.
                         Frere du formulaire d'edition, jamais imbrique. --}}
                    @if(isset($created[$key]))
                        <form method="POST" action="{{ route('admin.loop-types.destroy', $created[$key]) }}"
                              class="mt-3 flex justify-end">
                            @csrf @method('DELETE')
                            <input type="hidden" name="scope" value="{{ $scope?->id ?? 'platform' }}">
                            <button type="submit" class="text-xs text-gray-400 underline underline-offset-2 transition hover:text-red-600 dark:hover:text-red-400">
                                {{ __('loops.types_admin_delete') }}
                            </button>
                        </form>
                    @endif
                    </div>
                </details>

                {{-- Outside the card: nested forms are invalid HTML, and the
                     submit button reaches this one by its `form` attribute. --}}
                @if($type['customised'])
                    <form id="reset-{{ $key }}" method="POST" action="{{ route('admin.loop-types.reset', $key) }}" class="hidden">
                        @csrf @method('DELETE')
                        <input type="hidden" name="scope" value="{{ $scope?->id ?? 'platform' }}">
                    </form>
                @endif
            @endforeach
        </div>
    </div>
</x-admin-layout>
