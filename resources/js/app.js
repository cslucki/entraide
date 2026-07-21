import './bootstrap';
import { createEditor } from './blog-editor';
import { extractEmbedUrl } from './tiptap/media-embed-node.js';
import * as FilePond from 'filepond';
import 'filepond/dist/filepond.min.css';
import FilePondPluginFileValidateType from 'filepond-plugin-file-validate-type';
import FilePondPluginFileValidateSize from 'filepond-plugin-file-validate-size';
import Sortable from 'sortablejs';
window.FilePond = FilePond;
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
        savedContent: '',
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
        methodSelectionActive: false,
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
            this.savedContent = this.content;
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
                document.dispatchEvent(new CustomEvent('blog-editor-selection-updated'));
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
                        detail: { id: mark.dataset.annotationId, origin: mark.dataset.annotationOrigin || 'human' }
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

            window.addEventListener('method-selection-card-state', (event) => {
                this.methodSelectionActive = event.detail?.active === true;
            });

            window.addEventListener('request-open-explorer-from-method-card', () => {
                this.openExplorer();
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

        normalizeContent(html) {
            return (html || '').replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
        },

        hasUnsavedEditorChanges() {
            return this.normalizeContent(this.content) !== this.normalizeContent(this.savedContent);
        },

        openExplorer() {
            window.dispatchEvent(new CustomEvent('open-explorer', {
                detail: {
                    hasSavedArticle: this.normalizeContent(this.savedContent).length > 0,
                    hasUnsavedChanges: this.hasUnsavedEditorChanges(),
                },
            }));
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
                    if (data.title) {
                        const titleInput = form?.querySelector('[name="title"]');
                        if (titleInput) titleInput.value = data.title;
                    }
                    if (data.summary) {
                        const summaryInput = form?.querySelector('[name="summary"]');
                        if (summaryInput) summaryInput.value = data.summary;
                    }
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

        startEditorMethodSelection() {
            if (this.methodSelectionActive) {
                document.dispatchEvent(new CustomEvent('toggle-method-selection-card'));
                return;
            }
            if (!editor) return;
            const { from, to } = editor.state.selection;
            if (from === to) {
                this.error = this.msgAnnotationTooShort;
                return;
            }
            const text = editor.state.doc.textBetween(from, to, ' ').trim();
            if (text.trim().length < 2) {
                this.error = this.msgAnnotationTooShort;
                return;
            }
            document.dispatchEvent(new CustomEvent('toggle-method-selection-card'));
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

function registerBlogMethodSelectionCard() {
    if (!window.Alpine || window.__blogMethodSelectionCardRegistered) {
        return;
    }

    window.__blogMethodSelectionCardRegistered = true;

    Alpine.data('blogMethodSelectionCard', (config) => ({
        open: false,
        active: false,
        loading: false,
        error: '',
        success: '',
        copied: false,
        selectedText: '',
        from: null,
        to: null,
        method: 'explorer',
        suggestion: '',
        aiInteractionId: null,
        provider: '',
        model: '',
        selectionUrl: config.selectionUrl,
        postId: config.postId,
        csrfToken: config.csrfToken,
        i18n: config.i18n || {},
        methods: config.methods || [],

        init() {
            this.notifyState();

            document.addEventListener('blog-editor-selection-updated', () => {
                if (this.active) this.refreshSelection();
            });
            document.addEventListener('open-method-selection-card', () => this.activate());
            document.addEventListener('toggle-method-selection-card', () => {
                if (this.active) {
                    this.deactivate();
                } else {
                    this.activate();
                }
            });
            document.addEventListener('annotation-created', () => {
                this.suggestion = '';
                this.success = '';
                this.aiInteractionId = null;
            });
            window.addEventListener('close-other-sidebar-cards', () => {
                if (this._dispatching) return;
                this.deactivate();
            });
        },

        toggle() {
            if (this.open) {
                this.deactivate();
                return;
            }

            this.activate(false);
            this._dispatching = true;
            window.dispatchEvent(new CustomEvent('close-other-sidebar-cards'));
            this._dispatching = false;
        },

        activate(closeOtherCards = true) {
            this.active = true;
            this.open = true;
            this.refreshSelection();
            this.notifyState();
            if (closeOtherCards) {
                this._dispatching = true;
                window.dispatchEvent(new CustomEvent('close-other-sidebar-cards'));
                this._dispatching = false;
            }
        },

        deactivate() {
            this.active = false;
            this.open = false;
            this.selectedText = '';
            this.from = null;
            this.to = null;
            this.suggestion = '';
            this.error = '';
            this.success = '';
            this.notifyState();
        },

        notifyState() {
            window.dispatchEvent(new CustomEvent('method-selection-card-state', {
                detail: { active: this.active, open: this.open },
            }));
        },

        openWholeArticleExplorer() {
            this.deactivate();
            window.dispatchEvent(new CustomEvent('request-open-explorer-from-method-card'));
        },

        refreshSelection() {
            if (typeof editor === 'undefined' || !editor) return;
            const { from, to } = editor.state.selection;
            if (from === to) {
                this.selectedText = '';
                this.from = null;
                this.to = null;
                return;
            }
            this.selectedText = editor.state.doc.textBetween(from, to, ' ').trim();
            this.from = from;
            this.to = to;
        },

        selectMethod(method) {
            this.method = method;
            this.error = '';
        },

        canAnalyze() {
            return !this.loading && this.selectedText.trim().length >= 2;
        },

        analyze() {
            this.refreshSelection();
            if (!this.canAnalyze()) {
                this.error = this.i18n.noSelection || 'Select a passage first.';
                return;
            }
            this.loading = true;
            this.error = '';
            this.success = '';
            this.copied = false;

            const context = this.selectionContext();

            fetch(this.selectionUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.csrfToken, Accept: 'application/json' },
                body: JSON.stringify({
                    post_id: this.postId,
                    method: this.method,
                    selected_text: this.selectedText,
                    start_offset: this.from,
                    end_offset: this.to,
                    context_before: context.before,
                    context_after: context.after,
                }),
            })
                .then(r => r.json().then(d => ({ ok: r.ok, data: d })))
                .then(({ ok, data }) => {
                    if (!ok) {
                        this.error = data.message || data.error || this.i18n.error || 'AI analysis failed.';
                        return;
                    }
                    this.suggestion = data.content || '';
                    this.aiInteractionId = data.ai_interaction_id || null;
                    this.provider = data.provider || '';
                    this.model = data.model || '';
                    this.success = this.i18n.ready || '';
                })
                .catch(() => { this.error = this.i18n.error || 'AI analysis failed.'; })
                .finally(() => { this.loading = false; });
        },

        selectionContext() {
            if (typeof editor === 'undefined' || !editor || this.from === null || this.to === null) {
                return { before: '', after: '' };
            }
            const docSize = editor.state.doc.content.size;
            const beforeFrom = Math.max(0, this.from - 500);
            const afterTo = Math.min(docSize, this.to + 500);
            return {
                before: editor.state.doc.textBetween(beforeFrom, this.from, ' ').trim(),
                after: editor.state.doc.textBetween(this.to, afterTo, ' ').trim(),
            };
        },

        createAnnotation() {
            if (!this.suggestion.trim()) return;
            document.dispatchEvent(new CustomEvent('open-annotation-modal', {
                detail: {
                    selectedText: this.selectedText.substring(0, 5000),
                    from: this.from,
                    to: this.to,
                    content: this.suggestion,
                    storeUrl: config.annotationStoreUrl || '',
                    contentSaveUrl: config.annotationContentSaveUrl || '',
                    csrfToken: this.csrfToken || '',
                    origin: 'ai_method',
                    methodKey: this.method,
                    aiInteractionId: this.aiInteractionId,
                },
            }));
        },

        copySuggestion() {
            if (!this.suggestion.trim()) return;
            navigator.clipboard?.writeText(this.suggestion).then(() => {
                this.copied = true;
                setTimeout(() => { this.copied = false; }, 1800);
            });
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
        origin: 'human',
        methodKey: null,
        aiInteractionId: null,

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
                this.origin = e.detail.origin || 'human';
                this.methodKey = e.detail.methodKey || null;
                this.aiInteractionId = e.detail.aiInteractionId || null;
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
                    start_offset: this.from,
                    end_offset: this.to,
                    origin: this.origin,
                    method_key: this.methodKey,
                    ai_interaction_id: this.aiInteractionId,
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
                            .setAnnotation(annotation.id, annotation.origin || this.origin)
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
            this.origin = 'human';
            this.methodKey = null;
            this.aiInteractionId = null;
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

function registerBlogDossierCard() {
    if (!window.Alpine || window.__blogDossierCardRegistered) {
        return;
    }

    window.__blogDossierCardRegistered = true;

    Alpine.data('blogDossierCard', (config) => ({
        open: false,
        loading: false,
        saving: false,
        creating: false,
        error: '',
        success: '',
        currentDossier: null,
        dossiers: [],
        selectedDossierId: '',
        showQuickCreate: false,
        newDossierName: '',

        currentDossierUrl: config.currentDossierUrl,
        dossiersUrl: config.dossiersUrl,
        attachUrl: config.attachUrl,
        detachUrl: config.detachUrl,
        quickCreateUrl: config.quickCreateUrl,
        i18n: config.i18n || {},

        toggle() {
            this.open = !this.open;
            localStorage.setItem('editor_sidebar_card_dossier', this.open ? '1' : '0');
            if (this.open) {
                this.loadCurrent();
                this.loadDossiers();
                this._dispatching = true;
                window.dispatchEvent(new CustomEvent('close-other-sidebar-cards'));
                this._dispatching = false;
            }
        },

        init() {
            const stored = localStorage.getItem('editor_sidebar_card_dossier');
            if (stored !== null) this.open = stored === '1';
            if (this.open) {
                this.loadCurrent();
                this.loadDossiers();
            }

            window.addEventListener('close-other-sidebar-cards', () => {
                if (this._dispatching) return;
                this.open = false;
                localStorage.setItem('editor_sidebar_card_dossier', '0');
            });
        },

        loadCurrent() {
            this.loading = true;
            this.error = '';
            fetch(this.currentDossierUrl, { cache: 'no-store' })
                .then(r => r.json())
                .then(data => {
                    this.currentDossier = data.dossier || null;
                    this.loading = false;
                })
                .catch(() => {
                    this.error = this.i18n.loadError || 'Erreur de chargement.';
                    this.loading = false;
                });
        },

        loadDossiers() {
            fetch(this.dossiersUrl, { cache: 'no-store' })
                .then(r => r.json())
                .then(data => {
                    this.dossiers = data.dossiers || [];
                })
                .catch(() => {});
        },

        classify() {
            if (!this.selectedDossierId || this.saving) return;
            this.saving = true;
            this.error = '';
            this.success = '';
            fetch(this.attachUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.i18n.csrfToken || '' },
                body: JSON.stringify({ dossier_id: this.selectedDossierId }),
            })
                .then(r => r.json().then(d => ({ ok: r.ok, data: d })))
                .then(({ ok, data }) => {
                    if (!ok) {
                        this.error = data.message || this.i18n.classifyError;
                        return;
                    }
                    this.currentDossier = data.dossier || null;
                    this.selectedDossierId = '';
                    this.success = data.message || this.i18n.classified;
                    setTimeout(() => { this.success = ''; }, 3000);
                })
                .catch(() => {
                    this.error = this.i18n.classifyError;
                })
                .finally(() => { this.saving = false; });
        },

        detach() {
            if (this.saving) return;
            this.saving = true;
            this.error = '';
            this.success = '';
            fetch(this.detachUrl, {
                method: 'DELETE',
                headers: { 'X-CSRF-TOKEN': this.i18n.csrfToken || '' },
            })
                .then(r => r.json().then(d => ({ ok: r.ok, data: d })))
                .then(({ ok, data }) => {
                    if (!ok) {
                        this.error = data.message || this.i18n.detachError;
                        return;
                    }
                    this.currentDossier = null;
                    this.success = data.message || this.i18n.detached;
                    setTimeout(() => { this.success = ''; }, 3000);
                })
                .catch(() => {
                    this.error = this.i18n.detachError;
                })
                .finally(() => { this.saving = false; });
        },

        quickCreate() {
            const name = this.newDossierName.trim();
            if (!name || this.creating) return;
            this.creating = true;
            this.error = '';
            this.success = '';
            fetch(this.quickCreateUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.i18n.csrfToken || '' },
                body: JSON.stringify({ name }),
            })
                .then(r => r.json().then(d => ({ ok: r.ok, data: d })))
                .then(({ ok, data }) => {
                    if (!ok) {
                        this.error = data.message || this.i18n.createError;
                        return;
                    }
                    this.dossiers.push(data.dossier);
                    this.selectedDossierId = data.dossier.id;
                    this.newDossierName = '';
                    this.showQuickCreate = false;
                    this.success = data.message || this.i18n.created;
                    setTimeout(() => { this.success = ''; }, 3000);
                })
                .catch(() => {
                    this.error = this.i18n.createError;
                })
                .finally(() => { this.creating = false; });
        },
    }));
}

function normalizeArticle(blogPost) {
    if (!blogPost) return null;
    return {
        id: blogPost.id,
        blogPostId: blogPost.blog_post_id || blogPost.id,
        title: blogPost.title || null,
        slug: blogPost.slug || null,
        status: blogPost.status || 'draft',
        updatedAt: blogPost.updated_at || blogPost.updatedAt || null,
        publishedAt: blogPost.published_at || blogPost.publishedAt || null,
        author: blogPost.author || null,
        coAuthors: blogPost.coAuthors || [],
        canView: blogPost.canView || false,
        canEdit: blogPost.canEdit || false,
        viewUrl: blogPost.viewUrl || null,
        editUrl: blogPost.editUrl || null,
    };
}

function registerDossierTabs() {
    if (typeof Alpine === 'undefined') return;

    Alpine.data('dossierTabs', (defaultTab) => ({
        active: defaultTab || 'contenus',

        init() {
            const hash = window.location.hash.replace('#', '');
            if (['contenus', 'fichiers', 'membres'].includes(hash)) {
                this.active = hash;
            }
        },

        activate(tab) {
            this.active = tab;
            window.location.hash = tab;
        },

        onHashChange() {
            const hash = window.location.hash.replace('#', '');
            if (['contenus', 'fichiers', 'membres'].includes(hash)) {
                this.active = hash;
            }
        },
    }));
}

function registerDossierContentsCard() {
    if (typeof Alpine === 'undefined') return;

    Alpine.data('dossierContentsCard', (config) => ({
        hasSeries: !!config.series,
        seriesId: config.series?.id || null,
        seriesRoot: config.series?.root ? normalizeArticle(config.series.root) : null,
        seriesRootBlogPostId: config.series?.root_blog_post_id || null,
        seriesItems: (config.series?.items || []).map(item => ({
            id: item.id,
            blog_post_id: item.blog_post_id,
            position: item.position,
            blog_post: normalizeArticle(item.blog_post),
        })),
        ungrouped: config.ungrouped.map(e => ({
            ...e,
            blog_post: normalizeArticle(e.blog_post),
        })),
        seriesEligibleArticles: config.seriesEligibleArticles || [],
        searchQuery: '',
        message: '',
        messageType: 'success',
        showAddModal: false,
        addSearchQuery: '',
        addSearchResults: [],
        addSearching: false,
        adding: false,
        showDeleteSeriesModal: false,
        showDetachModal: false,
        detachEntry: null,
        detaching: false,
        openMenuId: null,
        showSeriesMenu: false,
        saving: false,
        sortables: [],
        i18n: config.i18n || {},
        canManageArticles: config.canManageArticles || false,
        csrfToken: config.csrfToken,
        dossierId: config.dossierId,
        orgParam: config.orgParam,

        init() {
            document.addEventListener('click', (ev) => {
                if (this.openMenuId && !ev.target.closest('[data-article-menu]') && !ev.target.closest('button')) {
                    this.openMenuId = null;
                }
                if (this.showSeriesMenu && !ev.target.closest('[data-article-menu]') && !ev.target.closest('button')) {
                    this.showSeriesMenu = false;
                }
            });
            document.addEventListener('keydown', (ev) => {
                if (ev.key === 'Escape') {
                    if (this.showDetachModal) { this.showDetachModal = false; this.detachEntry = null; }
                    else if (this.showAddModal) { this.closeAddModal(); }
                    else if (this.showDeleteSeriesModal) { this.showDeleteSeriesModal = false; }
                    else { this.openMenuId = null; this.showSeriesMenu = false; }
                }
            });

            const groupOptions = {
                name: 'dossier-articles',
                put: true,
                pull: true,
            };

            this.$nextTick(() => {
                if (!this.canManageArticles) return;

                const commonSortable = {
                    group: groupOptions,
                    handle: '.drag-handle',
                    filter: '[data-no-drag]',
                    animation: 150,
                    onEnd: (evt) => this.onDragEnd(evt),
                };

                if (this.$refs.ungroupedContainer) {
                    this.sortables.push(Sortable.create(this.$refs.ungroupedContainer, commonSortable));
                }
                if (this.$refs.annexesContainer) {
                    this.sortables.push(Sortable.create(this.$refs.annexesContainer, commonSortable));
                }
            });
        },

        destroy() {
            this.sortables.forEach(s => s.destroy());
            this.sortables = [];
        },

        initSortables() {
            this.sortables.forEach(s => s.destroy());
            this.sortables = [];
            if (!this.canManageArticles) return;
            const groupOptions = { name: 'dossier-articles', put: true, pull: true };
            const commonSortable = {
                group: groupOptions,
                handle: '.drag-handle',
                filter: '[data-no-drag]',
                animation: 150,
                onEnd: (evt) => this.onDragEnd(evt),
            };
            if (this.$refs.ungroupedContainer) {
                this.sortables.push(Sortable.create(this.$refs.ungroupedContainer, commonSortable));
            }
            if (this.$refs.annexesContainer) {
                this.sortables.push(Sortable.create(this.$refs.annexesContainer, commonSortable));
            }
        },

        onDragEnd(evt) {
            const movedId = evt.item.getAttribute('data-article-id');
            if (!movedId) return;

            const fromUngrouped = evt.from === this.$refs.ungroupedContainer;
            const toUngrouped = evt.to === this.$refs.ungroupedContainer;

            if (fromUngrouped && toUngrouped) {
                this.reorderUngrouped(evt);
            } else if (!fromUngrouped && !toUngrouped) {
                this.reorderAnnexes(evt);
            } else if (fromUngrouped && !toUngrouped) {
                this.crossListToAnnex(evt, movedId);
            } else {
                this.crossListToUngrouped(evt, movedId);
            }
        },

        reorderUngrouped(evt) {
            const ids = [];
            evt.from.querySelectorAll('[data-article-id]').forEach(el => {
                ids.push(el.getAttribute('data-article-id'));
            });
            if (ids.length === 0) return;
            const ordered = ids.map(id => this.ungrouped.find(e => String(e.blog_post_id) === id)).filter(Boolean);
            const extra = this.ungrouped.filter(e => !ids.includes(String(e.blog_post_id)));
            this.ungrouped.splice(0, this.ungrouped.length, ...ordered, ...extra);
            this.ungrouped.forEach((e, i) => { e.position = i + 1; });
            this.persistReorder();
        },

        reorderAnnexes() {
            const ids = [];
            this.$refs.annexesContainer.querySelectorAll('[data-article-id]').forEach(el => {
                ids.push(el.getAttribute('data-article-id'));
            });
            const ordered = ids.map(id => this.seriesItems.find(e => String(e.blog_post_id) === id)).filter(Boolean);
            const extra = this.seriesItems.filter(e => !ids.includes(String(e.blog_post_id)));
            this.seriesItems.splice(0, this.seriesItems.length, ...ordered, ...extra);
            this.seriesItems.forEach((e, i) => { e.position = i + 1; });
            this.saveAnnexReorder();
        },

        saveAnnexReorder() {
            const url = `/org/${this.orgParam}/dossiers/${this.dossierId}/series/annexes/reorder`;
            fetch(url, {
                method: 'PATCH',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': this.csrfToken, 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify({ items: this.seriesItems.map(e => e.blog_post_id) }),
            })
                .then(r => r.json().then(d => ({ ok: r.ok, data: d })))
                .then(({ ok, data }) => {
                    if (!ok) { this.showError(data.message || 'Error'); }
                })
                .catch(() => {});
        },

        crossListToAnnex(evt, movedId) {
            const entry = this.ungrouped.find(e => String(e.blog_post_id) === movedId);
            if (!entry) return;
            this.addToSeries(entry, evt.newIndex);
        },

        crossListToUngrouped(evt, movedId) {
            const item = this.seriesItems.find(e => String(e.blog_post_id) === movedId);
            if (!item) return;
            this.removeAnnex(item);
        },

        persistReorder() {
            const reorderUrl = `/org/${this.orgParam}/dossiers/${this.dossierId}/articles/reorder`;
            fetch(reorderUrl, {
                method: 'PATCH',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': this.csrfToken, 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify({ articles: this.ungrouped.map(e => e.blog_post_id) }),
            })
                .then(r => {
                    if (!r.ok) { this.showError('Reorder failed'); }
                })
                .catch(() => { this.message = this.i18n.dragError || this.i18n.networkError || 'Drag failed'; this.messageType = 'error'; });
        },

        get filteredUngrouped() {
            if (!this.searchQuery) return this.ungrouped;
            const q = this.searchQuery.toLowerCase();
            return this.ungrouped.filter(e => (e.blog_post?.title || '').toLowerCase().includes(q));
        },

        get filteredAnnexItems() {
            if (!this.searchQuery) return this.seriesItems;
            const q = this.searchQuery.toLowerCase();
            return this.seriesItems.filter(e => (e.blog_post?.title || '').toLowerCase().includes(q));
        },

        isRoot(blogPostId) {
            return this.hasSeries && String(this.seriesRootBlogPostId) === String(blogPostId);
        },

        moveAnnex(index, direction) {
            const newIndex = index + direction;
            if (newIndex < 0 || newIndex >= this.seriesItems.length) return;
            const temp = this.seriesItems[index];
            this.seriesItems.splice(index, 1, this.seriesItems[newIndex]);
            this.seriesItems.splice(newIndex, 1, temp);
            this.seriesItems.forEach((e, i) => { e.position = i + 1; });
            this.saveAnnexReorder();
        },

        showSuccess(msg) { this.message = msg; this.messageType = 'success'; setTimeout(() => { this.message = ''; }, 4000); },
        showError(msg) { this.message = msg; this.messageType = 'error'; setTimeout(() => { this.message = ''; }, 5000); },

        formatStatus(status) {
            if (status === 'published') return this.i18n.statusPublished || 'Published';
            if (status === 'draft') return this.i18n.statusDraft || 'Draft';
            return status || '';
        },

        formatDate(date) {
            if (!date) return '';
            try { return new Date(date).toLocaleDateString(); } catch { return ''; }
        },

        openAddArticleModal() {
            this.showAddModal = true;
            this.addSearchQuery = '';
            this.addSearchResults = [];
            this.$nextTick(() => { const el = this.$refs.addSearchInput; if (el) el.focus(); });
        },

        closeAddModal() {
            this.showAddModal = false;
            this.addSearchQuery = '';
            this.addSearchResults = [];
        },

        async searchEligibleArticles() {
            if (this.addSearchQuery.length < 2) { this.addSearchResults = []; return; }
            this.addSearching = true;
            try {
                const searchUrl = `/org/${this.orgParam}/dossiers/${this.dossierId}/articles/search`;
                const res = await fetch(searchUrl + '?q=' + encodeURIComponent(this.addSearchQuery), {
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                });
                const data = await res.json();
                this.addSearchResults = (data.articles || []).map(a => ({
                    ...a,
                    statusLabel: a.status === 'published' ? (this.i18n.statusPublished || 'Published') : (this.i18n.statusDraft || 'Draft'),
                }));
            } catch { this.addSearchResults = []; }
            finally { this.addSearching = false; }
        },

        async attachArticle(article) {
            this.adding = true;
            try {
                const storeUrl = `/org/${this.orgParam}/dossiers/${this.dossierId}/articles`;
                const res = await fetch(storeUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': this.csrfToken, 'X-Requested-With': 'XMLHttpRequest' },
                    body: JSON.stringify({ blog_post_id: article.id }),
                });
                const data = await res.json();
                if (res.ok) {
                    const entry = data.entry;
                    const bp = entry.blog_post;
                    this.ungrouped.push({
                        id: entry.id,
                        blog_post_id: entry.blog_post_id,
                        position: entry.position,
                        blog_post: normalizeArticle(bp),
                    });
                    this.closeAddModal();
                    this.showSuccess(data.message);
                } else {
                    this.showError(data.message || 'Error');
                }
            } catch { this.showError('Network error'); }
            finally { this.adding = false; }
        },

        setAsRoot(entry) {
            if (!entry) return;
            this.saving = true;
            const url = `/org/${this.orgParam}/dossiers/${this.dossierId}/series`;
            fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': this.csrfToken, 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify({ root_blog_post_id: entry.blog_post_id }),
            })
                .then(r => r.json().then(d => ({ ok: r.ok, data: d })))
                .then(({ ok, data }) => {
                    if (!ok) {
                        this.showError(data.message || data.root_blog_post_id?.[0] || 'Error');
                        return;
                    }
                    this.hasSeries = true;
                    this.seriesId = data.series.id;
                    const root = data.series.root_blog_post;
                    this.seriesRoot = normalizeArticle(root);
                    this.seriesRoot.blog_post_id = data.series.root_blog_post_id;
                    this.seriesItems = [];
                    this.ungrouped = this.ungrouped.filter(e => e.blog_post_id !== entry.blog_post_id);
                    this.showSuccess(this.i18n.seriesCreated || 'Series created');
                    this.openMenuId = null;
                })
                .catch(() => this.showError('Error'))
                .finally(() => { this.saving = false; });
        },

        addToSeries(entry, dropIndex) {
            if (!entry) return;
            const previousUngrouped = [...this.ungrouped];
            const previousSeriesItems = [...this.seriesItems];
            this.saving = true;
            const url = `/org/${this.orgParam}/dossiers/${this.dossierId}/series/annexes`;
            fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': this.csrfToken, 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify({ blog_post_id: entry.blog_post_id }),
            })
                .then(r => r.json().then(d => ({ ok: r.ok, data: d })))
                .then(({ ok, data }) => {
                    if (!ok) {
                        this.showError(data.message || data.blog_post_id?.[0] || 'Error');
                        return;
                    }
                    const item = data.item;
                    const normalized = {
                        id: item.id,
                        blog_post_id: item.blog_post_id,
                        position: 0,
                        blog_post: normalizeArticle(item.blog_post),
                    };
                    const insertAt = (typeof dropIndex === 'number' && dropIndex >= 0 && dropIndex <= this.seriesItems.length)
                        ? dropIndex
                        : this.seriesItems.length;
                    this.seriesItems.splice(insertAt, 0, normalized);
                    this.seriesItems.forEach((a, i) => a.position = i + 1);
                    this.ungrouped = this.ungrouped.filter(e => e.blog_post_id !== entry.blog_post_id);
                    this.reorderAnnexes();
                    this.openMenuId = null;
                    this.$nextTick(() => this.initSortables());
                    this.showSuccess(this.i18n.annexAdded || 'Annex added');
                })
                .catch(() => {
                    this.ungrouped = previousUngrouped;
                    this.seriesItems = previousSeriesItems;
                    this.showError('Error');
                })
                .finally(() => { this.saving = false; });
        },

        removeAnnex(item) {
            if (!item) return;
            const previousSeriesItems = [...this.seriesItems];
            const previousUngrouped = [...this.ungrouped];
            this.saving = true;
            const url = `/org/${this.orgParam}/dossiers/${this.dossierId}/series/annexes/${item.blog_post_id}`;
            fetch(url, {
                method: 'DELETE',
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': this.csrfToken },
            })
                .then(r => r.json().then(d => ({ ok: r.ok, data: d })))
                .then(({ ok, data }) => {
                    if (!ok) {
                        this.showError(data.message || 'Error');
                        return;
                    }
                    this.seriesItems = this.seriesItems.filter(a => a.id !== item.id);
                    this.seriesItems.forEach((a, i) => a.position = i + 1);
                    this.ungrouped.push({
                        id: item.id,
                        blog_post_id: item.blog_post_id,
                        position: this.ungrouped.length + 1,
                        blog_post: item.blog_post,
                    });
                    this.$nextTick(() => this.initSortables());
                    this.showSuccess(this.i18n.annexRemoved || 'Annex removed');
                })
                .catch(() => {
                    this.seriesItems = previousSeriesItems;
                    this.ungrouped = previousUngrouped;
                    this.showError('Error');
                })
                .finally(() => { this.saving = false; });
        },

        openDeleteSeriesModal() {
            this.showDeleteSeriesModal = true;
        },

        deleteSeries() {
            this.saving = true;
            const url = `/org/${this.orgParam}/dossiers/${this.dossierId}/series`;
            fetch(url, {
                method: 'DELETE',
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': this.csrfToken },
            })
                .then(r => r.json().then(d => ({ ok: r.ok, data: d })))
                .then(({ ok, data }) => {
                    if (!ok) {
                        this.showError(data.message || 'Error');
                        return;
                    }
                    if (this.seriesRoot) {
                        this.ungrouped.push({
                            id: this.seriesRoot.blogPostId,
                            blog_post_id: this.seriesRoot.blogPostId,
                            position: this.ungrouped.length + 1,
                            blog_post: this.seriesRoot,
                        });
                    }
                    this.seriesItems.forEach(item => {
                        this.ungrouped.push({
                            id: item.blog_post_id,
                            blog_post_id: item.blog_post_id,
                            position: this.ungrouped.length + 1,
                            blog_post: item.blog_post,
                        });
                    });
                    this.hasSeries = false;
                    this.seriesId = null;
                    this.seriesRoot = null;
                    this.seriesItems = [];
                    this.showDeleteSeriesModal = false;
                    this.showSuccess(this.i18n.seriesDeleted || 'Series deleted');
                })
                .catch(() => this.showError('Error'))
                .finally(() => { this.saving = false; });
        },

        confirmDetach(entry) {
            this.detachEntry = entry;
            this.showDetachModal = true;
            this.openMenuId = null;
        },

        async detachArticle() {
            if (!this.detachEntry) return;
            this.detaching = true;
            try {
                const destroyUrl = `/org/${this.orgParam}/dossiers/${this.dossierId}/articles/${this.detachEntry.blog_post_id}`;
                const res = await fetch(destroyUrl, {
                    method: 'DELETE',
                    headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': this.csrfToken, 'X-Requested-With': 'XMLHttpRequest' },
                });
                const data = await res.json();
                if (res.ok) {
                    this.ungrouped = this.ungrouped.filter(e => e.id !== this.detachEntry.id);
                    this.showDetachModal = false;
                    this.detachEntry = null;
                    this.showSuccess(data.message || this.i18n.articleDetached);
                } else {
                    this.showError(data.message || 'Error');
                }
            } catch { this.showError('Network error'); }
            finally { this.detaching = false; }
        },

        moveUngrouped(index, direction) {
            const newIndex = index + direction;
            if (newIndex < 0 || newIndex >= this.ungrouped.length) return;
            const temp = this.ungrouped[index];
            this.ungrouped.splice(index, 1, this.ungrouped[newIndex]);
            this.ungrouped.splice(newIndex, 1, temp);
            this.ungrouped.forEach((e, i) => { e.position = i + 1; });

            const reorderUrl = `/org/${this.orgParam}/dossiers/${this.dossierId}/articles/reorder`;
            fetch(reorderUrl, {
                method: 'PATCH',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': this.csrfToken, 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify({ articles: this.ungrouped.map(e => e.blog_post_id) }),
            })
                .then(r => r.json().then(d => ({ ok: r.ok, data: d })))
                .then(({ ok, data }) => {
                    if (!ok) {
                        this.showError(data.message || 'Error');
                    }
                })
                .catch(() => this.showError('Network error'));
        },

        toggleMenu(id) {
            this.openMenuId = this.openMenuId === id ? null : id;
        },
    }));
}

function registerDossierSemanticArticleSearch() {
    if (typeof Alpine === 'undefined') return;

    Alpine.data('dossierSemanticArticleSearch', (config) => ({
        query: '',
        loading: false,
        results: [],
        searched: false,
        error: '',
        validationError: '',
        endpoint: config.endpoint,
        i18n: config.i18n || {},

        async search() {
            if (this.loading) return;

            const trimmedQuery = this.query.trim();
            this.error = '';
            this.validationError = '';

            if (trimmedQuery.length < 2) {
                this.validationError = this.i18n.validationTooShort;
                return;
            }

            this.loading = true;
            this.searched = true;
            this.results = [];

            try {
                const url = new URL(this.endpoint, window.location.origin);
                url.search = new URLSearchParams({ query: trimmedQuery }).toString();

                const response = await fetch(url.toString(), {
                    method: 'GET',
                    credentials: 'same-origin',
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                });

                if (response.ok) {
                    const data = await response.json();
                    this.results = Array.isArray(data.data) ? data.data.slice(0, 5) : [];
                    return;
                }

                if (response.status === 422) {
                    this.validationError = this.i18n.validationTooShort;
                    return;
                }

                if (response.status === 503) {
                    this.error = this.i18n.unavailable;
                    return;
                }

                this.error = this.i18n.genericError;
            } catch (e) {
                this.error = this.i18n.genericError;
            } finally {
                this.loading = false;
            }
        },

        excerpt(content) {
            const text = String(content || '').replace(/\s+/g, ' ').trim();

            if (text.length <= 320) {
                return text;
            }

            return text.slice(0, 317).trimEnd() + '…';
        },

        passageLabel(index) {
            return this.i18n.passage.replace(':number', Number(index) + 1);
        },

        resultCountLabel() {
            return this.i18n.resultsCount.replace(':count', this.results.length);
        },
    }));
}

function registerDossierMembersCard() {
    if (typeof Alpine === 'undefined') return;

    Alpine.data('dossierMembersCard', (config) => ({
        members: [],
        showSearch: false,
        searchQuery: '',
        searchResults: [],
        searchLoading: false,
        showManageModal: false,
        showRemoveModal: false,
        removeTarget: null,
        message: '',
        messageType: 'success',
        csrfToken: config.csrfToken,
        dossierId: config.dossierId,
        orgParam: config.orgParam,
        ownerId: config.ownerId,
        ownerName: config.ownerName || '',
        ownerInitial: config.ownerInitial || '?',
        currentUserId: config.currentUserId,
        canManage: config.canManage || false,
        i18n: config.i18n,

        init() {
            this.loadMembers();
            document.addEventListener('keydown', (ev) => {
                if (ev.key === 'Escape') {
                    if (this.showRemoveModal) { this.showRemoveModal = false; this.removeTarget = null; }
                    else if (this.showManageModal) { this.showManageModal = false; }
                }
            });
        },

        get displayMembers() {
            return this.members.slice(0, 5);
        },

        get overflowCount() {
            return Math.max(0, this.members.length - 5);
        },

        get currentRoleLabel() {
            if (String(this.currentUserId) === String(this.ownerId)) {
                return this.i18n.ownerBadge || 'Owner';
            }
            const m = this.members.find(m => String(m.id) === String(this.currentUserId));
            return m?.roleLabel || '';
        },

        loadMembers() {
            const url = `/org/${this.orgParam}/dossiers/${this.dossierId}/members`;
            fetch(url, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
                .then(r => r.json())
                .then(data => {
                    this.members = (data.members || []).map(m => ({
                        ...m,
                        isYou: String(m.id) === String(this.currentUserId),
                        displayName: `${m.first_name || ''} ${(m.name || '').toUpperCase()}`.trim(),
                        initial: (m.first_name || m.name || '?').charAt(0).toUpperCase(),
                        roleLabel: m.role === 'reader' ? (this.i18n.roleReader || 'Reader') : (m.role === 'editor' ? (this.i18n.roleEditor || 'Editor') : m.role),
                    }));
                })
                .catch(() => {});
        },

        searchUsers() {
            if (this.searchQuery.length < 2) { this.searchResults = []; return; }
            this.searchLoading = true;
            const url = `/org/${this.orgParam}/dossiers/${this.dossierId}/members/search?q=${encodeURIComponent(this.searchQuery)}`;
            fetch(url, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
                .then(r => r.json())
                .then(data => {
                    this.searchResults = (data.users || [])
                        .map(u => ({
                            ...u,
                            displayName: `${u.first_name || ''} ${(u.name || '').toUpperCase()}`.trim(),
                            _selectedRole: 'reader',
                        }));
                })
                .catch(() => {})
                .finally(() => { this.searchLoading = false; });
        },

        addMember(user) {
            const url = `/org/${this.orgParam}/dossiers/${this.dossierId}/members`;
            fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': this.csrfToken,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({ user_id: user.id, role: user._selectedRole }),
            })
                .then(r => r.json().then(d => ({ ok: r.ok, data: d })))
                .then(({ ok, data }) => {
                    if (!ok) {
                        this.showMessage(data.message || this.i18n.memberAlready, 'error');
                        return;
                    }
                    this.members.push({
                        ...data.member,
                        displayName: `${data.member.first_name || ''} ${(data.member.name || '').toUpperCase()}`.trim(),
                        initial: (data.member.first_name || data.member.name || '?').charAt(0).toUpperCase(),
                        roleLabel: data.member.role === 'reader' ? (this.i18n.roleReader || 'Reader') : (this.i18n.roleEditor || 'Editor'),
                    });
                    this.searchQuery = '';
                    this.searchResults = [];
                    this.showMessage(data.message || this.i18n.memberAdded, 'success');
                })
                .catch(() => { this.showMessage(this.i18n.memberAlready, 'error'); });
        },

        updateRole(member, newRole) {
            const url = `/org/${this.orgParam}/dossiers/${this.dossierId}/members/${member.id}`;
            fetch(url, {
                method: 'PATCH',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': this.csrfToken,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({ role: newRole }),
            })
                .then(r => r.json().then(d => ({ ok: r.ok, data: d })))
                .then(({ ok, data }) => {
                    if (!ok) {
                        this.showMessage(data.message || this.i18n.memberRoleUpdated, 'error');
                        return;
                    }
                    member.role = newRole;
                    member.roleLabel = newRole === 'reader' ? (this.i18n.roleReader || 'Reader') : (this.i18n.roleEditor || 'Editor');
                    this.showMessage(data.message || this.i18n.memberRoleUpdated, 'success');
                })
                .catch(() => {});
        },

        openRemoveModal(member) {
            this.removeTarget = member;
            this.showRemoveModal = true;
        },

        confirmRemove() {
            if (!this.removeTarget) return;
            const member = this.removeTarget;
            const url = `/org/${this.orgParam}/dossiers/${this.dossierId}/members/${member.id}`;
            fetch(url, {
                method: 'DELETE',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': this.csrfToken,
                },
            })
                .then(r => r.json().then(d => ({ ok: r.ok, data: d })))
                .then(({ ok, data }) => {
                    if (!ok) {
                        this.showMessage(data.message || this.i18n.memberRemoved, 'error');
                        return;
                    }
                    this.members = this.members.filter(m => m.id !== member.id);
                    this.showRemoveModal = false;
                    this.removeTarget = null;
                    this.showMessage(data.message || this.i18n.memberRemoved, 'success');
                })
                .catch(() => {});
        },

        removeMember(member) {
            this.openRemoveModal(member);
        },

        showMessage(msg, type) {
            this.message = msg;
            this.messageType = type;
            setTimeout(() => { this.message = ''; }, 3000);
        },
    }));
}

function registerDossierArticlesCard() {
    if (typeof Alpine === 'undefined') return;

    Alpine.data('dossierArticlesCard', (config) => ({
        entries: [],
        searchQuery: '',
        message: '',
        messageType: 'success',
        showAddModal: false,
        addSearchQuery: '',
        addSearchResults: [],
        addSearching: false,
        adding: false,
        showDetachModal: false,
        detachEntry: null,
        detaching: false,
        openMenuId: null,
        i18n: config.i18n || {},
        canManageArticles: config.canManageArticles || false,

        init() {
            this.entries = (config.entries || []).map(e => ({
                ...e,
                blog_post: e.blog_post || null,
                canDeleteArticle: config.currentUserId === (e.blog_post?.user_id || null),
            }));
            document.addEventListener('click', (ev) => {
                if (this.openMenuId && !ev.target.closest('[data-article-menu]') && !ev.target.closest('[data-article-menu-btn]')) {
                    this.openMenuId = null;
                }
            });
            document.addEventListener('keydown', (ev) => {
                if (ev.key === 'Escape') {
                    if (this.showDetachModal) { this.showDetachModal = false; this.detachEntry = null; }
                    else if (this.showAddModal) { this.showAddModal = false; this.addSearchQuery = ''; this.addSearchResults = []; }
                    else { this.openMenuId = null; }
                }
            });
        },

        get filteredEntries() {
            if (!this.searchQuery) return this.entries;
            const q = this.searchQuery.toLowerCase();
            return this.entries.filter(e => (e.blog_post?.title || '').toLowerCase().includes(q));
        },

        openAddModal() {
            this.showAddModal = true;
            this.addSearchQuery = '';
            this.addSearchResults = [];
            this.$nextTick(() => { const el = this.$refs.addSearchInput; if (el) el.focus(); });
        },

        closeAddModal() {
            this.showAddModal = false;
            this.addSearchQuery = '';
            this.addSearchResults = [];
        },

        async searchEligible() {
            if (this.addSearchQuery.length < 2) { this.addSearchResults = []; return; }
            this.addSearching = true;
            try {
                const res = await fetch(config.searchUrl + '?q=' + encodeURIComponent(this.addSearchQuery), {
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                });
                const data = await res.json();
                this.addSearchResults = (data.articles || []).map(a => ({ ...a, statusLabel: a.status === 'published' ? (config.i18n.statusPublished || 'Published') : (config.i18n.statusDraft || 'Draft') }));
            } catch { this.addSearchResults = []; }
            finally { this.addSearching = false; }
        },

        async attachArticle(article) {
            this.adding = true;
            try {
                const res = await fetch(config.storeUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': config.csrfToken, 'X-Requested-With': 'XMLHttpRequest' },
                    body: JSON.stringify({ blog_post_id: article.id }),
                });
                const data = await res.json();
                if (res.ok) {
                    const entry = data.entry;
                    entry.canDeleteArticle = config.currentUserId === (entry.blog_post?.user_id || null);
                    this.entries.push(entry);
                    this.closeAddModal();
                    this.showSuccess(data.message);
                } else {
                    this.showError(data.message || config.i18n.uploadFailed);
                }
            } catch { this.showError(config.i18n.networkError); }
            finally { this.adding = false; }
        },

        confirmDetach(entry) {
            this.detachEntry = entry;
            this.showDetachModal = true;
            this.openMenuId = null;
        },

        async detachArticle() {
            if (!this.detachEntry) return;
            this.detaching = true;
            try {
                const res = await fetch(config.destroyUrl.replace('__POST_ID__', this.detachEntry.blog_post_id), {
                    method: 'DELETE',
                    headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': config.csrfToken, 'X-Requested-With': 'XMLHttpRequest' },
                });
                const data = await res.json();
                if (res.ok) {
                    this.entries = this.entries.filter(e => e.id !== this.detachEntry.id);
                    this.showDetachModal = false;
                    this.detachEntry = null;
                    this.showSuccess(data.message);
                } else {
                    this.showError(data.message || config.i18n.networkError);
                }
            } catch { this.showError(config.i18n.networkError); }
            finally { this.detaching = false; }
        },

        async moveArticle(index, direction) {
            const newIndex = index + direction;
            if (newIndex < 0 || newIndex >= this.entries.length) return;
            const temp = this.entries[index];
            this.entries.splice(index, 1, this.entries[newIndex]);
            this.entries.splice(newIndex, 1, temp);
            this.entries.forEach((e, i) => { e.position = i + 1; });
            try {
                const res = await fetch(config.reorderUrl, {
                    method: 'PATCH',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': config.csrfToken, 'X-Requested-With': 'XMLHttpRequest' },
                    body: JSON.stringify({ articles: this.entries.map(e => e.blog_post_id) }),
                });
                const data = await res.json();
                if (!res.ok) {
                    if (data.articles) { this.entries = data.articles.map(a => ({ ...a, canDeleteArticle: config.currentUserId === (a.blog_post?.user_id || null) })); }
                    this.showError(data.message || config.i18n.networkError);
                }
            } catch { this.showError(config.i18n.networkError); }
        },

        formatStatus(status) {
            if (status === 'published') return config.i18n.statusPublished || 'Published';
            if (status === 'draft') return config.i18n.statusDraft || 'Draft';
            return status || '';
        },

        formatDate(date) {
            if (!date) return '';
            try { return new Date(date).toLocaleDateString(); } catch { return ''; }
        },

        editUrl(entry) {
            if (!entry.blog_post?.slug) return '#';
            return config.blogEditUrl.replace('__SLUG__', entry.blog_post.slug);
        },

        toggleMenu(id) {
            this.openMenuId = this.openMenuId === id ? null : id;
        },

        showSuccess(msg) { this.message = msg; this.messageType = 'success'; setTimeout(() => { this.message = ''; }, 4000); },
        showError(msg) { this.message = msg; this.messageType = 'error'; setTimeout(() => { this.message = ''; }, 5000); },
    }));
}

function registerDossierFilesCard() {
    if (typeof Alpine === 'undefined') return;

    FilePond.registerPlugin(FilePondPluginFileValidateType, FilePondPluginFileValidateSize);

    Alpine.data('dossierFilesCard', (config) => ({
        files: [],
        quota: { used_bytes: 0, limit_bytes: null, remaining_bytes: null },
        uploading: false,
        saving: false,
        message: '',
        messageType: 'success',
        csrfToken: config.csrfToken,
        dossierId: config.dossierId,
        orgParam: config.orgParam,
        canManageFiles: config.canManageFiles,
        canDeleteFiles: config.canDeleteFiles,
        i18n: config.i18n,
        currentPage: 1,
        lastPage: 1,
        totalFiles: 0,
        _pond: null,
        showDeleteModal: false,
        deleteTarget: null,
        showPreviewModal: false,
        previewFile: null,
        showImportMenu: false,
        sortBy: 'name',
        sortDirection: 'asc',
        viewMode: 'list',
        showArticleModal: false,
        articleTitle: '',
        articleCategoryId: '',
        showMdModal: false,
        mdFileName: '',
        mdContent: '',

        openArticleModal() {
            this.showArticleModal = true;
            this.articleTitle = '';
            this.articleCategoryId = '';
        },

        openMdModal() {
            this.showMdModal = true;
            this.mdFileName = '';
            this.mdContent = '';
        },

        async createArticle() {
            if (!this.articleTitle.trim() || !this.articleCategoryId) return;
            
            this.saving = true;
            try {
                const response = await fetch(`/org/${this.orgParam}/blog`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': this.csrfToken,
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify({
                        title: this.articleTitle,
                        category_id: this.articleCategoryId,
                        content: '',
                        status: 'draft',
                    }),
                });
                
                const data = await response.json();
                
                if (response.ok) {
                    this.showArticleModal = false;
                    this.showSuccess(this.i18n.articleCreated || 'Article created');
                    // Redirect to edit the new article
                    window.location.href = data.redirect_url || `/org/${this.orgParam}/blog/${data.post.slug}/edit`;
                } else {
                    this.showError(data.message || 'Error creating article');
                }
            } catch (error) {
                this.showError('Network error');
            } finally {
                this.saving = false;
            }
        },

        async createMarkdownNote() {
            if (!this.mdFileName.trim()) return;
            
            this.saving = true;
            try {
                const fileName = this.mdFileName.endsWith('.md') ? this.mdFileName : `${this.mdFileName}.md`;
                const blob = new Blob([this.mdContent], { type: 'text/markdown' });
                const file = new File([blob], fileName, { type: 'text/markdown' });
                
                const formData = new FormData();
                formData.append('files[]', file);
                
                const response = await fetch(`/org/${this.orgParam}/dossiers/${this.dossierId}/files`, {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': this.csrfToken,
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: formData,
                });
                
                const data = await response.json();
                
                if (response.ok) {
                    this.showMdModal = false;
                    await this.loadFiles();
                    this.showSuccess(this.i18n.markdownCreated || 'Markdown note created');
                } else {
                    this.showError(data.message || 'Error creating markdown note');
                }
            } catch (error) {
                this.showError('Network error');
            } finally {
                this.saving = false;
            }
        },

        triggerMediaUpload(type) {
            const inputMap = {
                'image': 'imageInput',
                'video': 'videoInput',
                'audio': 'audioInput',
            };
            const ref = inputMap[type];
            if (ref && this.$refs[ref]) {
                this.$refs[ref].click();
            }
        },

        async handleMediaFiles(event, type) {
            const files = event.target.files;
            if (!files || files.length === 0) return;
            
            this.saving = true;
            try {
                const formData = new FormData();
                for (let i = 0; i < files.length; i++) {
                    formData.append('files[]', files[i]);
                }
                
                const response = await fetch(`/org/${this.orgParam}/dossiers/${this.dossierId}/files`, {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': this.csrfToken,
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: formData,
                });
                
                const data = await response.json();
                
                if (response.ok) {
                    await this.loadFiles();
                    this.showSuccess(this.i18n.filesUploaded || 'Files uploaded');
                } else {
                    this.showError(data.message || 'Error uploading files');
                }
            } catch (error) {
                this.showError('Network error');
            } finally {
                this.saving = false;
                // Reset the input
                event.target.value = '';
            }
        },

        get sortedFiles() {
            const sorted = [...this.files];
            sorted.sort((a, b) => {
                let aVal, bVal;
                switch (this.sortBy) {
                    case 'name':
                        aVal = (a.display_name || a.original_name || '').toLowerCase();
                        bVal = (b.display_name || b.original_name || '').toLowerCase();
                        break;
                    case 'size':
                        aVal = a.size_bytes || 0;
                        bVal = b.size_bytes || 0;
                        break;
                    case 'uploader':
                        aVal = (a.uploader?.name || a.uploader?.email || '').toLowerCase();
                        bVal = (b.uploader?.name || b.uploader?.email || '').toLowerCase();
                        break;
                    case 'date':
                        aVal = new Date(a.created_at || 0).getTime();
                        bVal = new Date(b.created_at || 0).getTime();
                        break;
                    default:
                        return 0;
                }
                if (aVal < bVal) return this.sortDirection === 'asc' ? -1 : 1;
                if (aVal > bVal) return this.sortDirection === 'asc' ? 1 : -1;
                return 0;
            });
            return sorted;
        },

        toggleSort(column) {
            if (this.sortBy === column) {
                this.sortDirection = this.sortDirection === 'asc' ? 'desc' : 'asc';
            } else {
                this.sortBy = column;
                this.sortDirection = 'asc';
            }
        },

        browseFiles() {
            if (this._pond) {
                this._pond.browse();
            }
        },

        init() {
            this.loadFiles();
            document.addEventListener('keydown', (ev) => {
                if (ev.key === 'Escape') {
                    if (this.showPreviewModal) { this.showPreviewModal = false; this.previewFile = null; }
                    else if (this.showDeleteModal) { this.showDeleteModal = false; this.deleteTarget = null; }
                    else if (this.showArticleModal) { this.showArticleModal = false; }
                    else if (this.showMdModal) { this.showMdModal = false; }
                }
            });
            if (this.canManageFiles && this.$refs.filePondContainer) {
                const self = this;
                const csrfToken = this.csrfToken;
                const orgParam = this.orgParam;
                const dossierId = this.dossierId;
                const endpoint = `/org/${orgParam}/dossiers/${dossierId}/files`;
                const acceptedTypes = [
                    'image/jpeg', 'image/png', 'image/webp', 'image/gif',
                    'application/pdf',
                    'application/msword',
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                    'text/plain', 'text/markdown', 'text/csv',
                    'application/vnd.ms-excel',
                    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    'application/zip', 'application/x-zip-compressed',
                ];
                const labelIdle = this.i18n.uploadHelp || 'Drag & drop files or <span class="filepond--label-action">browse</span>';

                this._pond = FilePond.create(this.$refs.filePondContainer, Object.assign(Object.create(null), {
                    multiple: true,
                    maxFiles: 5,
                    maxFileSize: '20MB',
                    acceptedFileTypes: acceptedTypes,
                    labelIdle: labelIdle,
                    onaddfile(err, file) {
                        if (err) { console.warn('[FilePond] addfile error', err); return; }
                        const formData = new FormData();
                        formData.append('files[]', file.file, file.file.name);

                        fetch(endpoint, {
                            method: 'POST',
                            headers: {
                                'Accept': 'application/json',
                                'X-CSRF-TOKEN': csrfToken,
                                'X-Requested-With': 'XMLHttpRequest',
                            },
                            body: formData,
                        })
                            .then(r => r.json().then(d => ({ ok: r.ok, data: d })))
                            .then(({ ok, data }) => {
                                if (ok) {
                                    self.showMessage(data.message || self.i18n.uploaded, 'success');
                                    self._pond.removeFile(file.id);
                                    self.loadFiles(self.currentPage);
                                } else {
                                    self.showMessage(data.message || self.i18n.uploadFailed, 'error');
                                    self._pond.removeFile(file.id);
                                }
                            })
                            .catch(() => {
                                self.showMessage(self.i18n.uploadFailed, 'error');
                                self._pond.removeFile(file.id);
                            });
                    },
                }));
            }
        },

        destroy() {
            if (this._pond) {
                this._pond.destroy();
                this._pond = null;
            }
        },

        loadFiles(page) {
            page = page || 1;
            const url = `/org/${this.orgParam}/dossiers/${this.dossierId}/files?page=${page}`;
            return fetch(url, { cache: 'no-store', headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
                .then(r => r.json().then(data => ({ ok: r.ok, data })))
                .then(({ ok, data }) => {
                    if (!ok) {
                        this.showMessage(data.message || this.i18n.uploadFailed, 'error');
                        return;
                    }

                    return data;
                })
                .then(data => {
                    if (!data) return;

                    this.files = (data.files.data || []).map(file => this.normalizeFile(file));
                    this.quota = data.quota || this.quota;
                    this.currentPage = data.files.current_page || 1;
                    this.lastPage = data.files.last_page || 1;
                    this.totalFiles = data.files.total || 0;
                })
                .catch(() => this.showMessage(this.i18n.uploadFailed, 'error'));
        },

        formatBytes(bytes) {
            if (!bytes || bytes === 0) return '0 o';
            const units = ['o', 'Ko', 'Mo', 'Go'];
            const i = Math.floor(Math.log(bytes) / Math.log(1024));
            return (bytes / Math.pow(1024, i)).toFixed(i > 0 ? 1 : 0) + ' ' + units[i];
        },

        normalizeFile(file) {
            return {
                ...file,
                sizeFormatted: this.formatBytes(file.size_bytes),
                uploadedAtFormatted: file.created_at ? new Date(file.created_at).toLocaleDateString() : '',
            };
        },

        formatQuota(bytes) {
            return this.formatBytes(bytes);
        },

        fileTypeLabel(mime) {
            if (mime === 'application/pdf') return 'PDF';
            if (mime?.startsWith('image/')) return 'Image';
            if (mime === 'application/msword' || mime?.includes('wordprocessingml')) return 'Word';
            if (mime === 'text/plain') return 'TXT';
            if (mime === 'text/markdown') return 'Markdown';
            if (mime === 'text/csv' || mime === 'application/vnd.ms-excel' || mime?.includes('spreadsheetml')) return 'Excel';
            if (mime === 'application/zip' || mime === 'application/x-zip-compressed') return 'ZIP';
            return mime || '—';
        },

        async deleteFile(file) {
            this.saving = true;
            const url = `/org/${this.orgParam}/dossiers/${this.dossierId}/files/${file.id}`;
            try {
                const response = await fetch(url, {
                    method: 'DELETE',
                    headers: {
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': this.csrfToken,
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                });
                const data = await response.json();
                
                if (response.ok) {
                    this.showMessage(data.message || this.i18n.deleted, 'success');
                    await this.loadFiles(this.currentPage);
                } else {
                    this.showMessage(data.message || this.i18n.deleteFailed, 'error');
                }
            } catch (error) {
                this.showMessage(this.i18n.deleteFailed, 'error');
            } finally {
                this.saving = false;
            }
        },

        openDeleteModal(file) {
            this.deleteTarget = file;
            this.showDeleteModal = true;
        },

        confirmDeleteFile() {
            if (!this.deleteTarget) return;
            this.showDeleteModal = false;
            this.deleteFile(this.deleteTarget);
            this.deleteTarget = null;
        },

        openPreview(file) {
            this.previewFile = file;
            this.showPreviewModal = true;
        },

        get quotaPercent() {
            if (!this.quota.limit_bytes || this.quota.limit_bytes === 0) return 0;
            return Math.min(100, Math.round((this.quota.used_bytes / this.quota.limit_bytes) * 100));
        },

        get quotaLabel() {
            if (this.quota.limit_bytes === null) {
                return this.i18n.storageUnlimited + ' — ' + this.formatQuota(this.quota.used_bytes) + ' ' + this.i18n.storageUsedLabel;
            }
            return this.formatQuota(this.quota.used_bytes) + ' / ' + this.formatQuota(this.quota.limit_bytes);
        },

        showMessage(text, type) {
            this.message = text;
            this.messageType = type;
            setTimeout(() => { this.message = ''; }, 3000);
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
        authorUserId: config.authorUserId || null,
        currentUserId: config.currentUserId || null,
        newAssignee: config.currentUserId || null,
        editingAssignee: null,
        pendingDelete: null,
        loadTodosRequestId: 0,
        loadingTodos: false,
        recentLocalTodos: {},
        recentDeletedTodoIds: {},
        recentTodoMutationTtlMs: 5000,
        pollingTimer: null,
        pollingIntervalMs: 3000,

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
                this.startPolling();
                this._dispatching = true;
                window.dispatchEvent(new CustomEvent('close-other-sidebar-cards'));
                this._dispatching = false;
            } else {
                this.stopPolling();
            }
        },

        init() {
            if (localStorage.getItem('editor_sidebar_card_todo') === '1') {
                this.open = true;
                this.loadTodos();
                this.startPolling();
            }
            window.addEventListener('close-other-sidebar-cards', () => {
                if (this._dispatching) return;
                this.open = false;
                localStorage.setItem('editor_sidebar_card_todo', '0');
                this.stopPolling();
            });
            window.addEventListener('snapshot-restore', () => {
                if (this.open) this.loadTodos();
            });
            document.addEventListener('visibilitychange', () => {
                if (document.hidden) {
                    this.stopPolling();
                    return;
                }

                if (this.open) {
                    this.loadTodos(true);
                    this.startPolling();
                }
            });
            window.addEventListener('focus', () => {
                if (this.open && !document.hidden) this.loadTodos(true);
            });
        },

        destroy() {
            this.stopPolling();
        },

        startPolling() {
            if (this.pollingTimer || document.hidden) return;
            this.pollingTimer = window.setInterval(() => {
                if (!this.open || document.hidden || this.loadingTodos) return;
                this.loadTodos(true);
            }, this.pollingIntervalMs);
        },

        stopPolling() {
            if (!this.pollingTimer) return;
            window.clearInterval(this.pollingTimer);
            this.pollingTimer = null;
        },

        isThreadsOpen(todo) {
            return this.threadsOpen[todo.id] ?? false;
        },

        toggleThreads(todo) {
            this.threadsOpen[todo.id] = !(this.threadsOpen[todo.id] ?? false);
        },

        loadTodos(silent = false) {
            if (this.loadingTodos) return Promise.resolve();
            const requestId = ++this.loadTodosRequestId;
            this.loadingTodos = true;
            this.loading = !silent;
            this.error = '';
            return fetch(this.indexUrl, { cache: 'no-store' })
                .then(r => r.json().then(d => ({ ok: r.ok, data: d })))
                .then(({ ok, data }) => {
                    if (requestId !== this.loadTodosRequestId) return;
                    this.loadingTodos = false;
                    if (!ok) {
                        this.error = data.message || this.i18n.loadError || 'Failed to load tasks.';
                        this.loading = false;
                        return;
                    }
                    this.reconcileTodos(data.todos || []);
                    this.loading = false;
                })
                .catch(() => {
                    if (requestId !== this.loadTodosRequestId) return;
                    this.loadingTodos = false;
                    this.error = this.i18n.loadError || 'Failed to load tasks.';
                    this.loading = false;
                });
        },

        invalidateTodoLoads() {
            this.loadTodosRequestId++;
            this.loadingTodos = false;
            this.loading = false;
        },

        purgeRecentTodoMutations() {
            const now = Date.now();
            Object.entries(this.recentLocalTodos).forEach(([id, entry]) => {
                if (entry.expiresAt <= now) delete this.recentLocalTodos[id];
            });
            Object.entries(this.recentDeletedTodoIds).forEach(([id, expiresAt]) => {
                if (expiresAt <= now) delete this.recentDeletedTodoIds[id];
            });
        },

        rememberLocalTodo(todo) {
            const normalized = this.normalizeTodo(todo);
            this.recentLocalTodos[normalized.id] = {
                todo: normalized,
                expiresAt: Date.now() + this.recentTodoMutationTtlMs,
            };
            delete this.recentDeletedTodoIds[normalized.id];
            return normalized;
        },

        rememberDeletedTodo(todoId) {
            delete this.recentLocalTodos[todoId];
            this.recentDeletedTodoIds[todoId] = Date.now() + this.recentTodoMutationTtlMs;
        },

        reconcileTodos(serverTodos) {
            this.purgeRecentTodoMutations();
            const localById = new Map(this.todos.map(t => [t.id, t]));
            const reconciledById = new Map();

            serverTodos.forEach(t => {
                const normalized = this.normalizeTodo(t);
                const local = localById.get(normalized.id);

                if (this.editingTodo === normalized.id && local) {
                    normalized.title = local.title;
                }

                reconciledById.set(normalized.id, normalized);
            });

            Object.values(this.recentLocalTodos).forEach(entry => {
                reconciledById.set(entry.todo.id, entry.todo);
            });

            Object.keys(this.recentDeletedTodoIds).forEach(id => {
                reconciledById.delete(id);
            });

            this.todos = Array.from(reconciledById.values());
            this.todos.forEach(t => { if (this.threadsOpen[t.id] === undefined) this.threadsOpen[t.id] = false; });
        },

        normalizeTodo(todo) {
            return {
                ...todo,
                assigned_to: todo.assigned_to || '',
                can_edit: Boolean(todo.can_edit),
                can_assign: Boolean(todo.can_assign),
                can_change_status: Boolean(todo.can_change_status),
                can_complete: Boolean(todo.can_complete),
                can_reopen: Boolean(todo.can_reopen),
                can_delete: Boolean(todo.can_delete),
            };
        },

        requestJson(url, options) {
            return fetch(url, options)
                .then(r => r.json().then(d => ({ ok: r.ok, status: r.status, data: d })));
        },

        canToggleStatus(todo) {
            return todo.can_change_status;
        },

        canChooseStatus(todo, status) {
            if (status === todo.status) return true;
            return todo.can_change_status;
        },

        applyTodo(todo) {
            this.invalidateTodoLoads();
            const normalized = this.rememberLocalTodo(todo);
            const idx = this.todos.findIndex(t => t.id === normalized.id);
            if (idx !== -1) this.todos[idx] = normalized;
            return normalized;
        },

        reloadAfterError(status) {
            if ([403, 404, 409, 422].includes(status)) this.loadTodos(true);
        },

        createTodo() {
            const title = this.newTitle.trim();
            if (!title || this.creating) return;
            this.creating = true;
            this.error = '';
            this.success = '';
            this.requestJson(this.storeUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.i18n.csrfToken || '' },
                body: JSON.stringify({ title, assigned_to: this.newAssignee }),
            })
                .then(({ ok, status, data }) => {
                    if (!ok) {
                        this.error = data.message || this.i18n.createError || 'Failed to create task.';
                        this.reloadAfterError(status);
                        return;
                    }
                    this.invalidateTodoLoads();
                    const todo = this.rememberLocalTodo(data.todo);
                    const idx = this.todos.findIndex(t => t.id === todo.id);
                    if (idx === -1) this.todos.push(todo);
                    else this.todos[idx] = todo;
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
            if (!todo.can_edit) return;
            this.editingTodo = todo.id;
            this.editTitle = todo.title;
        },

        saveEdit(todo) {
            const title = this.editTitle.trim();
            if (!title || this.saving || !todo.can_edit) return;
            this.saving = true;
            this.error = '';
            const url = this.updateUrlBase.replace('__TODO_ID__', todo.id);
            this.requestJson(url, {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.i18n.csrfToken || '' },
                body: JSON.stringify({ title }),
            })
                .then(({ ok, status, data }) => {
                    if (!ok) {
                        this.error = data.message || this.i18n.notOwner || this.i18n.updateError || 'Failed to update task.';
                        this.reloadAfterError(status);
                        return;
                    }
                    this.applyTodo(data.todo);
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
            if (!this.canChooseStatus(todo, todo.status)) {
                this.loadTodos(true);
                return;
            }
            this.error = '';
            const url = this.updateUrlBase.replace('__TODO_ID__', todo.id);
            this.requestJson(url, {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.i18n.csrfToken || '' },
                body: JSON.stringify({ status: todo.status }),
            })
                .then(({ ok, status, data }) => {
                    if (!ok) {
                        this.error = data.message || this.i18n.notOwner || this.i18n.updateError || 'Failed to update task.';
                        this.reloadAfterError(status);
                        return;
                    }
                    this.applyTodo(data.todo);
                })
                .catch(() => {
                    this.error = this.i18n.updateError || 'Failed to update task.';
                    this.loadTodos();
                });
        },

        toggleDone(todo) {
            if (!this.canToggleStatus(todo)) return;
            const newStatus = todo.status === 'done' ? 'todo' : 'done';
            const url = this.updateUrlBase.replace('__TODO_ID__', todo.id);
            this.error = '';
            this.requestJson(url, {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.i18n.csrfToken || '' },
                body: JSON.stringify({ status: newStatus }),
            })
                .then(({ ok, status, data }) => {
                    if (!ok) {
                        this.error = data.message || this.i18n.notOwner || this.i18n.updateError || 'Failed to update task.';
                        this.reloadAfterError(status);
                        return;
                    }
                    this.applyTodo(data.todo);
                })
                .catch(() => {
                    this.error = this.i18n.updateError || 'Failed to update task.';
                    this.loadTodos();
                });
        },

        confirmDeleteTodo(todo) {
            if (!todo.can_delete) return;
            this.pendingDelete = todo.id;
        },

        cancelDeleteTodo() {
            this.pendingDelete = null;
        },

        doDeleteTodo(todo) {
            this.pendingDelete = null;
            this.error = '';
            const url = this.destroyUrlBase.replace('__TODO_ID__', todo.id);
            this.requestJson(url, {
                method: 'DELETE',
                headers: { 'X-CSRF-TOKEN': this.i18n.csrfToken || '' },
            })
                .then(({ ok, status, data }) => {
                    if (!ok) {
                        this.error = data.message || this.i18n.notOwner || this.i18n.deleteError || 'Failed to delete task.';
                        this.reloadAfterError(status);
                        return;
                    }
                    this.invalidateTodoLoads();
                    this.rememberDeletedTodo(todo.id);
                    this.todos = this.todos.filter(t => t.id !== todo.id);
                    this.success = data.message || this.i18n.deleted;
                    setTimeout(() => { this.success = ''; }, 3000);
                })
                .catch(() => {
                    this.error = this.i18n.deleteError || 'Failed to delete task.';
                });
        },

        startEditAssignee(todo) {
            if (!todo.can_assign) return;
            this.editingAssignee = todo.id;
        },

        saveEditAssignee(todo) {
            if (!todo.can_assign) return;
            this.editingAssignee = null;
            this.error = '';
            const assignedTo = todo.assigned_to || null;
            const url = this.updateUrlBase.replace('__TODO_ID__', todo.id);
            this.requestJson(url, {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.i18n.csrfToken || '' },
                body: JSON.stringify({ assigned_to: assignedTo }),
            })
                .then(({ ok, status, data }) => {
                    if (!ok) {
                        this.error = data.message || this.i18n.notOwner || this.i18n.assignError || 'Failed to update assignee.';
                        this.reloadAfterError(status);
                        return;
                    }
                    this.applyTodo(data.todo);
                })
                .catch(() => {
                    this.error = this.i18n.assignError || 'Failed to update assignee.';
                    this.loadTodos();
                });
        },

        addThread(todo) {
            const body = (this.threadDrafts[todo.id] || '').trim();
            if (!body || this.sendingThread || !todo.can_edit) return;
            this.sendingThread = true;
            const url = this.threadStoreUrlBase.replace('__TODO_ID__', todo.id);
            this.requestJson(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.i18n.csrfToken || '' },
                body: JSON.stringify({ body }),
            })
                .then(({ ok, status, data }) => {
                    if (!ok) {
                        this.error = data.message || this.i18n.threadError || 'Failed to add comment.';
                        this.reloadAfterError(status);
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
        sourceFilter: 'all',
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
                this.openForAnnotation(e.detail.id, e.detail.origin || null);
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
            let items = this.annotations.filter(a => a.status === this.filterTab);
            if (this.sourceFilter === 'human') {
                items = items.filter(a => (a.origin || 'human') === 'human');
            }
            if (this.sourceFilter === 'ai_method') {
                items = items.filter(a => a.origin === 'ai_method');
            }
            return items;
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
            return fetch(this.indexUrl, { cache: 'no-store' })
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

        openForAnnotation(id, origin) {
            this.isOpen = true;
            localStorage.setItem('editor_sidebar_card_annotations', '1');
            this._startPolling();
            this._dispatching = true;
            window.dispatchEvent(new CustomEvent('close-other-sidebar-cards'));
            this._dispatching = false;

            if (origin === 'ai_method') {
                this.sourceFilter = 'ai_method';
            }

            this.loadAnnotations({ silent: true }).then(() => {
                const annotation = this.annotations.find(a => a.id === id);
                if (annotation) {
                    this.filterTab = annotation.status || 'open';
                    if ((annotation.origin || origin) === 'ai_method') {
                        this.sourceFilter = 'ai_method';
                    }
                }
                setTimeout(() => this.selectAnnotation(id), 50);
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
                setTimeout(() => {
                    marks.forEach(mark => mark.classList.remove('bp-annotation-highlight'));
                }, 2400);
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
        maxDialogues: 50,
        maxNoteChars: config.maxNoteChars || 3000,
        noteContent: '',
        noteEditor: null,
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
            window.addEventListener('open-explorer', (event) => {
                this.open = true;
                const detail = event.detail || {};
                const unavailable = detail.hasSavedArticle === false || detail.hasUnsavedChanges === true;
                this.phase = unavailable ? 'unavailable' : 'dialogue';
                this.dialogueCount = 0;
                this.noteContent = '';
                this.noteTooLong = false;
                this.error = '';
                this.success = '';
                if (!unavailable) {
                    this.$nextTick(() => this.setupDeepChat());
                }
            });
        },

        setupDeepChat() {
            const dc = this.$refs.deepChat;
            if (!dc) return;

            dc.style.display = 'block';
            dc.style.width = '100%';
            dc.style.height = '100%';
            dc.style.minHeight = '0';

            this.applyDeepChatTheme(dc);

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

        isDarkMode() {
            return document.documentElement.classList.contains('dark') || document.body.classList.contains('dark');
        },

        applyDeepChatTheme(dc) {
            const dark = this.isDarkMode();
            const surface = dark ? '#111827' : '#ffffff';
            const surfaceSoft = dark ? '#1f2937' : '#f9fafb';
            const border = dark ? '#374151' : '#e5e7eb';
            const text = dark ? '#f3f4f6' : '#111827';
            const muted = dark ? '#9ca3af' : '#6b7280';
            const userBubble = dark ? '#6d28d9' : '#7c3aed';
            const aiBubble = dark ? '#273244' : '#eef2f7';

            dc.chatStyle = {
                backgroundColor: surface,
                border: 'none',
                borderRadius: '0.5rem',
                height: '100%',
                width: '100%',
            };

            dc.inputAreaStyle = {
                backgroundColor: surface,
                borderTop: `1px solid ${border}`,
                position: 'sticky',
                bottom: '0',
            };

            dc.textInput = {
                styles: {
                    container: {
                        backgroundColor: surfaceSoft,
                        border: `1px solid ${border}`,
                        borderRadius: '0.75rem',
                        boxShadow: dark ? 'none' : '0 1px 8px rgba(15, 23, 42, 0.08)',
                    },
                    text: {
                        color: text,
                        backgroundColor: surfaceSoft,
                    },
                    focus: {
                        border: '1px solid #8b5cf6',
                    },
                },
                placeholder: {
                    text: this.i18n.chatPlaceholder || 'Posez votre question sur l\'article…',
                    style: { color: muted },
                },
            };

            dc.submitButtonStyles = {
                submit: {
                    container: {
                        default: { color: dark ? '#c4b5fd' : '#7c3aed' },
                        hover: { color: dark ? '#ddd6fe' : '#6d28d9' },
                    },
                },
                disabled: {
                    container: {
                        default: { color: dark ? '#4b5563' : '#d1d5db' },
                    },
                },
            };

            dc.messageStyles = {
                default: {
                    shared: {
                        bubble: {
                            borderRadius: '0.85rem',
                            lineHeight: '1.45',
                            maxWidth: '78%',
                        },
                    },
                    user: {
                        bubble: {
                            backgroundColor: userBubble,
                            color: '#ffffff',
                        },
                    },
                    ai: {
                        bubble: {
                            backgroundColor: aiBubble,
                            color: text,
                        },
                    },
                },
                intro: {
                    bubble: {
                        backgroundColor: aiBubble,
                        color: text,
                        borderRadius: '0.85rem',
                        lineHeight: '1.45',
                        maxWidth: '78%',
                    },
                },
                error: {
                    bubble: {
                        backgroundColor: dark ? '#7f1d1d' : '#fee2e2',
                        color: dark ? '#fecaca' : '#991b1b',
                    },
                },
            };

            dc.auxiliaryStyle = `
                ::-webkit-scrollbar { width: 10px; }
                ::-webkit-scrollbar-track { background: ${surface}; }
                ::-webkit-scrollbar-thumb { background: ${dark ? '#4b5563' : '#cbd5e1'}; border-radius: 999px; border: 2px solid ${surface}; }
                ::-webkit-scrollbar-thumb:hover { background: ${dark ? '#6b7280' : '#94a3b8'}; }
            `;
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
                        this.$nextTick(() => this.initNoteEditor());
                        return;
                    }
                    this.error = data.error || this.i18n.deepChatError || 'Erreur lors de la génération.';
                    this.phase = 'dialogue';
                    return;
                }

                this.noteContent = data.note || '';
                this.noteTooLong = false;
                this.phase = 'note';
                this.$nextTick(() => this.initNoteEditor());
            } catch (_) {
                this.error = this.i18n.deepChatError || 'Erreur de connexion.';
                this.phase = 'dialogue';
            } finally {
                this.generatingNote = false;
            }
        },

        initNoteEditor() {
            const el = this.$refs.noteEditor;
            if (!el || typeof createEditor === 'undefined') return;

            if (this.noteEditor) {
                this.noteEditor.destroy();
                this.noteEditor = null;
            }

            this.noteEditor = createEditor(el, {
                content: this.noteContent || '',
                placeholder: (this.i18n.notePlaceholder || '').replace(':min', '150').replace(':max', this.maxNoteChars),
                onUpdate: (html) => {
                    this.noteContent = html;
                },
            });
        },

        noteCommand(command) {
            if (!this.noteEditor) return;

            const chain = this.noteEditor.chain().focus();
            if (command === 'bold') chain.toggleBold().run();
            if (command === 'italic') chain.toggleItalic().run();
            if (command === 'bulletList') chain.toggleBulletList().run();
            if (command === 'orderedList') chain.toggleOrderedList().run();
            if (command === 'heading3') chain.toggleHeading({ level: 3 }).run();
            if (command === 'heading4') chain.toggleHeading({ level: 4 }).run();
        },

        isNoteActive(name, options = {}) {
            return this.noteEditor ? this.noteEditor.isActive(name, options) : false;
        },

        stripHtml(html) {
            const tmp = document.createElement('div');
            tmp.innerHTML = html || '';
            return tmp.textContent || tmp.innerText || '';
        },

        get noteTextLength() {
            return this.stripHtml(this.noteContent).trim().length;
        },

        backToDialogue() {
            if (this.noteEditor) {
                this.noteContent = this.noteEditor.getHTML();
                this.noteEditor.destroy();
                this.noteEditor = null;
            }
            this.phase = 'dialogue';
            this.error = '';
            this.success = '';
        },

        async saveNote() {
            if (this.noteEditor) {
                this.noteContent = this.noteEditor.getHTML();
            }

            if (this.noteTextLength < 150 || this.noteTextLength > this.maxNoteChars) {
                const message = this.noteTextLength < 150
                    ? (this.i18n.noteMinMax || 'La note doit faire au moins :min caractères.')
                    : (this.i18n.noteMax || 'La note ne peut pas dépasser :max caractères.');
                this.error = message.replace(':min', '150').replace(':max', String(this.maxNoteChars));
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

                const data = await response.json().catch(() => ({}));
                this.success = this.i18n.noteSaved || 'Note sauvegardée !';
                window.dispatchEvent(new CustomEvent('explorer-note-saved', { detail: data }));
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
            if (this.noteEditor) {
                this.noteEditor.destroy();
                this.noteEditor = null;
            }
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
        selectedNote: null,
        editingNote: false,
        noteEditor: null,
        savingNote: false,
        highlightedId: null,

        indexUrl: config.indexUrl,
        updateUrlBase: config.updateUrlBase,
        destroyUrlBase: config.destroyUrlBase,
        csrfToken: config.csrfToken || document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
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
            window.addEventListener('explorer-note-saved', (event) => {
                this.open = true;
                this.highlightedId = event.detail?.id || null;
                this.loadNotes();
                if (this.highlightedId) {
                    setTimeout(() => { this.highlightedId = null; }, 2200);
                }
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
                if (this.selectedNote?.id === noteId) this.closeNoteModal();
                this.success = this.i18n.noteDeleted || 'Note supprimée.';
                setTimeout(() => { this.success = ''; }, 2000);
            } catch (_) {
                this.error = this.i18n.deleteError || 'Erreur de suppression.';
            } finally {
                this.deletingId = null;
            }
        },

        openNote(note) {
            this.selectedNote = { ...note };
            this.editingNote = false;
            this.error = '';
            this.success = '';
        },

        closeNoteModal() {
            if (this.noteEditor) {
                this.noteEditor.destroy();
                this.noteEditor = null;
            }
            this.selectedNote = null;
            this.editingNote = false;
            this.savingNote = false;
        },

        startEditNote() {
            if (!this.selectedNote) return;
            this.editingNote = true;
            this.$nextTick(() => {
                const el = this.$refs.questionEditor;
                if (!el || typeof createEditor === 'undefined') return;
                if (this.noteEditor) this.noteEditor.destroy();
                this.noteEditor = createEditor(el, {
                    content: this.selectedNote.note_content || '',
                    placeholder: this.i18n.notePlaceholder || '',
                    onUpdate: (html) => {
                        if (this.selectedNote) this.selectedNote.note_content = html;
                    },
                });
            });
        },

        cancelEditNote() {
            if (this.noteEditor) {
                this.noteEditor.destroy();
                this.noteEditor = null;
            }
            const fresh = this.notes.find((n) => n.id === this.selectedNote?.id);
            this.selectedNote = fresh ? { ...fresh } : null;
            this.editingNote = false;
        },

        noteCommand(command) {
            if (!this.noteEditor) return;

            const chain = this.noteEditor.chain().focus();
            if (command === 'bold') chain.toggleBold().run();
            if (command === 'italic') chain.toggleItalic().run();
            if (command === 'bulletList') chain.toggleBulletList().run();
            if (command === 'orderedList') chain.toggleOrderedList().run();
            if (command === 'heading3') chain.toggleHeading({ level: 3 }).run();
            if (command === 'heading4') chain.toggleHeading({ level: 4 }).run();
        },

        isNoteActive(name, options = {}) {
            return this.noteEditor ? this.noteEditor.isActive(name, options) : false;
        },

        async saveSelectedNote() {
            if (!this.selectedNote || this.savingNote) return;
            if (this.noteEditor) {
                this.selectedNote.note_content = this.noteEditor.getHTML();
            }

            this.savingNote = true;
            this.error = '';
            try {
                const url = this.updateUrlBase.replace('__NOTE_ID__', this.selectedNote.id);
                const response = await fetch(url, {
                    method: 'PUT',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': this.csrfToken,
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify({ note_content: this.selectedNote.note_content }),
                });
                const data = await response.json().catch(() => ({}));
                if (!response.ok) throw new Error(data.message || 'Failed');

                this.selectedNote.note_content = data.note_content || this.selectedNote.note_content;
                this.notes = this.notes.map((note) => note.id === this.selectedNote.id ? { ...note, note_content: this.selectedNote.note_content } : note);
                this.highlightedId = this.selectedNote.id;
                this.cancelEditNote();
                this.success = this.i18n.noteSaved || 'Questionnement sauvegardé.';
                setTimeout(() => { this.highlightedId = null; this.success = ''; }, 2200);
            } catch (error) {
                this.error = error.message || this.i18n.noteSaveError || 'Erreur de sauvegarde.';
            } finally {
                this.savingNote = false;
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

        renderQuestioning(content) {
            const html = content || '';
            const text = this.stripHtml(html).replace(/\s+/g, ' ').trim();

            if (!text) {
                return `<p class="bp-questioning-empty">${this.escapeHtml(this.i18n.noNotes || 'Aucun questionnement pour le moment.')}</p>`;
            }

            const hasStructure = /<\s*(h3|h4|ul|ol|li|blockquote)\b/i.test(html);
            const looksFlattened = /\s+-\s+/.test(text) || /^Note Explorer\s*:?/i.test(text) || /Analyse et pistes d.amélioration/i.test(text);

            if (hasStructure && !looksFlattened) {
                return this.cleanQuestioningHtml(html);
            }

            return this.formatLegacyQuestioning(text);
        },

        cleanQuestioningHtml(html) {
            const wrapper = document.createElement('div');
            wrapper.innerHTML = html || '';
            wrapper.querySelectorAll('script, style, iframe, object, embed').forEach((node) => node.remove());

            wrapper.querySelectorAll('*').forEach((node) => {
                [...node.attributes].forEach((attribute) => {
                    if (attribute.name.startsWith('on') || attribute.name === 'style') {
                        node.removeAttribute(attribute.name);
                    }
                });
            });

            const first = wrapper.firstElementChild;
            if (first && /^h[1-4]$/i.test(first.tagName) && /^(Note Explorer|Explorer Note)\s*:?$/i.test(first.textContent.trim())) {
                first.remove();
            }

            return wrapper.innerHTML.trim() || this.formatLegacyQuestioning(this.stripHtml(html));
        },

        formatLegacyQuestioning(text) {
            const cleaned = text
                .replace(/^\s*(Note Explorer|Explorer Note)\s*:?\s*/i, '')
                .replace(/\s+/g, ' ')
                .trim();
            const sections = this.splitLegacyQuestioning(cleaned);

            if (!sections.length) {
                return `<p>${this.escapeHtml(cleaned)}</p>`;
            }

            const intro = sections.find((section) => section.type === 'intro');
            const body = sections.filter((section) => section.type !== 'intro');

            return [
                intro ? `<div class="bp-questioning-callout">${this.escapeHtml(intro.content)}</div>` : '',
                body.map((section) => `
                    <section class="bp-questioning-section">
                        <h4>${this.escapeHtml(section.title)}</h4>
                        <ul>
                            ${section.items.map((item) => `<li>${this.escapeHtml(item)}</li>`).join('')}
                        </ul>
                    </section>
                `).join(''),
            ].join('').trim();
        },

        splitLegacyQuestioning(text) {
            const labels = [
                'Analyse et pistes d’amélioration',
                'Analyse et pistes d\'amélioration',
                'Points saillants',
                'Pistes d’amélioration',
                'Pistes d\'amélioration',
                'Ouvertures',
                'Questions à creuser',
                'Pistes de réécriture',
                'Points à conserver',
                'Key insights',
                'Areas for improvement',
                'Open questions',
                'Strengths to keep',
                'Questions to explore',
                'Rewrite paths',
            ];
            const normalized = text.replace(new RegExp(`\\b(${labels.map((label) => label.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')).join('|')})\\s*(?::|-|–|—)`, 'gi'), '|||$1:');
            const chunks = normalized.split('|||').map((chunk) => chunk.trim()).filter(Boolean);

            return chunks.map((chunk, index) => {
                const match = chunk.match(/^(.{3,100}?)\s*(?::|-|–|—)\s*(.*)$/);
                if (!match) {
                    return index === 0 ? { type: 'intro', content: this.compactLegacyItem(chunk) } : null;
                }

                const title = this.normalizeLegacyTitle(match[1]);
                const rawItems = match[2]
                    .split(/\s+-\s+|\s+•\s+/)
                    .map((item) => this.compactLegacyItem(item))
                    .filter((item) => item && !this.isSeoNoise(item));

                const items = rawItems.length ? rawItems : [this.compactLegacyItem(match[2])].filter(Boolean);
                return items.length ? { type: 'section', title, items } : null;
            }).filter(Boolean);
        },

        compactLegacyItem(item) {
            return (item || '')
                .replace(/^[-•]\s*/, '')
                .replace(/\s+/g, ' ')
                .trim();
        },

        normalizeLegacyTitle(title) {
            const value = (title || '').trim();
            if (/Analyse et pistes/i.test(value)) return 'Lecture éditoriale';
            if (/SEO|référencement|keywords|mots-clés|Google/i.test(value)) return 'Pistes éditoriales';
            return value;
        },

        isSeoNoise(text) {
            return /\b(SEO|référencement|mots-clés|keywords|Google|optimisation SEO)\b/i.test(text || '');
        },

        escapeHtml(text) {
            return String(text || '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
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
    registerBlogMethodSelectionCard();
    registerAnnotationModal();
    registerBlogCoAuthorCard();
    registerBlogInviteByEmail();
    registerBlogDossierCard();
    registerDossierSemanticArticleSearch();
    registerDossierArticlesCard();
    registerDossierMembersCard();
    registerDossierFilesCard();
    registerBlogLoopCard();
    registerBlogTodoCard();
    registerBlogPlanCard();
    registerBlogExplorerModal();
    registerBlogExplorerCard();
});

registerAlpineStores();
registerBlogSnapshotCard();
registerBlogEditor();
registerBlogMethodSelectionCard();
registerAnnotationModal();
registerBlogCoAuthorCard();
registerBlogInviteByEmail();
    registerBlogDossierCard();
    registerDossierTabs();
    registerDossierContentsCard();
    registerDossierSemanticArticleSearch();
    registerDossierMembersCard();
    registerDossierFilesCard();
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
