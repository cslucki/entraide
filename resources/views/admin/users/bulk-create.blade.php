<x-admin-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            Création de comptes en masse
        </h2>
    </x-slot>

    <div class="py-6">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

            <p class="text-sm text-gray-500 dark:text-gray-400">
                Créer des accès pour plusieurs personnes, envoyer leurs invitations et suivre leur activation.
            </p>

            @if (session('success'))
                <div class="rounded-lg bg-green-50 dark:bg-green-900/30 border border-green-200 dark:border-green-800 p-4 text-sm text-green-800 dark:text-green-300">
                    {{ session('success') }}
                </div>
            @endif

            @if (session('error'))
                <div class="rounded-lg bg-red-50 dark:bg-red-900/30 border border-red-200 dark:border-red-800 p-4 text-sm text-red-800 dark:text-red-300">
                    {{ session('error') }}
                </div>
            @endif

            @if (session('bulkCreateResult'))
                @php $result = session('bulkCreateResult'); @endphp
                <div class="rounded-lg bg-white dark:bg-gray-800 shadow sm:rounded-lg p-6 space-y-2 text-sm">
                    @if ($result['created'] > 0)
                        <p class="text-green-700 dark:text-green-400">{{ $result['created'] }} invitation(s) envoyée(s).</p>
                    @endif
                    @if ($result['resent'] > 0)
                        <p class="text-indigo-700 dark:text-indigo-400">{{ $result['resent'] }} invitation(s) relancée(s) (en attente déjà existante).</p>
                    @endif
                    @foreach ($result['alreadyMember'] as $email)
                        <p class="text-amber-700 dark:text-amber-400">{{ $email }} — cette personne possède déjà un compte dans cette organisation.</p>
                    @endforeach
                    @foreach ($result['usedElsewhere'] as $email)
                        <p class="text-red-700 dark:text-red-400">{{ $email }} — cette adresse possède déjà un compte BouclePro associé à une autre organisation. Le multi-Organization n'est pas encore pris en charge.</p>
                    @endforeach
                </div>
            @endif

            <!-- Créer des accès -->
            <div class="bg-white dark:bg-gray-800 shadow sm:rounded-lg p-6"
                 x-data="{
                    people: [{ first_name: '', last_name: '', email: '' }],
                    addRow() { this.people.push({ first_name: '', last_name: '', email: '' }) },
                    removeRow(i) { if (this.people.length > 1) this.people.splice(i, 1) },
                 }">
                <h3 class="text-sm font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-4">
                    Créer des accès
                </h3>

                <form method="POST" action="{{ route('admin.users.bulk-create.invitations.store') }}" class="space-y-6">
                    @csrf

                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Organisation</label>
                        <select name="organization_id" required
                                class="w-full max-w-md rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                            <option value="">— Sélectionner —</option>
                            @foreach ($organizations as $organization)
                                <option value="{{ $organization->id }}" @selected(old('organization_id', $selectedOrganizationId) === $organization->id)>
                                    {{ $organization->name }}
                                </option>
                            @endforeach
                        </select>
                        @error('organization_id')
                            <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
                        @enderror
                        <p class="mt-1 text-xs text-gray-400 dark:text-gray-500">
                            Les organisations en sandbox du Scenario Manager ne sont pas proposées : aucun vrai compte n'y est créé.
                        </p>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Langue de l'email</label>
                        <select name="locale"
                                class="w-full max-w-xs rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                            <option value="fr" @selected(old('locale', \App\Models\OrganizationInvitation::DEFAULT_LOCALE) === 'fr')>Français</option>
                            <option value="en" @selected(old('locale') === 'en')>English</option>
                        </select>
                        @error('locale')
                            <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
                        @enderror
                        <p class="mt-1 text-xs text-gray-400 dark:text-gray-500">
                            S'applique à toutes les personnes de cet envoi.
                        </p>
                    </div>

                    <div class="space-y-3">
                        <template x-for="(person, index) in people" :key="index">
                            <div class="grid grid-cols-1 sm:grid-cols-[1fr_1fr_1.5fr_auto] gap-3 items-start">
                                <div>
                                    <input type="text" :name="`people[${index}][first_name]`" x-model="person.first_name"
                                           placeholder="Prénom" required
                                           class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                                </div>
                                <div>
                                    <input type="text" :name="`people[${index}][last_name]`" x-model="person.last_name"
                                           placeholder="Nom" required
                                           class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                                </div>
                                <div>
                                    <input type="email" :name="`people[${index}][email]`" x-model="person.email"
                                           placeholder="Email" required
                                           class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                                </div>
                                <button type="button" @click="removeRow(index)" x-show="people.length > 1"
                                        class="text-gray-400 hover:text-red-600 dark:hover:text-red-400 px-2 py-2" title="Retirer">
                                    ✕
                                </button>
                            </div>
                        </template>

                        <button type="button" @click="addRow()"
                                class="text-sm text-indigo-600 dark:text-indigo-400 hover:text-indigo-900 dark:hover:text-indigo-300 font-medium">
                            + Ajouter une personne
                        </button>
                    </div>

                    <div class="flex items-center gap-4 pt-2">
                        <button type="submit"
                                class="px-6 py-2 bg-indigo-600 text-white rounded-md hover:bg-indigo-700 text-sm font-medium">
                            Créer les accès et envoyer les invitations
                        </button>
                        <a href="{{ route('admin.system-email-templates', ['slug' => 'organization_invitation']) }}" class="text-sm text-gray-500 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-200">
                            Gérer le modèle d'email
                        </a>
                        <a href="{{ route('admin.email-logs', ['source' => 'organization-invitation']) }}" class="text-sm text-gray-500 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-200">
                            Voir l'historique des emails
                        </a>
                    </div>
                </form>
            </div>

            <!-- Suivi des invitations -->
            <div class="bg-white dark:bg-gray-800 shadow sm:rounded-lg overflow-hidden">
                <div class="p-6 pb-0 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                    <h3 class="text-sm font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">
                        Suivi des invitations
                    </h3>
                    <form method="GET" class="flex gap-2">
                        <select name="organization_id" onchange="this.form.submit()"
                                class="rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500">
                            <option value="">Toutes les organisations</option>
                            @foreach ($organizations as $organization)
                                <option value="{{ $organization->id }}" @selected($selectedOrganizationId === $organization->id)>
                                    {{ $organization->name }}
                                </option>
                            @endforeach
                        </select>
                    </form>
                </div>

                <div class="overflow-x-auto mt-4">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                        <thead class="bg-gray-50 dark:bg-gray-900/50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Personne</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Email</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Organisation</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Statut</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Envoyée</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Expiration</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Activée</th>
                                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                            @forelse ($invitations as $invitation)
                                <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/50">
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700 dark:text-gray-300">
                                        {{ $invitation->recipientFullName() }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700 dark:text-gray-300">
                                        {{ $invitation->recipient_email }}
                                        <span class="ml-1 text-xs uppercase text-gray-400 dark:text-gray-500">{{ $invitation->locale }}</span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                                        {{ $invitation->organization?->name }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        @if ($invitation->isAccepted())
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 dark:bg-green-900/30 text-green-800 dark:text-green-300">Activée</span>
                                        @elseif ($invitation->isRevoked())
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300">Révoquée</span>
                                        @elseif ($invitation->status === \App\Models\OrganizationInvitation::STATUS_EXPIRED || $invitation->isExpired())
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-amber-100 dark:bg-amber-900/30 text-amber-800 dark:text-amber-300">Expirée</span>
                                        @else
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 dark:bg-blue-900/30 text-blue-800 dark:text-blue-300">En attente</span>
                                        @endif
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                                        {{ $invitation->created_at->format('d/m/Y H:i') }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                                        {{ $invitation->expires_at?->format('d/m/Y H:i') ?? '—' }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                                        {{ $invitation->accepted_at?->format('d/m/Y H:i') ?? '—' }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium space-x-3">
                                        <a href="{{ route('admin.users.bulk-create.invitations.show', $invitation) }}"
                                           class="text-indigo-600 dark:text-indigo-400 hover:text-indigo-900 dark:hover:text-indigo-300">Voir</a>
                                        @if ($invitation->isPending())
                                            <form method="POST" action="{{ route('admin.users.bulk-create.invitations.resend', $invitation) }}" class="inline">
                                                @csrf
                                                <button type="submit" class="text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-200">Relancer</button>
                                            </form>
                                            <form method="POST" action="{{ route('admin.users.bulk-create.invitations.revoke', $invitation) }}" class="inline">
                                                @csrf
                                                <button type="submit" class="text-red-600 dark:text-red-400 hover:text-red-900 dark:hover:text-red-300">Révoquer</button>
                                            </form>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="8" class="px-6 py-4 text-center text-sm text-gray-500 dark:text-gray-400">
                                        Aucune invitation pour le moment.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="p-6 pt-4">
                    {{ $invitations->links() }}
                </div>
            </div>

        </div>
    </div>
</x-admin-layout>
