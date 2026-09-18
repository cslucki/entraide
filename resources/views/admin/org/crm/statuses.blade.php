<x-org-admin-layout :title="__('crm.statuses.title')" :organization="$organization">
    {{-- TASK-1419 — CRM-4b : le pipeline de statuts appartient a l'Organization, et se gere ici. --}}
    <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">{{ __('crm.statuses.title') }}</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">{{ __('crm.statuses.subtitle') }}</p>
        </div>
        <a href="{{ route('organization.admin.crm.contacts', ['organization' => $organization->slug]) }}" class="text-sm text-indigo-600 dark:text-indigo-400 hover:underline">&larr; {{ __('crm.title') }}</a>
    </div>

    <form method="POST" action="{{ route('organization.admin.crm.statuses.store', ['organization' => $organization->slug]) }}" data-crm-status-create
          class="mb-5 flex flex-wrap items-center gap-3 p-4 bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700">
        @csrf
        <input type="text" name="label" value="{{ old('label') }}" required maxlength="60" placeholder="{{ __('crm.statuses.label') }}"
            class="flex-1 min-w-48 px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm">
        <input type="color" name="color" value="{{ old('color', '#6366f1') }}" aria-label="{{ __('crm.statuses.color') }}" class="h-9 w-12 rounded border border-gray-300 dark:border-gray-600 bg-transparent">
        <label class="flex items-center gap-1 text-xs text-gray-500 dark:text-gray-400"><input type="checkbox" name="no_color" value="1" {{ old('no_color') ? 'checked' : '' }} class="rounded border-gray-300 dark:border-gray-600"> {{ __('crm.statuses.no_color') }}</label>
        <button type="submit" class="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm hover:bg-indigo-700">{{ __('crm.statuses.new') }}</button>
        @if($errors->any())<ul class="w-full text-xs text-red-600 dark:text-red-400" data-crm-status-errors>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>@endif
    </form>

    <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-x-auto">
        <table class="w-full text-sm" data-crm-statuses-table>
            <thead class="bg-gray-50 dark:bg-gray-700">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide w-24">{{ __('crm.statuses.order') }}</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('crm.statuses.label') }}</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('crm.statuses.contacts') }}</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('crm.statuses.state') }}</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('crm.column.actions') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                @foreach($statuses as $index => $status)
                <tr class="{{ $status->is_active ? '' : 'opacity-60' }}" data-crm-status="{{ $status->id }}" data-crm-status-position="{{ $index + 1 }}">
                    <td class="px-4 py-3 whitespace-nowrap">
                        <form method="POST" action="{{ route('organization.admin.crm.statuses.move', ['organization' => $organization->slug, 'status' => $status->id]) }}" class="inline">@csrf<input type="hidden" name="direction" value="up">
                            <button type="submit" data-crm-status-move-up {{ $index === 0 ? 'disabled' : '' }} class="px-2 py-1 text-xs rounded border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 disabled:opacity-30" aria-label="{{ __('crm.statuses.up') }}">&uarr;</button></form>
                        <form method="POST" action="{{ route('organization.admin.crm.statuses.move', ['organization' => $organization->slug, 'status' => $status->id]) }}" class="inline">@csrf<input type="hidden" name="direction" value="down">
                            <button type="submit" data-crm-status-move-down {{ $index === $statuses->count() - 1 ? 'disabled' : '' }} class="px-2 py-1 text-xs rounded border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 disabled:opacity-30" aria-label="{{ __('crm.statuses.down') }}">&darr;</button></form>
                    </td>
                    <td class="px-4 py-3">
                        <form method="POST" action="{{ route('organization.admin.crm.statuses.update', ['organization' => $organization->slug, 'status' => $status->id]) }}" class="flex items-center gap-2" data-crm-status-edit>
                            @csrf
                            @method('PUT')
                            <input type="color" name="color" value="{{ $status->color ?? '#9ca3af' }}" aria-label="{{ __('crm.statuses.color') }}" class="h-7 w-9 rounded border border-gray-300 dark:border-gray-600 bg-transparent">
                            <input type="text" name="label" value="{{ $status->label }}" required maxlength="60" class="px-2 py-1 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm min-w-40">
                            <button type="submit" class="px-2 py-1 text-xs rounded border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700">{{ __('crm.statuses.save') }}</button>
                        </form>
                    </td>
                    <td class="px-4 py-3 text-xs text-gray-500" data-crm-status-count>{{ $status->contacts_count }}</td>
                    <td class="px-4 py-3 whitespace-nowrap">
                        @if($status->is_default)<span class="px-2 py-0.5 rounded text-xs bg-indigo-100 dark:bg-indigo-900/40 text-indigo-700 dark:text-indigo-300" data-crm-status-default-badge>{{ __('crm.statuses.default_badge') }}</span>@endif
                        @unless($status->is_active)<span class="px-2 py-0.5 rounded text-xs bg-gray-200 dark:bg-gray-700 text-gray-600 dark:text-gray-300" data-crm-status-inactive-badge>{{ __('crm.statuses.inactive_badge') }}</span>@endunless
                    </td>
                    <td class="px-4 py-3 whitespace-nowrap">
                        @unless($status->is_default)
                        <form method="POST" action="{{ route('organization.admin.crm.statuses.toggle', ['organization' => $organization->slug, 'status' => $status->id]) }}" class="inline">@csrf
                            <button type="submit" data-crm-status-toggle class="px-2 py-1 text-xs rounded border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 mr-1">{{ $status->is_active ? __('crm.statuses.deactivate') : __('crm.statuses.activate') }}</button></form>
                        @endunless
                        @if($status->is_active && !$status->is_default)
                        <form method="POST" action="{{ route('organization.admin.crm.statuses.default', ['organization' => $organization->slug, 'status' => $status->id]) }}" class="inline">@csrf
                            <button type="submit" data-crm-status-set-default class="px-2 py-1 text-xs rounded border border-indigo-300 dark:border-indigo-700 text-indigo-700 dark:text-indigo-300 hover:bg-indigo-50 dark:hover:bg-indigo-900/30">{{ __('crm.statuses.set_default') }}</button></form>
                        @endif
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">{{ __('crm.statuses.hint') }}</p>
</x-org-admin-layout>
