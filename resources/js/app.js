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

function registerBlogSnapshotCard() {
    if (!window.Alpine || window.__blogSnapshotCardRegistered) {
        return;
    }

    window.__blogSnapshotCardRegistered = true;

    Alpine.data('blogSnapshotCard', (config) => ({
        open: false,
        name: '',
        comment: '',
        snapshots: [],
        hasMore: false,
        total: 0,
        page: 0,
        saving: false,
        loading: false,
        error: '',
        success: '',

        storeUrl: config.storeUrl,
        indexUrl: config.indexUrl,
        restoreUrlBase: config.restoreUrlBase,
        i18n: config.i18n || {},

        toggle() {
            this.open = !this.open;
            localStorage.setItem('editor_sidebar_card_snapshot', this.open ? '1' : '0');
        },

        init() {
            const stored = localStorage.getItem('editor_sidebar_card_snapshot');
            if (stored !== null) this.open = stored === '1';
            this.loadHistory();
        },

        latestSnapshot() {
            return this.snapshots[0] || null;
        },

        remainingCount() {
            return Math.max(0, this.total - this.snapshots.length);
        },

        async loadMore() {
            await this.loadHistory(false);
        },

        async createSnapshot() {
            if (!this.name) return;
            this.saving = true;
            this.error = '';
            this.success = '';

            try {
                const title = document.querySelector('input[name="title"]')?.value || '';
                const summary = document.querySelector('textarea[name="summary"]')?.value || '';
                const content = (typeof editor !== 'undefined' && editor) ? editor.getHTML() : '';
                const metaTitle = document.querySelector('input[name="meta_title"]')?.value || '';
                const metaDesc = document.querySelector('textarea[name="meta_description"], input[name="meta_description"]')?.value || '';
                const statusEl = document.querySelector('[name="status"]:checked');
                const status = statusEl?.value || 'draft';

                const resp = await fetch(this.storeUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '' },
                    body: JSON.stringify({
                        name: this.name,
                        comment: this.comment,
                        title,
                        summary,
                        content,
                        meta_title: metaTitle,
                        meta_description: metaDesc,
                        status,
                    }),
                });

                const data = await resp.json();
                if (!resp.ok) throw new Error(data.message || this.i18n.snapshotCreated);

                this.success = data.message || (data.updated ? this.i18n.snapshotNamed : this.i18n.snapshotCreated);
                this.name = '';
                this.comment = '';

                await this.loadHistory();

                setTimeout(() => { this.success = ''; }, 3000);
            } catch (e) {
                this.error = e.message;
            } finally {
                this.saving = false;
            }
        },

        async loadHistory(reset = true) {
            this.loading = true;
            this.error = '';

            try {
                const offset = reset ? 0 : this.snapshots.length;
                const resp = await fetch(this.indexUrl + '?_=' + Date.now() + '&offset=' + offset + '&limit=5', {
                    headers: { 'Accept': 'application/json' },
                });
                if (!resp.ok) throw new Error(this.i18n.snapshotLoadError);
                const data = await resp.json();
                if (reset) {
                    this.snapshots = data.snapshots;
                    this.page = 0;
                } else {
                    this.snapshots = [...this.snapshots, ...data.snapshots];
                    this.page++;
                }
                this.hasMore = data.has_more;
                this.total = data.total;
            } catch (e) {
                this.error = e.message;
            } finally {
                this.loading = false;
            }
        },

        async restoreSnapshot(id) {
            if (!confirm(this.i18n.snapshotConfirmRestore)) return;
            this.loading = true;
            this.error = '';
            this.success = '';

            try {
                const url = this.restoreUrlBase.replace('__PLACEHOLDER__', id);
                const resp = await fetch(url, {
                    method: 'POST',
                    headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '' },
                });

                if (!resp.ok) throw new Error(this.i18n.snapshotRestoreError);
                const data = await resp.json();

                const setVal = (name, val) => {
                    const el = document.querySelector(`[name="${name}"]`);
                    if (el) {
                        if (el.type === 'radio') {
                            const radio = document.querySelector(`[name="${name}"][value="${val}"]`);
                            if (radio) radio.checked = true;
                        } else {
                            el.value = val || '';
                        }
                    }
                };

                setVal('title', data.title);
                setVal('summary', data.summary);
                setVal('meta_title', data.meta_title);
                setVal('meta_description', data.meta_description);
                setVal('status', data.status);

                window.dispatchEvent(new CustomEvent('snapshot-restore', { detail: { content: data.content || '' } }));

                this.success = this.i18n.snapshotRestored;
                setTimeout(() => { this.success = ''; }, 3000);
            } catch (e) {
                this.error = e.message;
            } finally {
                this.loading = false;
            }
        },
    }));
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
        aiMode: 'generate',
        aiProvider: '',
        aiModel: '',
        error: '',
        editing: false,
        editorError: false,
        remaining: { generate: 3, correct: 3 },
        limits: { generate: 3, correct: 3 },
        activeStates: null,
        csrfToken: '',
        uploadRoute: '',
        aiRemainingRoute: '',
        aiGenerateRoute: '',
        aiCorrectRoute: '',
        fullscreen: false,
        editorDark: localStorage.getItem('bp-editor-dark') === 'true',
        linkPopupOpen: false,
        linkUrl: '',
        hasLink: false,
        linkType: 'url',
        errorUpload: '',
        errorAi: '',
        linkPrompt: '',
        msgGenerateRequire: '',
        msgCorrectRequire: '',

        init() {
            const root = this.$root;
            this.name = root.dataset.editorName || 'content';
            this.content = root.dataset.editorValue || '';
            this.editorPostId = root.dataset.editorPostId || '';
            this.editing = this.editorPostId !== '';
            this.csrfToken = root.dataset.editorCsrf || '';
            this.errorUpload = root.dataset.editorErrorUpload || '';
            this.errorAi = root.dataset.editorErrorAi || '';
            this.linkPrompt = root.dataset.editorLinkPrompt || 'Link URL:';
            this.msgGenerateRequire = root.dataset.editorGenerateRequire || '';
            this.msgCorrectRequire = root.dataset.editorCorrectRequire || '';
            this.uploadRoute = root.dataset.routeUpload || '';
            this.aiRemainingRoute = root.dataset.routeAiRemaining || '';
            this.aiGenerateRoute = root.dataset.routeAiGenerate || '';
            this.aiCorrectRoute = root.dataset.routeAiCorrect || '';

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
                    this.syncHidden();
                },
            });

            editor.on('selectionUpdate', () => {
                this.updateActiveStates();
            });

            const form = this.$el.closest('form');
            if (form) {
                form.addEventListener('submit', () => {
                    this.syncHidden();
                });
            }

            this.$watch('editorDark', (val) => {
                localStorage.setItem('bp-editor-dark', val);
            });

            this.updateActiveStates();
            this.loadRemaining();

            this.$el.addEventListener('keydown', (e) => {
                if (e.key === 'Escape' && this.fullscreen) {
                    this.fullscreen = false;
                    document.body.style.overflow = '';
                }
            });

            window.addEventListener('snapshot-restore', (e) => {
                if (editor) {
                    editor.commands.setContent(e.detail.content);
                    this.content = e.detail.content;
                    this.syncHidden();
                } else {
                    const ta = this.$refs?.fallbackTextarea;
                    if (ta) ta.value = e.detail.content;
                }
            });
        },

        destroy() {
            if (editor) {
                editor.destroy();
                editor = null;
            }
        },

        updateActiveStates() {
            if (!editor) return;
            const isImage = editor.isActive('image');
            let imageResized = false;
            if (isImage) {
                try {
                    const { from } = editor.state.selection;
                    const n = editor.state.doc.nodeAt(from);
                    imageResized = n?.attrs?.resized === 'true';
                } catch (e) { /* ignore */ }
            }
            this.activeStates = {
                bold: editor.isActive('bold'),
                italic: editor.isActive('italic'),
                underline: editor.isActive('underline'),
                heading1: editor.isActive('heading', { level: 1 }),
                heading2: editor.isActive('heading', { level: 2 }),
                heading3: editor.isActive('heading', { level: 3 }),
                heading4: editor.isActive('heading', { level: 4 }),
                bulletList: editor.isActive('bulletList'),
                orderedList: editor.isActive('orderedList'),
                link: editor.isActive('link'),
                codeBlock: editor.isActive('codeBlock'),
                image: isImage,
                imageResized,
                highlight: editor.isActive('highlight'),
                textAlign: editor.isActive({ textAlign: 'left' }) ? 'left'
                    : editor.isActive({ textAlign: 'center' }) ? 'center'
                    : editor.isActive({ textAlign: 'right' }) ? 'right'
                    : editor.isActive({ textAlign: 'justify' }) ? 'justify'
                    : '',
                textColor: editor.getAttributes('textStyle')?.color || null,
            };
        },

        btnClass(name) {
            if (!this.activeStates) return 'text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800';
            return this.activeStates[name]
                ? 'bg-indigo-100 dark:bg-indigo-900/40 text-indigo-700 dark:text-indigo-300'
                : 'text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800';
        },

        syncHidden() {
            const form = this.$el.closest('form');
            if (!form || !editor || this.editorError) return;

            const hidden = form.querySelector('input[type="hidden"][name="' + this.name + '"]');
            if (hidden) hidden.value = editor.getHTML();
        },

        exec(command) {
            if (!editor) return;
            const chain = editor.chain().focus();
            switch (command) {
                case 'undo': chain.undo().run(); break;
                case 'redo': chain.redo().run(); break;
                case 'toggleBold': chain.toggleBold().run(); break;
                case 'toggleItalic': chain.toggleItalic().run(); break;
                case 'toggleUnderline': chain.toggleUnderline().run(); break;
                case 'toggleH1': chain.toggleHeading({ level: 1 }).run(); break;
                case 'toggleH2': chain.toggleHeading({ level: 2 }).run(); break;
                case 'toggleH3': chain.toggleHeading({ level: 3 }).run(); break;
                case 'toggleH4': chain.toggleHeading({ level: 4 }).run(); break;
                case 'toggleParagraph': chain.setParagraph().run(); break;
                case 'toggleBulletList': chain.toggleBulletList().run(); break;
                case 'toggleOrderedList': chain.toggleOrderedList().run(); break;
                case 'toggleCodeBlock': chain.toggleCodeBlock().run(); break;
                case 'insertTable': chain.insertTable({ rows: 3, cols: 3, withHeaderRow: true }).run(); break;
            }
            this.updateActiveStates();
        },

        openLink() {
            if (!editor) return;
            this.hasLink = editor.isActive('link');
            this.linkUrl = editor.getAttributes('link').href || '';
            this.linkType = 'url';
            this.linkPopupOpen = true;
        },

        applyLink() {
            if (!editor || !this.linkUrl) return;
            const url = this.linkUrl.trim();
            if (!url) {
                editor.chain().focus().unsetLink().run();
            } else {
                editor.chain().focus().setLink({ href: url }).run();
            }
            this.linkPopupOpen = false;
            this.updateActiveStates();
        },

        removeLink() {
            if (!editor) return;
            editor.chain().focus().unsetLink().run();
            this.linkPopupOpen = false;
            this.linkUrl = '';
            this.hasLink = false;
            this.updateActiveStates();
        },

        triggerImageUpload() {
            this.$refs.imageInput.click();
        },

        toggleFullscreen() {
            this.fullscreen = !this.fullscreen;
            document.body.style.overflow = this.fullscreen ? 'hidden' : '';
        },

        resizeImage() {
            if (!editor || !editor.isActive('image')) return;
            const { state } = editor;
            const { from } = state.selection;
            const node = state.doc.nodeAt(from);
            if (!node || node.type.name !== 'image') return;

            const resized = node.attrs.resized === 'true';

            if (resized) {
                const { tr } = state;
                tr.setNodeMarkup(from, null, {
                    ...node.attrs,
                    resized: null,
                    width: null,
                    height: null,
                });
                editor.view.dispatch(tr);
            } else {
                let targetW = null, targetH = null;
                try {
                    const dom = editor.view.nodeDOM(from);
                    let imgEl = dom?.querySelector?.('img') || dom;
                    if (imgEl && imgEl.tagName !== 'IMG') imgEl = null;
                    if (imgEl) {
                        targetW = imgEl.naturalWidth || null;
                        targetH = imgEl.naturalHeight || null;
                    }
                } catch (e) { /* fallback below */ }

                if (targetW && targetH) {
                    const { tr } = state;
                    tr.setNodeMarkup(from, null, {
                        ...node.attrs,
                        resized: 'true',
                        width: Math.round(targetW * 0.5),
                        height: Math.round(targetH * 0.5),
                    });
                    editor.view.dispatch(tr);
                } else {
                    const { tr } = state;
                    tr.setNodeMarkup(from, null, {
                        ...node.attrs,
                        resized: 'true',
                    });
                    editor.view.dispatch(tr);
                }
            }
            this.updateActiveStates();
            editor.commands.focus();
        },

        toggleHighlight(color) {
            if (!editor) return;
            editor.chain().focus().unsetHighlight().run();
            if (color) {
                editor.chain().focus().toggleHighlight({ color }).run();
            }
            this.updateActiveStates();
        },

        setTextAlign(align) {
            if (!editor) return;
            if (editor.isActive({ textAlign: align })) {
                editor.chain().focus().unsetTextAlign().run();
            } else {
                editor.chain().focus().setTextAlign(align).run();
            }
            this.updateActiveStates();
        },

        setColor(color) {
            if (!editor) return;
            editor.chain().focus().setColor(color).run();
            this.updateActiveStates();
        },

        unsetColor() {
            if (!editor) return;
            editor.chain().focus().unsetColor().run();
            this.updateActiveStates();
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
                    this.syncHidden();
                } else if (data.error) {
                    this.error = data.error;
                }
            })
            .catch(() => { this.error = this.errorUpload; })
            .finally(() => {
                this.loading = false;
                event.target.value = '';
            });
        },

        loadRemaining() {
            const postId = this.editorPostId;
            const body = postId ? { post_id: postId } : {};

            fetch(this.aiRemainingRoute, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.csrfToken },
                body: JSON.stringify(body)
            })
            .then(r => r.json())
            .then(data => {
                this.remaining = { generate: data.generate, correct: data.correct };
                if (data.limits) {
                    this.limits = data.limits;
                }
                if (data.provider) this.aiProvider = data.provider;
                if (data.model) this.aiModel = data.model;
            })
            .catch(() => {});
        },

        aiGenerate(mode) {
            if (this.generating) return;

            const postId = this.editorPostId;
            const form = this.$el.closest('form');
            const title = form?.querySelector('[name="title"]')?.value || '';
            const summary = form?.querySelector('[name="summary"]')?.value || '';

            if (mode === 'generate' && (!title || !summary)) {
                this.error = this.msgGenerateRequire;
                return;
            }

            if (mode === 'correct' && !this.contentHasText()) {
                this.error = this.msgCorrectRequire;
                return;
            }

            this.aiMode = mode;
            this.generating = true;
            this.error = '';
            this.aiProvider = '';
            this.aiModel = '';

            const body = {
                post_id: postId || null,
                ...(mode === 'generate' ? { title, summary } : { content: this.content }),
            };

            fetch(mode === 'generate' ? this.aiGenerateRoute : this.aiCorrectRoute, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.csrfToken },
                body: JSON.stringify(body)
            })
            .then(r => r.json())
            .then(data => {
                if (data.content && editor) {
                    editor.commands.setContent(data.content);
                    this.content = editor.getHTML();
                    this.syncHidden();
                    if (data.remaining) this.remaining = data.remaining;
                    if (data.provider) this.aiProvider = data.provider;
                    if (data.model) this.aiModel = data.model;
                    if (data.limit) {
                        this.limits[mode] = data.limit;
                    }
                    if (data.post_id) {
                        this.editorPostId = data.post_id;
                        this.editing = true;
                    }
                } else if (data.error) {
                    this.error = data.error;
                }
            })
            .catch(() => { this.error = this.errorAi; })
            .finally(() => {
                this.generating = false;
            });
        },

        contentHasText() {
            const text = this.content.replace(/<[^>]*>/g, '').trim();
            return text.length > 0;
        },

        usedCount(mode) {
            return Math.max(0, this.limits[mode] - this.remaining[mode]);
        },

        ordinal(mode) {
            const used = this.usedCount(mode);
            if (used === 0) return '';
            const suffix = used === 1 ? 'ère' : 'ème';
            const label = mode === 'generate' ? 'génération' : 'correction';
            const limit = this.limits[mode];
            return `${used}${suffix} ${label} sur ${limit} possibles`;
        },
    }));
}

let editor = null;

document.addEventListener('alpine:init', () => {
    registerAlpineStores();
    registerBlogSnapshotCard();
    registerBlogEditor();
});

registerAlpineStores();
registerBlogSnapshotCard();
registerBlogEditor();

// Service Worker registration
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js');
    });
}
