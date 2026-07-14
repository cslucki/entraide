<x-admin-layout title="Nouvelle boucle">
    <div class="max-w-2xl">
        <a href="{{ route('admin.loops') }}" class="text-sm text-indigo-600 dark:text-indigo-400 hover:underline">&larr; Retour aux boucles</a>
        <h1 class="text-xl font-bold text-gray-900 dark:text-gray-100 mt-2 mb-6">Nouvelle boucle</h1>

        <form method="POST" action="{{ route('admin.loops.store') }}" class="space-y-6">
            @csrf

            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-6 space-y-5">
                @isset($organizations)
                <div>
                    <label for="organization_id" class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('admin.org_select_label') }}</label>
                    <select name="organization_id" id="organization_id" required
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
                    <label for="owner_id" class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('admin.owner_select_label') }}</label>
                    <select name="owner_id" id="owner_id" required
                        class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-indigo-500">
                        <option value="">{{ __('admin.owner_select_placeholder') }}</option>
                        @php
                            $grouped = $users->groupBy(fn ($u) => $u->organization->name ?? __('admin.organizations'));
                            $hasMultipleOrgs = $grouped->count() > 1;
                        @endphp
                        @foreach($grouped as $orgName => $orgUsers)
                            @if($hasMultipleOrgs)
                            <optgroup label="{{ $orgName }}">
                            @endif
                            @foreach($orgUsers as $u)
                            <option value="{{ $u->id }}" @selected(old('owner_id') === $u->id)>
                                {{ $u->full_name }} — {{ $u->email }}@if($hasMultipleOrgs) · {{ $orgName }}@endif
                            </option>
                            @endforeach
                            @if($hasMultipleOrgs)
                            </optgroup>
                            @endif
                        @endforeach
                    </select>
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
