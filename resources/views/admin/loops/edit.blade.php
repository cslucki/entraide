<x-admin-layout title="Modifier la boucle">
    <div class="max-w-3xl">
        <a href="{{ route('admin.loops') }}" class="text-sm text-indigo-600 dark:text-indigo-400 hover:underline">&larr; Retour aux boucles</a>
        <div class="mt-2 mb-6 flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-xl font-bold text-gray-900 dark:text-gray-100">{{ $boucle->name }}</h1>
            {{-- Le configurateur de composition existait sans aucun lien
                 entrant : cette action est son point d'entrée depuis l'écran
                 où l'on administre déjà la Boucle. Il agit sur CETTE Boucle,
                 jamais sur son type ni sur les autres. Visible seulement si la
                 capacité est réelle (canConfigure + Boucle non archivée) :
                 pas de bouton que le serveur refuserait. --}}
            @if($canConfigureCards)
            <a href="{{ route('admin.loops.configure', $boucle) }}"
               class="inline-flex items-center gap-1.5 rounded-lg border border-indigo-200 bg-indigo-50 px-3 py-2 text-sm font-semibold text-indigo-700 transition hover:bg-indigo-100 dark:border-indigo-800 dark:bg-indigo-900/30 dark:text-indigo-300 dark:hover:bg-indigo-900/50">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M11.42 15.17 17.25 21A2.652 2.652 0 0 0 21 17.25l-5.877-5.877M11.42 15.17l2.496-3.03c.317-.384.74-.626 1.208-.766M11.42 15.17l-4.655 5.653a2.548 2.548 0 1 1-3.586-3.586l6.837-5.63m5.108-.233c.55-.164 1.163-.188 1.743-.14a4.5 4.5 0 0 0 4.486-6.336l-3.276 3.277a3.004 3.004 0 0 1-2.25-2.25l3.276-3.276a4.5 4.5 0 0 0-6.336 4.486c.091 1.076-.071 2.264-.904 2.95l-.102.085"/></svg>
                {{ __('loops.edit_tools_action') }}
            </a>
            @endif
        </div>

        @if(session('success'))
        <div class="mb-6 bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 text-green-700 dark:text-green-300 px-4 py-3 rounded-lg text-sm">{{ session('success') }}</div>
        @endif
        @if(session('error'))
        <div class="mb-6 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 text-red-700 dark:text-red-300 px-4 py-3 rounded-lg text-sm">{{ session('error') }}</div>
        @endif

        <div class="space-y-6">
            {{-- Informations --}}
            <form method="POST" action="{{ route('admin.loops.update', $boucle) }}" class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-6 space-y-5">
                @csrf @method('PUT')

                <h2 class="text-sm font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Informations</h2>

                <div>
                    <span class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">Organisation liée</span>
                    <div class="rounded-lg border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-900/40 px-3 py-2 text-sm">
                        @if($boucle->organization)
                            <a href="{{ route('admin.organizations.edit', $boucle->organization) }}" class="font-medium text-indigo-600 dark:text-indigo-400 hover:underline">
                                {{ $boucle->organization->name }}
                            </a>
                        @else
                            <span class="font-medium text-gray-500 dark:text-gray-400">Organisation inconnue</span>
                        @endif
                        <p class="mt-1 truncate font-mono text-[11px] text-gray-500 dark:text-gray-400">{{ $boucle->organization_id }}</p>
                    </div>
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Lecture seule : la réaffectation d'organisation est désactivée pour préserver l'isolation tenant.</p>
                </div>

                <div>
                    <label for="name" class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">Nom</label>
                    <input type="text" name="name" id="name" value="{{ old('name', $boucle->name) }}" required maxlength="255"
                        class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-indigo-500">
                    @error('name')<p class="text-red-500 text-xs mt-1">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label for="description" class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">Description</label>
                    <textarea name="description" id="description" rows="3" maxlength="5000"
                        class="w-full resize-none px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-indigo-500">{{ old('description', $boucle->description) }}</textarea>
                    @error('description')<p class="text-red-500 text-xs mt-1">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label for="visibility" class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">Visibilité</label>
                    <select name="visibility" id="visibility" required
                        class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-indigo-500">
                        <option value="private" @selected(old('visibility', $boucle->visibility) === 'private')>Privée — uniquement les membres invités</option>
                        <option value="public" @selected(old('visibility', $boucle->visibility) === 'public')>Publique — tous les membres de l'organisation peuvent rejoindre</option>
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
                <h2 class="text-sm font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Membres ({{ $boucle->members->count() }})</h2>

                {{-- Ajouter un membre --}}
                <form method="POST" action="{{ route('admin.loops.members.add', $boucle) }}" class="flex gap-2 items-end">
                    @csrf
                    <div class="flex-1">
                        <label for="user_id" class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('admin.owner_select_label') }}</label>
                        <select name="user_id" id="user_id" required
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
                                <option value="{{ $u->id }}" @disabled($boucle->members->pluck('user_id')->contains($u->id))>
                                    {{ $u->full_name }} — {{ $u->email }}@if($hasMultipleOrgs) · {{ $orgName }}@endif
                                </option>
                                @endforeach
                                @if($hasMultipleOrgs)
                                </optgroup>
                                @endif
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="add_role" class="block text-xs font-medium text-gray-600 dark:text-gray-300">{{ __('loops.governance_add_as') }}</label>
                        <select name="role" id="add_role"
                                class="mt-1 min-h-[44px] rounded-lg border border-gray-300 bg-white px-2 text-sm text-gray-900 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                            <option value="member">{{ __('loops.members_role_member') }}</option>
                            <option value="facilitator">{{ __('loops.members_role_facilitator') }}</option>
                            <option value="owner">{{ __('loops.members_role_owner') }}</option>
                        </select>
                    </div>
                    <button type="submit"
                        class="min-h-[44px] px-4 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium rounded-lg transition whitespace-nowrap">
                        Ajouter
                    </button>
                </form>

                {{-- Gouvernance : propriétaires, Animateurs, membres --}}
                <x-loops.governance-roster
                    :members="$boucle->members->where('status', 'active')"
                    :role-route="fn($m) => route('admin.loops.members.role', [$boucle, $m])"
                    :remove-route="fn($m) => route('admin.loops.members.remove', [$boucle, $m])"
                    :can-manage-owners="true"
                    :can-manage-facilitators="true"
                    :can-remove="true"
                    :creator-id="$boucle->created_by"
                    :current-user-id="auth()->id()" />
            </div>

            {{-- Status / Archive / Restore --}}
            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-6 space-y-4">
                <h2 class="text-sm font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Statut</h2>

                <div class="flex items-center gap-3">
                    <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium
                        {{ $boucle->isActive() ? 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200' : 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300' }}">
                        {{ $boucle->isActive() ? 'Active' : 'Archivée' }}
                    </span>

                    @if($boucle->isActive())
                    <form method="POST" action="{{ route('admin.loops.archive', $boucle) }}"
                          onsubmit="return confirm('Archiver la boucle « {{ addslashes($boucle->name) }} » ?')">
                        @csrf
                        <button type="submit"
                            class="px-4 py-2 bg-amber-500 hover:bg-amber-600 text-white text-sm font-medium rounded-lg transition">
                            Archiver
                        </button>
                    </form>
                    @else
                    <form method="POST" action="{{ route('admin.loops.restore', $boucle) }}"
                          onsubmit="return confirm('Réactiver la boucle « {{ addslashes($boucle->name) }} » ?')">
                        @csrf
                        <button type="submit"
                            class="px-4 py-2 bg-green-600 hover:bg-green-700 text-white text-sm font-medium rounded-lg transition">
                            Réactiver
                        </button>
                    </form>
                    @endif
                </div>
            </div>

            {{-- Suppression --}}
            <div class="bg-white dark:bg-gray-800 rounded-xl border border-red-200 dark:border-red-900/50 p-6">
                <h2 class="text-sm font-semibold text-red-600 dark:text-red-400 uppercase tracking-wide mb-2">Zone dangereuse</h2>
                @if($boucle->hasContent())
                <p class="text-xs text-amber-600 dark:text-amber-400 mb-3">Cette boucle contient des messages. La suppression est désactivée. Archivez-la plutôt.</p>
                @else
                <p class="text-xs text-gray-500 dark:text-gray-400 mb-3">Supprimer définitivement cette boucle. Action irréversible.</p>
                <form method="POST" action="{{ route('admin.loops.destroy', $boucle) }}"
                      onsubmit="return confirm('Supprimer définitivement la boucle « {{ addslashes($boucle->name) }} » ? Cette action est irréversible.')">
                    @csrf @method('DELETE')
                    <button type="submit"
                        class="px-4 py-2 bg-red-600 hover:bg-red-700 text-white text-sm font-medium rounded-lg transition">
                        Supprimer la boucle
                    </button>
                </form>
                @endif
            </div>
        </div>
    </div>
</x-admin-layout>
