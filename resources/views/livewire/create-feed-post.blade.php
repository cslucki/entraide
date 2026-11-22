@php
    $organizationRouteParam = request()->route('organization') ?: currentOrganization()?->slug;
    // TASK-1879 (demande Cyril, 22/11) — arrive par /org/{slug}/..., on y reste :
    // les liens sans prefixe ne servent qu'a qui navigue deja sans prefixe.
    $usesDefaultOrganizationRoute = (bool) currentOrganization()?->is_default && ! request()->route('organization');
    $fluxUrl = $usesDefaultOrganizationRoute && Route::has('flux')
        ? route('flux')
        : route('organization.flux', ['organization' => $organizationRouteParam]);
    $myPostsUrl = $usesDefaultOrganizationRoute && Route::has('flux.my')
        ? route('flux.my')
        : route('organization.flux.my', ['organization' => $organizationRouteParam]);
@endphp

<div data-feed-create-root>
{{-- TASK-1879 (demande Cyril, 22/11) — pour tout le monde SAUF l'administrateur
     de l'Organization : un composeur epure, facon « Creer une publication » de
     Facebook, mais en page (pas de pop-up). Une seule decision visible (dans
     quelles Boucles ?), une grande zone d'ecriture, une photo, « Publier ».
     Titre, brouillon, programmation, message de Boucle : reserves au formulaire
     complet de l'administrateur. --}}
@if(! $peutPublierATous)
@php
    $moi = auth()->user();
    $prenom = $moi?->first_name ?: \Illuminate\Support\Str::before((string) $moi?->name, ' ');
@endphp
<div class="mx-auto max-w-xl px-4 py-6 sm:py-10" data-feed-composer>
    <form wire:submit="submit" data-live-validation
          class="overflow-hidden rounded-3xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-900">
        <header class="relative flex items-center justify-center border-b border-gray-100 px-14 py-4 dark:border-gray-800">
            <h1 class="text-lg font-bold text-gray-900 dark:text-gray-100">{{ __('feed-post.new_post') }}</h1>
            <a href="{{ $fluxUrl }}" aria-label="{{ __('feed-post.composer_close') }}"
               class="absolute right-3 top-1/2 grid h-9 w-9 -translate-y-1/2 place-items-center rounded-full bg-gray-100 text-gray-600 transition hover:bg-gray-200 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/></svg>
            </a>
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
                    :value="$content" required rows="4" :invalid="$errors->has('content')" />
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
</div>
@else
<div class="mx-auto max-w-2xl px-4 py-6 sm:px-6 lg:px-8">
    <div class="mb-6">
        <div class="flex items-center justify-between gap-4">
            <div>
                <a href="{{ $fluxUrl }}" class="text-sm font-semibold text-indigo-600 hover:underline dark:text-indigo-400">← {{ __('feed-post.back_to_feed') }}</a>
                <h1 class="mt-3 text-2xl font-bold text-gray-900 dark:text-gray-100">{{ __('feed-post.new_post') }}</h1>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('feed-post.new_subtitle') }}</p>
            </div>
            <a href="{{ $myPostsUrl }}"
               class="hidden shrink-0 items-center gap-1 rounded-full border border-indigo-600 px-4 py-2 text-sm font-semibold text-indigo-600 transition hover:bg-indigo-50 active:scale-95 sm:inline-flex dark:border-indigo-400 dark:text-indigo-400 dark:hover:bg-indigo-950">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                {{ __('feed-post.my_posts') }}
            </a>
        </div>
    </div>

    <form wire:submit="submit" data-live-validation class="space-y-5 rounded-3xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-900 sm:p-6">
        <div>
            <label for="title" class="block text-sm font-semibold text-gray-800 dark:text-gray-100">{{ __('feed-post.title_optional') }}</label>
            <input id="title" type="text" wire:model="title" maxlength="255" class="mt-2 w-full rounded-2xl border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-700 dark:bg-gray-950 dark:text-gray-100" placeholder="{{ __('feed-post.title_placeholder') }}">
            @error('title') <p class="mt-1 text-sm text-red-600 dark:text-red-400" data-erreur-serveur>{{ $message }}</p> @enderror
        </div>

        {{-- TASK-1879 (demande Cyril, 22/11) — le message s'ecrit avec TipTap, en HTML. --}}
        <div data-champ wire:key="champ-message-formulaire" class="scroll-mt-24">
            <label for="content" class="block text-sm font-semibold text-gray-800 dark:text-gray-100">{{ __('feed-post.message') }}</label>
            <div class="mt-2">
                <x-markdown-wysiwyg-editor name="content" format="html" wire-model="content" :value="$content" required rows="7" :invalid="$errors->has('content')" />
            </div>
            {{-- Emplacement de l'alerte AVANT soumission (`x-form-live-validation`), sous l'editeur. --}}
            <ul id="content-error" hidden class="mt-1 space-y-1 text-sm text-red-600 dark:text-red-400"></ul>
            @error('content') <p class="mt-1 text-sm text-red-600 dark:text-red-400" data-erreur-serveur>{{ $message }}</p> @enderror
        </div>

        <div class="space-y-3 rounded-2xl border border-gray-200 p-4 dark:border-gray-700">
            <div>
                <h2 class="text-sm font-semibold text-gray-800 dark:text-gray-100">{{ __('feed-post.publication') }}</h2>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('feed-post.publish_when') }}</p>
            </div>

            <div class="flex flex-wrap gap-2">
                <label class="flex items-center gap-2 rounded-full border px-4 py-2 text-sm cursor-pointer transition {{ $mode === 'publish' ? 'border-indigo-300 bg-indigo-50 text-indigo-700 dark:border-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-200' : 'border-gray-200 bg-white hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-900 dark:hover:bg-gray-800' }}">
                    <input type="radio" wire:model.live="mode" value="publish" class="sr-only">
                    {{ __('feed-post.publish_now_label') }}
                </label>
                <label class="flex items-center gap-2 rounded-full border px-4 py-2 text-sm cursor-pointer transition {{ $mode === 'draft' ? 'border-indigo-300 bg-indigo-50 text-indigo-700 dark:border-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-200' : 'border-gray-200 bg-white hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-900 dark:hover:bg-gray-800' }}">
                    <input type="radio" wire:model.live="mode" value="draft" class="sr-only">
                    {{ __('feed-post.draft') }}
                </label>
                <label class="flex items-center gap-2 rounded-full border px-4 py-2 text-sm cursor-pointer transition {{ $mode === 'schedule' ? 'border-indigo-300 bg-indigo-50 text-indigo-700 dark:border-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-200' : 'border-gray-200 bg-white hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-900 dark:hover:bg-gray-800' }}">
                    <input type="radio" wire:model.live="mode" value="schedule" class="sr-only">
                    {{ __('feed-post.schedule') }}
                </label>
            </div>

            @if($mode === 'schedule')
                <div>
                    <label for="scheduledAt" class="block text-sm font-semibold text-gray-800 dark:text-gray-100">{{ __('feed-post.schedule_label') }}</label>
                    <input id="scheduledAt" type="datetime-local" wire:model="scheduledAt" class="mt-2 w-full rounded-2xl border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-700 dark:bg-gray-950 dark:text-gray-100">
                    @error('scheduledAt') <p class="mt-1 text-sm text-red-600 dark:text-red-400" data-erreur-serveur>{{ $message }}</p> @enderror
                </div>
            @endif
        </div>

        <div>
            <label for="image" class="block text-sm font-semibold text-gray-800 dark:text-gray-100">{{ __('feed-post.image_optional') }}</label>
            <input id="image" type="file" wire:model="image" accept="image/*" class="mt-2 block w-full text-sm text-gray-600 file:mr-4 file:rounded-full file:border-0 file:bg-indigo-50 file:px-4 file:py-2 file:text-sm file:font-semibold file:text-indigo-700 hover:file:bg-indigo-100 dark:text-gray-300 dark:file:bg-indigo-900/40 dark:file:text-indigo-200">
            <div wire:loading wire:target="image" class="mt-2 text-sm text-gray-500 dark:text-gray-400">{{ __('feed-post.image_loading') }}</div>
            @if($image)
                <div class="mt-3 overflow-hidden rounded-2xl border border-gray-200 dark:border-gray-700">
                    <img src="{{ $image->temporaryUrl() }}" alt="{{ __('feed-post.image_preview') }}" class="max-h-72 w-full object-cover">
                    <button type="button" wire:click="removeImage" class="w-full bg-gray-50 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-100 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700">{{ __('feed-post.remove') }}</button>
                </div>
            @endif
            @error('image') <p class="mt-1 text-sm text-red-600 dark:text-red-400" data-erreur-serveur>{{ $message }}</p> @enderror
        </div>

        {{-- TASK-1879 (demande Cyril, 22/11) — epingler est reserve a l'administrateur
             de l'Organization (meme regle que `FeedPostPolicy::pin`, deja exigee
             par `submit`) : les autres membres ne voient plus la case. --}}
        @can('pin', App\Models\FeedPost::class)
            <div class="rounded-2xl bg-gray-50 p-4 dark:bg-gray-800/70" data-feed-pin-field>
                <label class="flex items-center gap-3 text-sm font-semibold text-gray-800 dark:text-gray-100">
                    <input type="checkbox" wire:model="pin" class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                    {{ __('feed-post.pin') }}
                </label>
            </div>
        @endcan

        <div>
            <label for="loopMessage" class="block text-sm font-semibold text-gray-800 dark:text-gray-100">{{ __('feed-post.loop_message') }}</label>
            <textarea id="loopMessage" wire:model="loopMessage" rows="2" class="mt-2 w-full rounded-2xl border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-700 dark:bg-gray-950 dark:text-gray-100" placeholder="{{ __('feed-post.loop_message_placeholder') }}"></textarea>
            @error('loopMessage') <p class="mt-1 text-sm text-red-600 dark:text-red-400" data-erreur-serveur>{{ $message }}</p> @enderror
        </div>

        <div data-champ class="scroll-mt-24 space-y-3 rounded-2xl border border-gray-200 p-4 dark:border-gray-700">
            <div>
                <h2 class="text-sm font-semibold text-gray-800 dark:text-gray-100">{{ __('feed-post.loop_distribution') }}</h2>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('feed-post.loop_distribution_hint') }}</p>
                @unless($peutPublierATous)
                    <p class="mt-1 text-xs font-medium text-indigo-700 dark:text-indigo-300" data-feed-members-only-hint>{{ __('feed-post.members_only_hint') }}</p>
                @endunless
            </div>

            <label class="flex items-center gap-3 text-sm text-gray-700 dark:text-gray-200">
                <input type="checkbox" wire:model.live="allLoops" class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                {{ __('feed-post.all_loops') }}
            </label>

            <div class="grid gap-2">
                @forelse($loops as $feedLoop)
                    <label class="flex items-start gap-3 rounded-2xl border border-gray-200 bg-white px-3 py-3 text-sm text-gray-800 shadow-sm transition dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 {{ $allLoops ? 'opacity-75' : 'hover:border-indigo-200 dark:hover:border-indigo-700' }}">
                        <input type="checkbox" wire:model.live="selectedLoops" value="{{ data_get($feedLoop, 'id') }}" @disabled($allLoops) class="mt-1 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500 disabled:cursor-not-allowed disabled:opacity-60">
                        <span class="min-w-0">
                            <span class="block font-semibold leading-5">{{ data_get($feedLoop, 'name') }}</span>
                            @if(data_get($feedLoop, 'description'))
                                <span class="mt-1 block line-clamp-2 text-xs text-gray-500 dark:text-gray-400">{{ data_get($feedLoop, 'description') }}</span>
                            @endif
                        </span>
                    </label>
                @empty
                    <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('feed-post.no_loops') }}</p>
                @endforelse
            </div>
            @error('selectedLoops.*') <p class="text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            @error('selectedLoops') <p class="text-sm text-red-600 dark:text-red-400" data-feed-loops-required data-erreur-serveur>{{ $message }}</p> @enderror
            @unless($peutPublierATous)
                {{-- TASK-1879 — la regle « au moins une de vos Boucles », connue du
                     navigateur : `x-form-live-validation` la lit par checkValidity(). --}}
                <input type="text" name="boucles_choisies" tabindex="-1" aria-hidden="true" class="sr-only" data-feed-loops-sentinel
                       x-data
                       x-effect="$el.setCustomValidity(($wire.allLoops || ($wire.selectedLoops || []).length > 0) ? '' : @js(__('feed-post.loops_required')))">
                <ul id="boucles_choisies-error" hidden class="space-y-1 text-sm text-red-600 dark:text-red-400"></ul>
            @endunless

            <p class="text-xs font-medium text-gray-500 dark:text-gray-400">
                {{ __('feed-post.scope_label') }}: @if($allLoops) {{ __('feed-post.scope_all') }} @elseif(count($selectedLoops) > 0) {{ trans_choice('feed-post.scope_selected', count($selectedLoops), ['count' => count($selectedLoops)]) }} @elseif($peutPublierATous) {{ __('feed-post.scope_org_only') }} @else {{ __('feed-post.scope_none') }} @endif
            </p>
        </div>

        <div class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
            <a href="{{ $fluxUrl }}" class="inline-flex justify-center rounded-full border border-gray-300 px-5 py-2.5 text-sm font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-gray-800">{{ __('feed-post.cancel') }}</a>
            <button type="submit" class="inline-flex justify-center rounded-full bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-700 disabled:opacity-60" wire:loading.attr="disabled">
                @if($mode === 'draft') {{ __('feed-post.submit_draft') }}
                @elseif($mode === 'schedule') {{ __('feed-post.submit_schedule') }}
                @else {{ __('feed-post.submit_publish') }}
                @endif
            </button>
        </div>
        <x-form-live-validation />
    </form>
</div>
@endif
</div>
