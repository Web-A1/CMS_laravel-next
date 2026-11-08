<style>
    .admin-review-column-heading { display: flex; align-items: center; gap: .375rem; padding-inline-end: .375rem; white-space: nowrap; }
    .admin-review-column-heading .fi-ta-header-cell-sort-btn { flex: 1; justify-content: space-between; gap: .75rem; text-align: start; }
    .admin-review-column-label { margin-inline-end: auto; }
    .admin-review-column-heading > .admin-review-column-filter { flex: none; }
    .admin-review-column-heading .fi-icon-btn { width: 1.5rem; height: 1.5rem; }
    .admin-review-column-heading .fi-icon { width: 1rem; height: 1rem; }
    .admin-review-column-heading [data-filter-active="false"] .fi-icon { color: var(--gray-500); }
    .dark .admin-review-column-heading [data-filter-active="false"] .fi-icon { color: var(--gray-400); }
    .admin-review-filter-content { display: grid; gap: 1rem; padding: 1rem; white-space: normal; }
    .admin-review-filter-actions { display: flex; align-items: center; justify-content: space-between; gap: 1rem; }
    .admin-review-column-filter .fi-dropdown-panel { max-width: calc(100vw - 2rem); }
    .fi-dropdown-panel.admin-column-choice-menu { width: max-content; min-width: 8rem; max-width: calc(100vw - 2rem); }
    .admin-review-text-search { flex: 1; min-width: 8rem; margin-inline-start: .375rem; }
    .admin-review-text-input { display: flex; align-items: center; }
    .admin-review-text-input .fi-input { min-width: 0; }
    .admin-review-text-input .fi-icon-btn { flex: none; margin-inline-end: .25rem; }
    .fi-main .admin-review-text-search .fi-input { font-size: var(--text-xs); font-weight: 400; padding-block: .25rem; }
    .admin-review-text-search .fi-input::-webkit-search-cancel-button { display: none; }
    [data-admin-reviews] .fi-ta-text { max-width: none !important; }
    .fi-main table .fi-ta-header-cell-sort-btn { width: 100%; display: flex; align-items: center; }
    .fi-main table th.fi-ta-header-cell { text-align: start; padding-inline: .75rem; }
    @media (max-width: 639px) {
        .fi-main .admin-review-text-search .fi-input { font-size: var(--text-base); }
    }

    [data-admin-reviews] .fi-ta-table:not(.admin-table-sized) { table-layout: fixed; min-width: 78rem; width: 100%; }
    [data-admin-reviews] .fi-ta-table:not(.admin-table-sized) th:first-child { width: 2.5rem; }
    [data-admin-reviews] .fi-ta-table:not(.admin-table-sized) th[data-column-label="ID"] { width: 6.5rem; }
    [data-admin-reviews] .fi-ta-table:not(.admin-table-sized) th[data-column-label="Автор"] { width: 10rem; }
    [data-admin-reviews] .fi-ta-table:not(.admin-table-sized) th[data-column-label="Предмет / курс"] { width: 11.5rem; }
    [data-admin-reviews] .fi-ta-table:not(.admin-table-sized) th[data-column-label="Дата отзыва"] { width: 9rem; }
    [data-admin-reviews] .fi-ta-table:not(.admin-table-sized) th[data-column-label="Главная"] { width: 5.5rem; }
    [data-admin-reviews] .fi-ta-table:not(.admin-table-sized) th[data-column-label="Фото"],
    [data-admin-reviews] .fi-ta-table:not(.admin-table-sized) th[data-column-label="Скрин"] { width: 5rem; }
    [data-admin-reviews] .fi-ta-table:not(.admin-table-sized) th[data-column-label="Статус"] { width: 7rem; }
</style>
