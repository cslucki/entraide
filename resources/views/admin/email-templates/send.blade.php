<x-admin-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('admin.emailer_send_title', ['name' => $template->name]) }}
        </h2>
    </x-slot>

    <div class="py-6">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
            @if(!empty($confirm))
                <div class="bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-200 dark:border-yellow-700 rounded-lg p-4">
                    <p class="text-sm text-yellow-800 dark:text-yellow-200 font-medium">
                        {{ __('admin.emailer_confirm_message', ['count' => $users->count()]) }}
                    </p>
                </div>

                @if($previewUser)
                    <div class="bg-white dark:bg-gray-800 shadow sm:rounded-lg">
                        <div class="p-6">
                            <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4">
                                {{ __('admin.emailer_preview_for', ['name' => $previewUser->name]) }}
                            </h3>
                            <dl class="space-y-3">
                                <div>
                                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ __('admin.common_subject') }}</dt>
                                    <dd class="mt-1 text-sm text-gray-900 dark:text-gray-100">
                                        {{ $service->interpolateSubject($template->subject, $service->availableVariables($previewUser)) }}
                                    </dd>
                                </div>
                                <div>
                                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ __('admin.common_content') }}</dt>
                                    <dd class="mt-1">
                                        <div class="bg-gray-50 dark:bg-gray-900 rounded-md p-4 max-h-96 overflow-auto border border-gray-200 dark:border-gray-700">
                                            {!! $service->interpolate($template->content_html, $service->availableVariables($previewUser)) !!}
                                        </div>
                                    </dd>
                                </div>
                            </dl>
                        </div>
                    </div>
                @endif

                <form method="POST" action="{{ route('admin.email-templates.send.execute', $template) }}">
                    @csrf
                    @foreach($userIds as $uid)
                        <input type="hidden" name="user_ids[]" value="{{ $uid }}">
                    @endforeach
                    <input type="hidden" name="confirmed" value="1">

                    <div class="flex items-center justify-between">
                        <a href="{{ route('admin.email-templates.send', $template) }}"
                           class="text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-200">
                            ← {{ __('admin.emailer_back_to_selection') }}
                        </a>
                        <button type="submit"
                                class="px-6 py-2 bg-indigo-600 text-white rounded-md hover:bg-indigo-700 font-medium">
                            {{ __('admin.emailer_confirm_send', ['count' => $users->count()]) }}
                        </button>
                    </div>
                </form>

            @else
                <form method="POST" action="{{ route('admin.email-templates.send.execute', $template) }}">
                    @csrf

                    <div class="bg-white dark:bg-gray-800 shadow sm:rounded-lg">
                        <div class="p-6">
                            <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-2">
                                {{ __('admin.emailer_select_recipients') }}
                            </h3>
                            <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">
                                {{ __('admin.emailer_max_50_hint') }}
                            </p>

                            @error('user_ids')
                                <p class="text-sm text-red-600 mb-4">{{ $message }}</p>
                            @enderror

                            <div class="overflow-x-auto max-h-96 overflow-y-auto border border-gray-200 dark:border-gray-700 rounded-lg">
                                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                                    <thead class="bg-gray-50 dark:bg-gray-900/50 sticky top-0">
                                        <tr>
                                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase w-10">
                                                <input type="checkbox" id="select-all" class="rounded border-gray-300 dark:border-gray-600">
                                            </th>
                                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">
                                                {{ __('admin.common_name') }}
                                            </th>
                                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">
                                                {{ __('admin.common_email') }}
                                            </th>
                                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">
                                                {{ __('admin.common_organization') }}
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                                        @forelse($users as $user)
                                            <tr class="hover:bg-gray-50 dark:hover:bg-gray-700">
                                                <td class="px-4 py-2">
                                                    <input type="checkbox" name="user_ids[]" value="{{ $user->id }}"
                                                           class="user-checkbox rounded border-gray-300 dark:border-gray-600">
                                                </td>
                                                <td class="px-4 py-2 text-sm text-gray-900 dark:text-gray-100">
                                                    {{ $user->fullName }}
                                                </td>
                                                <td class="px-4 py-2 text-sm text-gray-600 dark:text-gray-400">
                                                    {{ $user->email }}
                                                </td>
                                                <td class="px-4 py-2 text-sm text-gray-600 dark:text-gray-400">
                                                    {{ $user->organization?->name ?? '—' }}
                                                </td>
                                            </tr>
                                        @empty
                                            <tr>
                                                <td colspan="4" class="px-4 py-8 text-center text-sm text-gray-400">
                                                    {{ __('admin.emailer_no_users') }}
                                                </td>
                                            </tr>
                                        @endforelse
                                    </tbody>
                                </table>
                            </div>

                            @if($users->hasPages())
                                <div class="mt-4">{{ $users->withQueryString()->links() }}</div>
                            @endif
                        </div>
                    </div>

                    <div class="flex items-center justify-between mt-6">
                        <a href="{{ route('admin.email-templates.show', $template) }}"
                           class="text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-200">
                            ← {{ __('admin.emailer_back_to_template') }}
                        </a>
                        <button type="submit"
                                class="px-6 py-2 bg-indigo-600 text-white rounded-md hover:bg-indigo-700 font-medium">
                            {{ __('admin.emailer_send') }}
                        </button>
                    </div>
                </form>
            @endif
        </div>
    </div>

    @push('scripts')
    <script>
        document.getElementById('select-all')?.addEventListener('change', function () {
            document.querySelectorAll('.user-checkbox').forEach(cb => cb.checked = this.checked);
        });
    </script>
    @endpush
</x-admin-layout>
