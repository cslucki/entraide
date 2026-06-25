<!DOCTYPE html>
<html lang="fr" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Détail — Affecter données</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50 dark:bg-gray-900 text-gray-900 dark:text-gray-100 p-6">
    <h1 class="text-lg font-bold mb-1">Détail des données à affecter</h1>
    @if($orgName)
    <p class="text-sm text-gray-500 dark:text-gray-400 mb-6">Organisation cible : <strong>{{ $orgName }}</strong></p>
    @else
    <p class="text-sm text-gray-500 dark:text-gray-400 mb-6">Organisation cible : <strong>par défaut</strong></p>
    @endif

    @foreach($previews as $key => $preview)
    <div class="mb-6 bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
        <div class="px-4 py-3 bg-gray-50 dark:bg-gray-700 border-b border-gray-200 dark:border-gray-600 flex items-center justify-between">
            <h2 class="text-sm font-semibold">{{ $preview['label'] }}</h2>
            <span class="text-xs text-gray-500 dark:text-gray-400">
                {{ $preview['affected'] }} / {{ $preview['total'] }} ligne(s) avec org_id différent
            </span>
        </div>
        @if(count($preview['rows']) > 0)
        <div class="overflow-x-auto">
            <table class="w-full text-xs">
                <thead>
                    <tr class="bg-gray-50 dark:bg-gray-750">
                        @foreach(array_keys($preview['rows'][0]) as $col)
                        <th class="px-3 py-2 text-left font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide whitespace-nowrap">{{ $col }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    @foreach($preview['rows'] as $row)
                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-750">
                        @foreach($row as $val)
                        <td class="px-3 py-2 text-gray-700 dark:text-gray-300 whitespace-nowrap max-w-xs truncate">{{ is_null($val) ? 'NULL' : (is_array($val) ? json_encode($val) : $val) }}</td>
                        @endforeach
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="px-4 py-2 text-xs text-gray-400 border-t border-gray-100 dark:border-gray-700">
            Aperçu des {{ min(5, $preview['total']) }} premières lignes (lecture seule)
        </div>
        @else
        <div class="px-4 py-6 text-center text-sm text-gray-400">Aucune ligne dans ce jeu de données.</div>
        @endif
    </div>
    @endforeach

    <p class="text-xs text-gray-400 mt-4">Ce détail est en lecture seule. Aucune modification n'est effectuée.</p>
</body>
</html>
