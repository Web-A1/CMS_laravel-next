const createArticleFigures = () => {
    const { core: { Node, Extension }, pmState: { NodeSelection, Plugin } } = window.FilamentRichEditor.tiptap;
    const caption = Node.create({ name: 'articleFigcaption', inline: true, content: 'inline*', parseHTML() { return [{ tag: 'figcaption' }]; }, renderHTML() { return ['figcaption', {}, 0]; } });
    const figure = Node.create({
        name: 'articleFigure', group: 'block', content: 'image articleFigcaption?', defining: true, isolating: true,
        parseHTML() { return [{ tag: 'figure' }]; }, renderHTML() { return ['figure', {}, 0]; },
        addProseMirrorPlugins() {
            const selectImageBlock = (view, position, movePair = false) => {
                for (let depth = position.depth; depth > 0; depth--) {
                    if (position.node(depth).type.name !== 'articleFigure') continue;

                    const parent = position.node(depth - 1);
                    const usePair = parent.type.name === 'articleImagePair' && (movePair || parent.childCount === 1);
                    const block = usePair ? depth - 1 : depth;
                    view.dispatch(view.state.tr.setSelection(NodeSelection.create(view.state.doc, position.before(block))));
                    break;
                }
            };

            return [new Plugin({
                props: {
                    handleDOMEvents: {
                        dragstart(view, event) {
                            const figure = event.target.closest?.('figure');
                            if (event.target.tagName === 'IMG' && figure) {
                                // A native image drag can also carry a file. Let ProseMirror serialize the block.
                                event.dataTransfer?.items.clear();
                                selectImageBlock(view, view.state.doc.resolve(view.posAtDOM(figure, 0)), true);
                            }

                            return false;
                        },
                        cut(view) {
                            const { selection } = view.state;
                            if (selection.node?.type.name === 'image') selectImageBlock(view, selection.$from);

                            return false;
                        },
                    },
                },
                filterTransaction(transaction, state) {
                    if (!transaction.docChanged) return true;

                    // Removing a required image can make ProseMirror insert a blank one before its caption.
                    const countEmptyFigures = doc => {
                        let count = 0;
                        doc.descendants(node => {
                            if (node.type.name === 'articleFigure' && !node.firstChild?.attrs.src && !node.firstChild?.attrs.id) {
                                count++;
                            }
                        });
                        return count;
                    };
                    const emptyFigures = countEmptyFigures(transaction.doc);
                    return emptyFigures === 0 || emptyFigures <= countEmptyFigures(state.doc);
                },
            })];
        },
        addKeyboardShortcuts() {
            const removeFigure = () => this.editor.commands.deleteArticleFigure();
            return { Backspace: removeFigure, Delete: removeFigure };
        },
        addCommands() {
            return {
                insertArticleImageBlock: block => ({ state, commands }) => {
                    const { $to, empty } = state.selection;
                    if (empty && $to.depth === 1 && $to.parent.type.name === 'paragraph' && !$to.parent.content.size) {
                        return commands.insertContent(block);
                    }

                    return commands.insertContentAt($to.depth ? $to.after(1) : $to.pos, block);
                },
                deleteArticleFigure: () => ({ state, tr, dispatch }) => {
                    const { selection } = state;
                    const selected = selection.node;
                    if (!['image', 'articleFigure'].includes(selected?.type.name)) return false;

                    const { $from } = selection;
                    let figure = selected.type.name === 'articleFigure' ? selected : null;
                    let figureFrom = selection.from;
                    for (let depth = $from.depth; depth > 0; depth--) {
                        const node = $from.node(depth);
                        if (node.type.name === 'articleFigure') {
                            figure = node;
                            figureFrom = $from.before(depth);
                        } else if (node.type.name === 'articleImagePair' && figure) {
                            const remaining = node.maybeChild($from.index(depth) === 0 ? 1 : 0);
                            if (dispatch) tr.replaceWith($from.before(depth), $from.after(depth), remaining);
                            return true;
                        }
                    }
                    if (!figure) return false;

                    if (dispatch) tr.delete(figureFrom, figureFrom + figure.nodeSize);
                    return true;
                },
                setArticleCaption: text => ({ state, tr, dispatch }) => {
                    const { selection, schema } = state;
                    let selectedFigure = selection.node?.type.name === 'articleFigure' ? selection.node : null;
                    let from = selection.from;
                    let to = selection.to;

                    for (let depth = selection.$from.depth; depth > 0; depth--) {
                        const parent = selection.$from.node(depth);
                        if (parent.type.name !== 'articleFigure') continue;
                        selectedFigure = parent;
                        from = selection.$from.before(depth);
                        to = from + parent.nodeSize;
                        break;
                    }

                    const image = selectedFigure?.firstChild ?? (selection.node?.type.name === 'image' ? selection.node : null);
                    if (!image) return false;

                    const existingCaption = selectedFigure?.lastChild?.type.name === 'articleFigcaption' ? selectedFigure.lastChild : null;
                    const captionChanged = text !== (existingCaption?.textContent ?? '');
                    const children = [captionChanged ? image.type.create({ ...image.attrs, alt: text }, null, image.marks) : image];
                    if (text) {
                        children.push(existingCaption?.textContent === text
                            ? existingCaption
                            : schema.nodes.articleFigcaption.create(null, schema.text(text)));
                    }
                    if (dispatch) tr.replaceRangeWith(from, to, schema.nodes.articleFigure.create(null, children));
                    return true;
                },
            };
        },
    });
    const pair = Node.create({
        name: 'articleImagePair', group: 'block', content: 'articleFigure{1,2}', defining: true,
        parseHTML() { return [{ tag: 'div[data-article-images="pair"]' }]; },
        renderHTML() { return ['div', { 'data-article-images': 'pair' }, 0]; },
    });
    return Extension.create({ name: 'articleFigures', addExtensions() { return [figure, caption, pair]; } });
};

export default createArticleFigures;
