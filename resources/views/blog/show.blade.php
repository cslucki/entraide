<x-app-layout>
    @php
        $_blogRoute = function ($name, $parameters = []) {
            $orgSlug = request()->route('organization');
            if (! $orgSlug || ! Route::has('organization.blog.'.$name)) {
                return route('blog.'.$name, $parameters);
            }
            return route('organization.blog.'.$name, array_merge(['organization' => $orgSlug], $parameters));
        };
        $_profileRoute = function ($user) {
            $orgSlug = request()->route('organization');
            if ($orgSlug && Route::has('organization.profile.show')) {
                return route('organization.profile.show', ['organization' => $orgSlug, 'user' => $user]);
            }
            return route('profile.show', $user);
        };
    @endphp
    <x-slot name="title">{{ $post->meta_title ?: $post->title }} {{ __('blog.blog_brand_suffix') }}</x-slot>

    <!-- Desktop topbar -->
    <div class="hidden md:flex items-center gap-3 px-4 sm:px-6 lg:px-8 py-3 border-b border-gray-200 dark:border-gray-700 bg-[var(--bp-surface)] sticky top-0 z-30">
        <a href="{{ $_blogRoute('index') }}" class="inline-flex h-8 w-8 items-center justify-center rounded-full bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-600 flex-shrink-0" aria-label="{{ __('blog.back_to_blog') }}">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
        </a>
        <a href="{{ $_blogRoute('my-posts') }}" class="text-xs text-gray-500 dark:text-gray-400 hover:text-indigo-600 dark:hover:text-indigo-400 hover:underline ml-2 shrink-0">
            {{ __('blog.back_to_my_articles') }}
        </a>
        <span class="truncate text-sm font-medium text-gray-900 dark:text-gray-100">{{ $post->title }}</span>
    </div>

    <x-page-container>
        @php
            $hasToc = ! empty($headers);
            $usesNavigationToc = $hasToc && $post->toc_navigation_enabled;
        @endphp

        <div class="grid grid-cols-1 lg:grid-cols-4 gap-8">

            <!-- Article -->
            <article class="lg:col-span-3">



                <!-- Image de couverture -->
                @if($post->image)
                <div class="mb-8 rounded-xl overflow-hidden">
                    <img src="{{ $post->image_url }}" alt="{{ $post->title }}" class="w-full max-h-72 object-cover">
                </div>
                @endif

                <!-- Catégories -->
                @if($post->category)
                <div class="flex flex-wrap gap-2 mb-4">
                    <a href="{{ $_blogRoute('category', ['slug' => $post->category->slug]) }}"
                       class="text-xs font-medium px-2.5 py-1 rounded-full bg-indigo-50 dark:bg-indigo-900/30 text-indigo-600 dark:text-indigo-400 hover:bg-indigo-100 transition">
                        {{ $post->category->displayName('blog') }}
                    </a>
                </div>
                @endif

                <!-- Titre -->
                <h1 class="text-3xl font-bold text-gray-900 dark:text-gray-100 mb-4 leading-tight">{{ $post->title }}</h1>

                <!-- Auteur + méta -->
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-4 pb-4 border-b border-gray-200 dark:border-gray-700">
                    <a href="{{ $_profileRoute($post->user) }}" class="flex items-center gap-3 group min-w-0">
                        <img src="{{ $post->user->avatar_url }}" alt="" class="w-9 h-9 rounded-full flex-shrink-0">
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-gray-900 dark:text-gray-100 group-hover:text-indigo-600 dark:group-hover:text-indigo-400 transition truncate">{{ $post->user->fullName }}</p>
                            <p class="text-xs text-gray-400">{{ $post->published_at?->translatedFormat('d F Y') }}</p>
                        </div>
                    </a>
                    <div class="flex items-center gap-4 text-sm text-gray-400 dark:text-gray-500 flex-shrink-0">
                        @if($post->read_time)
                        <span class="flex items-center gap-1">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                            {{ __('blog.read_time', ['count' => $post->read_time]) }}
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
                        {{ ['draft' => __('blog.status_draft'), 'pending' => __('blog.status_pending'), 'archived' => __('blog.status_archived')][$post->status] ?? $post->status }}
                    </span>
                    <form action="{{ $_blogRoute('publish', ['post' => $post]) }}" method="POST">
                        @csrf @method('PATCH')
                        <button type="submit"
                            class="inline-flex items-center gap-1.5 px-4 py-1.5 bg-green-600 hover:bg-green-700 text-white text-sm font-semibold rounded-lg transition">
                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                            {{ __('blog.btn_publish') }}
                        </button>
                    </form>
                    @endif
                    <a href="{{ $_blogRoute('edit', ['post' => $post]) }}"
                       class="inline-flex items-center gap-1.5 px-4 py-1.5 border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700 text-sm font-medium rounded-lg transition">
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/></svg>
                        {{ __('blog.btn_edit') }}
                    </a>
                </div>
                @endif
                @endauth

                <!-- Contenu -->
                @if($hasToc && ! $usesNavigationToc)
                <div x-data="planToc({
                    headings: @js($headers),
                    i18n: { title: @js(__('blog.toc_publication_title')), expandAll: @js(__('blog.plan_expand_all')), collapseAll: @js(__('blog.plan_collapse_all')) },
                })" data-testid="blog-toc" class="mb-8 rounded-2xl border border-indigo-100 bg-indigo-50/70 p-4 shadow-sm dark:border-indigo-900/50 dark:bg-indigo-950/20">
                    <div class="flex items-center justify-between mb-3">
                        <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100" x-text="i18n.title"></h3>
                        <div class="flex items-center gap-2" x-show="tree.length > 0">
                            <button type="button" @click="expandAll" class="text-xs text-gray-500 hover:text-indigo-600 dark:hover:text-indigo-400 transition" x-text="i18n.expandAll"></button>
                            <span class="text-xs text-gray-300">|</span>
                            <button type="button" @click="collapseAll" class="text-xs text-gray-500 hover:text-indigo-600 dark:hover:text-indigo-400 transition" x-text="i18n.collapseAll"></button>
                        </div>
                    </div>
                    <template x-if="tree.length === 0">
                        <p class="text-sm text-gray-400 dark:text-gray-500 py-2">{{ __('blog.plan_empty') }}</p>
                    </template>
                    <ul class="space-y-0.5" x-show="tree.length > 0">
                        <template x-for="(h, i) in flatVisible" :key="h.id">
                            <li>
                                <a :href="'#' + h.id"
                                   :class="{
                                       'text-sm font-semibold text-gray-800 dark:text-gray-100': h.level === 2,
                                       'text-xs text-gray-500 dark:text-gray-400 pl-3': h.level === 3,
                                       'text-xs text-gray-400 dark:text-gray-500 pl-5': h.level >= 4,
                                   }"
                                   class="block rounded-r-lg border-l-2 border-transparent py-1 hover:border-indigo-400 hover:bg-indigo-50/70 hover:text-indigo-600 dark:hover:bg-indigo-950/30 dark:hover:text-indigo-400 transition"
                                   @click.prevent="scrollTo(h.id)"
                                >
                                    <span class="flex items-center gap-1 truncate">
                                        <template x-if="childCount(h) > 0">
                                            <span @click.prevent.stop="toggle(h)" class="shrink-0 w-3 h-3 flex items-center justify-center cursor-pointer hover:text-indigo-500">
                                                <svg class="w-2.5 h-2.5 transition-transform" :class="{ 'rotate-90': !isCollapsed(h) }" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                                            </span>
                                        </template>
                                        <template x-if="childCount(h) === 0">
                                            <span class="shrink-0 w-3 h-3"></span>
                                        </template>
                                        <span class="truncate" x-text="h.text"></span>
                                    </span>
                                </a>
                            </li>
                        </template>
                    </ul>
                </div>

                    <!-- Back to outline (desktop only, normal mode) -->
                    <div x-data="{ showBackBtn: false }"
                         x-init="
                             const tocEl = $el.closest('article').querySelector('[data-testid=&quot;blog-toc&quot;]');
                             if (tocEl) {
                                 new IntersectionObserver((entries) => { showBackBtn = !entries[0].isIntersecting; }, { threshold: 0 }).observe(tocEl);
                             }
                         "
                         x-show="showBackBtn"
                         x-transition:enter="transition ease-out duration-300"
                         x-transition:enter-start="opacity-0 translate-y-2"
                         x-transition:enter-end="opacity-100 translate-y-0"
                         x-transition:leave="transition ease-in duration-200"
                         x-transition:leave-start="opacity-100 translate-y-0"
                         x-transition:leave-end="opacity-0 translate-y-2"
                         @click="document.querySelector('[data-testid=&quot;blog-toc&quot;]')?.scrollIntoView({ behavior: 'smooth', block: 'start' })"
                         class="hidden lg:flex fixed bottom-6 right-6 z-40 items-center gap-2 cursor-pointer rounded-full bg-white dark:bg-gray-800 px-5 py-2.5 shadow-xl ring-1 ring-gray-200 dark:ring-gray-700 backdrop-blur text-sm font-medium text-gray-700 dark:text-gray-300 hover:text-indigo-600 dark:hover:text-indigo-400 hover:shadow-2xl transition-all select-none"
                         role="button"
                         tabindex="0"
                    >
                        <svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 10l7-7m0 0l7 7m-7-7v18"/></svg>
                        <span>{{ __('blog.back_to_outline') }}</span>
                    </div>

                @elseif($usesNavigationToc)
                <div x-data="planToc({
                    headings: @js($headers),
                    i18n: { title: @js(__('blog.toc_publication_title')), expandAll: @js(__('blog.plan_expand_all')), collapseAll: @js(__('blog.plan_collapse_all')) },
                })" class="mb-8 rounded-2xl border border-indigo-100 bg-white p-4 shadow-sm dark:border-indigo-900/50 dark:bg-gray-900 lg:hidden">
                    <div class="flex items-center justify-between mb-3">
                        <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100" x-text="i18n.title"></h3>
                        <div class="flex items-center gap-2" x-show="tree.length > 0">
                            <button type="button" @click="expandAll" class="text-xs text-gray-500 hover:text-indigo-600 dark:hover:text-indigo-400 transition" x-text="i18n.expandAll"></button>
                            <span class="text-xs text-gray-300">|</span>
                            <button type="button" @click="collapseAll" class="text-xs text-gray-500 hover:text-indigo-600 dark:hover:text-indigo-400 transition" x-text="i18n.collapseAll"></button>
                        </div>
                    </div>
                    <ul class="space-y-0.5" x-show="tree.length > 0">
                        <template x-for="(h, i) in flatVisible" :key="h.id">
                            <li>
                                <a :href="'#' + h.id" :style="{ paddingLeft: ((h.level - 1) * 12) + 'px' }"
                                   class="flex items-center gap-1 text-sm text-gray-600 dark:text-gray-400 hover:text-indigo-600 dark:hover:text-indigo-400 transition py-0.5 truncate"
                                   @click.prevent="scrollTo(h.id)"
                                >
                                    <template x-if="childCount(h) > 0">
                                        <span @click.prevent.stop="toggle(h)" class="shrink-0 w-3 h-3 flex items-center justify-center cursor-pointer hover:text-indigo-500">
                                            <svg class="w-2.5 h-2.5 transition-transform" :class="{ 'rotate-90': !isCollapsed(h) }" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                                        </span>
                                    </template>
                                    <template x-if="childCount(h) === 0">
                                        <span class="shrink-0 w-3 h-3"></span>
                                    </template>
                                    <span class="truncate" x-text="h.text"></span>
                                </a>
                            </li>
                        </template>
                    </ul>
                </div>
                @endif

                @if($hasToc)
                <script>
                function planToc(config) {
                    return {
                        headings: config.headings || [],
                        i18n: config.i18n || {},
                        _collapsed: {},
                        tree: [],
                        flatVisible: [],

                        init() {
                            this._buildTree();
                            this._updateFlatVisible();
                        },

                        _buildTree() {
                            const flat = this.headings;
                            const tree = [];
                            const stack = [];
                            flat.forEach((h) => {
                                while (stack.length > 0 && stack[stack.length - 1].level >= h.level) {
                                    stack.pop();
                                }
                                const item = Object.assign({}, h, { _children: 0 });
                                if (stack.length > 0) {
                                    stack[stack.length - 1]._children++;
                                }
                                if (stack.length === 0) {
                                    tree.push(item);
                                }
                                stack.push(item);
                            });
                            this.tree = tree;
                            const ch = {};
                            (function walk(items) {
                                items.forEach((item) => {
                                    ch[item.id] = item._children;
                                });
                            })(tree);
                            this._childrenMap = ch;
                        },

                        childCount(h) {
                            return this._childrenMap?.[h.id] ?? 0;
                        },

                        isCollapsed(h) {
                            return this._collapsed[h.id] === true;
                        },

                        toggle(h) {
                            if (this.isCollapsed(h)) {
                                delete this._collapsed[h.id];
                            } else {
                                this._collapsed[h.id] = true;
                            }
                            this._updateFlatVisible();
                        },

                        expandAll() {
                            this._collapsed = {};
                            this._updateFlatVisible();
                        },

                        collapseAll() {
                            const ids = {};
                            const collect = (items) => {
                                items.forEach((h) => {
                                    if (h._children > 0) {
                                        ids[h.id] = true;
                                        const children = this._getChildren(h);
                                        collect(children);
                                    }
                                });
                            };
                            collect(this.tree);
                            this._collapsed = ids;
                            this._updateFlatVisible();
                        },

                        _getChildren(h) {
                            const idx = this.headings.findIndex((x) => x.id === h.id);
                            if (idx === -1) return [];
                            const level = h.level;
                            const children = [];
                            for (let i = idx + 1; i < this.headings.length; i++) {
                                if (this.headings[i].level <= level) break;
                                if (this.headings[i].level === level + 1) children.push(this.headings[i]);
                            }
                            return children;
                        },

                        _updateFlatVisible() {
                            const collapsedAncestors = [];
                            this.headings.forEach((h) => {
                                const toRemove = [];
                                for (const level of collapsedAncestors) {
                                    if (level >= h.level) toRemove.push(level);
                                }
                                toRemove.forEach(l => {
                                    const idx = collapsedAncestors.indexOf(l);
                                    if (idx > -1) collapsedAncestors.splice(idx, 1);
                                });
                                h._hidden = collapsedAncestors.length > 0;
                                const childCount = this._childrenMap?.[h.id] ?? 0;
                                if (childCount > 0 && this.isCollapsed(h)) {
                                    collapsedAncestors.push(h.level);
                                }
                            });
                            this.flatVisible = this.headings.filter(h => !h._hidden);
                        },

                        scrollTo(id) {
                            const el = document.getElementById(id);
                            if (el) {
                                el.scrollIntoView({ behavior: 'smooth', block: 'start' });
                            }
                        },
                    };
                }
                </script>
                @endif
                <div class="max-w-none mb-8 text-gray-800 dark:text-gray-200 leading-relaxed text-base prose prose-sm dark:prose-invert max-w-none [&_img[data-resized]]:!max-w-[70%] [&_pre]:bg-gray-100 [&_pre]:dark:bg-gray-900 [&_pre]:rounded-lg [&_pre]:p-3 [&_pre]:font-mono [&_pre]:text-xs [&_pre]:overflow-x-auto [&_code]:bg-gray-100 [&_code]:dark:bg-gray-900 [&_code]:rounded [&_code]:px-1 [&_code]:py-0.5 [&_code]:text-xs [&_pre_code]:bg-transparent [&_pre_code]:p-0 [&_table[data-borderless]_th]:border-none [&_table[data-borderless]_td]:border-none [&_table[data-borderless]_th]:bg-transparent [&_table[data-borderless]_th]:dark:bg-transparent [&_table]:max-w-full [&_table]:overflow-x-auto [&_table]:block md:[&_table]:table [&_div[data-media-embed]]:rounded-lg [&_div[data-media-embed]]:overflow-hidden [&_div[data-media-embed]]:my-4 [&_div[data-media-embed]_iframe]:w-full [&_div[data-media-embed]_iframe]:h-full [&_div[data-media-embed]_iframe]:border-0">
                    {!! $postContent !!}
                </div>

                <!-- Tags -->
                @if($post->tags->isNotEmpty())
                <div class="flex flex-wrap gap-2 mb-8">
                    @foreach($post->tags as $tag)
                    <a href="{{ $_blogRoute('tag', ['slug' => $tag->slug]) }}"
                       class="text-xs px-2.5 py-1 rounded-full bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300 hover:bg-indigo-100 dark:hover:bg-indigo-900/40 hover:text-indigo-700 transition">
                        #{{ ltrim($tag->name, '#') }}
                    </a>
                    @endforeach
                </div>
                @endif

                @php $commentCount = $post->comments->count(); $lastComment = $commentCount > 0 ? $post->comments->first() : null; @endphp
                <!-- Action bar compact (comments + likes) -->
                <div x-data="{ showComments: false }" class="mb-6">
                    <div class="flex items-center gap-4 py-4 border-t border-b border-gray-200 dark:border-gray-700">
                        @auth
                        <button type="button" @click="showComments = !showComments"
                                class="inline-flex items-center gap-1.5 text-sm text-gray-500 hover:text-indigo-600 dark:text-gray-400 dark:hover:text-indigo-400 transition">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>
                            <span>{{ $commentCount }}</span>
                        </button>
                        @else
                        <span class="inline-flex items-center gap-1.5 text-sm text-gray-400">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>
                            <span>{{ $commentCount }}</span>
                        </span>
                        @endauth

                        @auth
                        <button id="like-btn" data-post-id="{{ $post->id }}" data-liked="{{ $isLiked ? 'true' : 'false' }}"
                                class="inline-flex items-center gap-1.5 text-sm transition
                                {{ $isLiked ? 'text-red-500' : 'text-gray-500 hover:text-red-500 dark:text-gray-400 dark:hover:text-red-400' }}">
                            <svg class="w-4 h-4" fill="{{ $isLiked ? 'currentColor' : 'none' }}" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"/>
                            </svg>
                            <span id="like-count">{{ $post->likes_count }}</span>
                        </button>
                        @else
                        <span class="inline-flex items-center gap-1.5 text-sm text-gray-400">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"/></svg>
                            <span>{{ $post->likes_count }}</span>
                        </span>
                        @endauth
                    </div>

                    @auth
                    @if($lastComment)
                    <div class="flex items-start gap-2 mt-4">
                        <img src="{{ $lastComment->user->avatar_url }}" alt="" class="w-5 h-5 rounded-full flex-shrink-0 mt-0.5">
                        <div class="flex-1 min-w-0">
                            <span class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ $lastComment->user->fullName }}</span>
                            <p class="text-sm text-gray-600 dark:text-gray-300 line-clamp-2">{{ $lastComment->content }}</p>
                        </div>
                    </div>
                    @if($commentCount > 1)
                    <button type="button" @click="showComments = !showComments" class="mt-1 text-sm text-indigo-600 hover:underline dark:text-indigo-400">
                        {{ __('blog.view_comments', ['count' => $commentCount]) }}
                    </button>
                    @endif
                    @endif

                    <div x-show="showComments" x-cloak class="space-y-4 mt-6">
                        <form action="{{ $_blogRoute('comment.store', ['post' => $post]) }}" method="POST">
                            @csrf
                            <textarea name="content" rows="3" placeholder="{{ __('blog.placeholder_comment') }}" required
                                class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-indigo-500 text-sm"></textarea>
                            <div class="mt-2 flex justify-end">
                                <button type="submit" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold rounded-lg transition">{{ __('blog.btn_add_comment') }}</button>
                            </div>
                        </form>

                        @if($commentCount > 0)
                        <div class="space-y-6">
                            @foreach($post->comments as $comment)
                            <div class="flex gap-3">
                                <img src="{{ $comment->user->avatar_url }}" alt="" class="w-8 h-8 rounded-full flex-shrink-0 mt-0.5">
                                <div class="flex-1">
                                    <div class="bg-gray-50 dark:bg-gray-700/50 rounded-xl px-4 py-3">
                                        <div class="flex items-center justify-between mb-1">
                                            <span class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ $comment->user->fullName }}</span>
                                            <span class="text-xs text-gray-400">{{ $comment->created_at->diffForHumans() }}</span>
                                        </div>
                                        <p class="text-sm text-gray-700 dark:text-gray-300">{{ $comment->content }}</p>
                                    </div>
                                    <div class="flex items-center gap-3 mt-1.5 px-1">
                                        @auth
                                        <button x-data x-on:click="$el.nextElementSibling.classList.toggle('hidden')" class="text-xs text-gray-400 hover:text-indigo-500 transition">{{ __('blog.btn_reply') }}</button>
                                        <div class="hidden mt-3 w-full">
                        <form action="{{ $_blogRoute('comment.store', ['post' => $post]) }}" method="POST">
                                                @csrf
                                                <input type="hidden" name="parent_id" value="{{ $comment->id }}">
                                                <textarea name="content" rows="2" placeholder="{{ __('blog.placeholder_reply') }}" required
                                                    class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-indigo-500 text-sm"></textarea>
                                                <div class="mt-1 flex justify-end">
                                                    <button type="submit" class="px-3 py-1.5 bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-semibold rounded-lg transition">{{ __('blog.btn_reply') }}</button>
                                                </div>
                                            </form>
                                        </div>
                                        @if(auth()->id() === $comment->user_id || auth()->user()->is_admin)
                                        <form action="{{ $_blogRoute('comment.destroy', ['comment' => $comment]) }}" method="POST" class="inline">
                                            @csrf @method('DELETE')
                                            <button type="submit" class="text-xs text-red-400 hover:text-red-600 transition" onclick="return confirm('{{ __('blog.confirm_delete_comment') }}')">{{ __('blog.delete_post') }}</button>
                                        </form>
                                        @endif
                                        @endauth
                                    </div>

                                    @foreach($comment->replies as $reply)
                                    <div class="flex gap-3 mt-3 ml-4">
                                        <img src="{{ $reply->user->avatar_url }}" alt="" class="w-6 h-6 rounded-full flex-shrink-0 mt-0.5">
                                        <div class="flex-1 bg-gray-50 dark:bg-gray-700/50 rounded-xl px-3 py-2">
                                            <div class="flex items-center justify-between mb-1">
                                                <span class="text-xs font-medium text-gray-900 dark:text-gray-100">{{ $reply->user->fullName }}</span>
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
                        @endif
                    </div>
                    @endauth
                </div>

                @guest
                <p class="mb-6 text-sm text-gray-500 dark:text-gray-400">
                    <a href="{{ route('login') }}" class="text-indigo-600 dark:text-indigo-400 hover:underline">{{ __('blog.login') }}</a> {{ __('blog.guest_prompt') }}
                </p>
                @endguest
            </article>

            <!-- Sidebar -->
            <aside class="space-y-6">
                @if($usesNavigationToc)
                <nav x-data="planToc({
                    headings: @js($headers),
                    i18n: { title: @js(__('blog.toc_navigation_title')), expandAll: @js(__('blog.plan_expand_all')), collapseAll: @js(__('blog.plan_collapse_all')) },
                })" class="hidden rounded-2xl border border-indigo-100/80 bg-white/95 p-5 shadow-xl shadow-indigo-950/5 ring-1 ring-indigo-100/50 backdrop-blur dark:border-indigo-900/50 dark:bg-gray-900/95 dark:shadow-black/20 dark:ring-indigo-900/40 lg:sticky lg:top-24 lg:block lg:max-h-[calc(100vh-7rem)] lg:overflow-y-auto" aria-label="{{ __('blog.toc_navigation_title') }}">
                    <div class="mb-4">
                        <p class="text-xs font-semibold uppercase tracking-wide text-indigo-500 dark:text-indigo-400">{{ __('blog.toc_navigation_label') }}</p>
                        <h3 class="mt-1 text-sm font-semibold text-gray-900 dark:text-gray-100" x-text="i18n.title"></h3>
                    </div>
                    <ul class="space-y-1.5 border-l border-indigo-100 pl-3 dark:border-indigo-900/60" x-show="tree.length > 0">
                        <template x-for="h in flatVisible" :key="h.id">
                            <li>
                                <a :href="'#' + h.id"
                                   :class="{
                                       '-ml-3 pl-3 text-sm font-semibold text-gray-800 dark:text-gray-100': h.level === 2,
                                       'pl-3 text-xs text-gray-500 dark:text-gray-400': h.level === 3,
                                       'pl-6 text-xs text-gray-400 dark:text-gray-500': h.level >= 4,
                                   }"
                                   class="block rounded-r-lg border-l-2 border-transparent py-1 hover:border-indigo-400 hover:bg-indigo-50/70 hover:text-indigo-600 dark:hover:bg-indigo-950/30 dark:hover:text-indigo-400 transition"
                                   @click.prevent="scrollTo(h.id)"
                                >
                                    <span class="line-clamp-2" x-text="h.text"></span>
                                </a>
                            </li>
                        </template>
                    </ul>
                </nav>
                @endif

                <!-- Articles liés -->
                @if($relatedPosts->isNotEmpty())
                <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-5">
                    <h3 class="font-semibold text-gray-900 dark:text-gray-100 mb-4">{{ __('blog.related_posts') }}</h3>
                    <div class="space-y-3">
                        @foreach($relatedPosts as $related)
                        <a href="{{ $_blogRoute('show', ['post' => $related]) }}" class="block group">
                            <p class="text-sm font-medium text-gray-800 dark:text-gray-200 group-hover:text-indigo-600 dark:group-hover:text-indigo-400 transition leading-snug">{{ $related->title }}</p>
                            <p class="text-xs text-gray-400 mt-0.5">{{ $related->user->fullName }} · {{ __('blog.read_time', ['count' => $related->read_time]) }}</p>
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
        fetch('{{ organizationRoute('organization.likes.toggle', ['organization' => currentOrganization()?->slug ?? 'default']) }}', {
            method: 'POST',
            headers: {'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}'},
            body: JSON.stringify({likeable_type: 'blog_post', likeable_id: '{{ $post->id }}'})
        })
        .then(r => r.json())
        .then(data => {
            document.getElementById('like-count').textContent = data.count;
            btn.dataset.liked = data.liked ? 'true' : 'false';
            if (data.liked) {
                btn.classList.remove('text-gray-500', 'dark:text-gray-400');
                btn.classList.add('text-red-500');
                btn.querySelector('svg').setAttribute('fill', 'currentColor');
            } else {
                btn.classList.remove('text-red-500');
                btn.classList.add('text-gray-500', 'dark:text-gray-400');
                btn.querySelector('svg').setAttribute('fill', 'none');
            }
        });
    });
    </script>
    @endauth
</x-app-layout>
