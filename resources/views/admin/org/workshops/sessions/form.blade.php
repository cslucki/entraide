<x-org-admin-layout :title="$session ? __('workshops.session_edit_title', ['date' => $session->localStartsAt()->format('d/m/Y H:i')]) : __('workshops.session_create_title')" :organization="$organization">
    {{-- TASK-1451 — B4-A : une session, saisie dans son fuseau. Aucune meeting_url (le secret arrive avec son consommateur). --}}
    <div class="max-w-3xl">
        <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100 mb-1">{{ $session ? __('workshops.session_edit_title', ['date' => $session->localStartsAt()->format('d/m/Y H:i')]) : __('workshops.session_create_title') }}</h1>
        <p class="text-sm text-gray-500 dark:text-gray-400 mb-1">{{ $workshop->title }}</p>
        <p class="text-sm text-gray-500 dark:text-gray-400 mb-6">{{ __('workshops.session_form_hint') }}</p>

        <form method="POST" action="{{ $session ? route('organization.admin.workshops.sessions.update', [$organization, $workshop, $session]) : route('organization.admin.workshops.sessions.store', [$organization, $workshop]) }}" class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-6 space-y-4" data-session-form>
            @csrf
            @if($session) @method('PUT') @endif
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <label class="block">
                    <span class="block text-xs font-medium text-gray-600 dark:text-gray-300 mb-1">{{ __('workshops.col_starts_at') }}</span>
                    <input type="datetime-local" name="starts_at" value="{{ old('starts_at', $session?->localStartsAt()->format('Y-m-d\TH:i')) }}" required class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 text-sm" data-session-starts>
                    @error('starts_at')<p class="mt-1 text-xs text-red-600" data-session-error>{{ $message }}</p>@enderror
                </label>
                <label class="block">
                    <span class="block text-xs font-medium text-gray-600 dark:text-gray-300 mb-1">{{ __('workshops.col_ends_at') }}</span>
                    <input type="datetime-local" name="ends_at" value="{{ old('ends_at', $session?->localEndsAt()?->format('Y-m-d\TH:i')) }}" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 text-sm">
                    @error('ends_at')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </label>
                <label class="block">
                    <span class="block text-xs font-medium text-gray-600 dark:text-gray-300 mb-1">{{ __('workshops.col_timezone') }}</span>
                    <select name="timezone" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 text-sm" data-session-timezone>
                        @foreach($timezones as $tz)<option value="{{ $tz }}" @selected(old('timezone', $defaultTimezone) === $tz)>{{ $tz }}</option>@endforeach
                    </select>
                    @error('timezone')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </label>
                <label class="block">
                    <span class="block text-xs font-medium text-gray-600 dark:text-gray-300 mb-1">{{ __('workshops.col_capacity') }}</span>
                    <input type="number" name="capacity" min="1" max="10000" value="{{ old('capacity', $session?->capacity) }}" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 text-sm">
                    @error('capacity')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </label>
                <label class="block md:col-span-2">
                    <span class="block text-xs font-medium text-gray-600 dark:text-gray-300 mb-1">{{ __('workshops.col_location') }}</span>
                    <input type="text" name="location" value="{{ old('location', $session?->location) }}" maxlength="255" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 text-sm">
                    @error('location')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </label>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <button type="submit" class="px-4 py-2 rounded-lg bg-indigo-600 text-white text-sm font-medium hover:bg-indigo-700" data-session-save>{{ __('workshops.save') }}</button>
                <a href="{{ route('organization.admin.workshops.sessions', [$organization, $workshop]) }}" class="px-3 py-2 text-sm text-gray-600 dark:text-gray-300 hover:underline">{{ __('workshops.session_back') }}</a>
            </div>
        </form>
    </div>
</x-org-admin-layout>
