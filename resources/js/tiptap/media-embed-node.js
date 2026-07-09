import { Node, mergeAttributes } from '@tiptap/core';

const MEDIA_DOMAINS = [
    'youtube.com', 'www.youtube.com', 'youtube-nocookie.com', 'www.youtube-nocookie.com',
    'vimeo.com', 'player.vimeo.com',
    'dailymotion.com', 'www.dailymotion.com',
];

function extractEmbedUrl(url) {
    const trimmed = (url || '').trim();
    if (!trimmed) return null;

    try {
        const parsed = new URL(trimmed);
        const host = parsed.hostname.toLowerCase();

        if (host.includes('youtube.com') || host.includes('youtube-nocookie.com')) {
            const videoId = parsed.searchParams.get('v') || parsed.pathname.split('/').filter(Boolean).pop();
            if (!videoId) return null;
            return `https://www.youtube.com/embed/${videoId}`;
        }

        if (host.includes('vimeo.com')) {
            const videoId = parsed.pathname.split('/').filter(Boolean).pop();
            if (!videoId || !/^\d+$/.test(videoId)) return null;
            return `https://player.vimeo.com/video/${videoId}`;
        }

        if (host.includes('dailymotion.com')) {
            const parts = parsed.pathname.split('/').filter(Boolean);
            const vidIdx = parts.indexOf('video');
            const videoId = vidIdx >= 0 ? parts[vidIdx + 1] : parts.pop();
            if (!videoId) return null;
            return `https://www.dailymotion.com/embed/video/${videoId}`;
        }

        return null;
    } catch {
        return null;
    }
}

function isValidEmbedSrc(src) {
    if (!src) return false;
    try {
        const host = new URL(src).hostname.toLowerCase();
        return MEDIA_DOMAINS.some(d => host === d || host.endsWith('.' + d));
    } catch {
        return false;
    }
}

const MediaEmbed = Node.create({
    name: 'mediaEmbed',
    group: 'block',
    atom: true,
    draggable: true,

    addOptions() {
        return {
            HTMLAttributes: {},
        };
    },

    addAttributes() {
        return {
            src: {
                default: null,
                parseHTML: element => {
                    const iframe = element.querySelector('iframe');
                    return iframe?.getAttribute('src') || null;
                },
            },
            aspectRatio: {
                default: '16/9',
                parseHTML: element => {
                    const style = element.getAttribute('style') || '';
                    const match = style.match(/aspect-ratio\s*:\s*(\d+)\s*\/\s*(\d+)/);
                    if (match) return `${match[1]}/${match[2]}`;
                    return '16/9';
                },
            },
        };
    },

    parseHTML() {
        return [{ tag: 'div[data-media-embed]' }];
    },

    renderHTML({ HTMLAttributes }) {
        const src = HTMLAttributes.src || '';
        const ratio = HTMLAttributes.aspectRatio || '16/9';
        const parts = ratio.split('/');
        const w = parts[0] || '16';
        const h = parts[1] || '9';
        const allow = 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture';

        return [
            'div',
            mergeAttributes(this.options.HTMLAttributes, {
                'data-media-embed': '',
                style: `aspect-ratio: ${w} / ${h}`,
            }),
            ['iframe', {
                src,
                width: '100%',
                height: '100%',
                frameborder: '0',
                allowfullscreen: 'true',
                allow,
                style: 'width: 100%; height: 100%',
                title: 'Embedded media',
            }],
        ];
    },

    addCommands() {
        return {
            insertMediaEmbed: (attributes) => ({ commands }) => {
                return commands.insertContent({
                    type: this.name,
                    attrs: attributes,
                });
            },
        };
    },
});

export { MediaEmbed, extractEmbedUrl, isValidEmbedSrc, MEDIA_DOMAINS };
