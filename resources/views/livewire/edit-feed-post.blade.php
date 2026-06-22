@php
    $organizationRouteParam = request()->route('organization') ?: currentOrganization()?->slug;
    $usesDefaultOrganizationRoute = (bool) currentOrganization()?->is_default;
    $myPostsUrl = $usesDefaultOrganizationRoute && Route::has('flux.my')
        ? route('flux.my')
        : route('organization.flux.my', ['organization' => $organizationRouteParam]);
    $fluxUrl = $usesDefaultOrganizationRoute && Route::has('flux')
        ? route('flux')
        : route('organization.flux', ['organization' => $organizationRouteParam]);
@endphp

<div class="mx-auto max-w-2xl px-4 py-6 sm:px-6 lg:px-8">
    <div class="mb-6">
        <div class="flex items-center gap-4">
            <a href="{{ $fluxUrl }}" class="text-sm font-semibold text-indigo-600 hover:underline dark:text-indigo-400">← {{ __('feed-post.back_to_feed') }}</a>
            <a href="{{ $myPostsUrl }}" class="text-sm font-semibold text-indigo-600 hover:underline dark:text-indigo-400">{{ __('feed-post.my_posts') }}</a>
        </div>
        <h1 class="mt-3 text-2xl font-bold text-gray-900 dark:text-gray-100">{{ __('feed-post.edit_post') }}</h1>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('feed-post.edit_subtitle') }}</p>
    </div>

    <form wire:submit="submit" class="space-y-5 rounded-3xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-900 sm:p-6">
        <div>
            <label for="title" class="block text-sm font-semibold text-gray-800 dark:text-gray-100">{{ __('feed-post.title_optional') }}</label>
            <input id="title" type="text" wire:model="title" maxlength="255" class="mt-2 w-full rounded-2xl border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-700 dark:bg-gray-950 dark:text-gray-100" placeholder="{{ __('feed-post.title_placeholder') }}">
            @error('title') <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="content" class="block text-sm font-semibold text-gray-800 dark:text-gray-100">{{ __('feed-post.message') }}</label>

            <div x-data="{ insertMarkdown(type) { const ta = document.getElementById('content'); if (!ta) return; const start = ta.selectionStart; const end = ta.selectionEnd; const val = ta.value; const sel = val.substring(start, end); let before = '', after = ''; if (type === 'bold') { before = '**'; after = '**'; } else if (type === 'link') { before = '['; after = '](url)'; } else if (type === 'h2') { before = '## '; } else if (type === 'h3') { before = '### '; } else if (type === 'list') { before = '\n- '; } let newVal; if (sel && type !== 'h2' && type !== 'h3' && type !== 'list') { newVal = val.substring(0, start) + before + sel + after + val.substring(end); } else { newVal = val.substring(0, start) + before + after + val.substring(end); } ta.value = newVal; ta.dispatchEvent(new Event('input', { bubbles: true })); ta.focus(); const pos = start + before.length; ta.setSelectionRange(pos, pos); } }" class="mt-2 flex flex-wrap gap-1">
                <button type="button" @click="insertMarkdown('bold')" class="rounded-lg px-2 py-1 text-xs font-bold text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800" title="{{ __('feed-post.toolbar_bold_title') }}">{{ __('feed-post.toolbar_bold') }}</button>
                <button type="button" @click="insertMarkdown('link')" class="rounded-lg px-2 py-1 text-xs font-semibold text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800" title="{{ __('feed-post.link_title') }}">{{ __('feed-post.toolbar_link') }}</button>
                <button type="button" @click="insertMarkdown('h2')" class="rounded-lg px-2 py-1 text-xs font-semibold text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800" title="{{ __('feed-post.h2_title') }}">H2</button>
                <button type="button" @click="insertMarkdown('h3')" class="rounded-lg px-2 py-1 text-xs font-semibold text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800" title="{{ __('feed-post.h3_title') }}">H3</button>
                <button type="button" @click="insertMarkdown('list')" class="rounded-lg px-2 py-1 text-xs font-semibold text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800" title="{{ __('feed-post.list_title') }}">{{ __('feed-post.toolbar_list') }}</button>
            </div>

            <textarea id="content" wire:model="content" rows="7" class="mt-1 w-full rounded-2xl border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-700 dark:bg-gray-950 dark:text-gray-100" placeholder="{{ __('feed-post.content_placeholder') }}"></textarea>
            @error('content') <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
        </div>

        <div class="space-y-3 rounded-2xl border border-gray-200 p-4 dark:border-gray-700">
            <div>
                <h2 class="text-sm font-semibold text-gray-800 dark:text-gray-100">{{ __('feed-post.publication') }}</h2>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('feed-post.edit_status') }}</p>
            </div>

            <div class="flex flex-wrap gap-2">
                <label class="flex items-center gap-2 rounded-full border px-4 py-2 text-sm cursor-pointer transition {{ $mode === 'publish' ? 'border-indigo-300 bg-indigo-50 text-indigo-700 dark:border-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-200' : 'border-gray-200 bg-white hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-900 dark:hover:bg-gray-800' }}">
                    <input type="radio" wire:model.live="mode" value="publish" class="sr-only">
                    {{ __('feed-post.status_published') }}
                </label>
                <label class="flex items-center gap-2 rounded-full border px-4 py-2 text-sm cursor-pointer transition {{ $mode === 'draft' ? 'border-indigo-300 bg-indigo-50 text-indigo-700 dark:border-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-200' : 'border-gray-200 bg-white hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-900 dark:hover:bg-gray-800' }}">
                    <input type="radio" wire:model.live="mode" value="draft" class="sr-only">
                    {{ __('feed-post.draft') }}
                </label>
                <label class="flex items-center gap-2 rounded-full border px-4 py-2 text-sm cursor-pointer transition {{ $mode === 'schedule' ? 'border-indigo-300 bg-indigo-50 text-indigo-700 dark:border-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-200' : 'border-gray-200 bg-white hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-900 dark:hover:bg-gray-800' }}">
                    <input type="radio" wire:model.live="mode" value="schedule" class="sr-only">
                    {{ __('feed-post.status_scheduled') }}
                </label>
            </div>

            @if($mode === 'schedule')
                <div>
                    <label for="scheduledAt" class="block text-sm font-semibold text-gray-800 dark:text-gray-100">{{ __('feed-post.schedule_label') }}</label>
                    <input id="scheduledAt" type="datetime-local" wire:model="scheduledAt" class="mt-2 w-full rounded-2xl border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-700 dark:bg-gray-950 dark:text-gray-100">
                    @error('scheduledAt') <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
            @endif
        </div>

        @if($post->image_path)
            <div>
                <label class="block text-sm font-semibold text-gray-800 dark:text-gray-100">{{ __('feed-post.current_image') }}</label>
                <div class="mt-2 overflow-hidden rounded-2xl border border-gray-200 dark:border-gray-700">
                    <img src="{{ $post->imageUrl() }}" alt="" class="max-h-48 w-full object-cover">
                </div>
            </div>
        @endif

        <div>
            <label for="image" class="block text-sm font-semibold text-gray-800 dark:text-gray-100">{{ $post->image_path ? __('feed-post.new_image_optional') : __('feed-post.image_optional') }}</label>
            <input id="image" type="file" wire:model="image" accept="image/*" class="mt-2 block w-full text-sm text-gray-600 file:mr-4 file:rounded-full file:border-0 file:bg-indigo-50 file:px-4 file:py-2 file:text-sm file:font-semibold file:text-indigo-700 hover:file:bg-indigo-100 dark:text-gray-300 dark:file:bg-indigo-900/40 dark:file:text-indigo-200">
            <div wire:loading wire:target="image" class="mt-2 text-sm text-gray-500 dark:text-gray-400">{{ __('feed-post.image_loading') }}</div>
            @if($image)
                <div class="mt-3 overflow-hidden rounded-2xl border border-gray-200 dark:border-gray-700">
                    <img src="{{ $image->temporaryUrl() }}" alt="{{ __('feed-post.image_preview') }}" class="max-h-48 w-full object-cover">
                    <button type="button" wire:click="removeImage" class="w-full bg-gray-50 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-100 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700">{{ __('feed-post.remove_new') }}</button>
                </div>
            @endif
            @error('image') <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="loopMessage" class="block text-sm font-semibold text-gray-800 dark:text-gray-100">{{ __('feed-post.loop_message') }}</label>
            <textarea id="loopMessage" wire:model="loopMessage" rows="2" class="mt-2 w-full rounded-2xl border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-700 dark:bg-gray-950 dark:text-gray-100" placeholder="{{ __('feed-post.loop_message_placeholder') }}"></textarea>
            @error('loopMessage') <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
        </div>

        <div class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
            <a href="{{ $myPostsUrl }}" class="inline-flex justify-center rounded-full border border-gray-300 px-5 py-2.5 text-sm font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-gray-800">{{ __('feed-post.cancel') }}</a>
            <button type="submit" class="inline-flex justify-center rounded-full bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-700 disabled:opacity-60" wire:loading.attr="disabled">
                {{ __('feed-post.submit_save') }}
            </button>
        </div>
    </form>
</div>
