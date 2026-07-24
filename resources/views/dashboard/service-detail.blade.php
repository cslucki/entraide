<x-app-layout>
    @php
        $_dashOrgSlug = $organization?->slug;
        $_dashRoute = function (string $name, array $params = []) use ($_dashOrgSlug): string {
            $orgRoute = 'organization.' . $name;
            return $_dashOrgSlug && Route::has($orgRoute)
                ? route($orgRoute, ['organization' => $_dashOrgSlug] + $params)
                : route($name, $params);
        };
    @endphp
    <div class="max-w-4xl mx-auto px-4 py-8">
        <a href="{{ $_dashRoute('dashboard.services') }}" class="hidden text-sm text-indigo-600 hover:underline md:inline">&larr; {{ __('dashboard.back_to_services') }}</a>

        <div class="mt-0 bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden md:mt-4">
            <div class="px-6 py-5 border-b border-gray-100 dark:border-gray-700 flex items-center justify-between">
                <div>
                    <h1 class="text-xl font-bold text-gray-900 dark:text-gray-100">{{ $service->title }}</h1>
                    @if($service->category)
                    <span class="mt-1 inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium" style="background-color: {{ $service->category->color ?? '#e5e7eb' }}20; color: {{ $service->category->color ?? '#6b7280' }}">{{ $service->category->name }}</span>
                    @endif
                </div>
                <div class="flex items-center gap-3">
                    @if($respondents->isEmpty() && $service->status === 'active')
                    <a href="{{ $_dashRoute('services.edit', ['service' => $service]) }}" class="inline-flex items-center px-3 py-1.5 bg-indigo-600 text-white text-xs font-medium rounded-lg hover:bg-indigo-700 transition">{{ __('dashboard.edit_service') }}</a>
                    @endif
                    <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium
                        {{ $service->status === 'active' ? 'bg-green-100 dark:bg-green-900 text-green-700 dark:text-green-300' : '' }}
                        {{ $service->status === 'paused' ? 'bg-yellow-100 dark:bg-yellow-900 text-yellow-700 dark:text-yellow-300' : '' }}
                        {{ $service->status === 'deleted' ? 'bg-red-100 dark:bg-red-900 text-red-700 dark:text-red-300' : '' }}">
                        {{ $service->status }}
                    </span>
                </div>
            </div>

            <div class="px-6 py-5 space-y-4">
                <div class="grid grid-cols-2 gap-4 text-sm">
                    <div>
                        <span class="text-gray-500 dark:text-gray-400">{{ __('dashboard.table_date') }} :</span>
                        <span class="ml-2 text-gray-900 dark:text-gray-100">{{ $service->created_at->isoFormat('D MMM YYYY') }}</span>
                    </div>
                    <div>
                        <span class="text-gray-500 dark:text-gray-400">{{ __('dashboard.table_points') }} :</span>
                        <span class="ml-2 text-gray-900 dark:text-gray-100">{{ $service->points_cost }} pts</span>
                    </div>
                    <div>
                        <span class="text-gray-500 dark:text-gray-400">{{ __('dashboard.table_delivery_mode') }} :</span>
                        <span class="ml-2 text-gray-900 dark:text-gray-100">{{ __("dashboard.delivery_{$service->delivery_mode}") }}</span>
                    </div>
                </div>

                @if($service->description)
                <div>
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100 mb-2">Description</h3>
                    <div class="text-sm text-gray-600 dark:text-gray-400 whitespace-pre-wrap">{{ $service->description }}</div>
                </div>
                @endif

                @if($service->images->isNotEmpty())
                <div x-data="{ imageOpen: false, imageUrl: null }" x-on:keydown.escape.window="imageOpen = false">
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100 mb-2">{{ __('dashboard.table_attachments') }}</h3>
                    <div class="grid grid-cols-2 sm:grid-cols-3 gap-3">
                        @foreach($service->images as $image)
                        <button type="button" @click="imageUrl = @js($image->url); imageOpen = true" class="block aspect-square rounded-lg overflow-hidden bg-gray-100 shadow-sm transition hover:-translate-y-0.5 hover:shadow-md focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 focus:ring-offset-white dark:bg-gray-700 dark:focus:ring-offset-gray-800">
                            <img src="{{ $image->url }}" alt="" class="w-full h-full object-cover">
                        </button>
                        @endforeach
                    </div>

                    <div x-show="imageOpen" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4 sm:p-6" role="dialog" aria-modal="true">
                        <div x-show="imageOpen" x-transition.opacity class="absolute inset-0 bg-black/80 backdrop-blur-sm" @click="imageOpen = false"></div>

                        <div x-show="imageOpen" x-transition class="relative max-h-[88vh] w-full max-w-5xl overflow-hidden rounded-2xl bg-black shadow-2xl ring-1 ring-white/10">
                            <button type="button" @click="imageOpen = false" class="absolute right-3 top-3 z-10 inline-flex h-9 w-9 items-center justify-center rounded-full bg-black/60 text-white backdrop-blur transition hover:bg-black/80 focus:outline-none focus:ring-2 focus:ring-white" aria-label="{{ __('ui.close') }}">
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <path d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </button>
                            <img :src="imageUrl" alt="" class="max-h-[88vh] w-full object-contain">
                        </div>
                    </div>
                </div>
                @endif

                @if($respondents->isNotEmpty())
                <div class="border-t border-gray-100 dark:border-gray-700 pt-4">
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100 mb-3">{{ __('dashboard.respondents') }}</h3>
                    <div class="space-y-3">
                        @foreach($respondents as $tx)
                        <div class="flex items-center justify-between bg-gray-50 dark:bg-gray-700/50 rounded-lg px-4 py-3">
                            <div class="flex items-center gap-3">
                                <img src="{{ $tx->buyer->avatar_url }}" class="w-8 h-8 rounded-full" alt="">
                                <div>
                                    <p class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ $tx->buyer->full_name }}</p>
                                    <p class="text-xs text-gray-500">{{ $tx->points_proposed }} pts</p>
                                </div>
                            </div>
                            <a href="{{ $_dashRoute('messages.show', ['transaction' => $tx]) }}" class="text-xs text-indigo-600 hover:underline font-medium">{{ __('dashboard.messages_link') }}</a>
                        </div>
                        @endforeach
                    </div>
                </div>
                @else
                <div class="border-t border-gray-100 dark:border-gray-700 pt-4">
                    <p class="text-sm text-gray-500 dark:text-gray-400 mb-3">{{ __('dashboard.no_respondents') }}</p>
                    @if($service->status === 'active')
                    <a href="{{ $_dashRoute('services.edit', ['service' => $service]) }}" class="inline-flex items-center px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-lg hover:bg-indigo-700 transition">{{ __('dashboard.edit_service') }}</a>
                    @endif
                </div>
                @endif
            </div>
        </div>
    </div>
</x-app-layout>
