<x-app-layout>
    <x-slot name="title">Blog — BouclePro</x-slot>

    <x-page-container>

        <!-- Header -->
        <div class="flex items-center justify-between mb-8">
            <div>
                <h1 class="text-3xl font-bold text-gray-900 dark:text-gray-100">Blog</h1>
                <p class="mt-1 text-gray-500 dark:text-gray-400">Conseils, expertises et ressources de la boucle</p>
            </div>
            @auth
            <div class="flex items-center gap-2">
                <a href="{{ route('blog.my-posts') }}" class="inline-flex items-center gap-2 px-4 py-2 border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700 text-sm font-semibold rounded-lg transition">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 20H5a2 2 0 01-2-2V6a2 2 0 012-2h10a2 2 0 012 2v1m2 13a2 2 0 01-2-2V7m2 13a2 2 0 002-2V9a2 2 0 00-2-2h-2m-4-3H9M7 16h6M7 8h6v4H7V8z"/></svg>
                    Mes articles
                </a>
                <a href="{{ route('blog.create') }}" class="inline-flex items-center gap-2 px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold rounded-lg transition">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                    Écrire un article
                </a>
            </div>
            @endauth
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-4 gap-8">

            <!-- Articles récents -->
            <div class="lg:col-span-3">
                @if($recentPosts->isEmpty())
                <div class="text-center py-16 text-gray-500 dark:text-gray-400">
                    <svg class="w-12 h-12 mx-auto mb-4 opacity-30" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19 20H5a2 2 0 01-2-2V6a2 2 0 012-2h10a2 2 0 012 2v1m2 13a2 2 0 01-2-2V7m2 13a2 2 0 002-2V9a2 2 0 00-2-2h-2m-4-3H9M7 16h6M7 8h6v4H7V8z"/></svg>
                    <p>Aucun article publié pour l'instant.</p>
                    @auth<a href="{{ route('blog.create') }}" class="mt-3 inline-block text-indigo-600 dark:text-indigo-400 hover:underline">Soyez le premier à publier</a>@endauth
                </div>
                @else
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
                    @foreach($recentPosts as $post)
                    <article class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden hover:shadow-md transition group">
                        @if($post->image)
                        <a href="{{ route('blog.show', $post) }}">
                            <img src="{{ $post->image_url }}" alt="{{ $post->title }}"
                                 class="w-full h-44 object-cover group-hover:opacity-90 transition">
                        </a>
                        @endif
                        <div class="p-5">
                            <!-- Catégories -->
                            @if($post->category)
                            <div class="flex flex-wrap gap-1 mb-2">
                                <a href="{{ route('blog.category', $post->category->slug) }}"
                                   class="text-xs font-medium px-2 py-0.5 rounded-full bg-indigo-50 dark:bg-indigo-900/30 text-indigo-600 dark:text-indigo-400 hover:bg-indigo-100 transition">
                                    {{ $post->category->displayName('blog') }}
                                </a>
                            </div>
                            @endif

                            <h2 class="font-semibold text-gray-900 dark:text-gray-100 mb-2 leading-snug">
                                <a href="{{ route('blog.show', $post) }}" class="hover:text-indigo-600 dark:hover:text-indigo-400 transition">
                                    {{ $post->title }}
                                </a>
                            </h2>

                            @if($post->summary)
                            <p class="text-sm text-gray-500 dark:text-gray-400 mb-3 line-clamp-2">{{ $post->summary }}</p>
                            @endif

                            <div class="flex items-center justify-between text-xs text-gray-500 dark:text-gray-400">
                                <div class="flex items-center gap-2">
                                    <img src="{{ $post->user->avatar_url }}" alt="" class="w-5 h-5 rounded-full">
                                    <span>{{ $post->user->name }}</span>
                                </div>
                                <div class="flex items-center gap-3">
                                    @auth
                                    @if(auth()->id() === $post->user_id)
                                    <a href="{{ route('blog.edit', $post) }}" class="text-indigo-600 dark:text-indigo-400 hover:underline font-medium">Modifier</a>
                                    @endif
                                    @endauth
                                    @if($post->read_time)
                                    <span>{{ $post->read_time }} min</span>
                                    @endif
                                    <span class="flex items-center gap-1">
                                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"/></svg>
                                        {{ $post->likes_count }}
                                    </span>
                                    <span class="flex items-center gap-1">
                                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>
                                        {{ $post->comments_count }}
                                    </span>
                                </div>
                            </div>
                        </div>
                    </article>
                    @endforeach
                </div>

                <div class="mt-8">
                    {{ $recentPosts->links() }}
                </div>
                @endif
            </div>

            <!-- Sidebar -->
            <aside class="space-y-6">

                <!-- Articles populaires -->
                @if($popularPosts->isNotEmpty())
                <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-5">
                    <h3 class="font-semibold text-gray-900 dark:text-gray-100 mb-4">Les plus lus</h3>
                    <div class="space-y-3">
                        @foreach($popularPosts as $pop)
                        <a href="{{ route('blog.show', $pop) }}" class="flex gap-3 group">
                            @if($pop->image)
                            <img src="{{ $pop->image_url }}" alt="" class="w-14 h-14 rounded-lg object-cover flex-shrink-0">
                            @else
                            <div class="w-14 h-14 rounded-lg bg-gray-100 dark:bg-gray-700 flex-shrink-0"></div>
                            @endif
                            <div>
                                <p class="text-sm font-medium text-gray-800 dark:text-gray-200 group-hover:text-indigo-600 dark:group-hover:text-indigo-400 transition leading-snug line-clamp-2">{{ $pop->title }}</p>
                                <p class="text-xs text-gray-400 mt-0.5">{{ number_format($pop->views_count) }} vues</p>
                            </div>
                        </a>
                        @endforeach
                    </div>
                </div>
                @endif

                <!-- Catégories -->
                @if($categories->isNotEmpty())
                <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-5">
                    <h3 class="font-semibold text-gray-900 dark:text-gray-100 mb-4">Catégories</h3>
                    <div class="space-y-1">
                        @foreach($categories->filter(fn($c) => $c->blog_posts_count > 0) as $cat)
                        <a href="{{ route('blog.category', $cat->slug) }}"
                           class="flex items-center justify-between px-3 py-1.5 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition text-sm text-gray-700 dark:text-gray-300">
                            <span>{{ $cat->displayName('blog') }}</span>
                            <span class="text-xs text-gray-400">{{ $cat->blog_posts_count }}</span>
                        </a>
                        @endforeach
                    </div>
                </div>
                @endif

                <!-- Tags populaires -->
                @if($popularTags->isNotEmpty())
                <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-5">
                    <h3 class="font-semibold text-gray-900 dark:text-gray-100 mb-4">Tags populaires</h3>
                    <div class="flex flex-wrap gap-2">
                        @foreach($popularTags as $tag)
                        <a href="{{ route('blog.tag', $tag->slug) }}"
                           class="text-xs px-2.5 py-1 rounded-full bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300 hover:bg-indigo-100 dark:hover:bg-indigo-900/40 hover:text-indigo-700 dark:hover:text-indigo-400 transition">
                            #{{ $tag->name }}
                        </a>
                        @endforeach
                    </div>
                </div>
                @endif

            </aside>
        </div>
    </x-page-container>
</x-app-layout>
