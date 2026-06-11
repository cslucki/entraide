<x-admin-layout title="Détail interaction IA">
    <div class="max-w-4xl mx-auto space-y-6">

        {{-- Header --}}
        <div class="flex items-start justify-between gap-4">
            <div>
                <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">
                    Détail de l'interaction IA
                </h2>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                    {{ $interaction->created_at->format('d/m/Y H:i:s') }} · {{ $interaction->id }}
                </p>
            </div>
            <a href="{{ route('admin.ai-interactions') }}"
               class="px-4 py-2 bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-200 text-sm font-medium rounded-lg hover:bg-gray-300 dark:hover:bg-gray-600 transition">
                ← Retour
            </a>
        </div>

        {{-- Metadata cards --}}
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            <div class="bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 p-4">
                <p class="text-xs text-gray-500 dark:text-gray-400 uppercase">Scénario</p>
                <p class="text-sm font-medium text-gray-900 dark:text-gray-100 mt-1">{{ $interaction->scenario_id }}</p>
            </div>
            <div class="bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 p-4">
                <p class="text-xs text-gray-500 dark:text-gray-400 uppercase">Provider</p>
                <p class="text-sm font-medium text-gray-900 dark:text-gray-100 mt-1">{{ $interaction->provider ?? '—' }}</p>
            </div>
            <div class="bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 p-4">
                <p class="text-xs text-gray-500 dark:text-gray-400 uppercase">Modèle</p>
                <p class="text-sm font-medium text-gray-900 dark:text-gray-100 mt-1">{{ $interaction->model ?? '—' }}</p>
            </div>
            <div class="bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 p-4">
                <p class="text-xs text-gray-500 dark:text-gray-400 uppercase">Statut</p>
                <p class="text-sm font-medium mt-1">
                    @if($interaction->status === 'success')
                        <span class="text-green-600 dark:text-green-400">success</span>
                    @else
                        <span class="text-red-600 dark:text-red-400">{{ $interaction->status }}</span>
                    @endif
                </p>
            </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <div class="bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 p-4">
                <p class="text-xs text-gray-500 dark:text-gray-400 uppercase">Latence</p>
                <p class="text-sm font-medium text-gray-900 dark:text-gray-100 mt-1">
                    {{ $interaction->latency_ms ? $interaction->latency_ms . ' ms' : '—' }}
                </p>
            </div>
            <div class="bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 p-4">
                <p class="text-xs text-gray-500 dark:text-gray-400 uppercase">Tokens (in / out)</p>
                <p class="text-sm font-medium text-gray-900 dark:text-gray-100 mt-1">
                    {{ $interaction->input_tokens }} / {{ $interaction->output_tokens }}
                </p>
            </div>
            <div class="bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 p-4">
                <p class="text-xs text-gray-500 dark:text-gray-400 uppercase">Coût estimé</p>
                <p class="text-sm font-medium text-gray-900 dark:text-gray-100 mt-1">
                    {{ $interaction->cost_usd ? '$' . number_format($interaction->cost_usd, 6) : '—' }}
                </p>
            </div>
        </div>

        {{-- Input --}}
        <div class="bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 p-4 space-y-3">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">Input</h3>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
                <div>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Excerpt</p>
                    <p class="mt-1 text-gray-800 dark:text-gray-200">{{ $interaction->input_excerpt ?? '—' }}</p>
                </div>
                <div>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Hash (SHA-256)</p>
                    <p class="mt-1 text-gray-800 dark:text-gray-200 font-mono text-xs break-all">{{ $interaction->input_hash ?? '—' }}</p>
                </div>
            </div>
            <div class="text-sm">
                <p class="text-xs text-gray-500 dark:text-gray-400">Longueur</p>
                <p class="mt-1 text-gray-800 dark:text-gray-200">{{ $interaction->input_length }} caractères</p>
            </div>
        </div>

        {{-- Result summary --}}
        @if($interaction->result_summary)
        <div class="bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 p-4 space-y-3">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">Résumé du résultat</h3>
            <p class="text-sm text-gray-800 dark:text-gray-200">{{ $interaction->result_summary }}</p>
        </div>
        @endif

        {{-- Result payload --}}
        @if($interaction->result_payload)
        <div class="bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 p-4 space-y-3">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">Payload (JSON)</h3>
            <pre class="text-xs text-gray-800 dark:text-gray-200 bg-gray-50 dark:bg-gray-900 rounded-lg p-4 overflow-x-auto">{{ json_encode($interaction->result_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
        </div>
        @endif

        {{-- Metadata --}}
        @if($interaction->metadata)
        <div class="bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 p-4 space-y-3">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">Métadonnées</h3>
            <pre class="text-xs text-gray-800 dark:text-gray-200 bg-gray-50 dark:bg-gray-900 rounded-lg p-4 overflow-x-auto">{{ json_encode($interaction->metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
        </div>
        @endif

        {{-- Organization / User --}}
        <div class="bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 p-4 space-y-3">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">Contexte</h3>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
                <div>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Organisation</p>
                    <p class="mt-1 text-gray-800 dark:text-gray-200">
                        {{ $interaction->organization?->name ?? '—' }}
                    </p>
                </div>
                <div>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Utilisateur</p>
                    <p class="mt-1 text-gray-800 dark:text-gray-200">
                        {{ $interaction->user?->name ?? '—' }}
                    </p>
                </div>
            </div>
        </div>
    </div>
</x-admin-layout>
