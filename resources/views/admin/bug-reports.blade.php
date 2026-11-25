{{-- TASK-1904 — « Signalements », back-office SUPERADMIN. Seul ecran ou les
     signalements « Securite des enfants » sont lus (decision MASTER D3) ; ceux
     en attente sont en tete. Confidentiel : la note de reponse n'est lue que
     par l'auteur du signalement. --}}
<x-admin-layout title="Signalements">
    <div class="mb-4 rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
        <h1 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Signalements</h1>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
            Vue globale, toutes organisations confondues. Confidentiel : la réponse éventuelle n'est visible que de l'auteur du signalement.
        </p>
        @if($pendingChildSafety > 0)
            <a href="{{ route('admin.bug-reports', ['category' => \App\Models\BugReport::CHILD_SAFETY, 'status' => 'pending']) }}"
               data-child-safety-pending
               class="mt-3 inline-flex rounded-lg bg-red-50 px-3 py-2 text-sm font-semibold text-red-700 ring-1 ring-red-200 hover:underline dark:bg-red-900/30 dark:text-red-200 dark:ring-red-800">
                {{ $pendingChildSafety }} signalement(s) « {{ __('bugs.categories.child_safety') }} » en attente — à traiter en priorité
            </a>
        @endif
        <form method="GET" action="{{ route('admin.bug-reports') }}" class="mt-4 flex flex-wrap items-end gap-3" data-report-filters>
            <label class="text-xs text-gray-500 dark:text-gray-400">Catégorie
                <select name="category" class="mt-1 block rounded-lg border border-gray-200 bg-white px-2 py-1 text-sm text-gray-900 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                    <option value="">Toutes</option>
                    @foreach(\App\Models\BugReport::CATEGORIES as $category)
                        <option value="{{ $category }}" @selected(($filters['category'] ?? null) === $category)>{{ __('bugs.categories.'.$category) }}</option>
                    @endforeach
                </select>
            </label>
            <label class="text-xs text-gray-500 dark:text-gray-400">Statut
                <select name="status" class="mt-1 block rounded-lg border border-gray-200 bg-white px-2 py-1 text-sm text-gray-900 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                    <option value="">Tous</option>
                    @foreach(\App\Http\Controllers\Admin\AdminBugReportController::STATUSES as $status)
                        <option value="{{ $status }}" @selected(($filters['status'] ?? null) === $status)>{{ __('bugs.status_'.$status) }}</option>
                    @endforeach
                </select>
            </label>
            <button type="submit" class="rounded-lg bg-gray-900 px-3 py-1.5 text-sm font-semibold text-white dark:bg-gray-100 dark:text-gray-900">Filtrer</button>
        </form>
    </div>

    <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 dark:bg-gray-700">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Signalé par</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Organisation</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Signalement</th>
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
                        $statusClasses['resolved'] = $statusClasses['fixed'];
                        $statusLabels = collect(\App\Http\Controllers\Admin\AdminBugReportController::STATUSES)
                            ->mapWithKeys(fn ($status) => [$status => __('bugs.status_'.$status)])->all();
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
                            @if($bugReport->isChildSafety())
                                <span data-report-category="child_safety" class="inline-flex rounded px-2 py-0.5 text-xs font-bold bg-red-600 text-white">{{ __('bugs.categories.child_safety') }}</span>
                            @else
                                <span data-report-category="{{ $bugReport->category }}" class="inline-flex rounded px-2 py-0.5 text-xs font-medium bg-indigo-50 text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-200">{{ __('bugs.categories.'.$bugReport->category) }}</span>
                            @endif
                            @if($bugReport->reason !== $bugReport->category)
                                <p class="mt-1 font-medium text-gray-900 dark:text-gray-100">{{ $bugReport->reason }}</p>
                            @endif
                            @if(mb_strlen($bugReport->details) > 120)
                                <details class="mt-0.5 text-xs text-gray-500">
                                    <summary class="cursor-pointer">{{ Str::limit($bugReport->details, 120) }}</summary>
                                    <p class="mt-1 whitespace-pre-line text-gray-700 dark:text-gray-300" data-report-details>{{ $bugReport->details }}</p>
                                </details>
                            @else
                                <p class="mt-0.5 whitespace-pre-line text-xs text-gray-500" data-report-details>{{ $bugReport->details }}</p>
                            @endif
                            @if(\App\Models\BugReport::isInternalUrl($bugReport->page_url))
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
                                <br>Traité {{ $bugReport->fixed_at->format('d/m/Y') }}
                            @endif
                        </td>
                        <td class="px-4 py-3 align-top">
                            @if($bugReport->status !== 'fixed')
                                <form method="POST" action="{{ route('admin.bug-reports.fix', $bugReport) }}" class="w-56 space-y-2">
                                    @csrf
                                    @method('PATCH')
                                    <textarea name="resolution_notes" rows="2" placeholder="Réponse à l'auteur (optionnel)"
                                              class="w-full resize-none rounded-lg border border-gray-200 bg-white px-2 py-1 text-xs text-gray-900 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100"></textarea>
                                    <div class="flex gap-3">
                                        <button class="text-xs text-green-600 hover:underline">Traité</button>
                                        @if($bugReport->status === 'pending')
                                            <button form="dismiss-bug-{{ $bugReport->id }}" class="text-xs text-red-500 hover:underline">Classer</button>
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
                            <form method="POST" action="{{ route('admin.bug-reports.destroy', $bugReport) }}" class="mt-2"
                                  onsubmit="return confirm('Supprimer ce signalement définitivement ?')">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="text-xs text-red-400 hover:text-red-600 hover:underline">Supprimer</button>
                            </form>
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

    @if($bugReports->hasPages())
        <div class="mt-4">{{ $bugReports->links() }}</div>
    @endif
</x-admin-layout>
