<x-admin-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('admin.bulk_email_title') }}
        </h2>
    </x-slot>

    <div class="py-6">
        <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
            <div class="bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-200 dark:border-yellow-700 rounded-lg p-4">
                <p class="text-sm text-yellow-800 dark:text-yellow-200 font-medium">
                    {{ __('admin.bulk_email_recipient_count', ['count' => $count]) }}
                </p>
                <p class="mt-1 text-sm text-yellow-700 dark:text-yellow-300">
                    {{ $organization?->name ?? __('admin.bulk_email_scope_all') }}
                    —
                    {{ $validated['member_filter'] === \App\Models\BulkEmailSend::MEMBER_FILTER_NO_PRESENTATION
                        ? __('admin.bulk_email_filter_no_presentation')
                        : __('admin.bulk_email_filter_all') }}
                </p>
            </div>

            <div class="bg-white dark:bg-gray-800 shadow sm:rounded-lg">
                <div class="p-6">
                    <h3 class="text-sm font-medium text-gray-500 dark:text-gray-400 mb-2">{{ __('admin.common_subject') }}</h3>
                    <p class="text-sm text-gray-900 dark:text-gray-100 mb-4">
                        {{ $preview['subject'] ?? ($template?->subject ?? $validated['subject']) }}
                    </p>
                    @if($previewUser)
                        <div class="flex items-center justify-between gap-4 mb-2">
                            <p class="text-xs text-gray-500 dark:text-gray-400">
                                {{ __('admin.bulk_email_preview_for', ['name' => $previewUser->fullName]) }}
                            </p>
                            <div class="flex items-center gap-2 text-xs shrink-0">
                                <form method="POST" action="{{ route('admin.email-bulksending.preview') }}">
                                    @csrf
                                    @include('admin.email-bulksending.partials.hidden-fields')
                                    <input type="hidden" name="preview_index" value="{{ max($previewIndex - 1, 0) }}">
                                    <button type="submit" @disabled($previewIndex === 0)
                                            class="px-2 py-1 rounded border border-gray-300 dark:border-gray-600 text-gray-600 dark:text-gray-300 disabled:opacity-40 disabled:cursor-not-allowed hover:bg-gray-100 dark:hover:bg-gray-700">
                                        ← {{ __('admin.bulk_email_preview_prev') }}
                                    </button>
                                </form>
                                <span class="text-gray-400 dark:text-gray-500 tabular-nums">{{ $previewIndex + 1 }} / {{ $count }}</span>
                                <form method="POST" action="{{ route('admin.email-bulksending.preview') }}">
                                    @csrf
                                    @include('admin.email-bulksending.partials.hidden-fields')
                                    <input type="hidden" name="preview_index" value="{{ min($previewIndex + 1, $count - 1) }}">
                                    <button type="submit" @disabled($previewIndex >= $count - 1)
                                            class="px-2 py-1 rounded border border-gray-300 dark:border-gray-600 text-gray-600 dark:text-gray-300 disabled:opacity-40 disabled:cursor-not-allowed hover:bg-gray-100 dark:hover:bg-gray-700">
                                        {{ __('admin.bulk_email_preview_next') }} →
                                    </button>
                                </form>
                            </div>
                        </div>
                    @endif
                    <h3 class="text-sm font-medium text-gray-500 dark:text-gray-400 mb-2">{{ __('admin.common_content') }}</h3>
                    <div class="bg-gray-50 dark:bg-gray-900 rounded-md p-4 max-h-96 overflow-auto border border-gray-200 dark:border-gray-700 text-sm">
                        @if($preview)
                            @if($preview['isHtml'])
                                {!! $preview['body'] !!}
                            @else
                                <pre class="whitespace-pre-wrap font-sans">{{ $preview['body'] }}</pre>
                            @endif
                        @elseif($template)
                            {!! $template->content_html !!}
                        @elseif($validated['content_format'] === \App\Models\BulkEmailSend::CONTENT_FORMAT_HTML)
                            {!! markdown($validated['content_raw']) !!}
                        @else
                            <pre class="whitespace-pre-wrap font-sans">{{ $validated['content_raw'] }}</pre>
                        @endif
                    </div>
                </div>
            </div>

            {{-- Tous les champs valides repartent en inputs caches : rien n'est
                 revalide depuis ce qui est AFFICHE, `store()` revalide le payload
                 complet comme si ce recapitulatif n'avait jamais existe. --}}
            <form method="POST" action="{{ route('admin.email-bulksending.store') }}">
                @csrf
                @include('admin.email-bulksending.partials.hidden-fields')

                <div class="flex items-center justify-between">
                    <a href="{{ route('admin.email-bulksending') }}" class="text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-200">
                        ← {{ __('admin.bulk_email_back_cta') }}
                    </a>
                    <button type="submit" class="px-6 py-2 bg-indigo-600 text-white rounded-md hover:bg-indigo-700 font-medium">
                        {{ __('admin.bulk_email_confirm_send', ['count' => $count]) }}
                    </button>
                </div>
            </form>
        </div>
    </div>
</x-admin-layout>
