import { Editor } from '@tiptap/core';
import StarterKit from '@tiptap/starter-kit';
import LinkExtension from '@tiptap/extension-link';
import { Markdown } from '@tiptap/markdown';

const ACTIVE_EDITORS = new WeakMap();

function createToolbarItem(editor, type, attrs = {}) {
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.dataset.markdownTool = type;

    const labels = {
        undo: { title: 'Undo', icon: '<svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h10a5 5 0 015 5v2M3 10l4-4M3 10l4 4"/></svg>' },
        redo: { title: 'Redo', icon: '<svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 10H11a5 5 0 00-5 5v2m15-7l-4-4m4 4l-4 4"/></svg>' },
        bold: { title: 'Bold', icon: '<strong class="text-xs">B</strong>' },
        link: { title: 'Link', icon: '<svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/></svg>' },
        h2: { title: 'Heading 2', label: 'H2' },
        h3: { title: 'Heading 3', label: 'H3' },
        bulletList: { title: 'Bullet list', icon: '<svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16"/></svg>' },
    };

    const cfg = labels[type];

    btn.title = cfg.title;
    btn.setAttribute('aria-label', cfg.title);

    if (cfg.icon) {
        btn.innerHTML = `<span class="flex items-center justify-center w-7 h-7 rounded hover:bg-gray-200 dark:hover:bg-gray-700 transition-colors ${editor.isActive(type, attrs) ? 'bg-gray-200 dark:bg-gray-700 text-indigo-600 dark:text-indigo-400' : 'text-gray-600 dark:text-gray-400'}">${cfg.icon}</span>`;
    } else {
        btn.innerHTML = `<span class="flex items-center justify-center min-w-[28px] h-7 px-1.5 rounded text-xs font-semibold hover:bg-gray-200 dark:hover:bg-gray-700 transition-colors ${editor.isActive(type, attrs) ? 'bg-gray-200 dark:bg-gray-700 text-indigo-600 dark:text-indigo-400' : 'text-gray-600 dark:text-gray-400'}">${cfg.label}</span>`;
    }

    btn.addEventListener('mousedown', (e) => {
        e.preventDefault();
    });

    btn.addEventListener('click', () => {
        if (type === 'undo') editor.chain().focus().undo().run();
        else if (type === 'redo') editor.chain().focus().redo().run();
        else if (type === 'bold') editor.chain().focus().toggleBold().run();
        else if (type === 'h2') editor.chain().focus().toggleHeading({ level: 2 }).run();
        else if (type === 'h3') editor.chain().focus().toggleHeading({ level: 3 }).run();
        else if (type === 'bulletList') editor.chain().focus().toggleBulletList().run();
        else if (type === 'link') {
            const prev = editor.getAttributes('link').href;
            const url = window.prompt('URL', prev || 'https://');
            if (url === null) return;
            if (url === '') {
                editor.chain().focus().extendMarkRange('link').unsetLink().run();
                return;
            }
            editor.chain().focus().extendMarkRange('link').setLink({ href: url }).run();
        }
    });

    const updateActiveState = () => {
        const span = btn.querySelector('span');
        if (!span) return;
        const isActive = editor.isActive(type, attrs);
        if (isActive) {
            span.classList.add('bg-gray-200', 'dark:bg-gray-700', 'text-indigo-600', 'dark:text-indigo-400');
        } else {
            span.classList.remove('bg-gray-200', 'dark:bg-gray-700', 'text-indigo-600', 'dark:text-indigo-400');
        }
    };

    editor.on('selectionUpdate', updateActiveState);
    editor.on('transaction', updateActiveState);

    return btn;
}

function buildToolbar(editor, container) {
    const toolbar = document.createElement('div');
    toolbar.className = 'flex flex-wrap items-center gap-0.5 p-1 border border-gray-300 dark:border-gray-600 rounded-t-lg bg-gray-50 dark:bg-gray-800/50';
    toolbar.setAttribute('role', 'toolbar');
    toolbar.setAttribute('aria-label', 'Formatting toolbar');

    ['undo', 'redo', 'bold', 'link', 'h2', 'h3', 'bulletList'].forEach((type) => {
        toolbar.appendChild(createToolbarItem(editor, type));
    });

    container.insertBefore(toolbar, container.firstChild);
    return toolbar;
}

function setupEditor(container) {
    if (ACTIVE_EDITORS.has(container)) {
        ACTIVE_EDITORS.get(container).destroy();
    }

    const textarea = container.querySelector('textarea[data-tiptap-target]');
    if (!textarea) return;

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
                protocols: ['http', 'https', 'mailto'],
                HTMLAttributes: { rel: 'noopener noreferrer', target: '_blank' },
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

    const toolbar = container.querySelector('[role="toolbar"]');
    const updateClasses = () => {
        const hasFocus = editor.isFocused;
        const ringColor = 'border-indigo-500 dark:border-indigo-400';
        const defaultBorder = 'border-gray-300 dark:border-gray-600';

        if (hasFocus) {
            editorDiv.classList.add(...ringColor.split(' '));
            editorDiv.parentElement.querySelector('.ProseMirror-wrapper').classList.add('ring-2', 'ring-indigo-500', 'dark:ring-indigo-400');
        } else {
            editorDiv.parentElement?.querySelector('.ProseMirror-wrapper')?.classList.remove('ring-2', 'ring-indigo-500', 'dark:ring-indigo-400');
        }
    };
    editor.on('focus', updateClasses);
    editor.on('blur', updateClasses);

    ACTIVE_EDITORS.set(container, editor);
    container._tiptapEditor = editor;

    return editor;
}

function handleSetContent(event) {
    const { name, markdown } = event.detail;
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
