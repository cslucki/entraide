@props(['name' => 'content', 'value' => '', 'postId' => null, 'invalid' => false])

<div
    x-data="blogEditor"
    x-id="['blog-editor']"
    data-editor-name="{{ $name }}"
    data-editor-value="{{ $value }}"
    data-editor-post-id="{{ $postId ?: '' }}"
    data-editor-csrf="{{ csrf_token() }}"
    data-editor-invalid="{{ $invalid ? '1' : '0' }}"
    data-route-upload="{{ route('blog.upload-image') }}"
    data-route-ai-remaining="{{ route('blog.ai-remaining') }}"
    data-route-ai-generate="{{ route('blog.ai-generate') }}"
    data-route-ai-correct="{{ route('blog.ai-correct') }}"
    class="space-y-1"
>
    {{-- Toolbar --}}
    <div class="flex flex-wrap gap-1 pb-2 border-b border-gray-200 dark:border-gray-700">
        <button type="button" @click="exec('toggleBold')" :class="btnClass('bold')"
            class="rounded-lg px-2.5 py-1 text-xs font-bold transition" title="Gras">Gras</button>
        <button type="button" @click="exec('toggleItalic')" :class="btnClass('italic')"
            class="rounded-lg px-2.5 py-1 text-xs italic transition" title="Italique">Italique</button>
        <button type="button" @click="exec('toggleUnderline')" :class="btnClass('underline')"
            class="rounded-lg px-2.5 py-1 text-xs underline transition" title="Souligné">Souligné</button>
        <button type="button" @click="exec('toggleH2')" :class="btnClass('heading2')"
            class="rounded-lg px-2.5 py-1 text-xs font-semibold transition" title="Titre niveau 2">H2</button>
        <button type="button" @click="exec('toggleH3')" :class="btnClass('heading3')"
            class="rounded-lg px-2.5 py-1 text-xs font-semibold transition" title="Titre niveau 3">H3</button>
        <button type="button" @click="exec('toggleBulletList')" :class="btnClass('bulletList')"
            class="rounded-lg px-2.5 py-1 text-xs font-semibold transition" title="Liste">Liste</button>
        <button type="button" @click="openLink()" :class="btnClass('link')"
            class="rounded-lg px-2.5 py-1 text-xs font-semibold transition" title="Lien">Lien</button>
        <button type="button" @click="exec('insertTable')"
            class="rounded-lg px-2.5 py-1 text-xs font-semibold text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800 transition" title="Tableau">Tableau</button>
        <button type="button" @click="triggerImageUpload" title="Insérer une image"
            class="rounded-lg px-2.5 py-1 text-xs font-semibold text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800 transition">
            <svg class="w-3.5 h-3.5 inline" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
        </button>
        <input type="file" accept="image/*" class="hidden" x-ref="imageInput" @change="uploadImage($event)">
    </div>

    {{-- TipTap Editor --}}
    <div class="relative">
        <div
            x-ref="editorElement"
            x-show="!editorError"
            class="w-full border {{ $invalid ? 'border-red-500 ring-1 ring-red-500 dark:border-red-500' : 'border-gray-300 dark:border-gray-600' }} rounded-lg bg-white dark:bg-gray-800 [&_.ProseMirror]:min-h-[20rem] [&_.ProseMirror]:max-h-[36rem] [&_.ProseMirror]:overflow-y-auto [&_.ProseMirror]:px-4 [&_.ProseMirror]:py-3 [&_.ProseMirror]:text-gray-900 [&_.ProseMirror]:dark:text-gray-100 [&_.ProseMirror]:text-sm [&_.ProseMirror]:outline-none [&_.ProseMirror_p]:my-1 [&_.ProseMirror_h2]:text-lg [&_.ProseMirror_h2]:font-bold [&_.ProseMirror_h2]:mt-4 [&_.ProseMirror_h3]:text-base [&_.ProseMirror_h3]:font-semibold [&_.ProseMirror_h3]:mt-3 [&_.ProseMirror_ul]:list-disc [&_.ProseMirror_ul]:pl-6 [&_.ProseMirror_ol]:list-decimal [&_.ProseMirror_ol]:pl-6 [&_.ProseMirror_li]:my-0.5 [&_.ProseMirror_table]:w-full [&_.ProseMirror_table]:border-collapse [&_.ProseMirror_th]:border [&_.ProseMirror_th]:border-gray-300 [&_.ProseMirror_th]:dark:border-gray-600 [&_.ProseMirror_th]:px-3 [&_.ProseMirror_th]:py-2 [&_.ProseMirror_th]:bg-gray-50 [&_.ProseMirror_th]:dark:bg-gray-700 [&_.ProseMirror_th]:font-semibold [&_.ProseMirror_th]:text-left [&_.ProseMirror_td]:border [&_.ProseMirror_td]:border-gray-300 [&_.ProseMirror_td]:dark:border-gray-600 [&_.ProseMirror_td]:px-3 [&_.ProseMirror_td]:py-2 [&_.ProseMirror_img]:max-w-full [&_.ProseMirror_img]:rounded [&_.ProseMirror_a]:text-indigo-600 [&_.ProseMirror_a]:dark:text-indigo-400 [&_.ProseMirror_a]:underline [&_.ProseMirror_*]:caret-gray-800 [&_.ProseMirror_*]:dark:caret-gray-200
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
                    {{-- i18n handled client-side --}}
                    <span x-show="aiMode === 'generate'">Génération de l'article en cours…</span>
                    <span x-show="aiMode === 'correct'">Correction en cours…</span>
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
        placeholder="Rédigez votre article…"
        :name="editorError ? name : null"
    ></textarea>

    {{-- Hidden input for form (removed from DOM when fallback is active) --}}
    <template x-if="!editorError">
        <input type="hidden" name="{{ $name }}" :value="content">
    </template>

    {{-- Error message --}}
    <div x-show="editorError" x-cloak class="text-sm text-amber-600 dark:text-amber-400 mt-1">
        L'éditeur visuel n'a pas pu être chargé. Vous pouvez utiliser le champ texte ci-dessus.
    </div>

    {{-- Loading --}}
    <div x-show="loading" x-cloak class="text-sm text-indigo-600 dark:text-indigo-400">
        Traitement en cours…
    </div>

    {{-- Boutons IA --}}
    @auth
    <div class="mt-3 flex flex-wrap items-center gap-4 border-t border-gray-100 dark:border-gray-700 pt-3">
        <button type="button" @click="aiGenerate('generate')" :disabled="generating || remaining.generate <= 0"
            class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-lg transition"
            :class="remaining.generate > 0 ? 'bg-indigo-50 text-indigo-700 hover:bg-indigo-100 dark:bg-indigo-900/30 dark:text-indigo-400' : 'bg-gray-50 text-gray-400 cursor-not-allowed dark:bg-gray-800 dark:text-gray-500'">
            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
            Générer avec l'IA
        </button>
        <button type="button" @click="aiGenerate('correct')" :disabled="generating || remaining.correct <= 0"
            class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-lg transition"
            :class="remaining.correct > 0 ? 'bg-green-50 text-green-700 hover:bg-green-100 dark:bg-green-900/30 dark:text-green-400' : 'bg-gray-50 text-gray-400 cursor-not-allowed dark:bg-gray-800 dark:text-gray-500'">
            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            Corriger les fautes
        </button>

        {{-- Compteur d'utilisations --}}
        <template x-if="editing && limits.generate > 0">
            <span class="text-xs text-gray-400 dark:text-gray-500">
                Génération <span x-text="Math.max(0, limits.generate - remaining.generate) + 1"></span> sur <span x-text="limits.generate"></span>
            </span>
        </template>
        <template x-if="editing && limits.correct > 0">
            <span class="text-xs text-gray-400 dark:text-gray-500">
                · Correction <span x-text="Math.max(0, limits.correct - remaining.correct) + 1"></span> sur <span x-text="limits.correct"></span>
            </span>
        </template>

        {{-- Indicateur provider/modèle --}}
        <template x-if="aiProvider">
            <span class="text-xs text-gray-400 dark:text-gray-500 ml-auto">
                <span x-text="aiModel"></span> via <span x-text="aiProvider === 'ollama' ? 'Ollama' : (aiProvider === 'openrouter' ? 'OpenRouter' : 'OpenAI')"></span>
            </span>
        </template>
    </div>
    @endauth

    {{-- Erreur IA --}}
    <div x-show="error" x-cloak class="mt-2 text-sm text-red-500" x-text="error"></div>
</div>
