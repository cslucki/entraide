{{--
    Card Veille — TASK-1879.

    Une liste (plus recentes d'abord, filtrable par categorie), un formulaire,
    et pour qui mene la Boucle la gestion des categories. Mobile-first : une
    colonne, des cibles de 44px, rien qui deborde. Les boutons visibles ne
    decident de rien : LoopWatchService reverifie chaque geste.

    Les liens sources sont EXTERNES : `target="_blank"` et
    `rel="noopener noreferrer nofollow"`, jamais d'apercu distant.
--}}
<div class="space-y-3" data-watch-root>

    @if(! $allowed)
        <p class="rounded-xl border border-gray-200 bg-gray-50 px-3 py-2 text-xs leading-5 text-gray-600 dark:border-gray-700 dark:bg-gray-800/60 dark:text-gray-300">
            {{ __('veille.unavailable') }}
        </p>
    @else

        @if($readOnly)
            <p class="rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-xs leading-5 text-amber-800 dark:border-amber-800/60 dark:bg-amber-900/20 dark:text-amber-200" data-watch-read-only>
                {{ __('veille.read_only') }}
            </p>
        @endif

        @if($errorMessage)
            <p class="rounded-xl border border-red-200 bg-red-50 px-3 py-2 text-xs leading-5 text-red-700 dark:border-red-800/60 dark:bg-red-900/20 dark:text-red-300" role="alert">
                {{ $errorMessage }}
            </p>
        @endif

        @if($successMessage)
            <p class="rounded-xl border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs leading-5 text-emerald-700 dark:border-emerald-800/60 dark:bg-emerald-900/20 dark:text-emerald-300" role="status">
                {{ $successMessage }}
            </p>
        @endif

        {{-- ── Aucune categorie : publication impossible (categorie obligatoire) ── --}}
        @if($needsCategory && ($canCreate || $canManageCategories))
            <p class="rounded-xl border border-sky-200 bg-sky-50 px-3 py-2 text-xs leading-5 text-sky-800 dark:border-sky-800/60 dark:bg-sky-900/20 dark:text-sky-200" data-watch-needs-category>
                {{ $canManageCategories ? __('veille.needs_category_manager') : __('veille.needs_category_member') }}
            </p>
        @endif

        {{-- ── Actions principales ───────────────────────────────────────── --}}
        @if(! $showForm && ($canCreate || $canManageCategories))
            <div class="flex flex-wrap gap-2">
                @if($canCreate && ! $needsCategory)
                    <button type="button" wire:click="openCreateForm" data-action="add-watch"
                            class="inline-flex min-h-[44px] flex-1 items-center justify-center gap-2 rounded-xl bg-sky-600 px-4 text-sm font-semibold text-white transition hover:bg-sky-700">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                        {{ __('veille.add') }}
                    </button>
                @endif
                @if($canManageCategories)
                    <button type="button" wire:click="toggleCategories" data-action="toggle-categories"
                            aria-expanded="{{ $showCategories ? 'true' : 'false' }}"
                            class="inline-flex min-h-[44px] items-center justify-center rounded-xl border border-gray-300 px-4 text-sm font-medium text-gray-700 transition hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-800">
                        {{ __('veille.categories_manage') }}
                    </button>
                @endif
            </div>
        @endif

        {{-- ── Gestion des categories (proprietaire / animateur) ────────── --}}
        @if($canManageCategories && $showCategories && ! $showForm)
            <section class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900" data-section="watch-categories">
                <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('veille.categories_title') }}</h3>
                <p class="mt-1 text-xs leading-5 text-gray-500 dark:text-gray-400">{{ __('veille.categories_intro') }}</p>

                <form wire:submit="createCategory" class="mt-3 flex gap-2">
                    <label for="watch-new-category" class="sr-only">{{ __('veille.category_name') }}</label>
                    <input id="watch-new-category" type="text" wire:model="newCategoryName" maxlength="80" required
                           placeholder="{{ __('veille.category_name_placeholder') }}"
                           class="min-h-[44px] min-w-0 flex-1 rounded-xl border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100">
                    <button type="submit"
                            class="min-h-[44px] shrink-0 rounded-xl bg-sky-600 px-4 text-sm font-semibold text-white hover:bg-sky-700">
                        {{ __('veille.category_add') }}
                    </button>
                </form>

                @if($allCategories->isEmpty())
                    <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">{{ __('veille.categories_empty') }}</p>
                @else
                    @php $actives = $allCategories->whereNull('archived_at')->values(); @endphp
                    <ul class="mt-3 divide-y divide-gray-100 dark:divide-gray-800">
                        @foreach($allCategories as $categorie)
                            @php $rang = $actives->search(fn ($c) => $c->id === $categorie->id); @endphp
                            <li class="flex flex-wrap items-center gap-2 py-2" data-watch-category="{{ $categorie->id }}" wire:key="watch-cat-{{ $categorie->id }}">
                                @if($renamingCategoryId === $categorie->id)
                                    <form wire:submit="renameCategory" class="flex w-full gap-2">
                                        <input type="text" wire:model="renameCategoryName" maxlength="80" required
                                               aria-label="{{ __('veille.category_name') }}"
                                               class="min-h-[44px] min-w-0 flex-1 rounded-xl border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100">
                                        <button type="submit" class="min-h-[44px] rounded-xl bg-sky-600 px-3 text-sm font-semibold text-white hover:bg-sky-700">{{ __('veille.save') }}</button>
                                        <button type="button" wire:click="cancelRename" class="min-h-[44px] rounded-xl border border-gray-300 px-3 text-sm text-gray-700 dark:border-gray-600 dark:text-gray-200">{{ __('veille.cancel') }}</button>
                                    </form>
                                @else
                                    <span class="min-w-0 flex-1 truncate text-sm {{ $categorie->isArchived() ? 'text-gray-400 line-through dark:text-gray-500' : 'text-gray-900 dark:text-gray-100' }}">
                                        {{ $categorie->name }}
                                    </span>
                                    @if($categorie->isArchived())
                                        <span class="rounded-full bg-gray-100 px-2 py-0.5 text-[11px] text-gray-500 dark:bg-gray-800 dark:text-gray-400">{{ __('veille.category_archived_badge') }}</span>
                                    @else
                                        <div class="flex shrink-0 items-center gap-1">
                                            <button type="button" wire:click="moveCategory('{{ $categorie->id }}', 'up')" @disabled($rang === 0)
                                                    aria-label="{{ __('veille.category_up') }}"
                                                    class="inline-flex h-11 w-11 items-center justify-center rounded-xl text-gray-500 hover:bg-gray-100 disabled:opacity-30 dark:text-gray-400 dark:hover:bg-gray-800">↑</button>
                                            <button type="button" wire:click="moveCategory('{{ $categorie->id }}', 'down')" @disabled($rang === $actives->count() - 1)
                                                    aria-label="{{ __('veille.category_down') }}"
                                                    class="inline-flex h-11 w-11 items-center justify-center rounded-xl text-gray-500 hover:bg-gray-100 disabled:opacity-30 dark:text-gray-400 dark:hover:bg-gray-800">↓</button>
                                            <button type="button" wire:click="startRename('{{ $categorie->id }}')"
                                                    class="min-h-[44px] rounded-xl px-2 text-xs font-medium text-sky-700 hover:bg-sky-50 dark:text-sky-300 dark:hover:bg-sky-900/30">{{ __('veille.category_rename') }}</button>
                                            <button type="button" wire:click="archiveCategory('{{ $categorie->id }}')"
                                                    wire:confirm="{{ __('veille.category_archive_confirm') }}"
                                                    class="min-h-[44px] rounded-xl px-2 text-xs font-medium text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800">{{ __('veille.category_archive') }}</button>
                                        </div>
                                    @endif
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        @endif

        {{-- ── Formulaire de veille ──────────────────────────────────────── --}}
        @if($showForm)
            <form wire:submit="save" class="space-y-3 rounded-2xl border border-sky-200 bg-white p-4 dark:border-sky-800/60 dark:bg-gray-900" data-section="watch-form">
                <p class="text-sm font-semibold text-gray-900 dark:text-gray-100">
                    {{ $editingId ? __('veille.form_title_edit') : __('veille.form_title_create') }}
                </p>

                <div class="grid gap-3 sm:grid-cols-2">
                    <div>
                        <label for="watch-date" class="block text-xs font-medium text-gray-700 dark:text-gray-300">{{ __('veille.field_date') }}</label>
                        <input id="watch-date" type="date" wire:model="observedOn" required
                               class="mt-1 block min-h-[44px] w-full rounded-xl border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100">
                    </div>

                    <div>
                        <label for="watch-category" class="block text-xs font-medium text-gray-700 dark:text-gray-300">{{ __('veille.field_category') }}</label>
                        @php
                            // Une veille en edition peut porter une categorie
                            // archivee : elle reste affichee et conservable,
                            // sans etre proposee aux nouvelles veilles.
                            $categorieCourante = $editingId && $categoryId !== '' && ! $categories->contains('id', $categoryId)
                                ? \App\Models\LoopWatchCategory::query()->whereKey($categoryId)->where('loop_id', $loop->id)->first()
                                : null;
                        @endphp
                        <select id="watch-category" wire:model="categoryId" required
                                class="mt-1 block min-h-[44px] w-full rounded-xl border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100">
                            <option value="">{{ __('veille.category_choose') }}</option>
                            @foreach($categories as $categorie)
                                <option value="{{ $categorie->id }}">{{ $categorie->name }}</option>
                            @endforeach
                            @if($categorieCourante)
                                <option value="{{ $categorieCourante->id }}">{{ $categorieCourante->name }} ({{ __('veille.category_archived_badge') }})</option>
                            @endif
                        </select>
                    </div>
                </div>

                <div>
                    <label for="watch-subject" class="block text-xs font-medium text-gray-700 dark:text-gray-300">{{ __('veille.field_subject') }}</label>
                    <input id="watch-subject" type="text" wire:model="subject" required maxlength="{{ \App\Models\LoopWatchItem::SUBJECT_MAX }}"
                           placeholder="{{ __('veille.field_subject_placeholder') }}"
                           class="mt-1 block min-h-[44px] w-full rounded-xl border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100">
                </div>

                <div>
                    <label for="watch-information" class="block text-xs font-medium text-gray-700 dark:text-gray-300">{{ __('veille.field_information') }}</label>
                    <textarea id="watch-information" wire:model="information" rows="3" required maxlength="{{ \App\Models\LoopWatchItem::INFORMATION_MAX }}"
                              placeholder="{{ __('veille.field_information_placeholder') }}"
                              class="mt-1 block w-full rounded-xl border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100"></textarea>
                </div>

                <div>
                    <label for="watch-impact" class="block text-xs font-medium text-gray-700 dark:text-gray-300">{{ __('veille.field_impact') }}</label>
                    <textarea id="watch-impact" wire:model="impact" rows="3" required maxlength="{{ \App\Models\LoopWatchItem::IMPACT_MAX }}"
                              placeholder="{{ __('veille.field_impact_placeholder') }}"
                              class="mt-1 block w-full rounded-xl border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100"></textarea>
                </div>

                <div>
                    <label for="watch-source" class="block text-xs font-medium text-gray-700 dark:text-gray-300">{{ __('veille.field_source') }}</label>
                    <input id="watch-source" type="url" wire:model="sourceUrl" required maxlength="{{ \App\Models\LoopWatchItem::SOURCE_URL_MAX }}"
                           pattern="https?://.+" inputmode="url" placeholder="https://"
                           class="mt-1 block min-h-[44px] w-full rounded-xl border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100">
                </div>

                <div class="flex flex-wrap gap-2">
                    <button type="submit" wire:loading.attr="disabled" wire:target="save"
                            class="min-h-[44px] flex-1 rounded-xl bg-sky-600 px-4 text-sm font-semibold text-white hover:bg-sky-700 disabled:opacity-60">
                        {{ $editingId ? __('veille.save') : __('veille.publish') }}
                    </button>
                    <button type="button" wire:click="cancelForm"
                            class="min-h-[44px] rounded-xl border border-gray-300 px-4 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-800">
                        {{ __('veille.cancel') }}
                    </button>
                </div>
            </form>
        @endif

        {{-- ── Filtre ────────────────────────────────────────────────────── --}}
        @if($categories->isNotEmpty() && ! $showForm)
            <div>
                <label for="watch-filter" class="sr-only">{{ __('veille.filter_label') }}</label>
                <select id="watch-filter" wire:model.live="filterCategory" data-watch-filter
                        class="block min-h-[44px] w-full rounded-xl border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100">
                    <option value="">{{ __('veille.filter_all') }}</option>
                    @foreach($categories as $categorie)
                        <option value="{{ $categorie->id }}">{{ $categorie->name }}</option>
                    @endforeach
                </select>
            </div>
        @endif

        {{-- ── Liste ─────────────────────────────────────────────────────── --}}
        @if($items->isEmpty())
            <div class="rounded-2xl border border-dashed border-gray-300 px-4 py-6 text-center dark:border-gray-700" data-watch-empty>
                <p class="text-sm font-semibold text-gray-800 dark:text-gray-100">{{ __('loops.cards.veille.empty_title') }}</p>
                <p class="mt-1 text-xs leading-5 text-gray-500 dark:text-gray-400">{{ __('loops.cards.veille.empty_body') }}</p>
            </div>
        @else
            <ul class="space-y-3">
                @foreach($items as $ligne)
                    @php $veille = $ligne['item']; @endphp
                    <li class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900"
                        data-watch-item="{{ $veille->id }}" wire:key="watch-item-{{ $veille->id }}">
                        <div class="flex flex-wrap items-center gap-2 text-[11px] text-gray-500 dark:text-gray-400">
                            <time datetime="{{ $veille->observed_on->format('Y-m-d') }}" class="font-semibold text-gray-700 dark:text-gray-200">
                                {{ $veille->observed_on->isoFormat('D MMM YYYY') }}
                            </time>
                            @if($veille->category)
                                <span class="rounded-full bg-sky-100 px-2 py-0.5 font-medium text-sky-800 dark:bg-sky-900/40 dark:text-sky-200">{{ $veille->category->name }}</span>
                            @endif
                            <span class="ml-auto truncate">{{ $veille->author?->name ?? __('veille.author_unknown') }}</span>
                        </div>

                        @if(filled($veille->subject))
                            <p class="mt-2 break-words text-sm font-semibold text-gray-900 dark:text-gray-100" data-watch-subject>{{ $veille->subject }}</p>
                        @endif
                        <p class="mt-1 whitespace-pre-line break-words text-sm text-gray-800 dark:text-gray-200">{{ $veille->information }}</p>

                        <div class="mt-2 rounded-xl bg-sky-50/70 px-3 py-2 dark:bg-sky-900/20">
                            <p class="text-[11px] font-semibold uppercase tracking-wide text-sky-700 dark:text-sky-300">{{ __('veille.field_impact') }}</p>
                            <p class="mt-0.5 whitespace-pre-line break-words text-sm text-gray-800 dark:text-gray-200">{{ $veille->impact }}</p>
                        </div>

                        @if(\App\Services\Loops\LoopWatchService::isSafeUrl($veille->source_url))
                            <a href="{{ $veille->source_url }}" target="_blank" rel="noopener noreferrer nofollow"
                               class="mt-2 inline-flex min-h-[44px] max-w-full items-center gap-1 break-all text-xs font-medium text-sky-700 hover:underline dark:text-sky-300">
                                {{ __('veille.source_link') }} — {{ parse_url($veille->source_url, PHP_URL_HOST) }}
                                <svg class="h-3.5 w-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 0 0 3 8.25v10.5A2.25 2.25 0 0 0 5.25 21h10.5A2.25 2.25 0 0 0 18 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25"/></svg>
                            </a>
                        @endif

                        @if($ligne['can_manage'] && ! $showForm)
                            <div class="mt-2 flex flex-wrap items-center gap-2 border-t border-gray-100 pt-2 dark:border-gray-800">
                                @if($confirmingDeleteId === $veille->id)
                                    <span class="text-xs text-gray-600 dark:text-gray-300">{{ __('veille.delete_confirm') }}</span>
                                    <button type="button" wire:click="delete('{{ $veille->id }}')"
                                            class="min-h-[44px] rounded-xl bg-red-600 px-3 text-xs font-semibold text-white hover:bg-red-700">{{ __('veille.delete') }}</button>
                                    <button type="button" wire:click="cancelDelete"
                                            class="min-h-[44px] rounded-xl border border-gray-300 px-3 text-xs text-gray-700 dark:border-gray-600 dark:text-gray-200">{{ __('veille.cancel') }}</button>
                                @else
                                    <button type="button" wire:click="openEditForm('{{ $veille->id }}')" data-action="edit-watch"
                                            class="min-h-[44px] rounded-xl px-3 text-xs font-medium text-sky-700 hover:bg-sky-50 dark:text-sky-300 dark:hover:bg-sky-900/30">{{ __('veille.edit') }}</button>
                                    <button type="button" wire:click="confirmDelete('{{ $veille->id }}')" data-action="delete-watch"
                                            class="min-h-[44px] rounded-xl px-3 text-xs font-medium text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-900/20">{{ __('veille.delete') }}</button>
                                @endif
                            </div>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    @endif
</div>
