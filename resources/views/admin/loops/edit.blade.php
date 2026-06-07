<x-admin-layout title="Modifier la boucle">
    <div class="max-w-3xl">
        <a href="{{ route('admin.loops') }}" class="text-sm text-indigo-600 dark:text-indigo-400 hover:underline">&larr; Retour aux boucles</a>
        <h1 class="text-xl font-bold text-gray-900 dark:text-gray-100 mt-2 mb-6">{{ $loop->name }}</h1>

        @if(session('success'))
        <div class="mb-6 bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 text-green-700 dark:text-green-300 px-4 py-3 rounded-lg text-sm">{{ session('success') }}</div>
        @endif
        @if(session('error'))
        <div class="mb-6 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 text-red-700 dark:text-red-300 px-4 py-3 rounded-lg text-sm">{{ session('error') }}</div>
        @endif

        <div class="space-y-6">
            {{-- Informations --}}
            <form method="POST" action="{{ route('admin.loops.update', $loop) }}" class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-6 space-y-5">
                @csrf @method('PUT')

                <h2 class="text-sm font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Informations</h2>

                <div>
                    <label for="name" class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">Nom</label>
                    <input type="text" name="name" id="name" value="{{ old('name', $loop->name) }}" required maxlength="255"
                        class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-indigo-500">
                    @error('name')<p class="text-red-500 text-xs mt-1">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label for="description" class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">Description</label>
                    <textarea name="description" id="description" rows="3" maxlength="5000"
                        class="w-full resize-none px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-indigo-500">{{ old('description', $loop->description) }}</textarea>
                    @error('description')<p class="text-red-500 text-xs mt-1">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label for="visibility" class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">Visibilité</label>
                    <select name="visibility" id="visibility" required
                        class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-indigo-500">
                        <option value="private" @selected(old('visibility', $loop->visibility) === 'private')>Privée — uniquement les membres invités</option>
                        <option value="public" @selected(old('visibility', $loop->visibility) === 'public')>Publique — tous les membres de l'organisation peuvent rejoindre</option>
                    </select>
                    @error('visibility')<p class="text-red-500 text-xs mt-1">{{ $message }}</p>@enderror
                </div>

                <div class="flex items-center gap-3">
                    <button type="submit"
                        class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium rounded-lg transition">
                        Enregistrer
                    </button>
                </div>
            </form>

            {{-- Membres --}}
            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-6 space-y-4">
                <h2 class="text-sm font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Membres ({{ $loop->members->count() }})</h2>

                {{-- Ajouter un membre --}}
                <form method="POST" action="{{ route('admin.loops.members.add', $loop) }}" class="flex gap-2 items-end">
                    @csrf
                    <div class="flex-1">
                        <label for="user_id" class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">Ajouter un membre</label>
                        <select name="user_id" id="user_id" required
                            class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-indigo-500">
                            <option value="">— Sélectionner un utilisateur —</option>
                            @foreach($users as $u)
                            <option value="{{ $u->id }}" @disabled($loop->members->pluck('user_id')->contains($u->id))>
                                {{ $u->name }} ({{ $u->email }})
                            </option>
                            @endforeach
                        </select>
                    </div>
                    <button type="submit"
                        class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium rounded-lg transition whitespace-nowrap">
                        Ajouter
                    </button>
                </form>

                {{-- Liste des membres --}}
                <div class="divide-y divide-gray-100 dark:divide-gray-700">
                    @forelse($loop->members as $member)
                    <div class="py-3 flex items-center gap-3">
                        <img src="{{ $member->user->avatar_url }}" class="w-8 h-8 rounded-full flex-shrink-0" alt="">
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-medium text-gray-900 dark:text-gray-100 truncate">{{ $member->user->name }}</p>
                            <p class="text-xs text-gray-500">
                                {{ match($member->role) { 'owner' => 'Propriétaire', 'moderator' => 'Modérateur', default => 'Membre' } }}
                                @if($member->joined_at)
                                · {{ $member->joined_at->diffForHumans() }}
                                @endif
                            </p>
                        </div>
                        <span class="text-xs px-2 py-0.5 rounded-full
                            {{ $member->status === 'active' ? 'bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-300' : 'bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-400' }}">
                            {{ $member->status === 'active' ? 'Actif' : ($member->status === 'invited' ? 'Invité' : 'Parti') }}
                        </span>
                        @if($member->role !== 'owner')
                        <form method="POST" action="{{ route('admin.loops.members.remove', [$loop, $member]) }}"
                              onsubmit="return confirm('Retirer {{ addslashes($member->user->name) }} de la boucle ?')">
                            @csrf @method('DELETE')
                            <button class="text-xs text-red-500 hover:underline">Retirer</button>
                        </form>
                        @endif
                    </div>
                    @empty
                    <p class="py-4 text-sm text-gray-400 text-center">Aucun membre.</p>
                    @endforelse
                </div>
            </div>

            {{-- Suppression --}}
            <div class="bg-white dark:bg-gray-800 rounded-xl border border-red-200 dark:border-red-900/50 p-6">
                <h2 class="text-sm font-semibold text-red-600 dark:text-red-400 uppercase tracking-wide mb-2">Zone dangereuse</h2>
                <p class="text-xs text-gray-500 dark:text-gray-400 mb-3">Supprimer définitivement cette boucle et tous ses messages. Action irréversible.</p>
                <form method="POST" action="{{ route('admin.loops.destroy', $loop) }}"
                      onsubmit="return confirm('Supprimer définitivement la boucle « {{ addslashes($loop->name) }} » ? Cette action est irréversible.')">
                    @csrf @method('DELETE')
                    <button type="submit"
                        class="px-4 py-2 bg-red-600 hover:bg-red-700 text-white text-sm font-medium rounded-lg transition">
                        Supprimer la boucle
                    </button>
                </form>
            </div>
        </div>
    </div>
</x-admin-layout>
