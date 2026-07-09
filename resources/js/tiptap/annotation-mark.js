import { Mark, mergeAttributes } from '@tiptap/core';

const Annotation = Mark.create({
    name: 'annotation',

    addOptions() {
        return {
            HTMLAttributes: {},
        };
    },

    addAttributes() {
        return {
            annotationId: {
                default: null,
                parseHTML: element => element.getAttribute('data-annotation-id'),
                renderHTML: attributes => {
                    if (!attributes.annotationId) return {};
                    return { 'data-annotation-id': attributes.annotationId };
                },
            },
        };
    },

    parseHTML() {
        return [
            { tag: 'span[data-annotation-id]' },
        ];
    },

    renderHTML({ HTMLAttributes }) {
        return [
            'span',
            mergeAttributes(this.options.HTMLAttributes, HTMLAttributes, {
                class: 'bp-annotation-mark',
            }),
            0,
        ];
    },

    addCommands() {
        return {
            setAnnotation: (annotationId) => ({ commands }) => {
                return commands.setMark(this.name, { annotationId });
            },
            unsetAnnotation: () => ({ commands }) => {
                return commands.unsetMark(this.name);
            },
        };
    },
});

export default Annotation;
