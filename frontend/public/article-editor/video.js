const createArticleVideo = () => window.FilamentRichEditor.tiptap.core.Node.create({
    name: 'articleVideo', group: 'block', atom: true, selectable: true, draggable: true,
    addAttributes() { return { src: { default: null }, title: { default: 'Видео к статье' } }; },
    parseHTML() { return [{ tag: 'iframe[src]', getAttrs: element => /^https:\/\/kinescope\.io\/embed\/[A-Za-z0-9]+$/.test(element.getAttribute('src') ?? '') ? null : false }]; },
    renderHTML({ HTMLAttributes }) { return ['iframe', { src: HTMLAttributes.src, title: HTMLAttributes.title, loading: 'lazy', allowfullscreen: '' }]; },
    addNodeView() {
        return ({ node }) => {
            const dom = document.createElement('div');
            dom.className = 'article-video-node';
            dom.contentEditable = 'false';
            const frame = document.createElement('iframe');
            frame.src = node.attrs.src;
            frame.title = node.attrs.title;
            frame.setAttribute('allowfullscreen', '');
            frame.style.pointerEvents = 'none';
            dom.append(frame);
            return { dom, update(updated) { if (updated.type.name !== 'articleVideo') return false; frame.src = updated.attrs.src; frame.title = updated.attrs.title; return true; } };
        };
    },
});

export default createArticleVideo;
