<x-page :title="__('loops.articles_title')" :heading="__('loops.articles_title')">
    @php
        $organizationRouteParam = request()->route('organization');
        $_blogRoute = function ($name, $parameters = []) {
            $orgSlug = request()->route('organization');
            if (! $orgSlug || ! Route::has('organization.blog.'.$name)) {
                return route('blog.'.$name, $parameters);
            }
            return route('organization.blog.'.$name, array_merge(['organization' => $orgSlug], $parameters));
        };
        $_articlesRoute = function ($query = []) use ($organizationRouteParam) {
            if ($organizationRouteParam && request()->routeIs('organization.*')) {
                return route('organization.loops.articles', array_merge(['organization' => $organizationRouteParam], $query));
            }
            return route('loops.articles', $query);
        };
        $_loopShowHref = function ($loop) use ($organizationRouteParam) {
            if ($organizationRouteParam && request()->routeIs('organization.*')) {
                return route('organization.loops.show', ['organization' => $organizationRouteParam, 'loop' => $loop->slug ?: $loop->getKey()]);
            }
            return route('loops.show', $loop);
        };
    @endphp

    <p class="-mt-4 mb-6 text-sm text-gray-500 dark:text-gray-400">
        {{ __('loops.articles_subtitle') }}
    </p>

    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <p class="text-sm font-medium text-gray-700 dark:text-gray-300">
            {{ trans_choice('loops.articles_count', $posts->total(), ['count' => $posts->total()]) }}
        </p>

        @if($filterOptions->isNotEmpty())
        <div class="flex flex-wrap items-center gap-2">
            <a href="{{ $_articlesRoute() }}"
               class="px-3 py-1.5 rounded-full text-xs font-semibold transition {{ $activeLoopId ? 'bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300 hover:bg-gray-200' : 'bg-indigo-600 text-white' }}">
                {{ __('loops.articles_filter_all') }}
            </a>
            @foreach($filterOptions as $loopOption)
            <a href="{{ $_articlesRoute(['loop' => $loopOption->id]) }}"
               class="px-3 py-1.5 rounded-full text-xs font-semibold transition {{ $activeLoopId === $loopOption->id ? 'bg-indigo-600 text-white' : 'bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300 hover:bg-gray-200' }}">
                {{ $loopOption->name }}
            </a>
            @endforeach
        </div>
        @endif
    </div>

    @if($posts->isEmpty())
    <div class="text-center py-20 text-gray-500 dark:text-gray-400 bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700">
        <svg class="w-12 h-12 mx-auto mb-4 opacity-30" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 7.5h1.5m-1.5 3h1.5m-7.5 3h7.5m-7.5 3h7.5m3-9h3.375c.621 0 1.125.504 1.125 1.125V18a2.25 2.25 0 0 1-2.25 2.25M16.5 7.5V18a2.25 2.25 0 0 0 2.25 2.25M16.5 7.5V4.875c0-.621-.504-1.125-1.125-1.125H4.125C3.504 3.75 3 4.254 3 4.875V18a2.25 2.25 0 0 0 2.25 2.25h13.5M6 7.5h3v3H6v-3Z"/></svg>
        <p>{{ $activeLoopId ? __('loops.articles_empty_filtered') : __('loops.articles_empty') }}</p>
        @if($activeLoopId)
        <a href="{{ $_articlesRoute() }}" class="mt-3 inline-block text-indigo-600 dark:text-indigo-400 hover:underline">{{ __('loops.articles_filter_all') }}</a>
        @endif
    </div>
    @else
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
        @foreach($posts as $post)
        @php $postLoops = $linkedLoopsByPost->get($post->id, collect()); @endphp
        <article class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden hover:shadow-md transition group flex flex-col">
            <a href="{{ $_blogRoute('show', ['post' => $post]) }}" class="block">
                @if($post->image)
                <img src="{{ $post->image_url }}" alt="{{ $post->title }}" class="w-full h-40 object-cover group-hover:opacity-90 transition">
                @else
                <div class="w-full h-40 bg-gradient-to-br from-indigo-50 to-rose-50 dark:from-indigo-900/20 dark:to-rose-900/20 flex items-center justify-center text-indigo-300 dark:text-indigo-700">
                    <svg class="w-10 h-10" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 7.5h1.5m-1.5 3h1.5m-7.5 3h7.5m-7.5 3h7.5m3-9h3.375c.621 0 1.125.504 1.125 1.125V18a2.25 2.25 0 0 1-2.25 2.25M16.5 7.5V18a2.25 2.25 0 0 0 2.25 2.25M16.5 7.5V4.875c0-.621-.504-1.125-1.125-1.125H4.125C3.504 3.75 3 4.254 3 4.875V18a2.25 2.25 0 0 0 2.25 2.25h13.5M6 7.5h3v3H6v-3Z"/></svg>
                </div>
                @endif
            </a>
            <div class="p-5 flex flex-col flex-1">
                @if($postLoops->isNotEmpty())
                <div class="flex flex-wrap items-center gap-1.5 mb-2">
                    <span class="inline-flex items-center gap-1 text-[11px] font-semibold px-2 py-0.5 rounded-full bg-teal-50 dark:bg-teal-900/30 text-teal-700 dark:text-teal-300">
                        <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        {{ __('loops.articles_badge') }}
                    </span>
                </div>
                @endif

                <h2 class="font-semibold text-gray-900 dark:text-gray-100 mb-2 leading-snug">
                    <a href="{{ $_blogRoute('show', ['post' => $post]) }}" class="hover:text-indigo-600 dark:hover:text-indigo-400 transition">
                        {{ $post->title }}
                    </a>
                </h2>

                @if($post->summary)
                <p class="text-sm text-gray-500 dark:text-gray-400 mb-3 line-clamp-2">{{ $post->summary }}</p>
                @endif

                <div class="mt-auto pt-3 border-t border-gray-100 dark:border-gray-700 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-gray-400 dark:text-gray-500">
                    <span>{{ $post->published_at?->translatedFormat('d F Y') }}</span>
                    @foreach($postLoops as $postLoop)
                    <a href="{{ $_loopShowHref($postLoop) }}" class="inline-flex items-center gap-1 text-teal-700 dark:text-teal-300 hover:underline">
                        {{ $postLoop->name }}
                    </a>
                    @endforeach
                </div>
            </div>
        </article>
        @endforeach
    </div>

    <div class="mt-8">
        {{ $posts->links() }}
    </div>
    @endif
</x-page>
