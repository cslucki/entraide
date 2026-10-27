<x-admin-layout title="Nouvelle boucle">
    <div class="max-w-2xl">
        <a href="{{ route('admin.loops') }}" class="text-sm text-indigo-600 dark:text-indigo-400 hover:underline">&larr; Retour aux boucles</a>
        <h1 class="text-xl font-bold text-gray-900 dark:text-gray-100 mt-2 mb-6">Nouvelle boucle</h1>

        <form method="POST" action="{{ route('admin.loops.store') }}" class="space-y-6">
            @csrf

            {{-- TASK-1679 — branche SuperAdmin : les deux champs Organization et
                 Responsable sont LIES. La liste des responsables ne propose que
                 les utilisateurs de l'Organization choisie, jamais ceux d'une
                 autre — et « aucun responsable » est un choix legitime. Le JS
                 n'est qu'un confort : la garde same-org et la regle nullable
                 restent serveur (AdminLoopController::store). --}}
            @isset($organizations)
                @php
                    $ownersByOrg = $users->groupBy('organization_id')->map(
                        fn ($orgUsers) => $orgUsers->map(fn ($u) => [
                            'id' => $u->id,
                            'label' => $u->full_name.' — '.$u->email,
                        ])->values()
                    );
                @endphp
            @endisset
            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-6 space-y-5"
                @isset($organizations)
                x-data="{ orgId: @js(old('organization_id', '')), ownerId: @js(old('owner_id', '')), ownersByOrg: @js($ownersByOrg) }"
                @endisset>
                @isset($organizations)
                <div>
                    <label for="organization_id" class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('admin.org_select_label') }}</label>
                    {{-- Changer d'Organization remet le responsable a zero : sans
                         cela, un owner_id de l'org precedente resterait arme dans
                         le modele et partirait au POST (le serveur le refuserait
                         en 403, mais l'ecran ne doit jamais le proposer). --}}
                    <select name="organization_id" id="organization_id" required x-model="orgId" @change="ownerId = ''"
                        class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-indigo-500">
                        <option value="">{{ __('admin.org_select_placeholder') }}</option>
                        @foreach($organizations as $org)
                        <option value="{{ $org->id }}" @selected(old('organization_id') === $org->id)>
                            {{ $org->name }}
                        </option>
                        @endforeach
                    </select>
                    @error('organization_id')<p class="text-red-500 text-xs mt-1">{{ $message }}</p>@enderror
                </div>
                @endisset

                <div>
                    <label for="name" class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">Nom</label>
                    <input type="text" name="name" id="name" value="{{ old('name') }}" required maxlength="255"
                        class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-indigo-500">
                    @error('name')<p class="text-red-500 text-xs mt-1">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label for="description" class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">Description</label>
                    <textarea name="description" id="description" rows="3" maxlength="5000"
                        class="w-full resize-none px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-indigo-500">{{ old('description') }}</textarea>
                    @error('description')<p class="text-red-500 text-xs mt-1">{{ $message }}</p>@enderror
                </div>

                <x-loops.type-picker :types="$loopTypes" :selected="old('type')" />

                <div>
                    <label for="visibility" class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">Visibilité</label>
                    <select name="visibility" id="visibility" required
                        class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-indigo-500">
                        <option value="private" @selected(old('visibility', 'private') === 'private')>Privée — uniquement les membres invités</option>
                        <option value="public" @selected(old('visibility') === 'public')>Publique — tous les membres de l'organisation peuvent rejoindre</option>
                    </select>
                    @error('visibility')<p class="text-red-500 text-xs mt-1">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label for="owner_id" class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('admin.loop_owner_label') }}</label>
                    @isset($organizations)
                    <select name="owner_id" id="owner_id" x-model="ownerId" :disabled="! orgId"
                        class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-indigo-500 disabled:opacity-60">
                        <option value="">{{ __('admin.loop_owner_none') }}</option>
                        <template x-for="candidate in (ownersByOrg[orgId] ?? [])" :key="candidate.id">
                            <option :value="candidate.id" x-text="candidate.label"></option>
                        </template>
                    </select>
                    @else
                    <select name="owner_id" id="owner_id" required
                        class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-indigo-500">
                        <option value="">{{ __('admin.loop_owner_placeholder') }}</option>
                        @foreach($users as $u)
                        <option value="{{ $u->id }}" @selected(old('owner_id') === $u->id)>
                            {{ $u->full_name }} — {{ $u->email }}
                        </option>
                        @endforeach
                    </select>
                    @endisset
                    @error('owner_id')<p class="text-red-500 text-xs mt-1">{{ $message }}</p>@enderror
                </div>
            </div>

            <div class="flex items-center gap-3">
                <button type="submit"
                    class="px-6 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold rounded-lg transition">
                    Créer la boucle
                </button>
                <a href="{{ route('admin.loops') }}"
                   class="px-4 py-2.5 text-sm font-medium text-gray-700 dark:text-gray-300 bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 rounded-lg transition">
                    Annuler
                </a>
            </div>
        </form>
    </div>
</x-admin-layout>
