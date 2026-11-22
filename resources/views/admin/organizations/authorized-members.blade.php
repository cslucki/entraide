<x-admin-layout title="Membres autorisés à se connecter">
    {{-- TASK-1881 (demande Cyril, 22/11) — outil temporaire, le temps de migrer
         les comptes : on coche ceux qui peuvent se connecter. Un membre decoche
         voit « <organisation> est ferme » a la connexion, et sa session ouverte
         se ferme a la page suivante. --}}
    @php
        $bloques = $membres->whereNotNull('login_blocked_at')->count();
    @endphp
    <div class="max-w-5xl" data-admin-authorized-members
         x-data="{ tout(valeur) { this.$root.querySelectorAll('input[name=\'authorized[]\']:not(:disabled)').forEach(c => c.checked = valeur); } }">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <div>
                <a href="{{ route('admin.organizations.edit', $organization) }}" class="text-sm text-indigo-600 hover:underline dark:text-indigo-400">&larr; {{ $organization->name }}</a>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    {{ $membres->count() }} membre(s) · <span data-admin-authorized-members-blocked>{{ $bloques }}</span> bloqué(s).
                    Le responsable de l'organisation et le superadmin restent toujours autorisés.
                </p>
            </div>
            <div class="flex items-center gap-2 text-sm">
                <button type="button" x-on:click="tout(true)" class="rounded-lg border border-gray-300 px-3 py-1.5 text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700" data-admin-authorized-all>Tout cocher</button>
                <button type="button" x-on:click="tout(false)" class="rounded-lg border border-gray-300 px-3 py-1.5 text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700" data-admin-authorized-none>Tout décocher</button>
            </div>
        </div>

        <form method="POST" action="{{ route('admin.organizations.authorized-members.update', $organization) }}">
            @csrf
            @method('PUT')

            <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-700">
                        <tr>
                            <th class="w-24 px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Autorisé</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Prénom</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Nom</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Email</th>
                            <th class="hidden px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 sm:table-cell dark:text-gray-400">Compte créé le</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        @forelse($membres as $membre)
                            @php
                                $toujours = $membre->is_admin || $membre->id === $organization->admin_id;
                            @endphp
                            <tr data-admin-authorized-member="{{ $membre->id }}" @class(['bg-red-50/60 dark:bg-red-950/20' => ! $toujours && $membre->login_blocked_at])>
                                <td class="px-4 py-3">
                                    <label class="inline-flex items-center gap-2">
                                        <input type="checkbox" name="authorized[]" value="{{ $membre->id }}"
                                               @checked($toujours || $membre->login_blocked_at === null)
                                               @disabled($toujours)
                                               class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500 disabled:opacity-50">
                                        <span class="sr-only">Autoriser {{ $membre->first_name }} {{ $membre->name }}</span>
                                        @if($toujours)
                                            <span class="text-xs text-gray-400" title="Toujours autorisé">{{ $membre->is_admin ? 'Superadmin' : 'Responsable' }}</span>
                                        @endif
                                    </label>
                                </td>
                                <td class="px-4 py-3 text-gray-900 dark:text-gray-100">{{ $membre->first_name }}</td>
                                <td class="px-4 py-3 text-gray-900 dark:text-gray-100">{{ $membre->name }}</td>
                                <td class="px-4 py-3 text-gray-600 dark:text-gray-300">{{ $membre->email }}</td>
                                <td class="hidden px-4 py-3 text-gray-600 sm:table-cell dark:text-gray-300">{{ $membre->created_at?->timezone('Europe/Paris')->format('d/m/Y') }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-4 py-8 text-center text-gray-500 dark:text-gray-400">Aucun membre dans cette organisation.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="sticky bottom-0 mt-4 flex justify-end bg-gradient-to-t from-gray-50 py-3 dark:from-gray-900">
                <button type="submit" class="rounded-lg bg-indigo-600 px-5 py-2 text-sm font-semibold text-white hover:bg-indigo-700" data-admin-authorized-save>Enregistrer</button>
            </div>
        </form>
    </div>
</x-admin-layout>
