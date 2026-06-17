@php
    $organizationRouteParam = request()->route('organization') ?: currentOrganization()?->slug;
    $usesDefaultOrganizationRoute = (bool) currentOrganization()?->is_default;
    $feedUrl = $usesDefaultOrganizationRoute && Route::has('flux')
        ? route('flux')
        : route('organization.flux', ['organization' => $organizationRouteParam]);
    $createUrl = $usesDefaultOrganizationRoute && Route::has('flux.create')
        ? route('flux.create')
        : route('organization.flux.create', ['organization' => $organizationRouteParam]);
    $editRouteName = $usesDefaultOrganizationRoute ? 'flux.edit' : 'organization.flux.edit';
    $editRouteParams = fn ($postId) => $usesDefaultOrganizationRoute
        ? ['feedPost' => $postId]
        : ['organization' => $organizationRouteParam, 'feedPost' => $postId];
@endphp

<div>
    <x-slot:title>Mes annonces</x-slot:title>

    <div class="max-w-4xl mx-auto px-4 py-8">
        <div class="flex items-center justify-between mb-6">
            <div>
                <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">Mes annonces</h1>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Retrouvez toutes les annonces que vous avez publiées.</p>
            </div>
            @if($canCreate)
            <a href="{{ $createUrl }}" class="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium text-white bg-indigo-600 rounded-lg hover:bg-indigo-700 transition">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                Nouvelle annonce
            </a>
            @endif
        </div>

        @if($posts->count() === 0)
        <div class="text-center py-16">
            <svg class="w-16 h-16 mx-auto text-gray-300 dark:text-gray-600 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19 20H5a2 2 0 01-2-2V6a2 2 0 012-2h10a2 2 0 012 2v1m2 13a2 2 0 01-2-2V7m2 13a2 2 0 002-2V9a2 2 0 00-2-2h-2m-4-3H9M7 16h6M7 8h6v4H7V8z"/></svg>
            <p class="text-gray-500 dark:text-gray-400">Vous n'avez pas encore publié d'annonce.</p>
            @if($canCreate)
            <a href="{{ $createUrl }}" class="mt-4 inline-flex items-center gap-2 text-sm text-indigo-600 hover:underline">
                Créer ma première annonce
            </a>
            @endif
        </div>
        @endif

        <div class="space-y-4">
            @foreach($posts as $post)
            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-5">
                    <div class="flex items-start justify-between gap-4">
                        <div class="min-w-0 flex-1">
                            <h3 class="font-medium text-gray-900 dark:text-gray-100">{{ $post->title ?: 'Sans titre' }}</h3>
                            <p class="text-sm text-gray-500 mt-1">{{ Str::limit(strip_tags($post->content), 200) }}</p>
                            <p class="text-xs text-gray-400 mt-2">Créée le {{ $post->created_at->setTimezone('Europe/Paris')->isoFormat('D MMM YYYY à HH:mm') }} (heure de Paris)</p>
                            @if($post->scheduled_at)
                                <p class="text-xs text-gray-400">Planifiée le {{ $post->scheduled_at->setTimezone('Europe/Paris')->isoFormat('D MMM YYYY à HH:mm') }} (heure de Paris)</p>
                            @endif
                            @if($post->published_at)
                                <p class="text-xs text-gray-400">Publiée le {{ $post->published_at->setTimezone('Europe/Paris')->isoFormat('D MMM YYYY à HH:mm') }} (heure de Paris)</p>
                            @endif
                        </div>
                        <div class="flex items-center gap-3 flex-shrink-0">
                            @php
                                $badgeClasses = match ($post->status) {
                                    'published' => 'bg-green-100 dark:bg-green-900 text-green-700 dark:text-green-300',
                                    'scheduled' => 'bg-yellow-100 dark:bg-yellow-900 text-yellow-700 dark:text-yellow-300',
                                    default => 'bg-gray-100 dark:bg-gray-700 text-gray-500 dark:text-gray-400',
                                };
                                $badgeLabel = match ($post->status) {
                                    'published' => 'Publiée',
                                    'scheduled' => 'Planifiée',
                                    default => 'Brouillon',
                                };
                            @endphp
                            <span class="text-xs px-2 py-0.5 rounded-full {{ $badgeClasses }}">
                                {{ $badgeLabel }}
                            </span>
                            <div class="flex items-center gap-1">
                                @if($post->status === 'scheduled')
                                    <button type="button" wire:click="publishNow('{{ $post->id }}')"
                                            class="text-xs text-green-600 hover:underline">Publier maintenant</button>
                                @endif
                                <a href="{{ route($editRouteName, $editRouteParams($post->id)) }}" class="text-xs text-indigo-600 hover:underline">Modifier</a>
                                <button type="button"
                                        x-on:click="if (confirm('Supprimer cette annonce ?')) { $wire.delete('{{ $post->id }}') }"
                                        class="text-xs text-red-600 hover:underline">Supprimer</button>
                            </div>
                            <a href="{{ $feedUrl }}" class="text-xs text-indigo-600 hover:underline">Voir</a>
                        </div>
                    </div>
            </div>
            @endforeach
        </div>

        <div class="mt-6">
            {{ $posts->links() }}
        </div>
    </div>
</div>
