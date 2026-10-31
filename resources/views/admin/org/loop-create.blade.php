{{--
    TASK-1725 (demande Cyril, 29/10) — creer une Boucle depuis l'administration
    de l'Organization.

    ## Ce qui le distingue de l'ecran du SuperAdmin

    AUCUN selecteur d'Organization : celle de l'URL est la seule possible, et la
    proposer au choix inviterait a fabriquer une Boucle chez le voisin. Le
    controleur ne lit d'ailleurs aucun `organization_id` du formulaire — un champ
    cache n'y changerait donc rien.

    Le responsable est OBLIGATOIRE ici, alors qu'il est facultatif pour le
    SuperAdmin (arbitrage Cyril ; voir le docblock de `storeLoop()`).

    ## Ce qui n'est PAS sur cet ecran

    Ni couverture, ni cartes, ni membres. La Boucle se cree, puis la page de
    modification — ou l'on atterrit aussitot — porte tout le reste. Un formulaire
    de creation qui demande tout ne se remplit jamais.
--}}
<x-app-layout>
    <div class="mx-auto max-w-2xl px-4 py-8">
        <div class="mb-6 flex items-center gap-3">
            <a href="{{ route('organization.admin.loops', ['organization' => $organization->slug]) }}"
               class="text-gray-400 transition hover:text-gray-600 dark:hover:text-gray-300" aria-label="{{ __('navigation.org_admin_loops') }}">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
            </a>
            <h1 class="text-xl font-bold text-gray-900 dark:text-white">{{ __('admin.org_admin_loop_create_title') }}</h1>
        </div>

        @if($errors->any())
            <div class="mb-5 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700 dark:border-red-700 dark:bg-red-900/20 dark:text-red-400">
                <ul class="ml-4 list-disc space-y-1">
                    @foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('organization.admin.loops.store', ['organization' => $organization->slug]) }}"
              class="space-y-4 rounded-2xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-800"
              data-marqueur="formulaire-creation-boucle">
            @csrf

            <label class="block">
                <span class="text-xs font-medium text-gray-600 dark:text-gray-300">{{ __('navigation.org_admin_table_name') }}</span>
                <input type="text" name="name" value="{{ old('name') }}" required maxlength="255" autofocus
                       class="mt-1 w-full rounded-xl border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
                <x-input-error :messages="$errors->get('name')" class="mt-1" />
            </label>

            <label class="block">
                <span class="text-xs font-medium text-gray-600 dark:text-gray-300">Description</span>
                <textarea name="description" rows="3" maxlength="5000"
                          class="mt-1 w-full rounded-xl border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">{{ old('description') }}</textarea>
                <x-input-error :messages="$errors->get('description')" class="mt-1" />
            </label>

            {{-- Le responsable. La liste vient du controleur et porte EXACTEMENT
                 la portee de la regle de validation — membres assignables de
                 cette Organization. Une liste plus large offrirait des choix que
                 l'envoi refuserait. --}}
            <label class="block">
                <span class="text-xs font-medium text-gray-600 dark:text-gray-300">{{ __('admin.org_admin_loop_owner') }}</span>
                <select name="owner_id" required
                        class="mt-1 w-full rounded-xl border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
                    <option value="">{{ __('admin.org_admin_loop_owner_placeholder') }}</option>
                    @foreach($responsables as $membre)
                        <option value="{{ $membre->id }}" @selected(old('owner_id') === $membre->id)>{{ $membre->name }} — {{ $membre->email }}</option>
                    @endforeach
                </select>
                <p class="mt-1 text-xs text-gray-400">{{ __('admin.org_admin_loop_owner_hint') }}</p>
                <x-input-error :messages="$errors->get('owner_id')" class="mt-1" />
            </label>

            <fieldset>
                <legend class="text-xs font-medium text-gray-600 dark:text-gray-300">{{ __('admin.org_admin_loop_visibility') }}</legend>
                <div class="mt-2 space-y-2">
                    <label class="flex cursor-pointer items-start gap-2">
                        <input type="radio" name="visibility" value="private" @checked(old('visibility', 'private') === 'private')
                               class="mt-0.5 text-indigo-600 focus:ring-indigo-500">
                        <span class="min-w-0">
                            <span class="block text-sm font-medium text-gray-800 dark:text-gray-100">{{ __('admin.org_admin_loop_visibility_private') }}</span>
                            <span class="block text-xs text-gray-500 dark:text-gray-400">{{ __('admin.org_admin_loop_visibility_private_hint') }}</span>
                        </span>
                    </label>
                    <label class="flex cursor-pointer items-start gap-2">
                        <input type="radio" name="visibility" value="public" @checked(old('visibility') === 'public')
                               class="mt-0.5 text-indigo-600 focus:ring-indigo-500">
                        <span class="min-w-0">
                            <span class="block text-sm font-medium text-gray-800 dark:text-gray-100">{{ __('admin.org_admin_loop_visibility_public') }}</span>
                            <span class="block text-xs text-gray-500 dark:text-gray-400">{{ __('admin.org_admin_loop_visibility_public_hint') }}</span>
                        </span>
                    </label>
                </div>
                <x-input-error :messages="$errors->get('visibility')" class="mt-1" />
            </fieldset>

            <fieldset>
                <legend class="text-xs font-medium text-gray-600 dark:text-gray-300">{{ __('loops.type_label') }}</legend>
                <p class="mb-2 mt-1 text-xs text-gray-400">{{ __('admin.org_admin_loop_type_hint') }}</p>
                <div class="grid gap-2 sm:grid-cols-2">
                    @php($typeRegistry = app(\App\Support\Loops\LoopTypeRegistry::class))
                    @foreach($types as $key => $definition)
                        <label class="flex cursor-pointer items-start gap-2 rounded-xl border border-gray-200 p-3 dark:border-gray-700">
                            <input type="radio" name="type" value="{{ $key }}" @checked(old('type') === $key)
                                   class="mt-0.5 text-indigo-600 focus:ring-indigo-500">
                            {{-- Le registre, jamais la definition : un type cree
                                 porte un mot ecrit et aucune cle de traduction.
                                 Meme idiome que l'ecran de modification. --}}
                            <span class="min-w-0">
                                <span class="block text-sm font-medium text-gray-800 dark:text-gray-100">{{ $typeRegistry->label($key, $organization) }}</span>
                                <span class="block text-xs text-gray-500 dark:text-gray-400">{{ $typeRegistry->description($key, $organization) }}</span>
                            </span>
                        </label>
                    @endforeach
                </div>
                <x-input-error :messages="$errors->get('type')" class="mt-1" />
            </fieldset>

            <div class="flex items-center gap-3 pt-1">
                <button type="submit"
                        class="inline-flex items-center justify-center rounded-xl bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-indigo-700">
                    {{ __('admin.org_admin_loop_create_submit') }}
                </button>
                <a href="{{ route('organization.admin.loops', ['organization' => $organization->slug]) }}"
                   class="rounded-xl border border-gray-300 px-4 py-2.5 text-sm text-gray-700 transition hover:bg-gray-50 dark:border-gray-600 dark:text-gray-300 dark:hover:bg-gray-700">
                    {{ __('admin.cancel') }}
                </a>
            </div>
        </form>
    </div>
</x-app-layout>
