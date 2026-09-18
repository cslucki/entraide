<x-admin-layout :title="$reference ? __('admin.usage_reference_edit_title', ['version' => $reference->version]) : __('admin.usage_reference_create_title')">
    {{-- TASK-1439 — UsageReference V1 : un brouillon s'edite ; une version publiee jamais. --}}
    <div class="max-w-3xl">
        <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100 mb-1">{{ $reference ? __('admin.usage_reference_edit_title', ['version' => $reference->version]) : __('admin.usage_reference_create_title') }}</h1>
        <p class="text-sm text-gray-500 dark:text-gray-400 mb-6">{{ __('admin.usage_reference_form_hint') }}</p>

        {{-- TASK-1480 : un texte pre-rempli sans explication ferait croire qu'on
             edite la version publiee. On dit d'ou il vient, et qu'il ne
             remplacera rien tant qu'un humain n'aura pas publie. --}}
        @if($from)
            <p class="mb-6 rounded-lg border border-indigo-200 bg-indigo-50 px-3 py-2 text-xs text-indigo-800 dark:border-indigo-800/50 dark:bg-indigo-900/20 dark:text-indigo-200" data-usage-reference-from="{{ $from->id }}">
                {{ __('admin.usage_reference_from_hint', ['version' => $from->version]) }}
            </p>
        @endif

        <form method="POST" action="{{ $reference ? route('admin.usage-references.update', $reference) : route('admin.usage-references.store') }}" class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-6 space-y-4" data-usage-reference-form>
            @csrf
            @if($reference) @method('PUT') @endif
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <label class="block">
                    <span class="block text-xs font-medium text-gray-600 dark:text-gray-300 mb-1">{{ __('admin.usage_reference_col_surface') }}</span>
                    @if($reference)
                        <input type="text" value="{{ $surfaceKey }}" disabled class="w-full rounded-lg border-gray-200 bg-gray-50 dark:bg-gray-900 dark:border-gray-700 text-sm text-gray-500">
                    @else
                        <select name="surface_key" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 text-sm">
                            @foreach($surfaces as $surface)<option value="{{ $surface }}" @selected(old('surface_key', $surfaceKey) === $surface)>{{ __('admin.usage_reference_surface_'.$surface) }} ({{ $surface }})</option>@endforeach
                        </select>
                    @endif
                    @error('surface_key')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </label>
                <label class="block">
                    <span class="block text-xs font-medium text-gray-600 dark:text-gray-300 mb-1">{{ __('admin.usage_reference_col_locale') }}</span>
                    @if($reference)
                        <input type="text" value="{{ strtoupper($locale) }}" disabled class="w-full rounded-lg border-gray-200 bg-gray-50 dark:bg-gray-900 dark:border-gray-700 text-sm text-gray-500">
                    @else
                        <select name="locale" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 text-sm">
                            @foreach($locales as $loc)<option value="{{ $loc }}" @selected(old('locale', $locale) === $loc)>{{ strtoupper($loc) }}</option>@endforeach
                        </select>
                    @endif
                    @error('locale')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </label>
            </div>
            <label class="block">
                <span class="block text-xs font-medium text-gray-600 dark:text-gray-300 mb-1">{{ __('admin.usage_reference_col_reference_title') }}</span>
                <input type="text" name="title" value="{{ old('title', $reference?->title ?? $from?->title) }}" maxlength="160" required class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 text-sm" data-usage-reference-title>
                @error('title')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
            </label>
            <label class="block">
                <span class="block text-xs font-medium text-gray-600 dark:text-gray-300 mb-1">{{ __('admin.usage_reference_col_content') }}</span>
                <textarea name="content" rows="14" maxlength="{{ $maxChars }}" required class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 text-sm font-mono" data-usage-reference-content>{{ old('content', $reference?->content ?? $from?->content) }}</textarea>
                @error('content')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                <span class="text-xs text-gray-400">{{ __('admin.usage_reference_footer', ['max' => $maxChars]) }}</span>
            </label>
            <div class="flex flex-wrap items-center gap-2">
                <button type="submit" class="px-4 py-2 rounded-lg bg-indigo-600 text-white text-sm font-medium hover:bg-indigo-700" data-usage-reference-save>{{ __('admin.usage_reference_save_draft') }}</button>
                <a href="{{ route('admin.usage-references') }}" class="px-3 py-2 text-sm text-gray-600 dark:text-gray-300 hover:underline">{{ __('admin.usage_reference_back') }}</a>
            </div>
        </form>
    </div>
</x-admin-layout>
