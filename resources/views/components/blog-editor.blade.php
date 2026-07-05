@props(['name' => 'content', 'value' => '', 'postId' => null, 'invalid' => false, 'routeAiGenerate' => null, 'routeAiCorrect' => null, 'routeAiRemaining' => null, 'routeUpload' => null])

<style>
.bp-resize-handle {
  position: absolute;
  width: 10px;
  height: 10px;
  background: #fff;
  border: 2px solid #6366f1;
  border-radius: 1px;
  z-index: 10;
}
.bp-resize-handle[data-resize-handle="bottom-right"] {
  cursor: nwse-resize;
  transform: translate(50%, 50%);
}
.bp-resize-handle[data-resize-handle="bottom-left"] {
  cursor: nesw-resize;
  transform: translate(-50%, 50%);
}
.bp-resize-handle[data-resize-handle="top-right"] {
  cursor: nesw-resize;
  transform: translate(50%, -50%);
}
.bp-resize-handle[data-resize-handle="top-left"] {
  cursor: nwse-resize;
  transform: translate(-50%, -50%);
}
[data-resize-container].ProseMirror-selectednode {
  outline: 2px solid #6366f1;
  border-radius: 4px;
}
.bp-fullscreen {
  position: fixed !important;
  inset: 1.5rem !important;
  z-index: 50 !important;
  background: #fff !important;
  border-radius: 0.75rem !important;
  box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25) !important;
}
.dark .bp-fullscreen {
  background: #111827 !important;
}
.bp-fullscreen .ProseMirror {
  min-height: calc(100vh - 12rem) !important;
  max-height: calc(100vh - 12rem) !important;
}
.bp-fullscreen-resize-handle {
  position: absolute;
  top: 0;
  bottom: 0;
  right: -6px;
  width: 12px;
  cursor: ew-resize;
  z-index: 60;
}
.bp-fullscreen-resize-handle::after {
  content: '';
  position: absolute;
  top: 50%;
  right: 4px;
  width: 4px;
  height: 32px;
  background: #d1d5db;
  border-radius: 2px;
  transform: translateY(-50%);
}
.bp-fullscreen-resize-handle:hover::after {
  background: #6366f1;
  height: 48px;
}
.bp-editor-dark .ProseMirror {
  background: #1f2937 !important;
  color: #f3f4f6 !important;
}
.bp-editor-dark .ProseMirror * {
  color: inherit !important;
}
.bp-editor-dark [data-color] {
  filter: brightness(1.2) !important;
}

</style>

<div
    x-data="blogEditor"
    :class="{ 'bp-fullscreen': fullscreen, 'bp-editor-dark': editorDark }"
    x-id="['blog-editor']"
    data-editor-name="{{ $name }}"
    data-editor-value="{{ $value }}"
    data-editor-post-id="{{ $postId ?: '' }}"
    data-editor-csrf="{{ csrf_token() }}"
    data-editor-invalid="{{ $invalid ? '1' : '0' }}"
    data-editor-error-upload="{{ __('blog.editor_upload_error') }}"
    data-editor-error-ai="{{ __('blog.editor_ai_error') }}"
    data-editor-link-prompt="{{ __('blog.editor_link_prompt') }}"
    data-editor-generate-require="{{ __('blog.editor_generate_require_input') }}"
    data-editor-correct-require="{{ __('blog.editor_correct_require_input') }}"
    data-route-upload="{{ $routeUpload ?? route('blog.upload-image') }}"
    data-route-ai-remaining="{{ $routeAiRemaining ?? route('blog.ai-remaining') }}"
    data-route-ai-generate="{{ $routeAiGenerate ?? route('blog.ai-generate') }}"
    data-route-ai-correct="{{ $routeAiCorrect ?? route('blog.ai-correct') }}"
    class="space-y-1"
>
    {{-- Toolbar --}}
    <div class="flex flex-wrap items-center gap-1 pb-2 border-b border-gray-200 dark:border-gray-700">
        {{-- Undo / Redo --}}
        <button type="button" @click="exec('undo')" class="shrink-0 rounded-lg px-2 py-1 text-xs font-semibold text-gray-500 hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-gray-800 transition" title="{{ __('blog.editor_undo') }}">
            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 15L3 9m0 0l6-6M3 9h12a6 6 0 010 12h-3"/></svg>
        </button>
        <button type="button" @click="exec('redo')" class="shrink-0 rounded-lg px-2 py-1 text-xs font-semibold text-gray-500 hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-gray-800 transition" title="{{ __('blog.editor_redo') }}">
            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 15l6-6m0 0l-6-6m6 6H9a6 6 0 000 12h3"/></svg>
        </button>

        <span class="shrink-0 w-px h-4 bg-gray-300 dark:bg-gray-600 mx-1"></span>

        {{-- Formatting: B / I / U as single letters --}}
        <button type="button" @click="exec('toggleBold')" :class="btnClass('bold')"
            class="shrink-0 rounded-lg px-2.5 py-1 text-xs font-bold transition" title="{{ __('blog.editor_bold') }}">B</button>
        <button type="button" @click="exec('toggleItalic')" :class="btnClass('italic')"
            class="shrink-0 rounded-lg px-2.5 py-1 text-xs italic transition" title="{{ __('blog.editor_italic') }}">I</button>
        <button type="button" @click="exec('toggleUnderline')" :class="btnClass('underline')"
            class="shrink-0 rounded-lg px-2.5 py-1 text-xs underline transition" title="{{ __('blog.editor_underline') }}">U</button>

        <span class="shrink-0 w-px h-4 bg-gray-300 dark:bg-gray-600 mx-1"></span>

        {{-- Heading dropdown --}}
        <div x-data="{ headingOpen: false }" class="relative shrink-0" @click.outside="headingOpen = false">
            <button type="button" @click="headingOpen = !headingOpen" :class="btnClass(activeStates?.heading1 ? 'heading1' : activeStates?.heading2 ? 'heading2' : activeStates?.heading3 ? 'heading3' : activeStates?.heading4 ? 'heading4' : '' )"
                class="rounded-lg px-2.5 py-1 text-xs font-semibold text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800 transition">
                <span x-text="activeStates?.heading1 ? 'H1' : activeStates?.heading2 ? 'H2' : activeStates?.heading3 ? 'H3' : activeStates?.heading4 ? 'H4' : 'P'"></span>
                <svg class="w-3 h-3 inline ml-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
            </button>
            <div x-show="headingOpen" x-cloak class="absolute top-full left-0 mt-1 z-50 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-600 rounded-lg shadow-lg py-1 min-w-[140px]">
                <button type="button" @click="exec('toggleParagraph'); headingOpen = false" :class="!activeStates?.heading1 && !activeStates?.heading2 && !activeStates?.heading3 && !activeStates?.heading4 ? 'bg-indigo-100 dark:bg-indigo-900/40 text-indigo-700 dark:text-indigo-300' : 'text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700'" class="block w-full text-left px-3 py-1.5 text-xs transition">{{ __('blog.editor_paragraph') }}</button>
                <button type="button" @click="exec('toggleH1'); headingOpen = false" :class="activeStates?.heading1 ? 'bg-indigo-100 dark:bg-indigo-900/40 text-indigo-700 dark:text-indigo-300' : 'text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700'" class="block w-full text-left px-3 py-1.5 text-xs font-bold transition">{{ __('blog.editor_heading1') }}</button>
                <button type="button" @click="exec('toggleH2'); headingOpen = false" :class="activeStates?.heading2 ? 'bg-indigo-100 dark:bg-indigo-900/40 text-indigo-700 dark:text-indigo-300' : 'text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700'" class="block w-full text-left px-3 py-1.5 text-xs font-semibold transition">{{ __('blog.editor_heading2') }}</button>
                <button type="button" @click="exec('toggleH3'); headingOpen = false" :class="activeStates?.heading3 ? 'bg-indigo-100 dark:bg-indigo-900/40 text-indigo-700 dark:text-indigo-300' : 'text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700'" class="block w-full text-left px-3 py-1.5 text-xs font-medium transition">{{ __('blog.editor_heading3') }}</button>
                <button type="button" @click="exec('toggleH4'); headingOpen = false" :class="activeStates?.heading4 ? 'bg-indigo-100 dark:bg-indigo-900/40 text-indigo-700 dark:text-indigo-300' : 'text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700'" class="block w-full text-left px-3 py-1.5 text-xs transition">{{ __('blog.editor_heading4') }}</button>
            </div>
        </div>

        {{-- Lists dropdown (bullet + ordered merged) --}}
        <div x-data="{ listsOpen: false }" class="relative shrink-0" @click.outside="listsOpen = false">
            <button type="button" @click="listsOpen = !listsOpen" :class="activeStates?.bulletList || activeStates?.orderedList ? 'bg-indigo-100 dark:bg-indigo-900/40 text-indigo-700 dark:text-indigo-300' : 'text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800'"
                class="rounded-lg px-2.5 py-1 text-xs font-semibold transition" title="{{ __('blog.editor_list') }}">
                <svg class="w-3.5 h-3.5 inline" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16"/></svg>
                <svg class="w-3 h-3 inline ml-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
            </button>
            <div x-show="listsOpen" x-cloak class="absolute top-full left-0 mt-1 z-50 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-600 rounded-lg shadow-lg py-1 min-w-[150px]">
                <button type="button" @click="exec('toggleBulletList'); listsOpen = false" :class="activeStates?.bulletList ? 'bg-indigo-100 dark:bg-indigo-900/40 text-indigo-700 dark:text-indigo-300' : 'text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700'" class="block w-full text-left px-3 py-1.5 text-xs transition">
                    <svg class="w-3.5 h-3.5 inline mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16"/></svg>
                    {{ __('blog.editor_list') }}
                </button>
                <button type="button" @click="exec('toggleOrderedList'); listsOpen = false" :class="activeStates?.orderedList ? 'bg-indigo-100 dark:bg-indigo-900/40 text-indigo-700 dark:text-indigo-300' : 'text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700'" class="block w-full text-left px-3 py-1.5 text-xs transition">
                    <svg class="w-3.5 h-3.5 inline mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 10h16M4 14h16M4 18h16"/><text x="2" y="9" class="text-[8px]" fill="currentColor" stroke="none">1</text><text x="2" y="13" class="text-[8px]" fill="currentColor" stroke="none">2</text><text x="2" y="17" class="text-[8px]" fill="currentColor" stroke="none">3</text></svg>
                    {{ __('blog.editor_ordered_list') }}
                </button>
            </div>
        </div>

        <span class="shrink-0 w-px h-4 bg-gray-300 dark:bg-gray-600 mx-1"></span>

        {{-- Link / Code / Table / Image with icons --}}
        <button type="button" @click="openLink()" :class="btnClass('link')"
            class="shrink-0 rounded-lg px-2.5 py-1 text-xs font-semibold transition" title="{{ __('blog.editor_link') }}">
            <svg class="w-3.5 h-3.5 inline" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/></svg>
        </button>
        <button type="button" @click="exec('toggleCodeBlock')" :class="btnClass('codeBlock')"
            class="shrink-0 rounded-lg px-2.5 py-1 text-xs font-semibold transition" title="{{ __('blog.editor_code') }}">
            <svg class="w-3.5 h-3.5 inline" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4"/></svg>
        </button>
        <button type="button" @click="exec('insertTable')"
            class="shrink-0 rounded-lg px-2.5 py-1 text-xs font-semibold text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800 transition" title="{{ __('blog.editor_table') }}">
            <svg class="w-3.5 h-3.5 inline" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 10h18M3 14h18m-9-4v8m-7 0h14a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
        </button>
        <button type="button" @click="triggerImageUpload" title="{{ __('blog.editor_image') }}"
            class="shrink-0 rounded-lg px-2.5 py-1 text-xs font-semibold text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800 transition">
            <svg class="w-3.5 h-3.5 inline" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
        </button>

        {{-- Image resize 50% --}}
        <button type="button" @click="resizeImage" x-show="activeStates?.image"
            x-cloak class="shrink-0 rounded-lg px-2.5 py-1 text-xs font-semibold text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800 transition"
            :title="activeStates?.imageResized ? @js(__('blog.editor_reset_size')) : @js(__('blog.editor_resize_image'))">
            <span x-text="activeStates?.imageResized ? '100%' : '50%'"></span>
        </button>
        <input type="file" accept="image/*" class="hidden" x-ref="imageInput" @change="uploadImage($event)">

        <span class="shrink-0 w-px h-4 bg-gray-300 dark:bg-gray-600 mx-1"></span>

        {{-- Highlight dropdown --}}
        <div x-data="{ highlightOpen: false }" class="relative shrink-0" @click.outside="highlightOpen = false">
            <button type="button" @click="highlightOpen = !highlightOpen" :class="btnClass('highlight')"
                class="rounded-lg px-2.5 py-1 text-xs font-semibold transition" title="{{ __('blog.editor_highlight') }}">
                <svg class="w-3.5 h-3.5 inline" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/></svg>
            </button>
            <div x-show="highlightOpen" x-cloak class="absolute top-full left-0 mt-1 z-50 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-600 rounded-lg shadow-lg py-1 min-w-[140px]">
                <button type="button" @click="toggleHighlight('#fef08a'); highlightOpen = false" class="block w-full text-left px-3 py-1.5 text-xs text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 transition">
                    <span class="inline-block w-3 h-3 rounded mr-2 align-middle" style="background:#fef08a"></span> {{ __('blog.editor_highlight') }}
                </button>
                <button type="button" @click="toggleHighlight('#bbf7d0'); highlightOpen = false" class="block w-full text-left px-3 py-1.5 text-xs text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 transition">
                    <span class="inline-block w-3 h-3 rounded mr-2 align-middle" style="background:#bbf7d0"></span> Green
                </button>
                <button type="button" @click="toggleHighlight('#bfdbfe'); highlightOpen = false" class="block w-full text-left px-3 py-1.5 text-xs text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 transition">
                    <span class="inline-block w-3 h-3 rounded mr-2 align-middle" style="background:#bfdbfe"></span> Blue
                </button>
                <button type="button" @click="toggleHighlight('#fecaca'); highlightOpen = false" class="block w-full text-left px-3 py-1.5 text-xs text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 transition">
                    <span class="inline-block w-3 h-3 rounded mr-2 align-middle" style="background:#fecaca"></span> Red
                </button>
                <button type="button" @click="toggleHighlight('#e9d5ff'); highlightOpen = false" class="block w-full text-left px-3 py-1.5 text-xs text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 transition">
                    <span class="inline-block w-3 h-3 rounded mr-2 align-middle" style="background:#e9d5ff"></span> Purple
                </button>
                <div class="border-t border-gray-100 dark:border-gray-700 my-1"></div>
                <button type="button" @click="toggleHighlight(); highlightOpen = false" class="block w-full text-left px-3 py-1.5 text-xs text-gray-500 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700 transition">
                    ✗ {{ __('blog.editor_reset_color') }}
                </button>
            </div>
        </div>

        {{-- Text align dropdown --}}
        <div x-data="{ alignOpen: false }" class="relative shrink-0" @click.outside="alignOpen = false">
            <button type="button" @click="alignOpen = !alignOpen" class="rounded-lg px-2.5 py-1 text-xs font-semibold text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800 transition" title="{{ __('blog.editor_align_left') }}">
                <svg class="w-3.5 h-3.5 inline" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16" opacity="0.3"/><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h8M4 12h8M4 18h8"/></svg>
            </button>
            <div x-show="alignOpen" x-cloak class="absolute top-full left-0 mt-1 z-50 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-600 rounded-lg shadow-lg py-1 min-w-[120px]">
                <button type="button" @click="setTextAlign('left'); alignOpen = false" :class="activeStates?.textAlign === 'left' || !activeStates?.textAlign ? 'bg-indigo-100 dark:bg-indigo-900/40 text-indigo-700 dark:text-indigo-300' : 'text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700'" class="block w-full text-left px-3 py-1.5 text-xs transition">
                    <svg class="w-3.5 h-3.5 inline mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h10M4 18h14"/></svg>
                    {{ __('blog.editor_align_left') }}
                </button>
                <button type="button" @click="setTextAlign('center'); alignOpen = false" :class="activeStates?.textAlign === 'center' ? 'bg-indigo-100 dark:bg-indigo-900/40 text-indigo-700 dark:text-indigo-300' : 'text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700'" class="block w-full text-left px-3 py-1.5 text-xs transition">
                    <svg class="w-3.5 h-3.5 inline mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M6 12h12M4 18h16"/></svg>
                    {{ __('blog.editor_align_center') }}
                </button>
                <button type="button" @click="setTextAlign('right'); alignOpen = false" :class="activeStates?.textAlign === 'right' ? 'bg-indigo-100 dark:bg-indigo-900/40 text-indigo-700 dark:text-indigo-300' : 'text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700'" class="block w-full text-left px-3 py-1.5 text-xs transition">
                    <svg class="w-3.5 h-3.5 inline mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M8 12h12M4 18h16"/></svg>
                    {{ __('blog.editor_align_right') }}
                </button>
            </div>
        </div>

        {{-- Text color dropdown --}}
        <div x-data="{ colorOpen: false }" class="relative shrink-0" @click.outside="colorOpen = false">
            <button type="button" @click="colorOpen = !colorOpen" class="rounded-lg px-2.5 py-1 text-xs font-semibold text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800 transition" title="{{ __('blog.editor_text_color') }}">
                <span class="inline-flex items-center gap-1">
                    <span class="text-xs underline" :style="'text-decoration-color: ' + (activeStates?.textColor || '#000') + '; text-decoration-thickness: 3px'">A</span>
                    <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                </span>
            </button>
            <div x-show="colorOpen" x-cloak class="absolute top-full left-0 mt-1 z-50 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-600 rounded-lg shadow-lg py-1 min-w-[140px]">
                <button type="button" @click="unsetColor(); colorOpen = false" class="block w-full text-left px-3 py-1.5 text-xs text-gray-500 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700 transition">
                    ✗ {{ __('blog.editor_reset_color') }}
                </button>
                <button type="button" @click="setColor('#000000'); colorOpen = false" class="block w-full text-left px-3 py-1.5 text-xs text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 transition">
                    <span class="inline-block w-3 h-3 rounded-full mr-2 align-middle" style="background:#000000"></span> Black
                </button>
                <button type="button" @click="setColor('#dc2626'); colorOpen = false" class="block w-full text-left px-3 py-1.5 text-xs text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 transition">
                    <span class="inline-block w-3 h-3 rounded-full mr-2 align-middle" style="background:#dc2626"></span> Red
                </button>
                <button type="button" @click="setColor('#2563eb'); colorOpen = false" class="block w-full text-left px-3 py-1.5 text-xs text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 transition">
                    <span class="inline-block w-3 h-3 rounded-full mr-2 align-middle" style="background:#2563eb"></span> Blue
                </button>
                <button type="button" @click="setColor('#16a34a'); colorOpen = false" class="block w-full text-left px-3 py-1.5 text-xs text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 transition">
                    <span class="inline-block w-3 h-3 rounded-full mr-2 align-middle" style="background:#16a34a"></span> Green
                </button>
                <button type="button" @click="setColor('#d97706'); colorOpen = false" class="block w-full text-left px-3 py-1.5 text-xs text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 transition">
                    <span class="inline-block w-3 h-3 rounded-full mr-2 align-middle" style="background:#d97706"></span> Orange
                </button>
                <div class="border-t border-gray-100 dark:border-gray-700 my-1"></div>
                <label class="block w-full text-left px-3 py-1.5 text-xs text-gray-500 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700 transition cursor-pointer">
                    <span class="inline-flex items-center gap-1">
                        <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M7 21a4 4 0 01-4-4V5a2 2 0 012-2h4a2 2 0 012 2v12a4 4 0 01-4 4zm0 0h12a2 2 0 002-2v-4a2 2 0 00-2-2h-2.343M11 7.343l1.657-1.657a2 2 0 012.828 0l2.829 2.829a2 2 0 010 2.828l-8.486 8.485M7 17h.01"/></svg>
                        Custom…
                    </span>
                    <input type="color" class="absolute inset-0 opacity-0 cursor-pointer" @change="setColor($event.target.value); colorOpen = false">
                </label>
            </div>
        </div>

        {{-- Spacer + Editor dark mode + Fullscreen --}}
        <span class="shrink-0 w-px h-4 bg-gray-300 dark:bg-gray-600 mx-1"></span>
        <button type="button" @click="editorDark = !editorDark"
            class="shrink-0 rounded-lg px-2.5 py-1 text-xs font-semibold text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800 transition"
            :title="editorDark ? @js(__('blog.editor_light_mode')) : @js(__('blog.editor_dark_mode'))">
            <svg x-show="!editorDark" class="w-3.5 h-3.5 inline" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21.752 15.002A9.718 9.718 0 0118 15.75c-5.385 0-9.75-4.365-9.75-9.75 0-1.33.266-2.597.748-3.752A9.753 9.753 0 003 11.25C3 16.635 7.365 21 12.75 21a9.753 9.753 0 009.002-5.998z"/></svg>
            <svg x-show="editorDark" class="w-3.5 h-3.5 inline" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3v2.25m6.364.386l-1.591 1.591M21 12h-2.25m-.386 6.364l-1.591-1.591M12 18.75V21m-4.773-4.227l-1.591 1.591M5.25 12H3m4.227-4.773L5.636 5.636M15.75 12a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0z"/></svg>
        </button>
        <button type="button" @click="toggleFullscreen" x-cloak
            class="shrink-0 rounded-lg px-2.5 py-1 text-xs font-semibold text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800 transition"
            :title="fullscreen ? @js(__('blog.editor_exit_fullscreen')) : @js(__('blog.editor_fullscreen'))">
            <svg x-show="!fullscreen" class="w-3.5 h-3.5 inline" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 3.75v4.5m0-4.5h4.5m-4.5 0L9 9M3.75 20.25v-4.5m0 4.5h4.5m-4.5 0L9 15M20.25 3.75h-4.5m4.5 0v4.5m0-4.5L15 9m5.25 11.25h-4.5m4.5 0v-4.5m0 4.5L15 15"/></svg>
            <svg x-show="fullscreen" class="w-3.5 h-3.5 inline" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 9V4.5M9 9H4.5M9 9 3.75 3.75M9 15v4.5M9 15H4.5M9 15l-5.25 5.25M15 9h4.5M15 9V4.5M15 9l5.25-5.25M15 15h4.5M15 15v4.5m0-4.5 5.25 5.25"/></svg>
        </button>
    </div>

    {{-- Link popup --}}
    <div x-show="linkPopupOpen" x-cloak
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/20"
        @click.self="linkPopupOpen = false">
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-2xl border border-gray-200 dark:border-gray-600 p-4 w-full max-w-sm mx-4" @keydown.escape.window="linkPopupOpen = false">
            <h3 class="text-sm font-semibold text-gray-800 dark:text-gray-200 mb-3">{{ __('blog.editor_link') }}</h3>
            <div class="space-y-2">
                {{-- Type tabs --}}
                <div class="flex gap-1 mb-2">
                    <button type="button" @click="linkType = 'url'" :class="linkType === 'url' ? 'bg-indigo-100 dark:bg-indigo-900/40 text-indigo-700 dark:text-indigo-300' : 'text-gray-600 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700'"
                        class="px-3 py-1 text-xs rounded-lg font-medium transition">{{ __('blog.editor_link_web') }}</button>
                    <button type="button" @click="linkType = 'email'" :class="linkType === 'email' ? 'bg-indigo-100 dark:bg-indigo-900/40 text-indigo-700 dark:text-indigo-300' : 'text-gray-600 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700'"
                        class="px-3 py-1 text-xs rounded-lg font-medium transition">{{ __('blog.editor_link_email') }}</button>
                    <button type="button" @click="linkType = 'tel'" :class="linkType === 'tel' ? 'bg-indigo-100 dark:bg-indigo-900/40 text-indigo-700 dark:text-indigo-300' : 'text-gray-600 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700'"
                        class="px-3 py-1 text-xs rounded-lg font-medium transition">{{ __('blog.editor_link_tel') }}</button>
                </div>
                {{-- URL input --}}
                <template x-if="linkType === 'url'">
                    <input type="text" x-model="linkUrl" placeholder="{{ __('blog.editor_link_url_placeholder') }}"
                        class="w-full border border-gray-300 dark:border-gray-600 rounded-lg px-3 py-1.5 text-xs bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-indigo-500 focus:border-transparent outline-none">
                </template>
                <template x-if="linkType === 'email'">
                    <input type="email" x-model="linkUrl" placeholder="{{ __('blog.editor_link_email_placeholder') }}"
                        class="w-full border border-gray-300 dark:border-gray-600 rounded-lg px-3 py-1.5 text-xs bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-indigo-500 focus:border-transparent outline-none">
                </template>
                <template x-if="linkType === 'tel'">
                    <input type="tel" x-model="linkUrl" placeholder="{{ __('blog.editor_link_tel_placeholder') }}"
                        class="w-full border border-gray-300 dark:border-gray-600 rounded-lg px-3 py-1.5 text-xs bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-indigo-500 focus:border-transparent outline-none">
                </template>
            </div>
            <div class="flex justify-between items-center mt-4">
                <button type="button" @click="removeLink(); linkPopupOpen = false" x-show="hasLink"
                    class="text-xs text-red-500 hover:text-red-700 dark:text-red-400 dark:hover:text-red-300 font-medium transition">
                    {{ __('blog.editor_remove_link') }}
                </button>
                <div class="flex gap-2 ml-auto">
                    <button type="button" @click="linkPopupOpen = false"
                        class="px-3 py-1.5 text-xs font-medium text-gray-600 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg transition">
                        {{ __('blog.btn_cancel') }}
                    </button>
                    <button type="button" @click="applyLink(); linkPopupOpen = false"
                        class="px-3 py-1.5 text-xs font-medium text-white bg-indigo-600 hover:bg-indigo-700 rounded-lg transition">
                        {{ __('blog.editor_apply_link') }}
                    </button>
                </div>
            </div>
        </div>
    </div>

    {{-- TipTap Editor --}}
    <div class="relative">
        <div
            x-ref="editorElement"
            x-show="!editorError"
            class="w-full border {{ $invalid ? 'border-red-500 ring-1 ring-red-500 dark:border-red-500' : 'border-gray-300 dark:border-gray-600' }} rounded-lg bg-white dark:bg-gray-800 [&_.ProseMirror]:min-h-[20rem]             [&_.ProseMirror]:max-h-[36rem] [&_.ProseMirror]:overflow-y-auto [&_.ProseMirror]:px-4 [&_.ProseMirror]:py-3 [&_.ProseMirror]:text-gray-900 [&_.ProseMirror]:dark:text-gray-100 [&_.ProseMirror]:text-sm [&_.ProseMirror]:outline-none [&_.ProseMirror_p]:my-1 [&_.ProseMirror_h2]:text-lg [&_.ProseMirror_h2]:font-bold [&_.ProseMirror_h2]:mt-4 [&_.ProseMirror_h3]:text-base [&_.ProseMirror_h3]:font-semibold [&_.ProseMirror_h3]:mt-3 [&_.ProseMirror_ul]:list-disc [&_.ProseMirror_ul]:pl-6 [&_.ProseMirror_ol]:list-decimal [&_.ProseMirror_ol]:pl-6 [&_.ProseMirror_li]:my-0.5 [&_.ProseMirror_pre]:bg-gray-100 [&_.ProseMirror_pre]:dark:bg-gray-900 [&_.ProseMirror_pre]:rounded-lg [&_.ProseMirror_pre]:p-3 [&_.ProseMirror_pre]:font-mono [&_.ProseMirror_pre]:text-xs [&_.ProseMirror_pre]:overflow-x-auto [&_.ProseMirror_code]:bg-gray-100 [&_.ProseMirror_code]:dark:bg-gray-900 [&_.ProseMirror_code]:rounded [&_.ProseMirror_code]:px-1 [&_.ProseMirror_code]:py-0.5 [&_.ProseMirror_code]:text-xs [&_.ProseMirror_pre_code]:bg-transparent [&_.ProseMirror_pre_code]:p-0 [&_.ProseMirror_table]:w-full [&_.ProseMirror_table]:border-collapse [&_.ProseMirror_th]:border [&_.ProseMirror_th]:border-gray-300 [&_.ProseMirror_th]:dark:border-gray-600 [&_.ProseMirror_th]:px-3 [&_.ProseMirror_th]:py-2 [&_.ProseMirror_th]:bg-gray-50 [&_.ProseMirror_th]:dark:bg-gray-700 [&_.ProseMirror_th]:font-semibold [&_.ProseMirror_th]:text-left [&_.ProseMirror_td]:border [&_.ProseMirror_td]:border-gray-300 [&_.ProseMirror_td]:dark:border-gray-600 [&_.ProseMirror_td]:px-3 [&_.ProseMirror_td]:py-2 [&_.ProseMirror_img]:max-w-full [&_.ProseMirror_img]:rounded [&_.ProseMirror_img]:h-auto [&_.ProseMirror_a]:text-indigo-600 [&_.ProseMirror_a]:dark:text-indigo-400 [&_.ProseMirror_a]:underline [&_.ProseMirror_*]:caret-gray-800 [&_.ProseMirror_*]:dark:caret-gray-200
            [&_.ProseMirror_p.is-editor-empty:first-child::before]:text-gray-400 [&_.ProseMirror_p.is-editor-empty:first-child::before]:float-left [&_.ProseMirror_p.is-editor-empty:first-child::before]:pointer-events-none [&_.ProseMirror_p.is-editor-empty:first-child::before]:h-0 [&_.ProseMirror_p.is-editor-empty:first-child::before]:content-[attr(data-placeholder)]"></div>

        {{-- Loading overlay --}}
        <div x-show="generating" x-cloak
             class="absolute inset-0 bg-white/80 dark:bg-gray-900/80 rounded-lg flex items-center justify-center z-10">
            <div class="text-center">
                <svg class="animate-spin h-8 w-8 text-indigo-500 mx-auto mb-2" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                <p class="text-sm font-medium text-indigo-600 dark:text-indigo-400">
                    <span x-show="aiMode === 'generate'" x-text="{{ Illuminate\Support\Js::from(__('blog.editor_ai_generating')) }}"></span>
                    <span x-show="aiMode === 'correct'" x-text="{{ Illuminate\Support\Js::from(__('blog.editor_ai_correcting')) }}"></span>
                </p>
                <p class="text-xs text-gray-500 mt-2" x-show="aiModel">
                    <span x-text="aiModel"></span> {{ __('blog.via') }} <span x-text="aiProvider === 'ollama' ? 'Ollama' : (aiProvider === 'openrouter' ? 'OpenRouter' : 'OpenAI')"></span>
                </p>
                <p class="text-xs text-gray-500" x-show="limits[aiMode] > 0 && remaining[aiMode] > 0">
                    <span x-text="ordinal(aiMode)"></span>
                </p>
            </div>
        </div>
    </div>

    {{-- Fallback textarea when TipTap fails --}}
    <textarea
        x-ref="fallbackTextarea"
        x-show="editorError"
        x-model="content"
        class="w-full border {{ $invalid ? 'border-red-500 ring-1 ring-red-500 dark:border-red-500' : 'border-gray-300 dark:border-gray-600' }} rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 px-4 py-3 text-sm min-h-[20rem] max-h-[36rem] overflow-y-auto focus:ring-2 focus:ring-indigo-500"
        placeholder="{{ __('blog.editor_fallback_placeholder') }}"
        :name="editorError ? name : null"
    ></textarea>

    {{-- Hidden input for form (removed from DOM when fallback is active) --}}
    <template x-if="!editorError">
        <input type="hidden" name="{{ $name }}" :value="content">
    </template>

    {{-- Error message --}}
    <div x-show="editorError" x-cloak class="text-sm text-amber-600 dark:text-amber-400 mt-1">
        {{ __('blog.editor_fallback_error') }}
    </div>

    {{-- Loading --}}
    <div x-show="loading" x-cloak class="text-sm text-indigo-600 dark:text-indigo-400">
        {{ __('blog.processing') }}
    </div>

    {{-- Boutons IA --}}
    @auth
    <div class="mt-3 flex flex-wrap items-center gap-4 border-t border-gray-100 dark:border-gray-700 pt-3">
        <button type="button" @click="aiGenerate('generate')" :disabled="generating || remaining.generate <= 0"
            class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-lg transition"
            :class="remaining.generate > 0 ? 'bg-indigo-50 text-indigo-700 hover:bg-indigo-100 dark:bg-indigo-900/30 dark:text-indigo-400' : 'bg-gray-50 text-gray-400 cursor-not-allowed dark:bg-gray-800 dark:text-gray-500'">
            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
            {{ __('blog.btn_generate_ai_editor') }}
        </button>
        <button type="button" @click="aiGenerate('correct')" :disabled="generating || remaining.correct <= 0 || !contentHasText()"
            class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-lg transition"
            :class="remaining.correct > 0 && contentHasText() ? 'bg-green-50 text-green-700 hover:bg-green-100 dark:bg-green-900/30 dark:text-green-400' : 'bg-gray-50 text-gray-400 cursor-not-allowed dark:bg-gray-800 dark:text-gray-500'">
            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            {{ __('blog.btn_correct_typos') }}
        </button>

        {{-- Compteur d'utilisations --}}
        <template x-if="limits.generate > 0 && usedCount('generate') > 0">
            <span class="text-xs text-gray-400 dark:text-gray-500" x-text="ordinal('generate')"></span>
        </template>
        <template x-if="limits.correct > 0 && usedCount('correct') > 0">
            <span class="text-xs text-gray-400 dark:text-gray-500">
                · <span x-text="ordinal('correct')"></span>
            </span>
        </template>

        {{-- Indicateur provider/modèle --}}
        <template x-if="aiProvider">
            <span class="text-xs text-gray-400 dark:text-gray-500 ml-auto">
                <span x-text="aiModel"></span> {{ __('blog.via') }} <span x-text="aiProvider === 'ollama' ? 'Ollama' : (aiProvider === 'openrouter' ? 'OpenRouter' : 'OpenAI')"></span>
            </span>
        </template>
    </div>
    @endauth

    {{-- Erreur IA --}}
    <div x-show="error" x-cloak class="mt-2 text-sm text-red-500" x-text="error"></div>
</div>
