import './bootstrap';
import { createEditor } from './blog-editor';
import { extractEmbedUrl } from './tiptap/media-embed-node.js';
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
        selectedSnapshotId: null,
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
            if (this.open) {
                this._dispatching = true;
                window.dispatchEvent(new CustomEvent('close-other-sidebar-cards'));
                this._dispatching = false;
            }
        },

        init() {
            const stored = localStorage.getItem('editor_sidebar_card_snapshot');
            if (stored !== null) this.open = stored === '1';
            this.loadHistory();

            window.addEventListener('close-other-sidebar-cards', () => {
                if (this._dispatching) return;
                this.open = false;
                localStorage.setItem('editor_sidebar_card_snapshot', '0');
            });
        },

        latestSnapshot() {
            return this.snapshots[0] || null;
        },

        selectedSnapshot() {
            return this.snapshots.find((snapshot) => snapshot.id === this.selectedSnapshotId) || this.latestSnapshot();
        },

        selectedIndex() {
            return this.snapshots.findIndex((snapshot) => snapshot.id === this.selectedSnapshot()?.id);
        },

        selectSnapshot(id) {
            this.selectedSnapshotId = id;
        },

        canGoPrevious() {
            const index = this.selectedIndex();

            return index > 0;
        },

        canGoNext() {
            const index = this.selectedIndex();

            return index >= 0 && index < this.snapshots.length - 1;
        },

        selectPrevious() {
            const index = this.selectedIndex();
            if (index > 0) {
                this.selectedSnapshotId = this.snapshots[index - 1].id;
            }
        },

        selectNext() {
            const index = this.selectedIndex();
            if (index >= 0 && index < this.snapshots.length - 1) {
                this.selectedSnapshotId = this.snapshots[index + 1].id;
            }
        },

        comparisonSnapshot() {
            const index = this.selectedIndex();

            return index >= 0 ? this.snapshots[index + 1] || null : null;
        },

        canCompare() {
            return Boolean(this.selectedSnapshot() && this.comparisonSnapshot());
        },

        fieldChanged(field) {
            if (!this.canCompare()) return false;

            return (this.selectedSnapshot()?.[field] || '') !== (this.comparisonSnapshot()?.[field] || '');
        },

        changedFields() {
            return ['title', 'summary', 'status', 'meta_title', 'meta_description']
                .filter((field) => this.fieldChanged(field));
        },

        plainTextFromHtml(html) {
            const doc = new DOMParser().parseFromString(html || '', 'text/html');

            return doc.body.textContent?.replace(/\s+/g, ' ').trim() || '';
        },

        previewText(snapshot) {
            const text = this.plainTextFromHtml(snapshot?.content || '');

            return text.length > 260 ? text.slice(0, 260).trim() + '…' : text;
        },

        diffText(current, previous) {
            return this.tokenizeDiff(
                this.plainTextFromHtml(previous?.content || ''),
                this.plainTextFromHtml(current?.content || ''),
            );
        },

        tokenizeDiff(previousText, currentText) {
            const limit = 90;
            const previous = previousText.split(/\s+/).filter(Boolean).slice(0, limit);
            const current = currentText.split(/\s+/).filter(Boolean).slice(0, limit);
            const table = Array.from({ length: previous.length + 1 }, () => Array(current.length + 1).fill(0));

            for (let i = previous.length - 1; i >= 0; i--) {
                for (let j = current.length - 1; j >= 0; j--) {
                    table[i][j] = previous[i] === current[j]
                        ? table[i + 1][j + 1] + 1
                        : Math.max(table[i + 1][j], table[i][j + 1]);
                }
            }

            const segments = [];
            let i = 0;
            let j = 0;

            const push = (type, word) => {
                const last = segments[segments.length - 1];
                if (last?.type === type) {
                    last.text += ' ' + word;
                } else {
                    segments.push({ type, text: word });
                }
            };

            while (i < previous.length && j < current.length) {
                if (previous[i] === current[j]) {
                    push('unchanged', current[j]);
                    i++;
                    j++;
                } else if (table[i + 1][j] >= table[i][j + 1]) {
                    push('removed', previous[i]);
                    i++;
                } else {
                    push('added', current[j]);
                    j++;
                }
            }

            while (i < previous.length) {
                push('removed', previous[i]);
                i++;
            }

            while (j < current.length) {
                push('added', current[j]);
                j++;
            }

            return segments.slice(0, 28);
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
                    this.selectedSnapshotId = this.snapshots[0]?.id || null;
                } else {
                    this.snapshots = [...this.snapshots, ...data.snapshots];
                    this.page++;
                    if (!this.selectedSnapshotId) {
                        this.selectedSnapshotId = this.snapshots[0]?.id || null;
                    }
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
        mediaDialogOpen: false,
        mediaUrl: '',
        errorUpload: '',
        errorAi: '',
        linkPrompt: '',
        msgGenerateRequire: '',
        msgCorrectRequire: '',
        msgAnnotationTooShort: '',
        annotationStoreUrl: '',
        annotationContentSaveUrl: '',
        msgAnnotationTooLong: '',

        init() {
            const root = this.$root;
            this.name = root.dataset.editorName || 'content';
            this.content = root.dataset.editorValue || '';
            this.editorPostId = root.dataset.editorPostId || '';
            this.editing = this.editorPostId !== '';
            this.csrfToken = root.dataset.editorCsrf || '';
            this.errorUpload = root.dataset.editorErrorUpload || '';
            this.errorAi = root.dataset.editorErrorAi || '';
            this.msgAnnotationTooLong = root.dataset.editorAnnotationTooLong || '';
            this.linkPrompt = root.dataset.editorLinkPrompt || 'Link URL:';
            this.msgGenerateRequire = root.dataset.editorGenerateRequire || '';
            this.msgCorrectRequire = root.dataset.editorCorrectRequire || '';
            this.msgAnnotationTooShort = root.dataset.editorAnnotationTooShort || '';
            this.uploadRoute = root.dataset.routeUpload || '';
            this.aiRemainingRoute = root.dataset.routeAiRemaining || '';
            this.aiGenerateRoute = root.dataset.routeAiGenerate || '';
            this.aiCorrectRoute = root.dataset.routeAiCorrect || '';
            this.annotationStoreUrl = root.dataset.annotationStoreUrl || '';
            this.annotationContentSaveUrl = root.dataset.annotationContentSaveUrl || '';

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

            this.$el.addEventListener('click', (e) => {
                const mark = e.target.closest('.bp-annotation-mark[data-annotation-id]');
                if (mark) {
                    document.dispatchEvent(new CustomEvent('annotation-selected', {
                        detail: { id: mark.dataset.annotationId }
                    }));
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
                annotation: editor.isActive('annotation'),
                table: editor.isActive('table'),
                tableHeader: editor.isActive('tableHeader'),
                tableBorderless: editor.isActive('table') ? (editor.getAttributes('table').borderless || false) : false,
                mediaEmbed: editor.isActive('mediaEmbed'),
            };
        },

        btnClass(name) {
            if (!this.activeStates) return 'text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800';
            if (this.activeStates[name]) {
                return 'bg-indigo-100 dark:bg-indigo-900/40 text-indigo-700 dark:text-indigo-300';
            }
            return 'text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800';
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
                case 'addRowBefore': chain.addRowBefore().run(); break;
                case 'addRowAfter': chain.addRowAfter().run(); break;
                case 'deleteRow': chain.deleteRow().run(); break;
                case 'addColumnBefore': chain.addColumnBefore().run(); break;
                case 'addColumnAfter': chain.addColumnAfter().run(); break;
                case 'deleteColumn': chain.deleteColumn().run(); break;
                case 'toggleHeaderRow': chain.toggleHeaderRow().run(); break;
                case 'toggleHeaderColumn': chain.toggleHeaderColumn().run(); break;
                case 'mergeCells': chain.mergeCells().run(); break;
                case 'splitCell': chain.splitCell().run(); break;
                case 'deleteTable': chain.deleteTable().run(); break;
            }
            this.updateActiveStates();
        },

        toggleTableBorderless() {
            if (!editor || !editor.isActive('table')) return;
            const attrs = editor.getAttributes('table');
            editor.chain().focus().updateAttributes('table', { borderless: !attrs.borderless }).run();
            this.updateActiveStates();
        },

        openLink() {
            if (!editor) return;
            this.hasLink = editor.isActive('link');
            this.linkUrl = editor.getAttributes('link').href || '';
            this.linkType = 'url';
            this.linkPopupOpen = true;
        },

        openMediaDialog() {
            if (!editor) return;
            this.mediaUrl = '';
            this.mediaDialogOpen = true;
        },

        applyMedia() {
            if (!editor || !this.mediaUrl.trim()) return;
            const embedUrl = extractEmbedUrl(this.mediaUrl.trim());
            if (embedUrl) {
                editor.chain().focus().insertMediaEmbed({ src: embedUrl }).run();
            }
            this.mediaDialogOpen = false;
            this.mediaUrl = '';
            this.updateActiveStates();
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

        startEditorAnnotation() {
            if (!editor) return;
            const { from, to } = editor.state.selection;
            if (from === to) return;
            const text = editor.state.doc.textBetween(from, to, ' ').trim();
            if (text.length === 0) return;
            if (text.trim().length < 2) {
                this.error = this.msgAnnotationTooShort;
                return;
            }
            const words = text.split(/\s+/).filter(Boolean).length;
            if (words > 80 || text.length > 600) {
                this.error = this.msgAnnotationTooLong;
                return;
            }
            document.dispatchEvent(new CustomEvent('open-annotation-modal', {
                detail: {
                    from,
                    to,
                    selectedText: text.substring(0, 200),
                    storeUrl: this.annotationStoreUrl || '',
                    contentSaveUrl: this.annotationContentSaveUrl || '',
                    csrfToken: this.csrfToken || '',
                },
            }));
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

function registerAnnotationModal() {
    if (!window.Alpine || window.__annotationModalRegistered) {
        return;
    }

    window.__annotationModalRegistered = true;

    Alpine.data('annotationModal', () => ({
        open: false,
        mode: 'create',
        selectedText: '',
        from: null,
        to: null,
        content: '',
        saving: false,
        error: '',
        storeUrl: '',
        contentSaveUrl: '',
        updateUrl: '',
        annotationId: null,
        csrfToken: '',

        init() {
            document.addEventListener('open-annotation-modal', (e) => {
                this.mode = e.detail.mode || 'create';
                this.selectedText = e.detail.selectedText || '';
                this.from = e.detail.from || null;
                this.to = e.detail.to || null;
                this.storeUrl = e.detail.storeUrl || '';
                this.contentSaveUrl = e.detail.contentSaveUrl || '';
                this.csrfToken = e.detail.csrfToken || '';
                this.content = e.detail.content || '';
                this.updateUrl = e.detail.updateUrl || '';
                this.annotationId = e.detail.annotationId || null;
                this.error = '';
                this.saving = false;
                this.open = true;
            });
        },

        save() {
            if (this.saving || !this.content.trim()) return;
            this.saving = true;
            this.error = '';

            if (this.mode === 'edit') {
                fetch(this.updateUrl, {
                    method: 'PUT',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.csrfToken },
                    body: JSON.stringify({ content: this.content.trim() }),
                })
                    .then(r => r.json().then(d => ({ ok: r.ok, data: d })))
                    .then(({ ok, data }) => {
                        if (!ok) {
                            this.error = data.message || 'Failed to update annotation.';
                            this.saving = false;
                            return;
                        }
                        this.open = false;
                        this.content = '';
                        document.dispatchEvent(new CustomEvent('annotation-updated', {
                            detail: { annotation: data.annotation },
                        }));
                    })
                    .catch(() => {
                        this.error = 'Communication error.';
                        this.saving = false;
                    });
                return;
            }

            fetch(this.storeUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.csrfToken },
                body: JSON.stringify({
                    selected_text: this.selectedText,
                    content: this.content.trim(),
                }),
            })
                .then(r => r.json().then(d => ({ ok: r.ok, data: d })))
                .then(({ ok, data }) => {
                    if (!ok) {
                        this.error = data.message || 'Failed to create annotation.';
                        this.saving = false;
                        return;
                    }

                    const annotation = data.annotation;

                    if (typeof editor !== 'undefined' && editor && this.from !== null && this.to !== null) {
                        editor.chain()
                            .setTextSelection({ from: this.from, to: this.to })
                            .setAnnotation(annotation.id)
                            .run();

                        const html = editor.getHTML();
                        fetch(this.contentSaveUrl, {
                            method: 'PUT',
                            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.csrfToken },
                            body: JSON.stringify({ content: html }),
                        })
                            .then(r => r.json().then(d => ({ ok: r.ok, data: d })))
                            .then(({ ok }) => {
                                if (!ok) {
                                    this._removeMark(annotation.id);
                                    this.error = 'Failed to save content.';
                                    this.saving = false;
                                    return;
                                }

                                this.open = false;
                                this.content = '';
                                document.dispatchEvent(new CustomEvent('annotation-created', {
                                    detail: { annotation },
                                }));
                            })
                            .catch(() => {
                                this._removeMark(annotation.id);
                                this.error = 'Communication error while saving content.';
                                this.saving = false;
                            });
                    } else {
                        this.open = false;
                        this.content = '';
                        document.dispatchEvent(new CustomEvent('annotation-created', {
                            detail: { annotation },
                        }));
                    }
                })
                .catch(() => {
                    this.error = 'Communication error.';
                    this.saving = false;
                });
        },

        _removeMark(id) {
            if (typeof editor === 'undefined' || !editor) return;
            const mark = editor.state.schema.marks.annotation;
            if (!mark) return;
            const { state } = editor;
            const tr = state.tr;
            state.doc.descendants((node, pos) => {
                if (node.marks.length) {
                    const m = node.marks.find(m => m.type === mark && m.attrs.annotationId === id);
                    if (m) {
                        tr.removeMark(pos, pos + node.nodeSize, mark);
                    }
                }
            });
            if (tr.steps.length > 0) {
                editor.view.dispatch(tr);
            }
        },

        cancel() {
            this.open = false;
            this.content = '';
            this.selectedText = '';
            this.from = null;
            this.to = null;
            this.error = '';
        },
    }));
}

function registerBlogCoAuthorCard() {
    if (!window.Alpine || window.__blogCoAuthorCardRegistered) {
        return;
    }

    window.__blogCoAuthorCardRegistered = true;

    Alpine.data('blogCoAuthorCard', (config) => ({
        open: false,
        coAuthors: [],
        searchResults: [],
        loading: false,
        adding: false,
        removing: false,
        searching: false,
        error: '',
        success: '',
        selectedUserId: null,
        userQuery: '',

        indexUrl: config.indexUrl,
        storeUrl: config.storeUrl,
        destroyUrlBase: config.destroyUrlBase,
        searchUrl: config.searchUrl,
        isOwner: config.isOwner,
        isAdmin: config.isAdmin,
        postOwnerId: config.postOwnerId,
        i18n: config.i18n || {},

        toggle() {
            this.open = !this.open;
            localStorage.setItem('editor_sidebar_card_coecriture', this.open ? '1' : '0');
            if (this.open) {
                this._dispatching = true;
                window.dispatchEvent(new CustomEvent('close-other-sidebar-cards'));
                this._dispatching = false;
            }
        },

        init() {
            const stored = localStorage.getItem('editor_sidebar_card_coecriture');
            if (stored !== null) this.open = stored === '1';
            this.loadCoAuthors();

            window.addEventListener('close-other-sidebar-cards', () => {
                if (this._dispatching) return;
                this.open = false;
                localStorage.setItem('editor_sidebar_card_coecriture', '0');
            });
        },

        canManage() {
            return this.isOwner || this.isAdmin;
        },

        loadCoAuthors() {
            this.loading = true;
            this.error = '';
            fetch(this.indexUrl)
                .then(r => r.json())
                .then(data => {
                    this.coAuthors = data.co_authors;
                    this.loading = false;
                })
                .catch(() => {
                    this.error = this.i18n.loadError || 'Failed to load co-authors.';
                    this.loading = false;
                });
        },

        searchUsers() {
            const q = (this.userQuery || '').trim();
            if (!q || q.length < 2) {
                this.searchResults = [];
                return;
            }
            this.searching = true;
            fetch(this.searchUrl + '?q=' + encodeURIComponent(q))
                .then(r => r.json())
                .then(data => {
                    this.searchResults = (data.users || []).filter(u => {
                        if (u.id === this.postOwnerId) return false;
                        return !this.coAuthors.some(c => c.id === u.id);
                    });
                    this.searching = false;
                })
                .catch(() => {
                    this.searchResults = [];
                    this.searching = false;
                });
        },

        selectUser(user) {
            this.selectedUserId = user.id;
            this.userQuery = user.name;
            this.searchResults = [];
        },

        addCoAuthor() {
            if (!this.selectedUserId || this.adding) return;
            this.adding = true;
            this.error = '';
            this.success = '';
            fetch(this.storeUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.i18n.csrfToken || '' },
                body: JSON.stringify({ user_id: this.selectedUserId }),
            })
                .then(r => r.json().then(d => ({ ok: r.ok, data: d })))
                .then(({ ok, data }) => {
                    if (!ok) {
                        this.error = data.message || this.i18n.addError || 'Failed to add co-author.';
                        return;
                    }
                    this.coAuthors.push(data.co_author);
                    this.selectedUserId = null;
                    this.userQuery = '';
                    this.success = data.message || this.i18n.added || 'Co-author added.';
                    setTimeout(() => { this.success = ''; }, 3000);
                })
                .catch(() => {
                    this.error = this.i18n.addError || 'Failed to add co-author.';
                })
                .finally(() => { this.adding = false; });
        },

        removeCoAuthor(userId) {
            if (this.removing) return;
            if (!confirm(this.i18n.confirmRemove || 'Remove this co-author?')) return;
            this.removing = true;
            this.error = '';
            this.success = '';
            const url = this.destroyUrlBase.replace('__USER_ID__', userId);
            fetch(url, {
                method: 'DELETE',
                headers: { 'X-CSRF-TOKEN': this.i18n.csrfToken || '' },
            })
                .then(r => r.json().then(d => ({ ok: r.ok, data: d })))
                .then(({ ok, data }) => {
                    if (!ok) {
                        this.error = data.message || this.i18n.removeError || 'Failed to remove co-author.';
                        return;
                    }
                    this.coAuthors = this.coAuthors.filter(c => c.id !== userId);
                    this.success = data.message || this.i18n.removed || 'Co-author removed.';
                    setTimeout(() => { this.success = ''; }, 3000);
                })
                .catch(() => {
                    this.error = this.i18n.removeError || 'Failed to remove co-author.';
                })
                .finally(() => { this.removing = false; });
        },
    }));
}

function registerBlogInviteByEmail() {
    if (!window.Alpine || window.__blogInviteByEmailRegistered) {
        return;
    }

    window.__blogInviteByEmailRegistered = true;

    Alpine.data('blogInviteByEmail', (config) => ({
        open: false,
        sending: false,
        success: '',
        error: '',
        recipientEmail: '',
        recipientName: '',
        message: '',
        invitations: [],
        loadingHistory: false,
        showHistory: false,

        inviteStoreUrl: config.inviteStoreUrl,
        inviteIndexUrl: config.inviteIndexUrl,
        isOwner: config.isOwner,
        isAdmin: config.isAdmin,
        historyUrl: config.historyUrl,
        i18n: config.i18n || {},
        csrfToken: config.i18n?.csrfToken || '',

        canInvite() {
            return this.isOwner || this.isAdmin;
        },

        openModal() {
            this.open = true;
            this.success = '';
            this.error = '';
        },

        closeModal() {
            this.open = false;
            this.recipientEmail = '';
            this.recipientName = '';
            this.message = '';
            this.error = '';
        },

        sendInvite() {
            if (this.sending) return;
            if (!this.recipientEmail || !this.recipientEmail.includes('@')) {
                this.error = this.i18n.errorInvalidEmail || 'Please enter a valid email address.';
                return;
            }
            this.sending = true;
            this.error = '';

            fetch(this.inviteStoreUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': this.csrfToken,
                    'Accept': 'application/json',
                },
                body: JSON.stringify({
                    recipient_email: this.recipientEmail,
                    recipient_name: this.recipientName,
                    message: this.message,
                }),
            })
                .then(r => r.json().then(d => ({ ok: r.ok, status: r.status, data: d })))
                .then(({ ok, status, data }) => {
                    if (!ok || status >= 400) {
                        if (status === 422 && data.errors) {
                            const errs = Object.values(data.errors).flat();
                            this.error = errs.join(' ');
                        } else {
                            this.error = data.message || this.i18n.errorSendFailed || 'Failed to send invitation.';
                        }
                        return;
                    }
                    this.success = data.message || this.i18n.sent || 'Invitation sent.';
                    this.recipientEmail = '';
                    this.recipientName = '';
                    this.message = '';
                    setTimeout(() => { this.success = ''; this.open = false; }, 2500);
                    this.loadHistory();
                })
                .catch(() => {
                    this.error = this.i18n.errorSendFailed || 'Failed to send invitation.';
                })
                .finally(() => { this.sending = false; });
        },

        loadHistory() {
            this.loadingHistory = true;
            fetch(this.inviteIndexUrl)
                .then(r => r.json())
                .then(data => {
                    this.invitations = data.invitations || [];
                    this.loadingHistory = false;
                })
                .catch(() => {
                    this.invitations = [];
                    this.loadingHistory = false;
                });
        },

        toggleHistory() {
            this.showHistory = !this.showHistory;
            if (this.showHistory && this.invitations.length === 0) {
                this.loadHistory();
            }
        },

        formatDate(iso) {
            if (!iso) return '';
            const d = new Date(iso);
            return d.toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
        },
    }));
}

function registerBlogLoopCard() {
    if (!window.Alpine || window.__blogLoopCardRegistered) {
        return;
    }

    window.__blogLoopCardRegistered = true;

    Alpine.data('blogLoopCard', (config) => ({
        open: false,
        saving: false,
        loading: false,
        error: '',
        success: '',
        selectedLoopId: '',

        storeUrl: config.storeUrl,
        destroyUrlBase: config.destroyUrlBase,
        messagesUrl: config.messagesUrl,
        storeMessageUrlBase: config.storeMessageUrlBase || '',
        userLoops: config.userLoops || [],
        linkedLoops: config.linkedLoops || [],
        i18n: config.i18n || {},
        messageDrafts: {},
        sendingMessage: '',
        _pollInterval: null,
        _fingerprint: '',

        get availableLoops() {
            const linkedIds = new Set(this.linkedLoops.map(l => l.id));
            return this.userLoops.filter(l => !linkedIds.has(l.id));
        },

        toggle() {
            this.open = !this.open;
            localStorage.setItem('editor_sidebar_card_boucle', this.open ? '1' : '0');
            if (this.open) {
                this.loadMessages();
                this._startPolling();
                this._dispatching = true;
                window.dispatchEvent(new CustomEvent('close-other-sidebar-cards'));
                this._dispatching = false;
            } else {
                this._stopPolling();
            }
        },

        init() {
            const stored = localStorage.getItem('editor_sidebar_card_boucle');
            if (stored !== null) this.open = stored === '1';
            this.loadMessages();
            if (this.open) this._startPolling();

            window.addEventListener('close-other-sidebar-cards', () => {
                if (this._dispatching) return;
                this.open = false;
                localStorage.setItem('editor_sidebar_card_boucle', '0');
                this._stopPolling();
            });

            document.addEventListener('visibilitychange', () => {
                if (document.visibilityState === 'visible' && this.open) {
                    this.loadMessages({ silent: true });
                }
            });
        },

        _startPolling() {
            if (this._pollInterval) return;
            this._pollInterval = setInterval(() => {
                if (!this.open) return;
                if (this.sendingMessage) return;
                this.loadMessages({ silent: true });
            }, 8000);
        },

        _stopPolling() {
            if (this._pollInterval) {
                clearInterval(this._pollInterval);
                this._pollInterval = null;
            }
        },

        loadMessages(options) {
            if (this.linkedLoops.length === 0) return;
            const silent = options && options.silent;
            if (!silent) this.loading = true;
            fetch(this.messagesUrl, { cache: 'no-store' })
                .then(r => r.json())
                .then(data => {
                    const raw = JSON.stringify(data.loops || []);
                    if (silent && raw === this._fingerprint) {
                        this.loading = false;
                        return;
                    }
                    this._fingerprint = raw;
                    if (data.loops) {
                        this.linkedLoops = data.loops;
                    }
                    this.loading = false;
                })
                .catch(() => {
                    this.loading = false;
                });
        },

        linkLoop() {
            if (!this.selectedLoopId || this.saving) return;
            this.saving = true;
            this.error = '';
            this.success = '';
            fetch(this.storeUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.i18n.csrfToken || '' },
                body: JSON.stringify({ loop_id: this.selectedLoopId }),
            })
                .then(r => r.json().then(d => ({ ok: r.ok, data: d })))
                .then(({ ok, data }) => {
                    if (!ok) {
                        this.error = data.message || 'Failed to link loop.';
                        return;
                    }
                    this.linkedLoops.push({ ...data.loop, messages: [] });
                    this.selectedLoopId = '';
                    this.success = data.message || this.i18n.linked || 'Loop linked.';
                    setTimeout(() => { this.success = ''; }, 3000);
                    this.loadMessages();
                })
                .catch(() => {
                    this.error = 'Failed to link loop.';
                })
                .finally(() => { this.saving = false; });
        },

        unlinkLoop(loopId) {
            if (this.saving) return;
            this.saving = true;
            this.error = '';
            this.success = '';
            const url = this.destroyUrlBase.replace('__LOOP_ID__', loopId);
            fetch(url, {
                method: 'DELETE',
                headers: { 'X-CSRF-TOKEN': this.i18n.csrfToken || '' },
            })
                .then(r => r.json().then(d => ({ ok: r.ok, data: d })))
                .then(({ ok, data }) => {
                    if (!ok) {
                        this.error = data.message || 'Failed to unlink loop.';
                        return;
                    }
                    this.linkedLoops = this.linkedLoops.filter(l => l.id !== loopId);
                    this.success = data.message || this.i18n.unlinked || 'Loop unlinked.';
                    setTimeout(() => { this.success = ''; }, 3000);
                })
                .catch(() => {
                    this.error = 'Failed to unlink loop.';
                })
                .finally(() => { this.saving = false; });
        },

        sendMessage(loopId) {
            const draft = (this.messageDrafts[loopId] || '').trim();
            if (!draft || this.sendingMessage) return;

            const tempId = '__pending__' + Date.now();
            const optimistic = {
                id: tempId,
                body: draft,
                sender_name: '…',
                created_at_human: "à l'instant",
                _optimistic: true,
            };

            this.messageDrafts[loopId] = '';
            this.linkedLoops = this.linkedLoops.map(l => {
                if (l.id !== loopId) return l;
                return { ...l, messages: [...(l.messages || []), optimistic].slice(-3) };
            });
            this.sendingMessage = loopId;
            this.error = '';

            const url = this.storeMessageUrlBase.replace('__LOOP_ID__', loopId);
            fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.i18n.csrfToken || '' },
                body: JSON.stringify({ body: draft }),
            })
                .then(r => r.json().then(d => ({ ok: r.ok, data: d })))
                .then(({ ok, data }) => {
                    if (!ok) {
                        this.linkedLoops = this.linkedLoops.map(l => {
                            if (l.id !== loopId) return l;
                            return { ...l, messages: (l.messages || []).filter(m => m.id !== tempId) };
                        });
                        this.messageDrafts[loopId] = draft;
                        this.error = data.message || 'Failed to send message.';
                        return;
                    }
                    this.linkedLoops = this.linkedLoops.map(l => {
                        if (l.id !== loopId) return l;
                        return { ...l, messages: [...(l.messages || []).filter(m => m.id !== tempId), data.message].slice(-3) };
                    });
                    this.loadMessages({ silent: true });
                })
                .catch(() => {
                    this.linkedLoops = this.linkedLoops.map(l => {
                        if (l.id !== loopId) return l;
                        return { ...l, messages: (l.messages || []).filter(m => m.id !== tempId) };
                    });
                    this.messageDrafts[loopId] = draft;
                    this.error = 'Failed to send message.';
                })
                .finally(() => { this.sendingMessage = ''; });
        },
    }));
}

function registerBlogTodoCard() {
    if (!window.Alpine || window.__blogTodoCardRegistered) {
        return;
    }

    window.__blogTodoCardRegistered = true;

    Alpine.data('blogTodoCard', (config) => ({
        open: false,
        loading: false,
        creating: false,
        saving: false,
        error: '',
        success: '',
        todos: [],
        newTitle: '',
        editingTodo: null,
        editTitle: '',
        activeTab: 'todo',
        threadDrafts: {},
        threadsOpen: {},
        sendingThread: false,
        assignableUsers: config.assignableUsers || [],
        currentUserId: config.currentUserId || null,
        newAssignee: config.currentUserId || null,
        editingAssignee: null,
        pendingDelete: null,

        indexUrl: config.indexUrl,
        storeUrl: config.storeUrl,
        updateUrlBase: config.updateUrlBase,
        destroyUrlBase: config.destroyUrlBase,
        threadStoreUrlBase: config.threadStoreUrlBase,
        threadDestroyUrlBase: config.threadDestroyUrlBase,
        i18n: config.i18n,

        get filteredTodos() {
            return this.todos.filter(t => t.status === this.activeTab);
        },

        toggle() {
            this.open = !this.open;
            localStorage.setItem('editor_sidebar_card_todo', this.open ? '1' : '0');
            if (this.open) {
                this.loadTodos();
                this._dispatching = true;
                window.dispatchEvent(new CustomEvent('close-other-sidebar-cards'));
                this._dispatching = false;
            }
        },

        init() {
            if (localStorage.getItem('editor_sidebar_card_todo') === '1') {
                this.open = true;
                this.loadTodos();
            }
            window.addEventListener('close-other-sidebar-cards', () => {
                if (this._dispatching) return;
                this.open = false;
                localStorage.setItem('editor_sidebar_card_todo', '0');
            });
            window.addEventListener('snapshot-restore', () => {
                if (this.open) this.loadTodos();
            });
        },

        isThreadsOpen(todo) {
            return this.threadsOpen[todo.id] ?? false;
        },

        toggleThreads(todo) {
            this.threadsOpen[todo.id] = !(this.threadsOpen[todo.id] ?? false);
        },

        loadTodos() {
            this.loading = true;
            this.error = '';
            fetch(this.indexUrl, { cache: 'no-store' })
                .then(r => r.json())
                .then(data => {
                    this.todos = (data.todos || []).map(t => ({ ...t, assigned_to: t.assigned_to || '' }));
                    this.todos.forEach(t => { if (this.threadsOpen[t.id] === undefined) this.threadsOpen[t.id] = false; });
                    this.loading = false;
                })
                .catch(() => {
                    this.error = this.i18n.loadError || 'Failed to load tasks.';
                    this.loading = false;
                });
        },

        createTodo() {
            const title = this.newTitle.trim();
            if (!title || this.creating) return;
            this.creating = true;
            this.error = '';
            this.success = '';
            fetch(this.storeUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.i18n.csrfToken || '' },
                body: JSON.stringify({ title, assigned_to: this.newAssignee }),
            })
                .then(r => r.json().then(d => ({ ok: r.ok, data: d })))
                .then(({ ok, data }) => {
                    if (!ok) {
                        this.error = data.message || this.i18n.createError || 'Failed to create task.';
                        return;
                    }
                    this.todos.push(data.todo);
                    this.threadsOpen[data.todo.id] = false;
                    this.activeTab = 'todo';
                    this.newAssignee = this.currentUserId;
                    this.newTitle = '';
                    this.success = data.message || this.i18n.created || 'Task created.';
                    setTimeout(() => { this.success = ''; }, 3000);
                })
                .catch(() => {
                    this.error = this.i18n.createError || 'Failed to create task.';
                })
                .finally(() => { this.creating = false; });
        },

        startEdit(todo) {
            this.editingTodo = todo.id;
            this.editTitle = todo.title;
        },

        saveEdit(todo) {
            const title = this.editTitle.trim();
            if (!title || this.saving) return;
            this.saving = true;
            this.error = '';
            const url = this.updateUrlBase.replace('__TODO_ID__', todo.id);
            fetch(url, {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.i18n.csrfToken || '' },
                body: JSON.stringify({ title }),
            })
                .then(r => r.json().then(d => ({ ok: r.ok, data: d })))
                .then(({ ok, data }) => {
                    if (!ok) {
                        this.error = data.message || this.i18n.notOwner || this.i18n.updateError || 'Failed to update task.';
                        return;
                    }
                    const idx = this.todos.findIndex(t => t.id === todo.id);
                    if (idx !== -1) this.todos[idx] = data.todo;
                    this.editingTodo = null;
                    this.success = data.message || this.i18n.updated;
                    setTimeout(() => { this.success = ''; }, 3000);
                })
                .catch(() => {
                    this.error = this.i18n.updateError || 'Failed to update task.';
                })
                .finally(() => { this.saving = false; });
        },

        changeStatus(todo) {
            this.error = '';
            const url = this.updateUrlBase.replace('__TODO_ID__', todo.id);
            fetch(url, {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.i18n.csrfToken || '' },
                body: JSON.stringify({ status: todo.status }),
            })
                .then(r => r.json().then(d => ({ ok: r.ok, data: d })))
                .then(({ ok, data }) => {
                    if (!ok) {
                        this.error = data.message || this.i18n.notOwner || this.i18n.updateError || 'Failed to update task.';
                        this.loadTodos();
                        return;
                    }
                    const idx = this.todos.findIndex(t => t.id === todo.id);
                    if (idx !== -1) this.todos[idx] = data.todo;
                })
                .catch(() => {
                    this.error = this.i18n.updateError || 'Failed to update task.';
                    this.loadTodos();
                });
        },

        toggleDone(todo) {
            const newStatus = todo.status === 'done' ? 'todo' : 'done';
            const url = this.updateUrlBase.replace('__TODO_ID__', todo.id);
            this.error = '';
            fetch(url, {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.i18n.csrfToken || '' },
                body: JSON.stringify({ status: newStatus }),
            })
                .then(r => r.json().then(d => ({ ok: r.ok, data: d })))
                .then(({ ok, data }) => {
                    if (!ok) {
                        this.error = data.message || this.i18n.notOwner || this.i18n.updateError || 'Failed to update task.';
                        this.loadTodos();
                        return;
                    }
                    const idx = this.todos.findIndex(t => t.id === todo.id);
                    if (idx !== -1) this.todos[idx] = data.todo;
                })
                .catch(() => {
                    this.error = this.i18n.updateError || 'Failed to update task.';
                    this.loadTodos();
                });
        },

        confirmDeleteTodo(todo) {
            this.pendingDelete = todo.id;
        },

        cancelDeleteTodo() {
            this.pendingDelete = null;
        },

        doDeleteTodo(todo) {
            this.pendingDelete = null;
            this.error = '';
            const url = this.destroyUrlBase.replace('__TODO_ID__', todo.id);
            fetch(url, {
                method: 'DELETE',
                headers: { 'X-CSRF-TOKEN': this.i18n.csrfToken || '' },
            })
                .then(r => r.json().then(d => ({ ok: r.ok, data: d })))
                .then(({ ok, data }) => {
                    if (!ok) {
                        this.error = data.message || this.i18n.notOwner || this.i18n.deleteError || 'Failed to delete task.';
                        return;
                    }
                    this.todos = this.todos.filter(t => t.id !== todo.id);
                    this.success = data.message || this.i18n.deleted;
                    setTimeout(() => { this.success = ''; }, 3000);
                })
                .catch(() => {
                    this.error = this.i18n.deleteError || 'Failed to delete task.';
                });
        },

        startEditAssignee(todo) {
            this.editingAssignee = todo.id;
        },

        saveEditAssignee(todo) {
            this.editingAssignee = null;
            this.error = '';
            const assignedTo = todo.assigned_to || null;
            const url = this.updateUrlBase.replace('__TODO_ID__', todo.id);
            fetch(url, {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.i18n.csrfToken || '' },
                body: JSON.stringify({ assigned_to: assignedTo }),
            })
                .then(r => r.json().then(d => ({ ok: r.ok, data: d })))
                .then(({ ok, data }) => {
                    if (!ok) {
                        this.error = data.message || this.i18n.notOwner || this.i18n.assignError || 'Failed to update assignee.';
                        this.loadTodos();
                        return;
                    }
                    const idx = this.todos.findIndex(t => t.id === todo.id);
                    if (idx !== -1) this.todos[idx] = data.todo;
                })
                .catch(() => {
                    this.error = this.i18n.assignError || 'Failed to update assignee.';
                    this.loadTodos();
                });
        },

        addThread(todo) {
            const body = (this.threadDrafts[todo.id] || '').trim();
            if (!body || this.sendingThread) return;
            this.sendingThread = true;
            const url = this.threadStoreUrlBase.replace('__TODO_ID__', todo.id);
            fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.i18n.csrfToken || '' },
                body: JSON.stringify({ body }),
            })
                .then(r => r.json().then(d => ({ ok: r.ok, data: d })))
                .then(({ ok, data }) => {
                    if (!ok) {
                        this.error = data.message || this.i18n.threadError || 'Failed to add comment.';
                        return;
                    }
                    this.threadDrafts[todo.id] = '';
                    const idx = this.todos.findIndex(t => t.id === todo.id);
                    if (idx !== -1) {
                        if (!this.todos[idx].threads) this.todos[idx].threads = [];
                        this.todos[idx].threads.push(data.thread);
                    }
                    this.threadsOpen[todo.id] = true;
                    this.success = data.message || this.i18n.threadAdded;
                    setTimeout(() => { this.success = ''; }, 3000);
                })
                .catch(() => {
                    this.error = this.i18n.threadError || 'Failed to add comment.';
                })
                .finally(() => { this.sendingThread = false; });
        },

        deleteThread(todo, thread) {
            this.error = '';
            const url = this.threadDestroyUrlBase
                .replace('__TODO_ID__', todo.id)
                .replace('__THREAD_ID__', thread.id);
            fetch(url, {
                method: 'DELETE',
                headers: { 'X-CSRF-TOKEN': this.i18n.csrfToken || '' },
            })
                .then(r => r.json().then(d => ({ ok: r.ok, data: d })))
                .then(({ ok, data }) => {
                    if (!ok) {
                        this.error = data.message || this.i18n.threadDeleteError || 'Failed to delete comment.';
                        return;
                    }
                    const idx = this.todos.findIndex(t => t.id === todo.id);
                    if (idx !== -1 && this.todos[idx].threads) {
                        this.todos[idx].threads = this.todos[idx].threads.filter(t => t.id !== thread.id);
                    }
                })
                .catch(() => {
                    this.error = this.i18n.threadDeleteError || 'Failed to delete comment.';
                });
        },
    }));
}

window.blogAnnotationCard = function (config) {
    return {
        isOpen: false,
        annotations: [],
        loading: false,
        saving: false,
        error: '',
        success: '',
        filterTab: 'open',
        selectedAnnotationId: null,
        deletedFeedbackAnnotationId: null,
        replyContents: {},
        replySaving: false,
        replyEditingId: null,
        replyEditContent: '',
        pendingDeleteAnnotationId: null,
        pendingDeleteReplyId: null,
        pendingDeleteReplyParentId: null,
        _pollInterval: null,
        _fingerprint: '',

        indexUrl: config.indexUrl,
        updateUrlBase: config.updateUrlBase,
        destroyUrlBase: config.destroyUrlBase,
        resolveUrlBase: config.resolveUrlBase,
        replyStoreUrlBase: config.replyStoreUrlBase || '',
        replyUpdateUrlBase: config.replyUpdateUrlBase || '',
        replyDestroyUrlBase: config.replyDestroyUrlBase || '',
        i18n: config.i18n || {},

        init() {
            const stored = localStorage.getItem('editor_sidebar_card_annotations');
            if (stored !== null) this.isOpen = stored === '1';
            this.loadAnnotations();
            if (this.isOpen) this._startPolling();

            document.addEventListener('annotation-selected', (e) => {
                this.selectAnnotation(e.detail.id);
            });

            document.addEventListener('annotation-created', () => {
                this.loadAnnotations();
            });

            document.addEventListener('annotation-updated', () => {
                this.loadAnnotations();
            });

            document.addEventListener('visibilitychange', () => {
                if (document.visibilityState === 'visible' && this.isOpen) {
                    this.loadAnnotations();
                }
            });

            window.addEventListener('close-other-sidebar-cards', () => {
                if (this._dispatching) return;
                this.isOpen = false;
                localStorage.setItem('editor_sidebar_card_annotations', '0');
                this._stopPolling();
            });
        },

        _startPolling() {
            if (this._pollInterval) return;
            this._pollInterval = setInterval(() => {
                if (!this.isOpen) return;
                if (this.replyEditingId) return;
                this.loadAnnotations({ silent: true });
            }, 8000);
        },

        _stopPolling() {
            if (this._pollInterval) {
                clearInterval(this._pollInterval);
                this._pollInterval = null;
            }
        },

        get filteredAnnotations() {
            if (this.filterTab === 'open') {
                return this.annotations.filter(a => a.status === 'open');
            }
            return this.annotations.filter(a => a.status === 'resolved');
        },

        toggle() {
            this.isOpen = !this.isOpen;
            localStorage.setItem('editor_sidebar_card_annotations', this.isOpen ? '1' : '0');
            if (this.isOpen) {
                this.loadAnnotations();
                this._startPolling();
                this._dispatching = true;
                window.dispatchEvent(new CustomEvent('close-other-sidebar-cards'));
                this._dispatching = false;
            } else {
                this._stopPolling();
            }
        },

        loadAnnotations(options) {
            const silent = options && options.silent;
            if (!silent) this.loading = true;
            this.error = '';
            fetch(this.indexUrl, { cache: 'no-store' })
                .then(r => r.json())
                .then(data => {
                    const raw = JSON.stringify(data.annotations || []);
                    if (silent && raw === this._fingerprint) {
                        this.loading = false;
                        return;
                    }
                    this._fingerprint = raw;
                    this.annotations = data.annotations || [];
                    this._computeOrphaned();
                    this.annotations.forEach(a => { this.replyContents[a.id] = this.replyContents[a.id] || ''; });
                    this.loading = false;
                })
                .catch(() => {
                    this.error = this.i18n.loadError || 'Failed to load annotations.';
                    this.loading = false;
                });
        },

        editAnnotation(annotation) {
            document.dispatchEvent(new CustomEvent('open-annotation-modal', {
                detail: {
                    mode: 'edit',
                    annotationId: annotation.id,
                    selectedText: annotation.selected_text,
                    content: annotation.content,
                    updateUrl: this.updateUrlBase.replace('__ANNOTATION_ID__', annotation.id),
                    csrfToken: this.i18n.csrfToken || '',
                },
            }));
        },

        askDeleteAnnotation(id) {
            this.pendingDeleteAnnotationId = id;
        },
        cancelDeleteAnnotation() {
            this.pendingDeleteAnnotationId = null;
        },
        confirmDeleteAnnotation() {
            const id = this.pendingDeleteAnnotationId;
            this.pendingDeleteAnnotationId = null;
            if (!id) return;
            this.saving = true;
            this.error = '';
            this.success = '';
            const url = this.destroyUrlBase.replace('__ANNOTATION_ID__', id);
            fetch(url, {
                method: 'DELETE',
                headers: { 'X-CSRF-TOKEN': this.i18n.csrfToken || '' },
            })
                .then(r => r.json().then(d => ({ ok: r.ok, data: d })))
                .then(({ ok, data }) => {
                    if (!ok) {
                        this.error = data.message || this.i18n.deleteError || 'Failed to delete annotation.';
                        return;
                    }
                    this.annotations = this.annotations.filter(a => a.id !== id);
                    if (this.selectedAnnotationId === id) {
                        this.selectedAnnotationId = null;
                    }
                    if (typeof editor !== 'undefined' && editor) {
                        const { state } = editor;
                        const mark = state.schema.marks.annotation;
                        if (mark) {
                            const { tr } = state;
                            state.doc.descendants((node, pos) => {
                                if (node.marks.length) {
                                    const m = node.marks.find(m => m.type === mark && m.attrs.annotationId === id);
                                    if (m) {
                                        tr.removeMark(pos, pos + node.nodeSize, mark);
                                    }
                                }
                            });
                            if (tr.steps.length > 0) {
                                editor.view.dispatch(tr);
                            }
                        }
                    }
                    this.success = data.message || this.i18n.deleted || 'Annotation deleted.';
                    setTimeout(() => { this.success = ''; }, 3000);
                })
                .catch(() => {
                    this.error = this.i18n.deleteError || 'Failed to delete annotation.';
                })
                .finally(() => { this.saving = false; });
        },

        resolveAnnotation(id) {
            this.saving = true;
            this.error = '';
            this.success = '';
            const url = this.resolveUrlBase.replace('__ANNOTATION_ID__', id);
            fetch(url, {
                method: 'PATCH',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.i18n.csrfToken || '' },
            })
                .then(r => r.json().then(d => ({ ok: r.ok, data: d })))
                .then(({ ok, data }) => {
                    if (!ok) {
                        this.error = data.message || this.i18n.resolveError || 'Failed to resolve annotation.';
                        return;
                    }
                    const idx = this.annotations.findIndex(a => a.id === id);
                    if (idx !== -1) {
                        this.annotations[idx] = data.annotation;
                    }
                    this.success = data.message || this.i18n.resolved || 'Annotation resolved.';
                    setTimeout(() => { this.success = ''; }, 3000);
                })
                .catch(() => {
                    this.error = this.i18n.resolveError || 'Failed to resolve annotation.';
                })
                .finally(() => { this.saving = false; });
        },

        selectAnnotation(id) {
            this.selectedAnnotationId = id;
            this.deletedFeedbackAnnotationId = null;
            const marks = document.querySelectorAll(`[data-annotation-id="${id}"]`);
            document.querySelectorAll('.bp-annotation-highlight').forEach(el => {
                el.classList.remove('bp-annotation-highlight');
            });
            if (marks.length === 0) {
                this.deletedFeedbackAnnotationId = id;
                setTimeout(() => { if (this.deletedFeedbackAnnotationId === id) this.deletedFeedbackAnnotationId = null; }, 3000);
            } else {
                marks.forEach(mark => {
                    mark.classList.add('bp-annotation-highlight');
                    mark.scrollIntoView({ behavior: 'smooth', block: 'center' });
                });
            }
            const card = document.querySelector(`[data-annotation-card-id="${id}"]`);
            if (card) {
                card.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }
        },

        _computeOrphaned() {
            const editorEl = document.querySelector('.ProseMirror');
            if (!editorEl) return;
            const html = editorEl.innerHTML;
            this.annotations.forEach(a => {
                a._orphaned = !html.includes(`data-annotation-id="${a.id}"`);
            });
        },

        getReplyStoreUrl(annotationId) {
            return this.replyStoreUrlBase.replace('__ANNOTATION_ID__', annotationId);
        },

        getReplyUpdateUrl(annotationId, replyId) {
            return this.replyUpdateUrlBase
                .replace('__ANNOTATION_ID__', annotationId)
                .replace('__REPLY_ID__', replyId);
        },

        getReplyDestroyUrl(annotationId, replyId) {
            return this.replyDestroyUrlBase
                .replace('__ANNOTATION_ID__', annotationId)
                .replace('__REPLY_ID__', replyId);
        },

        submitReply(annotationId) {
            const text = (this.replyContents[annotationId] || '').trim();
            if (!text) return;
            this.replySaving = true;
            this.error = '';
            const url = this.getReplyStoreUrl(annotationId);
            fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.i18n.csrfToken || '' },
                body: JSON.stringify({ content: text }),
            })
                .then(r => r.json().then(d => ({ ok: r.ok, data: d })))
                .then(({ ok, data }) => {
                    if (!ok) {
                        this.error = data.message || 'Failed to add reply.';
                        return;
                    }
                    this.replyContents[annotationId] = '';
                    this.loadAnnotations();
                })
                .catch(() => {
                    this.error = 'Failed to add reply.';
                })
                .finally(() => { this.replySaving = false; });
        },

        askDeleteReply(annotationId, replyId) {
            this.pendingDeleteReplyId = replyId;
            this.pendingDeleteReplyParentId = annotationId;
        },
        cancelDeleteReply() {
            this.pendingDeleteReplyId = null;
            this.pendingDeleteReplyParentId = null;
        },
        confirmDeleteReply() {
            const replyId = this.pendingDeleteReplyId;
            const annotationId = this.pendingDeleteReplyParentId;
            this.pendingDeleteReplyId = null;
            this.pendingDeleteReplyParentId = null;
            if (!replyId) return;
            this.replySaving = true;
            this.error = '';
            const url = this.getReplyDestroyUrl(annotationId, replyId);
            fetch(url, {
                method: 'DELETE',
                headers: { 'X-CSRF-TOKEN': this.i18n.csrfToken || '' },
            })
                .then(r => r.json().then(d => ({ ok: r.ok, data: d })))
                .then(({ ok, data }) => {
                    if (!ok) {
                        this.error = data.message || 'Failed to delete reply.';
                        return;
                    }
                    this.loadAnnotations();
                })
                .catch(() => {
                    this.error = 'Failed to delete reply.';
                })
                .finally(() => { this.replySaving = false; });
        },

        editReply(reply) {
            this.replyEditingId = reply.id;
            this.replyEditContent = reply.content;
        },

        cancelReplyEdit() {
            this.replyEditingId = null;
            this.replyEditContent = '';
        },

        updateReply(annotationId) {
            const text = this.replyEditContent.trim();
            if (!text || this.replySaving) return;
            this.replySaving = true;
            this.error = '';
            const url = this.getReplyUpdateUrl(annotationId, this.replyEditingId);
            fetch(url, {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.i18n.csrfToken || '' },
                body: JSON.stringify({ content: text }),
            })
                .then(r => r.json().then(d => ({ ok: r.ok, data: d })))
                .then(({ ok, data }) => {
                    if (!ok) {
                        this.error = data.message || 'Failed to update reply.';
                        return;
                    }
                    this.replyEditingId = null;
                    this.replyEditContent = '';
                    this.loadAnnotations();
                })
                .catch(() => {
                    this.error = 'Failed to update reply.';
                })
                .finally(() => { this.replySaving = false; });
        },

        refreshDocument() {
            location.reload();
        },
    };
};

function registerBlogPlanCard() {
    if (!window.Alpine || window.__blogPlanCardRegistered) {
        return;
    }

    window.__blogPlanCardRegistered = true;

    Alpine.data('blogPlanCard', (config) => ({
        open: false,
        loading: false,
        error: '',
        success: '',
        headings: [],
        showToc: false,
        i18n: config.i18n,
        _debounceTimer: null,
        _editorUpdateHandler: null,

        toggle() {
            this.open = !this.open;
            localStorage.setItem('editor_sidebar_card_plan', this.open ? '1' : '0');
            if (this.open) {
                this.extractHeadings();
                this._startListening();
                this._dispatching = true;
                window.dispatchEvent(new CustomEvent('close-other-sidebar-cards'));
                this._dispatching = false;
            } else {
                this._stopListening();
            }
        },

        init() {
            this.showToc = config.showToc === true;
            if (localStorage.getItem('editor_sidebar_card_plan') === '1') {
                this.open = true;
                this.extractHeadings();
                this.$nextTick(() => this._startListening());
            }
            window.addEventListener('close-other-sidebar-cards', () => {
                if (this._dispatching) return;
                this.open = false;
                this._stopListening();
                localStorage.setItem('editor_sidebar_card_plan', '0');
            });
        },

        _startListening() {
            this._stopListening();
            if (typeof editor === 'undefined' || !editor) return;
            const self = this;
            this._editorUpdateHandler = () => {
                if (self._debounceTimer) clearTimeout(self._debounceTimer);
                self._debounceTimer = setTimeout(() => {
                    self.extractHeadings();
                }, 300);
            };
            editor.on('update', this._editorUpdateHandler);
        },

        _stopListening() {
            if (typeof editor !== 'undefined' && editor && this._editorUpdateHandler) {
                editor.off('update', this._editorUpdateHandler);
            }
        },
        extractHeadings() {
            if (typeof editor === 'undefined' || !editor) {
                this.headings = [];
                return;
            }
            this.loading = true;
            this.error = '';
            this.success = '';

            const flatHeadings = [];
            editor.state.doc.descendants((node, pos) => {
                if (node.type.name === 'heading') {
                    const level = node.attrs.level || 1;
                    const text = node.textContent.trim();
                    if (!text) return;
                    const baseId = 'heading-' + text.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
                    flatHeadings.push({ level, text, id: baseId, pos, collapsed: false, parentCollapsed: false, children: [] });
                }
            });

            const tree = [];
            const stack = [];
            flatHeadings.forEach((h) => {
                while (stack.length > 0 && stack[stack.length - 1].level >= h.level) {
                    stack.pop();
                }
                if (stack.length > 0) {
                    stack[stack.length - 1].children.push(h);
                    h.parentCollapsed = stack[stack.length - 1].collapsed || stack[stack.length - 1].parentCollapsed;
                }
                tree.push(h);
                stack.push(h);
            });

            this.headings = tree;
            this.loading = false;
        },
        toggleCollapse(h) {
            h.collapsed = !h.collapsed;
            this._updateParentCollapsed();
            this.headings = Array.from(this.headings);
        },

        expandAll() {
            const expand = (items) => {
                items.forEach((h) => {
                    h.collapsed = false;
                    h.parentCollapsed = false;
                    if (h.children && h.children.length > 0) expand(h.children);
                });
            };
            expand(this.headings);
            this.headings = Array.from(this.headings);
        },

        collapseAll() {
            const collapse = (items) => {
                items.forEach((h) => {
                    if (h.children && h.children.length > 0) {
                        h.collapsed = true;
                        collapse(h.children);
                    }
                });
            };
            collapse(this.headings);
            this._updateParentCollapsed();
            this.headings = Array.from(this.headings);
        },

        _updateParentCollapsed() {
            const visited = new Set();
            const propagate = (items, parentCollapsed) => {
                items.forEach((h) => {
                    if (visited.has(h)) return;
                    visited.add(h);
                    h.parentCollapsed = parentCollapsed;
                    if (h.children && h.children.length > 0) {
                        propagate(h.children, parentCollapsed || h.collapsed);
                    }
                });
            };
            propagate(this.headings, false);
        },

        scrollToHeading(id) {
            if (typeof editor === 'undefined' || !editor) return;
            const heading = this.headings.find((h) => h.id === id);
            if (!heading) return;
            const dom = editor.view.nodeDOM(heading.pos);
            if (dom) {
                dom.scrollIntoView({ behavior: 'smooth', block: 'center' });
                if (dom.focus) dom.focus({ preventScroll: true });
            } else {
                const coords = editor.view.coordsAtPos(heading.pos);
                if (coords) {
                    window.scrollTo({ top: coords.top - 100, behavior: 'smooth' });
                }
            }
        },

        toggleShowToc() {
            this.error = '';
            this.success = '';
            const formData = new FormData();
            formData.append('_token', config.csrfToken);
            formData.append('_method', 'PATCH');
            formData.append('show_toc', this.showToc ? '1' : '0');

            fetch(config.planUrl, {
                method: 'POST',
                body: formData,
                headers: { Accept: 'application/json' },
            })
                .then((r) => {
                    if (!r.ok) throw new Error('Request failed');
                    return r.json();
                })
                .then((data) => {
                    this.success = data.message || (this.showToc ? 'Plan visible' : 'Plan masqué');
                })
                .catch(() => {
                    this.showToc = !this.showToc;
                    this.error = this.i18n.updateError || 'Update failed.';
                });
        },
    }));
}

function registerBlogExplorerModal() {
    if (!window.Alpine || window.__blogExplorerModalRegistered) {
        return;
    }

    window.__blogExplorerModalRegistered = true;

    Alpine.data('blogExplorerModal', (config) => ({
        open: false,
        phase: 'dialogue',
        dialogueCount: 0,
        maxDialogues: 5,
        noteContent: '',
        noteTooLong: false,
        saving: false,
        generatingNote: false,
        error: '',
        success: '',

        chatUrl: config.chatUrl,
        noteGenerateUrl: config.noteGenerateUrl,
        notesStoreUrl: config.notesStoreUrl,
        csrfToken: config.csrfToken,
        i18n: config.i18n || {},

        init() {
            window.addEventListener('open-explorer', () => {
                this.open = true;
                this.phase = 'dialogue';
                this.dialogueCount = 0;
                this.noteContent = '';
                this.noteTooLong = false;
                this.error = '';
                this.success = '';
                this.$nextTick(() => this.setupDeepChat());
            });
        },

        setupDeepChat() {
            const dc = this.$refs.deepChat;
            if (!dc) return;

            try { dc.clearMessages(); } catch (_) {}

            dc.connect = {
                url: this.chatUrl,
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': this.csrfToken,
                    'X-Requested-With': 'XMLHttpRequest',
                },
            };

            dc.textInput = {
                placeholder: { text: this.i18n.chatPlaceholder || 'Posez votre question sur l\'article…' },
            };

            dc.requestInterceptor = (details) => {
                const body = details.body || {};
                const dcMessages = body.messages || [];
                const lastMsg = dcMessages[dcMessages.length - 1];
                const history = dcMessages.slice(0, -1).map((m) => ({
                    role: m.role === 'ai' ? 'assistant' : m.role,
                    text: m.text || '',
                }));
                return {
                    body: {
                        message: lastMsg?.text || '',
                        messages: history,
                    },
                    headers: details.headers,
                };
            };

            dc.responseInterceptor = (response) => {
                if (response && response.error) {
                    throw new Error(response.error);
                }
                return { text: response?.text || '' };
            };

            dc.introMessage = {
                text: this.i18n.introMessage || 'Bonjour ! Je suis votre Explorer. Posez-moi des questions sur votre article.',
            };

            dc.onMessage = () => {
                this.dialogueCount++;
                if (this.dialogueCount >= this.maxDialogues) {
                    try { dc.disableSubmitButton(); } catch (_) {}
                }
            };
        },

        get canGenerateNote() {
            return this.dialogueCount >= 2;
        },

        get dialogueLabel() {
            return (this.i18n.dialogueCount || ':count échange(s)')
                .replace(':count', this.dialogueCount);
        },

        async generateNote() {
            this.phase = 'generating';
            this.generatingNote = true;
            this.error = '';

            try {
                const dc = this.$refs.deepChat;
                let dcMessages = [];
                if (dc) {
                    try { dcMessages = dc.getMessages(); } catch (_) {}
                }

                const messages = dcMessages
                    .filter((m) => m.role === 'user' || m.role === 'ai')
                    .map((m) => ({
                        role: m.role === 'ai' ? 'assistant' : m.role,
                        text: m.text || '',
                    }));

                const response = await fetch(this.noteGenerateUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': this.csrfToken,
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify({ messages }),
                });

                const data = await response.json();

                if (!response.ok) {
                    if (data.note) {
                        this.noteContent = data.note;
                        this.noteTooLong = true;
                        this.phase = 'note';
                        return;
                    }
                    this.error = data.error || this.i18n.deepChatError || 'Erreur lors de la génération.';
                    this.phase = 'dialogue';
                    return;
                }

                this.noteContent = data.note || '';
                this.noteTooLong = false;
                this.phase = 'note';
            } catch (_) {
                this.error = this.i18n.deepChatError || 'Erreur de connexion.';
                this.phase = 'dialogue';
            } finally {
                this.generatingNote = false;
            }
        },

        async saveNote() {
            if (this.noteContent.length < 150 || this.noteContent.length > 900) {
                this.error = (this.i18n.noteMinMax || 'La note doit faire entre 150 et 900 caractères.')
                    .replace(':min', '150').replace(':max', '900');
                return;
            }

            this.saving = true;
            this.error = '';

            try {
                const response = await fetch(this.notesStoreUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': this.csrfToken,
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify({
                        note_content: this.noteContent,
                        metadata: { source: 'explorer', dialogue_count: this.dialogueCount },
                    }),
                });

                if (!response.ok) {
                    const data = await response.json().catch(() => ({}));
                    this.error = data.message || this.i18n.noteSaveError || 'Erreur de sauvegarde.';
                    return;
                }

                this.success = this.i18n.noteSaved || 'Note sauvegardée !';
                window.dispatchEvent(new CustomEvent('explorer-note-saved'));
                setTimeout(() => this.close(), 1200);
            } catch (_) {
                this.error = this.i18n.noteSaveError || 'Erreur de connexion.';
            } finally {
                this.saving = false;
            }
        },

        close() {
            this.open = false;
            this.phase = 'dialogue';
            this.dialogueCount = 0;
            this.noteContent = '';
            this.noteTooLong = false;
            this.error = '';
            this.success = '';
            if (this.$refs.deepChat) {
                try { this.$refs.deepChat.clearMessages(); } catch (_) {}
            }
        },
    }));
}

function registerBlogExplorerCard() {
    if (!window.Alpine || window.__blogExplorerCardRegistered) {
        return;
    }

    window.__blogExplorerCardRegistered = true;

    Alpine.data('blogExplorerCard', (config) => ({
        open: false,
        notes: [],
        loading: false,
        error: '',
        success: '',
        deletingId: null,

        indexUrl: config.indexUrl,
        destroyUrlBase: config.destroyUrlBase,
        i18n: config.i18n || {},

        toggle() {
            this.open = !this.open;
            localStorage.setItem('editor_sidebar_card_explorer', this.open ? '1' : '0');
            if (this.open) {
                this.loadNotes();
                this._dispatching = true;
                window.dispatchEvent(new CustomEvent('close-other-sidebar-cards'));
                this._dispatching = false;
            }
        },

        init() {
            window.addEventListener('explorer-note-saved', () => {
                if (this.open) this.loadNotes();
            });
            window.addEventListener('close-other-sidebar-cards', () => {
                if (!this._dispatching) this.open = false;
            });
        },

        async loadNotes() {
            this.loading = true;
            this.error = '';
            try {
                const response = await fetch(this.indexUrl, {
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                });
                if (!response.ok) throw new Error('Failed');
                const data = await response.json();
                this.notes = data.notes || data.data || data || [];
            } catch (_) {
                this.error = this.i18n.loadError || 'Erreur de chargement.';
            } finally {
                this.loading = false;
            }
        },

        async deleteNote(noteId) {
            if (this.deletingId) return;
            this.deletingId = noteId;
            this.error = '';
            try {
                const url = this.destroyUrlBase.replace('__NOTE_ID__', noteId);
                const response = await fetch(url, {
                    method: 'DELETE',
                    headers: {
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                });
                if (!response.ok) throw new Error('Failed');
                this.notes = this.notes.filter((n) => n.id !== noteId);
                this.success = this.i18n.noteDeleted || 'Note supprimée.';
                setTimeout(() => { this.success = ''; }, 2000);
            } catch (_) {
                this.error = this.i18n.deleteError || 'Erreur de suppression.';
            } finally {
                this.deletingId = null;
            }
        },

        stripHtml(html) {
            const tmp = document.createElement('div');
            tmp.innerHTML = html || '';
            return tmp.textContent || tmp.innerText || '';
        },

        truncate(text, len) {
            const s = this.stripHtml(text);
            return s.length > len ? s.substring(0, len) + '…' : s;
        },
    }));
}

if (window.Alpine) {
    Alpine.data('blogAnnotationCard', window.blogAnnotationCard);
}

let editor = null;

document.addEventListener('alpine:init', () => {
    registerAlpineStores();
    registerBlogSnapshotCard();
    registerBlogEditor();
    registerAnnotationModal();
    registerBlogCoAuthorCard();
    registerBlogInviteByEmail();
    registerBlogLoopCard();
    registerBlogTodoCard();
    registerBlogPlanCard();
    registerBlogExplorerModal();
    registerBlogExplorerCard();
});

registerAlpineStores();
registerBlogSnapshotCard();
registerBlogEditor();
registerAnnotationModal();
registerBlogCoAuthorCard();
registerBlogInviteByEmail();
registerBlogLoopCard();
registerBlogTodoCard();
registerBlogPlanCard();
registerBlogExplorerModal();
registerBlogExplorerCard();

// Service Worker registration
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js');
    });
}
