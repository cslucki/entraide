<x-admin-layout title="Signalements">
    <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 dark:bg-gray-700">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Signalé par</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Cible</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Motif</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Statut</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Date</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                @forelse($reports as $report)
                <tr class="hover:bg-gray-50 dark:hover:bg-gray-750">
                    <td class="px-4 py-3">
                        <p class="font-medium text-gray-900 dark:text-gray-100">{{ $report->reporter->name }}</p>
                        <p class="text-xs text-gray-500">{{ $report->reporter->email }}</p>
                    </td>
                    <td class="px-4 py-3">
                        @if($report->reportable_type === 'App\Models\Service')
                            <span class="px-2 py-0.5 bg-blue-100 dark:bg-blue-900/40 text-blue-700 dark:text-blue-300 rounded text-xs">Service</span>
                        @elseif($report->reportable_type === 'App\Models\ServiceRequest')
                            <span class="px-2 py-0.5 bg-green-100 dark:bg-green-900/40 text-green-700 dark:text-green-300 rounded text-xs">Demande</span>
                        @else
                            <span class="px-2 py-0.5 bg-orange-100 dark:bg-orange-900/40 text-orange-700 dark:text-orange-300 rounded text-xs">Utilisateur</span>
                        @endif
                    </td>
                    <td class="px-4 py-3">
                        <p class="text-gray-900 dark:text-gray-100">{{ $report->reason }}</p>
                        @if($report->details)
                        <p class="text-xs text-gray-500 mt-0.5">{{ Str::limit($report->details, 80) }}</p>
                        @endif
                    </td>
                    <td class="px-4 py-3">
                        @php
                            $colors = [
                                'pending'   => 'bg-yellow-100 dark:bg-yellow-900/40 text-yellow-700 dark:text-yellow-300',
                                'reviewed'  => 'bg-green-100 dark:bg-green-900/40 text-green-700 dark:text-green-300',
                                'dismissed' => 'bg-gray-100 dark:bg-gray-700 text-gray-500 dark:text-gray-400',
                            ];
                            $labels = ['pending' => 'En attente', 'reviewed' => 'Traité', 'dismissed' => 'Ignoré'];
                        @endphp
                        <span class="px-2 py-0.5 rounded text-xs {{ $colors[$report->status] ?? '' }}">
                            {{ $labels[$report->status] ?? $report->status }}
                        </span>
                    </td>
                    <td class="px-4 py-3 text-xs text-gray-500">{{ $report->created_at->format('d/m/Y H:i') }}</td>
                    <td class="px-4 py-3">
                        @if($report->status === 'pending')
                        <div class="flex gap-3">
                            <form method="POST" action="{{ route('admin.reports.review', $report) }}">
                                @csrf @method('PATCH')
                                <button class="text-xs text-green-600 hover:underline">Traité</button>
                            </form>
                            <form method="POST" action="{{ route('admin.reports.dismiss', $report) }}">
                                @csrf @method('PATCH')
                                <button class="text-xs text-red-500 hover:underline">Ignorer</button>
                            </form>
                        </div>
                        @else
                        <span class="text-xs text-gray-400">—</span>
                        @endif
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="6" class="px-4 py-8 text-center text-sm text-gray-400">Aucun signalement.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if($reports->hasPages())
    <div class="mt-4">{{ $reports->links() }}</div>
    @endif
</x-admin-layout>
