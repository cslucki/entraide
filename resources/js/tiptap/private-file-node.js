import { Node } from '@tiptap/core';

/**
 * TASK-1907 — fichier PRIVE d'article (PDF ou audio).
 *
 * Le HTML enregistre ne porte que le type, l'identifiant et le nom :
 *   <figure data-bp-file="pdf|audio" data-bp-file-id="uuid" data-bp-file-name="…">
 * Aucune URL n'est stockee : a l'affichage, le serveur construit le lecteur
 * (BlogPostFileRenderer) apres avoir verifie que le fichier est rattache a
 * l'article. Dans l'editeur, l'apercu passe par la route gardee.
 */
const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;
const KINDS = ['pdf', 'audio'];

const PrivateFile = Node.create({
    name: 'bpFile',
    group: 'block',
    atom: true,
    draggable: true,
    selectable: true,

    addOptions() {
        return {
            // (id) => URL de lecture gardee, fournie par l'editeur.
            fileUrl: () => '',
            labels: { pdf: 'PDF', audio: 'Audio', open: 'Ouvrir' },
        };
    },

    addAttributes() {
        return {
            kind: {
                default: null,
                parseHTML: el => el.getAttribute('data-bp-file'),
                renderHTML: attrs => ({ 'data-bp-file': attrs.kind }),
            },
            id: {
                default: null,
                parseHTML: el => el.getAttribute('data-bp-file-id'),
                renderHTML: attrs => ({ 'data-bp-file-id': attrs.id }),
            },
            name: {
                default: '',
                parseHTML: el => el.getAttribute('data-bp-file-name') || '',
                renderHTML: attrs => (attrs.name ? { 'data-bp-file-name': attrs.name } : {}),
            },
        };
    },

    parseHTML() {
        return [{
            tag: 'figure[data-bp-file]',
            getAttrs: el => (KINDS.includes(el.getAttribute('data-bp-file')) && UUID.test(el.getAttribute('data-bp-file-id') || '')) ? null : false,
        }];
    },

    renderHTML({ HTMLAttributes }) {
        return ['figure', HTMLAttributes];
    },

    addNodeView() {
        return ({ node }) => {
            const { kind, id, name } = node.attrs;
            const labels = this.options.labels;
            const url = this.options.fileUrl(id);

            const dom = document.createElement('figure');
            dom.className = 'bp-file-editor';
            dom.setAttribute('data-bp-file', kind);
            dom.contentEditable = 'false';

            const head = document.createElement('div');
            head.className = 'bp-file-editor-head';

            const badge = document.createElement('span');
            badge.className = 'bp-file-editor-badge';
            badge.textContent = kind === 'audio' ? labels.audio : labels.pdf;

            const title = document.createElement('span');
            title.className = 'bp-file-editor-name';
            title.textContent = name || id;

            head.append(badge, title);
            dom.append(head);

            if (url && kind === 'audio') {
                const audio = document.createElement('audio');
                audio.controls = true;
                audio.preload = 'none';
                audio.src = url;
                dom.append(audio);
            } else if (url) {
                const link = document.createElement('a');
                link.href = url;
                link.target = '_blank';
                link.rel = 'noopener';
                link.textContent = labels.open;
                head.append(link);
            }

            return { dom };
        };
    },

    addCommands() {
        return {
            // Un bloc (fichier, image, video) selectionne n'est pas remplace :
            // le fichier s'insere juste apres lui. Deux depots a la suite
            // gardent ainsi les deux fichiers.
            insertPrivateFile: (attrs) => ({ state, commands }) => {
                const content = { type: this.name, attrs };
                const { selection } = state;

                return selection.node
                    ? commands.insertContentAt(selection.to, content)
                    : commands.insertContent(content);
            },
        };
    },
});

export { PrivateFile };
