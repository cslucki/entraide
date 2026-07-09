import { Editor, mergeAttributes, ResizableNodeView } from '@tiptap/core';
import StarterKit from '@tiptap/starter-kit';
import Link from '@tiptap/extension-link';
import Image from '@tiptap/extension-image';
import { Table, TableRow, TableCell, TableHeader } from '@tiptap/extension-table';
import Placeholder from '@tiptap/extension-placeholder';
import Underline from '@tiptap/extension-underline';
import Highlight from '@tiptap/extension-highlight';
import TextAlign from '@tiptap/extension-text-align';
import { TextStyle, Color } from '@tiptap/extension-text-style';
import Annotation from './tiptap/annotation-mark.js';

const editors = new WeakMap();

export function createEditor(element, { content = '', onUpdate = null, placeholder = 'Rédigez votre article…' } = {}) {
    if (editors.has(element)) {
        editors.get(element).destroy();
    }

    const editor = new Editor({
        element,
        extensions: [
            StarterKit.configure({
                heading: { levels: [1, 2, 3, 4] },
                codeBlock: true,
                link: false,
                underline: false,
                horizontalRule: false,
            }),
            Link.configure({
                openOnClick: false,
                HTMLAttributes: { rel: 'noopener noreferrer', target: '_blank' },
            }),
            Image.extend({
                addAttributes() {
                    return {
                        src: { default: null },
                        alt: { default: null },
                        title: { default: null },
                        width: { default: null },
                        height: { default: null },
                        resized: {
                            default: null,
                            parseHTML: element => element.getAttribute('data-resized'),
                            renderHTML: attributes => {
                                if (!attributes.resized) return {};
                                return { 'data-resized': attributes.resized };
                            },
                        },
                    };
                },
                renderHTML({ HTMLAttributes }) {
                    return ['img', mergeAttributes(this.options.HTMLAttributes, HTMLAttributes)];
                },
                parseHTML() {
                    return [{ tag: 'img[src]' }];
                },
                addNodeView() {
                    if (!this.options.resize?.enabled || typeof document === 'undefined') return null;
                    const { directions, minWidth, minHeight, alwaysPreserveAspectRatio } = this.options.resize;
                    return ({ node, getPos, HTMLAttributes, editor }) => {
                        const el = document.createElement('img');
                        el.draggable = false;
                        const merged = mergeAttributes(this.options.HTMLAttributes, HTMLAttributes);
                        Object.entries(merged).forEach(([key, value]) => {
                            if (value == null) return;
                            if (key === 'width' || key === 'height') return;
                            el.setAttribute(key, String(value));
                        });
                        if (merged.src !== null) el.src = merged.src;
                        const nodeView = new ResizableNodeView({
                            element: el,
                            editor,
                            node,
                            getPos,
                            onResize: (width, height) => {
                                el.style.width = `${width}px`;
                                el.style.height = `${height}px`;
                            },
                            onCommit: (width, height) => {
                                const pos = getPos();
                                if (pos === undefined) return;
                                this.editor.chain().setNodeSelection(pos).updateAttributes('image', {
                                    width, height,
                                }).run();
                            },
                            onUpdate: (updatedNode) => {
                                if (updatedNode.type !== node.type) return false;
                                if (updatedNode.attrs.width != null) {
                                    el.style.width = `${updatedNode.attrs.width}px`;
                                    el.style.height = `${updatedNode.attrs.height}px`;
                                } else {
                                    el.style.width = '';
                                    el.style.height = '';
                                }
                                return true;
                            },
                            options: {
                                directions: directions ?? ['bottom-right', 'bottom-left', 'top-right', 'top-left'],
                                min: { width: minWidth ?? 80, height: minHeight ?? 80 },
                                preserveAspectRatio: alwaysPreserveAspectRatio === true,
                                className: { handle: 'bp-resize-handle' },
                            },
                        });
                        const dom = nodeView.dom;
                        dom.style.visibility = 'hidden';
                        dom.style.pointerEvents = 'none';
                        el.onload = () => {
                            dom.style.visibility = '';
                            dom.style.pointerEvents = '';
                        };
                        return nodeView;
                    };
                },
            }).configure({
                inline: false,
                allowBase64: false,
                resize: {
                    enabled: true,
                    directions: ['bottom-right', 'bottom-left', 'top-right', 'top-left'],
                    minWidth: 80,
                    minHeight: 80,
                    alwaysPreserveAspectRatio: true,
                },
            }),
            Table.configure({
                resizable: true,
                allowTableNodeSelection: true,
            }).extend({
                addAttributes() {
                    return {
                        ...this.parent?.() || {},
                        borderless: {
                            default: false,
                            parseHTML: element => element.getAttribute('data-borderless') === 'true',
                            renderHTML: attributes => {
                                if (!attributes.borderless) return {};
                                return { 'data-borderless': 'true' };
                            },
                        },
                    };
                },
            }),
            TableRow,
            TableCell,
            TableHeader,
            Placeholder.configure({ placeholder }),
            Underline,
            Highlight.configure({ multicolor: true }),
            TextAlign.configure({ types: ['heading', 'paragraph'] }),
            TextStyle,
            Color,
            Annotation.configure({
                HTMLAttributes: {},
            }),
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
