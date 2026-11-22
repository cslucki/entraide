{{-- TASK-1879 (demandes Cyril, 22/11) — le composeur d'annonce, facon « Creer une
     publication » de Facebook. Inclus par `create-feed-post` : en page seule
     (`/flux/creer`) ou sur le Flux (`$integre`), ou la croix ferme la feuille
     ouverte sur smartphone. Variables : celles du composant + `$moi`, `$prenom`,
     `$fluxUrl`. --}}
<form wire:submit="submit" data-live-validation
      class="overflow-hidden {{ $integre ? '' : 'rounded-3xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-900' }}">
    <header class="relative flex items-center justify-center border-b border-gray-100 px-14 py-4 dark:border-gray-800">
        <h1 class="text-lg font-bold text-gray-900 dark:text-gray-100">{{ __('feed-post.new_post') }}</h1>
        @if($integre)
            {{-- Sur le Flux : la croix ferme la fenetre. --}}
            <button type="button" x-on:click="ouvert = false" aria-label="{{ __('feed-post.composer_close') }}"
                    class="absolute right-3 top-1/2 grid h-9 w-9 -translate-y-1/2 place-items-center rounded-full bg-gray-100 text-gray-600 transition hover:bg-gray-200 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/></svg>
            </button>
        @else
        <a href="{{ $fluxUrl }}" aria-label="{{ __('feed-post.composer_close') }}"
           class="absolute right-3 top-1/2 grid h-9 w-9 -translate-y-1/2 place-items-center rounded-full bg-gray-100 text-gray-600 transition hover:bg-gray-200 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700">
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/></svg>
        </a>
        @endif
    </header>

    <div class="space-y-4 p-4 sm:p-5">
        {{-- Qui parle, et a qui : l'auteur puis ses Boucles, en pastilles a cocher. --}}
        <div class="flex items-start gap-3">
            <x-user-avatar :user="$moi" size="md" variant="solid" class="shrink-0" />
            <div class="min-w-0 flex-1">
                <p class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ $moi?->publicDisplayName() }}</p>

                <div data-champ class="scroll-mt-24">
                    <div class="mt-1.5 flex flex-wrap gap-1.5" role="group" aria-label="{{ __('feed-post.composer_audience') }}" aria-describedby="composeur-portee" data-feed-composer-audience>
                        @if(count($loops) > 1)
                            <label class="inline-flex cursor-pointer items-center gap-1.5 rounded-lg px-2.5 py-1 text-xs font-semibold transition has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-indigo-500 {{ $allLoops ? 'bg-indigo-600 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700' }}">
                                <input type="checkbox" wire:model.live="allLoops" class="sr-only">
                                {{ __('feed-post.all_loops') }}
                            </label>
                        @endif
                        @forelse($loops as $feedLoop)
                            @php($choisie = $allLoops || in_array(data_get($feedLoop, 'id'), $selectedLoops, true))
                            <label class="inline-flex cursor-pointer items-center gap-1.5 rounded-lg px-2.5 py-1 text-xs font-semibold transition has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-indigo-500 {{ $choisie ? 'bg-indigo-600 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700' }} {{ $allLoops ? 'opacity-80' : '' }}">
                                <input type="checkbox" wire:model.live="selectedLoops" value="{{ data_get($feedLoop, 'id') }}" @disabled($allLoops) class="sr-only">
                                <svg class="h-3.5 w-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    @if($choisie)<path d="M5 12.5 10 17 19 7.5"/>@else<circle cx="12" cy="12" r="8"/>@endif
                                </svg>
                                {{ data_get($feedLoop, 'name') }}
                            </label>
                        @empty
                            <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('feed-post.no_loops') }}</p>
                        @endforelse
                    </div>
                    <p id="composeur-portee" class="sr-only" data-feed-members-only-hint>{{ __('feed-post.members_only_hint') }}</p>
                    <input type="text" name="boucles_choisies" tabindex="-1" aria-hidden="true" class="sr-only" data-feed-loops-sentinel
                           x-data
                           x-effect="$el.setCustomValidity(($wire.allLoops || ($wire.selectedLoops || []).length > 0) ? '' : @js(__('feed-post.loops_required')))">
                    <ul id="boucles_choisies-error" hidden class="mt-1 space-y-1 text-xs text-red-600 dark:text-red-400"></ul>
                    @error('selectedLoops.*') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    @error('selectedLoops') <p class="mt-1 text-xs text-red-600 dark:text-red-400" data-feed-loops-required data-erreur-serveur>{{ $message }}</p> @enderror
                </div>
            </div>
        </div>

        <div data-champ wire:key="champ-message-composeur" class="scroll-mt-24">
            <x-markdown-wysiwyg-editor name="content" format="html" wire-model="content" composer
                :placeholder="__('feed-post.composer_placeholder', ['name' => $prenom])"
                :value="$content" required rows="4" :invalid="$errors->has('content')" :mentions="$mentions" />
            <ul id="content-error" hidden class="mt-1 space-y-1 text-sm text-red-600 dark:text-red-400"></ul>
            @error('content') <p class="mt-1 text-sm text-red-600 dark:text-red-400" data-erreur-serveur>{{ $message }}</p> @enderror
        </div>

        @if($image)
            <div class="relative overflow-hidden rounded-2xl border border-gray-200 dark:border-gray-700">
                <img src="{{ $image->temporaryUrl() }}" alt="{{ __('feed-post.image_preview') }}" class="max-h-80 w-full object-cover">
                <button type="button" wire:click="removeImage" aria-label="{{ __('feed-post.composer_remove_photo') }}"
                        class="absolute right-2 top-2 grid h-8 w-8 place-items-center rounded-full bg-white/90 text-gray-700 shadow hover:bg-white dark:bg-gray-900/90 dark:text-gray-200">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/></svg>
                </button>
            </div>
        @endif
        <div wire:loading wire:target="image" class="text-sm text-gray-500 dark:text-gray-400">{{ __('feed-post.image_loading') }}</div>
        @error('image') <p class="text-sm text-red-600 dark:text-red-400" data-erreur-serveur>{{ $message }}</p> @enderror

        {{-- La rangee des ajouts : la place des fonctionnalites a venir. --}}
        <div class="flex items-center justify-between gap-3 rounded-2xl border border-gray-200 px-4 py-2 dark:border-gray-700" data-feed-composer-add>
            <span class="text-sm font-semibold text-gray-700 dark:text-gray-200">{{ __('feed-post.composer_add') }}</span>
            <div class="flex items-center gap-1">
                <label for="image" title="{{ __('feed-post.composer_photo') }}"
                       class="grid h-9 w-9 cursor-pointer place-items-center rounded-full text-emerald-600 transition hover:bg-emerald-50 has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-indigo-500 dark:text-emerald-400 dark:hover:bg-emerald-900/30">
                    <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="16" rx="3"/><circle cx="9" cy="10" r="1.6"/><path d="m21 16-5-5-9 9"/></svg>
                    <span class="sr-only">{{ __('feed-post.composer_photo') }}</span>
                    <input id="image" type="file" wire:model="image" accept="image/*" class="sr-only">
                </label>
            </div>
        </div>

        <button type="submit" wire:loading.attr="disabled"
                class="w-full rounded-xl bg-indigo-600 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2 disabled:opacity-60 dark:focus-visible:ring-offset-gray-900">
            {{ __('feed-post.composer_publish') }}
        </button>
    </div>
    <x-form-live-validation />
</form>
