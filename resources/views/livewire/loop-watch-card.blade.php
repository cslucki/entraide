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
                            class="inline-flex min-h-[44px] items-center justify-center gap-1.5 rounded-xl border px-3 text-sm font-medium transition {{ $showCategories ? 'border-sky-300 bg-sky-50 text-sky-800 dark:border-sky-700 dark:bg-sky-900/30 dark:text-sky-200' : 'border-gray-300 text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-800' }}">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9.568 3H5.25A2.25 2.25 0 0 0 3 5.25v4.318c0 .597.237 1.17.659 1.591l9.581 9.581c.699.699 1.78.872 2.607.33a18.095 18.095 0 0 0 5.223-5.223c.542-.827.369-1.908-.33-2.607L11.16 3.66A2.25 2.25 0 0 0 9.568 3Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M6 6h.008v.008H6V6Z"/></svg>
                        {{ __('veille.categories_manage') }}
                    </button>
                @endif
            </div>
        @endif

        {{-- ── Gestion des categories (proprietaire / animateur) ────────── --}}
        @if($canManageCategories && $showCategories && ! $showForm)
            <section class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900" data-section="watch-categories">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ __('veille.categories_title') }}</h3>
                <p class="mt-1 text-xs leading-5 text-gray-500 dark:text-gray-400">{{ __('veille.categories_intro') }}</p>

                <form wire:submit="createCategory" class="relative mt-3 flex gap-2">
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
                                    <x-veille.categorie :category="$categorie" class="flex-1 text-sm {{ $categorie->isArchived() ? 'line-through' : '' }}" />
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

        {{-- ── Barre : filtre des categories + export, sur UNE ligne ─────────
             (anti-infobesite : une ligne de moins). L'export suit le filtre. --}}
        @php $orgSlug = currentOrganization()?->slug; @endphp
        @if(! $showForm && ($categories->isNotEmpty() || ($items->isNotEmpty() && $orgSlug)))
            <div class="flex items-center gap-2">
                @if($categories->isNotEmpty())
                    <div class="relative min-w-0 flex-1">
                        <label for="watch-filter" class="sr-only">{{ __('veille.filter_label') }}</label>
                        <select id="watch-filter" wire:model.live="filterCategory" data-watch-filter
                                class="block min-h-[44px] w-full rounded-xl border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100">
                            <option value="">{{ __('veille.filter_all') }}</option>
                            @foreach($categories as $categorie)
                                <option value="{{ $categorie->id }}">{{ $categorie->name }}</option>
                            @endforeach
                        </select>
                    </div>
                @else
                    <span class="flex-1"></span>
                @endif

                {{-- Export (demande Cyril) : icones seules, intitule en
                     `title` / `aria-label`. --}}
                @if($items->isNotEmpty() && $orgSlug)
                    @php
                        $exportParams = fn (string $format) => array_filter([
                            'organization' => $orgSlug,
                            // `$this->loop` : `$loop` est la variable des @foreach de Blade.
                            'loop' => $this->loop->id,
                            'format' => $format,
                            'categorie' => $filterCategory !== '' ? $filterCategory : null,
                        ]);
                    @endphp
                    <div class="flex shrink-0 items-center" data-section="watch-export">
                        @foreach([
                            ['format' => 'imprimer', 'cle' => 'pdf', 'badge' => 'PDF', 'couleur' => 'text-rose-600 dark:text-rose-400', 'nouvelOnglet' => true],
                            ['format' => 'csv', 'cle' => 'csv', 'badge' => 'CSV', 'couleur' => 'text-gray-600 dark:text-gray-300', 'nouvelOnglet' => false],
                            ['format' => 'xlsx', 'cle' => 'xlsx', 'badge' => 'XLS', 'couleur' => 'text-emerald-600 dark:text-emerald-400', 'nouvelOnglet' => false],
                        ] as $export)
                            <a href="{{ route('organization.loops.watch.export', $exportParams($export['format'])) }}"
                               @if($export['nouvelOnglet']) target="_blank" rel="noopener" @endif
                               data-export="{{ $export['cle'] }}"
                               aria-label="{{ __('veille.export_'.$export['cle'].'_title') }}"
                               title="{{ __('veille.export_'.$export['cle'].'_title') }}"
                               class="inline-flex h-11 w-11 flex-col items-center justify-center rounded-xl {{ $export['couleur'] }} transition hover:bg-gray-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-sky-500 dark:hover:bg-gray-800">
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m.75 12 3 3m0 0 3-3m-3 3v-6m-1.5-9H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z"/></svg>
                                <span class="mt-0.5 text-[9px] font-bold leading-none" aria-hidden="true">{{ $export['badge'] }}</span>
                            </a>
                        @endforeach
                    </div>
                @endif
            </div>
        @endif

        {{-- ── Membre : demander une categorie au proprietaire (TASK-1879) ──
             Place SOUS le menu des categories (filtre) — demande Cyril.
             Le composeur ChatLoop se pre-remplit d'une mention du
             proprietaire ; sur telephone, le panneau se ferme pour l'afficher.
             Rien n'est envoye sans la personne. --}}
        @if($canCreate && ! $canManageCategories && ! $showForm)
            <button type="button" data-action="suggest-watch-category"
                    x-on:click="$dispatch('bp-close-loop-card-mobile'); Livewire.dispatch('veille-suggerer-categorie')"
                    class="-mt-1 inline-flex min-h-[44px] items-center gap-1.5 self-start text-xs font-medium text-sky-700 underline-offset-2 hover:underline dark:text-sky-300">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>
                {{ __('veille.suggest_category_link') }}
            </button>
        @endif

        {{-- ── Liste ─────────────────────────────────────────────────────────
             Chaque veille repond a deux questions : qu'a-t-on repere, et
             qu'est-ce que ca change pour la Boucle. L'IMPACT est donc le bloc
             mis en relief ; l'information se replie a 3 lignes (anti-
             infobesite), la date sert de repere chronologique a gauche. --}}
        @if($items->isEmpty())
            <div class="flex flex-col items-center rounded-2xl border border-dashed border-gray-300 px-4 py-8 text-center dark:border-gray-700" data-watch-empty>
                <span class="flex h-11 w-11 items-center justify-center rounded-full bg-sky-50 text-sky-600 dark:bg-sky-900/30 dark:text-sky-300" aria-hidden="true">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0z"/></svg>
                </span>
                <p class="mt-3 text-sm font-semibold text-gray-800 dark:text-gray-100">{{ __('loops.cards.veille.empty_title') }}</p>
                <p class="mt-1 max-w-xs text-xs leading-5 text-gray-500 dark:text-gray-400">{{ __('loops.cards.veille.empty_body') }}</p>
            </div>
        @else
            <ul class="space-y-2.5">
                @foreach($items as $ligne)
                    @php
                        $veille = $ligne['item'];
                        $longue = mb_strlen((string) $veille->information) > 220 || substr_count((string) $veille->information, "\n") >= 3;
                        $memeAnnee = $veille->observed_on->isSameYear(now());
                    @endphp
                    <li class="flex gap-3 rounded-2xl border border-gray-200 bg-white p-3 sm:p-4 dark:border-gray-800 dark:bg-gray-900"
                        data-watch-item="{{ $veille->id }}" wire:key="watch-item-{{ $veille->id }}">

                        {{-- Repere de date : le jour d'abord, lisible d'un coup d'oeil. --}}
                        <time datetime="{{ $veille->observed_on->format('Y-m-d') }}"
                              class="flex h-12 w-11 shrink-0 flex-col items-center justify-center rounded-xl bg-gray-50 text-center dark:bg-gray-800/70"
                              title="{{ $veille->observed_on->isoFormat('LL') }}">
                            <span class="text-lg font-bold leading-none tabular-nums text-gray-900 dark:text-gray-100">{{ $veille->observed_on->format('j') }}</span>
                            <span class="mt-0.5 text-[10px] leading-none text-gray-500 dark:text-gray-400">{{ $veille->observed_on->isoFormat($memeAnnee ? 'MMM' : 'MMM YY') }}</span>
                        </time>

                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2 text-xs">
                                <x-veille.categorie :category="$veille->category" />
                                <span class="ml-auto shrink-0 truncate text-gray-400 dark:text-gray-500">{{ $veille->author?->name ?? __('veille.author_unknown') }}</span>
                            </div>

                            @if(filled($veille->subject))
                                <h4 class="mt-1 break-words text-[15px] font-semibold leading-snug text-gray-900 dark:text-gray-50" data-watch-subject>{{ $veille->subject }}</h4>
                            @endif

                            <div x-data="{ ouvert: false }" class="mt-1">
                                <p class="whitespace-pre-line break-words text-sm leading-6 text-gray-600 dark:text-gray-300"
                                   @if($longue) x-bind:class="ouvert ? '' : 'line-clamp-3'" @endif>{{ $veille->information }}</p>
                                @if($longue)
                                    <button type="button" x-on:click="ouvert = ! ouvert" x-bind:aria-expanded="ouvert"
                                            class="mt-0.5 min-h-[32px] text-xs font-medium text-sky-700 hover:underline dark:text-sky-300">
                                        <span x-show="! ouvert">{{ __('veille.read_more') }}</span>
                                        <span x-show="ouvert" x-cloak>{{ __('veille.read_less') }}</span>
                                    </button>
                                @endif
                            </div>

                            {{-- L'impact : le bloc qui compte, mis en relief. --}}
                            <div class="mt-2.5 rounded-r-xl border-l-[3px] border-sky-500 bg-sky-50/80 py-2 pl-3 pr-3 dark:border-sky-400 dark:bg-sky-950/40" data-watch-impact>
                                <p class="flex items-center gap-1 text-xs font-semibold text-sky-800 dark:text-sky-200">
                                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m16.49 12 3.75 3.75m0 0-3.75 3.75m3.75-3.75H3.74V4.499"/></svg>
                                    {{ __('veille.impact_heading') }}
                                </p>
                                <p class="mt-0.5 whitespace-pre-line break-words text-sm leading-6 text-gray-800 dark:text-gray-100">{{ $veille->impact }}</p>
                            </div>

                            <div class="mt-1.5 flex flex-wrap items-center gap-x-2">
                                @if(\App\Services\Loops\LoopWatchService::isSafeUrl($veille->source_url))
                                    <a href="{{ $veille->source_url }}" target="_blank" rel="noopener noreferrer nofollow"
                                       title="{{ $veille->source_url }}"
                                       class="relative inline-flex min-h-[44px] min-w-0 max-w-full items-center gap-1.5 text-xs font-medium text-gray-500 hover:text-sky-700 hover:underline dark:text-gray-400 dark:hover:text-sky-300">
                                        <svg class="h-3.5 w-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M13.19 8.688a4.5 4.5 0 0 1 1.242 7.244l-4.5 4.5a4.5 4.5 0 0 1-6.364-6.364l1.757-1.757m13.35-.622 1.757-1.757a4.5 4.5 0 0 0-6.364-6.364l-4.5 4.5a4.5 4.5 0 0 0 1.242 7.244"/></svg>
                                        <span class="sr-only">{{ __('veille.source_link') }} :</span>
                                        <span class="truncate">{{ parse_url($veille->source_url, PHP_URL_HOST) }}</span>
                                    </a>
                                @endif

                                @if($ligne['can_manage'] && ! $showForm)
                                    <div class="ml-auto flex items-center gap-1">
                                        @if($confirmingDeleteId === $veille->id)
                                            <span class="text-xs text-gray-600 dark:text-gray-300">{{ __('veille.delete_confirm') }}</span>
                                            <button type="button" wire:click="delete('{{ $veille->id }}')"
                                                    class="min-h-[44px] rounded-xl bg-red-600 px-3 text-xs font-semibold text-white hover:bg-red-700">{{ __('veille.delete') }}</button>
                                            <button type="button" wire:click="cancelDelete"
                                                    class="min-h-[44px] rounded-xl px-3 text-xs text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800">{{ __('veille.cancel') }}</button>
                                        @else
                                            <button type="button" wire:click="openEditForm('{{ $veille->id }}')" data-action="edit-watch"
                                                    aria-label="{{ __('veille.edit') }}" title="{{ __('veille.edit') }}"
                                                    class="inline-flex h-11 w-11 items-center justify-center rounded-xl text-gray-400 hover:bg-gray-100 hover:text-sky-700 dark:text-gray-500 dark:hover:bg-gray-800 dark:hover:text-sky-300">
                                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125"/></svg>
                                            </button>
                                            <button type="button" wire:click="confirmDelete('{{ $veille->id }}')" data-action="delete-watch"
                                                    aria-label="{{ __('veille.delete') }}" title="{{ __('veille.delete') }}"
                                                    class="inline-flex h-11 w-11 items-center justify-center rounded-xl text-gray-400 hover:bg-red-50 hover:text-red-600 dark:text-gray-500 dark:hover:bg-red-900/20 dark:hover:text-red-400">
                                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0"/></svg>
                                            </button>
                                        @endif
                                    </div>
                                @endif
                            </div>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    @endif
</div>
