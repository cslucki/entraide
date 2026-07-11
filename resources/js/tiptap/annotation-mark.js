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
            annotationOrigin: {
                default: 'human',
                parseHTML: element => element.getAttribute('data-annotation-origin') || 'human',
                renderHTML: attributes => {
                    if (!attributes.annotationOrigin || attributes.annotationOrigin === 'human') return {};
                    return { 'data-annotation-origin': attributes.annotationOrigin };
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
        const origin = HTMLAttributes['data-annotation-origin'] || HTMLAttributes.annotationOrigin;
        const originClass = origin === 'ai_method'
            ? 'bp-annotation-mark bp-annotation-mark-method'
            : 'bp-annotation-mark bp-annotation-mark-human';

        return [
            'span',
            mergeAttributes(this.options.HTMLAttributes, HTMLAttributes, {
                class: originClass,
            }),
            0,
        ];
    },

    addCommands() {
        return {
            setAnnotation: (annotationId, annotationOrigin = 'human') => ({ commands }) => {
                return commands.setMark(this.name, { annotationId, annotationOrigin });
            },
            unsetAnnotation: () => ({ commands }) => {
                return commands.unsetMark(this.name);
            },
        };
    },
});

export default Annotation;
