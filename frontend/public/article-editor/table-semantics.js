const createArticleTableSemantics = () => {
    const { Extension } = window.FilamentRichEditor.tiptap.core;
    const scope = value => ['row', 'col', 'rowgroup', 'colgroup'].includes(value) ? value : null;

    return Extension.create({
        name: 'articleTableSemantics',
        addGlobalAttributes() {
            return [{ types: ['tableHeader'], attributes: {
                scope: {
                    default: null,
                    parseHTML: element => scope(element.getAttribute('scope')),
                    renderHTML: attributes => scope(attributes.scope) ? { scope: attributes.scope } : {},
                },
            } }];
        },
    });
};

export default createArticleTableSemantics;
