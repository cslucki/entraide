<x-app-layout title="{{ __('ai.interactions_title') }}">
    <x-slot name="header">
        <div class="flex items-center justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold text-gray-900 dark:text-gray-100">{{ __('ai.interactions_title') }}</h1>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('ai.interactions_subtitle') }}</p>
            </div>
            <a href="{{ route('agent-ia.wizard') }}" class="inline-flex items-center rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">
                {{ __('ai.edit_profile') }}
            </a>
        </div>
    </x-slot>

    <div class="max-w-5xl mx-auto px-4 py-8 sm:px-6 lg:px-8">
        @if(!$profile)
            <div class="rounded-2xl border border-dashed border-gray-300 bg-white p-8 text-center dark:border-gray-700 dark:bg-gray-800">
                <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ __('ai.no_profile_title') }}</h2>
                <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">{{ __('ai.no_profile_body') }}</p>
                <a href="{{ route('agent-ia.wizard') }}" class="mt-5 inline-flex items-center rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">{{ __('ai.create_profile') }}</a>
            </div>
        @elseif($interactions->isEmpty())
            <div class="rounded-2xl border border-gray-200 bg-white p-8 text-center dark:border-gray-700 dark:bg-gray-800">
                <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ __('ai.no_interactions_title') }}</h2>
                <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">{{ __('ai.no_interactions_body') }}</p>
            </div>
        @else
            <div class="space-y-4">
                @foreach($interactions as $interaction)
                    <article class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800" wire:key="member-ai-profile-interaction-{{ $interaction->id }}">
                        <div class="flex flex-col gap-2 border-b border-gray-100 pb-4 dark:border-gray-700 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <p class="text-sm font-semibold text-gray-900 dark:text-gray-100">
                                    {{ $interaction->visitor?->full_name ?? __('ai.visitor_anonymous') }}
                                </p>
                                <p class="text-xs text-gray-500 dark:text-gray-400">
                                    {{-- TASK-1252 : un refus sans provider choisi (visiteur anonyme) n'affiche pas un « rule_based » fabrique. --}}
                                    {{ $interaction->created_at->format('d/m/Y H:i') }}@if($interaction->provider) · {{ $interaction->provider }}@endif@if($interaction->model) · {{ $interaction->model }}@endif
                                </p>
                            </div>
                            <div class="flex flex-wrap items-center gap-2">
                                @if($interaction->status === \App\Models\MemberAiProfileInteraction::STATUS_REFUSED)
                                    {{-- TASK-1251 / TASK-1252 : l'appel a ete refuse AVANT depart (garde economique, ou visiteur anonyme) — pas de reponse, pas de cout. --}}
                                    <span class="inline-flex w-fit rounded-full bg-amber-100 px-2.5 py-1 text-xs font-medium text-amber-800 dark:bg-amber-900/40 dark:text-amber-200" data-economic-refusal="{{ $interaction->metadata['economic_refusal']['code'] ?? '' }}">
                                        {{ __('ai.interaction_refused_badge') }}
                                    </span>
                                @endif
                                <span class="inline-flex w-fit rounded-full bg-gray-100 px-2.5 py-1 text-xs font-medium text-gray-700 dark:bg-gray-700 dark:text-gray-200">
                                    {{ $interaction->visitor_type === 'user' ? __('ai.visitor_user') : __('ai.visitor_anonymous_type') }}
                                </span>
                            </div>
                        </div>

                        <div class="mt-4 space-y-4">
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('ai.question') }}</p>
                                <p class="mt-1 whitespace-pre-wrap text-sm text-gray-900 dark:text-gray-100">{{ $interaction->question }}</p>
                            </div>
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('ai.response') }}</p>
                                @if($interaction->status === \App\Models\MemberAiProfileInteraction::STATUS_REFUSED)
                                    @if(($interaction->metadata['economic_refusal']['code'] ?? null) === \App\Support\Ai\AiRefusedException::CODE_AUTHENTICATION_REQUIRED)
                                        <p class="mt-1 whitespace-pre-wrap text-sm text-amber-800 dark:text-amber-200">{{ __('ai.interaction_refused_guest_body') }}</p>
                                    @else
                                        <p class="mt-1 whitespace-pre-wrap text-sm text-amber-800 dark:text-amber-200">{{ __('ai.interaction_refused_body', ['code' => $interaction->metadata['economic_refusal']['code'] ?? '—']) }}</p>
                                    @endif
                                @else
                                    <p class="mt-1 whitespace-pre-wrap text-sm text-gray-700 dark:text-gray-200">{{ $interaction->response ?: __('ai.no_response') }}</p>
                                @endif
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
