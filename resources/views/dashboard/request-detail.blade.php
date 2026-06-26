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
        <a href="{{ $_dashRoute('dashboard.requests') }}" class="text-sm text-indigo-600 hover:underline">&larr; {{ __('dashboard.back_to_requests') }}</a>

        <div class="mt-4 bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
            <div class="px-6 py-5 border-b border-gray-100 dark:border-gray-700 flex items-center justify-between">
                <div>
                    <h1 class="text-xl font-bold text-gray-900 dark:text-gray-100">{{ $serviceRequest->title }}</h1>
                    @if($serviceRequest->category)
                    <span class="mt-1 inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium" style="background-color: {{ $serviceRequest->category->color ?? '#e5e7eb' }}20; color: {{ $serviceRequest->category->color ?? '#6b7280' }}">{{ $serviceRequest->category->name }}</span>
                    @endif
                </div>
                <div class="flex items-center gap-3">
                    @if($respondents->isEmpty() && $serviceRequest->status === 'open')
                    <a href="{{ $_dashRoute('requests.edit', ['request' => $serviceRequest]) }}" class="inline-flex items-center px-3 py-1.5 bg-indigo-600 text-white text-xs font-medium rounded-lg hover:bg-indigo-700 transition">{{ __('dashboard.edit_request') }}</a>
                    @endif
                    <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium
                        {{ $serviceRequest->status === 'open' ? 'bg-green-100 dark:bg-green-900 text-green-700 dark:text-green-300' : '' }}
                        {{ $serviceRequest->status === 'in_progress' ? 'bg-blue-100 dark:bg-blue-900 text-blue-700 dark:text-blue-300' : '' }}
                        {{ $serviceRequest->status === 'closed' ? 'bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-400' : '' }}">
                        {{ $serviceRequest->status }}
                    </span>
                </div>
            </div>

            <div class="px-6 py-5 space-y-4">
                <div class="grid grid-cols-2 gap-4 text-sm">
                    <div>
                        <span class="text-gray-500 dark:text-gray-400">{{ __('dashboard.table_date') }} :</span>
                        <span class="ml-2 text-gray-900 dark:text-gray-100">{{ $serviceRequest->created_at->isoFormat('D MMM YYYY') }}</span>
                    </div>
                    <div>
                        <span class="text-gray-500 dark:text-gray-400">{{ __('dashboard.table_budget') }} :</span>
                        <span class="ml-2 text-gray-900 dark:text-gray-100">{{ $serviceRequest->budget_min }}{{ $serviceRequest->budget_max ? '–'.$serviceRequest->budget_max : '+' }} pts</span>
                    </div>
                    <div>
                        <span class="text-gray-500 dark:text-gray-400">{{ __('dashboard.table_delivery_mode') }} :</span>
                        <span class="ml-2 text-gray-900 dark:text-gray-100">{{ __("dashboard.delivery_{$serviceRequest->delivery_mode}") }}</span>
                    </div>
                    @if($serviceRequest->deadline)
                    <div>
                        <span class="text-gray-500 dark:text-gray-400">Délai :</span>
                        <span class="ml-2 text-gray-900 dark:text-gray-100">{{ $serviceRequest->deadline->isoFormat('D MMM YYYY') }}</span>
                    </div>
                    @endif
                </div>

                @if($serviceRequest->description)
                <div>
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100 mb-2">Description</h3>
                    <div class="text-sm text-gray-600 dark:text-gray-400 whitespace-pre-wrap">{{ $serviceRequest->description }}</div>
                </div>
                @endif

                @if($serviceRequest->attachments->isNotEmpty())
                <div>
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100 mb-2">{{ __('dashboard.table_attachments') }}</h3>
                    <div class="flex flex-wrap gap-3">
                        @foreach($serviceRequest->attachments as $attachment)
                        <a href="{{ Storage::url($attachment->file_path) }}" target="_blank" class="inline-flex items-center gap-2 px-3 py-2 bg-gray-50 dark:bg-gray-700 rounded-lg text-xs text-gray-600 dark:text-gray-400 hover:text-indigo-600 border border-gray-200 dark:border-gray-600">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                            {{ $attachment->original_name ?? $attachment->file_name }}
                        </a>
                        @endforeach
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
                                    <p class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ $tx->buyer->name }}</p>
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
                    <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('dashboard.no_respondents') }}</p>
                </div>
                @endif
            </div>
        </div>
    </div>
</x-app-layout>