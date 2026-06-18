@props(['name' => 'content', 'value' => '', 'format' => 'markdown', 'postId' => null])

<div x-data="blogEditor('{{ $name }}', '{{ $format }}', {{ $postId ?: 'null' }})" class="space-y-1">
    {{-- Onglets --}}
    <div class="flex gap-1 border-b border-gray-200 dark:border-gray-700 pb-1">
        <button type="button" @click="switchMode('markdown')" :class="mode === 'markdown' ? 'border-indigo-500 text-indigo-600' : 'border-transparent text-gray-500 hover:text-gray-700'"
            class="px-3 py-1 text-xs font-medium border-b-2 transition">Markdown</button>
        <button type="button" @click="switchMode('html')" :class="mode === 'html' ? 'border-indigo-500 text-indigo-600' : 'border-transparent text-gray-500 hover:text-gray-700'"
            class="px-3 py-1 text-xs font-medium border-b-2 transition">HTML</button>
        <button type="button" @click="showPreview = !showPreview; if (showPreview) renderPreview()" :class="showPreview ? 'border-indigo-500 text-indigo-600' : 'border-transparent text-gray-500 hover:text-gray-700'"
            class="px-3 py-1 text-xs font-medium border-b-2 transition">Aperçu</button>
    </div>

    {{-- Toolbar --}}
    <div class="flex flex-wrap gap-1">
        <button type="button" @click="insertFormat('bold')" title="Gras"
            class="rounded-lg px-2.5 py-1 text-xs font-bold text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800">Gras</button>
        <button type="button" @click="insertFormat('italic')" title="Italique"
            class="rounded-lg px-2.5 py-1 text-xs italic text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800">Italique</button>
        <button type="button" @click="insertFormat('link')" title="Lien"
            class="rounded-lg px-2.5 py-1 text-xs font-semibold text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800">Lien</button>
        <button type="button" @click="insertFormat('code')" title="Code"
            class="rounded-lg px-2.5 py-1 text-xs font-mono text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800">Code</button>
        <button type="button" @click="insertFormat('h2')" title="Titre niveau 2"
            class="rounded-lg px-2.5 py-1 text-xs font-semibold text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800">H2</button>
        <button type="button" @click="insertFormat('h3')" title="Titre niveau 3"
            class="rounded-lg px-2.5 py-1 text-xs font-semibold text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800">H3</button>
        <button type="button" @click="insertFormat('list')" title="Liste"
            class="rounded-lg px-2.5 py-1 text-xs font-semibold text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800">Liste</button>
        <button type="button" @click="insertFormat('table')" title="Tableau"
            class="rounded-lg px-2.5 py-1 text-xs font-semibold text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800">Tableau</button>
        <button type="button" @click="triggerImageUpload" title="Insérer une image"
            class="rounded-lg px-2.5 py-1 text-xs font-semibold text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800">
            <svg class="w-3.5 h-3.5 inline" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
        </button>
        <input type="file" accept="image/*" class="hidden" x-ref="imageInput" @change="uploadImage($event)">
    </div>

    {{-- Textearea (modes markdown et html) --}}
    <textarea x-show="!showPreview" :id="'blog-editor-' + name" :name="name" rows="14"
        x-model="content"
        class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-indigo-500 text-sm font-mono">{{ $value }}</textarea>

    {{-- Aperçu --}}
    <div x-show="showPreview" x-cloak>
        <div x-html="previewHtml" class="w-full min-h-[24rem] px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 prose prose-sm dark:prose-invert max-w-none overflow-auto">
            <p class="text-gray-400 italic">Cliquez sur Aperçu pour générer le rendu.</p>
        </div>
    </div>

    {{-- Loading --}}
    <div x-show="loading" x-cloak class="text-sm text-indigo-600 dark:text-indigo-400">
        Traitement en cours…
    </div>

    {{-- Hidden input for content_format --}}
    <input type="hidden" name="content_format" x-model="format">

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
    Alpine.data('blogEditor', (name, initialFormat, postId) => ({
        name: name,
        mode: 'markdown',
        format: initialFormat || 'markdown',
        content: '',
        showPreview: false,
        previewHtml: '',
        loading: false,
        generating: false,
        error: '',
        editing: postId !== null,
        remaining: { generate: 3, correct: 3 },
        csrfToken: document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '{{ csrf_token() }}',

        init() {
            const ta = document.getElementById('blog-editor-' + this.name);
            if (ta) {
                this.content = ta.value;
            }
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
            .then(data => {
                this.remaining = data;
            })
            .catch(() => {});
        },

        switchMode(newMode) {
            if (this.mode === newMode) return;
            if (this.showPreview) this.showPreview = false;
            this.format = newMode;
            this.mode = newMode;
        },

        insertFormat(type) {
            const ta = document.getElementById('blog-editor-' + this.name);
            if (!ta) return;

            const isMd = this.mode === 'markdown';
            const start = ta.selectionStart;
            const end = ta.selectionEnd;
            const val = ta.value;
            const sel = val.substring(start, end);

            let before = '', after = '';

            if (type === 'bold') {
                before = isMd ? '**' : '<b>'; after = isMd ? '**' : '</b>';
            } else if (type === 'italic') {
                before = isMd ? '*' : '<i>'; after = isMd ? '*' : '</i>';
            } else if (type === 'link') {
                before = isMd ? '[' : '<a href="'; after = isMd ? '](url)' : '">' + sel + '</a>';
            } else if (type === 'code') {
                before = isMd ? '`' : '<code>'; after = isMd ? '`' : '</code>';
            } else if (type === 'h2') {
                before = isMd ? '## ' : '<h2>'; after = isMd ? '' : '</h2>';
            } else if (type === 'h3') {
                before = isMd ? '### ' : '<h3>'; after = isMd ? '' : '</h3>';
            } else if (type === 'list') {
                before = isMd ? '\n- ' : '\n<ul>\n<li>'; after = isMd ? '' : '</li>\n</ul>';
            } else if (type === 'table') {
                before = isMd ? '\n| Cellule 1 | Cellule 2 |\n|-----------|-----------|' : '\n<table>\n<tr><td>Cellule 1</td><td>Cellule 2</td></tr>\n</table>';
            }

            let newVal;
            if (sel && (type === 'bold' || type === 'italic' || type === 'code' || type === 'link')) {
                if (type === 'link' && isMd) {
                    newVal = val.substring(0, start) + before + sel + after + val.substring(end);
                } else if (type === 'link' && !isMd) {
                    const url = prompt('URL du lien :', 'https://');
                    if (url) {
                        before = '<a href="' + url + '">';
                        newVal = val.substring(0, start) + before + sel + '</a>' + val.substring(end);
                    } else { return; }
                } else {
                    newVal = val.substring(0, start) + before + sel + after + val.substring(end);
                }
            } else {
                newVal = val.substring(0, start) + before + after + val.substring(end);
            }

            if (newVal !== undefined) {
                ta.value = newVal;
                this.content = newVal;
                ta.dispatchEvent(new Event('input', { bubbles: true }));
                ta.focus();
                const pos = start + before.length;
                ta.setSelectionRange(pos, pos);
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
                if (data.url) {
                    const ta = document.getElementById('blog-editor-' + this.name);
                    if (ta) {
                        const ins = this.mode === 'markdown' ? '![](' + data.url + ')' : '<img src="' + data.url + '" alt="">';
                        const start = ta.selectionStart;
                        const val = ta.value;
                        ta.value = val.substring(0, start) + ins + val.substring(ta.selectionEnd);
                        this.content = ta.value;
                        ta.dispatchEvent(new Event('input', { bubbles: true }));
                        ta.focus();
                    }
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

        renderPreview() {
            this.loading = true;
            fetch('{{ route("blog.preview-markdown") }}', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.csrfToken },
                body: JSON.stringify({ content: this.content, format: this.format })
            })
            .then(r => r.json())
            .then(data => {
                this.previewHtml = data.html || '<p class="text-red-500">Erreur de rendu.</p>';
            })
            .catch(() => { this.previewHtml = '<p class="text-red-500">Erreur lors du rendu.</p>'; })
            .finally(() => { this.loading = false; });
        },

        aiGenerate(mode) {
            if (this.generating || postId === null) return;

            this.generating = true;
            this.error = '';

            const ta = document.getElementById('blog-editor-' + this.name);
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
                if (data.content) {
                    this.content = data.content;
                    if (ta) { ta.value = data.content; ta.dispatchEvent(new Event('input', { bubbles: true })); }
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
