@php
    $processed = $send->sent_count + $send->failed_count;
    $percent = $send->total_recipients > 0 ? (int) round(($processed / $send->total_recipients) * 100) : 0;
@endphp
<x-admin-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('admin.bulk_email_title') }}
        </h2>
    </x-slot>

    <div class="py-6">
        <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
            @if(session('success'))
                <div class="bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-200 dark:border-emerald-700 rounded-lg p-4 text-sm text-emerald-800 dark:text-emerald-200">
                    {{ session('success') }}
                </div>
            @endif

            <div class="bg-white dark:bg-gray-800 shadow sm:rounded-lg">
                <div class="p-6">
                    <div class="flex items-center justify-between mb-2">
                        <h3 class="text-sm font-medium text-gray-700 dark:text-gray-300">
                            {{ $processed }} / {{ $send->total_recipients }}
                        </h3>
                        <span class="text-xs text-gray-500 dark:text-gray-400">
                            {{ __('admin.bulk_email_sent_failed', ['sent' => $send->sent_count, 'failed' => $send->failed_count]) }}
                        </span>
                    </div>
                    <div class="w-full bg-gray-200 dark:bg-gray-700 rounded-full h-2.5">
                        <div class="bg-indigo-600 h-2.5 rounded-full" style="width: {{ $percent }}%"></div>
                    </div>

                    @if($send->status === \App\Models\BulkEmailSend::STATUS_COMPLETED)
                        <div class="mt-6 rounded-lg bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-200 dark:border-emerald-700 p-4">
                            <p class="text-sm text-emerald-800 dark:text-emerald-200 font-medium">
                                {{ __('admin.bulk_email_completed') }}
                            </p>
                            <a href="{{ route('admin.email-logs', ['source' => 'bulk-email']) }}" class="mt-2 inline-block text-sm text-indigo-600 hover:underline">
                                {{ __('admin.bulk_email_view_logs_cta') }}
                            </a>
                        </div>
                    @else
                        <form method="POST" action="{{ route('admin.email-bulksending.batch', $send) }}" class="mt-6">
                            @csrf
                            <button type="submit" class="px-6 py-2 bg-indigo-600 text-white rounded-md hover:bg-indigo-700 font-medium">
                                {{ __('admin.bulk_email_next_batch', ['count' => min(50, $send->pending_count)]) }}
                            </button>
                        </form>
                    @endif
                </div>
            </div>

            <a href="{{ route('admin.email-bulksending') }}" class="text-sm text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-200">
                ← {{ __('admin.bulk_email_back_cta') }}
            </a>
        </div>
    </div>
</x-admin-layout>
