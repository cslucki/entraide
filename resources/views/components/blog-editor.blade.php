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
</style>

<div
    x-data="blogEditor"
    :class="{ 'bp-fullscreen': fullscreen }"
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
    <div class="flex flex-wrap gap-1 pb-2 border-b border-gray-200 dark:border-gray-700">
        <button type="button" @click="exec('toggleBold')" :class="btnClass('bold')"
            class="rounded-lg px-2.5 py-1 text-xs font-bold transition" title="{{ __('blog.editor_bold') }}">{{ __('blog.editor_bold') }}</button>
        <button type="button" @click="exec('toggleItalic')" :class="btnClass('italic')"
            class="rounded-lg px-2.5 py-1 text-xs italic transition" title="{{ __('blog.editor_italic') }}">{{ __('blog.editor_italic') }}</button>
        <button type="button" @click="exec('toggleUnderline')" :class="btnClass('underline')"
            class="rounded-lg px-2.5 py-1 text-xs underline transition" title="{{ __('blog.editor_underline') }}">{{ __('blog.editor_underline') }}</button>
        <button type="button" @click="exec('toggleH2')" :class="btnClass('heading2')"
            class="rounded-lg px-2.5 py-1 text-xs font-semibold transition" title="{{ __('blog.editor_h2') }}">H2</button>
        <button type="button" @click="exec('toggleH3')" :class="btnClass('heading3')"
            class="rounded-lg px-2.5 py-1 text-xs font-semibold transition" title="{{ __('blog.editor_h3') }}">H3</button>
        <button type="button" @click="exec('toggleBulletList')" :class="btnClass('bulletList')"
            class="rounded-lg px-2.5 py-1 text-xs font-semibold transition" title="{{ __('blog.editor_list') }}">{{ __('blog.editor_list') }}</button>
        <button type="button" @click="openLink()" :class="btnClass('link')"
            class="rounded-lg px-2.5 py-1 text-xs font-semibold transition" title="{{ __('blog.editor_link') }}">{{ __('blog.editor_link') }}</button>
        <button type="button" @click="exec('toggleCodeBlock')" :class="btnClass('codeBlock')"
            class="rounded-lg px-2.5 py-1 text-xs font-mono font-semibold transition" title="{{ __('blog.editor_code') }}">{ }</button>
        <button type="button" @click="exec('insertTable')"
            class="rounded-lg px-2.5 py-1 text-xs font-semibold text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800 transition" title="{{ __('blog.editor_table') }}">{{ __('blog.editor_table') }}</button>
        <button type="button" @click="triggerImageUpload" title="{{ __('blog.editor_image') }}"
            class="rounded-lg px-2.5 py-1 text-xs font-semibold text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800 transition">
            <svg class="w-3.5 h-3.5 inline" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
        </button>
        <button type="button" @click="resizeImage" x-show="activeStates?.image"
            x-cloak class="rounded-lg px-2.5 py-1 text-xs font-semibold text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800 transition"
            :title="activeStates?.imageResized ? @js(__('blog.editor_reset_size')) : @js(__('blog.editor_resize_image'))">
            <span x-text="activeStates?.imageResized ? '100%' : '50%'"></span>
        </button>
        <button type="button" @click="toggleFullscreen" x-cloak
            class="rounded-lg px-2.5 py-1 text-xs font-semibold text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800 transition ml-auto"
            :title="fullscreen ? @js(__('blog.editor_exit_fullscreen')) : @js(__('blog.editor_fullscreen'))">
            <svg x-show="!fullscreen" class="w-3.5 h-3.5 inline" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 3.75v4.5m0-4.5h4.5m-4.5 0L9 9M3.75 20.25v-4.5m0 4.5h4.5m-4.5 0L9 15M20.25 3.75h-4.5m4.5 0v4.5m0-4.5L15 9m5.25 11.25h-4.5m4.5 0v-4.5m0 4.5L15 15"/></svg>
            <svg x-show="fullscreen" class="w-3.5 h-3.5 inline" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 9V4.5M9 9H4.5M9 9 3.75 3.75M9 15v4.5M9 15H4.5M9 15l-5.25 5.25M15 9h4.5M15 9V4.5M15 9l5.25-5.25M15 15h4.5M15 15v4.5m0-4.5 5.25 5.25"/></svg>
        </button>
        <input type="file" accept="image/*" class="hidden" x-ref="imageInput" @change="uploadImage($event)">
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
