<style>
    /* Shared panel layout; keep page-specific data presentation in its columns. */
    /* Breadcrumbs are the parent navigation on mobile as well as desktop. */
    .fi-header .fi-breadcrumbs {
        display: block;
    }

    @media (min-width: 1024px) {
        .fi-main {
            padding-inline: 1.5rem;
        }
    }

    .fi-ta-header-toolbar {
        justify-content: flex-start;
    }

    .fi-ta-header-toolbar > .fi-ta-actions:not(:has(*)) {
        display: none;
    }

    .fi-ta-header-toolbar > :nth-child(2) {
        margin-inline-start: 0;
    }

    /* Compact panel scale uses the standard Tailwind type steps, without page zoom. */
    .fi-body { font-size: var(--text-sm); }
    .fi-header-heading { font-size: var(--text-2xl); line-height: var(--text-2xl--line-height); }
    [data-ui="article-management"] .fi-header-subheading {
        font-size: var(--text-xs);
        line-height: var(--text-xs--line-height);
    }
    [data-ui="article-management"] > .fi-page-header-main-ctn:has(> .article-editor-header) {
        gap: 1.5rem;
        padding-block: 1.5rem;
    }
    [data-ui="article-management"] .fi-header.article-editor-header {
        display: grid;
        grid-template-columns: minmax(0, 1fr) auto;
        align-items: center;
        gap: 0.5rem 1rem;
    }
    .article-editor-header .fi-breadcrumbs { grid-column: 1 / -1; min-width: 0; margin: 0; }
    .article-editor-header .fi-header-heading { min-width: 0; overflow-wrap: anywhere; }
    .article-editor-header > .fi-badge { justify-self: end; }
    .article-editor-header .fi-header-subheading { margin: 0; }
    .article-editor-header__save-state { grid-column: 1 / -1; }
    .article-editor-actions {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 1rem;
        margin-top: 1.5rem;
    }
    .fi-section-header-heading { font-size: var(--text-base); }
    .fi-sidebar-item-label,
    .fi-sidebar-group-label,
    .fi-btn,
    .fi-link,
    .fi-dropdown-list-item-label,
    .fi-fo-field-wrp-label,
    .fi-fo-field-wrp-helper-text,
    .fi-pagination,
    .fi-ta-header-cell,
    .fi-ta-text,
    .fi-ta-text-item,
    .fi-ta-text-description,
    .fi-ta-table .fi-badge,
    .fi-main table {
        font-size: var(--text-xs);
        line-height: var(--text-xs--line-height);
    }
    .fi-breadcrumbs-item-label {
        font-size: var(--text-xs);
        font-weight: 400;
        line-height: 1.25rem;
    }
    .fi-main table { font-size: var(--text-xs) !important; }
    .fi-main .fi-input,
    .fi-main .fi-select-input { font-size: var(--text-sm); }
    .fi-ta-text { padding-block: .75rem; }
    .fi-ta-header-cell { padding-block: .625rem; }
    .fi-sidebar-item-btn { padding-block: .5rem; }
    .fi-btn { min-height: 2rem; padding-block: .375rem; }
    .fi-main .fi-header { gap: 1rem; }
    .fi-main .fi-page-header-main-ctn { gap: 1rem; }
    @media (max-width: 639px) {
        .fi-main .fi-input,
        .fi-main .fi-select-input { font-size: var(--text-base); }
        .fi-btn { min-height: 2.5rem; }
    }
</style>
