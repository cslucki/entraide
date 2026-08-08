{{--
    Demande-Offre : ce que la Boucle met en avant du catalogue.

    **Un lien, jamais une copie.** Le titre, la description et le statut sont lus
    sur l'Offre ou la Demande à chaque affichage : la corriger la corrige partout,
    et une Offre retirée du catalogue se voit ici comme retirée.

    La Card ne porte **aucun formulaire de création** : le parcours existe, avec
    ses règles de catégorie, de mode de livraison et de coût en points. Les deux
    boutons renvoient vers lui.

    Chaque geste du composant a un chemin ici.
--}}
<div class="flex h-full flex-col" data-loop-marketplace>

@if (! $canView)
    <p class="px-4 py-10 text-center text-sm text-gray-500 dark:text-gray-400">
        {{ __('loops.cards.marketplace.no_access') }}
    </p>
@else

    @if ($problem)
        <p class="mb-3 rounded-lg bg-rose-50 px-3 py-2 text-sm text-rose-800 dark:bg-rose-500/15 dark:text-rose-200" role="alert">{{ $problem }}</p>
    @elseif ($flash)
        <p class="mb-3 rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-800 dark:bg-emerald-500/15 dark:text-emerald-200"
           role="status" aria-live="polite">{{ $flash }}</p>
    @endif

    @if ($links->isEmpty())
        <div class="rounded-2xl border border-dashed border-gray-300 px-5 py-10 text-center dark:border-gray-600">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ __('loops.cards.marketplace.empty_title') }}</h3>
            <p class="mx-auto mt-2 max-w-sm text-sm text-gray-500 dark:text-gray-400">{{ __('loops.cards.marketplace.empty_body') }}</p>
        </div>
    @else
        <ul class="space-y-3">
            @foreach ($links as $lien)
                <li class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
                    <header class="flex flex-wrap items-baseline justify-between gap-2">
                        <div class="flex flex-wrap items-center gap-1.5">
                            @if ($lien->kind() === \App\Models\LoopMarketplaceLink::KIND_OFFER)
                                <span class="rounded-full bg-emerald-100 px-2 py-0.5 text-[10px] font-semibold text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-300">
                                    {{ __('loops.cards.marketplace.offer_badge') }}
                                </span>
                            @else
                                <span class="rounded-full bg-indigo-100 px-2 py-0.5 text-[10px] font-semibold text-indigo-700 dark:bg-indigo-500/20 dark:text-indigo-300">
                                    {{ __('loops.cards.marketplace.request_badge') }}
                                </span>
                            @endif

                            {{-- Une Offre retirée du catalogue se voit comme retirée :
                                 sans cela, quelqu'un l'aurait sollicitée pour rien. --}}
                            @unless ($lien->isLive())
                                <span class="rounded-full bg-gray-200 px-2 py-0.5 text-[10px] font-semibold text-gray-600 dark:bg-gray-700 dark:text-gray-300">
                                    {{ __('loops.cards.marketplace.closed_badge') }}
                                </span>
                            @endunless
                        </div>

                        @if ($canHighlight && ($canManage || $lien->added_by === auth()->id()))
                            <div class="flex shrink-0 items-center gap-1.5">
                                <button type="button" wire:click="startEditingNote('{{ $lien->id }}')"
                                        class="rounded-lg p-1.5 text-gray-400 hover:bg-gray-100 hover:text-gray-700 dark:hover:bg-gray-700"
                                        {{-- Le titre est **dans** le libellé : sans lui, cinq
                                             boutons identiques se suivaient, indistinguables
                                             au lecteur d'écran. Observé en recette. --}}
                                        aria-label="{{ __('loops.cards.marketplace.edit_note_of', ['name' => Str::limit($lien->displayTitle(), 40)]) }}">
                                    <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z"/></svg>
                                </button>
                                <button type="button" wire:click="remove('{{ $lien->id }}')"
                                        wire:confirm="{{ __('loops.cards.marketplace.delete_confirm') }}"
                                        aria-label="{{ __('loops.cards.marketplace.remove', ['name' => Str::limit($lien->displayTitle(), 40)]) }}"
                                        class="rounded-lg p-1.5 text-gray-400 hover:bg-rose-50 hover:text-rose-600 dark:hover:bg-rose-500/15">
                                    <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                                </button>
                            </div>
                        @endif
                    </header>

                    <p class="mt-2 text-sm font-semibold text-gray-900 dark:text-gray-100">{{ $lien->displayTitle() }}</p>
                    <p class="mt-1 line-clamp-3 text-sm text-gray-700 dark:text-gray-300">{{ $lien->displayDescription() }}</p>

                    <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                        {{-- L'auteur de l'Offre, **et non** qui l'a mise en avant :
                             confondre les deux ferait écrire au mauvais. --}}
                        {{-- `publicDisplayName()` : un compte désactivé ne s'affiche
                             nulle part ailleurs sous son vrai nom. --}}
                        @if ($lien->author())
                            {{ __('loops.cards.marketplace.by', ['name' => $lien->authorName()]) }}
                        @endif
                        @if ($lien->addedBy && $lien->added_by !== ($lien->author()?->id))
                            · {{ __('loops.cards.marketplace.added_by', ['name' => $lien->addedByName()]) }}
                        @endif
                    </p>

                    {{-- Le chemin vers l'Offre. Sans lui la Card montrait un titre
                         et un prénom sans permettre ni de l'ouvrir, ni de la
                         demander, ni d'écrire à la personne. --}}
                    @if ($lien->isLive() && $this->catalogueUrl($lien))
                        <a href="{{ $this->catalogueUrl($lien) }}"
                           class="mt-2 inline-block text-xs font-semibold text-indigo-600 underline hover:text-indigo-700 dark:text-indigo-300">
                            {{ __('loops.cards.marketplace.open_in_catalogue') }}
                        </a>
                    @endif

                    @if ($lien->note && $editingId !== $lien->id)
                        <p class="mt-2 whitespace-pre-line rounded-xl bg-gray-50 px-3 py-2 text-sm text-gray-700 dark:bg-gray-900/50 dark:text-gray-300">{{ $lien->note }}</p>
                    @endif

                    @if ($editingId === $lien->id)
                        <div class="mt-2 space-y-2 rounded-xl border border-gray-200 p-3 dark:border-gray-700">
                            <label class="block">
                                <span class="text-xs font-medium text-gray-700 dark:text-gray-300">{{ __('loops.cards.marketplace.note_label') }}</span>
                                <textarea wire:model="note" rows="2" maxlength="2000" class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-800"></textarea>
                            </label>
                            <div class="flex gap-2">
                                <button type="button" wire:click="saveNote" class="rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-indigo-700">
                                    {{ __('loops.cards.marketplace.save') }}
                                </button>
                                <button type="button" wire:click="cancel" class="rounded-lg px-3 py-1.5 text-xs text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700">
                                    {{ __('loops.cards.marketplace.cancel') }}
                                </button>
                            </div>
                        </div>
                    @endif
                </li>
            @endforeach
        </ul>

        {{-- Ne pas taire ce qui n'est pas montré. --}}
        @if ($total > $links->count())
            <p class="mt-2 text-center text-xs text-gray-500 dark:text-gray-400">
                {{ trans_choice('loops.cards.marketplace.and_more', $total - $links->count(), ['count' => $total - $links->count()]) }}
            </p>
        @endif
    @endif

    @if ($canHighlight)
        <div class="mt-4 space-y-2">
            @if ($pickingKind === '')
                <div class="flex flex-wrap gap-2">
                    <button type="button" wire:click="startPicking('offer')"
                            class="flex-1 rounded-xl border border-dashed border-gray-300 px-4 py-2.5 text-sm font-semibold text-gray-600 hover:border-emerald-400 hover:text-emerald-600 dark:border-gray-600 dark:text-gray-300">
                        + {{ __('loops.cards.marketplace.add_offer') }}
                    </button>
                    <button type="button" wire:click="startPicking('request')"
                            class="flex-1 rounded-xl border border-dashed border-gray-300 px-4 py-2.5 text-sm font-semibold text-gray-600 hover:border-indigo-400 hover:text-indigo-600 dark:border-gray-600 dark:text-gray-300">
                        + {{ __('loops.cards.marketplace.add_request') }}
                    </button>
                </div>
            @else
                <div class="space-y-2 rounded-xl border border-gray-200 p-3 dark:border-gray-700">
                    <p class="text-xs font-semibold text-gray-700 dark:text-gray-300">
                        {{ $pickingKind === 'offer' ? __('loops.cards.marketplace.pick_offer') : __('loops.cards.marketplace.pick_request') }}
                    </p>

                    {{-- Dit noir sur blanc ce que ce geste ne fait pas. Laisser croire
                         qu'une Boucle rend une Offre privée serait une promesse de
                         confidentialité que le produit ne tient pas. --}}
                    <p class="rounded-lg bg-amber-50 px-2.5 py-1.5 text-[11px] text-amber-900 dark:bg-amber-500/10 dark:text-amber-200">
                        {{ __('loops.cards.marketplace.visibility_notice') }}
                    </p>

                    @if ($pickable->isEmpty())
                        <p class="text-xs text-gray-500 dark:text-gray-400">
                            {{ $pickingKind === 'offer' ? __('loops.cards.marketplace.no_offer') : __('loops.cards.marketplace.no_request') }}
                        </p>
                    @else
                        <label class="block">
                            <span class="text-xs font-medium text-gray-700 dark:text-gray-300">{{ __('loops.cards.marketplace.note_label') }}</span>
                            <textarea wire:model="note" rows="2" maxlength="2000" class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-800"></textarea>
                        </label>

                        <ul class="space-y-1.5">
                            @foreach ($pickable as $candidat)
                                <li>
                                    <button type="button"
                                            wire:click="{{ $pickingKind === 'offer' ? 'highlightOffer' : 'highlightRequest' }}('{{ $candidat->id }}')"
                                            class="flex w-full items-center gap-2 rounded-lg bg-white px-2.5 py-2 text-left text-sm text-gray-800 hover:bg-indigo-50 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-indigo-500/15">
                                        <span class="min-w-0 flex-1 truncate">{{ $candidat->title }}</span>
                                    </button>
                                </li>
                            @endforeach
                        </ul>
                    @endif

                        @if ($pickableTotal > $pickable->count())
                            <p class="text-[11px] text-gray-500 dark:text-gray-400">
                                {{ trans_choice('loops.cards.marketplace.and_more', $pickableTotal - $pickable->count(), ['count' => $pickableTotal - $pickable->count()]) }}
                            </p>
                        @endif

                    {{-- Vers le parcours qui existe. La Card ne refait pas ce
                         formulaire : il a ses règles de catégorie, de mode de
                         livraison et de coût en points. --}}
                    <div class="flex flex-wrap items-center gap-2 pt-1">
                        <a href="{{ $pickingKind === 'offer' ? $catalogue['offer_create'] : $catalogue['request_create'] }}"
                           class="text-xs font-semibold text-indigo-600 underline hover:text-indigo-700 dark:text-indigo-300">
                            {{ $pickingKind === 'offer' ? __('loops.cards.marketplace.create_offer') : __('loops.cards.marketplace.create_request') }}
                        </a>
                        <button type="button" wire:click="cancel" class="ml-auto rounded-lg px-3 py-1.5 text-xs text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700">
                            {{ __('loops.cards.marketplace.cancel') }}
                        </button>
                    </div>
                </div>
            @endif
        </div>
    @endif

@endif
</div>
