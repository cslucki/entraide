<x-admin-layout title="Fix catégories">
    <div class="mb-6">
        <p class="text-sm text-gray-500 dark:text-gray-400">
            Met à jour les noms B2B et B2C des catégories et rattache 5 compétences par catégorie,
            selon le référentiel défini. Les IDs des catégories sont préservés (services et demandes existants y sont liés).
        </p>
    </div>

    @if(session('success'))
    <div class="mb-4 p-3 bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 rounded-lg text-sm text-green-700 dark:text-green-300">
        {{ session('success') }}
    </div>
    @endif

    <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden mb-6">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 dark:bg-gray-700">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Catégorie (actuelle)</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Nouveau nom B2C</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Nouveau nom B2B</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Compétences actuelles</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Nouvelles compétences</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                @foreach($categories as $cat)
                <tr>
                    <td class="px-4 py-3">
                        <div class="flex items-center gap-2">
                            <span class="w-3 h-3 rounded-full flex-shrink-0" style="background-color:{{ $cat->color }}"></span>
                            <span class="font-medium text-gray-900 dark:text-gray-100">{{ $cat->name_b2c ?? '—' }}</span>
                        </div>
                        <p class="text-xs text-gray-400 mt-0.5">{{ $cat->services_count }} services · {{ $cat->service_requests_count }} demandes</p>
                    </td>
                    <td class="px-4 py-3 text-gray-700 dark:text-gray-300">{{ $mapping[$cat->slug]['name_b2c'] ?? '—' }}</td>
                    <td class="px-4 py-3 text-gray-700 dark:text-gray-300">{{ $mapping[$cat->slug]['name_b2b'] ?? '—' }}</td>
                    <td class="px-4 py-3">
                        <div class="flex flex-wrap gap-1">
                            @forelse($cat->skills as $skill)
                            <span class="inline-flex px-2 py-0.5 bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-400 rounded text-xs">{{ $skill->name }}</span>
                            @empty
                            <span class="text-xs text-gray-400">—</span>
                            @endforelse
                        </div>
                    </td>
                    <td class="px-4 py-3">
                        <div class="flex flex-wrap gap-1">
                            @foreach($mapping[$cat->slug]['skills'] ?? [] as $skill)
                            <span class="inline-flex px-2 py-0.5 bg-indigo-50 dark:bg-indigo-900/30 text-indigo-700 dark:text-indigo-300 rounded text-xs">{{ $skill }}</span>
                            @endforeach
                        </div>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <form method="POST" action="{{ route('admin.outils.fix-categories.do') }}" class="flex items-center gap-3">
        @csrf
        <button type="submit" class="px-6 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium rounded-lg transition"
                onclick="return confirm('Appliquer la mise à jour des catégories et compétences ? Cette action remplacera les compétences existantes.')">
            Appliquer le fix catégories
        </button>
        <span class="text-xs text-gray-400">Les IDs des catégories ne seront pas modifiés</span>
    </form>
</x-admin-layout>
