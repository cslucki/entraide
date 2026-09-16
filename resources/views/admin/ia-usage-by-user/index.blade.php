@php
    // TASK-1586 — la MEME autorite economique que le releve Organization Admin
    // (OrganizationAiEconomicUsage::byUser). « — » dit « on ne sait pas »,
    // « $0 » dirait « ca n'a rien coute » : un inconnu n'est jamais rendu 0.
    $cout = static fn (?float $v): string => $v === null ? '—' : '$'.number_format($v, 6);
    $tranche = static function (array $t, string $compteur = 'invocation_count'): string {
        $parts = [number_format($t[$compteur])];
        if (($t['unknown_count'] ?? 0) > 0) {
            $parts[] = $t['unknown_count'].' inconnu(s)';
        }
        if (($t['failed_count'] ?? 0) > 0) {
            $parts[] = $t['failed_count'].' échec(s)';
        }
        if (($t['unevaluated_count'] ?? 0) > 0) {
            $parts[] = $t['unevaluated_count'].' non évalué(s)';
        }

        return implode(' · ', $parts);
    };
    $lien = static fn (string $sort): string => route('admin.ia-usage-by-user', array_merge(request()->except(['sort', 'direction', 'page']), ['sort' => $sort, 'direction' => request('sort') === $sort && request('direction') === 'desc' ? 'asc' : 'desc']));
    $fleche = static fn (string $sort): string => request('sort', 'known_cost') === $sort ? (request('direction') === 'asc' ? ' ↑' : ' ↓') : '';
@endphp
<x-admin-layout>
    <x-slot name="title">Utilisation IA par utilisateur</x-slot>

    <div class="space-y-6" data-usage-by-user>
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-bold dark:text-white">Utilisation IA par utilisateur</h1>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1" data-usage-by-user-authority>
                    Autorité économique canonique (la même que le relevé de chaque Organization Admin) : générations, embeddings (recherche, indexation), rerank — coût connu, appels au coût inconnu et échecs séparés, aucun double comptage. Fenêtre {{ $from->format('d/m/Y') }} → {{ $to->subDay()->format('d/m/Y') }} (UTC). Organizations actives seulement ; la dépense d'une Organization supprimée ou sans Organization se lit sur le cockpit plateforme.
                </p>
            </div>
        </div>

        {{-- Filtres --}}
        <form method="GET" class="flex flex-wrap gap-3 items-end">
            <div>
                <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Organisation</label>
                <select name="organization_id" class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-300 text-sm">
                    <option value="">Toutes</option>
                    @foreach($organizations as $org)
                        <option value="{{ $org->id }}" @selected(request('organization_id') == $org->id)>{{ $org->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Du</label>
                <input type="date" name="date_from" value="{{ request('date_from') }}" class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-300 text-sm">
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Au</label>
                <input type="date" name="date_to" value="{{ request('date_to') }}" class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-300 text-sm">
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Recherche</label>
                <input type="text" name="search" value="{{ request('search') }}" placeholder="Nom ou email…" class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-300 text-sm">
            </div>
            <div class="flex gap-2">
                <button type="submit" class="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm hover:bg-indigo-700 transition">Filtrer</button>
                <a href="{{ route('admin.ia-usage-by-user') }}" class="px-4 py-2 bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-lg text-sm hover:bg-gray-300 dark:hover:bg-gray-600 transition">Réinitialiser</a>
            </div>
        </form>

        {{-- Tableau --}}
        <div class="overflow-x-auto bg-white dark:bg-gray-800 rounded-lg shadow">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-200 dark:border-gray-700 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                        <th class="px-4 py-3"><a href="{{ $lien('user') }}" class="hover:text-gray-700 dark:hover:text-gray-200">Utilisateur{{ $fleche('user') }}</a></th>
                        <th class="px-4 py-3">Organisation</th>
                        <th class="px-4 py-3 text-right">Générations</th>
                        <th class="px-4 py-3 text-right">Embeddings recherche</th>
                        <th class="px-4 py-3 text-right">Embeddings indexation</th>
                        <th class="px-4 py-3 text-right">Rerank</th>
                        <th class="px-4 py-3 text-right"><a href="{{ $lien('total_count') }}" class="hover:text-gray-700 dark:hover:text-gray-200" title="Générations + embeddings — le rerank a sa colonne, comme sur le relevé Organization Admin">Appels{{ $fleche('total_count') }}</a></th>
                        <th class="px-4 py-3 text-right"><a href="{{ $lien('known_cost') }}" class="hover:text-gray-700 dark:hover:text-gray-200">Coût connu{{ $fleche('known_cost') }}</a></th>
                        <th class="px-4 py-3 text-right"><a href="{{ $lien('unknown_count') }}" class="hover:text-gray-700 dark:hover:text-gray-200">Non mesurés{{ $fleche('unknown_count') }}</a></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    @forelse($rows as $row)
                        @php $user = $row['user']; @endphp
                        <tr class="hover:bg-gray-50 dark:hover:bg-gray-750 transition" data-usage-row="{{ $row['user_id'] ?? 'unattributed' }}" data-usage-org="{{ $row['organization']->id }}" data-usage-known-cost="{{ $row['total_known_cost_usd'] ?? '' }}" data-usage-unknown="{{ $row['total_unknown_count'] }}">
                            <td class="px-4 py-3">
                                @if($user)
                                    <div class="font-medium text-gray-900 dark:text-gray-100">{{ $user->name }}</div>
                                    <div class="text-xs text-gray-500">{{ $user->email }}</div>
                                @elseif($row['user_id'] !== null)
                                    <span class="text-gray-400 italic">Utilisateur supprimé</span>
                                @else
                                    <span class="text-gray-400 italic">Non attribuable</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-gray-600 dark:text-gray-400">{{ $row['organization']->name }}</td>
                            <td class="px-4 py-3 text-right font-mono text-xs text-gray-900 dark:text-gray-100">{{ $tranche($row['generation'], 'trace_count') }}<div class="text-gray-500">{{ $cout($row['generation']['known_cost_usd']) }}</div></td>
                            <td class="px-4 py-3 text-right font-mono text-xs text-gray-900 dark:text-gray-100">{{ $tranche($row['embedding_query']) }}<div class="text-gray-500">{{ $cout($row['embedding_query']['known_cost_usd']) }}</div></td>
                            <td class="px-4 py-3 text-right font-mono text-xs text-gray-900 dark:text-gray-100">{{ $tranche($row['embedding_ingestion']) }}<div class="text-gray-500">{{ $cout($row['embedding_ingestion']['known_cost_usd']) }}</div>
                                @if($row['embedding_undeclared']['invocation_count'] > 0)<div class="text-amber-600 dark:text-amber-400">+ {{ $tranche($row['embedding_undeclared']) }} non déclaré(s)</div>@endif
                            </td>
                            <td class="px-4 py-3 text-right font-mono text-xs text-gray-900 dark:text-gray-100" data-usage-rerank="{{ $row['user_id'] ?? 'unattributed' }}">{{ $tranche($row['rerank']) }}<div class="text-gray-500">{{ $cout($row['rerank']['known_cost_usd']) }}</div></td>
                            <td class="px-4 py-3 text-right font-mono text-xs text-gray-900 dark:text-gray-100"><span data-usage-total-count="{{ $row['total_count'] }}">{{ number_format($row['total_count']) }}</span></td>
                            <td class="px-4 py-3 text-right font-mono text-xs">
                                @if($row['total_known_cost_usd'] !== null)
                                    <span class="text-gray-900 dark:text-gray-100">${{ number_format((float) $row['total_known_cost_usd'], 6) }}</span>
                                @else
                                    <span class="text-gray-400">—</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right font-mono text-xs">
                                @if($row['total_unknown_count'] > 0)
                                    <span class="text-amber-600 dark:text-amber-400">{{ $row['total_unknown_count'] }} non mesuré(s)</span>
                                @else
                                    <span class="text-gray-400">0</span>
                                @endif
                                @if($row['total_unevaluated_count'] > 0)
                                    <div class="text-gray-400">{{ $row['total_unevaluated_count'] }} non évalué(s)</div>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="px-4 py-8 text-center text-gray-400">Aucune utilisation IA sur cette fenêtre.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <p class="text-xs text-gray-400 dark:text-gray-500">« — » : aucun coût mesuré (pas $0). « Appels » = générations + embeddings (le rerank a sa colonne, même définition que le relevé Organization Admin). « Non mesurés » compte les appels réussis au coût inconnu, rerank inclus ; les échecs et les traces historiques non évaluées sont comptés à part.</p>

        <div class="flex justify-center">{{ $rows->links() }}</div>
    </div>
</x-admin-layout>
