@php
    $organizationRouteParam = request()->route('organization') ?: currentOrganization()?->slug;
    $usesDefaultOrganizationRoute = (bool) currentOrganization()?->is_default;
    $dashboardUrl = $usesDefaultOrganizationRoute && Route::has('dashboard')
        ? route('dashboard')
        : route('organization.dashboard', ['organization' => $organizationRouteParam]);
    $createUrl = $usesDefaultOrganizationRoute && Route::has('flux.create')
        ? route('flux.create')
        : route('organization.flux.create', ['organization' => $organizationRouteParam]);
    $myPostsUrl = $usesDefaultOrganizationRoute && Route::has('flux.my')
        ? route('flux.my')
        : route('organization.flux.my', ['organization' => $organizationRouteParam]);
@endphp

<div class="mx-auto max-w-3xl px-4 py-6 sm:px-6 lg:px-8">
    <div class="mb-6 flex items-start justify-between gap-4">
        <div>
            <p class="text-xs font-semibold uppercase tracking-[0.2em] text-indigo-500">Organisation</p>
            <h1 class="mt-1 text-2xl font-bold text-gray-900 dark:text-gray-100">Flux</h1>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Annonces utiles, décisions et informations à retenir.</p>
            <a href="{{ $dashboardUrl }}" class="mt-2 inline-flex text-sm font-semibold text-indigo-600 hover:underline dark:text-indigo-400">
                Mon tableau de bord
            </a>
        </div>
        @can('create', App\Models\FeedPost::class)
            <div class="flex shrink-0 items-center gap-2">
                <a href="{{ $myPostsUrl }}"
                   class="inline-flex items-center gap-1 rounded-full border border-indigo-600 px-4 py-2 text-sm font-semibold text-indigo-600 transition hover:bg-indigo-50 active:scale-95 dark:border-indigo-400 dark:text-indigo-400 dark:hover:bg-indigo-950">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                    Mes annonces
                </a>
                <a href="{{ $createUrl }}"
                   class="inline-flex items-center gap-1.5 rounded-full bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-700 active:scale-95">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                    Nouvelle annonce
                </a>
            </div>
        @endcan
    </div>

    @if($pinned->isNotEmpty())
        <div class="mb-6 space-y-4">
            <h2 class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Épinglées</h2>
            @foreach($pinned as $post)
                @include('livewire.partials.feed-post-card', ['post' => $post, 'emojiMap' => $emojiMap])
            @endforeach
        </div>
    @endif

    <div class="space-y-4">
        @if($pinned->isEmpty() && $items->isEmpty())
            <div class="text-center py-12">
                <svg class="w-12 h-12 mx-auto text-gray-300 dark:text-gray-600 mb-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19 20H5a2 2 0 01-2-2V6a2 2 0 012-2h10a2 2 0 012 2v1m2 13a2 2 0 01-2-2V7m2 13a2 2 0 002-2V9a2 2 0 00-2-2h-2m-4-3H9M7 16h6M7 8h6v4H7V8z"/></svg>
                <p class="text-gray-500 dark:text-gray-400">Aucune annonce pour le moment.</p>
                @can('create', App\Models\FeedPost::class)
                    <a href="{{ $createUrl }}" class="mt-4 inline-flex items-center gap-1.5 text-indigo-600 dark:text-indigo-400 text-sm font-semibold hover:underline">
                        Créer la première annonce
                    </a>
                @endcan
            </div>
        @else
            @foreach($items as $post)
                @include('livewire.partials.feed-post-card', ['post' => $post, 'emojiMap' => $emojiMap])
            @endforeach

            @if($hasMore)
                <div class="text-center py-6">
                    <button wire:click="loadMore"
                            class="px-6 py-2.5 bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 text-sm font-medium rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition active:scale-95">
                        Voir plus
                    </button>
                </div>
            @endif
        @endif
    </div>
</div>
