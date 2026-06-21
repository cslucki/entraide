<x-admin-layout title="Détail interaction IA">
    <div class="max-w-4xl mx-auto space-y-6">

        <div class="flex items-center gap-3">
            <a href="{{ route('admin.ia-usage') }}" class="text-sm text-gray-500 hover:text-indigo-600 dark:hover:text-indigo-400">← Retour</a>
        </div>

        <div class="bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 p-6 space-y-4">
            <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">
                Détail de l'interaction
            </h2>

            <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 text-sm">
                <div>
                    <span class="text-xs text-gray-500 dark:text-gray-400 block">Source</span>
                    <span class="font-medium text-gray-900 dark:text-gray-100">{{ $source === 'blog' ? 'Blog IA' : 'Supervision' }}</span>
                </div>
                <div>
                    <span class="text-xs text-gray-500 dark:text-gray-400 block">Date</span>
                    <span class="font-medium text-gray-900 dark:text-gray-100">{{ $interaction->created_at->format('d/m/Y H:i:s') }}</span>
                </div>
                <div>
                    <span class="text-xs text-gray-500 dark:text-gray-400 block">Utilisateur</span>
                    <span class="font-medium text-gray-900 dark:text-gray-100">{{ $interaction->user?->name ?? '—' }}</span>
                </div>
                <div>
                    <span class="text-xs text-gray-500 dark:text-gray-400 block">Modèle</span>
                    <span class="font-medium text-gray-900 dark:text-gray-100 font-mono text-xs">{{ $interaction->model ?? '—' }}</span>
                </div>
                @if($source === 'blog')
                <div>
                    <span class="text-xs text-gray-500 dark:text-gray-400 block">Fonctionnalité</span>
                    <span class="inline-block px-2 py-0.5 rounded text-xs font-medium bg-blue-100 dark:bg-blue-900 text-blue-700 dark:text-blue-300">{{ $interaction->feature }}</span>
                </div>
                <div>
                    <span class="text-xs text-gray-500 dark:text-gray-400 block">Organisation</span>
                    <span class="font-medium text-gray-900 dark:text-gray-100">{{ $interaction->organization?->name ?? '—' }}</span>
                </div>
                @else
                <div>
                    <span class="text-xs text-gray-500 dark:text-gray-400 block">Scénario</span>
                    <span class="font-medium text-gray-900 dark:text-gray-100">{{ $interaction->scenario_id ?? '—' }}</span>
                </div>
                <div>
                    <span class="text-xs text-gray-500 dark:text-gray-400 block">Provider</span>
                    <span class="font-medium text-gray-900 dark:text-gray-100">{{ $interaction->provider ?? '—' }}</span>
                </div>
                @endif
                <div>
                    <span class="text-xs text-gray-500 dark:text-gray-400 block">Tokens (in/out)</span>
                    <span class="font-medium text-gray-900 dark:text-gray-100">
                        {{ $source === 'blog' ? $interaction->input_tokens : '—' }} / {{ $source === 'blog' ? $interaction->output_tokens : '—' }}
                    </span>
                </div>
                <div>
                    <span class="text-xs text-gray-500 dark:text-gray-400 block">Coût</span>
                    <span class="font-medium text-gray-900 dark:text-gray-100">{{ $interaction->cost_usd ? '$' . number_format($interaction->cost_usd, 6) : '—' }}</span>
                </div>
            </div>
        </div>

        {{-- Prompt --}}
        <div class="bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 p-6">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100 mb-3">Prompt</h3>
            <pre class="text-sm text-gray-700 dark:text-gray-300 bg-gray-50 dark:bg-gray-900 rounded-lg p-4 overflow-x-auto whitespace-pre-wrap">{{ $source === 'blog' ? $interaction->prompt : ($interaction->input_excerpt ?? '—') }}</pre>
        </div>

        {{-- Response --}}
        <div class="bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 p-6">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100 mb-3">Réponse</h3>
            <pre class="text-sm text-gray-700 dark:text-gray-300 bg-gray-50 dark:bg-gray-900 rounded-lg p-4 overflow-x-auto whitespace-pre-wrap">{{ $source === 'blog' ? $interaction->response : ($interaction->result_payload ?? '—') }}</pre>
        </div>

        {{-- Metadata --}}
        @if($source === 'blog' && $interaction->metadata)
        <div class="bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 p-6">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100 mb-3">Métadonnées</h3>
            <pre class="text-sm text-gray-700 dark:text-gray-300 bg-gray-50 dark:bg-gray-900 rounded-lg p-4 overflow-x-auto whitespace-pre-wrap">{{ json_encode($interaction->metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
        </div>
        @endif

        <div class="flex justify-start">
            <a href="{{ route('admin.ia-usage') }}" class="px-4 py-2 bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-200 text-sm font-medium rounded-lg hover:bg-gray-300 dark:hover:bg-gray-600 transition">← Retour</a>
        </div>
    </div>
</x-admin-layout>
