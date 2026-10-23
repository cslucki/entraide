<x-admin-layout title="Grand livre des points">
    {{-- TASK-1667 — cet ecran n'existait pas, et son absence rendait un blocage
         de suppression inexplicable : « ce membre a N ecriture(s) au grand livre
         des points », sans aucun moyen de les voir.

         Il est VOLONTAIREMENT en lecture seule : un historique comptable ne se
         supprime pas, et c'est precisement pour cela qu'il bloque. Cet ecran
         explique le blocage ; il ne le leve pas. --}}
    <p class="text-sm text-gray-500 dark:text-gray-400 mb-5">
        {{ __('admin.points_intro') }}
    </p>

    <form method="GET" class="mb-5 flex flex-wrap gap-3">
        {{-- TASK-1693 bug I — chercher AVANT de choisir. Le `select` ci-dessous
             repond bien quand on connait deja la personne ; il ne repond pas a
             « qui est ce ofsh@… ? ». Ce champ borne a la fois les ecritures
             affichees ET la liste deroulante, pour qu'on puisse designer
             ensuite la personne trouvee. --}}
        <input type="search" name="search" value="{{ request('search') }}"
               placeholder="{{ __('admin.points_search_placeholder') }}"
               data-points-search
               class="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm min-w-56">
        {{-- TASK-1667 — choisir la personne ICI, et non en fabriquant l'URL a la
             main. Ce `select` fait deux choses d'un coup : il borne la liste, et
             il fait apparaitre le panneau de correction. Il porte aussi le
             report du filtre — sans lui, changer d'organisation ELARGIRAIT la
             liste en silence.
             Le solde est affiche dans l'option : on le lit AVANT de choisir. --}}
        <select name="user_id" class="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm max-w-xs">
            <option value="">{{ __('admin.points_member_any') }}</option>
            @foreach($membres as $membre)
            <option value="{{ $membre->id }}" {{ request('user_id') === $membre->id ? 'selected' : '' }}>
                {{ $membre->full_name }} — {{ $membre->points_balance }} pts
            </option>
            @endforeach
        </select>
        <select name="organization_id" class="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm">
            <option value="all" {{ $selectedOrganizationId === 'all' ? 'selected' : '' }}>{{ __('admin.all_organizations') }}</option>
            @foreach($organizations as $org)
            <option value="{{ $org->id }}" {{ $selectedOrganizationId === $org->id ? 'selected' : '' }}>{{ $org->name }}</option>
            @endforeach
        </select>
        <input type="text" name="reason" value="{{ request('reason') }}" placeholder="{{ __('admin.points_reason_placeholder') }}"
               class="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm">
        <button type="submit" class="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm hover:bg-indigo-700">{{ __('admin.filter') }}</button>
        {{-- TASK-1693 bug I — `search` entre dans cette liste. Sans lui, une
             recherche seule n'aurait fait apparaitre aucun bouton « Effacer » :
             l'ecran serait filtre sans offrir de sortie. --}}
        @if(request()->hasAny(['organization_id', 'reason', 'user_id', 'search']))
        <a href="{{ route('admin.points') }}" class="px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg text-sm text-gray-600 dark:text-gray-400">{{ __('admin.clear') }}</a>
        @endif
    </form>

    {{-- TASK-1693 bug F — quand personne n'est designe, DIRE que la correction
         existe. Le panneau ne s'affiche qu'une fois la personne choisie, et
         rien ne l'annoncait : la capacite etait livree mais invisible, exactement
         le defaut signale. Un indice coute une ligne ; une capacite introuvable
         equivaut a une capacite absente. --}}
    @unless($filteredUser)
    <p class="mb-4 text-xs text-gray-500 dark:text-gray-400" data-points-adjust-hint>
        {{ __('admin.points_adjust_hint') }}
    </p>
    @endunless

    {{-- Une liste bornee a une personne doit se VOIR : sinon une ecriture se lit
         comme « il n'y en a qu'une sur toute la plateforme ». --}}
    @if($filteredUser)
    <div class="mb-4 flex flex-wrap items-center gap-2 rounded-xl border border-indigo-200 bg-indigo-50 px-4 py-3 text-sm text-indigo-800 dark:border-indigo-800 dark:bg-indigo-900/20 dark:text-indigo-300">
        <span>{{ __('admin.points_filtered_on_user', ['name' => $filteredUser->full_name, 'solde' => $solde]) }}</span>
        <a href="{{ route('admin.points', array_filter(['organization_id' => request('organization_id')])) }}"
           class="font-medium underline">{{ __('admin.points_filtered_clear') }}</a>
    </div>
    @endif

    {{-- TASK-1667 — corriger le solde d'une personne, SANS toucher a l'historique.
         Un grand livre est append-only : on n'edite pas une ecriture passee, on
         en AJOUTE une qui corrige. Le solde bouge, la tracabilite reste — et
         c'est precisement cette immuabilite qui fait qu'un solde bloque la
         suppression d'un compte.
         La primitive existe deja : `admin.users.adjust-points`. --}}
    @if($filteredUser)
    <div class="mb-5 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-4">
        <p class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">
            {{ __('admin.points_adjust_title', ['name' => $filteredUser->full_name, 'balance' => $filteredUser->points_balance]) }}
        </p>

        <div class="flex flex-wrap items-end gap-4">
            <form method="POST" action="{{ route('admin.users.adjust-points', $filteredUser) }}" class="flex flex-wrap items-end gap-2">
                @csrf
                <div>
                    <label for="delta" class="block text-xs text-gray-500 dark:text-gray-400 mb-1">{{ __('admin.points_adjust_delta') }}</label>
                    <input id="delta" type="number" name="delta" step="1" required
                           class="w-28 px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm">
                </div>
                <div>
                    <label for="reason" class="block text-xs text-gray-500 dark:text-gray-400 mb-1">{{ __('admin.points_adjust_reason') }}</label>
                    <input id="reason" type="text" name="reason" maxlength="255"
                           class="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm">
                </div>
                <button type="submit" class="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm hover:bg-indigo-700">
                    {{ __('admin.points_adjust_submit') }}
                </button>
            </form>

            {{-- Remise a zero : une ecriture de correction egale a l'oppose du
                 solde. Masquee quand le solde est deja nul — un `delta` de 0 est
                 refuse par la validation, et un bouton qui ne peut qu'echouer est
                 pire que pas de bouton. --}}
            @if($filteredUser->points_balance != 0)
            {{-- Pas de `confirm()` natif : non gere par Playwright, il annule la
                 soumission SANS erreur ni log (T1655). Le libelle nomme le montant
                 exact, et l'action reste reversible par une autre ecriture. --}}
            <form method="POST" action="{{ route('admin.users.adjust-points', $filteredUser) }}">
                @csrf
                <input type="hidden" name="delta" value="{{ -$filteredUser->points_balance }}">
                <input type="hidden" name="reason" value="admin_reset">
                <button type="submit" class="text-sm text-red-600 hover:underline">
                    {{ __('admin.points_reset', ['balance' => $filteredUser->points_balance]) }}
                </button>
            </form>
            @endif
        </div>
    </div>
    @endif

    <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 dark:bg-gray-700">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">
                            <a href="{{ request()->fullUrlWithQuery(['sort' => 'created_at', 'direction' => ($tri === 'created_at' && $sens === 'asc') ? 'desc' : 'asc']) }}"
                               class="inline-flex items-center gap-1 hover:text-gray-700 dark:hover:text-gray-200">
                                {{ __('admin.points_col_date') }}
                                @if($tri === 'created_at')<span aria-hidden="true">{!! $sens === 'asc' ? '&uarr;' : '&darr;' !!}</span>@endif
                            </a>
                        </th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('admin.points_col_member') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">
                            <a href="{{ request()->fullUrlWithQuery(['sort' => 'delta', 'direction' => ($tri === 'delta' && $sens === 'asc') ? 'desc' : 'asc']) }}"
                               class="inline-flex items-center gap-1 hover:text-gray-700 dark:hover:text-gray-200">
                                {{ __('admin.points_col_delta') }}
                                @if($tri === 'delta')<span aria-hidden="true">{!! $sens === 'asc' ? '&uarr;' : '&darr;' !!}</span>@endif
                            </a>
                        </th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">
                            <a href="{{ request()->fullUrlWithQuery(['sort' => 'reason', 'direction' => ($tri === 'reason' && $sens === 'asc') ? 'desc' : 'asc']) }}"
                               class="inline-flex items-center gap-1 hover:text-gray-700 dark:hover:text-gray-200">
                                {{ __('admin.points_col_reason') }}
                                @if($tri === 'reason')<span aria-hidden="true">{!! $sens === 'asc' ? '&uarr;' : '&darr;' !!}</span>@endif
                            </a>
                        </th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('admin.points_col_organization') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    @forelse($entries as $entry)
                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-750">
                        <td class="px-4 py-3 text-xs text-gray-500 whitespace-nowrap">{{ $entry->created_at?->format('d/m/Y H:i') ?? '—' }}</td>
                        <td class="px-4 py-3">
                            @if($entry->user)
                            {{-- La Fiche, pas `profile.show` : cette derniere est bornee au
                                 tenant et rend 404 hors organisation courante (TASK-1666). --}}
                            <button type="button"
                                @click="$dispatch('open-user-profile', { id: '{{ $entry->user->id }}', name: @js($entry->user->full_name) })"
                                class="text-indigo-600 hover:underline text-xs text-left">{{ $entry->user->full_name }}</button>
                            {{-- TASK-1667 — un pas de plus vers la correction : la Fiche
                                 dit QUI c'est, ce lien amene a ses ecritures et au
                                 panneau de correction. --}}
                            {{-- TASK-1693 bug H — ce lien etait un `&rarr;` gris
                                 pale, dont la seule explication vivait dans un
                                 attribut `title`. Une infobulle ne se survole
                                 pas au doigt, ne se lit pas au lecteur d'ecran
                                 comme une commande, et ne se devine pas : la
                                 porte principale vers la correction d'un solde
                                 etait donc invisible, exactement comme le
                                 panneau l'etait avant le bug F.

                                 Un VERBE, et une forme qui se lit comme une
                                 commande. Le libelle reste court — « Corriger »
                                 plutot que « Voir et corriger ses points » —
                                 parce qu'il se repete a chaque ligne : lecon du
                                 bug D, ou un libelle long avait fait exploser
                                 la largeur d'une colonne. La phrase complete
                                 reste en `title`. --}}
                            <a href="{{ request()->fullUrlWithQuery(['user_id' => $entry->user->id]) }}"
                               class="ml-2 inline-flex items-center gap-1 rounded-md border border-indigo-200 dark:border-indigo-800 px-2 py-0.5 text-xs font-medium text-indigo-700 dark:text-indigo-300 hover:bg-indigo-50 dark:hover:bg-indigo-900/30 hover:border-indigo-400 transition"
                               data-points-focus-member="{{ $entry->user->id }}"
                               title="{{ __('admin.points_focus_member') }}">
                                {{ __('admin.points_focus_member_short') }}
                                <span aria-hidden="true">&rarr;</span>
                            </a>
                            @else <span class="text-xs text-gray-400">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            <span class="font-medium {{ $entry->delta < 0 ? 'text-red-600 dark:text-red-400' : 'text-emerald-600 dark:text-emerald-400' }}">
                                {{ $entry->delta > 0 ? '+' : '' }}{{ $entry->delta }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-xs text-gray-600 dark:text-gray-400 font-mono">{{ $entry->reason }}</td>
                        <td class="px-4 py-3 text-xs text-gray-600 dark:text-gray-400">{{ $entry->organization?->name ?? '—' }}</td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="5" class="px-4 py-12 text-center text-sm text-gray-500 dark:text-gray-400">
                            {{-- TASK-1693 bug J — une recherche qui TROUVE une
                                 personne mais rend un tableau vide se lit comme
                                 « la recherche ne marche pas ». Elle marche :
                                 ce tableau liste des ECRITURES, et cette
                                 personne n'en a aucune.

                                 Le dire, et offrir le pas suivant — car si l'on
                                 cherche quelqu'un ici, c'est pour corriger son
                                 solde, et c'est justement quand le grand livre
                                 est vide qu'il faut une premiere ecriture. --}}
                            @if(request()->filled('search') && $membres->isNotEmpty())
                                <p data-points-search-no-entry>
                                    {{ trans_choice('admin.points_search_no_entry', $membres->count(), ['count' => $membres->count()]) }}
                                </p>
                                <div class="mt-3 flex flex-wrap items-center justify-center gap-2">
                                    @foreach($membres as $trouve)
                                    <a href="{{ request()->fullUrlWithQuery(['user_id' => $trouve->id]) }}"
                                       data-points-search-member="{{ $trouve->id }}"
                                       class="inline-flex items-center gap-1 rounded-md border border-indigo-200 dark:border-indigo-800 px-2 py-1 text-xs font-medium text-indigo-700 dark:text-indigo-300 hover:bg-indigo-50 dark:hover:bg-indigo-900/30 hover:border-indigo-400 transition">
                                        {{ $trouve->full_name }} — {{ $trouve->points_balance }} pts
                                        <span aria-hidden="true">&rarr;</span>
                                    </a>
                                    @endforeach
                                </div>
                            @else
                                {{ __('admin.points_empty') }}
                            @endif
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-4">{{ $entries->links() }}</div>

    @include('admin.partials.user-profile-modal')
</x-admin-layout>
