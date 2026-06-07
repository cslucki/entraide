<x-app-layout>
    <x-slot name="title">{{ $post->meta_title ?: $post->title }} — Blog BouclePro</x-slot>

    <x-page-container>
        <div class="grid grid-cols-1 lg:grid-cols-4 gap-8">

            <!-- Article -->
            <article class="lg:col-span-3">

                <!-- Breadcrumb -->
                <nav class="flex items-center gap-2 text-sm text-gray-500 dark:text-gray-400 mb-6">
                    <a href="{{ route('blog.index') }}" class="hover:text-indigo-600 dark:hover:text-indigo-400">Blog</a>
                    @if($post->category)
                    <span>/</span>
                    <a href="{{ route('blog.category', $post->category->slug) }}" class="hover:text-indigo-600 dark:hover:text-indigo-400">{{ $post->category->displayName('blog') }}</a>
                    @endif
                    <span>/</span>
                    <span class="text-gray-700 dark:text-gray-300 truncate max-w-xs">{{ $post->title }}</span>
                </nav>

                <!-- Image de couverture -->
                @if($post->image)
                <div class="mb-8 rounded-xl overflow-hidden">
                    <img src="{{ $post->image_url }}" alt="{{ $post->title }}" class="w-full max-h-72 object-cover">
                </div>
                @endif

                <!-- Catégories -->
                @if($post->category)
                <div class="flex flex-wrap gap-2 mb-4">
                    <a href="{{ route('blog.category', $post->category->slug) }}"
                       class="text-xs font-medium px-2.5 py-1 rounded-full bg-indigo-50 dark:bg-indigo-900/30 text-indigo-600 dark:text-indigo-400 hover:bg-indigo-100 transition">
                        {{ $post->category->displayName('blog') }}
                    </a>
                </div>
                @endif

                <!-- Titre -->
                <h1 class="text-3xl font-bold text-gray-900 dark:text-gray-100 mb-4 leading-tight">{{ $post->title }}</h1>

                <!-- Auteur + méta -->
                <div class="flex items-center justify-between mb-4 pb-4 border-b border-gray-200 dark:border-gray-700">
                    <a href="{{ route('profile.show', $post->user) }}" class="flex items-center gap-3 group">
                        <img src="{{ $post->user->avatar_url }}" alt="" class="w-9 h-9 rounded-full">
                        <div>
                            <p class="text-sm font-medium text-gray-900 dark:text-gray-100 group-hover:text-indigo-600 dark:group-hover:text-indigo-400 transition">{{ $post->user->name }}</p>
                            <p class="text-xs text-gray-400">{{ $post->published_at?->translatedFormat('d F Y') }}</p>
                        </div>
                    </a>
                    <div class="flex items-center gap-4 text-sm text-gray-400 dark:text-gray-500">
                        @if($post->read_time)
                        <span class="flex items-center gap-1">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                            {{ $post->read_time }} min de lecture
                        </span>
                        @endif
                        <span class="flex items-center gap-1">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                            {{ number_format($post->views_count) }}
                        </span>
                    </div>
                </div>

                <!-- Boutons auteur / admin -->
                @auth
                @if(auth()->id() === $post->user_id || auth()->user()->is_admin)
                <div class="flex items-center gap-3 mb-6">
                    @if($post->status !== 'published')
                    <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium bg-yellow-100 dark:bg-yellow-900/30 text-yellow-700 dark:text-yellow-400">
                        {{ ['draft' => 'Brouillon', 'pending' => 'En attente', 'archived' => 'Archivé'][$post->status] ?? $post->status }}
                    </span>
                    <form action="{{ route('blog.publish', $post) }}" method="POST">
                        @csrf @method('PATCH')
                        <button type="submit"
                            class="inline-flex items-center gap-1.5 px-4 py-1.5 bg-green-600 hover:bg-green-700 text-white text-sm font-semibold rounded-lg transition">
                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                            Publier
                        </button>
                    </form>
                    @endif
                    <a href="{{ route('blog.edit', $post) }}"
                       class="inline-flex items-center gap-1.5 px-4 py-1.5 border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700 text-sm font-medium rounded-lg transition">
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/></svg>
                        Modifier
                    </a>
                </div>
                @endif
                @endauth

                <!-- Contenu -->
                <div class="max-w-none mb-8 text-gray-800 dark:text-gray-200 leading-relaxed text-base prose prose-sm dark:prose-invert max-w-none">
                    {!! markdown($post->content) !!}
                </div>

                <!-- Tags -->
                @if($post->tags->isNotEmpty())
                <div class="flex flex-wrap gap-2 mb-8">
                    @foreach($post->tags as $tag)
                    <a href="{{ route('blog.tag', $tag->slug) }}"
                       class="text-xs px-2.5 py-1 rounded-full bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300 hover:bg-indigo-100 dark:hover:bg-indigo-900/40 hover:text-indigo-700 transition">
                        #{{ $tag->name }}
                    </a>
                    @endforeach
                </div>
                @endif

                <!-- Likes -->
                <div class="flex items-center gap-4 py-6 border-t border-b border-gray-200 dark:border-gray-700 mb-8">
                    @auth
                    <button id="like-btn" data-post-id="{{ $post->id }}" data-liked="{{ $isLiked ? 'true' : 'false' }}"
                            class="flex items-center gap-2 px-4 py-2 rounded-lg border transition text-sm font-medium
                                {{ $isLiked ? 'bg-red-50 dark:bg-red-900/20 border-red-200 dark:border-red-800 text-red-600 dark:text-red-400' : 'border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-300 hover:border-red-300' }}">
                        <svg class="w-4 h-4" fill="{{ $isLiked ? 'currentColor' : 'none' }}" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"/>
                        </svg>
                        <span id="like-count">{{ $post->likes_count ?? $post->likes()->count() }}</span> j'aime
                    </button>
                    @else
                    <span class="flex items-center gap-2 text-sm text-gray-400">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"/></svg>
                        {{ $post->likes()->count() }} j'aime
                    </span>
                    @endauth
                </div>

                <!-- Commentaires -->
                <section id="commentaires">
                    <h2 class="text-xl font-semibold text-gray-900 dark:text-gray-100 mb-6">
                        Commentaires ({{ $post->comments->count() }})
                    </h2>

                    @auth
                    <form action="{{ route('blog.comment.store', $post) }}" method="POST" class="mb-8">
                        @csrf
                        <textarea name="content" rows="3" placeholder="Votre commentaire…" required
                            class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-indigo-500 text-sm"></textarea>
                        <div class="mt-2 flex justify-end">
                            <button type="submit" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold rounded-lg transition">
                                Commenter
                            </button>
                        </div>
                    </form>
                    @else
                    <p class="mb-6 text-sm text-gray-500 dark:text-gray-400">
                        <a href="{{ route('login') }}" class="text-indigo-600 dark:text-indigo-400 hover:underline">Connectez-vous</a> pour commenter.
                    </p>
                    @endauth

                    <div class="space-y-6">
                        @foreach($post->comments as $comment)
                        <div class="flex gap-3">
                            <img src="{{ $comment->user->avatar_url }}" alt="" class="w-8 h-8 rounded-full flex-shrink-0 mt-0.5">
                            <div class="flex-1">
                                <div class="bg-gray-50 dark:bg-gray-700/50 rounded-xl px-4 py-3">
                                    <div class="flex items-center justify-between mb-1">
                                        <span class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ $comment->user->name }}</span>
                                        <span class="text-xs text-gray-400">{{ $comment->created_at->diffForHumans() }}</span>
                                    </div>
                                    <p class="text-sm text-gray-700 dark:text-gray-300">{{ $comment->content }}</p>
                                </div>
                                <div class="flex items-center gap-3 mt-1.5 px-1">
                                    @auth
                                    <button x-data x-on:click="$el.nextElementSibling.classList.toggle('hidden')" class="text-xs text-gray-400 hover:text-indigo-500 transition">Répondre</button>
                                    <div class="hidden mt-3 w-full">
                                        <form action="{{ route('blog.comment.store', $post) }}" method="POST">
                                            @csrf
                                            <input type="hidden" name="parent_id" value="{{ $comment->id }}">
                                            <textarea name="content" rows="2" placeholder="Votre réponse…" required
                                                class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-indigo-500 text-sm"></textarea>
                                            <div class="mt-1 flex justify-end">
                                                <button type="submit" class="px-3 py-1.5 bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-semibold rounded-lg transition">Répondre</button>
                                            </div>
                                        </form>
                                    </div>
                                    @if(auth()->id() === $comment->user_id || auth()->user()->is_admin)
                                    <form action="{{ route('blog.comment.destroy', $comment) }}" method="POST" class="inline">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="text-xs text-red-400 hover:text-red-600 transition" onclick="return confirm('Supprimer ce commentaire ?')">Supprimer</button>
                                    </form>
                                    @endif
                                    @endauth
                                </div>

                                <!-- Réponses -->
                                @foreach($comment->replies as $reply)
                                <div class="flex gap-3 mt-3 ml-4">
                                    <img src="{{ $reply->user->avatar_url }}" alt="" class="w-6 h-6 rounded-full flex-shrink-0 mt-0.5">
                                    <div class="flex-1 bg-gray-50 dark:bg-gray-700/50 rounded-xl px-3 py-2">
                                        <div class="flex items-center justify-between mb-1">
                                            <span class="text-xs font-medium text-gray-900 dark:text-gray-100">{{ $reply->user->name }}</span>
                                            <span class="text-xs text-gray-400">{{ $reply->created_at->diffForHumans() }}</span>
                                        </div>
                                        <p class="text-xs text-gray-700 dark:text-gray-300">{{ $reply->content }}</p>
                                    </div>
                                </div>
                                @endforeach
                            </div>
                        </div>
                        @endforeach
                    </div>
                </section>
            </article>

            <!-- Sidebar -->
            <aside class="space-y-6">
                <!-- Auteur -->
                <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-5 text-center">
                    <img src="{{ $post->user->avatar_url }}" alt="" class="w-16 h-16 rounded-full mx-auto mb-3">
                    <a href="{{ route('profile.show', $post->user) }}" class="font-semibold text-gray-900 dark:text-gray-100 hover:text-indigo-600 dark:hover:text-indigo-400 transition">
                        {{ $post->user->name }}
                    </a>
                    @if($post->user->bio)
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-2 line-clamp-3">{{ $post->user->bio }}</p>
                    @endif
                    @if($post->user->location)
                    <p class="text-xs text-gray-400 mt-1">{{ $post->user->location }}</p>
                    @endif
                </div>

                <!-- Articles liés -->
                @if($relatedPosts->isNotEmpty())
                <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-5">
                    <h3 class="font-semibold text-gray-900 dark:text-gray-100 mb-4">Articles liés</h3>
                    <div class="space-y-3">
                        @foreach($relatedPosts as $related)
                        <a href="{{ route('blog.show', $related) }}" class="block group">
                            <p class="text-sm font-medium text-gray-800 dark:text-gray-200 group-hover:text-indigo-600 dark:group-hover:text-indigo-400 transition leading-snug">{{ $related->title }}</p>
                            <p class="text-xs text-gray-400 mt-0.5">{{ $related->user->name }} · {{ $related->read_time }} min</p>
                        </a>
                        @endforeach
                    </div>
                </div>
                @endif
            </aside>
        </div>
    </x-page-container>

    @auth
    <script>
    document.getElementById('like-btn')?.addEventListener('click', function() {
        const btn = this;
        fetch('{{ route('likes.toggle') }}', {
            method: 'POST',
            headers: {'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}'},
            body: JSON.stringify({likeable_type: 'blog_post', likeable_id: '{{ $post->id }}'})
        })
        .then(r => r.json())
        .then(data => {
            document.getElementById('like-count').textContent = data.count;
            btn.dataset.liked = data.liked ? 'true' : 'false';
            if (data.liked) {
                btn.classList.add('bg-red-50', 'dark:bg-red-900/20', 'border-red-200', 'dark:border-red-800', 'text-red-600', 'dark:text-red-400');
                btn.querySelector('svg').setAttribute('fill', 'currentColor');
            } else {
                btn.classList.remove('bg-red-50', 'dark:bg-red-900/20', 'border-red-200', 'dark:border-red-800', 'text-red-600', 'dark:text-red-400');
                btn.querySelector('svg').setAttribute('fill', 'none');
            }
        });
    });
    </script>
    @endauth
</x-app-layout>
