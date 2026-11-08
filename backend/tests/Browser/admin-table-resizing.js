// Run this function through Chrome DevTools evaluate_script on a local /admin page.
// Uses only temporary DOM tables and their own column-width preference keys.
async function testAdminTableResizing() {
    const assert = (condition, message) => { if (!condition) throw new Error(message); };
    const flush = () => new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve)));
    const actor = document.querySelector('[data-admin-table-resizing]').dataset.userId;
    const signature = JSON.stringify(['QA resize name', 'QA resize value']);
    const keys = [0, 1].map((ordinal) => `cms.admin.column-widths.v1:${actor}:${location.pathname}:${signature}:${ordinal}`);
    const host = document.createElement('section');
    const markup = '<section class="fi-section"><header class="fi-section-header">QA table</header><div style="width:400px;overflow:auto"><table style="width:400px"><thead><tr><th>QA resize name</th><th>QA resize value</th></tr></thead><tbody><tr><td>Alpha</td><td>Beta</td></tr></tbody></table></div></section>';
    const control = (table) => table.closest('.fi-section').querySelector('.admin-table-controls');
    const keydown = (handle, key, shiftKey = false) => handle.dispatchEvent(new KeyboardEvent('keydown', { key, shiftKey, bubbles: true }));
    const pointer = (target, type, clientX) => target.dispatchEvent(new PointerEvent(type, {
        clientX, pointerId: 91, isPrimary: true, button: 0, bubbles: true,
    }));
    const width = (table) => table.tHead.rows[0].cells[0].getBoundingClientRect().width;
    const results = [];
    const originalGet = Storage.prototype.getItem;
    const originalSet = Storage.prototype.setItem;
    const originalRemove = Storage.prototype.removeItem;
    try {
        keys.forEach((key) => localStorage.removeItem(key));
        host.innerHTML = markup + markup;
        document.querySelector('.fi-main').append(host);
        await flush();
        let [first, second] = host.querySelectorAll('table');
        assert(host.querySelectorAll('.admin-column-handle').length === 4, 'All plain table columns receive handles');
        assert(!first.classList.contains('admin-table-sized'), 'Initial auto layout is preserved');
        assert(control(first).hidden, 'Reset is hidden before resizing');
        assert(control(first).querySelector('button').textContent.trim() === 'Сбросить ширину', 'Reset has a visible action label');
        assert(!control(first).querySelector('svg'), 'Reset does not rely on an ambiguous icon');
        let handle = first.querySelector('.admin-column-handle');
        const initial = width(first);
        keydown(handle, 'ArrowRight');
        assert(!control(first).hidden, 'Reset appears in the existing section header');
        assert(Math.abs(width(first) - initial - 16) < 3, 'Keyboard increases width by 16');
        const afterArrow = width(first);
        keydown(handle, 'ArrowRight', true);
        assert(Math.abs(width(first) - afterArrow - 64) < 3, 'Shift increases width by 64');
        assert(!second.classList.contains('admin-table-sized'), 'Identical tables have independent preferences');
        results.push('initial layout, keyboard, independent tables');

        keydown(handle, 'Home');
        assert(Math.abs(width(first) - 40) < 3, 'Minimum width');
        keydown(handle, 'End');
        assert(Math.abs(width(first) - 1600) < 3, 'Maximum width');
        keydown(handle, 'Home');
        pointer(handle, 'pointerdown', 100);
        pointer(window, 'pointermove', 220);
        pointer(window, 'pointerup', 220);
        assert(Math.abs(width(first) - 160) < 3, 'Pointer drag width');
        const committed = localStorage.getItem(keys[0]);
        pointer(handle, 'pointerdown', 100);
        pointer(window, 'pointermove', 300);
        window.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
        assert(Math.abs(width(first) - 160) < 3, 'Escape restores previous width');
        assert(localStorage.getItem(keys[0]) === committed, 'Cancelled drag does not save');
        assert(!document.documentElement.classList.contains('admin-column-dragging'), 'Drag listeners and cursor cleaned');
        results.push('bounds, drag, Escape cancellation');

        first.parentElement.innerHTML = markup.match(/<table[\s\S]*<\/table>/)[0];
        await flush();
        first = host.querySelector('table');
        assert(Math.abs(width(first) - 160) < 3, 'Replacement table restores saved widths');
        assert(host.querySelectorAll('.admin-table-controls').length === 2, 'Replacement has no orphan controls');
        control(first).querySelector('button').click();
        assert(!first.classList.contains('admin-table-sized'), 'Reset restores auto layout');
        assert(first.style.width === '400px', 'Reset restores original inline width');
        assert(localStorage.getItem(keys[0]) === null, 'Reset removes only this preference');
        assert(control(first).hidden, 'Reset hides after restoring the original layout');
        results.push('DOM replacement, reset, orphan cleanup');

        // Malformed preferences are ignored rather than applied to table geometry.
        localStorage.setItem(keys[0], '[null,"9999"]');
        first.parentElement.innerHTML = markup.match(/<table[\s\S]*<\/table>/)[0];
        await flush();
        first = host.querySelector('table');
        assert(!first.classList.contains('admin-table-sized'), 'Malformed saved widths ignored');

        Storage.prototype.getItem = function (key) {
            if (keys.includes(key)) throw new DOMException('Blocked', 'SecurityError');
            return originalGet.call(this, key);
        };
        Storage.prototype.setItem = function (key, value) {
            if (keys.includes(key)) throw new DOMException('Full', 'QuotaExceededError');
            return originalSet.call(this, key, value);
        };
        Storage.prototype.removeItem = function (key) {
            if (keys.includes(key)) throw new DOMException('Blocked', 'SecurityError');
            return originalRemove.call(this, key);
        };
        first.parentElement.innerHTML = markup.match(/<table[\s\S]*<\/table>/)[0];
        await flush();
        first = host.querySelector('table');
        keydown(first.querySelector('.admin-column-handle'), 'ArrowRight');
        assert(first.classList.contains('admin-table-sized'), 'Resizing works with blocked browser storage');
        control(first).querySelector('button').click();
        assert(!first.classList.contains('admin-table-sized'), 'Reset works with blocked browser storage');
        results.push('malformed preferences, disabled/full storage');
        return { passed: results };
    } finally {
        Storage.prototype.getItem = originalGet;
        Storage.prototype.setItem = originalSet;
        Storage.prototype.removeItem = originalRemove;
        keys.forEach((key) => localStorage.removeItem(key));
        host.remove();
        await flush();
    }
}
