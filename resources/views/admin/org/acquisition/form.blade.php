<x-org-admin-layout :title="$journey ? __('acquisition.edit_title', ['version' => $journey->version]) : __('acquisition.create_title')" :organization="$organization">
    {{-- TASK-1446 — AcquisitionJourney : un brouillon s'edite ; une version publiee jamais. --}}
    <div class="max-w-3xl">
        <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100 mb-1">{{ $journey ? __('acquisition.edit_title', ['version' => $journey->version]) : __('acquisition.create_title') }}</h1>
        <p class="text-sm text-gray-500 dark:text-gray-400 mb-6">{{ __('acquisition.form_hint') }}</p>

        <form method="POST" action="{{ $journey ? route('organization.admin.acquisition.update', [$organization, $journey]) : route('organization.admin.acquisition.store', $organization) }}" class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-6 space-y-4" data-acquisition-form>
            @csrf
            @if($journey) @method('PUT') @endif
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <label class="block">
                    <span class="block text-xs font-medium text-gray-600 dark:text-gray-300 mb-1">{{ __('acquisition.col_name') }}</span>
                    <input type="text" name="name" value="{{ old('name', $journey?->name) }}" maxlength="160" required class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 text-sm" data-acquisition-name>
                    @error('name')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </label>
                <label class="block">
                    <span class="block text-xs font-medium text-gray-600 dark:text-gray-300 mb-1">{{ __('acquisition.col_key') }}</span>
                    @if($journey)
                        <input type="text" value="{{ $journey->key }}" disabled class="w-full rounded-lg border-gray-200 bg-gray-50 dark:bg-gray-900 dark:border-gray-700 text-sm text-gray-500">
                    @else
                        <input type="text" name="key" value="{{ old('key') }}" maxlength="60" placeholder="{{ __('acquisition.key_placeholder') }}" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 text-sm font-mono">
                        @error('key')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    @endif
                </label>
                <label class="block">
                    <span class="block text-xs font-medium text-gray-600 dark:text-gray-300 mb-1">{{ __('acquisition.col_locale') }}</span>
                    <select name="locale" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 text-sm">
                        @foreach($locales as $loc)<option value="{{ $loc }}" @selected(old('locale', $journey?->locale ?? $organization->locale ?? 'fr') === $loc)>{{ strtoupper($loc) }}</option>@endforeach
                    </select>
                </label>
                <label class="block">
                    <span class="block text-xs font-medium text-gray-600 dark:text-gray-300 mb-1">{{ __('acquisition.col_goal') }}</span>
                    <select name="conversion_goal" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 text-sm" data-acquisition-goal>
                        @foreach($goals as $goal)<option value="{{ $goal }}" @selected(old('conversion_goal', $journey?->conversion_goal ?? 'account') === $goal)>{{ __('acquisition.goal_'.$goal) }}</option>@endforeach
                    </select>
                    @error('conversion_goal')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </label>
                <label class="block">
                    <span class="block text-xs font-medium text-gray-600 dark:text-gray-300 mb-1">{{ __('acquisition.col_campaign') }}</span>
                    <input type="text" name="campaign" value="{{ old('campaign', $journey?->campaign) }}" maxlength="100" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 text-sm">
                    @error('campaign')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </label>
                <label class="block">
                    <span class="block text-xs font-medium text-gray-600 dark:text-gray-300 mb-1">{{ __('acquisition.col_surface') }}</span>
                    <select name="usage_reference_surface_key" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 text-sm">
                        <option value="" @selected(old('usage_reference_surface_key', $journey?->usage_reference_surface_key) === null)>—</option>
                        @foreach($surfaces as $surface)<option value="{{ $surface }}" @selected(old('usage_reference_surface_key', $journey?->usage_reference_surface_key) === $surface)>{{ $surface }}</option>@endforeach
                    </select>
                    <span class="text-[11px] text-gray-400">{{ __('acquisition.surface_hint') }}</span>
                </label>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <button type="submit" class="px-4 py-2 rounded-lg bg-indigo-600 text-white text-sm font-medium hover:bg-indigo-700" data-acquisition-save>{{ __('acquisition.save_draft') }}</button>
                <a href="{{ route('organization.admin.acquisition', $organization) }}" class="px-3 py-2 text-sm text-gray-600 dark:text-gray-300 hover:underline">{{ __('acquisition.back') }}</a>
            </div>
        </form>
    </div>
</x-org-admin-layout>
