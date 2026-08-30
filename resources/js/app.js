import './bootstrap';
import { createEditor } from './blog-editor';
import './markdown-wysiwyg-editor';
import { extractEmbedUrl } from './tiptap/media-embed-node.js';
import * as FilePond from 'filepond';
import 'filepond/dist/filepond.min.css';
import FilePondPluginFileValidateType from 'filepond-plugin-file-validate-type';
import FilePondPluginFileValidateSize from 'filepond-plugin-file-validate-size';
import Sortable from 'sortablejs';
window.FilePond = FilePond;
window.createBlogEditor = createEditor;

const FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]), textarea:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])';

function focusableNodes(container) {
    return Array.from(container.querySelectorAll(FOCUSABLE)).filter(n => n.offsetParent !== null);
}

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
                // H1 exposed since TASK-1084: Markdown pasted from an LLM
                // almost always opens with one, so the toolbar has to be able
                // to show it and to set it.
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
                    this.publishBadge();
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

        /**
         * Reflete l'etat courant dans la barre d'outils de l'article.
         *
         * Seule cette Card sait s'il y a un Dossier lie, comment il s'appelle et
         * si l'article appartient a une serie — la barre, elle, ne fait
         * qu'afficher.
         */
        publishBadge() {
            const store = window.Alpine?.store('editorPanels');
            if (!store) return;
            store.dossierName = this.currentDossier?.name || null;
            store.dossierUrl = this.currentDossier?.url || null;
            store.inSeries = !!this.currentDossier?.series;
            store.seriesIsRoot = !!this.currentDossier?.series?.is_root;
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
                    this.publishBadge();
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
            if (['contenus', 'fichiers', 'series', 'membres'].includes(hash)) {
                this.active = hash;
            }
        },

        activate(tab) {
            this.active = tab;
            window.location.hash = tab;
        },

        onHashChange() {
            const hash = window.location.hash.replace('#', '');
            if (['contenus', 'fichiers', 'series', 'membres'].includes(hash)) {
                this.active = hash;
            }
        },
    }));
}

/**
 * Classer une Serie **sans glissement**.
 *
 * Le glisser-deposer reste, mais il ne peut pas etre le seul chemin : il est
 * inaccessible au clavier et impraticable sur un ecran tactile etroit, la ou
 * ces Series seront surtout lues.
 *
 * Les deux chemins appellent la meme route et envoient la meme chose — la liste
 * complete des items dans l'ordre voulu. Il n'y a donc pas deux mecaniques de
 * reordonnancement a garder d'accord.
 *
 * En cas d'echec, l'ordre affiche **revient a ce qu'il etait**. Laisser a
 * l'ecran un ordre que le serveur a refuse, c'est mentir jusqu'au prochain
 * rechargement.
 */
function registerDossierSeriesReorder() {
    if (typeof Alpine === 'undefined') return;

    Alpine.data('dossierSeriesReorder', (config) => ({
        seriesId: config.seriesId,
        dossierId: config.dossierId,
        orgParam: config.orgParam,
        itemIds: [...(config.itemIds || [])],
        csrfToken: config.csrfToken,
        // Les libelles viennent du serveur, traduits : le composant n'en
        // fabrique aucun et ne va en chercher aucun dans un objet global.
        i18n: config.i18n || {},
        announcement: '',
        saving: false,
        sortable: null,

        init() {
            // Une zone par Serie, et **jamais** de groupe commun : un groupe
            // partage aurait laisse glisser un contenu d'une Serie a l'autre,
            // ce que le serveur refuse — un contenu n'appartient qu'a une
            // seule Serie. Mieux vaut que le geste soit impossible que
            // possible puis rejete.
            this.$nextTick(() => {
                const zone = this.$el.querySelector('[data-series-zone]');
                if (!zone) return;

                this.sortable = Sortable.create(zone, {
                    group: `dossier-series-${this.seriesId}`,
                    handle: '[data-series-handle]',
                    filter: '[data-no-drag]',
                    animation: 150,
                    onEnd: () => this.persistDomOrder(),
                });
            });
        },

        destroy() {
            this.sortable?.destroy();
            this.sortable = null;
        },

        /**
         * Apres un glissement, lire l'ordre **depuis le DOM** et l'enregistrer.
         *
         * Sortable a deja deplace la ligne ; c'est donc le DOM qui fait foi a
         * cet instant. Le chemin d'enregistrement est ensuite le meme que
         * celui des boutons — une seule mecanique a garder juste.
         */
        persistDomOrder() {
            const zone = this.$el.querySelector('[data-series-zone]');
            if (!zone) return;

            const avant = [...this.itemIds];

            this.itemIds = [...zone.querySelectorAll('li[data-item-id]')]
                .map(li => li.dataset.itemId);

            this.applyDomOrder();
            this.persist(avant, null);
        },

        async move(itemId, delta) {
            if (this.saving) return;

            const from = this.itemIds.indexOf(itemId);
            const to = from + delta;
            if (from === -1 || to < 0 || to >= this.itemIds.length) return;

            const avant = [...this.itemIds];

            const ordre = [...this.itemIds];
            ordre.splice(to, 0, ordre.splice(from, 1)[0]);
            this.itemIds = ordre;

            // Le DOM suit tout de suite : le geste doit se voir avant que le
            // reseau ait repondu, sinon on croit qu'il n'a rien fait.
            this.applyDomOrder();
            await this.persist(avant, itemId);
        },

        /**
         * Enregistrer l'ordre courant. Le seul endroit qui parle au serveur.
         *
         * En cas d'echec, l'ordre affiche **revient a ce qu'il etait**, et
         * l'echec est dit. Laisser a l'ecran un ordre que le serveur a refuse,
         * c'est mentir jusqu'au prochain rechargement ; le retirer sans un mot
         * ressemble a un bouton qui ne marche pas.
         */
        async persist(avant, itemId) {
            if (this.saving) return;
            this.saving = true;

            try {
                const url = `/org/${this.orgParam}/dossiers/${this.dossierId}/series/annexes/reorder`;
                const response = await fetch(url, {
                    method: 'PATCH',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': this.csrfToken,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({ items: this.itemIds, series_id: this.seriesId }),
                });

                if (!response.ok) throw new Error(String(response.status));

                if (itemId) this.announce(itemId);
            } catch (e) {
                this.itemIds = avant;
                this.applyDomOrder();
                this.announcement = this.i18n.reorderFailed || '';
            } finally {
                this.saving = false;
            }
        },

        /**
         * Replacer les lignes et **recalculer les numeros affiches**.
         *
         * Les numeros ne sont recopies nulle part : ils sont deduits du rang,
         * ici comme sur le serveur. Deplacer une ligne les refait donc tous,
         * sans qu'aucun titre ni nom de fichier ne soit touche.
         */
        applyDomOrder() {
            const zone = this.$el.querySelector('[data-series-zone]');
            if (!zone) return;

            const parRang = new Map();
            zone.querySelectorAll('[data-item-id]').forEach(li => parRang.set(li.dataset.itemId, li));

            this.itemIds.forEach(id => {
                const li = parRang.get(id);
                if (li) zone.appendChild(li);
            });

            zone.querySelectorAll('li').forEach((li, index) => {
                const numero = li.querySelector('[data-series-number]');
                if (numero) numero.textContent = String(index + 1).padStart(2, '0');
            });

            this.refreshBounds();
        },

        /** Le premier ne monte pas, le dernier ne descend pas. */
        refreshBounds() {
            const zone = this.$el.querySelector('[data-series-zone]');
            if (!zone) return;

            const deplacables = [...zone.querySelectorAll('li[data-item-id]')];

            deplacables.forEach((li, index) => {
                const [monter, descendre] = li.querySelectorAll('button');
                if (monter) monter.disabled = index === 0;
                if (descendre) descendre.disabled = index === deplacables.length - 1;
            });
        },

        announce(itemId) {
            const zone = this.$el.querySelector('[data-series-zone]');
            const li = zone?.querySelector(`[data-item-id="${itemId}"]`);
            if (!li || !zone) return;

            const position = [...zone.querySelectorAll('li')].indexOf(li) + 1;
            const nom = li.querySelector('a, span.block')?.textContent?.trim() || '';

            this.announcement = `${nom} — ${position}`;
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
        // Creer une serie sur un Dossier vide : le premier article attache
        // devient sa racine (voir attachArticle).
        pendingSeriesRoot: false,
        showChooseRootModal: false,
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
        _trapTrigger: null,
        _trapHandler: null,

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
                    if (this.showDetachModal) { this.showDetachModal = false; this.detachEntry = null; this.$nextTick(() => { this._destroyFocusTrap('[aria-labelledby="detach-article-title"]'); }); }
                    else if (this.showAddModal) { this.closeAddModal(); }
                    else if (this.showChooseRootModal) { this.showChooseRootModal = false; }
                    else if (this.showDeleteSeriesModal) { this.showDeleteSeriesModal = false; this.$nextTick(() => { this._destroyFocusTrap('[aria-labelledby="delete-series-title"]'); }); }
                    else { this.openMenuId = null; this.showSeriesMenu = false; }
                }
            });

            // Une seule definition des zones de glisser-deposer. Le demarrage
            // en avait une copie a lui, et la zone racine ajoutee ici n'y
            // serait jamais apparue avant un premier ajout d'annexe.
            this.$nextTick(() => this.initSortables());

            this.$watch('searchQuery', (val) => {
                this.sortables.forEach(s => s.option('disabled', val.trim().length > 0));
            });
            this.$watch('showDeleteSeriesModal', (val) => { if (!val) this.$nextTick(() => { this._destroyFocusTrap('[aria-labelledby="delete-series-title"]'); }); });
            this.$watch('showDetachModal', (val) => { if (!val) this.$nextTick(() => { this._destroyFocusTrap('[aria-labelledby="detach-article-title"]'); }); });
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
            // Deposer un article sur la racine le promeut. La zone accepte mais
            // ne cede rien (`pull: false`) et ne se trie pas : elle ne contient
            // qu'un article, et c'est justement celui qu'on remplace.
            if (this.$refs.seriesRootContainer) {
                this.sortables.push(Sortable.create(this.$refs.seriesRootContainer, {
                    group: { name: 'dossier-articles', put: true, pull: false },
                    sort: false,
                    handle: '.drag-handle',
                    // Pas de `filter` ici : il ferait ignorer la carte racine
                    // comme cible d'insertion, et le depot ne prendrait que sur
                    // les quelques pixels de marge autour d'elle. `pull: false`
                    // suffit a empecher de la sortir de la zone.
                    animation: 150,
                    onAdd: (evt) => this.onDropOnRoot(evt),
                }));
            }
        },

        /**
         * Sortable a deja deplace l'element dans le DOM ; on le remet ou il
         * etait et on laisse le serveur trancher. Sans cela, un refus laisserait
         * deux articles dans la zone racine.
         */
        onDropOnRoot(evt) {
            const movedId = evt.item.getAttribute('data-article-id');
            evt.from.insertBefore(evt.item, evt.from.children[evt.oldIndex] || null);
            if (!movedId) return;

            const entry = this.ungrouped.find(e => String(e.blog_post_id) === movedId)
                || this.seriesItems.find(e => String(e.blog_post_id) === movedId);

            if (entry) this.promoteToRoot(entry);
        },

        onDragEnd(evt) {
            const movedId = evt.item.getAttribute('data-article-id');
            if (!movedId) return;

            // Un depot sur la racine emet onAdd (zone racine) *et* onEnd (zone
            // d'origine). Sans cette sortie, l'article etait promu racine puis
            // rajoute en annexe : racine et annexe a la fois.
            if (evt.to === this.$refs.seriesRootContainer) return;

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

        get isSearchActive() {
            return this.searchQuery.trim().length > 0;
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

        _activateFocusTrap(containerSelector) {
            const el = document.querySelector(containerSelector);
            if (!el) return;
            const nodes = focusableNodes(el);
            if (nodes.length) nodes[0].focus();
            this._trapHandler = (e) => {
                if (e.key !== 'Tab') return;
                const list = focusableNodes(el);
                if (!list.length) return;
                const first = list[0], last = list[list.length - 1];
                if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
                else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
            };
            el.addEventListener('keydown', this._trapHandler);
        },

        _destroyFocusTrap(containerSelector) {
            const el = document.querySelector(containerSelector);
            if (el && this._trapHandler) el.removeEventListener('keydown', this._trapHandler);
            this._trapHandler = null;
            if (this._trapTrigger && this._trapTrigger.isConnected) this._trapTrigger.focus();
            this._trapTrigger = null;
        },

        openAddArticleModal() {
            this._trapTrigger = document.activeElement;
            this.showAddModal = true;
            this.addSearchQuery = '';
            this.addSearchResults = [];
            this.$nextTick(() => { this._activateFocusTrap('[aria-labelledby="add-article-title"]'); });
        },

        closeAddModal() {
            this.showAddModal = false;
            this.pendingSeriesRoot = false;
            this.addSearchQuery = '';
            this.addSearchResults = [];
            this.$nextTick(() => { this._destroyFocusTrap('[aria-labelledby="add-article-title"]'); });
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

                    // Demande en attente depuis createSeries() sur un Dossier
                    // vide : cet article est le premier, il en est la racine.
                    // Lu avant closeAddModal(), qui purge le drapeau.
                    const wantsRoot = this.pendingSeriesRoot && !this.hasSeries;

                    this.closeAddModal();
                    this.showSuccess(data.message);

                    if (wantsRoot) {
                        this.setAsRoot(this.ungrouped[this.ungrouped.length - 1]);
                    }
                } else {
                    this.showError(data.message || 'Error');
                }
            } catch { this.showError('Network error'); }
            finally { this.adding = false; }
        },

        /**
         * Creer une serie, depuis un bouton plutot que depuis le menu cache
         * d'un article.
         *
         * Une serie ne peut pas exister sans racine — `article_series.
         * root_blog_post_id` est NOT NULL — donc creer une serie, c'est
         * toujours designer un article. Le bouton evite la question quand elle
         * n'a qu'une reponse possible : Dossier vide, on demande d'abord un
         * article ; un seul article, c'est lui ; plusieurs, on choisit.
         */
        createSeries() {
            if (this.hasSeries || this.saving) return;

            if (this.ungrouped.length === 0) {
                this.pendingSeriesRoot = true;
                this.openAddArticleModal();
                return;
            }

            if (this.ungrouped.length === 1) {
                this.setAsRoot(this.ungrouped[0]);
                return;
            }

            this.showChooseRootModal = true;
        },

        chooseRoot(entry) {
            this.showChooseRootModal = false;
            this.setAsRoot(entry);
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
                    // La zone racine vient d'apparaitre : sans cela elle
                    // n'accepterait aucun depot avant un rechargement.
                    this.$nextTick(() => this.initSortables());
                })
                .catch(() => this.showError('Error'))
                .finally(() => { this.saving = false; });
        },

        /**
         * Promote an article of the series to root.
         *
         * The former root is not dropped — the server moves it to the first
         * annexe slot — so nothing a human placed in the series is ever lost.
         * Available on every article of the series, which is where people look
         * for it; the old "change root" entry in the series menu did nothing at
         * all and has been removed.
         */
        promoteToRoot(entry) {
            if (!entry || this.saving) return;
            this.saving = true;
            const url = `/org/${this.orgParam}/dossiers/${this.dossierId}/series`;
            fetch(url, {
                method: 'PATCH',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': this.csrfToken, 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify({ root_blog_post_id: entry.blog_post_id }),
            })
                .then(r => r.json().then(d => ({ ok: r.ok, data: d })))
                .then(({ ok, data }) => {
                    if (!ok) {
                        this.showError(data.message || data.root_blog_post_id?.[0] || 'Error');
                        return;
                    }
                    this.showSuccess(data.message || this.i18n.seriesRootUpdated);
                    this.openMenuId = null;
                    // Appliquer l'etat que le serveur vient de renvoyer, plutot
                    // que recharger toute la page.
                    this.applySeriesState(data.series);
                })
                .catch(() => this.showError('Error'))
                .finally(() => { this.saving = false; });
        },

        /**
         * Appliquer l'etat canonique renvoye par le serveur.
         *
         * Le serveur dit **quoi** : quel article est racine, et dans quel ordre
         * viennent les annexes. Le client ne fournit que le **comment** —
         * titres, URLs, droits — a partir des objets qu'il detient deja. Il ne
         * reconstruit donc rien et ne devine rien, ce qui etait la seule
         * objection valable au rechargement de page qu'on remplace ici.
         *
         * Les champs enrichis (viewUrl, editUrl, canView, canEdit) sont
         * construits par la vue Blade et absents de la reponse JSON : les
         * reprendre depuis l'etat courant est ce qui evite d'afficher des lignes
         * privees de leurs actions.
         */
        applySeriesState(series) {
            if (!series) return;

            const connus = new Map();
            const retenir = (bp) => { if (bp && bp.blogPostId) connus.set(String(bp.blogPostId), bp); };
            retenir(this.seriesRoot);
            this.seriesItems.forEach(e => retenir(e.blog_post));
            this.ungrouped.forEach(e => retenir(e.blog_post));

            const enrichi = (id, brut) => connus.get(String(id)) || normalizeArticle(brut);

            const racineId = series.root_blog_post_id || (series.root_blog_post && series.root_blog_post.id) || null;

            this.seriesRootBlogPostId = racineId;
            this.seriesRoot = racineId ? enrichi(racineId, series.root_blog_post) : null;

            this.seriesItems.splice(0, this.seriesItems.length, ...(series.items || []).map(item => ({
                id: item.id,
                blog_post_id: item.blog_post_id,
                position: item.position,
                blog_post: enrichi(item.blog_post_id, item.blog_post),
            })));

            // Un article promu depuis les non-classes doit les quitter : c'est
            // le cas que le rechargement masquait.
            const dansLaSerie = new Set([
                ...(racineId ? [String(racineId)] : []),
                ...this.seriesItems.map(e => String(e.blog_post_id)),
            ]);
            const restants = this.ungrouped.filter(e => !dansLaSerie.has(String(e.blog_post_id)));
            this.ungrouped.splice(0, this.ungrouped.length, ...restants);

            // Les zones de glissement suivent le nouveau contenu.
            this.$nextTick(() => this.initSortables());
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
            this._trapTrigger = document.activeElement;
            this.showDeleteSeriesModal = true;
            this.$nextTick(() => { this._activateFocusTrap('[aria-labelledby="delete-series-title"]'); });
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
            this._trapTrigger = document.activeElement;
            this.detachEntry = entry;
            this.showDetachModal = true;
            this.openMenuId = null;
            this.$nextTick(() => { this._activateFocusTrap('[aria-labelledby="detach-article-title"]'); });
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
        errorCode: '',
        offersUrl: '',
        validationError: '',
        endpoint: config.endpoint,
        i18n: config.i18n || {},

        async search() {
            if (this.loading) return;

            const trimmedQuery = this.query.trim();
            this.error = '';
            this.errorCode = '';
            this.offersUrl = '';
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

                // TASK-1229 : refus economique avant appel, avec son code
                // (credit utilisateur epuise / budget Organization atteint).
                if (response.status === 429) {
                    let payload = null;
                    try { payload = await response.json(); } catch (e) { payload = null; }
                    this.error = (payload && payload.message) || this.i18n.unavailable;
                    this.errorCode = (payload && payload.code) || '';
                    this.offersUrl = (payload && payload.offers_url) || '';
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

        // TASK-1273 : l'en-tete compte les DOCUMENTS (lignes affichees, cf.
        // groupedResults()) ET les PASSAGES (contrat serveur, `results`) :
        // « 1 document · 5 passages ». Chaque cote a son propre pluriel, par
        // gabarit singulier / pluriel transmis dans config.i18n (meme mecanique
        // que otherPassagesLabel). Le groupement lui-meme ne change pas.
        countLabel(count, one, many) {
            return String((count === 1 ? one : many) || '').replace(':count', count);
        },

        resultCountLabel() {
            const documents = this.countLabel(this.groupedResults().length, this.i18n.documentsOne, this.i18n.documentsMany);
            const passages = this.countLabel(this.results.length, this.i18n.passagesOne, this.i18n.passagesMany);

            return String(this.i18n.resultsCount || '')
                .replace(':documents', () => documents)
                .replace(':passages', () => passages);
        },

        // TASK-1267 : un resultat est un article OU un fichier (source_type,
        // cf. DossierSemanticSearchService::mapSourceRow). Cote fichier,
        // slug/title sont nuls : la cle DOM, le titre et le libelle du lien
        // doivent suivre la source, sinon deux fichiers collisionnent sur
        // `null-0` et s'affichent sans titre avec « Lire l'article ».
        isFileResult(result) {
            return result && result.source_type === 'file';
        },

        // TASK-1271 : identite du DOCUMENT (article ou fichier), sans le
        // chunk. C'est la cle DOM de la liste groupee : une ligne par document.
        documentKey(result) {
            const sourceType = result.source_type || (result.blog_post_id ? 'article' : 'file');
            const sourceId = sourceType === 'file' ? result.dossier_file_id : result.blog_post_id;

            return `${sourceType}:${sourceId ?? ''}`;
        },

        resultKey(result) {
            return `${this.documentKey(result)}:${result.chunk_index}`;
        },

        // TASK-1271 : le serveur rend un top 5 de PASSAGES (contrat JSON
        // `data` inchange, teste par T1267 et consomme ailleurs). La liste
        // affichee presente chaque DOCUMENT une seule fois, represente par son
        // meilleur passage (plus petite `distance`), dans l'ordre de ce meilleur
        // passage. Les objets rendus sont les objets serveur eux-memes (meme
        // reference) : citation_url, mime_type, apercu restent ceux du passage.
        // Sans `distance` exploitable, l'ordre serveur fait foi.
        resultDistance(result) {
            const raw = result ? result.distance : null;
            if (typeof raw !== 'number' && !(typeof raw === 'string' && raw.trim() !== '')) return null;
            const distance = Number(raw);

            return Number.isFinite(distance) ? distance : null;
        },

        groupedResults() {
            const groups = new Map();

            this.results.forEach((result, rank) => {
                const key = this.documentKey(result);
                const group = groups.get(key);
                const distance = this.resultDistance(result);

                if (!group) {
                    groups.set(key, { result, distance, rank });
                    return;
                }

                if (distance !== null && (group.distance === null || distance < group.distance)) {
                    group.result = result;
                    group.distance = distance;
                    group.rank = rank;
                }
            });

            return Array.from(groups.values())
                .sort((a, b) => {
                    if (a.distance !== null && b.distance !== null && a.distance !== b.distance) {
                        return a.distance - b.distance;
                    }
                    if (a.distance === null && b.distance !== null) return 1;
                    if (a.distance !== null && b.distance === null) return -1;

                    return a.rank - b.rank;
                })
                .map((group) => group.result);
        },

        otherPassagesCount(result) {
            const key = this.documentKey(result);

            return Math.max(0, this.results.filter((candidate) => this.documentKey(candidate) === key).length - 1);
        },

        otherPassagesLabel(result) {
            const count = this.otherPassagesCount(result);
            if (count < 1) return '';

            const template = count === 1 ? this.i18n.otherPassagesOne : this.i18n.otherPassagesMany;

            return String(template || '').replace(':count', count);
        },

        resultTitle(result) {
            return String((this.isFileResult(result) ? result.filename : result.title) || '');
        },

        resultLinkLabel(result) {
            return this.isFileResult(result) ? this.i18n.openDocument : this.i18n.readArticle;
        },

        // TASK-1267 : meme regle d'apercu que `ouvrirFichier()` de
        // `dossierFilesCard` (image, PDF, texte, markdown). Pour ces fichiers
        // la modale d'apercu EXISTANTE s'ouvre via `openPreview()` du
        // composant parent, atteint par la chaine de portee Alpine (la
        // section de recherche vit dans `dossierFilesCard`). Aucun viewer
        // nouveau ; le telechargement reste `citation_url` (route files.show).
        canPreviewResult(result) {
            if (!this.isFileResult(result)) return false;
            const mime = result.mime_type || '';

            return mime.startsWith('image/')
                || mime === 'application/pdf'
                || mime === 'text/plain'
                || mime === 'text/markdown';
        },

        openResultPreview(result) {
            if (!this.canPreviewResult(result)) return false;

            // Repli : si la portee parente n'expose pas la modale, la route
            // fichier (telechargement) reste le chemin vers le document.
            if (typeof this.openPreview !== 'function') {
                window.location = result.citation_url;
                return false;
            }

            this.openPreview({
                id: result.dossier_file_id,
                mime_type: result.mime_type,
                display_name: result.filename,
            });

            return true;
        },
    }));
}

// TASK-1341 — Smart Dossier V1. Alpine MINIMAL : un POST explicite, un
// fragment HTML rendu SERVEUR insere par x-html. Aucun rendu metier ici.
function registerDossierInsights() {
    if (typeof Alpine === 'undefined') return;

    Alpine.data('dossierInsights', (config) => ({
        loading: false,
        generated: false,
        resultHtml: '',
        error: '',
        errorCode: '',
        offersUrl: '',
        hasIndexedContent: !!config.hasIndexedContent,
        endpoint: config.endpoint,
        i18n: config.i18n || {},

        async generate() {
            if (this.loading || !this.hasIndexedContent) return;

            this.loading = true;
            this.error = '';
            this.errorCode = '';
            this.offersUrl = '';

            try {
                const response = await fetch(this.endpoint, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                    },
                });

                if (response.ok) {
                    const data = await response.json();
                    this.resultHtml = data.html || '';
                    this.generated = true;
                    return;
                }

                if (response.status === 422) {
                    this.error = this.i18n.noContent;
                    return;
                }

                // TASK-1229 : refus economique avant tout appel (credit
                // utilisateur epuise / budget Organization atteint).
                if (response.status === 429) {
                    let payload = null;
                    try { payload = await response.json(); } catch (e) { payload = null; }
                    this.error = (payload && payload.message) || this.i18n.unavailable;
                    this.errorCode = (payload && payload.code) || '';
                    this.offersUrl = (payload && payload.offers_url) || '';
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
    }));
}

function registerDossierMembersCard() {
    if (typeof Alpine === 'undefined') return;

    Alpine.data('dossierMembersCard', (config) => ({
        members: [],
        inheritedMembers: [],
        searchQuery: '',
        searchResults: [],
        searchLoading: false,
        addingMemberId: null,
        updatingMemberId: null,
        removing: false,
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
                if (ev.key === 'Escape' && this.removeTarget) this.removeTarget = null;
            });
        },

        mapMember(member) {
            return {
                ...member,
                isYou: String(member.id) === String(this.currentUserId),
                displayName: `${member.first_name || ''} ${(member.name || '').toUpperCase()}`.trim(),
                initial: (member.first_name || member.name || '?').charAt(0).toUpperCase(),
                roleLabel: member.role === 'reader' ? (this.i18n.roleReader || 'Reader') : (member.role === 'editor' ? (this.i18n.roleEditor || 'Editor') : member.role),
            };
        },

        get allMembers() {
            const unique = new Map();
            [...this.members, ...this.inheritedMembers].forEach(member => {
                if (member.id && !unique.has(String(member.id))) unique.set(String(member.id), member);
            });
            return [...unique.values()];
        },

        get displayMembers() {
            return this.allMembers.slice(0, 5);
        },

        get overflowCount() {
            return Math.max(0, this.allMembers.length - 5);
        },

        get currentRoleLabel() {
            if (String(this.currentUserId) === String(this.ownerId)) {
                return this.i18n.ownerBadge || 'Owner';
            }
            const m = this.allMembers.find(m => String(m.id) === String(this.currentUserId));
            return m?.roleLabel || '';
        },

        loadMembers() {
            const url = `/org/${this.orgParam}/dossiers/${this.dossierId}/members`;
            fetch(url, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
                .then(r => r.json())
                .then(data => {
                    this.members = (data.members || []).map(m => this.mapMember(m));
                    this.inheritedMembers = (data.inherited_members || []).map(m => this.mapMember(m));
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
                        }));
                })
                .catch(() => {})
                .finally(() => { this.searchLoading = false; });
        },

        addMember(user) {
            this.addingMemberId = user.id;
            const url = `/org/${this.orgParam}/dossiers/${this.dossierId}/members`;
            fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': this.csrfToken,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({ user_id: user.id }),
            })
                .then(r => r.json().then(d => ({ ok: r.ok, data: d })))
                .then(({ ok, data }) => {
                    if (!ok) {
                        this.showMessage(data.message || this.i18n.memberAlready, 'error');
                        return;
                    }
                    this.members.push(this.mapMember(data.member));
                    this.inheritedMembers = this.inheritedMembers.filter(member => String(member.id) !== String(data.member.id));
                    this.searchQuery = '';
                    this.searchResults = [];
                    this.showMessage(data.message || this.i18n.memberAdded, 'success');
                })
                .catch(() => { this.showMessage(this.i18n.memberAlready, 'error'); })
                .finally(() => { this.addingMemberId = null; });
        },

        updateRole(member, newRole) {
            const previousRole = member.role;
            this.updatingMemberId = member.id;
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
                        member.role = previousRole;
                        this.showMessage(data.message || this.i18n.memberRoleUpdated, 'error');
                        return;
                    }
                    member.role = newRole;
                    member.roleLabel = newRole === 'reader' ? (this.i18n.roleReader || 'Reader') : (this.i18n.roleEditor || 'Editor');
                    this.showMessage(data.message || this.i18n.memberRoleUpdated, 'success');
                })
                .catch(() => { member.role = previousRole; })
                .finally(() => { this.updatingMemberId = null; });
        },

        confirmRemove() {
            if (!this.removeTarget) return;
            const member = this.removeTarget;
            this.removing = true;
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
                    this.removeTarget = null;
                    this.showMessage(data.message || this.i18n.memberRemoved, 'success');
                })
                .catch(() => {})
                .finally(() => { this.removing = false; });
        },

        removeMember(member) {
            this.removeTarget = member;
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
        // TASK-1130 : la surface a deux angles — Documents (la liste) et
        // Series (l'editorial). Un etat d'affichage, pas un moteur.
        vue: 'documents',
        // Compteur du survol de depot : sur LE MEME composant que le reste,
        // jamais dans un x-data imbrique — un scope enfant rendrait
        // filePondContainer invisible a $refs du composant parent (bug trouve
        // en recette, TASK-1130 passe 4).
        survol: 0,
        // Les fichiers arrivent par fetch APRES les dossiers/Articles rendus
        // cote serveur : sans cet etat, l'ecran affirmait « pas de fichier »
        // pendant 2-3 s — un fichier deplace semblait avoir disparu (constate
        // en audit, TASK-1130 UX finale). Le squelette remplace ce mensonge.
        filesLoading: true,
        // Les noms de la surface spatiale (dossiers + Articles), rendus par le
        // serveur : ils disent si une recherche ne trouve VRAIMENT rien, la
        // ou `totalFiles` ne parle que des fichiers.
        nomsSpatiaux: config.nomsSpatiaux || [],
        // Le nom du Dossier : celui que prend sa sequence, sans le demander.
        dossierName: config.dossierName || '',
        // ── Selection (TASK-1130, doctrine Cyril des 13 et 14/08) ───────
        // Un Drive se manipule en deux temps : on designe, puis on agit. Et la
        // designation est PLURIELLE : deplacer six fichiers d'un coup est le
        // geste utile ; un par un ne l'est pas.
        //
        // Les cles « type:id » sont la source de verite, dans l'ordre des
        // clics. Le catalogue, lui, garde le contenu de chaque ligne : les
        // dossiers et les Articles sont rendus par Blade et n'existent nulle
        // part ailleurs dans l'etat JS, et une plage Maj+clic prend des lignes
        // sur lesquelles personne n'a clique.
        selectionKeys: [],
        _catalogue: {},
        // L'ancre de la plage Maj+clic : le dernier element designe seul.
        _ancre: null,
        // Vrai sur un ecran tactile : le tap y ouvre et l'appui long
        // selectionne, comme dans les applications Drive, Files et Photos. Le
        // double-tap, lui, appartient au zoom du systeme.
        tactile: false,
        _appuiTimer: null,
        _appuiLong: false,
        // La file d'envoi : un fichier a la fois, dans l'ordre choisi.
        _fileQueue: [],
        uploadEnCours: false,
        uploadFait: 0,
        uploadTotal: 0,
        // Ce que le navigateur a refuse avant meme d'essayer (poids, format) :
        // il faut le dire, et le dire calmement.
        showUploadRejectModal: false,
        uploadRejects: [],
        // Renommer un fichier : son libelle, pas son fichier sur le disque.
        showRenameModal: false,
        renameTarget: null,
        renameValue: '',
        // « Retirer de cette Boucle » (CAS B) : une confirmation legere avant
        // le PATCH — retirer un partage n'est pas supprimer, le ton du modal
        // n'est pas celui d'une destruction.
        showUnshareFolderModal: false,
        unshareFolderTarget: null,
        // ── Mode Serie (TASK-1130, addendum) ────────────────────────────
        // Une Serie = organisation SEQUENTIELLE : elle ajoute une position et
        // une numerotation calculee, elle ne deplace, ne renomme et ne
        // duplique jamais rien. Meme URL, meme surface : `vue` passe a
        // 'serie' et la projection ordonnee remplace la liste spatiale.
        // Moteur : les routes /series existantes (store, annexes, reorder,
        // destroy), toutes multi-Series via `series_id` — rien de recree.
        seriesMode: config.seriesMode || [],
        serieArticles: config.serieArticles || [],
        canManageSeries: config.canManageSeries || false,
        serieActive: null,
        showSerieSelect: false,
        showCreateSerieModal: false,
        // « + Nouveau » -> « Ajouter un article existant » : le rattachement
        // au DOSSIER (pas a une Serie) — l'ancien modal de la carte Contenus,
        // rehoberge dans le flux de creation. Moteur inchange (articles.store
        // + articles/search).
        showAttachArticleModal: false,
        attachSearchQuery: '',
        attachSearchResults: [],
        attachSearching: false,
        attachSaving: false,
        newSerieName: '',
        showSerieAddModal: false,
        showSerieDeleteModal: false,
        serieSaving: false,
        serieDragArmedId: null,
        serieDragItemId: null,
        serieDragOverId: null,
        serieAnnouncement: '',
        files: [],
        quota: { used_bytes: 0, limit_bytes: null, remaining_bytes: null },
        uploading: false,
        uploadProgress: 0,
        uploadFileName: '',
        uploadBatchCurrent: 0,
        uploadBatchTotal: 0,
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
        // Supprimer un Dossier (TASK-1130 passe 4, CAS A/B) : les lignes de
        // dossier sont rendues cote serveur (pas reactives comme `files`),
        // donc un succes recharge la page plutot que de retirer la ligne a
        // la main — geste rare et deja confirme, la recharge reste honnete.
        showDeleteFolderModal: false,
        deleteFolderTarget: null,
        deletingFolder: false,
        // Deplacer un fichier (TASK-1130 passe 4) : `moveTargets` vient du
        // serveur (sous-dossiers + parent reel, deja filtres au droit
        // manageFiles) — jamais recalcule cote client, qui n'a pas les
        // permissions des AUTRES dossiers sous la main.
        moveTargets: config.moveTargets || [],
        showMoveModal: false,
        moveTarget: null,
        moveLot: [],
        // Les cles en cours de glissement : toute la selection, pas la seule
        // ligne saisie.
        draggingKeys: [],
        dragOverFolderId: null,
        // Le lot en attente de confirmation de suppression : le meme modal
        // sert l'element seul et le lot, avec un texte qui compte.
        deleteLot: [],
        // Le rapport d'un lot partiellement passe — jamais un « fait » qui
        // recouvrirait un refus.
        showLotModal: false,
        lotRapport: null,
        showPreviewModal: false,
        previewFile: null,
        showImportMenu: false,
        sortBy: 'date',
        sortDirection: 'desc',
        searchQuery: '',
        viewMode: 'list',
        showArticleModal: false,
        articleTitle: '',
        articleCategoryId: '',
        showMdModal: false,
        // Le fichier en cours de modification. Nul = on cree une note ; non nul
        // = on reecrit celle-la, dans la meme ligne `dossier_files`.
        mdTarget: null,
        mdLoading: false,
        mdFileName: '',
        mdContent: '',
        _trapTrigger: null,
        _trapHandler: null,

        _activateFocusTrap(containerSelector) {
            const el = document.querySelector(containerSelector);
            if (!el) return;
            const nodes = focusableNodes(el);
            if (nodes.length) nodes[0].focus();
            this._trapHandler = (e) => {
                if (e.key !== 'Tab') return;
                const list = focusableNodes(el);
                if (!list.length) return;
                const first = list[0], last = list[list.length - 1];
                if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
                else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
            };
            el.addEventListener('keydown', this._trapHandler);
        },

        _destroyFocusTrap(containerSelector) {
            const el = document.querySelector(containerSelector);
            if (el && this._trapHandler) el.removeEventListener('keydown', this._trapHandler);
            this._trapHandler = null;
            if (this._trapTrigger && this._trapTrigger.isConnected) this._trapTrigger.focus();
            this._trapTrigger = null;
        },

        openArticleModal() {
            this._trapTrigger = this.$refs.fabButton || document.activeElement;
            this.showArticleModal = true;
            this.articleTitle = '';
            this.articleCategoryId = '';
            this.$nextTick(() => { this._activateFocusTrap('[aria-labelledby="create-article-title"]'); });
        },

        openMdModal() {
            this._trapTrigger = this.$refs.fabButton || document.activeElement;
            this.showMdModal = true;
            this.mdFileName = '';
            this.mdContent = '';
            this.$nextTick(() => { this._activateFocusTrap('[aria-labelledby="markdown-note-title"]'); });
        },

        uploadFormData(formData, files = [], skipReset = false) {
            const names = Array.from(files).map(file => file.name).filter(Boolean);
            this.uploading = true;
            this.uploadProgress = 0;
            this.uploadFileName = names.length === 1 ? names[0] : names.join(', ');

            return new Promise((resolve, reject) => {
                const xhr = new XMLHttpRequest();
                xhr.open('POST', `/org/${this.orgParam}/dossiers/${this.dossierId}/files`);
                xhr.setRequestHeader('Accept', 'application/json');
                xhr.setRequestHeader('X-CSRF-TOKEN', this.csrfToken);
                xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

                xhr.upload.addEventListener('progress', (event) => {
                    if (!event.lengthComputable) return;
                    this.uploadProgress = Math.min(99, Math.round((event.loaded / event.total) * 100));
                });

                xhr.addEventListener('load', () => {
                    this.uploadProgress = 100;
                    let data = {};
                    try { data = JSON.parse(xhr.responseText || '{}'); } catch { data = {}; }
                    resolve({ ok: xhr.status >= 200 && xhr.status < 300, data });
                });

                xhr.addEventListener('error', () => reject(new Error('upload failed')));
                xhr.addEventListener('abort', () => reject(new Error('upload aborted')));
                xhr.send(formData);
            }).finally(() => {
                if (skipReset) return;
                setTimeout(() => {
                    this.uploading = false;
                    this.uploadProgress = 0;
                    this.uploadFileName = '';
                }, 500);
            });
        },

        mergeMissingUploads(uploadedFiles) {
            if (!uploadedFiles || !uploadedFiles.length) return;
            const newIds = uploadedFiles.map(f => f.id);
            const missingIds = newIds.filter(id => !this.files.some(existing => existing.id === id));
            if (missingIds.length === 0) return;
            const missingFiles = uploadedFiles
                .filter(f => missingIds.includes(f.id))
                .map(f => this.normalizeFile(f));
            this.files = [...missingFiles, ...this.files];
            // `totalFiles` vient du serveur et ne bougeait qu'a la suppression :
            // apres un import dans un dossier VIDE il restait a 0, et la liste
            // entiere — dont le fichier tout juste depose — restait masquee
            // jusqu'au rechargement. Le compte suit desormais ce qu'on voit.
            this.totalFiles = Math.max(this.totalFiles, this.files.length);
        },

        async createArticle() {
            if (!this.articleTitle.trim()) return;
            
            this.saving = true;
            try {
                const response = await fetch(`/org/${this.orgParam}/dossiers/${this.dossierId}/articles/create-and-attach`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': this.csrfToken,
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify({
                        title: this.articleTitle,
                        category_id: this.articleCategoryId || null,
                    }),
                });
                
                const data = await response.json();
                
                if (response.ok) {
                    this.showArticleModal = false;
                    this.showMessage(this.i18n.articleCreated, 'success');
                    // Redirect to edit the new article
                    // Le repli fabriquait la meme URL fausse que le serveur :
                    // sans `redirect_url`, on reste sur le Drive plutot que
                    // d'envoyer la personne sur un 404.
                    if (data.redirect_url) window.location.href = data.redirect_url;
                } else {
                    this.showMessage(data.message || this.i18n.articleCreateFailed, 'error');
                }
            } catch (error) {
                this.showMessage(this.i18n.networkError, 'error');
            } finally {
                this.saving = false;
            }
        },

        /** Ce fichier est-il une note Markdown modifiable ? */
        estMarkdown(file) {
            if (!file) return false;
            const nom = (file.original_name || file.display_name || '').toLowerCase();

            return file.mime_type === 'text/markdown' || nom.endsWith('.md') || nom.endsWith('.markdown');
        },

        /**
         * Rouvrir une note dans l'editeur qui a servi a l'ecrire.
         *
         * Le contenu vient du serveur, pas d'un cache local : une note peut
         * avoir ete modifiee ailleurs depuis l'affichage de la liste.
         */
        async openMarkdownEdit(file) {
            if (!this.estMarkdown(file)) return;

            this.mdTarget = file;
            this.mdFileName = (file.display_name || file.original_name || '').replace(/\.(md|markdown)$/i, '');
            this.mdContent = '';
            this.mdLoading = true;
            this.showMdModal = true;

            try {
                const reponse = await fetch(`/org/${this.orgParam}/dossiers/${this.dossierId}/files/${file.id}/markdown`, {
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                });
                const data = await reponse.json().catch(() => ({}));

                if (!reponse.ok) {
                    this.showMessage(data.message || this.i18n.markdownUpdateFailed, 'error');
                    this.closeMdModal();

                    return;
                }

                this.mdContent = data.content || '';
                // L'editeur s'initialise UNE fois par conteneur : redemander
                // `init` apres coup ne fait rien, et la modale s'ouvrait donc
                // vide — enregistrer sans retaper aurait efface la note.
                // `set-content` est l'API prevue pour cela ; on la laisse
                // precedee d'`init`, la modale n'existant pas au chargement.
                this.$nextTick(() => {
                    document.dispatchEvent(new CustomEvent('bp:markdown-editor:init'));
                    document.dispatchEvent(new CustomEvent('bp:markdown-editor:set-content', {
                        detail: { name: 'dossier-md-content', markdown: this.mdContent },
                    }));
                });
            } catch (error) {
                this.showMessage(this.i18n.networkError, 'error');
                this.closeMdModal();
            } finally {
                this.mdLoading = false;
            }
        },

        closeMdModal() {
            this.showMdModal = false;
            this.mdTarget = null;
            this.mdContent = '';
        },

        /** Enregistrer une note : creation ou reecriture, selon `mdTarget`. */
        async enregistrerMarkdown() {
            if (this.mdTarget) { await this.updateMarkdownNote(); return; }

            await this.createMarkdownNote();
        },

        /**
         * Reecrire le MEME fichier. Aucun nouveau `DossierFile` : le serveur
         * met a jour la ligne, son chemin, sa taille et son empreinte.
         */
        async updateMarkdownNote() {
            const file = this.mdTarget;
            if (!file) return;

            this.saving = true;
            try {
                const champ = document.querySelector('textarea[name="dossier-md-content"][data-tiptap-target]');
                const contenu = champ ? champ.value : this.mdContent;

                const reponse = await fetch(`/org/${this.orgParam}/dossiers/${this.dossierId}/files/${file.id}/markdown`, {
                    method: 'PATCH',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': this.csrfToken,
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify({ content: contenu }),
                });
                const data = await reponse.json().catch(() => ({}));

                if (reponse.ok) {
                    // La ligne existe deja : on la remplace sur place plutot
                    // que de recharger, pour ne pas faire clignoter la liste.
                    if (data.file) {
                        this.files = this.files.map(f => f.id === file.id ? this.normalizeFile(data.file) : f);
                    }
                    this.closeMdModal();
                    this.showMessage(data.message || this.i18n.markdownUpdated, 'success');
                } else {
                    this.showMessage(data.message || this.i18n.markdownUpdateFailed, 'error');
                }
            } catch (error) {
                this.showMessage(this.i18n.networkError, 'error');
            } finally {
                this.saving = false;
            }
        },

        async createMarkdownNote() {
            if (!this.mdFileName.trim()) return;

            this.saving = true;
            try {
                const fileName = this.mdFileName.endsWith('.md') ? this.mdFileName : `${this.mdFileName}.md`;
                // L'editeur ecrit dans son textarea : c'est lui qui porte le
                // Markdown reel (titres, listes, liens), pas `mdContent`.
                const champ = document.querySelector('textarea[name="dossier-md-content"][data-tiptap-target]');
                const contenu = champ ? champ.value : this.mdContent;
                const blob = new Blob([contenu], { type: 'text/markdown' });
                const file = new File([blob], fileName, { type: 'text/markdown' });
                
                const formData = new FormData();
                formData.append('files[]', file);
                
                const { ok, data } = await this.uploadFormData(formData, [file]);

                if (ok) {
                    this.closeMdModal();
                    await this.loadFiles(1);
                    this.mergeMissingUploads(data.files);
                    this.showMessage(this.i18n.markdownCreated, 'success');
                } else {
                    this.showMessage(data.message || this.i18n.markdownCreateFailed, 'error');
                }
            } catch (error) {
                this.showMessage(this.i18n.networkError, 'error');
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
            const files = Array.from(event.target.files);
            event.target.value = '';
            if (files.length === 0) return;

            const validFiles = files.filter(f => {
                if (f.size > 50 * 1024 * 1024) {
                    this.showMessage(this.i18n.fileTooLarge.replace(':name', f.name), 'error');
                    return false;
                }
                return true;
            });
            if (validFiles.length === 0) return;

            this.saving = true;
            this.uploading = true;
            this.uploadBatchCurrent = 0;
            this.uploadBatchTotal = validFiles.length;

            let succeeded = 0;
            let failed = 0;
            const failedNames = [];

            for (let i = 0; i < validFiles.length; i++) {
                const file = validFiles[i];
                this.uploadBatchCurrent = i + 1;
                this.uploadFileName = file.name;
                this.uploadProgress = 0;

                const formData = new FormData();
                formData.append('files[]', file, file.name);

                try {
                    const { ok, data } = await this.uploadFormData(formData, [file], true);
                    if (ok) {
                        succeeded++;
                        this.mergeMissingUploads(data.files);
                    } else {
                        failed++;
                        failedNames.push(file.name + ': ' + (data.message || this.i18n.uploadFailed));
                    }
                } catch (_e) {
                    failed++;
                    failedNames.push(file.name + ': ' + this.i18n.networkError);
                }
            }

            this.uploading = false;
            this.uploadProgress = 0;
            this.uploadFileName = '';
            this.uploadBatchCurrent = 0;

            if (succeeded > 0 && failed === 0) {
                this.showMessage(this.i18n.filesBatchResult
                    .replace(':success', succeeded)
                    .replace(':total', validFiles.length)
                    .replace(':errors', ''), 'success');
            } else if (succeeded > 0 && failed > 0) {
                const errorSuffix = this.i18n.filesBatchErrors.replace(':count', failed);
                const msg = this.i18n.filesBatchResult
                    .replace(':success', succeeded)
                    .replace(':total', validFiles.length)
                    .replace(':errors', errorSuffix);
                this.showMessage(msg + '\n' + failedNames.join('\n'), 'error');
            } else {
                this.showMessage(this.i18n.uploadFailed + '\n' + failedNames.join('\n'), 'error');
            }

            await this.loadFiles(1);
            this.saving = false;
        },

        get sortedFiles() {
            return this.files;
        },

        toggleSort(column) {
            if (this.sortBy === column) {
                this.sortDirection = this.sortDirection === 'asc' ? 'desc' : 'asc';
            } else {
                this.sortBy = column;
                this.sortDirection = 'asc';
            }
            this.loadFiles(1);
        },

        browseFiles() {
            if (this._pond) {
                this._pond.browse();
            }
        },

        init() {
            // Preference d'affichage (TASK-1130 passe 4) : localStorage
            // uniquement, jamais une colonne pour ce seul detail — la meme
            // cle sert le Dossier prive et le Dossier de Boucle, un seul
            // reglage pour tout le Drive.
            try {
                const stored = window.localStorage.getItem('bp-dossier-view-mode');
                if (stored === 'list' || stored === 'grid') this.viewMode = stored;
            } catch (e) { /* localStorage indisponible (navigation privee, quota) : reste sur 'list' */ }

            this.loadFiles();
            // TASK-1231 : le FAB « BouclePro IA » demande « Rechercher dans ce
            // Dossier » — on revient a la vue Documents (le bloc de recherche
            // n'existe que la) et on donne le focus au champ EXISTANT. Aucune
            // recherche n'est lancee ici : l'appel reste celui du bloc.
            window.addEventListener('bp-open-dossier-search', () => {
                if (this.vue === 'serie') this.quitSerieMode();
                this.$nextTick(() => {
                    const field = document.getElementById('dossier-semantic-search-query');
                    if (!field) return;
                    field.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    field.focus();
                });
            });
            // Ecran tactile : `hover: none` distingue un doigt d'une souris
            // mieux que la largeur de la fenetre.
            this.tactile = window.matchMedia('(hover: none) and (pointer: coarse)').matches;
            document.addEventListener('keydown', (ev) => {
                if (ev.key === 'Escape') {
                    if (this.showPreviewModal) { this.showPreviewModal = false; this.previewFile = null; this.$nextTick(() => { this._destroyFocusTrap('[aria-labelledby="preview-title"]'); }); }
                    else if (this.showDeleteModal) { this.showDeleteModal = false; this.deleteTarget = null; this.deleteLot = []; this.$nextTick(() => { this._destroyFocusTrap('[aria-labelledby="delete-file-title"]'); }); }
                    else if (this.showArticleModal) { this.showArticleModal = false; this.$nextTick(() => { this._destroyFocusTrap('[aria-labelledby="create-article-title"]'); }); }
                    else if (this.showMdModal) { this.showMdModal = false; this.$nextTick(() => { this._destroyFocusTrap('[aria-labelledby="markdown-note-title"]'); }); }
                    // En dernier : aucune modale ouverte, Echap libere la
                    // selection. Jamais avant — sortir d'une modale prime.
                    else if (this.selectionCount) { this.viderSelection(); }
                }
            });
            if (this.canManageFiles && this.$refs.filePondContainer) {
                const self = this;
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
                    // FilePond ne connait pas `multiple` : son option s'appelle
                    // `allowMultiple`. Ecrite a cote, elle etait ignoree — et
                    // l'`<input type=file>` sous-jacent restait sans l'attribut
                    // `multiple`, donc le selecteur du systeme n'autorisait
                    // qu'UN fichier a la fois (signale par Cyril).
                    allowMultiple: true,
                    // Au-dela de `maxFiles`, FilePond ne refuse pas le fichier
                    // en trop : sur le chemin « parcourir » il jette la
                    // SELECTION ENTIERE sans jamais appeler `onaddfile`
                    // (filepond.js, `exceedsMaxFiles`). A 5, choisir 6 fichiers
                    // ne produisait donc ni requete, ni message, ni ligne —
                    // l'ecran restait muet. La limite suit desormais celle du
                    // serveur.
                    maxFiles: 20,
                    maxFileSize: '50MB',
                    acceptedFileTypes: acceptedTypes,
                    // Le navigateur ne connait pas toujours l'extension : il
                    // rend alors un type vide, et le fichier etait refuse avant
                    // meme d'etre propose. Le serveur, lui, lit le contenu — on
                    // le laisse trancher.
                    fileValidateTypeDetectType: (source, type) => new Promise((resolve, reject) => {
                        if (type) { resolve(type); return; }
                        const extension = (source?.name || '').split('.').pop().toLowerCase();
                        const parExtension = {
                            md: 'text/markdown', markdown: 'text/markdown', txt: 'text/plain',
                            csv: 'text/csv', xls: 'application/vnd.ms-excel',
                            xlsx: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                            doc: 'application/msword',
                            docx: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                            pdf: 'application/pdf', zip: 'application/zip',
                        }[extension];

                        return parExtension ? resolve(parExtension) : reject(type);
                    }),
                    labelIdle: labelIdle,
                    // Les refus de FilePond sortent par ici. Sans ces deux
                    // rappels, un lot ecarte disparaissait en silence : c'est ce
                    // qui a rendu la panne si difficile a voir.
                    onwarning(err, file) {
                        self.refuserFichier(file, err);
                    },
                    onerror(err, file) {
                        self.refuserFichier(file, err);
                    },
                    // Chaque fichier choisi passe par ici. On ne lance PAS son
                    // envoi tout de suite : plusieurs envois simultanes se
                    // disputaient la meme barre de progression et le meme
                    // rechargement de liste — resultat, un seul fichier
                    // survivait a l'ecran. Les fichiers font la queue, et la
                    // file part une fois.
                    onaddfile(err, file) {
                        if (err) {
                            self.refuserFichier(file, err);

                            return;
                        }
                        const duplicate = self.files.some((existingFile) => existingFile.original_name === file.file.name || existingFile.display_name === file.file.name);
                        if (duplicate) {
                            self.refuserFichier(file, { main: self.i18n.duplicateName });

                            return;
                        }

                        self._fileQueue.push(file);
                        self.demarrerLaFile();
                    },
                }));
            }

            this.$watch('showDeleteModal', (val) => { if (val) { if (!this._trapTrigger) this._trapTrigger = this.$refs.fabButton || document.activeElement; this.$nextTick(() => { this._activateFocusTrap('[aria-labelledby="delete-file-title"]'); }); } else { this.$nextTick(() => { this._destroyFocusTrap('[aria-labelledby="delete-file-title"]'); }); } });
            this.$watch('showPreviewModal', (val) => { if (val) { if (!this._trapTrigger) this._trapTrigger = this.$refs.fabButton || document.activeElement; this.$nextTick(() => { this._activateFocusTrap('[aria-labelledby="preview-title"]'); }); } else { this.$nextTick(() => { this._destroyFocusTrap('[aria-labelledby="preview-title"]'); }); } });
            this.$watch('showArticleModal', (val) => { if (val) { if (!this._trapTrigger) this._trapTrigger = this.$refs.fabButton || document.activeElement; this.$nextTick(() => { this._activateFocusTrap('[aria-labelledby="create-article-title"]'); }); } else { this.$nextTick(() => { this._destroyFocusTrap('[aria-labelledby="create-article-title"]'); }); } });
            this.$watch('showMdModal', (val) => { if (val) { if (!this._trapTrigger) this._trapTrigger = this.$refs.fabButton || document.activeElement; this.$nextTick(() => { this._activateFocusTrap('[aria-labelledby="markdown-note-title"]'); }); } else { this.$nextTick(() => { this._destroyFocusTrap('[aria-labelledby="markdown-note-title"]'); }); } });
        },

        destroy() {
            if (this._pond) {
                this._pond.destroy();
                this._pond = null;
            }
        },

        onSearchInput() {
            this.loadFiles(1);
        },

        loadFiles(page) {
            page = page || 1;
            const params = new URLSearchParams({
                page: String(page),
                sort: this.sortBy,
                direction: this.sortDirection,
            });
            if (this.searchQuery && this.searchQuery.trim()) {
                params.set('search', this.searchQuery.trim());
            }
            const url = `/org/${this.orgParam}/dossiers/${this.dossierId}/files?${params.toString()}`;
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
                .catch(() => this.showMessage(this.i18n.uploadFailed, 'error'))
                .finally(() => { this.filesLoading = false; });
        },

        formatBytes(bytes) {
            if (!bytes || bytes === 0) return '0 o';
            const units = ['o', 'Ko', 'Mo', 'Go'];
            const i = Math.floor(Math.log(bytes) / Math.log(1024));
            return (bytes / Math.pow(1024, i)).toFixed(i > 0 ? 1 : 0) + ' ' + units[i];
        },

        normalizeFile(file) {
            // Les dates suivent la langue de la PAGE, pas celle du navigateur :
            // `toLocaleDateString()` sans argument rendait « 8/12/2026 » a cote
            // des « 11/08/2026 » du serveur, dans la meme colonne.
            const langue = document.documentElement.lang || undefined;
            const jour = (valeur) => valeur ? new Date(valeur).toLocaleDateString(langue) : '';

            return {
                ...file,
                sizeFormatted: this.formatBytes(file.size_bytes),
                uploadedAtFormatted: jour(file.created_at),
                updatedAtFormatted: jour(file.updated_at || file.created_at),
            };
        },

        /**
         * Le proprietaire d'un fichier, dit comme dans le reste de la liste :
         * « moi » pour soi-meme, le nom public sinon. L'identite de la personne
         * connectee vient de la balise <meta name="user-id">, deja posee par le
         * gabarit — aucune requete supplementaire.
         */
        /**
         * Vrai quand la recherche en cours ne rend rien du tout : ni fichier
         * (le serveur a repondu 0), ni dossier, ni Article (les noms sont
         * connus du client, la surface spatiale etant rendue en entier).
         */
        get aucunResultat() {
            const q = (this.searchQuery || '').trim().toLowerCase();
            if (!q) return false;
            if (this.totalFiles > 0) return false;

            return !(this.nomsSpatiaux || []).some(nom => nom.includes(q));
        },

        /**
         * Refuser un fichier sans le perdre de vue : il quitte la zone de
         * depot, et son motif rejoint la liste montree a la fin.
         */
        refuserFichier(file, err) {
            const nom = file?.file?.name || '';
            const tropLourd = file?.file?.size > 50 * 1024 * 1024;
            this.uploadRejects.push({
                name: nom,
                reason: tropLourd ? (this.i18n.fileTooLarge || '') : (err?.main || err?.body || this.i18n.uploadFailed || ''),
            });
            this.showUploadRejectModal = true;
            if (this._pond && file?.id) this._pond.removeFile(file.id);
        },

        /** Vide la file, un fichier apres l'autre. */
        async demarrerLaFile() {
            if (this.uploadEnCours) return;
            this.uploadEnCours = true;
            this.uploadFait = 0;
            this.uploadTotal = this._fileQueue.length;

            let reussis = 0;
            const echecs = [];

            while (this._fileQueue.length) {
                // Un fichier ajoute pendant l'envoi rejoint le compte affiche.
                this.uploadTotal = Math.max(this.uploadTotal, this.uploadFait + this._fileQueue.length);

                const file = this._fileQueue.shift();
                const formData = new FormData();
                formData.append('files[]', file.file, file.file.name);

                try {
                    const { ok, data } = await this.uploadFormData(formData, [file.file], true);
                    if (ok) {
                        reussis++;
                    } else {
                        // Reponse non-JSON (413 du serveur web, 500 en HTML) :
                        // `data` est vide. Dire « echec de l'envoi » vaut mieux
                        // que reafficher le nom du fichier en guise de raison.
                        echecs.push({ name: file.file.name, reason: data?.message || this.i18n.uploadFailed });
                    }
                } catch (e) {
                    echecs.push({ name: file.file.name, reason: this.i18n.networkError });
                } finally {
                    this.uploadFait++;
                    // FilePond peut avoir deja retire le fichier de sa propre
                    // liste : lever ici ferait sauter TOUT ce qui suit la
                    // boucle — dont le rechargement final, qui est justement
                    // ce qui fait apparaitre les fichiers a l'ecran.
                    try { this._pond?.removeFile(file.id); } catch (e) { /* deja retire */ }
                }
            }

            this.uploading = false;
            this.uploadProgress = 0;
            this.uploadFileName = '';

            // Un seul rechargement, a la fin : la liste ne clignote pas a
            // chaque fichier et aucune reponse n'en ecrase une autre.
            // Une seule lecture suffit desormais. La seconde, tentee 600 ms
            // plus tard quand la liste revenait vide, compensait un symptome
            // dont on ignorait la cause : le service worker servait sa copie
            // d'avant l'import (`stale-while-revalidate`, corrige dans sw.js).
            try {
                await this.loadFiles(1);
            } catch (e) {
                console.error('[dossiers] rechargement apres import', e);
            }

            // La file ne se rouvre qu'ici : tant que la relecture n'est pas
            // faite, une nouvelle selection rejoint la file en cours au lieu
            // d'en demarrer une seconde en parallele.
            this.uploadEnCours = false;

            if (reussis > 0) {
                const modele = reussis === 1 ? (this.i18n.uploaded || '') : (this.i18n.filesBatchResult || '');
                this.showMessage(
                    reussis === 1
                        ? modele
                        : modele.replace(':success', reussis).replace(':total', reussis + echecs.length).replace(':errors', ''),
                    'success',
                );
            }
            if (echecs.length) {
                this.uploadRejects = this.uploadRejects.concat(echecs);
                this.showUploadRejectModal = true;
            }
        },

        /**
         * L'element designe quand il n'y en a QU'UN.
         *
         * Volontairement nul des qu'il y en a plusieurs : la barre d'un seul
         * element propose Ouvrir, Partager et Renommer, qui n'ont pas de sens
         * en lot. Ce getter les eteint toutes d'un coup, sans qu'aucune de ces
         * conditions ait a connaitre l'existence du lot.
         */
        get selection() {
            return this.selectionKeys.length === 1
                ? (this._catalogue[this.selectionKeys[0]] || null)
                : null;
        },

        get selectionCount() {
            return this.selectionKeys.length;
        },

        get selectionElements() {
            return this.selectionKeys.map(cle => this._catalogue[cle]).filter(Boolean);
        },

        /**
         * Les actions du lot : proposees seulement si TOUS les elements les
         * supportent. Une action a moitie applicable est un piege — on la
         * masque plutot que de la faire echouer sur la moitie du lot.
         */
        get lotDeplacable() {
            const lot = this.selectionElements;

            return this.moveTargets.length > 0 && lot.length > 1
                && lot.every(i => i.type === 'file' || i.type === 'article');
        },

        get lotSupprimable() {
            const lot = this.selectionElements;

            return this.canDeleteFiles && lot.length > 1 && lot.every(i => i.type === 'file');
        },

        get lotRetirable() {
            const lot = this.selectionElements;

            return lot.length > 1 && lot.every(i => i.type === 'article' && i.formulaireRetrait);
        },

        cleSelection(item) {
            return `${item.type}:${item.id}`;
        },

        /**
         * Memoriser une ligne rendue, qu'elle soit designee ou non.
         *
         * La plage Maj+clic prend des lignes que personne n'a touchees : sans
         * ce catalogue on connaitrait leur cle sans rien savoir d'elles, et la
         * barre d'actions serait vide.
         */
        enregistrer(item) {
            if (!item) return;
            this._catalogue[this.cleSelection(item)] = item;
        },

        estSelectionne(type, id) {
            return this.selectionKeys.includes(`${type}:${id}`);
        },

        /** Clic simple : cet element REMPLACE la selection. */
        selectionner(item) {
            if (!item) return;
            this.enregistrer(item);
            const cle = this.cleSelection(item);
            this.selectionKeys = [cle];
            this._ancre = cle;
        },

        /** Ctrl/Cmd+clic : cet element rejoint ou quitte la selection. */
        basculerSelection(item) {
            if (!item) return;
            this.enregistrer(item);
            const cle = this.cleSelection(item);
            this.selectionKeys = this.selectionKeys.includes(cle)
                ? this.selectionKeys.filter(c => c !== cle)
                : this.selectionKeys.concat(cle);
            this._ancre = cle;
        },

        /**
         * Maj+clic : toute la plage entre l'ancre et cet element.
         *
         * L'ordre vient du DOM, seule source qui connaisse l'ordre VISUEL : les
         * lignes ont trois origines (dossiers et Articles rendus par Blade,
         * fichiers rendus par `x-for`), les deux modes coexistent dans la page
         * et la recherche en masque. Le filtre sur `offsetParent` ne garde donc
         * que ce qui est reellement a l'ecran.
         */
        etendreSelection(item) {
            if (!item) return;
            this.enregistrer(item);
            const cle = this.cleSelection(item);
            const cles = this.clesVisibles();
            const depart = cles.indexOf(this._ancre);
            const arrivee = cles.indexOf(cle);

            if (depart === -1 || arrivee === -1) { this.selectionner(item); return; }

            const [a, b] = depart <= arrivee ? [depart, arrivee] : [arrivee, depart];
            this.selectionKeys = cles.slice(a, b + 1);
            // Maj+clic surligne aussi du texte : le geste doit designer des
            // lignes, pas laisser une trainee de selection bleue derriere lui.
            window.getSelection()?.removeAllRanges();
        },

        clesVisibles() {
            const racine = this.$root || document;

            return [...racine.querySelectorAll('[data-selection-key]')]
                .filter(element => element.offsetParent !== null)
                .map(element => element.dataset.selectionKey);
        },

        viderSelection() {
            this.selectionKeys = [];
            this._ancre = null;
        },

        /**
         * Le clic simple designe ; il n'ouvre pas.
         *
         * Ctrl/Cmd+clic et Maj+clic n'ouvrent donc plus d'onglet sur une ligne :
         * c'est le compromis de Drive et de l'explorateur de fichiers, ou ces
         * deux gestes appartiennent a la selection. Le clic milieu, lui, reste
         * au navigateur pour qui veut un onglet.
         */
        clicElement(evenement, item) {
            if (evenement.button === 1) return;

            evenement.preventDefault();

            // Un appui long vient de selectionner : le `click` qui le suit sur
            // mobile ne doit pas ouvrir par-dessus.
            if (this._appuiLong) { this._appuiLong = false; return; }

            // Tactile : le tap ouvre, sauf quand une selection est deja en
            // cours — il l'enrichit alors, comme dans Files et Photos.
            if (this.tactile) {
                if (this.selectionCount > 0) { this.basculerSelection(item); return; }
                this.ouvrir(item);

                return;
            }

            if (evenement.shiftKey) { this.etendreSelection(item); return; }
            if (evenement.metaKey || evenement.ctrlKey) { this.basculerSelection(item); return; }

            this.selectionner(item);
        },

        /** Le double-clic ouvre : dossier, Article ou fichier. */
        ouvrir(item) {
            if (!item) return;
            this.viderSelection();
            if (item.type === 'file') { this.ouvrirFichier(item.file); return; }
            if (item.url) window.location.href = item.url;
        },

        /**
         * Ouvrir un fichier : l'apercu quand il est lisible dans la page, le
         * telechargement sinon. Une seule definition, partagee par la liste, la
         * grille et la barre contextuelle.
         */
        ouvrirFichier(file) {
            if (!file) return;
            const apercu = file.mime_type?.startsWith('image/')
                || file.mime_type === 'application/pdf'
                || file.mime_type === 'text/plain'
                || file.mime_type === 'text/markdown';

            if (apercu) { this.openPreview(file); return; }

            window.location = `/org/${this.orgParam}/dossiers/${this.dossierId}/files/${file.id}`;
        },

        /** Appui long tactile : 500 ms sans bouger designent l'element. */
        debutAppui(item) {
            this._appuiLong = false;
            clearTimeout(this._appuiTimer);
            this._appuiTimer = setTimeout(() => {
                this._appuiLong = true;
                this.selectionner(item);
                if (navigator.vibrate) navigator.vibrate(10);
            }, 500);
        },

        finAppui() {
            clearTimeout(this._appuiTimer);
        },

        openRenameModal(file) {
            this.renameTarget = file;
            this.renameValue = file.display_name || file.original_name || '';
            this.showRenameModal = true;
        },

        async confirmRename() {
            const nom = (this.renameValue || '').trim();
            if (!this.renameTarget || !nom || this.saving) return;
            this.saving = true;
            try {
                const url = `/org/${this.orgParam}/dossiers/${this.dossierId}/files/${this.renameTarget.id}/rename`;
                const response = await fetch(url, {
                    method: 'PATCH',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': this.csrfToken, 'X-Requested-With': 'XMLHttpRequest' },
                    body: JSON.stringify({ display_name: nom }),
                });
                const data = await response.json();
                if (!response.ok) {
                    this.showMessage(data.message || this.i18n.networkError, 'error');
                    return;
                }
                // Le nom rendu par le serveur, pas celui tape : c'est lui qui
                // garde l'extension d'origine.
                const cible = this.files.find(f => f.id === this.renameTarget.id);
                if (cible) cible.display_name = data.file.display_name;
                this.showRenameModal = false;
                this.renameTarget = null;
                this.showMessage(data.message, 'success');
            } catch (e) {
                this.showMessage(this.i18n.networkError, 'error');
            } finally {
                this.saving = false;
            }
        },

        uploaderLibelle(file) {
            const moi = document.querySelector('meta[name="user-id"]')?.content;
            if (file?.uploader?.id && moi && file.uploader.id === moi) return this.i18n.ownerMe || 'moi';

            return file?.uploader?.name || '—';
        },

        /** Les initiales, dessinees ici : aucun appel a un service d'avatars. */
        uploaderInitiales(file) {
            const nom = (file?.uploader?.name || '').trim();
            if (!nom) return '?';

            return nom.split(/\s+/).slice(0, 2).map(m => m.charAt(0)).join('').toUpperCase();
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

        /**
         * Le type tel qu'on le lit dans un explorateur : l'extension du nom,
         * qui distingue un .docx d'un .doc et un .xlsx d'un .xls la ou le type
         * MIME les confond. On retombe sur la famille quand le nom n'a pas
         * d'extension (une note creee ici, par exemple).
         */
        typeDeFichier(file) {
            const nom = file?.display_name || file?.original_name || '';
            const extension = nom.includes('.') ? nom.split('.').pop() : '';

            return extension.length && extension.length <= 5
                ? extension.toUpperCase()
                : this.fileTypeLabel(file?.mime_type);
        },

        async deleteFile(file) {
            this.saving = true;
            this.files = this.files.filter(f => f.id !== file.id);
            this.totalFiles = Math.max(0, this.totalFiles - 1);
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
                    if (this.files.some(f => f.id === file.id)) {
                        this.files = this.files.filter(f => f.id !== file.id);
                        this.totalFiles = Math.max(0, this.totalFiles - 1);
                    }
                } else {
                    await this.loadFiles(this.currentPage);
                    this.showMessage(data.message || this.i18n.deleteFailed, 'error');
                }
            } catch (error) {
                await this.loadFiles(this.currentPage);
                this.showMessage(this.i18n.deleteFailed, 'error');
            } finally {
                this.saving = false;
            }
        },

        openDeleteModal(file) {
            this._trapTrigger = document.activeElement;
            this.deleteTarget = file;
            this.showDeleteModal = true;
            this.$nextTick(() => { this._activateFocusTrap('[aria-labelledby="delete-file-title"]'); });
        },

        openDeleteFolderModal(id, name) {
            this._trapTrigger = document.activeElement;
            this.deleteFolderTarget = { id, name };
            this.showDeleteFolderModal = true;
            this.$nextTick(() => { this._activateFocusTrap('[aria-labelledby="delete-folder-title"]'); });
        },

        closeDeleteFolderModal() {
            this.showDeleteFolderModal = false;
            this.deleteFolderTarget = null;
            this.$nextTick(() => { this._destroyFocusTrap('[aria-labelledby="delete-folder-title"]'); });
        },

        async confirmDeleteFolder() {
            if (!this.deleteFolderTarget) return;
            const id = this.deleteFolderTarget.id;
            this.deletingFolder = true;
            try {
                const response = await fetch(`/org/${this.orgParam}/dossiers/${id}`, {
                    method: 'DELETE',
                    headers: {
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': this.csrfToken,
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                });
                if (response.ok) {
                    window.location.reload();
                    return;
                }
                const data = await response.json().catch(() => ({}));
                this.showMessage(data.message || this.i18n.deleteFailed, 'error');
            } catch (error) {
                this.showMessage(this.i18n.deleteFailed, 'error');
            } finally {
                this.deletingFolder = false;
                this.closeDeleteFolderModal();
            }
        },

        async confirmDeleteFile() {
            const lot = this.deleteLot.slice();
            const file = this.deleteTarget;
            this.showDeleteModal = false;
            this.deleteTarget = null;
            this.deleteLot = [];
            this.$nextTick(() => { this._destroyFocusTrap('[aria-labelledby="delete-file-title"]'); });

            if (lot.length) { await this.supprimerLot(lot); return; }
            if (!file) return;

            await this.deleteFile(file);
        },

        // ── Deplacer un fichier (TASK-1130 passe 4) : menu "Deplacer vers..."
        //    et glisser-deposer partagent ce meme point d'entree unique, pour
        //    ne jamais faire diverger les deux gestes. ─────────────────────

        setViewMode(mode) {
            this.viewMode = mode;
            // Liste et Grille sont aussi la sortie du mode Serie : trois modes,
            // une seule bascule.
            if (this.vue === 'serie') this.quitSerieMode();
            try { window.localStorage.setItem('bp-dossier-view-mode', mode); } catch (e) { /* meme garde qu'a la lecture */ }
        },

        // Le troisieme mode de la bascule. 0 Serie : etat vide + creer ;
        // exactement 1 : on l'ouvre directement ; plusieurs : le choix est
        // explicite (« Serie : Choisir… ▾ »), jamais arbitraire. Jamais
        // persiste : c'est une interaction, pas une preference d'affichage.
        enterSerieToggle() {
            if (this.vue === 'serie') return;
            this.vue = 'serie';
            this.serieActive = null;
            if (this.seriesMode.length === 1) { this.enterSerieMode(this.seriesMode[0].id); return; }
            // Aucune sequence encore : on n'ouvre pas un formulaire, on ouvre la
            // sequence. Elle prend le nom du Dossier et son contenu — c'est ce
            // qu'on venait voir. Renommer ou retirer reste possible ensuite.
            if (this.seriesMode.length === 0 && this.canManageSeries) this.creerSerieDuDossier();
        },

        /**
         * La Serie evidente : celle du Dossier, avec ce qu'il contient.
         */
        async creerSerieDuDossier() {
            if (this.serieSaving) return;
            this.serieSaving = true;
            try {
                const response = await fetch(`/org/${this.orgParam}/dossiers/${this.dossierId}/series`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': this.csrfToken, 'X-Requested-With': 'XMLHttpRequest' },
                    body: JSON.stringify({ name: this.dossierName, remplir: true }),
                });
                const data = await response.json();
                if (!response.ok) {
                    this.showMessage(data.message || Object.values(data.errors || {}).flat()[0] || this.i18n.serieReorderFailed, 'error');
                    return;
                }
                const items = (data.series.items || []).map((item) => {
                    const article = item.blog_post || item.blogPost;
                    const fichier = item.dossier_file || item.dossierFile;

                    return {
                        itemId: item.id,
                        type: article ? 'article' : 'file',
                        name: article ? article.title : (fichier?.display_name || fichier?.original_name || ''),
                        key: article ? `blog:${article.id}` : `file:${fichier?.id}`,
                    };
                });
                const serie = { id: data.series.id, name: data.series.name || this.dossierName, items };
                this.seriesMode.push(serie);
                this.enterSerieMode(serie.id);
            } catch (e) {
                this.showMessage(this.i18n.networkError, 'error');
            } finally {
                this.serieSaving = false;
            }
        },

        // Apres un deplacement reussi, le compteur « N elements » du dossier
        // cible est rafraichi depuis l'etat deja disponible (data-count porte
        // par le serveur au rendu) — pas de rechargement, pas de requete.
        bumpFolderCount(folderId) {
            document.querySelectorAll(`[data-folder-count="${folderId}"]`).forEach((el) => {
                const count = (parseInt(el.dataset.count, 10) || 0) + 1;
                el.dataset.count = String(count);
                el.textContent = count === 1
                    ? (this.i18n.folderItemsOne || '1')
                    : (this.i18n.folderItemsMany || ':count').replace(':count', String(count));
            });
        },

        // ── Mode Serie : entrer, sortir, classer ─────────────────────────

        enterSerieMode(id) {
            const serie = this.seriesMode.find(s => s.id === id);
            if (!serie) return;
            // Copie de travail : l'optimisme s'applique dessus, le revert
            // revient a l'etat serveur garde dans seriesMode.
            this.serieActive = { id: serie.id, name: serie.name, items: serie.items.map(i => ({ ...i })) };
            this.vue = 'serie';
            this.showSerieSelect = false;
        },

        quitSerieMode() {
            this.serieActive = null;
            this.serieDragArmedId = null;
            this.serieDragItemId = null;
            this.serieDragOverId = null;
            this.vue = 'documents';
        },

        // L'etat serveur de reference, mis a jour apres chaque succes.
        _syncSerieBack() {
            const serie = this.seriesMode.find(s => s.id === this.serieActive?.id);
            if (serie) serie.items = this.serieActive.items.map(i => ({ ...i }));
        },

        // Un contenu n'appartient qu'a UNE Serie (regle MVP du moteur) : les
        // candidats a l'ajout excluent tout ce qui vit deja dans une Serie.
        _serieUsedKeys() {
            const used = new Set();
            this.seriesMode.forEach(s => s.items.forEach(i => { if (i.key) used.add(i.key); }));
            return used;
        },

        get serieArticleCandidates() {
            const used = this._serieUsedKeys();
            return this.serieArticles.filter(a => !used.has('blog:' + a.id));
        },

        get serieFileCandidates() {
            const used = this._serieUsedKeys();
            return this.files.filter(f => !used.has('file:' + f.id));
        },

        async serieAdd(kind, id, name) {
            if (!this.serieActive || this.serieSaving) return;
            this.serieSaving = true;
            try {
                const response = await fetch(`/org/${this.orgParam}/dossiers/${this.dossierId}/series/annexes`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': this.csrfToken, 'X-Requested-With': 'XMLHttpRequest' },
                    body: JSON.stringify(kind === 'article'
                        ? { blog_post_id: id, series_id: this.serieActive.id }
                        : { dossier_file_id: id, series_id: this.serieActive.id }),
                });
                const data = await response.json();
                if (!response.ok) {
                    this.showMessage(data.message || Object.values(data.errors || {}).flat()[0] || this.i18n.serieReorderFailed, 'error');
                    return;
                }
                this.serieActive.items.push({
                    itemId: data.item?.id,
                    type: kind,
                    name,
                    key: (kind === 'article' ? 'blog:' : 'file:') + id,
                });
                this._syncSerieBack();
                this.showMessage(data.message || this.i18n.serieAdded, 'success');
            } catch (e) {
                this.showMessage(this.i18n.networkError, 'error');
            } finally {
                this.serieSaving = false;
            }
        },

        async serieRemove(item) {
            if (!this.serieActive || !item.itemId || this.serieSaving) return;
            this.serieSaving = true;
            try {
                const response = await fetch(`/org/${this.orgParam}/dossiers/${this.dossierId}/series/annexes/${item.itemId}`, {
                    method: 'DELETE',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': this.csrfToken, 'X-Requested-With': 'XMLHttpRequest' },
                    body: JSON.stringify({ series_id: this.serieActive.id }),
                });
                const data = await response.json();
                if (!response.ok) {
                    this.showMessage(data.message || this.i18n.serieReorderFailed, 'error');
                    return;
                }
                this.serieActive.items = this.serieActive.items.filter(i => i.itemId !== item.itemId);
                this._syncSerieBack();
                this.showMessage(data.message || this.i18n.serieRemoved, 'success');
            } catch (e) {
                this.showMessage(this.i18n.networkError, 'error');
            } finally {
                this.serieSaving = false;
            }
        },

        // Le premier ne monte pas, le dernier ne descend pas — la racine
        // (itemId null) n'entre jamais dans le classement.
        serieCanMoveUp(itemId) {
            const movable = this.serieActive?.items.filter(i => i.itemId) || [];
            return movable.findIndex(i => i.itemId === itemId) > 0;
        },

        serieCanMoveDown(itemId) {
            const movable = this.serieActive?.items.filter(i => i.itemId) || [];
            const idx = movable.findIndex(i => i.itemId === itemId);
            return idx !== -1 && idx < movable.length - 1;
        },

        // Monter / Descendre : premiere classe, clavier et mobile — le drag
        // les complete, il ne les remplace jamais. La racine (itemId null)
        // reste en tete : elle ne se classe pas, elle se remplace.
        async serieMove(itemId, delta) {
            if (!this.serieActive || this.serieSaving) return;
            const items = this.serieActive.items;
            const fixed = items.filter(i => !i.itemId);
            const movable = items.filter(i => i.itemId);
            const from = movable.findIndex(i => i.itemId === itemId);
            const to = from + delta;
            if (from === -1 || to < 0 || to >= movable.length) return;
            const avant = items.map(i => ({ ...i }));
            movable.splice(to, 0, movable.splice(from, 1)[0]);
            this.serieActive.items = [...fixed, ...movable];
            await this.persistSerieOrder(avant, itemId);
        },

        serieDropOn(targetItemId) {
            const dragged = this.serieDragItemId;
            this.serieDragOverId = null;
            if (!this.serieActive || !dragged || dragged === targetItemId) return;
            const items = this.serieActive.items;
            const fixed = items.filter(i => !i.itemId);
            const movable = items.filter(i => i.itemId);
            const from = movable.findIndex(i => i.itemId === dragged);
            const to = movable.findIndex(i => i.itemId === targetItemId);
            if (from === -1 || to === -1) return;
            const avant = items.map(i => ({ ...i }));
            movable.splice(to, 0, movable.splice(from, 1)[0]);
            this.serieActive.items = [...fixed, ...movable];
            this.persistSerieOrder(avant, dragged);
        },

        // Le seul endroit qui persiste un ordre : l'interface a deja bouge,
        // le serveur reste source de verite. Echec = retour a l'ordre
        // d'avant + message — jamais un ordre non persiste a l'ecran.
        async persistSerieOrder(avant, movedItemId) {
            this.serieSaving = true;
            try {
                const response = await fetch(`/org/${this.orgParam}/dossiers/${this.dossierId}/series/annexes/reorder`, {
                    method: 'PATCH',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': this.csrfToken, 'X-Requested-With': 'XMLHttpRequest' },
                    body: JSON.stringify({
                        items: this.serieActive.items.filter(i => i.itemId).map(i => i.itemId),
                        series_id: this.serieActive.id,
                    }),
                });
                if (!response.ok) throw new Error(String(response.status));
                this._syncSerieBack();
                const rang = this.serieActive.items.findIndex(i => i.itemId === movedItemId);
                if (rang !== -1) {
                    const item = this.serieActive.items[rang];
                    this.serieAnnouncement = `${String(rang + 1).padStart(2, '0')} — ${item.name}`;
                }
            } catch (e) {
                this.serieActive.items = avant;
                this.showMessage(this.i18n.serieReorderFailed, 'error');
            } finally {
                this.serieSaving = false;
                this.serieDragArmedId = null;
                this.serieDragItemId = null;
            }
        },

        async createSerie() {
            const name = this.newSerieName.trim();
            if (!name || this.serieSaving) return;
            this.serieSaving = true;
            try {
                const response = await fetch(`/org/${this.orgParam}/dossiers/${this.dossierId}/series`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': this.csrfToken, 'X-Requested-With': 'XMLHttpRequest' },
                    body: JSON.stringify({ name }),
                });
                const data = await response.json();
                if (!response.ok) {
                    this.showMessage(data.message || Object.values(data.errors || {}).flat()[0] || this.i18n.serieReorderFailed, 'error');
                    return;
                }
                const serie = { id: data.series.id, name: data.series.name || name, items: [] };
                this.seriesMode.push(serie);
                this.showCreateSerieModal = false;
                this.newSerieName = '';
                this.showMessage(data.message || this.i18n.serieCreated, 'success');
                this.enterSerieMode(serie.id);
            } catch (e) {
                this.showMessage(this.i18n.networkError, 'error');
            } finally {
                this.serieSaving = false;
            }
        },

        // Dissoudre la classification sequentielle — aucun Article, aucun
        // fichier, aucun contenu du Dossier n'est supprime.
        async deleteSerieActive() {
            if (!this.serieActive || this.serieSaving) return;
            this.serieSaving = true;
            try {
                const response = await fetch(`/org/${this.orgParam}/dossiers/${this.dossierId}/series`, {
                    method: 'DELETE',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': this.csrfToken, 'X-Requested-With': 'XMLHttpRequest' },
                    body: JSON.stringify({ series_id: this.serieActive.id }),
                });
                const data = await response.json();
                if (!response.ok) {
                    this.showMessage(data.message || this.i18n.serieReorderFailed, 'error');
                    return;
                }
                this.seriesMode = this.seriesMode.filter(s => s.id !== this.serieActive.id);
                this.showSerieDeleteModal = false;
                this.quitSerieMode();
                this.showMessage(data.message || this.i18n.serieDeleted, 'success');
            } catch (e) {
                this.showMessage(this.i18n.networkError, 'error');
            } finally {
                this.serieSaving = false;
            }
        },

        // Definir un Article de la Serie comme racine : le moteur existant
        // (update + series_id) replace l'ancienne racine en premiere annexe
        // et renumerote — la reponse porte l'etat complet, on l'applique.
        async serieSetRoot(item) {
            if (!this.serieActive || this.serieSaving || item.type !== 'article' || !item.key) return;
            const blogPostId = item.key.split(':')[1];
            this.serieSaving = true;
            try {
                const response = await fetch(`/org/${this.orgParam}/dossiers/${this.dossierId}/series`, {
                    method: 'PATCH',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': this.csrfToken, 'X-Requested-With': 'XMLHttpRequest' },
                    body: JSON.stringify({ root_blog_post_id: blogPostId, series_id: this.serieActive.id }),
                });
                const data = await response.json();
                if (!response.ok) {
                    this.showMessage(data.message || Object.values(data.errors || {}).flat()[0] || this.i18n.serieReorderFailed, 'error');
                    return;
                }
                // Reconstruire la projection depuis la verite serveur.
                const s = data.series;
                const items = [];
                if (s.root_blog_post) items.push({ itemId: null, type: 'root', name: s.root_blog_post.title, key: 'blog:' + s.root_blog_post.id });
                (s.items || []).forEach(i => {
                    if (i.blog_post) items.push({ itemId: i.id, type: 'article', name: i.blog_post.title, key: 'blog:' + i.blog_post.id });
                    else if (i.dossier_file) items.push({ itemId: i.id, type: 'file', name: i.dossier_file.display_name || i.dossier_file.original_name, key: 'file:' + i.dossier_file.id });
                });
                this.serieActive.items = items;
                this._syncSerieBack();
                this.showMessage(data.message || this.i18n.serieCreated, 'success');
            } catch (e) {
                this.showMessage(this.i18n.networkError, 'error');
            } finally {
                this.serieSaving = false;
            }
        },

        // ── « Ajouter un article existant » (fonction DOSSIER, via + Nouveau) ──

        openAttachArticleModal() {
            this._trapTrigger = this.$refs.fabButton || document.activeElement;
            this.attachSearchQuery = '';
            this.attachSearchResults = [];
            this.showAttachArticleModal = true;
            this.$nextTick(() => { this._activateFocusTrap('[aria-labelledby="attach-article-title"]'); });
        },

        closeAttachArticleModal() {
            this.showAttachArticleModal = false;
            this.$nextTick(() => { this._destroyFocusTrap('[aria-labelledby="attach-article-title"]'); });
        },

        async searchAttachArticles() {
            if (this.attachSearchQuery.trim().length < 2) { this.attachSearchResults = []; return; }
            this.attachSearching = true;
            try {
                const res = await fetch(`/org/${this.orgParam}/dossiers/${this.dossierId}/articles/search?q=` + encodeURIComponent(this.attachSearchQuery.trim()), {
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                });
                const data = await res.json();
                this.attachSearchResults = data.articles || [];
            } catch (e) {
                this.attachSearchResults = [];
            } finally {
                this.attachSearching = false;
            }
        },

        async attachExistingArticle(article) {
            if (this.attachSaving) return;
            this.attachSaving = true;
            try {
                const response = await fetch(`/org/${this.orgParam}/dossiers/${this.dossierId}/articles`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': this.csrfToken, 'X-Requested-With': 'XMLHttpRequest' },
                    body: JSON.stringify({ blog_post_id: article.id }),
                });
                const data = await response.json();
                if (!response.ok) {
                    this.showMessage(data.message || Object.values(data.errors || {}).flat()[0] || this.i18n.networkError, 'error');
                    return;
                }
                // Les lignes Articles sont rendues cote serveur : recharger est
                // le geste honnete, comme pour la suppression de dossier.
                window.location.reload();
            } catch (e) {
                this.showMessage(this.i18n.networkError, 'error');
            } finally {
                this.attachSaving = false;
            }
        },

        // « Retirer de cette Boucle » (CAS B) : confirmation legere, ton
        // non destructif — le PATCH part du <form> du modal, pas d'ici.
        openUnshareFolderModal(id, name, action) {
            this._trapTrigger = document.activeElement;
            this.unshareFolderTarget = { id, name, action };
            this.showUnshareFolderModal = true;
            this.$nextTick(() => { this._activateFocusTrap('[aria-labelledby="unshare-folder-title"]'); });
        },

        closeUnshareFolderModal() {
            this.showUnshareFolderModal = false;
            this.unshareFolderTarget = null;
            this.$nextTick(() => { this._destroyFocusTrap('[aria-labelledby="unshare-folder-title"]'); });
        },

        openMoveModal(file) {
            if (!this.moveTargets.length) return;
            this._trapTrigger = document.activeElement;
            this.moveTarget = file;
            this.moveLot = [];
            this.showMoveModal = true;
            this.$nextTick(() => { this._activateFocusTrap('[aria-labelledby="move-file-title"]'); });
        },

        /** Le meme choix de destination, pour toute la selection. */
        openMoveLot() {
            if (!this.moveTargets.length) return;
            const lot = this.selectionElements.filter(i => i.type === 'file' || i.type === 'article');
            if (!lot.length) return;
            this._trapTrigger = document.activeElement;
            this.moveTarget = null;
            this.moveLot = lot;
            this.showMoveModal = true;
            this.$nextTick(() => { this._activateFocusTrap('[aria-labelledby="move-file-title"]'); });
        },

        closeMoveModal() {
            this.showMoveModal = false;
            this.moveTarget = null;
            this.moveLot = [];
            this.$nextTick(() => { this._destroyFocusTrap('[aria-labelledby="move-file-title"]'); });
        },

        async moveFileTo(file, targetDossierId) {
            if (!file || !targetDossierId || targetDossierId === this.dossierId) return;

            this.saving = true;
            try {
                const response = await fetch(`/org/${this.orgParam}/dossiers/${this.dossierId}/files/${file.id}/move`, {
                    method: 'PATCH',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': this.csrfToken,
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify({ target_dossier_id: targetDossierId }),
                });
                const data = await response.json();

                if (response.ok) {
                    // Le fichier a quitte ce Dossier : il quitte aussi cette vue,
                    // exactement comme apres une suppression.
                    this.files = this.files.filter(f => f.id !== file.id);
                    this.totalFiles = Math.max(0, this.totalFiles - 1);
                    // La destination est nommee (TASK-1130 UX finale) :
                    // « Fichier deplace. » sans dire vers ou laissait le geste
                    // muet. moveTargets porte deja le nom, filtre au droit reel.
                    const cible = this.moveTargets.find(t => t.id === targetDossierId);
                    this.showMessage(cible?.name && this.i18n.movedTo
                        ? this.i18n.movedTo.replace(':name', cible.name)
                        : (data.message || this.i18n.moved), 'success');
                    this.bumpFolderCount(targetDossierId);
                } else {
                    this.showMessage(data.message || this.i18n.moveFailed, 'error');
                }
            } catch (error) {
                this.showMessage(this.i18n.moveFailed, 'error');
            } finally {
                this.saving = false;
            }
        },

        async confirmMoveFile(targetDossierId) {
            const lot = this.moveLot.slice();
            const file = this.moveTarget;
            this.closeMoveModal();

            if (lot.length) { await this.deplacerVers(targetDossierId, lot); return; }
            if (!file) return;

            await this.moveFileTo(file, targetDossierId);
        },

        // Glisser des lignes : un marqueur d'etat propre a cette page — le
        // geste ne quitte jamais l'onglet. `draggingKeys` porte TOUT ce qui est
        // tire, et pas seulement la ligne sous le curseur.
        onFileDragStart(evenement, file) {
            this.demarrerGlissement(evenement, { type: 'file', id: file.id, name: file.display_name || file.original_name, file });
        },

        onArticleDragStart(evenement, item) {
            this.demarrerGlissement(evenement, item);
        },

        /**
         * Tirer un element HORS de la selection la remplace d'abord ; tirer un
         * element DE la selection emporte toute la selection. C'est le geste de
         * Drive et de l'explorateur : on ne deplace jamais a l'insu de la
         * personne des lignes qu'elle ne voit pas designees.
         */
        demarrerGlissement(evenement, item) {
            if (!this.estSelectionne(item.type, item.id)) this.selectionner(item);
            else this.enregistrer(item);

            this.draggingKeys = this.selectionKeys.slice();
            // Firefox n'emet aucun `drop` si le glissement ne porte pas de
            // donnee : ce texte n'est jamais lu, il rend le geste possible.
            try { evenement?.dataTransfer?.setData('text/plain', this.draggingKeys.join(',')); } catch (e) { /* navigateur qui refuse : le geste marche sans */ }
        },

        estEnDeplacement(type, id) {
            return this.draggingKeys.includes(`${type}:${id}`);
        },

        onFileDragEnd() {
            this.draggingKeys = [];
            this.dragOverFolderId = null;
        },

        onFolderDragOver(folderId) {
            if (!this.draggingKeys.length) return;
            this.dragOverFolderId = folderId;
        },

        onFolderDragLeave(folderId) {
            if (this.dragOverFolderId === folderId) this.dragOverFolderId = null;
        },

        async onFolderDrop(folderId) {
            const cles = this.draggingKeys.slice();
            this.dragOverFolderId = null;
            this.draggingKeys = [];
            if (!cles.length) return;

            await this.deplacerVers(folderId, cles.map(cle => this._catalogue[cle]).filter(Boolean));
        },

        // ── Agir sur un lot ──────────────────────────────────────────────
        //
        // Une requete par element, sur les endpoints qui existent deja. Un
        // endpoint « en masse » aurait fait perdre deux choses : l'examen des
        // droits element par element (source ET cible sont verifiees a chaque
        // deplacement) et le detail des refus — un doublon de nom n'a aucune
        // raison d'empecher les cinq autres de partir.

        async deplacerVers(cibleId, elements = null) {
            const lot = (elements || this.selectionElements).filter(i => i.type === 'file' || i.type === 'article');
            if (!cibleId || !lot.length || cibleId === this.dossierId) return;

            this.saving = true;
            const echecs = [];
            let reussis = 0;
            let articleDeplace = false;

            for (const item of lot) {
                const url = item.type === 'file'
                    ? `/org/${this.orgParam}/dossiers/${this.dossierId}/files/${item.id}/move`
                    : `/org/${this.orgParam}/dossiers/${this.dossierId}/articles/${item.id}/move`;

                try {
                    const reponse = await fetch(url, {
                        method: 'PATCH',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': this.csrfToken,
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        body: JSON.stringify({ target_dossier_id: cibleId }),
                    });
                    const data = await reponse.json().catch(() => ({}));

                    if (reponse.ok) {
                        reussis++;
                        if (item.type === 'file') {
                            this.files = this.files.filter(f => f.id !== item.id);
                            this.totalFiles = Math.max(0, this.totalFiles - 1);
                        } else {
                            articleDeplace = true;
                        }
                        this.bumpFolderCount(cibleId);
                    } else {
                        echecs.push({ name: item.name, reason: data.message || this.i18n.moveFailed });
                    }
                } catch (error) {
                    echecs.push({ name: item.name, reason: this.i18n.moveFailed });
                }
            }

            this.saving = false;
            this.viderSelection();
            this.rapporterLot(reussis, lot.length, echecs, 'move', cibleId);

            // Les lignes d'Article viennent du serveur : elles ne peuvent pas
            // disparaitre sans une recharge, contrairement aux fichiers.
            if (articleDeplace && !echecs.length) setTimeout(() => window.location.reload(), 900);
        },

        /** Le lot part sur le meme modal de confirmation que l'element seul. */
        openDeleteLot() {
            const lot = this.selectionElements.filter(i => i.type === 'file');
            if (!lot.length) return;
            this._trapTrigger = document.activeElement;
            this.deleteLot = lot;
            this.deleteTarget = null;
            this.showDeleteModal = true;
            this.$nextTick(() => { this._activateFocusTrap('[aria-labelledby="delete-file-title"]'); });
        },

        async supprimerLot(lot) {
            this.saving = true;
            const echecs = [];
            let reussis = 0;

            for (const item of lot) {
                try {
                    const reponse = await fetch(`/org/${this.orgParam}/dossiers/${this.dossierId}/files/${item.id}`, {
                        method: 'DELETE',
                        headers: {
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': this.csrfToken,
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                    });
                    const data = await reponse.json().catch(() => ({}));

                    if (reponse.ok) {
                        reussis++;
                        this.files = this.files.filter(f => f.id !== item.id);
                        this.totalFiles = Math.max(0, this.totalFiles - 1);
                    } else {
                        echecs.push({ name: item.name, reason: data.message || this.i18n.deleteFailed });
                    }
                } catch (error) {
                    echecs.push({ name: item.name, reason: this.i18n.deleteFailed });
                }
            }

            this.saving = false;
            this.viderSelection();
            this.rapporterLot(reussis, lot.length, echecs, 'delete');
            await this.loadFiles(this.currentPage);
        },

        /**
         * Retirer des Articles du Dossier — ils ne sont pas supprimes, d'ou un
         * verbe different de celui des fichiers et aucune confirmation : le
         * geste se refait en un rattachement.
         */
        async retirerLot() {
            const lot = this.selectionElements.filter(i => i.type === 'article');
            if (!lot.length) return;

            this.saving = true;
            const echecs = [];
            let reussis = 0;

            for (const item of lot) {
                try {
                    const reponse = await fetch(`/org/${this.orgParam}/dossiers/${this.dossierId}/articles/${item.id}`, {
                        method: 'DELETE',
                        headers: {
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': this.csrfToken,
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                    });
                    const data = await reponse.json().catch(() => ({}));

                    if (reponse.ok) reussis++;
                    else echecs.push({ name: item.name, reason: data.message || Object.values(data.errors || {}).flat()[0] || this.i18n.deleteFailed });
                } catch (error) {
                    echecs.push({ name: item.name, reason: this.i18n.deleteFailed });
                }
            }

            this.saving = false;
            this.viderSelection();

            if (echecs.length) {
                this.rapporterLot(reussis, lot.length, echecs, 'delete');

                return;
            }

            // Les lignes d'Article sont rendues par le serveur.
            window.location.reload();
        },

        /**
         * Dire ce qui s'est VRAIMENT passe : « 5 sur 6 » et la liste des refus,
         * jamais un « fait » qui recouvrirait un echec. Le lot sans faute se
         * contente du bandeau habituel.
         */
        rapporterLot(reussis, total, echecs, action, cibleId = null) {
            if (!echecs.length) {
                if (!reussis) return;
                const cible = cibleId ? this.moveTargets.find(t => t.id === cibleId) : null;
                const message = action === 'move'
                    ? (total === 1 && cible?.name && this.i18n.movedTo
                        ? this.i18n.movedTo.replace(':name', cible.name)
                        : (this.i18n.lotMoved || '').replace(':count', String(reussis)).replace(':name', cible?.name || ''))
                    : (total === 1 ? this.i18n.deleted : (this.i18n.lotDeleted || '').replace(':count', String(reussis)));
                this.showMessage(message || this.i18n.moved, 'success');

                return;
            }

            this.lotRapport = {
                titre: action === 'move' ? this.i18n.lotMoveReportTitle : this.i18n.lotDeleteReportTitle,
                resume: (this.i18n.lotReportSummary || ':done/:total')
                    .replace(':done', String(reussis))
                    .replace(':total', String(total)),
                echecs,
            };
            this.showLotModal = true;
        },

        openPreview(file) {
            this._trapTrigger = document.activeElement;
            this.previewFile = file;
            this.showPreviewModal = true;
            this.$nextTick(() => { this._activateFocusTrap('[aria-labelledby="preview-title"]'); });
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

        /** Nom de la Boucle liee, pour le badge de la barre. */
        publishBadge() {
            const store = window.Alpine?.store('editorPanels');
            if (!store) return;
            const first = this.linkedLoops?.[0];
            store.loopName = first
                ? (this.linkedLoops.length > 1 ? `${first.name} +${this.linkedLoops.length - 1}` : first.name)
                : null;
        },

        i18n: config.i18n || {},
        messageDrafts: {},
        sendingMessage: '',
        _pollInterval: null,
        _fingerprint: '',

        init() {
            // Le badge de la barre est alimente des l'ouverture de la page, puis
            // a chaque liaison ou deliaison.
            this.publishBadge();
            this.$watch('linkedLoops', () => this.publishBadge());
        },

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
        // TASK-1249 : methode de facilitation de Roger, portee par LA
        // CONVERSATION (etat du composant, envoye avec chaque message ;
        // remis a zero a chaque ouverture = nouvelle conversation).
        methods: Array.isArray(config.methods) ? config.methods : [],
        methodCode: null,
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
        // TASK-1256 : feedback humain « Utile / A ameliorer » sur une reponse.
        feedbackUrl: config.feedbackUrl || null,
        csrfToken: config.csrfToken,
        i18n: config.i18n || {},

        init() {
            window.addEventListener('open-explorer', (event) => {
                this.open = true;
                const detail = event.detail || {};
                const unavailable = detail.hasSavedArticle === false || detail.hasUnsavedChanges === true;
                this.phase = unavailable ? 'unavailable' : 'dialogue';
                this.dialogueCount = 0;
                this.methodCode = null;
                this.noteContent = '';
                this.noteTooLong = false;
                this.error = '';
                this.success = '';
                if (!unavailable) {
                    this.$nextTick(() => this.setupDeepChat());
                }
            });
        },

        get activeMethod() {
            return this.methods.find((m) => m.key === this.methodCode) || null;
        },

        get activeMethodLabel() {
            const m = this.activeMethod;
            if (!m) return '';
            return (this.i18n.methodActive || ':method — :hint')
                .replace(':method', m.label || m.key)
                .replace(':hint', m.hint || '');
        },

        // Un clic selectionne la methode de la conversation ; un second clic
        // sur la methode active revient au questionnement libre.
        selectMethod(key) {
            this.methodCode = this.methodCode === key ? null : key;
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
                        method_code: this.methodCode,
                    },
                    headers: details.headers,
                };
            };

            dc.responseInterceptor = (response) => {
                if (response && response.error) {
                    throw new Error(response.error);
                }
                const text = response?.text || '';
                // TASK-1256 : la reponse porte l'id de sa trace
                // (`ai_interaction_id`) ; le bloc de feedback s'affiche SOUS la
                // bulle (html du meme message deep-chat, texte conserve pour
                // l'historique) et le reference. Sans id (article non
                // sauvegarde…) : bulle texte seule, comme avant.
                const interactionId = this.feedbackInteractionId(response);
                if (!interactionId || !this.feedbackUrl) {
                    return { text };
                }
                return {
                    text,
                    html: this.feedbackHtml(interactionId),
                    custom: { ai_interaction_id: interactionId },
                };
            };

            // TASK-1256 : les boutons du bloc de feedback vivent dans le shadow
            // DOM de deep-chat ; c'est son mecanisme officiel pour les relier.
            dc.htmlClassUtilities = {
                'bp-fb-verdict': { events: { click: (event) => this.onFeedbackVerdict(event) } },
                'bp-fb-send': { events: { click: (event) => this.onFeedbackSend(event) } },
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
                // TASK-1256 : le bloc de feedback (message html) n'est pas une
                // bulle : transparent, colle sous la bulle texte.
                html: {
                    shared: {
                        bubble: {
                            backgroundColor: 'transparent',
                            padding: '0',
                            marginTop: '-4px',
                            maxWidth: '78%',
                            color: muted,
                        },
                    },
                },
            };

            const fbAccent = dark ? '#c4b5fd' : '#6d28d9';
            const fbAccentBg = dark ? 'rgba(124, 58, 237, 0.18)' : '#f5f3ff';
            dc.auxiliaryStyle = `
                ::-webkit-scrollbar { width: 10px; }
                ::-webkit-scrollbar-track { background: ${surface}; }
                ::-webkit-scrollbar-thumb { background: ${dark ? '#4b5563' : '#cbd5e1'}; border-radius: 999px; border: 2px solid ${surface}; }
                ::-webkit-scrollbar-thumb:hover { background: ${dark ? '#6b7280' : '#94a3b8'}; }
                .bp-fb { font-size: 12px; line-height: 1.4; color: ${muted}; padding: 2px 4px 6px; }
                .bp-fb-row { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; }
                .bp-fb-q { margin-right: 2px; }
                .bp-fb-btn { font: inherit; font-size: 12px; line-height: 1; cursor: pointer; border: 1px solid ${border}; border-radius: 999px; padding: 5px 10px; background: ${surface}; color: ${text}; transition: border-color .15s, background-color .15s, color .15s; }
                .bp-fb-btn:hover { border-color: ${fbAccent}; color: ${fbAccent}; }
                .bp-fb-btn:disabled { cursor: default; opacity: .6; }
                .bp-fb-btn[aria-pressed="true"] { border-color: ${fbAccent}; background: ${fbAccentBg}; color: ${fbAccent}; font-weight: 600; }
                .bp-fb-status { color: ${fbAccent}; }
                .bp-fb-status[data-kind="error"] { color: ${dark ? '#fca5a5' : '#b91c1c'}; }
                .bp-fb-form { margin-top: 8px; display: grid; gap: 6px; }
                .bp-fb-form[hidden] { display: none; }
                .bp-fb-hint { color: ${muted}; }
                .bp-fb-label { display: block; color: ${text}; font-weight: 500; margin-bottom: 2px; }
                .bp-fb-input { font: inherit; font-size: 12px; line-height: 1.4; width: 100%; box-sizing: border-box; min-height: 48px; resize: vertical; border: 1px solid ${border}; border-radius: 8px; padding: 6px 8px; background: ${surfaceSoft}; color: ${text}; }
                .bp-fb-input:focus { outline: none; border-color: #8b5cf6; }
                .bp-fb-actions { display: flex; align-items: center; gap: 8px; }
                .bp-fb-send { font: inherit; font-size: 12px; line-height: 1; cursor: pointer; border: 0; border-radius: 8px; padding: 6px 12px; background: ${dark ? '#6d28d9' : '#7c3aed'}; color: #fff; font-weight: 600; }
                .bp-fb-send:hover { background: ${dark ? '#7c3aed' : '#6d28d9'}; }
                .bp-fb-send:disabled { opacity: .6; cursor: default; }
            `;
        },

        // ------------------------------------------------------------------
        // TASK-1256 : feedback humain sur une reponse (Utile / A ameliorer,
        // puis disclosure facultative : pourquoi / quoi ameliorer / quelle
        // meilleure intervention). Un clic enregistre le verdict tout de
        // suite ; le formulaire, optionnel, complete la MEME ligne.
        // ------------------------------------------------------------------
        feedbackInteractionId(response) {
            const id = response && typeof response.ai_interaction_id === 'string' ? response.ai_interaction_id : '';
            return /^[0-9a-fA-F-]{36}$/.test(id) ? id : null;
        },

        feedbackEscape(value) {
            return String(value == null ? '' : value)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        },

        feedbackHtml(interactionId) {
            const t = (key, fallback) => this.feedbackEscape(this.i18n[key] || fallback);
            return `
<div class="bp-fb" data-interaction-id="${this.feedbackEscape(interactionId)}" data-verdict="">
  <div class="bp-fb-row">
    <span class="bp-fb-q">${t('feedbackQuestion', 'Cette intervention vous a-t-elle aidé ?')}</span>
    <button type="button" class="bp-fb-btn bp-fb-verdict" data-verdict="helpful" aria-pressed="false">${t('feedbackHelpful', 'Utile')}</button>
    <button type="button" class="bp-fb-btn bp-fb-verdict" data-verdict="improve" aria-pressed="false">${t('feedbackImprove', 'À améliorer')}</button>
    <span class="bp-fb-status" aria-live="polite"></span>
  </div>
  <div class="bp-fb-form" hidden>
    <span class="bp-fb-hint">${t('feedbackDetailsHint', 'Facultatif : dites-nous en plus.')}</span>
    <label>
      <span class="bp-fb-label">${t('feedbackCommentLabel', 'Pourquoi ? Que faudrait-il améliorer ?')}</span>
      <textarea class="bp-fb-input bp-fb-comment" rows="2" maxlength="2000" placeholder="${t('feedbackCommentPlaceholder', '')}"></textarea>
    </label>
    <label>
      <span class="bp-fb-label">${t('feedbackSuggestLabel', 'Quelle aurait été une meilleure intervention ?')}</span>
      <textarea class="bp-fb-input bp-fb-suggest" rows="3" maxlength="6000" placeholder="${t('feedbackSuggestPlaceholder', '')}"></textarea>
    </label>
    <div class="bp-fb-actions">
      <button type="button" class="bp-fb-send">${t('feedbackSend', 'Envoyer')}</button>
    </div>
  </div>
</div>`;
        },

        feedbackRoot(event) {
            const el = event && (event.currentTarget || event.target);
            return el && el.closest ? el.closest('.bp-fb') : null;
        },

        feedbackSetStatus(root, message, kind) {
            const status = root.querySelector('.bp-fb-status');
            if (!status) return;
            status.textContent = message || '';
            if (kind) {
                status.setAttribute('data-kind', kind);
            } else {
                status.removeAttribute('data-kind');
            }
        },

        async onFeedbackVerdict(event) {
            const root = this.feedbackRoot(event);
            const button = event.currentTarget || event.target;
            if (!root || !button) return;
            const verdict = button.getAttribute('data-verdict');
            if (verdict !== 'helpful' && verdict !== 'improve') return;

            root.setAttribute('data-verdict', verdict);
            root.querySelectorAll('.bp-fb-verdict').forEach((b) => {
                b.setAttribute('aria-pressed', b === button ? 'true' : 'false');
            });

            const ok = await this.postFeedback(root, verdict, false);
            if (ok) {
                const form = root.querySelector('.bp-fb-form');
                if (form) form.hidden = false;
            }
        },

        async onFeedbackSend(event) {
            const root = this.feedbackRoot(event);
            if (!root) return;
            const verdict = root.getAttribute('data-verdict');
            if (verdict !== 'helpful' && verdict !== 'improve') return;
            await this.postFeedback(root, verdict, true);
        },

        async postFeedback(root, verdict, withDetails) {
            const interactionId = root.getAttribute('data-interaction-id');
            if (!interactionId || !this.feedbackUrl) return false;

            const comment = (root.querySelector('.bp-fb-comment')?.value || '').trim();
            const suggested = (root.querySelector('.bp-fb-suggest')?.value || '').trim();
            const sendButton = root.querySelector('.bp-fb-send');
            const verdictButtons = root.querySelectorAll('.bp-fb-verdict');

            verdictButtons.forEach((b) => { b.disabled = true; });
            if (sendButton) {
                sendButton.disabled = true;
                if (withDetails) sendButton.textContent = this.i18n.feedbackSending || 'Envoi…';
            }
            this.feedbackSetStatus(root, '', null);

            try {
                const response = await fetch(this.feedbackUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': this.csrfToken,
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify({
                        ai_interaction_id: interactionId,
                        verdict,
                        comment: comment || null,
                        suggested_response: suggested || null,
                    }),
                });

                if (!response.ok) {
                    const data = await response.json().catch(() => ({}));
                    this.feedbackSetStatus(root, data.message || this.i18n.feedbackError || 'Erreur.', 'error');
                    return false;
                }

                this.feedbackSetStatus(
                    root,
                    withDetails
                        ? (this.i18n.feedbackDetailsSaved || 'Merci, vos précisions sont enregistrées.')
                        : (this.i18n.feedbackSaved || 'Merci, c’est noté.'),
                    'ok',
                );
                return true;
            } catch (_) {
                this.feedbackSetStatus(root, this.i18n.feedbackError || 'Erreur de connexion.', 'error');
                return false;
            } finally {
                verdictButtons.forEach((b) => { b.disabled = false; });
                if (sendButton) {
                    sendButton.disabled = false;
                    sendButton.textContent = this.i18n.feedbackSend || 'Envoyer';
                }
            }
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

/**
 * Which editor panel is open, if any.
 *
 * Four sidebar cards — Boucle, Dossier, Liste de taches, Co-ecriture — moved out
 * of the stacked column and into a button bar above the article. They kept
 * their own Alpine state entirely: only their container changed, from a
 * collapsible box to a modal. That is why this store holds nothing but the
 * name of the open panel, plus the labels the badges need.
 *
 * The badges are published by the cards themselves, because only they know
 * whether a link exists and what it is called.
 */
document.addEventListener('alpine:init', () => {
    window.Alpine.store('editorPanels', {
        open: null,

        // Filled by the cards. Null means "no link", and the bar shows no badge.
        loopName: null,
        dossierName: null,
        dossierUrl: null,
        inSeries: false,
        seriesIsRoot: false,

        toggle(name) {
            this.open = this.open === name ? null : name;
        },

        close() {
            this.open = null;
        },

        isOpen(name) {
            return this.open === name;
        },
    });
});

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
    registerDossierInsights();
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
    registerDossierSeriesReorder();
    registerDossierContentsCard();
    registerDossierSemanticArticleSearch();
    registerDossierInsights();
    registerDossierMembersCard();
    registerDossierFilesCard();
    registerBlogLoopCard();
    registerBlogTodoCard();
registerBlogPlanCard();
registerBlogExplorerModal();
registerBlogExplorerCard();

// -------------------------------------------------------------------------
// Roadmap mini-kanban drag & drop (LoopRoadmapCard, Livewire) — reuses SortableJS.
// Three columns share one group → intra-column reorder AND inter-column moves.
// onEnd bridges to $wire.reorderGroup (same column) or $wire.moveItem (across columns).
// -------------------------------------------------------------------------
function registerRoadmapSortable() {
    if (window.__roadmapSortableRegistered || !window.Livewire) {
        return;
    }
    window.__roadmapSortableRegistered = true;

    const collect = (ul) => Array.from(ul.querySelectorAll('[data-roadmap-id]'))
        .map((el) => el.getAttribute('data-roadmap-id'))
        .filter(Boolean);

    const buildRoot = (root) => {
        if (!root) return;
        const canManage = root.getAttribute('data-roadmap-can-manage') === '1';

        root.querySelectorAll('[data-roadmap-group]').forEach((container) => {
            if (container._sortable) {
                container._sortable.destroy();
                container._sortable = null;
            }
            if (!canManage) return;

            container._sortable = Sortable.create(container, {
                group: { name: 'roadmap-kanban', pull: true, put: true },
                draggable: '[data-roadmap-id]',
                handle: '.drag-handle',
                filter: '[data-no-drag]',
                animation: 150,
                delay: 150,
                delayOnTouchOnly: true,
                ghostClass: 'roadmap-ghost',
                chosenClass: 'roadmap-chosen',
                onEnd: (evt) => {
                    const fromStatus = evt.from.getAttribute('data-status');
                    const toStatus = evt.to.getAttribute('data-status');
                    const itemId = evt.item.getAttribute('data-roadmap-id');
                    const wireId = root.getAttribute('wire:id');
                    const component = wireId ? window.Livewire.find(wireId) : null;
                    if (!component || !itemId) return;

                    if (fromStatus === toStatus) {
                        component.call('reorderGroup', toStatus, collect(evt.to));
                    } else {
                        component.call('moveItem', itemId, fromStatus, toStatus, collect(evt.from), collect(evt.to));
                    }
                },
            });
        });
    };

    const buildAll = () => document.querySelectorAll('[data-roadmap-root]').forEach(buildRoot);

    // Rebuild after each Livewire morph (covers lazy load, add/toggle/edit/delete/reorder).
    window.Livewire.hook('morphed', ({ el }) => {
        if (el?.matches?.('[data-roadmap-root]')) {
            buildRoot(el);
        } else {
            el?.querySelectorAll?.('[data-roadmap-root]').forEach(buildRoot);
        }
    });

    buildAll();
    document.addEventListener('livewire:navigated', buildAll);
}

document.addEventListener('livewire:init', registerRoadmapSortable);

// -------------------------------------------------------------------------
// Roadmap card actions menu — fixed-positioned dropdown that flips up/down so it
// never clips against the panel/column edges. Registered once for all cards.
// -------------------------------------------------------------------------
function registerRoadmapMenu() {
    if (!window.Alpine || window.__roadmapMenuRegistered) {
        return;
    }
    window.__roadmapMenuRegistered = true;

    window.Alpine.data('roadmapMenu', () => ({
        open: false,
        top: 0,
        bottom: 0,
        left: 0,
        placement: 'bottom',
        toggle() { this.open ? this.close() : this.openMenu(); },
        openMenu() {
            const r = this.$refs.btn.getBoundingClientRect();
            const menuW = 208; // w-52
            const menuH = 220;
            const spaceBelow = window.innerHeight - r.bottom;
            this.placement = spaceBelow < menuH ? 'top' : 'bottom';
            this.left = Math.min(Math.max(8, r.right - menuW), window.innerWidth - menuW - 8);
            this.top = r.bottom + 4;
            this.bottom = window.innerHeight - r.top + 4;
            this.open = true;
        },
        close() { this.open = false; },
        get menuStyle() {
            const v = this.placement === 'bottom' ? `top:${this.top}px` : `bottom:${this.bottom}px`;
            return `position:fixed; left:${this.left}px; ${v}; width:13rem;`;
        },
    }));
}

document.addEventListener('alpine:init', registerRoadmapMenu);
registerRoadmapMenu();

// Service Worker registration
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js');
    });
}
