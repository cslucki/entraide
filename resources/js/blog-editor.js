import { Editor } from '@tiptap/core';
import StarterKit from '@tiptap/starter-kit';
import Link from '@tiptap/extension-link';
import Image from '@tiptap/extension-image';
import { Table, TableRow, TableCell, TableHeader } from '@tiptap/extension-table';
import Placeholder from '@tiptap/extension-placeholder';
import Underline from '@tiptap/extension-underline';

const editors = new WeakMap();

export function createEditor(element, { content = '', onUpdate = null, placeholder = 'Rédigez votre article…' } = {}) {
    if (editors.has(element)) {
        editors.get(element).destroy();
    }

    const editor = new Editor({
        element,
        extensions: [
            StarterKit.configure({
                heading: { levels: [2, 3] },
                codeBlock: false,
                link: false,
                underline: false,
            }),
            Link.configure({
                openOnClick: false,
                HTMLAttributes: { rel: 'noopener noreferrer', target: '_blank' },
            }),
            Image.configure({
                inline: false,
                allowBase64: false,
            }),
            Table.configure({
                resizable: true,
            }),
            TableRow,
            TableCell,
            TableHeader,
            Placeholder.configure({ placeholder }),
            Underline,
        ],
        content,
        onUpdate: ({ editor: ed }) => {
            if (onUpdate) {
                onUpdate(ed.getHTML());
            }
        },
    });

    editors.set(element, editor);

    return editor;
}

export function getEditor(element) {
    return editors.get(element) || null;
}

export function destroyEditor(element) {
    if (editors.has(element)) {
        editors.get(element).destroy();
        editors.delete(element);
    }
}
