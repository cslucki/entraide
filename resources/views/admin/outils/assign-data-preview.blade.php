{{-- TASK-1631 — le temps intermediaire : ce que l'affectation ecrirait.
     La mutation ne part qu'apres une case cochee, et une phrase recopiee
     pour un dataset critique. --}}
<x-admin-layout :title="__('admin.assign_data.preview_title')">
    <div class="mb-4">
        <a href="{{ route('admin.outils.assign-data') }}" class="text-sm text-indigo-600 dark:text-indigo-400 underline">{{ __('admin.assign_data.back') }}</a>
    </div>

    <div class="mb-6 rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-900/20 dark:text-amber-200">
        <p class="font-semibold">{{ __('admin.assign_data.preview_count', ['count' => $count, 'organization' => $organization->name]) }}</p>
        <p class="mt-1">{{ __('admin.assign_data.preview_lead') }}</p>
    </div>

    <h2 class="mb-2 text-sm font-semibold text-gray-700 dark:text-gray-200">{{ __('admin.assign_data.preview_sample') }}</h2>
    <div class="mb-6 bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 dark:bg-gray-700">
                <tr>
                    @foreach($dataset->columns as $column)
                        <th class="px-3 py-2 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ $column }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                @foreach($sample as $row)
                    <tr>
                        @foreach($dataset->columns as $column)
                            <td class="px-3 py-2 align-top font-mono text-xs text-gray-600 dark:text-gray-300">{{ \Illuminate\Support\Str::limit((string) ($row->{$column} ?? ''), 50) }}</td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <form method="POST" action="{{ route('admin.outils.assign-data.assign') }}" x-data="{ confirmed: false }">
        @csrf
        <input type="hidden" name="dataset" value="{{ $dataset->key }}">
        <input type="hidden" name="organization_id" value="{{ $organization->id }}">

        <label class="mb-4 flex items-center gap-2 text-sm font-semibold text-gray-800 dark:text-gray-200">
            <input type="checkbox" name="confirm" value="1" x-model="confirmed" class="rounded border-gray-300 dark:border-gray-600">
            {{ __('admin.assign_data.preview_confirm') }}
        </label>

        @if($dataset->critical)
            <div class="mb-4">
                <label for="confirmation" class="block text-sm font-semibold text-red-700 dark:text-red-400">{{ __('admin.assign_data.preview_critical') }}</label>
                <input id="confirmation" name="confirmation" type="text" class="mt-1 rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 text-sm">
            </div>
        @endif

        <div class="flex flex-wrap items-center gap-3">
            <button type="submit" :disabled="! confirmed"
                    class="inline-flex min-h-11 items-center rounded-lg bg-indigo-600 px-5 text-sm font-semibold text-white hover:bg-indigo-700 disabled:opacity-40 disabled:cursor-not-allowed">
                {{ __('admin.assign_data.preview_submit') }}
            </button>
            <a href="{{ route('admin.outils.assign-data') }}" class="inline-flex min-h-11 items-center rounded-lg border border-gray-300 px-5 text-sm font-semibold text-gray-700 dark:border-gray-600 dark:text-gray-200">{{ __('admin.assign_data.preview_cancel') }}</a>
        </div>
    </form>
</x-admin-layout>
