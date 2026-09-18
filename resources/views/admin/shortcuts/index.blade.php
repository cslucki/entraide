<x-admin-layout :title="__('admin.shortcut_title')">
    {{-- TASK-1447 — OrganizationShortcut : /s/{code} → destination canonique. SuperAdmin-managed V1. --}}
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">{{ __('admin.shortcut_title') }}</h1>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">{{ __('admin.shortcut_hint') }}</p>
    </div>
    @if(session('success'))
        <div class="mb-4 rounded-lg bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-200 dark:border-emerald-800 px-4 py-3 text-sm text-emerald-800 dark:text-emerald-200">{{ session('success') }}</div>
    @endif

    <form method="POST" action="{{ route('admin.shortcuts.store') }}" class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-5 mb-6 grid grid-cols-1 md:grid-cols-5 gap-3 items-end" data-shortcut-form>
        @csrf
        <label class="block"><span class="block text-xs text-gray-500 dark:text-gray-400 mb-1">{{ __('admin.shortcut_col_organization') }}</span>
            <select name="organization_id" class="w-full px-3 py-1.5 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-sm">@foreach($organizations as $org)<option value="{{ $org->id }}" @selected(old('organization_id') === $org->id)>{{ $org->name }} ({{ $org->slug }})</option>@endforeach</select>
            @error('organization_id')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror</label>
        <label class="block"><span class="block text-xs text-gray-500 dark:text-gray-400 mb-1">{{ __('admin.shortcut_col_code') }}</span>
            <input type="text" name="code" value="{{ old('code') }}" maxlength="32" required placeholder="demo" class="w-full px-3 py-1.5 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-sm font-mono" data-shortcut-code>
            @error('code')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror</label>
        <label class="block"><span class="block text-xs text-gray-500 dark:text-gray-400 mb-1">{{ __('admin.shortcut_col_destination') }}</span>
            <select name="destination" class="w-full px-3 py-1.5 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-sm">@foreach($destinations as $destination)<option value="{{ $destination }}" @selected(old('destination') === $destination)>{{ __('admin.shortcut_destination_'.$destination) }}</option>@endforeach</select></label>
        <label class="block"><span class="block text-xs text-gray-500 dark:text-gray-400 mb-1">{{ __('admin.shortcut_col_journey') }}</span>
            <input type="text" name="acquisition_journey_key" value="{{ old('acquisition_journey_key') }}" maxlength="60" list="shortcut-journeys" class="w-full px-3 py-1.5 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-sm font-mono">
            <datalist id="shortcut-journeys">@foreach($journeys as $journey)<option value="{{ $journey->key }}">{{ $journey->name }}</option>@endforeach</datalist>
            @error('acquisition_journey_key')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror</label>
        <div class="flex gap-2 items-end">
            <label class="block flex-1"><span class="block text-xs text-gray-500 dark:text-gray-400 mb-1">{{ __('admin.shortcut_col_campaign') }}</span>
                <input type="text" name="campaign" value="{{ old('campaign') }}" maxlength="100" class="w-full px-3 py-1.5 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-sm"></label>
            <button type="submit" class="px-3 py-1.5 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold rounded-lg" data-shortcut-create>{{ __('admin.shortcut_create') }}</button>
        </div>
    </form>

    <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 dark:bg-gray-700"><tr>
                @foreach(['code', 'organization', 'destination', 'journey', 'campaign', 'state', 'link', 'actions'] as $col)<th class="px-3 py-2 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('admin.shortcut_col_'.$col) }}</th>@endforeach
            </tr></thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                @forelse($shortcuts as $shortcut)
                <tr data-shortcut-row="{{ $shortcut->code }}" data-shortcut-active="{{ $shortcut->active ? '1' : '0' }}">
                    <td class="px-3 py-2 font-mono">{{ $shortcut->code }}</td>
                    <td class="px-3 py-2">{{ $shortcut->organization?->name }}</td>
                    <td class="px-3 py-2 text-xs">{{ __('admin.shortcut_destination_'.$shortcut->destination) }}</td>
                    <td class="px-3 py-2 font-mono text-xs">{{ $shortcut->acquisition_journey_key ?? '—' }}</td>
                    <td class="px-3 py-2 text-xs">{{ $shortcut->campaign ?? '—' }}</td>
                    <td class="px-3 py-2"><span class="px-2 py-0.5 rounded text-xs font-semibold {{ $shortcut->active ? 'bg-emerald-100 dark:bg-emerald-900/40 text-emerald-800 dark:text-emerald-300' : 'bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300' }}">{{ $shortcut->active ? __('admin.shortcut_state_active') : __('admin.shortcut_state_inactive') }}</span></td>
                    <td class="px-3 py-2 font-mono text-xs"><a href="{{ route('shortcut', ['code' => $shortcut->code]) }}" class="text-indigo-600 dark:text-indigo-400 hover:underline" data-shortcut-link>{{ route('shortcut', ['code' => $shortcut->code]) }}</a></td>
                    <td class="px-3 py-2"><form method="POST" action="{{ route('admin.shortcuts.toggle', $shortcut) }}">@csrf @method('PATCH')<button type="submit" class="px-2 py-1 rounded border border-gray-300 dark:border-gray-600 text-xs text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700" data-shortcut-toggle="{{ $shortcut->code }}">{{ $shortcut->active ? __('admin.shortcut_deactivate') : __('admin.shortcut_activate') }}</button></form></td>
                </tr>
                @empty
                <tr><td colspan="8" class="px-3 py-6 text-center text-sm text-gray-500" data-shortcut-empty>{{ __('admin.shortcut_empty') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-admin-layout>
