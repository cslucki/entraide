@php
    $organizationRouteParam = request()->route('organization') ?: currentOrganization()?->slug;
    $usesDefaultOrganizationRoute = (bool) currentOrganization()?->is_default;
    $fluxUrl = $usesDefaultOrganizationRoute && Route::has('flux')
        ? route('flux')
        : route('organization.flux', ['organization' => $organizationRouteParam]);
    $myPostsUrl = $usesDefaultOrganizationRoute && Route::has('flux.my')
        ? route('flux.my')
        : route('organization.flux.my', ['organization' => $organizationRouteParam]);
@endphp

<div class="mx-auto max-w-2xl px-4 py-6 sm:px-6 lg:px-8">
    <div class="mb-6">
        <div class="flex items-center justify-between gap-4">
            <div>
                <a href="{{ $fluxUrl }}" class="text-sm font-semibold text-indigo-600 hover:underline dark:text-indigo-400">← Retour au flux</a>
                <h1 class="mt-3 text-2xl font-bold text-gray-900 dark:text-gray-100">Nouvelle annonce</h1>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Publiez une information claire pour les membres de l’organisation.</p>
            </div>
            <a href="{{ $myPostsUrl }}"
               class="hidden shrink-0 items-center gap-1 rounded-full border border-indigo-600 px-4 py-2 text-sm font-semibold text-indigo-600 transition hover:bg-indigo-50 active:scale-95 sm:inline-flex dark:border-indigo-400 dark:text-indigo-400 dark:hover:bg-indigo-950">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                Mes annonces
            </a>
        </div>
    </div>

    <form wire:submit="submit" class="space-y-5 rounded-3xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-900 sm:p-6">
        <div>
            <label for="title" class="block text-sm font-semibold text-gray-800 dark:text-gray-100">Titre optionnel</label>
            <input id="title" type="text" wire:model="title" maxlength="255" class="mt-2 w-full rounded-2xl border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-700 dark:bg-gray-950 dark:text-gray-100" placeholder="Ex: Permanence jeudi matin">
            @error('title') <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="content" class="block text-sm font-semibold text-gray-800 dark:text-gray-100">Message</label>

            <div x-data="{ insertMarkdown(type) { const ta = document.getElementById('content'); if (!ta) return; const start = ta.selectionStart; const end = ta.selectionEnd; const val = ta.value; const sel = val.substring(start, end); let before = '', after = ''; if (type === 'bold') { before = '**'; after = '**'; } else if (type === 'link') { before = '['; after = '](url)'; } else if (type === 'h2') { before = '## '; } else if (type === 'h3') { before = '### '; } else if (type === 'list') { before = '\n- '; } let newVal; if (sel && type !== 'h2' && type !== 'h3' && type !== 'list') { newVal = val.substring(0, start) + before + sel + after + val.substring(end); } else { newVal = val.substring(0, start) + before + after + val.substring(end); } ta.value = newVal; ta.dispatchEvent(new Event('input', { bubbles: true })); ta.focus(); const pos = start + before.length; ta.setSelectionRange(pos, pos); } }" class="mt-2 flex flex-wrap gap-1">
                <button type="button" @click="insertMarkdown('bold')" class="rounded-lg px-2 py-1 text-xs font-bold text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800" title="Gras (sélectionnez du texte)">Gras</button>
                <button type="button" @click="insertMarkdown('link')" class="rounded-lg px-2 py-1 text-xs font-semibold text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800" title="Insérer un lien">Lien</button>
                <button type="button" @click="insertMarkdown('h2')" class="rounded-lg px-2 py-1 text-xs font-semibold text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800" title="Titre niveau 2">H2</button>
                <button type="button" @click="insertMarkdown('h3')" class="rounded-lg px-2 py-1 text-xs font-semibold text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800" title="Titre niveau 3">H3</button>
                <button type="button" @click="insertMarkdown('list')" class="rounded-lg px-2 py-1 text-xs font-semibold text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800" title="Liste à puces">Liste</button>
            </div>

            <textarea id="content" wire:model="content" rows="7" class="mt-1 w-full rounded-2xl border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-700 dark:bg-gray-950 dark:text-gray-100" placeholder="Texte de l’annonce. Collez une URL pour générer un aperçu."></textarea>
            @error('content') <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
        </div>

        <div class="space-y-3 rounded-2xl border border-gray-200 p-4 dark:border-gray-700">
            <div>
                <h2 class="text-sm font-semibold text-gray-800 dark:text-gray-100">Publication</h2>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Choisissez quand publier cette annonce.</p>
            </div>

            <div class="flex flex-wrap gap-2">
                <label class="flex items-center gap-2 rounded-full border px-4 py-2 text-sm cursor-pointer transition {{ $mode === 'publish' ? 'border-indigo-300 bg-indigo-50 text-indigo-700 dark:border-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-200' : 'border-gray-200 bg-white hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-900 dark:hover:bg-gray-800' }}">
                    <input type="radio" wire:model.live="mode" value="publish" class="sr-only">
                    Publier maintenant
                </label>
                <label class="flex items-center gap-2 rounded-full border px-4 py-2 text-sm cursor-pointer transition {{ $mode === 'draft' ? 'border-indigo-300 bg-indigo-50 text-indigo-700 dark:border-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-200' : 'border-gray-200 bg-white hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-900 dark:hover:bg-gray-800' }}">
                    <input type="radio" wire:model.live="mode" value="draft" class="sr-only">
                    Brouillon
                </label>
                <label class="flex items-center gap-2 rounded-full border px-4 py-2 text-sm cursor-pointer transition {{ $mode === 'schedule' ? 'border-indigo-300 bg-indigo-50 text-indigo-700 dark:border-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-200' : 'border-gray-200 bg-white hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-900 dark:hover:bg-gray-800' }}">
                    <input type="radio" wire:model.live="mode" value="schedule" class="sr-only">
                    Planifier
                </label>
            </div>

            @if($mode === 'schedule')
                <div>
                    <label for="scheduledAt" class="block text-sm font-semibold text-gray-800 dark:text-gray-100">Date et heure de publication</label>
                    <input id="scheduledAt" type="datetime-local" wire:model="scheduledAt" class="mt-2 w-full rounded-2xl border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-700 dark:bg-gray-950 dark:text-gray-100">
                    @error('scheduledAt') <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
            @endif
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

        <div>
            <label for="loopMessage" class="block text-sm font-semibold text-gray-800 dark:text-gray-100">Message pour la boucle (optionnel)</label>
            <textarea id="loopMessage" wire:model="loopMessage" rows="2" class="mt-2 w-full rounded-2xl border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-700 dark:bg-gray-950 dark:text-gray-100" placeholder="Un message distinct pour la diffusion dans les boucles…"></textarea>
            @error('loopMessage') <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
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
                @forelse($loops as $feedLoop)
                    <label class="flex items-start gap-3 rounded-2xl border border-gray-200 bg-white px-3 py-3 text-sm text-gray-800 shadow-sm transition dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 {{ $allLoops ? 'opacity-75' : 'hover:border-indigo-200 dark:hover:border-indigo-700' }}">
                        <input type="checkbox" wire:model="selectedLoops" value="{{ data_get($feedLoop, 'id') }}" @disabled($allLoops) class="mt-1 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500 disabled:cursor-not-allowed disabled:opacity-60">
                        <span class="min-w-0">
                            <span class="block font-semibold leading-5">{{ data_get($feedLoop, 'name') }}</span>
                            @if(data_get($feedLoop, 'slug'))
                                <span class="block text-xs text-gray-500 dark:text-gray-400">#{{ data_get($feedLoop, 'slug') }}</span>
                            @endif
                            @if(data_get($feedLoop, 'description'))
                                <span class="mt-1 block line-clamp-2 text-xs text-gray-500 dark:text-gray-400">{{ data_get($feedLoop, 'description') }}</span>
                            @endif
                        </span>
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
            <a href="{{ $fluxUrl }}" class="inline-flex justify-center rounded-full border border-gray-300 px-5 py-2.5 text-sm font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-gray-800">Annuler</a>
            <button type="submit" class="inline-flex justify-center rounded-full bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-700 disabled:opacity-60" wire:loading.attr="disabled">
                @if($mode === 'draft') Enregistrer le brouillon
                @elseif($mode === 'schedule') Planifier
                @else Publier l'annonce
                @endif
            </button>
        </div>
    </form>
</div>
