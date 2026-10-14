<x-admin-layout title="Organisations">
    <div class="flex justify-end mb-4">
        <a href="{{ route('admin.organizations.create') }}"
           class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium rounded-lg transition">
            + Créer une organisation
        </a>
    </div>

    {{-- BUG-05 — `overflow-hidden` COUPAIT ce qui depassait au lieu de le
         rendre atteignable : si une ligne deborde malgre les paliers, elle doit
         pouvoir defiler horizontalement DANS sa carte, jamais faire defiler la
         page entiere. --}}
    <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-x-auto">
        {{-- BUG-05 — `table-fixed` sous le plus petit palier : une table en
             largeur AUTO prend la largeur de son contenu le plus large et
             deborde ; figee, les deux seules colonnes encore visibles se
             partagent l'ecran et la ligne tient sans defilement. Au-dela de
             `sm`, on rend la main a la largeur automatique. --}}
        <table class="w-full text-sm table-fixed sm:table-auto">
            <thead class="bg-gray-50 dark:bg-gray-700">
                <tr>
                    {{-- TASK-1683 BUG-05 — responsive par PALIERS (idiome T1640) :
                         neuf colonnes ne tiennent pas sur un telephone. Les
                         secondaires disparaissent par palier, et leur contenu
                         REMONTE dans la cellule Organisation : masquer sans rien
                         mettre a la place rendrait l'ecran inutile, pas etroit. --}}
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Organisation</th>
                    <th class="hidden xl:table-cell px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Slug</th>
                    <th class="hidden lg:table-cell px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Responsable</th>
                    <th class="hidden sm:table-cell px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Membres</th>
                    <th class="hidden xl:table-cell px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Services</th>
                    <th class="hidden md:table-cell px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Visibilité</th>
                    <th class="hidden lg:table-cell px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Boucles</th>
                    <th class="hidden xl:table-cell px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Plateforme</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                @forelse($organizations as $c)
                <tr class="hover:bg-gray-50 dark:hover:bg-gray-750 {{ !$c->is_active ? 'opacity-60' : '' }}">
                    <td class="px-4 py-3">
                        <div class="flex items-center gap-3">
                            <div class="w-3 h-3 rounded-full flex-shrink-0" style="background-color: {{ $c->accent_color }}"></div>
                            <div class="min-w-0">
                                {{-- BUG-05 — `block` : `truncate` sur un <a> INLINE est
                                     inoperant, et un nom long poussait toute la ligne
                                     hors de l'ecran (la liste ne se lisait qu'en
                                     defilant lateralement). Meme forme que la liste
                                     des utilisateurs, ou le nom vit dans un bloc. --}}
                                <a href="{{ route('organization.home', ['organization' => $c->slug]) }}" target="_blank" rel="noopener"
                                   class="block truncate font-medium text-gray-900 dark:text-gray-100 hover:text-indigo-600 dark:hover:text-indigo-400 transition">
                                    {{ $c->name }} ↗
                                </a>
                                @if($c->hero_title)
                                <p class="text-xs text-gray-500 truncate">{{ $c->hero_title }}</p>
                                @endif
                                {{-- TASK-1683 BUG-04 — le lien « Membres (n) » vivait
                                     ici depuis BUG-01 ; il fait doublon avec le
                                     compteur cliquable de la colonne MEMBRES
                                     (BUG-03), qui mene exactement au meme endroit.
                                     Une seule poignee, la ou le nombre est deja lu.

                                     BUG-05 — sauf sous le palier ou cette colonne-la
                                     disparait : la remontee ci-dessous n'est JAMAIS
                                     visible en meme temps que la colonne (sm:hidden
                                     contre hidden sm:table-cell), ce n'est donc pas
                                     un doublon mais le meme lien a sa seule place
                                     disponible. --}}
                                <p class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs text-gray-500 xl:hidden">
                                    <span class="font-mono text-gray-400">{{ $c->slug }}</span>
                                    <a href="{{ route('admin.users', ['organization_id' => $c->id]) }}"
                                       class="font-medium text-indigo-600 hover:underline dark:text-indigo-400 sm:hidden">
                                        {{ $c->users_count }} membre{{ $c->users_count > 1 ? 's' : '' }}
                                    </a>
                                    <span class="md:hidden">&middot; {{ $c->is_active ? 'Active' : 'Inactive' }}{{ $c->is_public ? ' · Publique' : ' · Privée' }}</span>
                                    <span class="lg:hidden">&middot; {{ $c->admin?->full_name ?? '—' }}</span>
                                </p>
                            </div>
                        </div>
                    </td>
                    <td class="hidden xl:table-cell px-4 py-3 font-mono text-xs text-gray-600 dark:text-gray-400">{{ $c->slug }}</td>
                    <td class="hidden lg:table-cell px-4 py-3 text-gray-700 dark:text-gray-300">
                        {{-- TASK-1683 BUG-02 — les infos du responsable sans quitter
                             l'ecran : la Fiche est un partiel PARTAGE, pilote par un
                             evenement fenetre (T1666), deja reutilise par
                             /admin/users, /admin/points et /admin/transactions. On
                             reprend le meme geste, aucune route ni requete nouvelle. --}}
                        @if($c->admin)
                            <button type="button"
                                    @click="$dispatch('open-user-profile', { id: '{{ $c->admin->id }}', name: @js($c->admin->full_name) })"
                                    class="text-left text-sky-600 hover:underline dark:text-sky-400">
                                {{-- `full_name` (Prenom NOM) et non `name`, qui ne
                                     porte que le patronyme. Le controleur charge le
                                     modele admin ENTIER (`with('admin')`) : la
                                     colonne first_name est donc bien la. --}}
                                {{ $c->admin->full_name }}
                            </button>
                        @else
                            —
                        @endif
                    </td>
                    <td class="hidden sm:table-cell px-4 py-3">
                        {{-- TASK-1683 BUG-03 — le compteur EST la poignee : cliquer
                             le nombre mene a la liste filtree de cette Organization
                             (meme cible que le lien de la colonne Organisation). --}}
                        <a href="{{ route('admin.users', ['organization_id' => $c->id]) }}"
                           class="font-medium text-indigo-600 hover:underline dark:text-indigo-400">
                            {{ $c->users_count }}
                        </a>
                    </td>
                    <td class="hidden xl:table-cell px-4 py-3 text-gray-700 dark:text-gray-300">{{ $c->services_count }}</td>
                    <td class="hidden md:table-cell px-4 py-3">
                        <div class="flex flex-col gap-1">
                            @if($c->is_active)
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200">Active</span>
                            @else
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300">Inactive</span>
                            @endif
                            @if($c->is_public)
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200">Publique</span>
                            @else
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200">Privée</span>
                            @endif
                            @if($c->is_default)
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-purple-100 text-purple-800 dark:bg-purple-900 dark:text-purple-200">Défaut</span>
                            @endif
                        </div>
                    </td>
                    <td class="hidden lg:table-cell px-4 py-3">
                        <div class="flex flex-col items-start gap-1">
                            @if($c->loops_enabled)
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200">Activées</span>
                            @else
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300">Désactivées</span>
                            @endif
                            @if($c->isMonoLoop())
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-purple-100 text-purple-800 dark:bg-purple-900 dark:text-purple-200">Mono</span>
                            @else
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200">Multi</span>
                            @endif
                            <div class="max-w-[180px] text-xs text-gray-600 dark:text-gray-400">
                                <span class="text-gray-500 dark:text-gray-500">Principale :</span>
                                @if($c->primaryLoop)
                                    <a href="{{ route('admin.loops.edit', $c->primaryLoop) }}" class="font-medium text-indigo-600 dark:text-indigo-400 hover:underline">
                                        {{ $c->primaryLoop->name }}
                                    </a>
                                @else
                                    <span class="text-gray-400 dark:text-gray-500">— Aucune —</span>
                                @endif
                            </div>
                        </div>
                    </td>
                    <td class="hidden xl:table-cell px-4 py-3 text-xs text-gray-700 dark:text-gray-300">
                        <div class="truncate max-w-[140px]">{{ $c->platform_name }}</div>
                        @if($c->maintenance_mode)
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-amber-100 text-amber-800 dark:bg-amber-900 dark:text-amber-200">Maintenance</span>
                        @endif
                    </td>
                    <td class="px-4 py-3">
                        {{-- BUG-05 — les trois actions s'empilent sous le palier le
                             plus etroit plutot que de pousser le tableau hors ecran. --}}
                        <div class="flex flex-col items-start gap-1 sm:flex-row sm:items-center sm:gap-2">
                            <a href="{{ route('admin.organizations.edit', $c) }}" class="text-indigo-600 hover:text-indigo-800 text-xs font-medium">Éditer</a>
                            <form method="POST" action="{{ route('admin.organizations.toggle-active', $c) }}" class="inline">
                                @csrf
                                <button type="submit" class="text-xs font-medium {{ $c->is_active ? 'text-amber-600 hover:text-amber-800' : 'text-green-600 hover:text-green-800' }}">
                                    {{ $c->is_active ? 'Désactiver' : 'Activer' }}
                                </button>
                            </form>
                            <form method="POST" action="{{ route('admin.organizations.destroy', $c) }}" class="inline"
                                  onsubmit="return confirm('Supprimer cette organisation ? Les utilisateurs et services associés seront remis dans l\'organisation globale.');">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="text-xs font-medium text-red-600 hover:text-red-800">Supprimer</button>
                            </form>
                        </div>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="9" class="px-4 py-8 text-center text-gray-500 dark:text-gray-400">Aucune organisation pour le moment.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if($organizations->hasPages())
    <div class="mt-4">
        {{ $organizations->links() }}
    </div>
    @endif

    {{-- TASK-1683 BUG-02 — la Fiche du responsable. Une seule modal pour toute
         la page, sa propre racine Alpine : sans cet include, le bouton
         ci-dessus serait inerte ET silencieux. --}}
    @include('admin.partials.user-profile-modal')
</x-admin-layout>
