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
    <x-slot name="title">{{ $category->displayName('blog') }} {{ __('blog.blog_brand_suffix') }}</x-slot>

    <!-- Desktop topbar -->
    <div class="hidden md:flex items-center gap-3 px-4 sm:px-6 lg:px-8 py-3 border-b border-gray-200 dark:border-gray-700 bg-[var(--bp-surface)] sticky top-0 z-30">
        <a href="{{ $_blogRoute('index') }}" class="inline-flex h-8 w-8 items-center justify-center rounded-full bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-600 flex-shrink-0" aria-label="{{ __('blog.back_to_blog') }}">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
        </a>
        <span class="truncate text-sm font-medium text-gray-900 dark:text-gray-100">{{ $category->displayName('blog') }}</span>
    </div>

    <x-page-container>
        <h1 class="text-xl sm:text-2xl font-bold text-gray-900 dark:text-gray-100 mb-2">{{ $category->displayName('blog') }}</h1>
        <p class="text-gray-500 dark:text-gray-400 mb-8">{{ __('blog.post_count_category', ['count' => $posts->total()]) }}</p>

        @if($posts->isEmpty())
        <p class="text-gray-500 dark:text-gray-400">{{ __('blog.empty_category') }}</p>
        @else
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
            @foreach($posts as $post)
            <article class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden hover:shadow-md transition">
                @if($post->image)
                <a href="{{ $_blogRoute('show', ['post' => $post]) }}"><img src="{{ $post->image_url }}" alt="{{ $post->title }}" class="w-full h-40 object-cover"></a>
                @endif
                <div class="p-5">
                    <h2 class="font-semibold text-gray-900 dark:text-gray-100 mb-2">
                        <a href="{{ $_blogRoute('show', ['post' => $post]) }}" class="hover:text-indigo-600 dark:hover:text-indigo-400 transition">{{ $post->title }}</a>
                    </h2>
                    @if($post->summary)
                    <p class="text-sm text-gray-500 dark:text-gray-400 mb-3 line-clamp-2">{{ $post->summary }}</p>
                    @endif
                    <a href="{{ $_profileRoute($post->user) }}" class="flex items-center gap-2 text-xs text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition">
                        <img src="{{ $post->user->avatar_url }}" alt="" class="w-4 h-4 rounded-full">
                        <span>{{ $post->user->fullName }}</span>
                        @if($post->read_time)<span>· {{ __('blog.read_time', ['count' => $post->read_time]) }}</span>@endif
                    </a>
                </div>
            </article>
            @endforeach
        </div>
        <div class="mt-8">{{ $posts->links() }}</div>
        @endif
    </x-page-container>
</x-app-layout>
