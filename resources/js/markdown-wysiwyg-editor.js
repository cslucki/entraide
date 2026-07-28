import { Editor } from '@tiptap/core';
import StarterKit from '@tiptap/starter-kit';
import LinkExtension from '@tiptap/extension-link';
import { Markdown } from '@tiptap/markdown';

const ACTIVE_EDITORS = new WeakMap();

function resolveI18n(container) {
    return {
        undo: container.dataset.i18nUndo || 'Undo',
        redo: container.dataset.i18nRedo || 'Redo',
        bold: container.dataset.i18nBold || 'Bold',
        link: container.dataset.i18nLink || 'Link',
        h2: container.dataset.i18nH2 || 'Heading 2',
        h3: container.dataset.i18nH3 || 'Heading 3',
        bulletList: container.dataset.i18nBulletList || 'Bullet list',
        toolbar: container.dataset.i18nToolbar || 'Formatting toolbar',
        urlPrompt: container.dataset.i18nUrlPrompt || 'URL',
    };
}

function isValidUri(uri) {
    if (!uri) return false;
    const allowed = ['https:', 'http:', 'mailto:'];
    try {
        const parsed = new URL(uri);
        return allowed.includes(parsed.protocol);
    } catch {
        return false;
    }
}

function createToolbarItem(editor, type, i18n, attrs = {}) {
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.dataset.markdownTool = type;
    btn.title = i18n[type];
    btn.setAttribute('aria-label', i18n[type]);

    const stateClass = () =>
        editor.isActive(type, attrs)
            ? 'bg-gray-200 dark:bg-gray-700 text-indigo-600 dark:text-indigo-400'
            : 'text-gray-600 dark:text-gray-400';

    const baseClass = 'flex items-center justify-center w-7 h-7 rounded hover:bg-gray-200 dark:hover:bg-gray-700 transition-colors';

    let inner;
    if (type === 'undo') {
        inner = `<svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h10a5 5 0 015 5v2M3 10l4-4M3 10l4 4"/></svg>`;
    } else if (type === 'redo') {
        inner = `<svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 10H11a5 5 0 00-5 5v2m15-7l-4-4m4 4l-4 4"/></svg>`;
    } else if (type === 'bold') {
        inner = '<strong class="text-xs">B</strong>';
    } else if (type === 'link') {
        inner = `<svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/></svg>`;
    } else if (type === 'h2') {
        inner = '<span class="text-xs font-semibold">H2</span>';
    } else if (type === 'h3') {
        inner = '<span class="text-xs font-semibold">H3</span>';
    } else if (type === 'bulletList') {
        inner = `<svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16"/></svg>`;
    } else {
        inner = type;
    }

    btn.innerHTML = `<span class="${baseClass}">${inner}</span>`;

    btn.addEventListener('mousedown', (e) => e.preventDefault());

    btn.addEventListener('click', () => {
        if (type === 'undo') editor.chain().focus().undo().run();
        else if (type === 'redo') editor.chain().focus().redo().run();
        else if (type === 'bold') editor.chain().focus().toggleBold().run();
        else if (type === 'h2') editor.chain().focus().toggleHeading({ level: 2 }).run();
        else if (type === 'h3') editor.chain().focus().toggleHeading({ level: 3 }).run();
        else if (type === 'bulletList') editor.chain().focus().toggleBulletList().run();
        else if (type === 'link') {
            const prev = editor.getAttributes('link').href;
            const url = window.prompt(i18n.urlPrompt, prev || 'https://');
            if (url === null) return;
            if (url === '') {
                editor.chain().focus().extendMarkRange('link').unsetLink().run();
                return;
            }
            if (!isValidUri(url)) return;
            editor.chain().focus().extendMarkRange('link').setLink({ href: url }).run();
        }
    });

    const updateActiveState = () => {
        const span = btn.querySelector('span');
        if (!span) return;
        span.className = `${baseClass} ${stateClass()}`;
    };

    editor.on('selectionUpdate', updateActiveState);
    editor.on('transaction', updateActiveState);

    return btn;
}

function buildToolbar(editor, container) {
    const toolbar = document.createElement('div');
    toolbar.className = 'flex flex-wrap items-center gap-0.5 p-1 border border-gray-300 dark:border-gray-600 rounded-t-lg bg-gray-50 dark:bg-gray-800/50';
    toolbar.setAttribute('role', 'toolbar');
    toolbar.setAttribute('aria-label', resolveI18n(container).toolbar);

    const types = ['undo', 'redo', 'bold', 'link', 'h2', 'h3', 'bulletList'];
    const i18n = resolveI18n(container);
    types.forEach((type) => toolbar.appendChild(createToolbarItem(editor, type, i18n)));

    container.insertBefore(toolbar, container.firstChild);
    return toolbar;
}

function setupEditor(container) {
    if (ACTIVE_EDITORS.has(container)) {
        return ACTIVE_EDITORS.get(container);
    }

    const textarea = container.querySelector('textarea[data-tiptap-target]');
    if (!textarea) return null;

    const initialContent = textarea.value || '';

    const editorDiv = document.createElement('div');
    editorDiv.className = 'ProseMirror-wrapper';
    container.appendChild(editorDiv);

    const editor = new Editor({
        element: editorDiv,
        extensions: [
            StarterKit.configure({
                heading: { levels: [2, 3] },
                bulletList: true,
                orderedList: false,
                codeBlock: false,
                blockquote: false,
                horizontalRule: false,
                strike: false,
                italic: false,
                code: false,
            }),
            LinkExtension.configure({
                openOnClick: false,
                HTMLAttributes: { rel: 'noopener noreferrer', target: '_blank' },
                validate(url) {
                    return isValidUri(url);
                },
            }),
            Markdown.configure({
                indentation: { style: 'space', size: 2 },
            }),
        ],
        content: initialContent,
        contentType: 'markdown',
        onUpdate: () => {
            const md = editor.getMarkdown();
            textarea.value = md;
            textarea.dispatchEvent(new Event('input', { bubbles: true }));
        },
    });

    buildToolbar(editor, container);

    textarea.classList.add('hidden');
    textarea.setAttribute('data-tiptap-initialized', 'true');

    ACTIVE_EDITORS.set(container, editor);
    container._tiptapEditor = editor;

    return editor;
}

function handleSetContent(event) {
    const { name, markdown } = event.detail || {};
    if (!name || markdown === undefined) return;

    const textarea = document.querySelector(`textarea[name="${name}"][data-tiptap-target]`);
    const container = textarea?.closest('[data-tiptap-container]');

    if (container && container._tiptapEditor) {
        container._tiptapEditor.commands.setContent(markdown, { contentType: 'markdown' });
    } else if (textarea) {
        textarea.value = markdown;
        textarea.dispatchEvent(new Event('input', { bubbles: true }));
    }
}

function initAll() {
    document.querySelectorAll('[data-tiptap-container]').forEach((container) => {
        try {
            setupEditor(container);
        } catch (e) {
            console.warn('Tiptap Markdown editor initialization failed, falling back to textarea', e);
        }
    });
}

document.addEventListener('bp:markdown-editor:set-content', handleSetContent);

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initAll);
} else {
    initAll();
}

export { setupEditor, ACTIVE_EDITORS };
