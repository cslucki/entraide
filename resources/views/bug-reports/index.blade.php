<x-app-layout>
    <div class="max-w-4xl mx-auto px-4 py-8">
        <div class="mb-6">
            <p class="text-xs font-semibold uppercase tracking-wide text-indigo-600 dark:text-indigo-400">Qualité produit</p>
            <h1 class="mt-1 text-2xl font-bold text-gray-900 dark:text-gray-100">Bugs signalés</h1>
            <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                Suivi des bugs signalés{{ $organization ? ' pour '.$organization->name : '' }} et des corrections publiées.
            </p>
        </div>

        <div class="space-y-3">
            @forelse($bugReports as $bugReport)
                @php
                    $statusClasses = [
                        'pending' => 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-300',
                        'fixed' => 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-300',
                    ];
                    $statusLabels = [
                        'pending' => 'Signalé',
                        'fixed' => 'Corrigé',
                    ];
                @endphp
                <article class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                        <div>
                            <h2 class="font-semibold text-gray-900 dark:text-gray-100">{{ $bugReport->reason }}</h2>
                            <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $bugReport->details }}</p>
                        </div>
                        <span class="inline-flex w-fit rounded-full px-2 py-0.5 text-xs font-medium {{ $statusClasses[$bugReport->status] ?? 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300' }}">
                            {{ $statusLabels[$bugReport->status] ?? $bugReport->status }}
                        </span>
                    </div>

                    @if($bugReport->status === 'fixed' && $bugReport->resolution_notes)
                        <div class="mt-3 rounded-lg bg-green-50 px-3 py-2 text-sm text-green-800 dark:bg-green-900/20 dark:text-green-200">
                            <span class="font-semibold">Correction :</span> {{ $bugReport->resolution_notes }}
                        </div>
                    @endif

                    <p class="mt-3 text-xs text-gray-400 dark:text-gray-500">
                        Signalé le {{ $bugReport->created_at->format('d/m/Y') }}
                        @if($bugReport->fixed_at)
                            · corrigé le {{ $bugReport->fixed_at->format('d/m/Y') }}
                        @endif
                    </p>
                </article>
            @empty
                <div class="rounded-xl border border-dashed border-gray-300 bg-white px-4 py-10 text-center dark:border-gray-700 dark:bg-gray-800">
                    <p class="text-sm text-gray-500 dark:text-gray-400">Aucun bug public pour le moment.</p>
                </div>
            @endforelse
        </div>

        @if($bugReports->hasPages())
            <div class="mt-6">{{ $bugReports->links() }}</div>
        @endif
    </div>
</x-app-layout>
