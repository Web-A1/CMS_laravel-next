(() => {
    const userId = document.currentScript.dataset.userId;
    const prefix = `cms.admin.column-widths.v1:${userId}:`;
    const states = new Map();
    const minWidth = 40;
    const maxWidth = 1600;
    let scheduled = false;
    let morphing = 0;
    let cancelDrag = null;

    function storage(key, value) {
        try {
            if (value === undefined) return JSON.parse(localStorage.getItem(prefix + key));
            if (value === null) localStorage.removeItem(prefix + key);
            else localStorage.setItem(prefix + key, JSON.stringify(value));
        } catch {
            // Resizing remains available when browser storage is disabled or full.
        }
        return null;
    }

    const minimum = (header) => Math.min(maxWidth, Math.max(minWidth, Number(header.dataset.adminColumnMinWidth) || minWidth));
    const bounded = (width, minimumWidth = minWidth) => Math.min(maxWidth, Math.max(minimumWidth, Math.round(width)));
    const measure = (state) => state.headers.map((header) => header.getBoundingClientRect().width);

    function apply(state) {
        const { table, widths } = state;
        if (!widths) return;
        let group = table.querySelector(':scope > colgroup[data-admin-columns]');
        if (!group) {
            group = document.createElement('colgroup');
            group.dataset.adminColumns = '';
            group.setAttribute('wire:ignore', '');
            group.append(...widths.map(() => document.createElement('col')));
            table.insertBefore(group, table.tHead);
        }
        widths.forEach((width, index) => {
            group.children[index].style.width = `${width}px`;
            const handle = state.headers[index].querySelector('.admin-column-handle');
            handle?.setAttribute('aria-valuenow', String(Math.round(width)));
            handle?.setAttribute('aria-valuetext', `${Math.round(width)} пикселей`);
        });
        table.classList.add('admin-table-sized');
        table.style.width = `${widths.reduce((sum, width) => sum + width, 0)}px`;
    }

    function save(state) {
        storage(state.key, state.widths);
        state.toolbar.hidden = !state.widths;
    }

    function reset(state) {
        cancelDrag?.();
        state.widths = null;
        storage(state.key, null);
        state.table.querySelector(':scope > colgroup[data-admin-columns]')?.remove();
        state.table.classList.remove('admin-table-sized');
        state.table.style.width = state.originalWidth;
        state.headers.forEach((header) => {
            const handle = header.querySelector('.admin-column-handle');
            const width = Math.round(header.getBoundingClientRect().width);
            handle?.setAttribute('aria-valuenow', String(width));
            handle?.setAttribute('aria-valuetext', `${width} пикселей`);
        });
        state.toolbar.hidden = true;
    }

    function detach(state) {
        state.toolbar.remove();
        state.table.querySelectorAll('.admin-column-handle').forEach((handle) => handle.remove());
        state.headers.forEach((header) => header.classList.remove('admin-column-header'));
        state.table.querySelector(':scope > colgroup[data-admin-columns]')?.remove();
        state.table.classList.remove('admin-table-sized');
        state.table.style.width = state.originalWidth;
    }

    function resize(state, index, width) {
        state.widths ??= measure(state);
        state.widths[index] = bounded(width, minimum(state.headers[index]));
        apply(state);
    }

    function startDrag(event, state, index) {
        if (event.button !== 0 || !event.isPrimary) return;
        event.preventDefault();
        event.stopPropagation();
        cancelDrag?.();
        const handle = event.currentTarget;
        handle.focus({ preventScroll: true });
        const initial = state.widths ? [...state.widths] : null;
        const width = measure(state)[index];
        const start = event.clientX;
        const direction = getComputedStyle(state.table).direction === 'rtl' ? -1 : 1;
        const controller = new AbortController();
        const options = { signal: controller.signal };
        let moved = false;
        document.documentElement.classList.add('admin-column-dragging');

        function finish(cancelled = false) {
            controller.abort();
            document.documentElement.classList.remove('admin-column-dragging');
            cancelDrag = null;
            if (cancelled) {
                state.widths = initial;
                if (initial) apply(state);
                else reset(state);
            } else if (moved) save(state);
        }

        cancelDrag = () => finish(true);
        window.addEventListener('pointermove', (move) => {
            if (move.pointerId !== event.pointerId) return;
            moved = true;
            resize(state, index, width + (move.clientX - start) * direction);
        }, options);
        window.addEventListener('pointerup', (up) => {
            if (up.pointerId === event.pointerId) finish();
        }, options);
        window.addEventListener('pointercancel', () => finish(true), options);
        window.addEventListener('blur', () => finish(true), options);
        window.addEventListener('keydown', (key) => {
            if (key.key !== 'Escape') return;
            key.preventDefault();
            key.stopPropagation();
            finish(true);
        }, options);
    }

    function addHandles(state) {
        state.headers.forEach((header, index) => {
            const label = state.labels[index];
            if (!label) return;
            header.classList.add('admin-column-header');
            if (header.querySelector('.admin-column-handle')) return;
            const handle = document.createElement('span');
            handle.className = 'admin-column-handle';
            handle.dataset.ui = 'admin.table.resizeColumn';
            handle.setAttribute('wire:ignore', '');
            handle.tabIndex = 0;
            handle.setAttribute('role', 'separator');
            handle.setAttribute('aria-orientation', 'vertical');
            handle.setAttribute('aria-label', `Ширина столбца «${label}»`);
            handle.setAttribute('aria-valuemin', String(minimum(header)));
            handle.setAttribute('aria-valuemax', String(maxWidth));
            const width = Math.round(header.getBoundingClientRect().width);
            handle.setAttribute('aria-valuenow', String(width));
            handle.setAttribute('aria-valuetext', `${width} пикселей`);
            handle.title = 'Потяните границу. Стрелки ← → — изменить ширину, Shift — крупный шаг.';
            handle.addEventListener('pointerdown', (event) => startDrag(event, state, index));
            handle.addEventListener('click', (event) => { event.preventDefault(); event.stopPropagation(); });
            handle.addEventListener('keydown', (event) => {
                if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
                event.preventDefault();
                event.stopPropagation();
                const direction = getComputedStyle(state.table).direction === 'rtl' ? -1 : 1;
                const step = (event.shiftKey ? 64 : 16) * direction;
                const width = measure(state)[index];
                resize(state, index, event.key === 'Home' ? minimum(header) : event.key === 'End' ? maxWidth
                    : width + (event.key === 'ArrowRight' ? step : -step));
                save(state);
            });
            header.append(handle);
        });
    }

    function scan() {
        scheduled = false;
        if (morphing) return;
        for (const [table, state] of states) {
            if (table.isConnected) continue;
            cancelDrag?.();
            state.toolbar.remove();
            states.delete(table);
        }
        const occurrences = new Map();
        document.querySelectorAll('.fi-main table').forEach((table) => {
            const rows = table.tHead?.rows;
            if (!rows || rows.length !== 1 || table.parentElement.closest('table')) return;
            const headers = [...rows[0].cells];
            if (headers.some((header) => header.colSpan !== 1 || header.rowSpan !== 1)) return;
            const labels = headers.map((header) => (header.dataset.columnLabel ?? header.textContent).trim().replace(/\s+/g, ' '));
            if (!labels.some(Boolean)) return;
            const signature = JSON.stringify(labels);
            const ordinal = occurrences.get(signature) ?? 0;
            occurrences.set(signature, ordinal + 1);
            const key = `${location.pathname}:${table.dataset.ui || signature}:${ordinal}`;
            let state = states.get(table);
            if (state && (state.key !== key || headers.some((header, index) => header !== state.headers[index]))) {
                detach(state);
                states.delete(table);
                state = null;
            }
            if (!state) {
                const saved = storage(key);
                const widths = Array.isArray(saved) && saved.length === headers.length
                    && saved.every((width) => typeof width === 'number' && Number.isFinite(width) && width > 0)
                    ? saved.map((width, index) => bounded(width, minimum(headers[index]))) : null;
                const toolbar = document.getElementById('admin-table-controls').content.firstElementChild.cloneNode(true);
                state = { table, headers, labels, key, widths, toolbar, originalWidth: table.style.width };
                toolbar.querySelector('button').addEventListener('click', () => reset(state));
                toolbar.hidden = !widths;
                states.set(table, state);
            }
            const scroll = table.parentElement;
            scroll.classList.add('admin-table-scroll');
            if (!state.toolbar.isConnected) {
                const pageControls = table.closest('[data-admin-table-controls-location="page-header"]');
                const nativeTable = table.closest('.fi-ta');
                const tools = (pageControls ? document.querySelector('.fi-main .fi-header-actions-ctn .fi-ac') : null)
                    ?? nativeTable?.querySelector('.fi-ta-header .fi-ac')
                    ?? table.closest('.fi-section')?.querySelector('.fi-section-header')
                    ?? document.querySelector('.fi-main .fi-header-actions-ctn .fi-ac')
                    ?? document.querySelector('.fi-main .fi-header');
                if (pageControls) tools?.prepend(state.toolbar);
                else tools?.append(state.toolbar);
            }
            addHandles(state);
            apply(state);
        });
    }

    function schedule() {
        if (scheduled) return;
        scheduled = true;
        requestAnimationFrame(scan);
    }

    new MutationObserver(schedule).observe(document.body, { childList: true, subtree: true });
    function registerLivewireHooks() {
        // Give Livewire its original DOM before it matches unkeyed table nodes.
        // Client controls/colgroups must never become candidates for server HTML.
        Livewire.hook('morph', ({ el }) => {
            morphing++;
            cancelDrag?.();
            for (const state of states.values()) {
                if (el.contains(state.table)) detach(state);
            }
        });
        Livewire.hook('morphed', () => {
            morphing = Math.max(0, morphing - 1);
            schedule();
        });
    }
    if (window.Livewire) registerLivewireHooks();
    else document.addEventListener('livewire:init', registerLivewireHooks, { once: true });
    document.addEventListener('livewire:navigated', schedule);
    scan();
})();
