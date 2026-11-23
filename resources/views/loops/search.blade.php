{{--
    TASK-1885 C9 (demande Cyril, 22/11) — resultats de la recherche dans MES
    Boucles, groupes par Boucle : messages, articles, dossiers et fichiers,
    evenements. Meme gabarit de page que « Derniers messages » (sous-page de
    « Boucles »).

    `$boucle`, jamais `$loop` : Blade pose `$loop` a chaque `@foreach`.
--}}
@php
    $_org = request()->route('organization');
    $_scopee = $_org && request()->routeIs('organization.*');
    $loopsIndexHref = $_scopee ? route('organization.loops.index', ['organization' => $_org]) : route('loops.index');
    $searchAction = $_scopee ? route('organization.loops.search', ['organization' => $_org]) : route('loops.search');
    $boucleHref = function ($boucle, array $query = []) use ($_scopee, $_org) {
        if ($_scopee) {
            return route('organization.loops.show', ['organization' => $_org, 'loop' => $boucle->slug ?: $boucle->id] + $query);
        }

        return route('loops.show', ['loop' => $boucle->id] + $query);
    };
    $dossierHref = function ($dossierId, $boucle) use ($_scopee, $_org, $boucleHref) {
        if ($_scopee && \Illuminate\Support\Facades\Route::has('organization.dossiers.show')) {
            return route('organization.dossiers.show', ['organization' => $_org, 'dossier' => $dossierId]);
        }

        return $boucleHref($boucle, ['card' => 'core.dossiers']);
    };
    $articleHref = function ($article) use ($_scopee, $_org) {
        return $_scopee
            ? route('organization.blog.show', ['organization' => $_org, 'post' => $article->slug])
            : route('blog.show', ['post' => $article->slug]);
    };
    $extrait = fn ($texte) => \App\Support\Loops\LoopSearch::extrait($texte, $terme);
@endphp

<x-page :title="__('loops.search_title')"
        :heading="__('loops.index_title')"
        :back="$loopsIndexHref"
        :back-label="__('loops.index_title')"
        width="3xl">
    <div data-loops-search-page>
        <h1 class="hidden md:block mb-4 text-2xl font-bold tracking-tight text-gray-900 dark:text-gray-100">{{ __('loops.search_title') }}</h1>

        <form method="GET" action="{{ $searchAction }}" role="search" class="mb-6" data-loops-search-form>
            <label class="relative block">
                <span class="sr-only">{{ __('loops.search_label') }}</span>
                <svg class="pointer-events-none absolute left-4 top-1/2 h-5 w-5 -translate-y-1/2 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path stroke-linecap="round" d="m20 20-3.5-3.5"/></svg>
                <input type="search" name="q" value="{{ $terme }}" minlength="{{ \App\Support\Loops\LoopSearch::MIN }}" maxlength="{{ \App\Support\Loops\LoopSearch::MAX }}" required
                       placeholder="{{ __('loops.search_in_loops_placeholder') }}" @if($terme === '') autofocus @endif
                       style="padding-left: 3rem;" class="min-h-[48px] w-full rounded-2xl border border-gray-300 bg-white py-3 pr-4 text-base text-gray-900 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100">
            </label>
        </form>

        @if(! $aAuMoinsUneBoucle)
            <div class="rounded-2xl border border-dashed border-gray-300 p-8 text-center dark:border-gray-600" data-loops-search-no-loop>
                <p class="text-sm text-gray-600 dark:text-gray-300">{{ __('loops.search_no_loop') }}</p>
                <a href="{{ $loopsIndexHref }}" class="mt-3 inline-flex min-h-[44px] items-center text-sm font-semibold text-indigo-600 hover:underline dark:text-indigo-400">{{ __('loops.search_no_loop_link') }}</a>
            </div>
        @elseif($terme === '')
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('loops.search_intro') }}</p>
        @elseif($tropCourt)
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('loops.search_too_short', ['min' => \App\Support\Loops\LoopSearch::MIN]) }}</p>
        @elseif($resultats->isEmpty())
            <div class="rounded-2xl border border-dashed border-gray-300 p-8 text-center dark:border-gray-600" data-loops-search-empty>
                <p class="text-sm text-gray-600 dark:text-gray-300">{{ __('loops.search_empty', ['q' => $terme]) }}</p>
            </div>
        @else
            <p class="mb-4 text-sm text-gray-500 dark:text-gray-400" data-loops-search-count>
                {{ trans_choice('loops.search_count', $total, ['count' => $total, 'loops' => trans_choice('loops.search_count_loops', $resultats->count(), ['count' => $resultats->count()])]) }}
            </p>

            <div class="space-y-6">
                @foreach($resultats as $groupe)
                    @php($boucle = $groupe['loop'])
                    <section class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800" data-loops-search-section="{{ $boucle->id }}">
                        <a href="{{ $boucleHref($boucle) }}" class="relative block">
                            <x-loops.cover :loop="$boucle" ratio="16 / 4" sizes="(min-width: 1024px) 60vw, 100vw" />
                            <div class="absolute inset-0 flex items-end justify-between gap-3 bg-gradient-to-t from-black/75 via-black/10 to-transparent p-4">
                                <h2 class="min-w-0 truncate text-lg font-bold text-white drop-shadow-sm">{{ $boucle->name }}</h2>
                                <span class="shrink-0 rounded-full bg-white/90 px-2.5 py-0.5 text-xs font-semibold text-gray-900">{{ $groupe['total'] }}</span>
                            </div>
                        </a>

                        <div class="divide-y divide-gray-100 dark:divide-gray-700">
                            @if($groupe['messages']->isNotEmpty())
                                <div class="p-4" data-loops-search-family="messages">
                                    <h3 class="mb-2 flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M8 10h8M8 14h5m-9 6 2.5-3H19a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v14Z"/></svg>
                                        {{ __('loops.search_family_messages') }}
                                    </h3>
                                    <ul class="space-y-1">
                                        @foreach($groupe['messages'] as $message)
                                            <li>
                                                <a href="{{ $boucleHref($boucle, ['message' => $message->id]) }}" data-loops-search-message="{{ $message->id }}"
                                                   class="flex gap-3 rounded-xl p-2 transition hover:bg-gray-50 dark:hover:bg-gray-700/40">
                                                    <img src="{{ $message->sender?->avatar_url }}" alt="" class="h-8 w-8 shrink-0 rounded-full object-cover ring-1 ring-gray-200 dark:ring-gray-600">
                                                    <div class="min-w-0 flex-1">
                                                        <div class="flex flex-wrap items-baseline gap-x-2">
                                                            <span class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ $message->sender?->publicDisplayName() }}</span>
                                                            <span class="text-xs text-gray-400">{{ $message->created_at->isoFormat('D MMM YYYY, HH:mm') }}</span>
                                                        </div>
                                                        <p class="break-words text-sm text-gray-600 dark:text-gray-300">{{ $extrait($message->body) }}</p>
                                                    </div>
                                                </a>
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endif

                            @if($groupe['articles']->isNotEmpty())
                                <div class="p-4" data-loops-search-family="articles">
                                    <h3 class="mb-2 flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M19 20H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v1m2 13a2 2 0 0 1-2-2V7m2 13a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-2M7 8h6M7 12h6M7 16h4"/></svg>
                                        {{ __('loops.search_family_articles') }}
                                    </h3>
                                    <ul class="space-y-1">
                                        @foreach($groupe['articles'] as $article)
                                            <li>
                                                <a href="{{ $articleHref($article) }}" data-loops-search-article="{{ $article->id }}" class="block rounded-xl p-2 transition hover:bg-gray-50 dark:hover:bg-gray-700/40">
                                                    <p class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ $extrait($article->title) }}</p>
                                                    <p class="break-words text-sm text-gray-600 dark:text-gray-300">{{ $extrait($article->content) }}</p>
                                                </a>
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endif

                            @if($groupe['dossiers']->isNotEmpty() || $groupe['fichiers']->isNotEmpty())
                                <div class="p-4" data-loops-search-family="dossiers">
                                    <h3 class="mb-2 flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7Z"/></svg>
                                        {{ __('loops.search_family_dossiers') }}
                                    </h3>
                                    <ul class="space-y-1">
                                        @foreach($groupe['dossiers'] as $dossier)
                                            <li>
                                                <a href="{{ $dossierHref($dossier->id, $boucle) }}" data-loops-search-dossier="{{ $dossier->id }}" class="flex items-center gap-2 rounded-xl p-2 text-sm text-gray-800 transition hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-700/40">
                                                    <span aria-hidden="true">📁</span><span class="min-w-0 truncate">{{ $extrait($dossier->name) }}</span>
                                                </a>
                                            </li>
                                        @endforeach
                                        @foreach($groupe['fichiers'] as $fichier)
                                            <li>
                                                <a href="{{ $dossierHref($fichier->dossier_id, $boucle) }}" data-loops-search-file="{{ $fichier->id }}" class="flex items-center gap-2 rounded-xl p-2 text-sm text-gray-800 transition hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-700/40">
                                                    <span aria-hidden="true">📄</span><span class="min-w-0 truncate">{{ $extrait($fichier->display_name ?: $fichier->original_name) }}</span>
                                                </a>
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endif

                            @if($groupe['evenements']->isNotEmpty())
                                <div class="p-4" data-loops-search-family="events">
                                    <h3 class="mb-2 flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"/><path stroke-linecap="round" d="M16 3v4M8 3v4M3 10h18"/></svg>
                                        {{ __('loops.search_family_events') }}
                                    </h3>
                                    <ul class="space-y-1">
                                        @foreach($groupe['evenements'] as $evenement)
                                            <li>
                                                <a href="{{ $boucleHref($boucle, ['card' => 'core.events']) }}" data-loops-search-event="{{ $evenement->id }}" class="block rounded-xl p-2 transition hover:bg-gray-50 dark:hover:bg-gray-700/40">
                                                    <p class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ $extrait($evenement->title) }}</p>
                                                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ $evenement->starts_at?->isoFormat('dddd D MMMM YYYY, HH:mm') }}</p>
                                                    @if(filled($evenement->description))
                                                        <p class="break-words text-sm text-gray-600 dark:text-gray-300">{{ $extrait($evenement->description) }}</p>
                                                    @endif
                                                </a>
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endif
                        </div>
                    </section>
                @endforeach
            </div>
        @endif
    </div>
</x-page>
