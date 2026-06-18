import './bootstrap';
import { createEditor } from './blog-editor';
window.createBlogEditor = createEditor;

function registerAlpineStores() {
    if (!window.Alpine || window.__boucleProAlpineStoresRegistered) {
        return;
    }

    window.__boucleProAlpineStoresRegistered = true;

    window.Alpine.store('modal', {
        active: null,
        _form: null,
        open(id, form) { this.active = id; this._form = form; },
        close() { this.active = null; this._form = null; },
        confirm() { if (this._form) this._form.submit(); this.close(); },
    });

    window.Alpine.store('darkMode', {
        on: document.documentElement.classList.contains('dark'),

        toggle() {
            this.on = !this.on;
            document.documentElement.classList.toggle('dark', this.on);
            localStorage.theme = this.on ? 'dark' : 'light';
        },
    });

    window.Alpine.store('visualTheme', {
        current: document.documentElement.dataset.bpTheme || window.bpDefaultTheme || 'zen',
        themes: window.bpThemes || { zen: { label: 'Zen' }, sable: { label: 'Sable' } },

        next() {
            const themeKeys = Object.keys(this.themes);
            const currentIndex = themeKeys.indexOf(this.current);
            this.current = themeKeys[(currentIndex + 1) % themeKeys.length] || window.bpDefaultTheme || 'zen';
            this.apply();
        },

        set(theme) {
            if (!this.themes[theme]) {
                return;
            }

            this.current = theme;
            this.apply();
        },

        apply() {
            document.documentElement.dataset.bpTheme = this.current;
            localStorage.bpTheme = this.current;
        },

        is(theme) {
            return this.current === theme;
        },

        label() {
            return this.themes[this.current]?.label || 'Zen';
        },
    });
}

function registerBlogEditor() {
    if (!window.Alpine || window.__blogEditorRegistered) {
        return;
    }

    window.__blogEditorRegistered = true;

    Alpine.data('blogEditor', () => ({
        name: '',
        content: '',
        loading: false,
        generating: false,
        error: '',
        editing: false,
        editorError: false,
        remaining: { generate: 3, correct: 3 },
        activeStates: null,
        csrfToken: '',
        uploadRoute: '',
        aiRemainingRoute: '',
        aiGenerateRoute: '',
        aiCorrectRoute: '',

        init() {
            const el = this.$el;
            this.name = el.dataset.editorName || 'content';
            this.content = el.dataset.editorValue || '';
            this.editing = el.dataset.editorPostId !== '';
            this.csrfToken = el.dataset.editorCsrf || '';
            this.uploadRoute = el.dataset.routeUpload || '';
            this.aiRemainingRoute = el.dataset.routeAiRemaining || '';
            this.aiGenerateRoute = el.dataset.routeAiGenerate || '';
            this.aiCorrectRoute = el.dataset.routeAiCorrect || '';

            if (typeof createBlogEditor === 'undefined') {
                this.editorError = true;
                this.$refs.fallbackTextarea.classList.remove('hidden');
                return;
            }

            const editorEl = this.$refs.editorElement;
            if (!editorEl) return;

            editor = createEditor(editorEl, {
                content: this.content,
                placeholder: 'Rédigez votre article…',
                onUpdate: (html) => {
                    this.content = html;
                },
            });

            editor.on('selectionUpdate', () => {
                this.updateActiveStates();
            });

            const form = this.$el.closest('form');
            if (form) {
                form.addEventListener('submit', () => {
                    if (editor && !this.editorError) {
                        const hidden = form.querySelector('input[type="hidden"][name="' + this.name + '"]');
                        if (hidden) hidden.value = editor.getHTML();
                    }
                });
            }

            this.updateActiveStates();

            if (this.editing) {
                this.loadRemaining();
            }
        },

        destroy() {
            if (editor) {
                editor.destroy();
                editor = null;
            }
        },

        updateActiveStates() {
            if (!editor) return;
            this.activeStates = {
                bold: editor.isActive('bold'),
                italic: editor.isActive('italic'),
                underline: editor.isActive('underline'),
                heading2: editor.isActive('heading', { level: 2 }),
                heading3: editor.isActive('heading', { level: 3 }),
                bulletList: editor.isActive('bulletList'),
                link: editor.isActive('link'),
            };
        },

        btnClass(name) {
            if (!this.activeStates) return 'text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800';
            return this.activeStates[name]
                ? 'bg-indigo-100 dark:bg-indigo-900/40 text-indigo-700 dark:text-indigo-300'
                : 'text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800';
        },

        exec(command) {
            if (!editor) return;
            const chain = editor.chain().focus();
            switch (command) {
                case 'toggleBold': chain.toggleBold().run(); break;
                case 'toggleItalic': chain.toggleItalic().run(); break;
                case 'toggleUnderline': chain.toggleUnderline().run(); break;
                case 'toggleH2': chain.toggleHeading({ level: 2 }).run(); break;
                case 'toggleH3': chain.toggleHeading({ level: 3 }).run(); break;
                case 'toggleBulletList': chain.toggleBulletList().run(); break;
                case 'insertTable': chain.insertTable({ rows: 3, cols: 3, withHeaderRow: true }).run(); break;
            }
            this.updateActiveStates();
        },

        openLink() {
            if (!editor) return;
            const prev = editor.getAttributes('link').href;
            const url = window.prompt('URL du lien :', prev || 'https://');
            if (url === null) return;
            if (url === '') {
                editor.chain().focus().unsetLink().run();
            } else {
                editor.chain().focus().setLink({ href: url }).run();
            }
            this.updateActiveStates();
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

            fetch(this.uploadRoute, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': this.csrfToken },
                body: formData
            })
            .then(r => r.json())
            .then(data => {
                if (data.url && editor) {
                    editor.chain().focus().setImage({ src: data.url }).run();
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

        loadRemaining() {
            fetch(this.aiRemainingRoute, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.csrfToken },
                body: JSON.stringify({ post_id: this.$el.dataset.editorPostId })
            })
            .then(r => r.json())
            .then(data => { this.remaining = data; })
            .catch(() => {});
        },

        aiGenerate(mode) {
            if (this.generating || !this.editing) return;

            this.generating = true;
            this.error = '';

            const body = mode === 'generate'
                ? { post_id: this.$el.dataset.editorPostId }
                : { post_id: this.$el.dataset.editorPostId, content: this.content };

            fetch(mode === 'generate' ? this.aiGenerateRoute : this.aiCorrectRoute, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.csrfToken },
                body: JSON.stringify(body)
            })
            .then(r => r.json())
            .then(data => {
                if (data.content && editor) {
                    editor.commands.setContent(data.content);
                    if (data.remaining) this.remaining = data.remaining;
                } else if (data.error) {
                    this.error = data.error;
                }
            })
            .catch(() => { this.error = 'Erreur de communication avec le service IA.'; })
            .finally(() => { this.generating = false; });
        },
    }));
}

let editor = null;

document.addEventListener('alpine:init', () => {
    registerAlpineStores();
    registerBlogEditor();
});

registerAlpineStores();
registerBlogEditor();

// Service Worker registration
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js');
    });
}
