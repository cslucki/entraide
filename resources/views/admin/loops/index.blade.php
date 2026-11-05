<x-admin-layout title="Boucles">
    {{-- Deux repères, pas un dashboard. Le total du parc ne varie jamais avec
         les filtres ; le second chiffre est le total de l'Organization choisie
         dans le filtre — le filtre Type ne le change pas. --}}
    <div class="mb-4 flex flex-wrap gap-8">
        <div>
            <div class="text-2xl font-bold text-gray-900 dark:text-gray-100">{{ $totalLoops }}</div>
            <div class="text-xs text-gray-500 dark:text-gray-400">{{ __('loops.admin_loops_total_label') }}</div>
        </div>
        @if($organizationLoops !== null)
        <div>
            <div class="text-2xl font-bold text-gray-900 dark:text-gray-100">{{ $organizationLoops }}</div>
            <div class="text-xs text-gray-500 dark:text-gray-400">{{ __('loops.admin_loops_org_label', ['organization' => $selectedOrganization->name]) }}</div>
        </div>
        @endif
    </div>

    <div class="flex flex-col gap-4 mb-4 lg:flex-row lg:items-center lg:justify-between">
        <p class="text-sm text-gray-500 dark:text-gray-400">
            Boucles filtrées par organisation. Par défaut : votre organisation.
        </p>
        <div class="flex flex-wrap items-center gap-3">
            <form method="GET" class="flex flex-wrap items-center gap-3">
                <select name="organization_id" class="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm">
                    <option value="all" {{ $selectedOrganizationId === 'all' ? 'selected' : '' }}>Toutes les organisations</option>
                    @foreach($organizations as $org)
                    <option value="{{ $org->id }}" {{ $selectedOrganizationId === $org->id ? 'selected' : '' }}>{{ $org->name }} {{ $org->is_default ? '(par défaut)' : '' }}</option>
                    @endforeach
                </select>
                {{-- Le filtre Type se combine au filtre Organization. Les
                     options viennent du registre, jamais du fichier de
                     configuration : les types crees s'y trouvent, dans la
                     portee affichee seulement. --}}
                <select name="type" class="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm">
                    <option value="">{{ __('loops.admin_loops_filter_all_types') }}</option>
                    @foreach($typeOptions as $typeKey => $typeLabel)
                    <option value="{{ $typeKey }}" @selected($selectedType === $typeKey)>{{ $typeLabel }}</option>
                    @endforeach
                </select>
                <button type="submit" class="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm hover:bg-indigo-700">Filtrer</button>
                @if(request()->has('organization_id') || request()->filled('type'))
                <a href="{{ route('admin.loops') }}" class="px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg text-sm text-gray-600 dark:text-gray-400">Effacer</a>
                @endif
            </form>
            <a href="{{ route('admin.loops.create') }}"
               class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium rounded-lg transition">
                + Créer une boucle
            </a>
        </div>
    </div>

    {{-- TASK-1761 point 3 — la MEME carte que sur `/admin/dashboard`, un seul
         composant. Elle suit le filtre Organization de cette page : sur « toutes
         les organisations » elle montre tout et affiche la colonne Organization,
         sous une Organization choisie elle se borne et la colonne disparait. --}}
    <x-admin.demandes-adhesion :demandes="$demandesDAdhesion"
                               :organization-id="$selectedOrganization?->id"
                               class="mb-6" />

    <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 dark:bg-gray-700">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Boucle</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">État</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('loops.type_label') }}</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                @forelse($loops as $orgLoop)
                <tr class="hover:bg-gray-50 dark:hover:bg-gray-750">
                    {{-- Secondary information is folded into this cell rather than
                         spread over eleven columns: the table was wider than the
                         viewport and the Actions column fell off the screen. --}}
                    <td class="px-4 py-3">
                        <div class="min-w-0 max-w-md">
                            <a href="{{ route('admin.loops.show', $orgLoop) }}"
                               class="block truncate font-medium text-gray-900 transition hover:text-indigo-600 hover:underline dark:text-gray-100 dark:hover:text-indigo-400"
                               title="Voir la Boucle">{{ $orgLoop->name }}</a>
                            {{-- TASK-1717 — l'adresse publique, sous le nom.
                                 C'est la colonne ou l'on cherche « quelle est
                                 cette Boucle » : son slug y repond mieux qu'un
                                 identifiant qu'il faut aller ouvrir pour voir.
                                 En `font-mono` parce que c'est une portion
                                 d'URL, pas une phrase. --}}
                            @if($orgLoop->slug)
                                <p class="truncate font-mono text-[11px] text-gray-400 dark:text-gray-500"
                                   data-loop-slug="{{ $orgLoop->id }}">{{ $orgLoop->slug }}</p>
                            @endif
                            @if($orgLoop->description)
                                <p class="truncate text-xs text-gray-500 dark:text-gray-400">{{ Str::limit($orgLoop->description, 70) }}</p>
                            @endif
                            <p class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-[11px] text-gray-400">
                                @if($orgLoop->organization)
                                    <a href="{{ route('admin.organizations.edit', $orgLoop->organization) }}"
                                       class="rounded bg-indigo-50 px-1.5 py-0.5 font-medium text-indigo-700 hover:underline dark:bg-indigo-900/40 dark:text-indigo-300">
                                        {{ $orgLoop->organization->name }}
                                    </a>
                                @endif
                                <span>{{ $orgLoop->active_members_count }} membre{{ $orgLoop->active_members_count !== 1 ? 's' : '' }}</span>
                                <span>· {{ $orgLoop->invitations_count }} {{ mb_strtolower(__('loops.invitations_sent_count')) }}@if($orgLoop->pending_invitations_count) ({{ $orgLoop->pending_invitations_count }} en attente)@endif</span>
                                {{-- TASK-1761 point 4 — « N cards liees » cede la place au
                                     nombre de demandes en attente. Cyril l'a demande pour
                                     traiter les demandes vite : un « 0 » repete sur chaque
                                     ligne rendrait justement les lignes qui comptent plus
                                     difficiles a reperer. Le chiffre n'apparait donc que
                                     lorsqu'il y a quelque chose a faire. --}}
                                @php $enAttente = (int) ($demandesParBoucle[$orgLoop->id] ?? 0); @endphp
                                @if($enAttente > 0)
                                    <span class="rounded bg-amber-100 px-1.5 py-0.5 font-semibold text-amber-800 dark:bg-amber-900/40 dark:text-amber-200"
                                          data-marqueur="boucle-demandes-en-attente"
                                          data-boucle="{{ $orgLoop->id }}">
                                        {{ trans_choice('admin.join_requests_pending_count', $enAttente, ['count' => $enAttente]) }}
                                    </span>
                                @endif
                                {{-- TASK-1761 point 5 — le PROPRIETAIRE, pas le createur :
                                     les deux se confondaient ici alors que ce sont deux
                                     notions distinctes (T1679, « Boucle sans owner »). Le
                                     createur n'est montre que lorsqu'il n'y a AUCUN
                                     proprietaire, et il est alors nomme comme tel pour
                                     qu'on ne le prenne pas pour l'un d'eux. --}}
                                @if($orgLoop->owners->isEmpty() && $orgLoop->creator)
                                    <span>· {{ __('admin.loop_creator_label') }} {{ $orgLoop->creator->full_name }}</span>
                                @endif
                                @php $lastMsg = $orgLoop->messages->first(); @endphp
                                @if($lastMsg?->created_at)
                                    <span>· {{ $lastMsg->created_at->diffForHumans() }}</span>
                                @endif
                            </p>
                            {{-- Hors du <p> ci-dessus, et ce n'est pas un detail : « Se
                                 connecter sous » est une route POST, donc un <form>, et un
                                 <form> dans un <p> fait fermer le paragraphe par le
                                 navigateur — la mise en page se defait sans erreur. --}}
                            @if($orgLoop->owners->isNotEmpty())
                                <p class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-[11px] text-gray-500 dark:text-gray-400"
                                   data-marqueur="boucle-proprietaires" data-boucle="{{ $orgLoop->id }}">
                                    <span class="font-medium">{{ trans_choice('admin.loop_owners_inline', $orgLoop->owners->count()) }}</span>
                                    @foreach($orgLoop->owners as $proprietaire)
                                        @continue(! $proprietaire->user)
                                        <span class="inline-flex items-center gap-1">
                                            <span data-marqueur="proprietaire-nom">{{ $proprietaire->user->full_name }}</span>
                                            {{-- Les deux refus de `loginAsUser()` sont connus d'avance :
                                                 banni, ou autre administrateur. Proposer un bouton qui
                                                 repondra « impossible » serait une fausse promesse. --}}
                                            @if(! $proprietaire->user->is_admin && ! $proprietaire->user->banned_at)
                                                <button type="submit"
                                                        form="login-as-{{ $orgLoop->id }}-{{ $proprietaire->user->id }}"
                                                        data-marqueur="se-connecter-sous"
                                                        class="text-indigo-600 underline underline-offset-2 hover:no-underline dark:text-indigo-400">{{ __('admin.login_as_short') }}</button>
                                            @endif
                                        </span>
                                    @endforeach
                                </p>
                                {{-- Les formulaires vivent hors de toute cellule de texte et
                                     sont relies au bouton par `form="..."` : aucun <form>
                                     imbrique dans le <form> du selecteur de type. --}}
                                @foreach($orgLoop->owners as $proprietaire)
                                    @continue(! $proprietaire->user || $proprietaire->user->is_admin || $proprietaire->user->banned_at)
                                    <form id="login-as-{{ $orgLoop->id }}-{{ $proprietaire->user->id }}"
                                          method="POST" action="{{ route('admin.users.login-as', $proprietaire->user) }}" class="hidden">
                                        @csrf
                                    </form>
                                @endforeach
                            @endif
                        </div>
                    </td>

                    {{-- Visibility and status share one column: two badges, not two. --}}
                    <td class="px-4 py-3 whitespace-nowrap">
                        <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium {{ $orgLoop->isPublic() ? 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200' : 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300' }}">
                            {{ $orgLoop->isPublic() ? 'Publique' : 'Privée' }}
                        </span>
                        <span class="mt-1 block text-[11px] {{ $orgLoop->status === 'active' ? 'text-green-700 dark:text-green-300' : 'text-gray-500 dark:text-gray-400' }}">
                            {{ $orgLoop->status === 'active' ? 'Active' : ($orgLoop->status === 'archived' ? 'Archivée' : $orgLoop->status) }}
                        </span>
                    </td>

                    <td class="px-4 py-3 hidden md:table-cell">
                        {{-- Inline form: changing the type applies the preset, which
                             only ever adds missing cards. Nothing is removed. --}}
                        @php
                            $registry = app(\App\Support\Loops\LoopTypeRegistry::class);
                            $currentType = $registry->resolve($orgLoop->type);
                            // Choices, plus whatever this Loop already carries: a
                            // type withdrawn from the offer must not disappear from
                            // the Loop that has it.
                            $choices = $registry->selectableFor($orgLoop->type);
                        @endphp
                        <form method="POST" action="{{ route('admin.loops.type.update', $orgLoop) }}">
                            @csrf @method('PUT')
                            <select name="type" onchange="this.form.submit()"
                                    class="rounded-lg border border-gray-300 bg-white px-2 py-1 text-xs text-gray-900 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                                @foreach($choices as $key => $definition)
                                    <option value="{{ $key }}" @selected($currentType === $key)>
                                        {{-- label(), jamais la definition : un type cree n'a
                                             pas de label_key, et le mot surcharge de
                                             l'Organization doit se voir ici aussi. --}}
                                        {{ $registry->label($key, $orgLoop->organization) }}@unless($registry->isAvailable($key)) — {{ __('loops.types_admin_unavailable') }}@endunless
                                    </option>
                                @endforeach
                            </select>
                        </form>
                    </td>
                    <td class="px-4 py-3">
                        <div class="flex gap-2 items-center">
                            {{-- The name itself opens the overview; the Actions column
                                 keeps only what acts. TASK-1680 : « Voir la Boucle »
                                 revient — depuis T1679 la page tenant rend la
                                 presentation au SuperAdmin non membre au lieu d'un
                                 404. workspaceUrl() : URL org-scopee canonique, la
                                 route courte resoudrait l'Organization de l'admin. --}}
                            <a href="{{ $orgLoop->workspaceUrl() }}" target="_blank" rel="noopener" class="text-xs text-gray-500 hover:text-indigo-600 hover:underline">{{ __('admin.loop_view_front') }}</a>
                            {{-- TASK-1681 — inviter directement dans l'Organization de
                                 CETTE Boucle : le workflow canonique bulk-create sait
                                 pre-selectionner l'org par la query string. --}}
                            <a href="{{ route('admin.users.bulk-create', ['organization_id' => $orgLoop->organization_id]) }}" class="text-xs text-gray-500 hover:text-indigo-600 hover:underline">Inviter</a>
                            <a href="{{ route('admin.loops.edit', $orgLoop) }}" class="text-xs font-medium text-indigo-600 hover:underline">Modifier</a>
                            {{-- TASK-1717 — l'ecran de composition des outils
                                 (`admin.loops.configure`) existait sans aucun
                                 lien depuis cette liste : on ne l'atteignait
                                 qu'en connaissant son URL. --}}
                            <a href="{{ route('admin.loops.configure', $orgLoop) }}"
                               data-loop-configure="{{ $orgLoop->id }}"
                               class="text-xs font-medium text-indigo-600 hover:underline">Modifier outils</a>
                            <a href="{{ route('admin.loops.files', $orgLoop) }}" class="text-xs text-gray-500 hover:text-indigo-600 hover:underline">Fichiers</a>
                            @if($orgLoop->isActive())
                            <form method="POST" action="{{ route('admin.loops.archive', $orgLoop) }}"
                                  onsubmit="return confirm('Archiver la boucle « {{ addslashes($orgLoop->name) }} » ?')">
                                @csrf
                                <button class="text-xs text-amber-500 hover:underline">Archiver</button>
                            </form>
                            @elseif($orgLoop->isArchived())
                            <form method="POST" action="{{ route('admin.loops.restore', $orgLoop) }}"
                                  onsubmit="return confirm('Réactiver la boucle « {{ addslashes($orgLoop->name) }} » ?')">
                                @csrf
                                <button class="text-xs text-green-500 hover:underline">Réactiver</button>
                            </form>
                            @endif
                            <form method="POST" action="{{ route('admin.loops.destroy', $orgLoop) }}"
                                  onsubmit="return confirm('Supprimer la boucle « {{ addslashes($orgLoop->name) }} » ? Cette action est irréversible.')">
                                @csrf @method('DELETE')
                                <button class="text-xs text-red-500 hover:underline">Supprimer</button>
                            </form>
                        </div>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="4" class="px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                        Aucune boucle dans cette Organisation.
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if($loops->hasPages())
    <div class="mt-4">
        {{ $loops->links() }}
    </div>
    @endif
</x-admin-layout>
