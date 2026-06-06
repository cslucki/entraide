<x-admin-layout title="Bugs signalés">
    <div class="mb-4 rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
        <h1 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Bugs signalés</h1>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
            Vue globale, toutes organisations confondues. Les bugs corrigés peuvent afficher une note publique de correction.
        </p>
    </div>

    <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 dark:bg-gray-700">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Signalé par</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Organisation</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Bug</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Statut</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Date</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                @forelse($bugReports as $bugReport)
                    @php
                        $statusClasses = [
                            'pending' => 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/40 dark:text-yellow-300',
                            'fixed' => 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300',
                            'dismissed' => 'bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-400',
                        ];
                        $statusLabels = [
                            'pending' => 'En attente',
                            'fixed' => 'Corrigé',
                            'dismissed' => 'Ignoré',
                        ];
                    @endphp
                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-700">
                        <td class="px-4 py-3 align-top">
                            <p class="font-medium text-gray-900 dark:text-gray-100">{{ $bugReport->reporter?->name ?? 'Utilisateur supprimé' }}</p>
                            @if($bugReport->reporter?->email)
                                <p class="text-xs text-gray-500">{{ $bugReport->reporter->email }}</p>
                            @endif
                        </td>
                        <td class="px-4 py-3 align-top text-gray-700 dark:text-gray-300">
                            {{ $bugReport->organization?->name ?? 'Organisation inconnue' }}
                        </td>
                        <td class="px-4 py-3 align-top">
                            <p class="font-medium text-gray-900 dark:text-gray-100">{{ $bugReport->reason }}</p>
                            <p class="mt-0.5 text-xs text-gray-500">{{ Str::limit($bugReport->details, 120) }}</p>
                            @if($bugReport->page_url)
                                <a href="{{ $bugReport->page_url }}" target="_blank" rel="noopener noreferrer" class="mt-1 inline-block max-w-xs truncate text-xs text-indigo-600 hover:underline dark:text-indigo-400">
                                    {{ $bugReport->page_url }}
                                </a>
                            @endif
                            @if($bugReport->resolution_notes)
                                <p class="mt-2 rounded bg-green-50 px-2 py-1 text-xs text-green-800 dark:bg-green-900/20 dark:text-green-200">
                                    {{ $bugReport->resolution_notes }}
                                </p>
                            @endif
                        </td>
                        <td class="px-4 py-3 align-top">
                            <span class="rounded px-2 py-0.5 text-xs {{ $statusClasses[$bugReport->status] ?? 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300' }}">
                                {{ $statusLabels[$bugReport->status] ?? $bugReport->status }}
                            </span>
                        </td>
                        <td class="px-4 py-3 align-top text-xs text-gray-500">
                            {{ $bugReport->created_at->format('d/m/Y H:i') }}
                            @if($bugReport->fixed_at)
                                <br>Corrigé {{ $bugReport->fixed_at->format('d/m/Y') }}
                            @endif
                        </td>
                        <td class="px-4 py-3 align-top">
                            @if($bugReport->status !== 'fixed')
                                <form method="POST" action="{{ route('admin.bug-reports.fix', $bugReport) }}" class="w-56 space-y-2">
                                    @csrf
                                    @method('PATCH')
                                    <textarea name="resolution_notes" rows="2" placeholder="Ce qui a été corrigé (optionnel)"
                                              class="w-full resize-none rounded-lg border border-gray-200 bg-white px-2 py-1 text-xs text-gray-900 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100"></textarea>
                                    <div class="flex gap-3">
                                        <button class="text-xs text-green-600 hover:underline">Corrigé</button>
                                        @if($bugReport->status === 'pending')
                                            <button form="dismiss-bug-{{ $bugReport->id }}" class="text-xs text-red-500 hover:underline">Ignorer</button>
                                        @endif
                                    </div>
                                </form>
                                @if($bugReport->status === 'pending')
                                    <form id="dismiss-bug-{{ $bugReport->id }}" method="POST" action="{{ route('admin.bug-reports.dismiss', $bugReport) }}" class="hidden">
                                        @csrf
                                        @method('PATCH')
                                    </form>
                                @endif
                            @else
                                <span class="text-xs text-gray-400">—</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-8 text-center text-sm text-gray-400">Aucun bug signalé.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if($bugReports->hasPages())
        <div class="mt-4">{{ $bugReports->links() }}</div>
    @endif
</x-admin-layout>
