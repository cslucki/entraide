import { Editor } from '@tiptap/core';
import StarterKit from '@tiptap/starter-kit';
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
        youtube: container.dataset.i18nYoutube || 'YouTube video',
        toolbar: container.dataset.i18nToolbar || 'Formatting toolbar',
        urlPrompt: container.dataset.i18nUrlPrompt || 'URL',
        youtubePrompt: container.dataset.i18nYoutubePrompt || 'YouTube video URL',
    };
}

/**
 * TASK-1788 correctif 12 (demande Cyril) — un extracteur DEDIE, et non la
 * fonction `extractEmbedUrl` du Flux/Blog : ce bouton n'insere QUE des videos
 * YouTube, et une fonction qui accepte aussi Vimeo/Dailymotion laisserait
 * passer une URL que cette barre n'a jamais promis de savoir integrer.
 *
 * Rend l'identifiant seul (11 caracteres), jamais une URL reconstruite a la
 * main : c'est aussi ce que le cote serveur revalide avant de construire
 * l'iframe — voir `App\Support\Markdown\YoutubeEmbedExtension`.
 */
function extractYoutubeVideoId(url) {
    const trimmed = (url || '').trim();
    if (!trimmed) return null;

    let parsed;
    try {
        parsed = new URL(trimmed);
    } catch {
        return null;
    }

    const host = parsed.hostname.toLowerCase();
    let id = null;

    if (host === 'youtu.be') {
        id = parsed.pathname.split('/').filter(Boolean)[0] || null;
    } else if (host === 'youtube.com' || host === 'www.youtube.com'
        || host === 'youtube-nocookie.com' || host === 'www.youtube-nocookie.com') {
        if (parsed.pathname.startsWith('/embed/')) {
            id = parsed.pathname.slice('/embed/'.length);
        } else {
            id = parsed.searchParams.get('v');
        }
    } else {
        return null;
    }

    return id && /^[A-Za-z0-9_-]{11}$/.test(id) ? id : null;
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

    // TASK-1788 correctif 12 (demande Cyril) — `dark:text-gray-300`, pas
    // `dark:text-gray-400` : les boutons etaient juges « trop gris fonce ».
    const stateClass = () =>
        editor.isActive(type, attrs)
            ? 'bg-gray-200 dark:bg-gray-700 text-indigo-600 dark:text-indigo-400'
            : 'text-gray-600 dark:text-gray-300';

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
    } else if (type === 'youtube') {
        inner = `<svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.75 10.5l4.72-2.364A1 1 0 0122 9v6a1 1 0 01-1.53.864L15.75 13.5m-11.5 3.5h9A2.25 2.25 0 0015.5 14.75v-5.5A2.25 2.25 0 0013.25 7h-9A2.25 2.25 0 002 9.25v5.5A2.25 2.25 0 004.25 17z"/></svg>`;
    } else {
        inner = type;
    }

    // TASK-1788 correctif 12 (demande Cyril) — le premier rendu applique
    // DEJA `stateClass()`, pas seulement `baseClass`. `baseClass` seul ne
    // porte AUCUNE couleur : avant le premier `selectionUpdate`/
    // `transaction`, l'icone heritait de `currentColor` de l'ancetre — dans
    // l'overlay plein ecran (fond force en sombre, couleur jamais posee),
    // l'icone en heritait une sombre et devenait invisible. « comme s'il
    // etait devenu noire. »
    btn.innerHTML = `<span class="${baseClass} ${stateClass()}">${inner}</span>`;

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
        } else if (type === 'youtube') {
            const url = window.prompt(i18n.youtubePrompt, 'https://www.youtube.com/watch?v=');
            if (url === null || url === '') return;

            const videoId = extractYoutubeVideoId(url);
            if (!videoId) return;

            // TASK-1788 correctif 12 — un lien NU (texte = href), pas un noeud
            // dedie : cet editeur serialise en Markdown
            // (`contentType: 'markdown'`), et la marque `link` deja configuree
            // sait deja aller et venir sans aucun serialiseur Markdown
            // supplementaire. Le cote serveur (`YoutubeEmbedExtension`)
            // reconnait ce MEME motif — un paragraphe dont le seul contenu est
            // un lien « nu » vers YouTube — et le transforme en lecteur
            // integre au rendu. Normalisee en URL `watch` : portable si copiee
            // ailleurs, contrairement a une URL `/embed/`.
            const watchUrl = `https://www.youtube.com/watch?v=${videoId}`;
            editor.chain().focus().insertContent({
                type: 'paragraph',
                content: [{
                    type: 'text',
                    text: watchUrl,
                    marks: [{ type: 'link', attrs: { href: watchUrl } }],
                }],
            }).run();
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

    const types = ['undo', 'redo', 'bold', 'link', 'h2', 'h3', 'bulletList', 'youtube'];
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
                link: {
                    openOnClick: false,
                    protocols: ['mailto'],
                    isAllowedUri: (url, ctx) => ctx.defaultValidate(url) && isValidUri(url),
                    HTMLAttributes: { rel: 'noopener noreferrer', target: '_blank' },
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

// On-demand init for editors injected after load (e.g. inside a Livewire modal).
// setupEditor() guards already-initialized containers via data-tiptap-initialized.
document.addEventListener('bp:markdown-editor:init', () => initAll());

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initAll);
} else {
    initAll();
}

export { setupEditor, ACTIVE_EDITORS };
