<x-org-admin-layout :title="$sectionName" :organization="$organization">
    <div class="flex flex-col items-center justify-center py-20 text-center">
        <svg class="w-20 h-20 text-gray-300 dark:text-gray-600 mb-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z"/>
        </svg>
        <h2 class="text-2xl font-bold text-gray-800 dark:text-gray-200 mb-2">{{ $sectionName }}</h2>
        <p class="text-gray-500 dark:text-gray-400 mb-8 max-w-md">{{ __('navigation.org_admin_coming_soon', ['section' => $sectionName]) }}</p>
        <a href="{{ route('organization.admin.dashboard', ['organization' => $organization->slug]) }}"
           class="flex items-center gap-2 px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 transition text-sm">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 19l-7-7 7-7m8 14l-7-7 7-7"/></svg>
            {{ __('navigation.dashboard') }}
        </a>
    </div>
</x-org-admin-layout>
