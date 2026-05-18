<x-app-layout>
    <div class="max-w-5xl mx-auto px-4 py-10">

        <div class="flex items-center justify-between mb-8">
            <div>
                <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">Les Boucles</h1>
                <p class="text-gray-500 dark:text-gray-400 mt-1 text-sm">Communautés thématiques ou professionnelles qui utilisent la plateforme</p>
            </div>
            <a href="{{ route('partenaires.request.create') }}"
               class="inline-flex items-center gap-2 px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold rounded-lg transition">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                </svg>
                Devenir partenaire
            </a>
        </div>

        @if($communities->isEmpty())
        <p class="text-gray-400 text-center py-16">Aucune boucle disponible pour le moment.</p>
        @else
        <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-5">
            @foreach($communities as $community)
            <a href="{{ route('community.home', ['community' => $community->slug]) }}"
               class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden hover:shadow-md hover:border-indigo-300 dark:hover:border-indigo-600 transition group">

                @if($community->hero_image_url)
                <div class="h-28 bg-cover bg-center" style="background-image: url('{{ $community->hero_image_url }}')"></div>
                @else
                <div class="h-28 flex items-center justify-center" style="background-color: {{ $community->primary_color ?? '#6366f1' }}">
                    <span class="text-white text-3xl font-bold">{{ mb_substr($community->name, 0, 1) }}</span>
                </div>
                @endif

                <div class="p-4">
                    <div class="flex items-center justify-between mb-1">
                        <h2 class="font-semibold text-gray-900 dark:text-gray-100 group-hover:text-indigo-600 dark:group-hover:text-indigo-400 transition">{{ $community->name }}</h2>
                        @if(!$community->is_public)
                        <span class="text-xs text-gray-400 flex items-center gap-1">
                            <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                            Privée
                        </span>
                        @endif
                    </div>
                    @if($community->description)
                    <p class="text-sm text-gray-500 dark:text-gray-400 line-clamp-2">{{ $community->description }}</p>
                    @endif
                </div>
            </a>
            @endforeach
        </div>
        @endif

    </div>
</x-app-layout>
