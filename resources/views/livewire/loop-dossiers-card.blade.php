{{--
    La Card Dossiers.

    Une fenetre sur le Dossier racine de la Boucle : ce qui a bouge recemment, et
    un chemin vers le reste. Elle ne charge jamais le Dossier entier, et n'ecrit
    rien — les deux gestes d'ecriture renvoient aux ecrans qui les portent deja.
--}}
@php
    $tile = 'rounded-2xl border border-[var(--bp-border)] bg-[var(--bp-panel)]';
    $quiet = 'inline-flex items-center gap-1.5 rounded-xl border border-[var(--bp-border)] bg-[var(--bp-panel)] px-2.5 py-1.5 text-xs font-semibold text-[var(--bp-muted)] transition hover:text-[var(--bp-text)]';
    $sectionTitle = 'mb-1.5 text-[10px] font-bold uppercase tracking-wide text-[var(--bp-muted)]';
@endphp

<div class="space-y-3">

    @if(! $dossier)
        {{-- Pas de Dossier racine, ou pas le droit de le lire. Les deux cas se
             disent de la meme facon : il n'y a rien a montrer ici. --}}
        <div class="{{ $tile }} p-5 text-center">
            <p class="text-sm text-[var(--bp-muted)]">{{ __('loops.cards.dossiers.empty_title') }}</p>
        </div>
    @else

        {{-- ── En-tete : le Dossier, son volume, son ouverture ────────────── --}}
        <div class="{{ $tile }} p-4">
            <div class="flex flex-wrap items-start gap-3">
                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-amber-100 text-amber-600 dark:bg-amber-500/20 dark:text-amber-300">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12.75V12A2.25 2.25 0 0 1 4.5 9.75h15A2.25 2.25 0 0 1 21.75 12v.75m-8.69-6.44-2.12-2.12a1.5 1.5 0 0 0-1.061-.44H4.5A2.25 2.25 0 0 0 2.25 6v12a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18V9a2.25 2.25 0 0 0-2.25-2.25h-5.379a1.5 1.5 0 0 1-1.06-.44Z"/>
                    </svg>
                </span>

                <div class="min-w-0 flex-1">
                    <p class="truncate text-sm font-bold text-[var(--bp-text)]">{{ $dossier->name }}</p>
                    <p class="mt-0.5 text-xs text-[var(--bp-muted)]">
                        {{ trans_choice('loops.cards.dossiers.item_count', $total, ['count' => $total]) }}
                    </p>
                </div>

                @if($urls)
                    <a href="{{ $urls['open'] }}" class="{{ $quiet }} shrink-0">
                        {{ __('loops.cards.dossiers.open') }}
                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3"/>
                        </svg>
                    </a>
                @endif
            </div>

            {{-- Les deux gestes d'ecriture. Ils ouvrent l'ecran du Dossier, la ou
                 ils vivent deja : les recopier ici les aurait fait diverger. --}}
            @if($urls && ($canCreateArticle || $canUploadFile))
                <div class="mt-3 flex flex-wrap gap-2 border-t border-[var(--bp-border)] pt-3">
                    @if($canCreateArticle)
                        <a href="{{ $urls['articles'] }}"
                           class="inline-flex items-center gap-1.5 rounded-xl bg-[var(--bp-primary)] px-3 py-1.5 text-xs font-semibold text-white transition hover:opacity-90">
                            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487a2.1 2.1 0 1 1 2.97 2.97L8.63 18.66l-4.243.53.53-4.243L16.862 4.487Z"/></svg>
                            {{ __('loops.cards.dossiers.create_article') }}
                        </a>
                    @endif
                    @if($canUploadFile)
                        <a href="{{ $urls['files'] }}" class="{{ $quiet }}">
                            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 16.5V9.75m0 0 3 3m-3-3-3 3M6.75 19.5a4.5 4.5 0 0 1-1.41-8.775 5.25 5.25 0 0 1 10.233-2.33 3 3 0 0 1 3.758 3.848A3.752 3.752 0 0 1 18 19.5H6.75Z"/></svg>
                            {{ __('loops.cards.dossiers.upload_file') }}
                        </a>
                    @endif
                </div>
            @elseif($readOnly)
                <p class="mt-3 border-t border-[var(--bp-border)] pt-3 text-xs text-[var(--bp-muted)]">
                    {{ __('loops.cards.dossiers.archived_read_only') }}
                </p>
            @endif
        </div>

        {{-- ── Rien encore ────────────────────────────────────────────────── --}}
        @if($articles->isEmpty() && $files->isEmpty() && $series->isEmpty())
            <div class="{{ $tile }} p-5 text-center">
                <p class="text-sm font-semibold text-[var(--bp-text)]">{{ __('loops.cards.dossiers.empty_title') }}</p>
                <p class="mt-1 text-xs leading-5 text-[var(--bp-muted)]">{{ __('loops.cards.dossiers.empty_body') }}</p>
            </div>
        @endif

        {{-- ── Articles recents ───────────────────────────────────────────── --}}
        @if($articles->isNotEmpty())
            <div class="{{ $tile }} p-3">
                <p class="{{ $sectionTitle }}">{{ __('loops.cards.dossiers.recent_articles') }}</p>
                <ul class="divide-y divide-[var(--bp-border)]">
                    @foreach($articles as $article)
                        <li wire:key="article-{{ $article->id }}">
                            <a href="{{ route('blog.show', $article->slug) }}"
                               class="flex items-center gap-2.5 rounded-xl px-1.5 py-2 transition hover:bg-[var(--bp-surface)]">
                                <x-user-avatar :user="$article->user" size="xs" />
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate text-sm font-medium text-[var(--bp-text)]">{{ $article->title }}</span>
                                    <span class="block truncate text-[10px] leading-tight text-[var(--bp-muted)]">
                                        {{ $article->user?->publicDisplayName() ?? '—' }} · {{ $article->updated_at->diffForHumans() }}
                                    </span>
                                </span>
                                @if($article->status === 'draft')
                                    <span class="shrink-0 rounded-full bg-[var(--bp-surface)] px-1.5 py-0.5 text-[9px] font-bold uppercase tracking-wide text-[var(--bp-muted)]">
                                        {{ __('loops.cards.dossiers.draft') }}
                                    </span>
                                @endif
                            </a>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        {{-- ── Series ─────────────────────────────────────────────────────── --}}
        @if($series->isNotEmpty())
            <div class="{{ $tile }} p-3">
                <p class="{{ $sectionTitle }}">{{ __('loops.cards.dossiers.series') }}</p>
                <ul class="divide-y divide-[var(--bp-border)]">
                    @foreach($series as $serie)
                        <li wire:key="series-{{ $serie->id }}" class="flex items-center gap-2.5 px-1.5 py-2">
                            <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-lg bg-sky-100 text-sky-600 dark:bg-sky-500/20 dark:text-sky-300">
                                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5"/></svg>
                            </span>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-sm font-medium text-[var(--bp-text)]">
                                    {{ $serie->rootBlogPost?->title ?? __('loops.cards.dossiers.series_untitled') }}
                                </span>
                                <span class="block text-[10px] leading-tight text-[var(--bp-muted)]">
                                    {{ trans_choice('loops.cards.dossiers.series_items', $serie->items_count, ['count' => $serie->items_count]) }}
                                </span>
                            </span>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        {{-- ── Fichiers recents ───────────────────────────────────────────── --}}
        @if($files->isNotEmpty())
            <div class="{{ $tile }} p-3">
                <p class="{{ $sectionTitle }}">{{ __('loops.cards.dossiers.recent_files') }}</p>
                <ul class="divide-y divide-[var(--bp-border)]">
                    @foreach($files as $file)
                        <li wire:key="file-{{ $file->id }}" class="flex items-center gap-2.5 px-1.5 py-2">
                            <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-lg bg-[var(--bp-surface)] text-[var(--bp-muted)]">
                                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-6a2.25 2.25 0 0 0-.659-1.591l-3-3A2.25 2.25 0 0 0 14.25 3H6.75A2.25 2.25 0 0 0 4.5 5.25v13.5A2.25 2.25 0 0 0 6.75 21h10.5a2.25 2.25 0 0 0 2.25-2.25v-4.5z"/></svg>
                            </span>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-sm font-medium text-[var(--bp-text)]">{{ $file->display_name ?: $file->original_name }}</span>
                                <span class="block truncate text-[10px] leading-tight text-[var(--bp-muted)]">
                                    {{ $file->uploader?->publicDisplayName() ?? '—' }} · {{ $file->created_at->diffForHumans() }}
                                </span>
                            </span>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    @endif
</div>
