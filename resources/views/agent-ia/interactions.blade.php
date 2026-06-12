<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold text-gray-900 dark:text-gray-100">Échanges avec mon agent IA</h1>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Questions posées sur votre profil et réponses données par votre agent.</p>
            </div>
            <a href="{{ route('agent-ia.wizard') }}" class="inline-flex items-center rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">
                Modifier mon profil IA
            </a>
        </div>
    </x-slot>

    <div class="max-w-5xl mx-auto px-4 py-8 sm:px-6 lg:px-8">
        @if(!$profile)
            <div class="rounded-2xl border border-dashed border-gray-300 bg-white p-8 text-center dark:border-gray-700 dark:bg-gray-800">
                <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Aucun profil IA pour le moment</h2>
                <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">Créez votre profil IA pour commencer à recevoir des questions sur votre fiche membre.</p>
                <a href="{{ route('agent-ia.wizard') }}" class="mt-5 inline-flex items-center rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">Créer mon profil IA</a>
            </div>
        @elseif($interactions->isEmpty())
            <div class="rounded-2xl border border-gray-200 bg-white p-8 text-center dark:border-gray-700 dark:bg-gray-800">
                <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Aucun échange enregistré</h2>
                <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">Les prochaines questions posées à votre agent depuis votre profil public apparaîtront ici.</p>
            </div>
        @else
            <div class="space-y-4">
                @foreach($interactions as $interaction)
                    <article class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800" wire:key="member-ai-profile-interaction-{{ $interaction->id }}">
                        <div class="flex flex-col gap-2 border-b border-gray-100 pb-4 dark:border-gray-700 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <p class="text-sm font-semibold text-gray-900 dark:text-gray-100">
                                    {{ $interaction->visitor?->name ?? 'Internaute non connecté' }}
                                </p>
                                <p class="text-xs text-gray-500 dark:text-gray-400">
                                    {{ $interaction->created_at->format('d/m/Y H:i') }} · {{ $interaction->provider ?? 'rule_based' }}@if($interaction->model) · {{ $interaction->model }}@endif
                                </p>
                            </div>
                            <span class="inline-flex w-fit rounded-full bg-gray-100 px-2.5 py-1 text-xs font-medium text-gray-700 dark:bg-gray-700 dark:text-gray-200">
                                {{ $interaction->visitor_type === 'user' ? 'Utilisateur connecté' : 'Internaute' }}
                            </span>
                        </div>

                        <div class="mt-4 space-y-4">
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Question</p>
                                <p class="mt-1 whitespace-pre-wrap text-sm text-gray-900 dark:text-gray-100">{{ $interaction->question }}</p>
                            </div>
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Réponse de l'agent</p>
                                <p class="mt-1 whitespace-pre-wrap text-sm text-gray-700 dark:text-gray-200">{{ $interaction->response ?: 'Aucune réponse enregistrée.' }}</p>
                            </div>
                        </div>
                    </article>
                @endforeach
            </div>

            <div class="mt-6">
                {{ $interactions->links() }}
            </div>
        @endif
    </div>
</x-app-layout>
