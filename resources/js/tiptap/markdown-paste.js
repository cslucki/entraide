/**
 * Pasting a Markdown document into the Article editor.
 *
 * The symptom: a whole Markdown document pasted into the editor arrived as one
 * grey code block. Two causes, depending on where it was copied from.
 *
 *  - From an AI answer or a rendered page, the clipboard carries `text/html`
 *    whose Markdown sits inside a single `<pre><code>`. TipTap parses that
 *    faithfully — as a code block. Faithful, and useless.
 *  - From a `.md` file or a plain textarea, the clipboard carries only
 *    `text/plain`, which ProseMirror inserts verbatim: no headings, no lists.
 *
 * So the editor is given a paste handler that recognises a Markdown *document*
 * and hands it to the Markdown extension, which is already installed and
 * already used by the other editor of this project.
 *
 * The detection is deliberately narrow. A stray asterisk is not Markdown, and
 * turning ordinary prose into headings would be worse than the bug being fixed.
 */

/** Syntaxes that only appear on purpose, weighted by how telling they are. */
const SIGNALS = [
    { re: /^#{1,6}\s+\S/m, weight: 2 },            // # Titre
    { re: /^\s*[-*+]\s+\S/m, weight: 1 },          // - liste
    { re: /^\s*\d+\.\s+\S/m, weight: 1 },          // 1. liste
    { re: /^>\s+\S/m, weight: 1 },                 // > citation
    { re: /^```/m, weight: 2 },                    // bloc clôturé
    { re: /\[[^\]\n]+\]\([^)\s]+\)/, weight: 2 },  // [lien](url)
    { re: /\*\*[^*\n]+\*\*/, weight: 1 },          // **gras**
    { re: /^\s*---\s*$/m, weight: 1 },             // séparateur
];

/**
 * Whether this text is a Markdown *document* rather than prose that happens to
 * contain a symbol.
 *
 * Two distinct signals are required, or one strong signal on a text of some
 * length. A single bold word in a sentence never qualifies.
 */
export function looksLikeMarkdownDocument(text) {
    if (typeof text !== 'string') return false;

    const trimmed = text.trim();
    if (trimmed.length < 12) return false;

    let score = 0;
    let distinct = 0;

    for (const { re, weight } of SIGNALS) {
        if (re.test(trimmed)) {
            score += weight;
            distinct += 1;
        }
    }

    // A real list — two items or more, each on its own line — is Markdown on
    // its own. One stray dash at the start of a sentence is not, which is why
    // the count matters rather than the mere presence.
    const listItems = (trimmed.match(/^\s*(?:[-*+]|\d+\.)\s+\S/gm) || []).length;
    if (listItems >= 2) {
        score += 2;
    }

    // A real code snippet — no prose, mostly symbols and indentation — must not
    // be mistaken for a document. It has structure, but not this kind.
    const lines = trimmed.split('\n');
    const codeish = lines.filter(l => /^\s{4,}\S/.test(l) || /[;{}()=<>]{2,}/.test(l)).length;
    if (lines.length > 2 && codeish / lines.length > 0.6 && !/^#{1,6}\s/m.test(trimmed)) {
        return false;
    }

    return distinct >= 2 || score >= 2;
}

/**
 * The Markdown carried by a clipboard, or null when there is none to speak of.
 *
 * The `text/html` case matters as much as the plain one: that is how a Markdown
 * document arrives wrapped in a single `<pre><code>`, and unwrapping it is the
 * whole point.
 */
export function markdownFromClipboard(clipboardData) {
    if (!clipboardData) return null;

    const plain = clipboardData.getData('text/plain') || '';
    const html = clipboardData.getData('text/html') || '';

    if (html) {
        // Only unwrap when the HTML is *nothing but* one code block. Rich HTML
        // copied from a page stays rich HTML — it is already structured, and
        // re-reading it as Markdown would lose more than it gains.
        const doc = new DOMParser().parseFromString(html, 'text/html');
        const body = doc.body;
        const blocks = body.querySelectorAll('pre');
        const onlyCode = blocks.length === 1 && body.textContent.trim() === blocks[0].textContent.trim();

        if (!onlyCode) return null;

        const inner = blocks[0].textContent || '';

        return looksLikeMarkdownDocument(inner) ? inner : null;
    }

    return looksLikeMarkdownDocument(plain) ? plain : null;
}

/**
 * The paste handler to hand to TipTap's `editorProps`.
 *
 * Returns true only when it has handled the paste itself; anything else falls
 * through to TipTap's own behaviour, untouched.
 */
export function createMarkdownPasteHandler() {
    return (view, event) => {
        // Pasting inside a code block is someone pasting code. Leave it alone.
        const { $from } = view.state.selection;
        for (let depth = $from.depth; depth > 0; depth--) {
            if ($from.node(depth).type.name === 'codeBlock') return false;
        }

        const markdown = markdownFromClipboard(event.clipboardData);
        if (!markdown) return false;

        const editor = view.dom.editor ?? window.__bpActiveEditor;
        if (!editor?.commands?.insertContent) return false;

        event.preventDefault();

        // Through the Markdown extension, which parses and sanitises for us.
        // Never innerHTML, never a hand-rolled parser.
        editor.commands.insertContent(markdown, { contentType: 'markdown' });

        return true;
    };
}
