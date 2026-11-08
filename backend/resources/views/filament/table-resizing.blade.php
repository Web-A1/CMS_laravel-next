<style>
    .admin-column-header { position: relative; }
    .admin-column-handle {
        position: absolute;
        inset-block: 0;
        inset-inline-end: 0;
        width: 10px;
        cursor: col-resize;
        touch-action: none;
        z-index: 2;
    }
    .admin-column-handle::after {
        content: '';
        position: absolute;
        inset-block: 25%;
        inset-inline-end: 2px;
        width: 1px;
        background: currentColor;
        opacity: .2;
    }
    .admin-column-handle:hover::after,
    .admin-column-handle:focus-visible::after { opacity: 1; }
    .admin-column-handle:focus-visible { outline: 2px solid currentColor; outline-offset: -2px; }
    html.admin-column-dragging, html.admin-column-dragging * { cursor: col-resize !important; user-select: none !important; }
    .admin-table-sized { table-layout: fixed !important; min-width: 0 !important; }
    .admin-table-sized > :is(thead, tbody, tfoot) > tr > :is(th, td) { overflow: hidden; overflow-wrap: anywhere; }
    .admin-table-sized .fi-ta-text { max-width: none !important; min-width: 0; }
    .admin-table-sized .fi-ta-text-item { min-width: 0; }
    .admin-table-controls { display: inline-flex; flex: 0 0 auto; margin-inline-start: auto; }
    .admin-table-controls[hidden] { display: none !important; }
    .admin-table-controls .fi-link { color: var(--gray-500); }
    .dark .admin-table-controls .fi-link { color: var(--gray-400); }
    .admin-table-controls .fi-link:is(:hover, :focus-visible) { color: var(--gray-700); }
    .dark .admin-table-controls .fi-link:is(:hover, :focus-visible) { color: var(--gray-200); }
    @media (max-width: 639px) {
        .fi-ta-header-toolbar:has(> .admin-table-controls:not([hidden])) > div:has(.fi-ta-search-field) { flex: 1; min-width: 0; }
        .fi-ta-header-toolbar:has(> .admin-table-controls:not([hidden])) .fi-ta-search-field { min-width: 0; }
    }
    .admin-table-scroll { overflow-x: auto; max-width: 100%; }
</style>
<template id="admin-table-controls">
    <span class="admin-table-controls" wire:ignore hidden>
        <x-filament::link tag="button" color="gray" size="sm" title="Сбросить ширину столбцов"
            data-ui="admin.table.resetWidths">Сбросить ширину</x-filament::link>
    </span>
</template>
<script data-admin-table-resizing data-user-id="{{ auth()->id() }}">
    @include('filament.table-resizing-script')
</script>
