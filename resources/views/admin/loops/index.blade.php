<x-admin-layout title="Boucles">
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
                                <span class="cursor-help" title="{{ $orgLoop->cards->where('enabled', true)->map->label()->implode(', ') ?: '—' }}">
                                    · {{ $orgLoop->enabled_cards_count }} {{ mb_strtolower(__('loops.cards_linked')) }}
                                </span>
                                @if($orgLoop->creator)
                                    <span>· {{ $orgLoop->creator->full_name }}</span>
                                @endif
                                @php $lastMsg = $orgLoop->messages->first(); @endphp
                                @if($lastMsg?->created_at)
                                    <span>· {{ $lastMsg->created_at->diffForHumans() }}</span>
                                @endif
                            </p>
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
                                 keeps only what acts. Workspace is gone from here: it
                                 needs an active membership and 404'd for an admin who
                                 is not one. --}}
                            <a href="{{ route('admin.loops.edit', $orgLoop) }}" class="text-xs font-medium text-indigo-600 hover:underline">Modifier</a>
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
