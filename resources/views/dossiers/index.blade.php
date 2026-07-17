<x-app-layout>
    @php
        $organizationRouteParam = request()->route('organization');
    @endphp

    <x-slot name="title">{{ __('dossiers.title') }} — {{ $brandOrganizationName ?? 'BouclePro' }}</x-slot>

    <x-page-container>
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <p class="text-sm font-semibold uppercase tracking-wide text-indigo-600 dark:text-indigo-400">{{ __('dossiers.kicker') }}</p>
                <h1 class="mt-1 text-3xl font-bold text-gray-900 dark:text-gray-100">{{ __('dossiers.title') }}</h1>
                <p class="mt-2 max-w-2xl text-sm text-gray-600 dark:text-gray-300">{{ __('dossiers.subtitle') }}</p>
            </div>
            <a href="{{ route('organization.dossiers.create', ['organization' => $organizationRouteParam]) }}" class="inline-flex items-center justify-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-700">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" /></svg>
                {{ __('dossiers.create') }}
            </a>
        </div>

        @if(session('success'))
            <div class="mt-6 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800 dark:border-emerald-900/60 dark:bg-emerald-950/40 dark:text-emerald-200">
                {{ session('success') }}
            </div>
        @endif

        @if($dossiers->isEmpty() && $sharedDossiers->isEmpty())
            <div class="mt-8 rounded-3xl border border-dashed border-indigo-200 bg-white p-8 text-center shadow-sm dark:border-indigo-900/60 dark:bg-gray-800 sm:p-12">
                <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-indigo-50 text-indigo-600 dark:bg-indigo-950/50 dark:text-indigo-300">
                    <svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M3 7a2 2 0 012-2h5l2 2h7a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2V7z" /></svg>
                </div>
                <h2 class="mt-5 text-lg font-semibold text-gray-900 dark:text-gray-100">{{ __('dossiers.empty_title') }}</h2>
                <p class="mx-auto mt-2 max-w-md text-sm text-gray-600 dark:text-gray-300">{{ __('dossiers.empty_body') }}</p>
                <a href="{{ route('organization.dossiers.create', ['organization' => $organizationRouteParam]) }}" class="mt-6 inline-flex items-center justify-center rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-indigo-700">
                    {{ __('dossiers.create_first') }}
                </a>
            </div>
        @else
            {{-- Owned dossiers --}}
            @if($dossiers->isNotEmpty())
                <div class="mt-8 grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                    @foreach($dossiers as $dossier)
                        <article class="flex min-h-44 flex-col rounded-2xl border border-gray-200 bg-white p-5 shadow-sm transition hover:-translate-y-0.5 hover:shadow-md dark:border-gray-700 dark:bg-gray-800">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="text-xs font-semibold uppercase tracking-wide text-indigo-600 dark:text-indigo-400">{{ __('dossiers.private_label') }}</p>
                                    <h2 class="mt-1 line-clamp-2 text-lg font-semibold text-gray-900 dark:text-gray-100">
                                        <a href="{{ route('organization.dossiers.show', ['organization' => $organizationRouteParam, 'dossier' => $dossier->getKey()]) }}" class="hover:text-indigo-600 dark:hover:text-indigo-300">{{ $dossier->name }}</a>
                                    </h2>
                                </div>
                                @if($dossier->dossier_members_count > 0)
                                    <span class="rounded-full bg-amber-100 px-2.5 py-1 text-xs font-medium text-amber-700 dark:bg-amber-950/50 dark:text-amber-300">{{ __('dossiers.shared_badge') }}</span>
                                @else
                                    <span class="rounded-full bg-gray-100 px-2.5 py-1 text-xs font-medium text-gray-600 dark:bg-gray-700 dark:text-gray-300">{{ __('dossiers.private_badge') }}</span>
                                @endif
                            </div>
                            <p class="mt-4 text-xs text-gray-500 dark:text-gray-400">{{ __('dossiers.updated_at', ['date' => $dossier->updated_at->diffForHumans()]) }}</p>
                            <div class="mt-auto flex flex-col gap-2 pt-5 sm:flex-row">
                                <a href="{{ route('organization.dossiers.show', ['organization' => $organizationRouteParam, 'dossier' => $dossier->getKey()]) }}" class="inline-flex flex-1 items-center justify-center rounded-lg bg-indigo-600 px-3 py-2 text-sm font-semibold text-white transition hover:bg-indigo-700">
                                    {{ __('dossiers.open') }}
                                </a>
                                <a href="{{ route('organization.dossiers.edit', ['organization' => $organizationRouteParam, 'dossier' => $dossier->getKey()]) }}" class="inline-flex flex-1 items-center justify-center rounded-lg border border-gray-300 px-3 py-2 text-sm font-semibold text-gray-700 transition hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">
                                    {{ __('dossiers.rename') }}
                                </a>
                                <form method="POST" action="{{ route('organization.dossiers.destroy', ['organization' => $organizationRouteParam, 'dossier' => $dossier->getKey()]) }}" class="flex-1" onsubmit="return confirm('{{ __('dossiers.confirm_delete', ['name' => $dossier->name]) }}')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="inline-flex w-full items-center justify-center rounded-lg border border-red-200 px-3 py-2 text-sm font-semibold text-red-600 transition hover:bg-red-50 dark:border-red-900/60 dark:text-red-300 dark:hover:bg-red-950/30">
                                        {{ __('dossiers.delete') }}
                                    </button>
                                </form>
                            </div>
                        </article>
                    @endforeach
                </div>
                <div class="mt-6">{{ $dossiers->links() }}</div>
            @endif

            {{-- Shared dossiers --}}
            @if($sharedDossiers->isNotEmpty())
                <div class="mt-10">
                    <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ __('dossiers.shared_with_me') }}</h2>
                    <div class="mt-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                        @foreach($sharedDossiers as $dossier)
                            @php
                                $member = $dossier->dossierMembers->first();
                                $role = $member?->role ?? 'reader';
                            @endphp
                            <article class="flex min-h-44 flex-col rounded-2xl border border-amber-200 bg-white p-5 shadow-sm transition hover:-translate-y-0.5 hover:shadow-md dark:border-amber-900/60 dark:bg-gray-800">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <p class="text-xs font-semibold uppercase tracking-wide text-amber-600 dark:text-amber-400">{{ __('dossiers.shared_label') }}</p>
                                        <h2 class="mt-1 line-clamp-2 text-lg font-semibold text-gray-900 dark:text-gray-100">
                                            <a href="{{ route('organization.dossiers.show', ['organization' => $organizationRouteParam, 'dossier' => $dossier->getKey()]) }}" class="hover:text-amber-600 dark:hover:text-amber-300">{{ $dossier->name }}</a>
                                        </h2>
                                    </div>
                                    <span class="rounded-full bg-amber-100 px-2.5 py-1 text-xs font-medium text-amber-700 dark:bg-amber-950/50 dark:text-amber-300">
                                        {{ __('dossiers.role_'.$role) }}
                                    </span>
                                </div>
                                <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">{{ __('dossiers.owned_by', ['name' => $dossier->owner->name]) }}</p>
                                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('dossiers.updated_at', ['date' => $dossier->updated_at->diffForHumans()]) }}</p>
                                <div class="mt-auto pt-5">
                                    <a href="{{ route('organization.dossiers.show', ['organization' => $organizationRouteParam, 'dossier' => $dossier->getKey()]) }}" class="inline-flex w-full items-center justify-center rounded-lg bg-amber-600 px-3 py-2 text-sm font-semibold text-white transition hover:bg-amber-700">
                                        {{ __('dossiers.open') }}
                                    </a>
                                </div>
                            </article>
                        @endforeach
                    </div>
                </div>
            @endif
        @endif
    </x-page-container>
</x-app-layout>
