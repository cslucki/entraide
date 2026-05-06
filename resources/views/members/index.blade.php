<x-app-layout>
    <div class="max-w-6xl mx-auto px-4 py-10">

        <div class="mb-8">
            <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">Annuaire des membres</h1>
            <p class="text-gray-500 dark:text-gray-400 mt-1 text-sm">{{ $members->total() }} membre{{ $members->total() > 1 ? 's' : '' }} inscrit{{ $members->total() > 1 ? 's' : '' }}</p>
        </div>

        <div class="grid sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-5">
            @foreach($members as $member)
            <a href="{{ route('profile.show', $member) }}"
               class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-5 hover:shadow-md hover:border-indigo-300 dark:hover:border-indigo-600 transition group flex flex-col gap-3">

                <!-- Avatar + nom -->
                <div class="flex items-center gap-3">
                    <div class="relative flex-shrink-0">
                        <img src="{{ $member->avatar_url }}" class="w-12 h-12 rounded-full" alt="">
                        @if($member->is_available)
                        <span class="absolute bottom-0 right-0 w-3 h-3 bg-green-500 rounded-full border-2 border-white dark:border-gray-800"></span>
                        @endif
                    </div>
                    <div class="min-w-0">
                        <p class="font-semibold text-gray-900 dark:text-gray-100 group-hover:text-indigo-600 dark:group-hover:text-indigo-400 transition truncate">{{ $member->name }}</p>
                        @if($member->location)
                        <p class="text-xs text-gray-400 truncate">📍 {{ $member->location }}</p>
                        @endif
                    </div>
                </div>

                <!-- Bio tronquée -->
                @if($member->bio)
                <p class="text-xs text-gray-500 dark:text-gray-400 leading-relaxed line-clamp-3">{{ $member->bio }}</p>
                @endif

                <!-- Contact info if public -->
                @if($member->show_email || ($member->show_phone && $member->phone))
                <div class="flex flex-col gap-1">
                    @if($member->show_email)
                    <div class="flex items-center gap-1.5 text-xs text-gray-500 truncate">
                        <svg class="w-3.5 h-3.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                        {{ $member->email }}
                    </div>
                    @endif
                    @if($member->show_phone && $member->phone)
                    <div class="flex items-center gap-1.5 text-xs text-gray-500">
                        <svg class="w-3.5 h-3.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/></svg>
                        {{ $member->phone }}
                    </div>
                    @endif
                </div>
                @endif

                <!-- Compteurs + catégories -->
                <div class="mt-auto pt-2 border-t border-gray-100 dark:border-gray-700 flex items-center gap-3 text-xs text-gray-400">
                    <span title="{{ $T['Services'] }} actifs">
                        <span class="font-semibold text-indigo-600 dark:text-indigo-400">{{ $member->active_services_count }}</span> {{ $T['services'] }}
                    </span>
                    <span class="text-gray-300 dark:text-gray-600">·</span>
                    <span title="Demandes ouvertes">
                        <span class="font-semibold text-green-600 dark:text-green-400">{{ $member->open_requests_count }}</span> demande{{ $member->open_requests_count > 1 ? 's' : '' }}
                    </span>
                </div>

                <!-- Top skills -->
                @php
                    $skills = $member->services->flatMap->skills->unique('id')->take(4);
                @endphp
                @if($skills->isNotEmpty())
                <div class="flex flex-wrap gap-1">
                    @foreach($skills as $skill)
                    <span class="px-2 py-0.5 bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300 rounded text-xs">{{ $skill->name }}</span>
                    @endforeach
                </div>
                @endif

            </a>
            @endforeach
        </div>

        <div class="mt-8">
            {{ $members->links() }}
        </div>

    </div>
</x-app-layout>
