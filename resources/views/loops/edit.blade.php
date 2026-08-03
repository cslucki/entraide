<x-app-layout>
    @php
        // Aliased to $currentLoop (not $loop): Blade's @foreach injects its own $loop
        // iteration variable into the current scope, and — since PHP variables are not
        // block-scoped — it stays overwritten for the rest of the template even after
        // @endforeach. Using $loop for the model here would silently break every
        // reference to it below the access-mode loop.
        $currentLoop = $loop;
        $_org = request()->route('organization');
        $_loopRoute = function ($name, $params = []) use ($_org) {
            if ($_org && request()->routeIs('organization.*') && Route::has('organization.loops.'.$name)) {
                return route('organization.loops.'.$name, array_merge(['organization' => $_org], $params));
            }
            return route('loops.'.$name, $params);
        };
        $selectedAccessMode = old('access_mode', $currentLoop->access_mode);
    @endphp
    <div class="max-w-2xl mx-auto px-4 py-8">
        <div class="mb-8">
            <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">Modifier la boucle</h1>
            <p class="text-gray-500 dark:text-gray-400 text-sm mt-1">{{ $currentLoop->name }}</p>
        </div>

        <form method="POST" action="{{ $_loopRoute('update', ['loop' => $currentLoop]) }}" enctype="multipart/form-data" class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-6 space-y-6">
            @csrf
            @method('PUT')

            <div>
                <label for="name" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Nom de la boucle *</label>
                <input type="text" name="name" id="name" value="{{ old('name', $currentLoop->name) }}" required
                       class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-indigo-500 focus:border-transparent">
                @error('name')
                    <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="tagline" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('loops.tagline_label') }}</label>
                <input type="text" name="tagline" id="tagline" value="{{ old('tagline', $currentLoop->tagline) }}" maxlength="140"
                       placeholder="{{ __('loops.tagline_placeholder') }}"
                       class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-indigo-500 focus:border-transparent">
                @error('tagline')
                    <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="description" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Description</label>
                <textarea name="description" id="description" rows="4"
                          class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-indigo-500 focus:border-transparent">{{ old('description', $currentLoop->description) }}</textarea>
                @error('description')
                    <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="cover_image" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('loops.cover_image_label') }}</label>
                @if($currentLoop->cover_image_url)
                    <div class="mb-2 flex items-center gap-3">
                        <img src="{{ $currentLoop->cover_image_url }}" alt="" class="h-16 w-28 rounded-lg object-cover">
                        <label class="flex items-center gap-1.5 text-xs text-gray-500 dark:text-gray-400">
                            <input type="checkbox" name="remove_cover_image" value="1" class="rounded text-red-600 focus:ring-red-500">
                            {{ __('loops.cover_image_remove') }}
                        </label>
                    </div>
                @endif
                <input type="file" name="cover_image" id="cover_image" accept="image/png,image/jpeg,image/webp"
                       class="w-full text-sm text-gray-600 dark:text-gray-300 file:mr-3 file:rounded-lg file:border-0 file:bg-gray-100 file:px-3 file:py-2 file:text-sm file:font-medium file:text-gray-700 hover:file:bg-gray-200 dark:file:bg-gray-700 dark:file:text-gray-200">
                <p class="mt-1 text-xs text-gray-400 dark:text-gray-500">{{ __('loops.cover_image_help') }}</p>
                @error('cover_image')
                    <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <span class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">{{ __('loops.access_mode_label') }}</span>
                <div class="space-y-2">
                    @foreach(['open', 'request', 'invitation'] as $mode)
                        <label class="flex cursor-pointer items-start gap-3 rounded-lg border border-gray-200 p-3 hover:bg-gray-50 has-[:checked]:border-indigo-400 has-[:checked]:bg-indigo-50 dark:border-gray-700 dark:hover:bg-gray-700/50 dark:has-[:checked]:border-indigo-600 dark:has-[:checked]:bg-indigo-900/20">
                            <input type="radio" name="access_mode" value="{{ $mode }}" {{ $selectedAccessMode === $mode ? 'checked' : '' }}
                                   class="mt-0.5 text-indigo-600 focus:ring-indigo-500">
                            <span>
                                <span class="block text-sm font-medium text-gray-800 dark:text-gray-100">{{ __('loops.access_mode_'.$mode) }}</span>
                                <span class="block text-xs text-gray-500 dark:text-gray-400">{{ __('loops.access_mode_'.$mode.'_desc') }}</span>
                            </span>
                        </label>
                    @endforeach
                </div>
                @error('access_mode')
                    <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                @enderror
            </div>


            @if($domains->isNotEmpty())
                <div>
                    <span class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('loops.domains_label') }}</span>
                    <p class="mb-2 text-xs text-gray-400 dark:text-gray-500">{{ __('loops.domains_help') }}</p>
                    {{-- Plain checkboxes: an Organization holds a handful of domains,
                         a heavier multiselect widget would be overkill here. The 3-max
                         rule is enforced server-side; Alpine only guides the user. --}}
                    <div x-data="{ picked: {{ \Illuminate\Support\Js::from(old('category_ids', $currentLoop->categories->pluck('id')->all())) }}, max: 3 }" class="flex flex-wrap gap-2">
                        @foreach($domains as $domain)
                            <label class="inline-flex cursor-pointer items-center gap-2 rounded-lg border border-gray-200 px-3 py-1.5 text-sm has-[:checked]:border-indigo-400 has-[:checked]:bg-indigo-50 dark:border-gray-700 dark:has-[:checked]:border-indigo-600 dark:has-[:checked]:bg-indigo-900/20">
                                <input type="checkbox" name="category_ids[]" value="{{ $domain->id }}"
                                       x-model="picked"
                                       x-bind:disabled="picked.length >= max && !picked.includes('{{ $domain->id }}')"
                                       class="rounded text-indigo-600 focus:ring-indigo-500 disabled:opacity-40">
                                <span class="text-gray-700 dark:text-gray-200">{{ $domain->displayName('loops') }}</span>
                            </label>
                        @endforeach
                    </div>
                    <p class="mt-1 text-xs text-gray-400 dark:text-gray-500" x-show="picked.length >= max" x-cloak>{{ __('loops.domains_max') }}</p>
                    @error('category_ids')
                        <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                    @enderror
                </div>
            @endif

            <div class="flex items-center gap-3">
                <button type="submit"
                        class="px-6 py-2 text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700 rounded-lg transition">
                    Enregistrer
                </button>
                <a href="{{ $_loopRoute('show', ['loop' => $currentLoop]) }}"
                   class="px-6 py-2 text-sm font-medium text-gray-600 dark:text-gray-400 border border-gray-300 dark:border-gray-600 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition">
                    Annuler
                </a>
            </div>
        </form>
    </div>
</x-app-layout>
