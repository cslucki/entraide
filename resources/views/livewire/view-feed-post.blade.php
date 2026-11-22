@php
    $organizationRouteParam = request()->route('organization') ?: currentOrganization()?->slug;
    // TASK-1879 (demande Cyril, 22/11) — arrive par /org/{slug}/..., on y reste :
    // les liens sans prefixe ne servent qu'a qui navigue deja sans prefixe.
    $usesDefaultOrganizationRoute = (bool) currentOrganization()?->is_default && ! request()->route('organization');
    $fluxUrl = $usesDefaultOrganizationRoute && Route::has('flux')
        ? route('flux')
        : route('organization.flux', ['organization' => $organizationRouteParam]);
@endphp

<div class="mx-auto max-w-3xl px-4 py-6 sm:px-6 lg:px-8">
    <div class="mb-6">
        <a href="{{ $fluxUrl }}" class="text-sm font-semibold text-indigo-600 hover:underline dark:text-indigo-400">← Retour au flux</a>
    </div>

    @include('livewire.partials.feed-post-card', ['post' => $post, 'emojiMap' => $emojiMap])
</div>
