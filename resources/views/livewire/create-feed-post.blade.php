@php($organizationRouteParam = request()->route('organization') ?: currentOrganization()?->slug)

<div class="mx-auto max-w-2xl px-4 py-6 sm:px-6 lg:px-8">
    <div class="mb-6">
        <a href="{{ route('organization.flux', ['organization' => $organizationRouteParam]) }}" class="text-sm font-semibold text-indigo-600 hover:underline dark:text-indigo-400">← Retour au flux</a>
        <h1 class="mt-3 text-2xl font-bold text-gray-900 dark:text-gray-100">Nouvelle annonce</h1>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Publiez une information claire pour les membres de l’organisation.</p>
    </div>

    <form wire:submit="submit" class="space-y-5 rounded-3xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-900 sm:p-6">
        <div>
            <label for="title" class="block text-sm font-semibold text-gray-800 dark:text-gray-100">Titre optionnel</label>
            <input id="title" type="text" wire:model="title" maxlength="255" class="mt-2 w-full rounded-2xl border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-700 dark:bg-gray-950 dark:text-gray-100" placeholder="Ex: Permanence jeudi matin">
            @error('title') <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="content" class="block text-sm font-semibold text-gray-800 dark:text-gray-100">Message</label>
            <textarea id="content" wire:model="content" rows="7" class="mt-2 w-full rounded-2xl border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-700 dark:bg-gray-950 dark:text-gray-100" placeholder="Texte de l’annonce. Collez une URL pour générer un aperçu."></textarea>
            @error('content') <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="image" class="block text-sm font-semibold text-gray-800 dark:text-gray-100">Image optionnelle</label>
            <input id="image" type="file" wire:model="image" accept="image/*" class="mt-2 block w-full text-sm text-gray-600 file:mr-4 file:rounded-full file:border-0 file:bg-indigo-50 file:px-4 file:py-2 file:text-sm file:font-semibold file:text-indigo-700 hover:file:bg-indigo-100 dark:text-gray-300 dark:file:bg-indigo-900/40 dark:file:text-indigo-200">
            <div wire:loading wire:target="image" class="mt-2 text-sm text-gray-500 dark:text-gray-400">Chargement de l’image…</div>
            @if($image)
                <div class="mt-3 overflow-hidden rounded-2xl border border-gray-200 dark:border-gray-700">
                    <img src="{{ $image->temporaryUrl() }}" alt="Aperçu" class="max-h-72 w-full object-cover">
                    <button type="button" wire:click="removeImage" class="w-full bg-gray-50 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-100 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700">Retirer l’image</button>
                </div>
            @endif
            @error('image') <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
        </div>

        <div class="rounded-2xl bg-gray-50 p-4 dark:bg-gray-800/70">
            <label class="flex items-center gap-3 text-sm font-semibold text-gray-800 dark:text-gray-100">
                <input type="checkbox" wire:model="pin" class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                Épingler cette annonce
            </label>
        </div>

        <div class="space-y-3 rounded-2xl border border-gray-200 p-4 dark:border-gray-700">
            <div>
                <h2 class="text-sm font-semibold text-gray-800 dark:text-gray-100">Diffusion vers les boucles</h2>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Choisissez une portée. Seules les boucles de cette organisation sont disponibles.</p>
            </div>

            <label class="flex items-center gap-3 text-sm text-gray-700 dark:text-gray-200">
                <input type="checkbox" wire:model.live="allLoops" class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                Toutes les boucles de l’organisation
            </label>

            <div class="grid gap-2">
                @forelse($loops as $loop)
                    <label class="flex items-center gap-3 rounded-2xl bg-gray-50 px-3 py-2 text-sm text-gray-700 dark:bg-gray-800 dark:text-gray-200">
                        <input type="checkbox" wire:model="selectedLoops" value="{{ data_get($loop, 'id') }}" @disabled($allLoops) class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                        <span>{{ data_get($loop, 'name') }}</span>
                    </label>
                @empty
                    <p class="text-sm text-gray-500 dark:text-gray-400">Aucune boucle disponible.</p>
                @endforelse
            </div>
            @error('selectedLoops.*') <p class="text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror

            <p class="text-xs font-medium text-gray-500 dark:text-gray-400">
                Portée: @if($allLoops) toutes les boucles @elseif(count($selectedLoops) > 0) {{ count($selectedLoops) }} boucle{{ count($selectedLoops) > 1 ? 's' : '' }} sélectionnée{{ count($selectedLoops) > 1 ? 's' : '' }} @else flux organisation uniquement @endif
            </p>
        </div>

        <div class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
            <a href="{{ route('organization.flux', ['organization' => $organizationRouteParam]) }}" class="inline-flex justify-center rounded-full border border-gray-300 px-5 py-2.5 text-sm font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-gray-800">Annuler</a>
            <button type="submit" class="inline-flex justify-center rounded-full bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-700 disabled:opacity-60" wire:loading.attr="disabled">
                Publier l’annonce
            </button>
        </div>
    </form>
</div>
