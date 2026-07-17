<x-app-layout>
    @php
        $organizationRouteParam = request()->route('organization');
        $entries = $dossier->dossierBlogPosts->filter(fn ($entry) => $entry->blogPost !== null)->values();
    @endphp

    <x-slot name="title">{{ $dossier->name }} — {{ __('dossiers.title') }} — {{ $brandOrganizationName ?? 'BouclePro' }}</x-slot>

    <x-page-container>
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div class="min-w-0">
                <a href="{{ route('organization.dossiers.index', ['organization' => $organizationRouteParam]) }}" class="text-sm font-medium text-indigo-600 hover:underline dark:text-indigo-400">{{ __('dossiers.back') }}</a>
                <div class="mt-4 flex flex-wrap items-center gap-3">
                    <h1 class="text-3xl font-bold text-gray-900 dark:text-gray-100">{{ $dossier->name }}</h1>
                    <span class="rounded-full bg-indigo-50 px-3 py-1 text-xs font-semibold uppercase tracking-wide text-indigo-700 dark:bg-indigo-950/60 dark:text-indigo-200">{{ __('dossiers.private_badge') }}</span>
                </div>
                <p class="mt-2 max-w-2xl text-sm text-gray-600 dark:text-gray-300">{{ __('dossiers.show_subtitle') }}</p>
            </div>
            <a href="{{ route('organization.dossiers.edit', ['organization' => $organizationRouteParam, 'dossier' => $dossier->getKey()]) }}" class="inline-flex items-center justify-center rounded-lg border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 transition hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">
                {{ __('dossiers.rename') }}
            </a>
        </div>

        @if(session('success'))
            <div class="mt-6 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800 dark:border-emerald-900/60 dark:bg-emerald-950/40 dark:text-emerald-200">
                {{ session('success') }}
            </div>
        @endif

        @if($errors->any())
            <div class="mt-6 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-800 dark:border-red-900/60 dark:bg-red-950/40 dark:text-red-200">
                {{ $errors->first() }}
            </div>
        @endif

        <div class="mt-8 grid gap-6 xl:grid-cols-[minmax(0,1fr)_22rem]">
            <section class="rounded-3xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800 sm:p-6">
                <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h2 class="text-xl font-semibold text-gray-900 dark:text-gray-100">{{ __('dossiers.articles_title') }}</h2>
                        <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ __('dossiers.articles_help') }}</p>
                    </div>
                </div>

                @if($entries->isEmpty())
                    <div class="mt-6 rounded-2xl border border-dashed border-gray-300 px-5 py-10 text-center dark:border-gray-700">
                        <h3 class="text-base font-semibold text-gray-900 dark:text-gray-100">{{ __('dossiers.articles_empty_title') }}</h3>
                        <p class="mx-auto mt-2 max-w-md text-sm text-gray-600 dark:text-gray-300">{{ __('dossiers.articles_empty_body') }}</p>
                    </div>
                @else
                    <ol class="mt-6 space-y-3">
                        @foreach($entries as $index => $entry)
                            @php $post = $entry->blogPost; @endphp
                            <li class="rounded-2xl border border-gray-200 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-900/40">
                                <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                                    <div class="min-w-0">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <span class="inline-flex h-7 w-7 items-center justify-center rounded-full bg-white text-xs font-bold text-gray-600 ring-1 ring-gray-200 dark:bg-gray-800 dark:text-gray-300 dark:ring-gray-700">{{ $index + 1 }}</span>
                                            <span class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $post->status === 'published' ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-200' : 'bg-amber-100 text-amber-800 dark:bg-amber-950/50 dark:text-amber-200' }}">
                                                {{ __('dossiers.status_'.$post->status, [], app()->getLocale()) !== 'dossiers.status_'.$post->status ? __('dossiers.status_'.$post->status) : $post->status }}
                                            </span>
                                        </div>
                                        <h3 class="mt-3 text-lg font-semibold text-gray-900 dark:text-gray-100">{{ $post->title }}</h3>
                                        <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ __('dossiers.article_updated_at', ['date' => $post->updated_at->diffForHumans()]) }}</p>
                                    </div>

                                    <div class="flex flex-col gap-2 sm:flex-row lg:flex-col">
                                        <a href="{{ route('organization.blog.edit', ['organization' => $organizationRouteParam, 'post' => $post->slug]) }}" class="inline-flex items-center justify-center rounded-lg border border-gray-300 px-3 py-2 text-sm font-semibold text-gray-700 hover:bg-white dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-800">
                                            {{ __('dossiers.edit_article') }}
                                        </a>
                                        <form method="POST" action="{{ route('organization.dossiers.articles.destroy', ['organization' => $organizationRouteParam, 'dossier' => $dossier->getKey(), 'post' => $post->getKey()]) }}" onsubmit="return confirm('{{ __('dossiers.confirm_detach_article', ['title' => $post->title]) }}')">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="inline-flex w-full items-center justify-center rounded-lg border border-red-200 px-3 py-2 text-sm font-semibold text-red-600 hover:bg-red-50 dark:border-red-900/60 dark:text-red-300 dark:hover:bg-red-950/30">
                                                {{ __('dossiers.detach_article') }}
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </li>
                        @endforeach
                    </ol>

                    <form method="POST" action="{{ route('organization.dossiers.articles.reorder', ['organization' => $organizationRouteParam, 'dossier' => $dossier->getKey()]) }}" class="mt-6 rounded-2xl border border-gray-200 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-900/40">
                        @csrf
                        @method('PATCH')
                        <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ __('dossiers.reorder_title') }}</h3>
                        <p class="mt-1 text-xs text-gray-600 dark:text-gray-300">{{ __('dossiers.reorder_help') }}</p>
                        <div class="mt-4 space-y-2">
                            @foreach($entries as $entry)
                                <label class="grid gap-2 sm:grid-cols-[4rem_minmax(0,1fr)] sm:items-center">
                                    <span class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">{{ __('dossiers.position') }}</span>
                                    <select name="articles[]" class="block w-full rounded-lg border-gray-300 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                                        @foreach($entries as $option)
                                            <option value="{{ $option->blog_post_id }}" @selected($option->blog_post_id === $entry->blog_post_id)>{{ $option->blogPost->title }}</option>
                                        @endforeach
                                    </select>
                                </label>
                            @endforeach
                        </div>
                        <x-primary-button class="mt-4">{{ __('dossiers.save_order') }}</x-primary-button>
                    </form>
                @endif
            </section>

            <aside class="rounded-3xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800 sm:p-6">
                <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ __('dossiers.attach_title') }}</h2>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ __('dossiers.attach_help') }}</p>

                @if($eligibleArticles->isEmpty())
                    <p class="mt-5 rounded-xl bg-gray-50 px-4 py-3 text-sm text-gray-600 dark:bg-gray-900/40 dark:text-gray-300">{{ __('dossiers.no_eligible_articles') }}</p>
                @else
                    <form method="POST" action="{{ route('organization.dossiers.articles.store', ['organization' => $organizationRouteParam, 'dossier' => $dossier->getKey()]) }}" class="mt-5 space-y-4">
                        @csrf
                        <div>
                            <x-input-label for="blog_post_id" :value="__('dossiers.article_select_label')" />
                            <select id="blog_post_id" name="blog_post_id" required class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                                @foreach($eligibleArticles as $article)
                                    <option value="{{ $article->id }}">{{ $article->title }} — {{ __('dossiers.status_'.$article->status, [], app()->getLocale()) !== 'dossiers.status_'.$article->status ? __('dossiers.status_'.$article->status) : $article->status }}</option>
                                @endforeach
                            </select>
                            <x-input-error :messages="$errors->get('blog_post_id')" class="mt-2" />
                        </div>
                        <x-primary-button>{{ __('dossiers.attach_article') }}</x-primary-button>
                    </form>
                @endif
            </aside>
        </div>
    </x-page-container>
</x-app-layout>
