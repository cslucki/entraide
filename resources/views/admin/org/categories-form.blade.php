<x-org-admin-layout :title="__('navigation.org_admin_categories')" :organization="$organization">
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">
            {{ $category ? __('navigation.org_admin_edit_category') : __('navigation.org_admin_new_category') }}
        </h1>
    </div>

    <form method="POST"
          action="{{ $category ? route('organization.admin.categories.update', [$organization, $category]) : route('organization.admin.categories.store', $organization) }}"
          class="max-w-lg space-y-6">
        @csrf
        @if($category) @method('PUT') @endif

        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">@lang('navigation.org_admin_category_name_b2c')</label>
            <input type="text" name="name_b2c" value="{{ old('name_b2c', $category?->name_b2c) }}" required maxlength="100"
                   class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-indigo-500">
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">@lang('navigation.org_admin_category_name_b2b')</label>
            <input type="text" name="name_b2b" value="{{ old('name_b2b', $category?->name_b2b) }}" required maxlength="100"
                   class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-indigo-500">
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">@lang('navigation.org_admin_category_color')</label>
            <div class="flex items-center gap-3">
                <input type="color" name="color" value="{{ old('color', $category?->color ?? '#6366f1') }}" required
                       class="w-10 h-10 rounded border border-gray-300 dark:border-gray-600 cursor-pointer">
                <input type="text" name="color_hex" value="{{ old('color', $category?->color ?? '#6366f1') }}" pattern="^#[0-9a-fA-F]{6}$" maxlength="7"
                       class="flex-1 px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-indigo-500 font-mono"
                       placeholder="#6366f1">
            </div>
        </div>

        <div x-data="{ skills: @js(old('skills', $category?->skills->pluck('name')->toArray() ?? [])) }">
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">@lang('navigation.org_admin_category_skills')</label>
            <div class="flex flex-wrap gap-2 mb-2">
                <template x-for="(skill, i) in skills" :key="i">
                    <span class="inline-flex items-center gap-1 px-2 py-0.5 bg-indigo-50 dark:bg-indigo-900/30 text-indigo-700 dark:text-indigo-300 rounded text-xs">
                        <span x-text="skill"></span>
                        <input type="hidden" :name="'skills[' + i + ']'" :value="skill">
                        <button type="button" @click="skills.splice(i, 1)" class="text-indigo-400 hover:text-red-500 leading-none">&times;</button>
                    </span>
                </template>
            </div>
            <div class="flex gap-2">
                <input type="text" placeholder="@lang('navigation.org_admin_category_skill_placeholder')"
                       @keydown.enter.prevent="if ($event.target.value.trim()) { skills.push($event.target.value.trim()); $event.target.value = ''; }"
                       class="flex-1 px-3 py-1.5 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-xs focus:ring-2 focus:ring-indigo-500">
                <button type="button" @click="let inp = $event.target.previousElementSibling; if (inp.value.trim()) { skills.push(inp.value.trim()); inp.value = ''; }"
                        class="px-3 py-1.5 bg-indigo-600 text-white text-xs rounded-lg hover:bg-indigo-700">+</button>
            </div>
        </div>

        <div class="flex items-center gap-3 pt-4">
            <button type="submit" class="px-6 py-2 bg-indigo-600 text-white rounded-lg text-sm hover:bg-indigo-700 font-medium">
                {{ $category ? __('navigation.org_admin_translation_save') : __('navigation.org_admin_translation_create') }}
            </button>
            <a href="{{ route('organization.admin.categories', $organization) }}" class="px-6 py-2 border border-gray-300 dark:border-gray-600 rounded-lg text-sm text-gray-600 dark:text-gray-400">
                @lang('navigation.org_admin_translation_cancel')
            </a>
        </div>
    </form>

    <script>
        document.querySelector('input[name="color"]')?.addEventListener('input', function() {
            document.querySelector('input[name="color_hex"]').value = this.value;
        });
        document.querySelector('input[name="color_hex"]')?.addEventListener('input', function() {
            document.querySelector('input[name="color"]').value = this.value;
        });
    </script>
</x-org-admin-layout>