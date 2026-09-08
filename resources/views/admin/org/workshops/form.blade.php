<x-org-admin-layout :title="$workshop ? __('workshops.edit_title', ['title' => $workshop->title]) : __('workshops.create_title')" :organization="$organization">
    {{-- TASK-1450 — Workshop : brouillon, edition (slug fige des la publication). --}}
    <div class="max-w-3xl">
        <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100 mb-1">{{ $workshop ? __('workshops.edit_title', ['title' => $workshop->title]) : __('workshops.create_title') }}</h1>
        <p class="text-sm text-gray-500 dark:text-gray-400 mb-6">{{ __('workshops.form_hint') }}</p>

        <form method="POST" action="{{ $workshop ? route('organization.admin.workshops.update', [$organization, $workshop]) : route('organization.admin.workshops.store', $organization) }}" class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-6 space-y-4" data-workshop-form>
            @csrf
            @if($workshop) @method('PUT') @endif
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <label class="block md:col-span-2">
                    <span class="block text-xs font-medium text-gray-600 dark:text-gray-300 mb-1">{{ __('workshops.col_title') }}</span>
                    <input type="text" name="title" value="{{ old('title', $workshop?->title) }}" maxlength="160" required class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 text-sm" data-workshop-title>
                    @error('title')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </label>
                <label class="block">
                    <span class="block text-xs font-medium text-gray-600 dark:text-gray-300 mb-1">{{ __('workshops.col_slug') }}</span>
                    @if($workshop?->hasBeenPublished())
                        <input type="text" value="{{ $workshop->slug }}" disabled class="w-full rounded-lg border-gray-200 bg-gray-50 dark:bg-gray-900 dark:border-gray-700 text-sm text-gray-500 font-mono" data-workshop-slug-frozen>
                    @else
                        <input type="text" name="slug" value="{{ old('slug', $workshop?->slug) }}" maxlength="80" placeholder="{{ __('workshops.slug_placeholder') }}" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 text-sm font-mono" data-workshop-slug>
                    @endif
                    @error('slug')<p class="mt-1 text-xs text-red-600" data-workshop-error>{{ $message }}</p>@enderror
                </label>
                <label class="block">
                    <span class="block text-xs font-medium text-gray-600 dark:text-gray-300 mb-1">{{ __('workshops.col_locale') }}</span>
                    <select name="locale" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 text-sm">
                        @foreach($locales as $loc)<option value="{{ $loc }}" @selected(old('locale', $workshop?->locale ?? $organization->locale ?? 'fr') === $loc)>{{ strtoupper($loc) }}</option>@endforeach
                    </select>
                </label>
                <label class="block md:col-span-2">
                    <span class="block text-xs font-medium text-gray-600 dark:text-gray-300 mb-1">{{ __('workshops.col_promise') }}</span>
                    <input type="text" name="promise" value="{{ old('promise', $workshop?->promise) }}" maxlength="255" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 text-sm">
                    @error('promise')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </label>
                <label class="block md:col-span-2">
                    <span class="block text-xs font-medium text-gray-600 dark:text-gray-300 mb-1">{{ __('workshops.col_description') }}</span>
                    <textarea name="description" rows="6" maxlength="5000" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 text-sm">{{ old('description', $workshop?->description) }}</textarea>
                    @error('description')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </label>
                <label class="block">
                    <span class="block text-xs font-medium text-gray-600 dark:text-gray-300 mb-1">{{ __('workshops.col_format') }}</span>
                    <select name="format" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 text-sm" data-workshop-format>
                        @foreach($formats as $format)<option value="{{ $format }}" @selected(old('format', $workshop?->format ?? 'online') === $format)>{{ __('workshops.format_'.$format) }}</option>@endforeach
                    </select>
                    @error('format')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </label>
                <label class="block">
                    <span class="block text-xs font-medium text-gray-600 dark:text-gray-300 mb-1">{{ __('workshops.col_duration') }}</span>
                    <input type="number" name="duration_minutes" min="1" max="1440" value="{{ old('duration_minutes', $workshop?->duration_minutes) }}" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 text-sm">
                    @error('duration_minutes')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </label>
                <label class="block md:col-span-2">
                    <span class="block text-xs font-medium text-gray-600 dark:text-gray-300 mb-1">{{ __('workshops.col_journey') }}</span>
                    <select name="acquisition_journey_id" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 text-sm" data-workshop-journey>
                        <option value="">{{ __('workshops.journey_none') }}</option>
                        @foreach($journeys as $journey)<option value="{{ $journey->id }}" @selected(old('acquisition_journey_id', $workshop?->acquisition_journey_id) === $journey->id)>{{ $journey->name }} — v{{ $journey->version }}</option>@endforeach
                    </select>
                    <span class="text-[11px] text-gray-400">{{ __('workshops.journey_hint') }}</span>
                    @error('acquisition_journey_id')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </label>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <button type="submit" class="px-4 py-2 rounded-lg bg-indigo-600 text-white text-sm font-medium hover:bg-indigo-700" data-workshop-save>{{ __('workshops.save') }}</button>
                <a href="{{ route('organization.admin.workshops', $organization) }}" class="px-3 py-2 text-sm text-gray-600 dark:text-gray-300 hover:underline">{{ __('workshops.back') }}</a>
            </div>
        </form>
    </div>
</x-org-admin-layout>
