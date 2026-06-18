@props(['name' => 'content', 'value' => '', 'postId' => null])

<div x-data="blogEditor('{{ $name }}', {{ $postId ?: 'null' }})" class="space-y-1">
    {{-- Toolbar --}}
    <div class="flex flex-wrap gap-1 pb-2 border-b border-gray-200 dark:border-gray-700">
        <button type="button" @click="editor?.chain().focus().toggleBold().run()" :class="editor?.isActive('bold') ? 'bg-indigo-100 dark:bg-indigo-900/40 text-indigo-700 dark:text-indigo-300' : 'text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800'"
            class="rounded-lg px-2.5 py-1 text-xs font-bold transition" title="Gras">Gras</button>
        <button type="button" @click="editor?.chain().focus().toggleItalic().run()" :class="editor?.isActive('italic') ? 'bg-indigo-100 dark:bg-indigo-900/40 text-indigo-700 dark:text-indigo-300' : 'text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800'"
            class="rounded-lg px-2.5 py-1 text-xs italic transition" title="Italique">Italique</button>
        <button type="button" @click="editor?.chain().focus().toggleUnderline().run()" :class="editor?.isActive('underline') ? 'bg-indigo-100 dark:bg-indigo-900/40 text-indigo-700 dark:text-indigo-300' : 'text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800'"
            class="rounded-lg px-2.5 py-1 text-xs underline transition" title="Souligné">Souligné</button>
        <button type="button" @click="editor?.chain().focus().toggleHeading({ level: 2 }).run()" :class="editor?.isActive('heading', { level: 2 }) ? 'bg-indigo-100 dark:bg-indigo-900/40 text-indigo-700 dark:text-indigo-300' : 'text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800'"
            class="rounded-lg px-2.5 py-1 text-xs font-semibold transition" title="Titre niveau 2">H2</button>
        <button type="button" @click="editor?.chain().focus().toggleHeading({ level: 3 }).run()" :class="editor?.isActive('heading', { level: 3 }) ? 'bg-indigo-100 dark:bg-indigo-900/40 text-indigo-700 dark:text-indigo-300' : 'text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800'"
            class="rounded-lg px-2.5 py-1 text-xs font-semibold transition" title="Titre niveau 3">H3</button>
        <button type="button" @click="editor?.chain().focus().toggleBulletList().run()" :class="editor?.isActive('bulletList') ? 'bg-indigo-100 dark:bg-indigo-900/40 text-indigo-700 dark:text-indigo-300' : 'text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800'"
            class="rounded-lg px-2.5 py-1 text-xs font-semibold transition" title="Liste">Liste</button>
        <button type="button" @click="insertLink" :class="editor?.isActive('link') ? 'bg-indigo-100 dark:bg-indigo-900/40 text-indigo-700 dark:text-indigo-300' : 'text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800'"
            class="rounded-lg px-2.5 py-1 text-xs font-semibold transition" title="Lien">Lien</button>
        <button type="button" @click="editor?.chain().focus().insertTable({ rows: 3, cols: 3, withHeaderRow: true }).run()" :class="editor?.isActive('table') ? 'bg-indigo-100 dark:bg-indigo-900/40 text-indigo-700 dark:text-indigo-300' : 'text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800'"
            class="rounded-lg px-2.5 py-1 text-xs font-semibold transition" title="Tableau">Tableau</button>
        <button type="button" @click="triggerImageUpload" title="Insérer une image"
            class="rounded-lg px-2.5 py-1 text-xs font-semibold text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800 transition">
            <svg class="w-3.5 h-3.5 inline" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
        </button>
        <input type="file" accept="image/*" class="hidden" x-ref="imageInput" @change="uploadImage($event)">
    </div>

    {{-- TipTap Editor --}}
    <div x-ref="editorElement" class="w-full border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 [&_.ProseMirror]:min-h-[20rem] [&_.ProseMirror]:px-4 [&_.ProseMirror]:py-3 [&_.ProseMirror]:text-gray-900 [&_.ProseMirror]:dark:text-gray-100 [&_.ProseMirror]:text-sm [&_.ProseMirror]:outline-none [&_.ProseMirror_p]:my-1 [&_.ProseMirror_h2]:text-lg [&_.ProseMirror_h2]:font-bold [&_.ProseMirror_h2]:mt-4 [&_.ProseMirror_h3]:text-base [&_.ProseMirror_h3]:font-semibold [&_.ProseMirror_h3]:mt-3 [&_.ProseMirror_ul]:list-disc [&_.ProseMirror_ul]:pl-6 [&_.ProseMirror_ol]:list-decimal [&_.ProseMirror_ol]:pl-6 [&_.ProseMirror_li]:my-0.5 [&_.ProseMirror_table]:w-full [&_.ProseMirror_table]:border-collapse [&_.ProseMirror_th]:border [&_.ProseMirror_th]:border-gray-300 [&_.ProseMirror_th]:dark:border-gray-600 [&_.ProseMirror_th]:px-3 [&_.ProseMirror_th]:py-2 [&_.ProseMirror_th]:bg-gray-50 [&_.ProseMirror_th]:dark:bg-gray-700 [&_.ProseMirror_th]:font-semibold [&_.ProseMirror_th]:text-left [&_.ProseMirror_td]:border [&_.ProseMirror_td]:border-gray-300 [&_.ProseMirror_td]:dark:border-gray-600 [&_.ProseMirror_td]:px-3 [&_.ProseMirror_td]:py-2 [&_.ProseMirror_img]:max-w-full [&_.ProseMirror_img]:rounded [&_.ProseMirror_a]:text-indigo-600 [&_.ProseMirror_a]:dark:text-indigo-400 [&_.ProseMirror_a]:underline [&_.ProseMirror_*]:caret-gray-800 [&_.ProseMirror_*]:dark:caret-gray-200
        [&_.ProseMirror_p.is-editor-empty:first-child::before]:text-gray-400 [&_.ProseMirror_p.is-editor-empty:first-child::before]:float-left [&_.ProseMirror_p.is-editor-empty:first-child::before]:pointer-events-none [&_.ProseMirror_p.is-editor-empty:first-child::before]:h-0 [&_.ProseMirror_p.is-editor-empty:first-child::before]:content-[attr(data-placeholder)]"></div>

    {{-- Hidden input for form submission --}}
    <input type="hidden" :name="name" :value="content">

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
        <span x-show="editing && remaining.generate < 3" class="text-xs text-gray-400 dark:text-gray-500">
            <span x-text="'Il vous reste ' + remaining.generate + ' génération' + (remaining.generate > 1 ? 's' : '') + ' sur cet article'"></span>
        </span>
        <span x-show="generating" class="text-xs text-indigo-500">Appel IA en cours…</span>
    </div>
    @endauth

    {{-- Erreur IA --}}
    <div x-show="error" x-cloak class="mt-2 text-sm text-red-500" x-text="error"></div>
</div>

<script>
document.addEventListener('alpine:init', () => {
    Alpine.data('blogEditor', (name, postId) => ({
        name: name,
        content: @json($value),
        editor: null,
        loading: false,
        generating: false,
        error: '',
        editing: postId !== null,
        remaining: { generate: 3, correct: 3 },
        csrfToken: document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '{{ csrf_token() }}',

        init() {
            if (typeof window.createBlogEditor === 'undefined') {
                this.error = "L'éditeur n'a pas pu être chargé.";
                return;
            }

            this.editor = window.createBlogEditor(this.$refs.editorElement, {
                content: this.content,
                placeholder: 'Rédigez votre article…',
                onUpdate: (html) => { this.content = html; },
            });

            if (postId) {
                this.loadRemaining();
            }
        },

        loadRemaining() {
            fetch('{{ route("blog.ai-remaining") }}', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.csrfToken },
                body: JSON.stringify({ post_id: postId })
            })
            .then(r => r.json())
            .then(data => { this.remaining = data; })
            .catch(() => {});
        },

        insertLink() {
            if (!this.editor) return;
            const prev = this.editor.getAttributes('link').href;
            const url = window.prompt('URL du lien :', prev || 'https://');
            if (url === null) return;
            if (url === '') {
                this.editor.chain().focus().unsetLink().run();
            } else {
                this.editor.chain().focus().setLink({ href: url }).run();
            }
        },

        triggerImageUpload() {
            this.$refs.imageInput.click();
        },

        uploadImage(event) {
            const file = event.target.files[0];
            if (!file) return;

            const formData = new FormData();
            formData.append('image', file);

            this.loading = true;
            this.error = '';

            fetch('{{ route("blog.upload-image") }}', {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': this.csrfToken },
                body: formData
            })
            .then(r => r.json())
            .then(data => {
                if (data.url && this.editor) {
                    this.editor.chain().focus().setImage({ src: data.url }).run();
                } else if (data.error) {
                    this.error = data.error;
                }
            })
            .catch(() => { this.error = 'Erreur lors de l\'upload.'; })
            .finally(() => {
                this.loading = false;
                event.target.value = '';
            });
        },

        aiGenerate(mode) {
            if (this.generating || postId === null) return;

            this.generating = true;
            this.error = '';

            const body = mode === 'generate'
                ? { post_id: postId }
                : { post_id: postId, content: this.content };

            fetch(mode === 'generate' ? '{{ route("blog.ai-generate") }}' : '{{ route("blog.ai-correct") }}', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.csrfToken },
                body: JSON.stringify(body)
            })
            .then(r => r.json())
            .then(data => {
                if (data.content && this.editor) {
                    this.editor.commands.setContent(data.content);
                    if (data.remaining) this.remaining = data.remaining;
                } else if (data.error) {
                    this.error = data.error;
                }
            })
            .catch(() => { this.error = 'Erreur de communication avec le service IA.'; })
            .finally(() => { this.generating = false; });
        }
    }));
});
</script>
