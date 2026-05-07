<x-app-layout>
    <x-slot name="title">{{ $tag->name }} — Blog BouclePro</x-slot>

    <div class="max-w-7xl mx-auto px-4 py-8">
        <div class="mb-6">
            <a href="{{ route('blog.index') }}" class="text-sm text-gray-500 hover:text-indigo-600 dark:hover:text-indigo-400">← Blog</a>
        </div>
        <div class="flex items-center gap-3 mb-2">
            <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-indigo-100 dark:bg-indigo-900/30 text-indigo-700 dark:text-indigo-300">#{{ $tag->name }}</span>
        </div>
        <p class="text-gray-500 dark:text-gray-400 mb-8">{{ $posts->total() }} article(s) avec ce tag</p>

        @if($posts->isEmpty())
        <p class="text-gray-500 dark:text-gray-400">Aucun article avec ce tag.</p>
        @else
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
            @foreach($posts as $post)
            <article class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden hover:shadow-md transition">
                @if($post->image)
                <a href="{{ route('blog.show', $post) }}"><img src="{{ $post->image_url }}" alt="{{ $post->title }}" class="w-full h-40 object-cover"></a>
                @endif
                <div class="p-5">
                    <h2 class="font-semibold text-gray-900 dark:text-gray-100 mb-2">
                        <a href="{{ route('blog.show', $post) }}" class="hover:text-indigo-600 dark:hover:text-indigo-400 transition">{{ $post->title }}</a>
                    </h2>
                    @if($post->summary)
                    <p class="text-sm text-gray-500 dark:text-gray-400 mb-3 line-clamp-2">{{ $post->summary }}</p>
                    @endif
                    <div class="flex items-center gap-2 text-xs text-gray-400">
                        <img src="{{ $post->user->avatar_url }}" alt="" class="w-4 h-4 rounded-full">
                        <span>{{ $post->user->name }}</span>
                        @if($post->read_time)<span>· {{ $post->read_time }} min</span>@endif
                    </div>
                </div>
            </article>
            @endforeach
        </div>
        <div class="mt-8">{{ $posts->links() }}</div>
        @endif
    </div>
</x-app-layout>
