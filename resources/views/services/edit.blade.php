<x-app-layout>
    <div class="max-w-3xl mx-auto px-4 py-8">
        <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100 mb-6">{{ __('services.edit.heading') }}</h1>

        @if($errors->any())
        <div class="mb-4 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-700 text-red-700 dark:text-red-300 px-4 py-3 rounded-lg text-sm">
            <ul class="list-disc ml-4">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
        </div>
        @endif

        @php $_isOrgRoute = str_starts_with(Route::currentRouteName(), 'organization.'); $_svcOrgSlug = $_isOrgRoute ? $organization?->slug : null; $_svcUpdateAction = $_svcOrgSlug && Route::has('organization.services.update') ? route('organization.services.update', ['organization' => $_svcOrgSlug, 'service' => $service]) : route('services.update', $service); @endphp
    <form method="POST" action="{{ $_svcUpdateAction }}" enctype="multipart/form-data"
              x-data="{
                selectedCategory: '{{ old('category_id', $service->category_id) }}',
                tags: '{{ old('tags', $service->tags->pluck('name')->join(',')) }}',
                tagList: [],
                tagInput: '',
                addTag() { if (!this.tagInput.trim() || this.tagList.length >= 5) return; this.tagList.push(this.tagInput.trim()); this.tags = this.tagList.join(','); this.tagInput = ''; },
                removeTag(i) { this.tagList.splice(i,1); this.tags = this.tagList.join(','); },
                init() { if(this.tags) this.tagList = this.tags.split(',').filter(t=>t); }
              }">
            @csrf @method('PUT')

            <div class="mb-5">
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('marketplace.title') }} {{ __('marketplace.required') }}</label>
                <input type="text" name="title" value="{{ old('title', $service->title) }}" required maxlength="255"
                    class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-indigo-500">
            </div>

            <div class="mb-5">
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('marketplace.description') }} {{ __('marketplace.required') }}</label>
                <textarea name="description" rows="5" required
                    class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-indigo-500">{{ old('description', $service->description) }}</textarea>
            </div>

            <div class="mb-5">
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('marketplace.category') }} {{ __('marketplace.required') }}</label>
                <select name="category_id" required x-model="selectedCategory"
                    class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-indigo-500">
                    @foreach($categories as $cat)
                    <option value="{{ $cat->id }}">{{ $cat->name_b2c }}</option>
                    @endforeach
                </select>
            </div>

            <div class="mb-5" x-show="selectedCategory">
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">{{ __('services.edit.skill_label') }}</label>
                @foreach($categories as $cat)
                <div x-show="selectedCategory === '{{ $cat->id }}'">
                    <div class="flex flex-wrap gap-2">
                        @foreach($cat->skills as $skill)
                        <label class="flex items-center gap-2 px-3 py-1.5 border border-gray-200 dark:border-gray-600 rounded-lg cursor-pointer hover:border-indigo-400 has-[:checked]:border-indigo-600 has-[:checked]:bg-indigo-50 dark:has-[:checked]:bg-indigo-900/30 text-sm dark:text-gray-300 dark:text-gray-300">
                            <input type="checkbox" name="skills[]" value="{{ $skill->id }}"
                                {{ $service->skills->contains($skill->id) || in_array($skill->id, old('skills', [])) ? 'checked' : '' }}
                                class="text-indigo-600">
                            {{ $skill->name }}
                        </label>
                        @endforeach
                    </div>
                </div>
                @endforeach
            </div>

            <!-- Images -->
            <div class="mb-5">
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('services.edit.images_label') }}</label>

                @if($service->images->isNotEmpty())
                <div class="flex flex-wrap gap-3 mb-4">
                    @foreach($service->images as $img)
                    <div class="relative" x-data="{ deleting: false }">
                        <img src="{{ $img->url }}"
                             class="w-24 h-24 object-cover rounded-lg border border-gray-200 dark:border-gray-700"
                             :class="deleting ? 'opacity-40 ring-2 ring-red-500' : ''">
                        <label class="absolute top-1 right-1 text-white p-1 rounded-full cursor-pointer transition"
                               :class="deleting ? 'bg-gray-500' : 'bg-red-600 hover:bg-red-700'">
                            <input type="checkbox" name="delete_images[]" value="{{ $img->id }}"
                                   @change="deleting = $event.target.checked" class="sr-only">
                            <svg class="w-4 h-4 pointer-events-none" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M6 18L18 6M6 6l12 12"/></svg>
                        </label>
                    </div>
                    @endforeach
                </div>
                @endif

                <div x-data="{ newImages: [] }">
                    <input type="file" name="images[]" multiple accept="image/*"
                        @change="newImages = Array.from($event.target.files)"
                        class="block w-full text-sm text-gray-600 dark:text-gray-400 file:mr-3 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-medium file:bg-indigo-50 file:text-indigo-700 dark:file:bg-indigo-900/30 dark:file:text-indigo-300 hover:file:bg-indigo-100">
                    <p class="text-xs text-gray-400 mt-1">{{ __('services.edit.images_help') }}</p>
                    <div class="flex flex-wrap gap-2 mt-2">
                        <template x-for="(img, i) in newImages" :key="i">
                            <div class="relative w-20 h-20 border rounded overflow-hidden bg-gray-100 dark:bg-gray-700">
                                <img :src="URL.createObjectURL(img)" class="w-full h-full object-cover">
                            </div>
                        </template>
                    </div>
                </div>
            </div>

            <div class="mb-5">
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('services.edit.tags_label') }} <span class="text-gray-400">{{ __('services.edit.tags_hint') }}</span></label>
                <input type="hidden" name="tags" x-bind:value="tags">
                <div class="flex flex-wrap gap-2 mb-2">
                    <template x-for="(tag, i) in tagList" :key="i">
                        <span class="flex items-center gap-1 px-2 py-0.5 bg-indigo-100 dark:bg-indigo-900/40 text-indigo-700 dark:text-indigo-300 rounded text-sm">
                            <span x-text="tag"></span>
                            <button type="button" @click="removeTag(i)" class="ml-1 text-indigo-400 hover:text-indigo-700">×</button>
                        </span>
                    </template>
                </div>
                <div class="flex gap-2" x-show="tagList.length < 5">
                    <input type="text" x-model="tagInput" @keydown.enter.prevent="addTag" placeholder="{{ __('services.edit.add_tag_placeholder') }}"
                        class="flex-1 px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-indigo-500">
                    <button type="button" @click="addTag" class="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg text-sm hover:bg-gray-50 dark:hover:bg-gray-700 dark:text-gray-300">{{ __('services.edit.add_tag') }}</button>
                </div>
            </div>

            <div class="mb-5">
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">{{ __('services.edit.delivery_mode') }}</label>
                <div class="flex gap-3">
                    @foreach(['remote' => __('services.edit.remote'), 'onsite' => __('services.edit.onsite'), 'both' => __('services.edit.both')] as $val => $label)
                    <label class="flex-1 flex items-center justify-center gap-2 px-3 py-2.5 border rounded-lg cursor-pointer text-sm font-medium hover:border-indigo-400 has-[:checked]:border-indigo-600 has-[:checked]:bg-indigo-50 dark:has-[:checked]:bg-indigo-900/30 dark:text-gray-300 border-gray-200 dark:border-gray-600 transition">
                        <input type="radio" name="delivery_mode" value="{{ $val }}" {{ (old('delivery_mode', $service->delivery_mode) === $val) ? 'checked' : '' }} required class="sr-only">
                        {{ $label }}
                    </label>
                    @endforeach
                </div>
            </div>

            <div class="mb-5">
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('services.edit.status_label') }}</label>
                <select name="status"
                    class="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-indigo-500">
                    <option value="active" {{ old('status', $service->status) === 'active' ? 'selected' : '' }}>{{ __('services.edit.status_active') }}</option>
                    <option value="paused" {{ old('status', $service->status) === 'paused' ? 'selected' : '' }}>{{ __('services.edit.status_paused') }}</option>
                </select>
            </div>

            <div class="mb-8">
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('services.edit.points_requested') }}</label>
                <input type="number" name="points_cost" value="{{ old('points_cost', $service->points_cost) }}" min="40" max="100" required
                    class="w-40 px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-indigo-500">
            </div>

            <div class="flex gap-3">
                <button type="submit" class="px-6 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 font-medium">{{ __('services.edit.save') }}</button>
                <a href="{{ $_svcOrgSlug ? route('organization.dashboard', ['organization' => $_svcOrgSlug]) : route('dashboard') }}" class="px-6 py-2 border border-gray-300 dark:border-gray-600 rounded-lg text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700">{{ __('services.edit.cancel') }}</a>
            </div>
        </form>
    </div>
</x-app-layout>
