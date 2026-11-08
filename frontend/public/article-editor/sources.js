const createArticleSources = () => {
    const { core: { Extension }, pmState: { Plugin } } = window.FilamentRichEditor.tiptap;
    return Extension.create({
        name: 'articleSources',
        addGlobalAttributes() {
            return [{ types: ['heading'], attributes: {
                articleSources: {
                    default: false,
                    parseHTML: element => element.hasAttribute('data-article-sources'),
                    renderHTML: attributes => attributes.articleSources ? { 'data-article-sources': '' } : {},
                },
            } }];
        },
        addProseMirrorPlugins() {
            return [new Plugin({
                appendTransaction(transactions, oldState, newState) {
                    if (!transactions.some(transaction => transaction.docChanged)) return null;
                    const transaction = newState.tr;
                    newState.doc.forEach((node, position, index) => {
                        if (node.type.name !== 'heading') return;
                        const next = newState.doc.maybeChild(index + 1);
                        const isSources = index === newState.doc.childCount - 2 && node.attrs.level === 2
                            && node.textContent.trim() === 'Источники' && ['bulletList', 'orderedList'].includes(next?.type.name);
                        if (Boolean(node.attrs.articleSources) !== isSources) {
                            transaction.setNodeMarkup(position, undefined, { ...node.attrs, articleSources: isSources });
                        }
                    });
                    return transaction.docChanged ? transaction : null;
                },
            })];
        },
    });
};

export default createArticleSources;
