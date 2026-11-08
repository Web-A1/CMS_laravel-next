const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

let factory;
// Browser capture listeners are matched by callback and capture flag on removal.
const listeners = new Map();
const window = {
    addEventListener(name, callback, capture = false) {
        const entries = listeners.get(name) ?? [];
        entries.push({ callback, capture }); listeners.set(name, entries);
    },
    removeEventListener(name, callback, capture = false) {
        listeners.set(name, (listeners.get(name) ?? []).filter((entry) => entry.callback !== callback || entry.capture !== capture));
    },
    dispatchEvent(event) {
        let stopped = false;
        event.stopImmediatePropagation = () => { stopped = true; };
        for (const { callback } of [...(listeners.get(event.type) ?? [])].sort((left, right) => Number(right.capture) - Number(left.capture))) {
            callback(event); if (stopped) break;
        }
    },
};
window.location = { origin: 'https://cms.example.test', assign() {} };
const source = fs.readFileSync(path.join(__dirname, '../../resources/views/filament/field-saving/scripts.blade.php'), 'utf8');
const faqPage = fs.readFileSync(path.join(__dirname, '../../app/Filament/Pages/Operations/Faqs.php'), 'utf8');
const cancelCapture = faqPage.match(/'x-on:click\.capture' => '([^']+)'/)[1];
vm.runInNewContext(source.replace(/^<script>\s*|\s*<\/script>\s*$/g, ''), {
    document: { addEventListener: (_, callback) => callback() },
    Alpine: { data: (_, callback) => { factory = callback; } },
    structuredClone, window, CustomEvent, Promise, Set, setTimeout, URL,
});

const tick = async () => {
    await new Promise(setImmediate);
    await new Promise((resolve) => setTimeout(resolve, 0));
    await new Promise(setImmediate);
};
const deferred = () => {
    let resolve, reject;
    const promise = new Promise((yes, no) => { resolve = yes; reject = no; });
    return { promise, resolve, reject };
};

function mount({ existing = true, save, values = {}, config = {}, unmountError = false } = {}) {
    const initial = { title: 'Вопрос', body: 'Ответ', sort_order: 0, subject_id: null, show_on_home: false, ...values };
    const state = { faqOpening: 'opening-1', ...initial };
    const watchers = new Map();
    const calls = [];
    let requestInterceptor, requestHooks, submitHooks, nativeSubmits = 0, firingSubmissionEvents = false, stayFocused = 0, unmounts = 0;
    const interceptors = new Map();
    const messageInterceptors = new Map();
    const submittedMethods = [];
    const submittedTargets = [];
    const actualForms = new Map();
    const headerCalls = [];
    const fieldKey = (key) => config.statePath === '' ? key : key.split('.').pop();
    const mounts = [];
    const startRequest = (cancel) => {
        const hooks = { cancelled: false };
        requestInterceptor({
            request: { cancel() { hooks.cancelled = true; cancel(); } },
            onRedirect: (callback) => { hooks.redirect = callback; },
            onError: (callback) => { hooks.error = callback; },
            onFailure: (callback) => { hooks.failure = callback; },
        });
        requestHooks = hooks;
    };
    const wire = {
        errorMessages: {},
        $errors: { any: () => Object.keys(wire.errorMessages).length > 0, get: (key) => wire.errorMessages[key] ?? [] },
        $get: (key) => key === 'faqOpening' ? state.faqOpening : state[fieldKey(key)],
        $watch(key, callback) { watchers.set(fieldKey(key), callback); return () => watchers.delete(fieldKey(key)); },
        $set(key, value) { state[fieldKey(key)] = value; watchers.get(fieldKey(key))?.(); },
        async unmountAction() { unmounts++; if (unmountError) throw new Error('network'); helper.destroy(); },
        async mountAction(name, arguments) { mounts.push({ name, arguments }); state.faqOpening = 'opening-2'; },
        $intercept(method, callback) { interceptors.set(method, callback); return () => { interceptors.delete(method); }; },
        $interceptMessage(method, callback) { messageInterceptors.set(method, callback); return () => messageInterceptors.delete(method); },
        $interceptRequest(callback) { requestInterceptor = callback; return () => { requestInterceptor = null; }; },
        saveFaqParameters(patch, revision, opening) {
            calls.push({ patch, revision, opening });
            const cancelled = deferred();
            startRequest(() => cancelled.reject(new Error('request cancelled')));
            return Promise.race([cancelled.promise, save ? save(patch, revision) : Promise.resolve({ ok: true, revision: revision + 1, fields: patch })]);
        },
    };
    const helper = factory(wire, {
        baseline: initial, revision: 1, opening: state.faqOpening, openingPath: 'faqOpening', existing,
        statePath: 'mountedActions.0.data', parameters: ['subject_id', 'show_on_home'],
        manual: ['title', 'body', 'sort_order'], saveMethod: 'saveFaqParameters', submitMethod: 'callMountedAction', ...config,
    });
    const formListeners = new Map();
    const form = {
        addEventListener(name, callback) { formListeners.set(name, callback); },
        removeEventListener(name) { formListeners.delete(name); },
        dispatchEvent(event) { formListeners.get(event.type)?.(event); window.dispatchEvent(event); },
    };
    form.closest = () => ({ dataset: { fiModalId: 'faq-test-modal' } });
    form.reportValidity = () => true;
    form.querySelector = (selector) => selector === '[x-ref="savingStay"]' ? { focus() { stayFocused++; } } : actualForms.get(selector.slice(1)) ?? null;
    form.inert = false;
    const dispatchSubmit = (target = form, method = config.submitMethod ?? 'callMountedAction') => {
        let prevented = false;
        helper.submit({ target, preventDefault() { prevented = true; }, stopImmediatePropagation() {} });
        if (prevented) return;
        nativeSubmits++;
        submittedMethods.push(method);
        submittedTargets.push(target);
        submitHooks = { native: deferred() };
        submitHooks.native.promise.catch(() => {});
        interceptors.get(method)({
            action: { promise: submitHooks.native.promise },
            onFinish: (callback) => { submitHooks.finish = callback; },
        });
        messageInterceptors.get(method)?.({
            message: { getActions: () => [{ name: '$refresh' }, { name: method }] },
            onSuccess(callback) { submitHooks.messageSuccess = callback; },
        });
        startRequest(() => { submitHooks.native.reject({ status: null, errors: null }); submitHooks.finish(); });
    };
    form.requestSubmit = () => {
        // HTML form submission aborts reentrant requests, without firing submit.
        if (firingSubmissionEvents || !form.reportValidity()) return;
        firingSubmissionEvents = true;
        try { dispatchSubmit(); } finally { firingSubmissionEvents = false; }
    };
    helper.$el = form;
    helper.$refs = {};
    helper.$nextTick = (callback) => callback();
    helper.init();
    return {
        helper, wire, state, calls, form, watchers, submittedMethods, submittedTargets, headerCalls,
        beginHeader(method) {
            headerCalls.push(method);
            const action = deferred(); let finish;
            action.promise.catch(() => {});
            interceptors.get(method)({ action: { promise: action.promise }, onFinish(callback) { finish = callback; } });
            startRequest(() => { action.reject({ status: null }); finish(); });
            return {
                resolve(result) { action.resolve(result); finish(); },
                reject(error) { action.reject(error); finish(); },
            };
        },
        addSubmitForm(id, method, valid = true) {
            const actualForm = { reportValidity: () => valid, requestSubmit() { if (valid) dispatchSubmit(actualForm, method); } };
            actualForms.set(id, actualForm);
            return actualForm;
        },
        get nativeSubmits() { return nativeSubmits; },
        get stayFocused() { return stayFocused; },
        get unmounts() { return unmounts; },
        mounts,
        get requestCancelled() { return requestHooks.cancelled; },
        get requestInterceptorActive() { return Boolean(requestInterceptor); },
        requestError(status) {
            let prevented = false;
            requestHooks.error({ response: { status }, preventDefault() { prevented = true; } });
            return prevented;
        },
        requestRedirect(url) {
            let prevented = false;
            requestHooks.redirect({ url, preventDefault() { prevented = true; } });
            return prevented;
        },
        requestFailure() { requestHooks.failure(); },
        beginRequest() { startRequest(() => {}); },
        set(field, value) { state[field] = value; watchers.get(field)?.(); },
        finish(result) { submitHooks.native.resolve(result); submitHooks.finish(); },
        finishRedirectEffect(result, redirect = result?.redirect, meta = {}) {
            // Installed Livewire 4: response payload -> snapshot sync -> redirect
            // effect/beforeunload -> morph -> action promise settlement.
            let sync;
            submitHooks.messageSuccess?.({
                payload: { effects: { returns: [null, result], returnsMeta: { 1: meta }, redirect } },
                onSync(callback) { sync = callback; },
            });
            sync?.();
            const suppressed = this.requestRedirect(redirect);
            let prompted = false;
            if (!suppressed) window.dispatchEvent({ type: 'beforeunload', preventDefault() { prompted = true; } });
            if (meta.errors || meta.status) submitHooks.native.reject({ status: meta.status ?? 422, errors: meta.errors ?? null });
            else submitHooks.native.resolve(result);
            submitHooks.finish();
            return { suppressed, prompted };
        },
        fail() { submitHooks.native.reject({ status: null, errors: null }); submitHooks.finish(); },
        rejected(errors = null, status = errors ? 422 : 403) {
            if (errors) wire.errorMessages = errors;
            submitHooks.native.reject({ status, errors }); submitHooks.finish();
        },
        lateFinish() { submitHooks.finish(); },
        async userSubmit() {
            firingSubmissionEvents = true;
            dispatchSubmit();
            // A trusted browser event checkpoints microtasks before its dispatch
            // returns to the native algorithm that clears this per-form flag.
            for (let checkpoint = 0; checkpoint < 5; checkpoint++) await Promise.resolve();
            firingSubmissionEvents = false;
        },
    };
}

function nativeCancel(m) {
    let closed = 0;
    return {
        get closed() { return closed; },
        click() {
            let stopped = false;
            const result = vm.runInNewContext(cancelCapture, {
                $event: { preventDefault() {}, stopImmediatePropagation() { stopped = true; } },
                requestLeave: (destination) => m.helper.requestLeave(destination),
                get disposed() { return m.helper.disposed; },
                close: () => { closed++; },
            });
            // Stock Cancel's bubble handler is direct close(), not close-modal.
            if (!stopped) closed++;
            return result;
        },
    };
}

const tests = [];
function test(name, callback) { tests.push([name, callback]); }

test('parameter patch never includes invalid manual draft', async () => {
    const m = mount();
    m.set('title', ''); m.set('body', 'Черновик'); m.set('sort_order', -1); m.set('subject_id', 3);
    await m.helper.settle();
    assert.equal(JSON.stringify(m.calls[0].patch), '{"subject_id":3}');
    assert.equal(m.state.body, 'Черновик');
    assert.equal(m.helper.changed('body'), true);
    assert.equal(m.helper.changed('subject_id'), false);
    m.helper.destroy();
});

test('create choices remain local until native submit', async () => {
    const m = mount({ existing: false });
    m.set('show_on_home', true); await tick();
    assert.equal(m.calls.length, 0);
    const result = m.helper.manual(); await tick();
    assert.equal(m.nativeSubmits, 1); assert.equal(m.form.inert, true);
    m.finish({ ok: true }); assert.equal(await result, true);
    m.helper.destroy();
});

test('delayed ACK preserves latest same-field intent and serial revision', async () => {
    const first = deferred();
    let count = 0;
    const m = mount({ save: (patch, revision) => ++count === 1 ? first.promise : Promise.resolve({ ok: true, revision: revision + 1, fields: patch }) });
    m.set('subject_id', 1); await tick();
    m.set('subject_id', 2); m.set('subject_id', 3);
    first.resolve({ ok: true, revision: 2, fields: { subject_id: 1 } });
    await m.helper.settle();
    assert.equal(m.calls.length, 2);
    assert.equal(m.calls[1].patch.subject_id, 3); assert.equal(m.calls[1].revision, 2);
    assert.equal(m.helper.baseline.subject_id, 3);
    m.helper.destroy();
});

test('a missed watch when reverting before ACK still saves the latest intent in serial order', async () => {
    const first = deferred(); const second = deferred(); let persisted = false; let requests = 0;
    const m = mount({ save: (patch) => {
        persisted = patch.show_on_home;
        return ++requests === 1 ? first.promise : second.promise;
    } });
    m.set('body', 'Manual draft'); m.set('show_on_home', true); await tick();
    assert.equal(persisted, true);
    // Native Livewire/Alpine observation may omit this return to its initial value.
    m.state.show_on_home = false;
    assert.equal(m.helper.pending.size, 0);
    first.resolve({ ok: true, revision: 2, fields: { show_on_home: true } }); await tick();
    assert.equal(m.calls.length, 2); assert.equal(m.calls[1].patch.show_on_home, false);
    assert.equal(m.calls[1].revision, 2); assert.equal(m.helper.busy, true); assert.equal(persisted, false);
    second.resolve({ ok: true, revision: 3, fields: { show_on_home: false } }); await m.helper.settle();
    assert.equal(m.calls.length, 2); assert.equal(m.helper.changed('show_on_home'), false);
    assert.equal(m.helper.revision, 3); assert.equal(m.helper.message, 'Сохранено');
    assert.equal(m.state.body, 'Manual draft'); assert.equal(m.helper.changed('body'), true);
    m.helper.destroy();
});

test('parameter normalization updates unchanged input once and does not enqueue an endless retry', async () => {
    const m = mount({ save: (patch, revision) => ({ ok: true, revision: revision + 1, fields: { subject_id: Number(patch.subject_id) } }) });
    m.set('subject_id', ' 4 '); await m.helper.settle(); await tick();
    assert.equal(m.calls.length, 1); assert.equal(m.state.subject_id, 4);
    assert.equal(m.helper.pending.size, 0); assert.equal(m.helper.changed('subject_id'), false);
    m.helper.destroy();
});

test('missed correction watch before a validation ACK retries only that newer input', async () => {
    const response = deferred(); let requests = 0;
    const m = mount({ save: (patch, revision) => ++requests === 1 ? response.promise : Promise.resolve({ ok: true, revision: revision + 1, fields: patch }) });
    m.set('subject_id', -1); await tick(); m.state.subject_id = 2;
    response.resolve({ ok: false }); await m.helper.settle();
    assert.equal(m.calls.length, 2); assert.equal(m.calls[1].patch.subject_id, 2);
    assert.equal(m.helper.changed('subject_id'), false); assert.equal(m.helper.failed.size, 0);
    m.helper.destroy();
});

test('correction before validation ACK is retried with latest value', async () => {
    const first = deferred(); let count = 0;
    const m = mount({ save: (patch) => ++count === 1 ? first.promise : Promise.resolve({ ok: true, revision: 2, fields: patch }) });
    m.set('subject_id', 999); await tick(); m.set('subject_id', null);
    first.resolve({ ok: false, errors: { subject_id: ['invalid'] } });
    await m.helper.settle();
    assert.equal(m.calls.length, 2); assert.equal(m.calls[1].patch.subject_id, null);
    assert.equal(m.helper.failed.size, 0); assert.equal(m.helper.stopped, false);
    m.helper.destroy();
});

test('validation without correction stops retry and retains draft', async () => {
    const m = mount({ save: () => Promise.resolve({ ok: false, errors: { subject_id: ['invalid'] } }) });
    m.set('body', 'Draft'); m.set('subject_id', 999); await m.helper.settle(); await tick();
    assert.equal(m.calls.length, 1); assert.equal(m.helper.failed.size, 1);
    assert.equal(m.state.body, 'Draft'); assert.equal(m.helper.stopped, false);
    m.helper.destroy();
});

test('unknown outcome never retries and terminal feedback survives input', async () => {
    const m = mount({ save: () => Promise.reject(new Error('network')) });
    m.set('subject_id', 1); await m.helper.settle();
    const terminal = m.helper.message;
    m.set('subject_id', 2); m.set('body', 'New draft'); await tick();
    assert.equal(m.calls.length, 1); assert.equal(m.helper.stopped, true); assert.equal(m.helper.message, terminal);
    m.helper.destroy();
});

test('CAS conflict remains stopped after further changes', async () => {
    const m = mount({ save: () => Promise.resolve({ ok: false, terminal: true, message: 'Conflict' }) });
    m.set('show_on_home', true); await m.helper.settle(); m.set('show_on_home', false);
    assert.equal(m.helper.message, 'Conflict'); assert.equal(m.helper.stopped, true);
    m.helper.destroy();
});

test('late ACK from another opening cannot change baseline', async () => {
    const first = deferred(); const m = mount({ save: () => first.promise });
    m.set('subject_id', 1); await tick(); m.state.faqOpening = 'opening-2';
    first.resolve({ ok: true, revision: 8, fields: { subject_id: 1 } }); await m.helper.settle();
    assert.equal(m.helper.revision, 1); assert.equal(m.helper.baseline.subject_id, null);
    m.helper.destroy();
});

test('clean leave blocks input while draining and leaves only after ACK', async () => {
    const first = deferred(); const m = mount({ save: () => first.promise }); let left = false;
    m.set('subject_id', 1); await tick();
    const leave = m.helper.requestLeave(() => { left = true; });
    assert.equal(m.form.inert, true); assert.equal(left, false);
    first.resolve({ ok: true, revision: 2, fields: { subject_id: 1 } }); await leave;
    assert.equal(left, true); assert.equal(m.helper.leaving, true);
    m.helper.destroy();
});

test('dirty close supports stay and explicit discard', async () => {
    const m = mount(); let left = 0;
    m.set('body', 'Draft'); await m.helper.requestLeave(() => left++);
    assert.equal(m.helper.guard, true); assert.equal(left, 0);
    await m.helper.resolveGuard('stay'); assert.equal(m.state.body, 'Draft');
    await m.helper.requestLeave(() => left++); await m.helper.resolveGuard('discard');
    assert.equal(left, 1); assert.equal(m.helper.leaving, true);
    m.helper.destroy();
});

test('guard Save drains then invokes native submit exactly once before leaving', async () => {
    const m = mount(); let left = false;
    m.set('body', 'Draft'); await m.helper.requestLeave(() => { left = true; });
    const saved = m.helper.resolveGuard('save'); await tick();
    assert.equal(m.nativeSubmits, 1); assert.equal(left, false); assert.equal(m.form.inert, true);
    m.finish({ ok: true }); await saved; assert.equal(left, true);
    m.helper.destroy();
});

test('native validation and network errors retain form and prevent guard navigation', async () => {
    for (const network of [false, true]) {
        const m = mount(); let left = false;
        m.set('title', 'Draft'); await m.helper.requestLeave(() => { left = true; });
        const saved = m.helper.resolveGuard('save'); await tick();
        if (network) m.fail(); else { m.wire.errorMessages = { title: ['invalid'] }; m.finish(null); }
        await saved;
        assert.equal(left, false); assert.equal(m.helper.stopped, network); assert.equal(m.form.inert, false);
        assert.equal(m.state.title, 'Draft'); m.helper.destroy();
    }
});

test('destroy removes watchers and rejects stale callbacks', async () => {
    const m = mount(); m.helper.destroy();
    assert.equal(m.watchers.size, 0); m.set('subject_id', 3); await tick(); assert.equal(m.calls.length, 0);
    assert.equal([...listeners.values()].flat().length, 0);
});

test('native close event is intercepted before close, then replayed once after discard', async () => {
    const m = mount(); let nativeClose = 0;
    const close = () => { nativeClose++; };
    window.addEventListener('close-modal', close);
    m.set('body', 'Draft');
    window.dispatchEvent(new CustomEvent('close-modal', { detail: { id: 'faq-test-modal' } }));
    await tick(); assert.equal(nativeClose, 0); assert.equal(m.helper.guard, true);
    await m.helper.resolveGuard('discard'); assert.equal(nativeClose, 1);
    window.removeEventListener('close-modal', close); m.helper.destroy();
});

test('full submit waits for parameter ACK and ignores a second submit', async () => {
    const first = deferred(); const m = mount({ save: () => first.promise });
    m.set('subject_id', 3); await tick();
    const saved = m.helper.manual();
    assert.equal(await m.helper.manual(), false); assert.equal(m.nativeSubmits, 0);
    first.resolve({ ok: true, revision: 2, fields: { subject_id: 3 } }); await tick();
    assert.equal(m.nativeSubmits, 1); m.finish({ ok: true }); assert.equal(await saved, true);
    m.helper.destroy();
});

test('contextual and main native submit survive the original submission event flag', async () => {
    for (const draft of [' ', 'Изменённый ответ']) {
        const m = mount(); m.set('body', draft);
        await m.userSubmit();
        assert.equal(m.helper.fullBusy, true); assert.equal(m.form.inert, true);
        await tick();
        assert.equal(m.nativeSubmits, 1);
        if (draft.trim()) {
            m.finish({ ok: true });
        } else {
            m.rejected({ 'mountedActions.0.data.body': ['required'] });
            await tick();
            assert.match(m.helper.message, /Не сохранено/);
            assert.equal(m.helper.stopped, false);
        }
        await tick();
        assert.equal(m.helper.fullBusy, false); assert.equal(m.helper.intent, false);
        assert.equal(m.form.inert, false); assert.equal(m.state.body, draft);
        m.helper.destroy();
    }
});

test('native validation before replayed submit cannot leave the form locked', async () => {
    const m = mount(); m.set('body', 'Draft');
    await m.userSubmit();
    // Simulate a native constraint changing before the next task, so the replay
    // never fires submit and therefore has no Livewire ACK to wait for.
    m.form.reportValidity = () => false;
    await tick();
    assert.equal(m.nativeSubmits, 0); assert.equal(m.helper.allowSubmit, false);
    assert.equal(m.helper.fullBusy, false); assert.equal(m.helper.intent, false);
    assert.equal(m.form.inert, false); assert.equal(m.helper.stopped, false);
    assert.equal(m.state.body, 'Draft'); assert.match(m.helper.message, /Не сохранено/);
    m.helper.destroy();
});

test('Livewire rejected status finishes without treating the errors API methods as validation', async () => {
    const m = mount(); m.set('body', 'Draft');
    const saved = m.helper.manual(); await tick();
    assert.equal(m.nativeSubmits, 1);
    m.wire.errorMessages = { 'mountedActions.0.data.body': ['old required error'] };
    m.rejected();
    assert.equal(await saved, false); assert.equal(m.helper.fullBusy, false);
    assert.equal(m.helper.stopped, true); assert.equal(m.form.inert, false);
    assert.equal(m.state.body, 'Draft');
    m.helper.destroy();
});

test('network rejection ignores stale validation and a late finish preserves terminal feedback', async () => {
    const m = mount(); m.set('body', 'Draft');
    m.wire.errorMessages = { 'mountedActions.0.data.body': ['old required error'] };
    const saved = m.helper.manual(); await tick(); m.fail();
    assert.equal(await saved, false); assert.equal(m.helper.stopped, true);
    const terminal = m.helper.message;
    m.lateFinish(); await tick(); m.set('body', 'New draft');
    assert.equal(m.helper.message, terminal); assert.equal(m.helper.fullBusy, false);
    assert.equal(m.helper.intent, false); assert.equal(m.form.inert, false);
    m.helper.destroy();
});

test('native direct Cancel keeps dirty draft until stay or explicit discard', async () => {
    const m = mount(); const cancel = nativeCancel(m);
    m.set('body', 'Draft'); await cancel.click();
    assert.equal(cancel.closed, 0); assert.equal(m.helper.guard, true);
    assert.equal(m.state.body, 'Draft');
    await m.helper.resolveGuard('stay'); assert.equal(cancel.closed, 0);
    await cancel.click(); await m.helper.resolveGuard('discard');
    assert.equal(cancel.closed, 1); assert.equal(m.state.body, 'Draft');
    assert.equal(m.calls.length, 0); m.helper.destroy();
});

test('native direct Cancel drains pending parameters before a clean close without a second write', async () => {
    const first = deferred(); const m = mount({ save: () => first.promise }); const cancel = nativeCancel(m);
    m.set('subject_id', 3); await tick();
    const closing = cancel.click();
    assert.equal(m.form.inert, true); assert.equal(cancel.closed, 0);
    first.resolve({ ok: true, revision: 2, fields: { subject_id: 3 } }); await closing;
    assert.equal(cancel.closed, 1); assert.equal(m.helper.guard, false);
    assert.equal(m.calls.length, 1); m.helper.destroy();
});

test('native direct Cancel Save retains validation draft and successful native unmount needs no extra close', async () => {
    const m = mount(); const cancel = nativeCancel(m);
    m.set('body', ' '); await cancel.click();
    const invalid = m.helper.resolveGuard('save'); await tick();
    m.rejected({ body: ['required'] }); await invalid;
    assert.equal(cancel.closed, 0); assert.equal(m.state.body, ' ');
    assert.equal(m.helper.stopped, false); assert.equal(m.helper.guard, true);
    m.set('body', 'Corrected');
    const saved = m.helper.resolveGuard('save'); await tick();
    assert.equal(m.nativeSubmits, 2);
    // Filament's successful full operation unmounts the modal before settling.
    m.helper.destroy(); m.finish({ ok: true }); await saved;
    assert.equal(cancel.closed, 0);
});

test('native direct Cancel cannot discard a stopped draft without an explicit choice', async () => {
    const m = mount({ save: () => Promise.reject(new Error('network')) }); const cancel = nativeCancel(m);
    m.set('subject_id', 3); await m.helper.settle();
    const terminal = m.helper.message;
    await cancel.click(); assert.equal(cancel.closed, 0); assert.equal(m.helper.guard, true);
    assert.equal(m.helper.message, terminal);
    await m.helper.resolveGuard('stay'); assert.equal(cancel.closed, 0);
    m.helper.destroy();
});

test('guard focuses Stay inside its owner form when nested Alpine refs are unavailable', async () => {
    const m = mount(); const cancel = nativeCancel(m);
    assert.equal(m.helper.$refs.savingStay, undefined);
    m.set('body', 'Draft'); await cancel.click();
    assert.equal(m.helper.guard, true); assert.equal(m.stayFocused, 1);
    assert.equal(cancel.closed, 0); assert.equal(m.state.body, 'Draft');
    m.helper.destroy();
});

test('guard Save from child FIELDSET context always submits the captured owner form', async () => {
    const m = mount(); const cancel = nativeCancel(m); const fieldset = { tagName: 'FIELDSET' };
    let child;
    child = new Proxy(m.helper, {
        get(target, key) {
            if (key === '$el') return fieldset;
            const value = Reflect.get(target, key);
            return typeof value === 'function' ? value.bind(child) : value;
        },
        set(target, key, value) { return Reflect.set(target, key, value); },
    });
    m.set('body', 'Draft'); await cancel.click();
    assert.equal(child.$el.tagName, 'FIELDSET'); assert.equal(child.form, m.form);
    const saved = child.resolveGuard('save'); await tick();
    assert.equal(m.nativeSubmits, 1); assert.equal(m.form.inert, true);
    assert.equal(fieldset.inert, undefined); assert.equal(cancel.closed, 0);
    m.finish({ ok: true }); await saved;
    assert.equal(m.helper.stopped, false); assert.equal(m.helper.fullBusy, false);
    assert.equal(m.helper.intent, false); assert.equal(m.form.inert, false);
    assert.equal(cancel.closed, 1); assert.equal(m.helper.leaving, true);
    m.helper.destroy();
});

test('discard drains new parameter intents and blocks a second discard until ACK', async () => {
    const first = deferred(); let count = 0;
    const m = mount({ save: (patch, revision) => ++count === 1 ? first.promise : Promise.resolve({ ok: true, revision: revision + 1, fields: patch }) });
    let left = 0;
    m.set('body', 'Draft'); await m.helper.requestLeave(() => { left++; });
    m.set('subject_id', 1); await tick(); m.set('subject_id', 2);
    const discard = m.helper.resolveGuard('discard');
    assert.equal(m.form.inert, true); assert.equal(m.helper.intent, true); assert.equal(left, 0);
    await m.helper.resolveGuard('discard'); assert.equal(left, 0);
    first.resolve({ ok: true, revision: 2, fields: { subject_id: 1 } }); await discard;
    assert.equal(m.calls.length, 2); assert.equal(m.calls[1].patch.subject_id, 2);
    assert.equal(m.calls[1].revision, 2); assert.equal(left, 1);
    assert.equal(m.helper.guard, false); assert.equal(m.helper.leaving, true);
    m.helper.destroy();
});

test('new failure during discard drain retains guard and requires another explicit discard', async () => {
    for (const kind of ['unknown', 'conflict', 'validation']) {
        const response = deferred(); const m = mount({ save: () => response.promise }); let left = 0;
        m.set('body', 'Draft'); await m.helper.requestLeave(() => { left++; });
        m.set('subject_id', 3); await tick();
        const discard = m.helper.resolveGuard('discard');
        if (kind === 'unknown') response.reject(new Error('network'));
        else response.resolve(kind === 'conflict' ? { ok: false, terminal: true, message: 'Conflict' } : { ok: false, errors: { subject_id: ['invalid'] } });
        await discard;
        assert.equal(left, 0); assert.equal(m.helper.guard, true); assert.equal(m.helper.intent, false);
        assert.equal(m.form.inert, false); assert.equal(m.state.body, 'Draft');
        const failure = m.helper.message;
        await m.helper.resolveGuard('discard');
        assert.equal(left, 1); assert.equal(m.calls.length, 1); assert.equal(m.helper.message, failure);
        m.helper.destroy();
    }
});

test('discard of an already-known stopped outcome leaves without retrying pending parameters', async () => {
    const m = mount({ save: () => Promise.reject(new Error('network')) }); let left = 0;
    m.set('subject_id', 1); await m.helper.settle();
    await m.helper.requestLeave(() => { left++; });
    assert.equal(m.helper.guard, true); assert.equal(m.helper.stopped, true);
    m.set('subject_id', 2);
    const failure = m.helper.message;
    await m.helper.resolveGuard('discard');
    assert.equal(left, 1); assert.equal(m.calls.length, 1);
    assert.equal(m.helper.message, failure); m.helper.destroy();
});

test('401 and 419 preserve the live manual form and session reason despite late action callbacks', async () => {
    for (const status of [401, 419]) {
        const m = mount(); m.set('body', 'Private draft');
        const saved = m.helper.manual(); await tick();
        assert.equal(m.requestError(status), true);
        const reason = m.helper.message;
        assert.match(reason, /Сессия завершилась/);
        assert.equal(await saved, false); assert.equal(m.helper.stopped, true);
        assert.equal(m.form.inert, false); assert.equal(m.helper.fullBusy, false);
        m.finish({ ok: true }); await tick(); m.set('body', 'Corrected draft');
        assert.equal(m.helper.message, reason); assert.equal(m.state.body, 'Corrected draft');
        assert.equal(await m.helper.manual(), false); assert.equal(m.nativeSubmits, 1);
        m.helper.destroy(); assert.equal(m.requestInterceptorActive, false);
    }
});

test('parameter HTTP failure is sticky across late success ACK, input and discard drain', async () => {
    const response = deferred(); const m = mount({ save: () => response.promise }); let left = 0;
    m.set('body', 'Draft'); await m.helper.requestLeave(() => { left++; });
    m.set('subject_id', 1); await tick(); const discard = m.helper.resolveGuard('discard');
    assert.equal(m.requestError(419), true); const reason = m.helper.message;
    response.resolve({ ok: true, revision: 2, fields: { subject_id: 1 } }); await discard;
    assert.equal(left, 0); assert.equal(m.helper.guard, true);
    assert.equal(m.helper.baseline.subject_id, null); assert.equal(m.helper.revision, 1);
    m.set('subject_id', 2); await tick();
    assert.equal(m.helper.message, reason); assert.equal(m.calls.length, 1);
    m.helper.destroy();
});

test('auth redirect suppression cancels the request, settles waiters and preserves its terminal reason', async () => {
    for (const full of [false, true]) {
        const response = deferred(); const m = mount({ save: () => response.promise });
        m.set('body', 'Draft');
        const saved = full ? m.helper.manual() : (m.set('subject_id', 1), m.helper.settle());
        await tick();
        assert.equal(m.requestRedirect('/auth?mode=login'), true); assert.equal(m.requestCancelled, true);
        const reason = m.helper.message; await saved; await tick();
        assert.match(reason, /Сессия завершилась/); assert.equal(m.helper.message, reason);
        assert.equal(m.helper.stopped, true); assert.equal(m.form.inert, false);
        assert.equal(m.helper.fullBusy, false); assert.equal(m.helper.busy, false);
        m.set('subject_id', 3); await tick();
        assert.equal(m.helper.message, reason); assert.equal(m.calls.length, full ? 0 : 1);
        m.helper.destroy();
    }
});

test('403, 429 and network outcomes remain terminal even with late ACK and prior validation', async () => {
    for (const kind of [403, 429, 'network']) {
        const m = mount(); m.set('body', 'Draft'); m.wire.errorMessages = { body: ['old error'] };
        const saved = m.helper.manual(); await tick();
        if (kind === 'network') m.requestFailure(); else assert.equal(m.requestError(kind), true);
        const reason = m.helper.message; assert.equal(await saved, false);
        m.fail(); await tick();
        assert.equal(m.helper.message, reason); assert.equal(m.helper.stopped, true);
        assert.equal(m.form.inert, false); assert.equal(m.state.body, 'Draft');
        m.helper.destroy();
    }
});

test('legitimate success redirects remain native and request interception is cleaned up', async () => {
    const m = mount(); const saved = m.helper.manual(); await tick();
    assert.equal(m.requestRedirect('/admin/tasks'), false); assert.equal(m.requestCancelled, false);
    assert.equal(m.requestRedirect('https://external.example/auth'), false);
    assert.equal(m.helper.stopped, false); m.finish({ ok: true }); assert.equal(await saved, true);
    m.helper.destroy(); assert.equal(m.requestInterceptorActive, false);
    assert.equal(m.requestRedirect('/auth'), false);
});

test('confirmed first create settles accepted baseline before native redirect beforeunload', async () => {
    const m = mount({ existing: false, config: { standalone: true } });
    m.set('body', 'Created body'); m.set('show_on_home', true);
    let prompted = false;
    window.dispatchEvent({ type: 'beforeunload', preventDefault() { prompted = true; } });
    assert.equal(prompted, true);
    const saved = m.helper.manual(); await tick();
    const fields = { ...m.helper.fullSnapshot };
    const result = m.finishRedirectEffect({ ok: true, revision: 0, fields, redirect: '/admin/reviews/12?returnTo=%2Fadmin%2Freviews' });
    assert.deepEqual(result, { suppressed: false, prompted: false });
    assert.equal(await saved, true); assert.equal(m.nativeSubmits, 1); assert.equal(m.calls.length, 0);
    assert.equal(m.helper.dirty(), false); assert.equal(m.helper.revision, 0);
    assert.equal(m.helper.leaving, true); assert.equal(m.form.inert, true);
    assert.equal(m.helper.message, 'Сохранено');
    m.lateFinish(); await tick(); assert.equal(m.form.inert, true);
    m.helper.destroy();
});

test('create guard Save honors the confirmed canonical redirect instead of a second destination', async () => {
    const m = mount({ existing: false, config: { standalone: true } }); let destinations = 0;
    m.set('body', 'Draft'); await m.helper.requestLeave(() => { destinations++; });
    const saved = m.helper.resolveGuard('save'); await tick();
    m.finishRedirectEffect({ ok: true, fields: { ...m.helper.fullSnapshot }, redirect: '/admin/reviews/13' });
    await saved; assert.equal(destinations, 0); assert.equal(m.helper.leaving, true);
    m.helper.destroy();
});

test('create guard Save keeps a newer draft after canonical ACK and beforeunload instead of running its old destination', async () => {
    const m = mount({ existing: false, config: { standalone: true } }); let destinations = 0;
    m.set('body', 'Submitted'); await m.helper.requestLeave(() => { destinations++; });
    const saved = m.helper.resolveGuard('save'); await tick();
    const fields = { ...m.helper.fullSnapshot };
    m.set('body', 'Newer draft');
    const result = m.finishRedirectEffect({ ok: true, fields, redirect: '/admin/reviews/13' });
    await saved;
    assert.equal(result.prompted, true); assert.equal(result.suppressed, false);
    assert.equal(destinations, 0); assert.equal(m.helper.leaving, false);
    assert.equal(m.helper.guard, true); assert.equal(m.helper.ordinaryDirty(), true);
    assert.equal(m.state.body, 'Newer draft'); assert.equal(m.helper.baseline.body, 'Submitted');
    assert.equal(m.form.inert, false); assert.equal(m.helper.fullBusy, false);
    assert.equal(m.nativeSubmits, 1); assert.equal(m.stayFocused, 2);
    m.helper.destroy();
});

test('create validation and unknown outcomes retain beforeunload protection and drafts', async () => {
    for (const validation of [false, true]) {
        const m = mount({ existing: false, config: { standalone: true } }); m.set('body', 'Draft');
        const saved = m.helper.manual(); await tick();
        if (validation) m.rejected({ body: ['Required'] }); else m.fail();
        assert.equal(await saved, false); assert.equal(m.helper.stopped, !validation);
        let prompted = false;
        window.dispatchEvent({ type: 'beforeunload', preventDefault() { prompted = true; } });
        assert.equal(prompted, true); assert.equal(m.helper.leaving, false); assert.equal(m.state.body, 'Draft');
        m.helper.destroy();
    }
});

test('stale or terminal create response cannot release its guard before redirect effects', async () => {
    for (const kind of ['opening', 'auth', 'unknown', 'validation', 'forbidden', 'noACK']) {
        const m = mount({ existing: false, config: { standalone: true } }); m.set('body', 'Draft');
        const saved = m.helper.manual(); await tick();
        if (kind === 'opening') m.state.faqOpening = 'new-opening';
        if (kind === 'unknown') m.requestFailure();
        const result = m.finishRedirectEffect(kind === 'noACK' ? null : { ok: true, fields: { body: 'Draft' }, redirect: kind === 'auth' ? '/auth' : '/admin/reviews/14' },
            kind === 'auth' ? '/auth' : '/admin/reviews/14', kind === 'validation' ? { errors: { body: ['Required'] } } : kind === 'forbidden' ? { status: 403 } : {});
        await tick(); await saved;
        assert.equal(m.helper.leaving, false); assert.equal(m.state.body, 'Draft');
        assert.equal(result.suppressed, kind === 'auth');
        if (kind !== 'auth') assert.equal(result.prompted, true);
        if (kind === 'auth') assert.match(m.helper.message, /Сессия завершилась/);
        if (kind === 'forbidden' || kind === 'noACK') assert.equal(m.helper.stopped, true);
        m.helper.destroy();
    }
});

test('unmatched or external redirect effects cannot authorize the create transition', async () => {
    for (const redirect of ['/admin/tasks', 'https://external.example/reviews/12']) {
        const m = mount({ existing: false, config: { standalone: true } }); m.set('body', 'Draft');
        const saved = m.helper.manual(); await tick();
        const result = m.finishRedirectEffect({ ok: true, fields: { ...m.helper.fullSnapshot }, redirect: '/admin/reviews/12' }, redirect);
        assert.equal(result.prompted, true); assert.equal(result.suppressed, false);
        assert.equal(m.helper.leaving, false); await saved; m.helper.destroy();
    }
});

test('create ACK with newer local input keeps that draft guarded through redirect effect', async () => {
    const m = mount({ existing: false, config: { standalone: true } }); m.set('body', 'Submitted');
    const saved = m.helper.manual(); await tick(); const fields = { ...m.helper.fullSnapshot };
    m.set('body', 'Latest draft');
    const result = m.finishRedirectEffect({ ok: true, fields, redirect: '/admin/reviews/15' });
    assert.equal(await saved, true); assert.equal(result.prompted, true);
    assert.equal(m.helper.leaving, false); assert.equal(m.helper.changed('body'), true);
    assert.equal(m.state.body, 'Latest draft'); m.helper.destroy();
});

test('JSON snapshots handle reactive compound values and equality compares structure', async () => {
    const m = mount();
    const proxy = new Proxy([{ path: 'a', options: { width: 10 } }], {});
    m.set('subject_id', proxy); await m.helper.settle();
    assert.equal(m.calls.length, 1); assert.equal(JSON.stringify(m.calls[0].patch.subject_id), JSON.stringify(proxy));
    assert.equal(m.helper.same({ a: 1, b: { x: 2 } }, { b: { x: 2 }, a: 1 }), true);
    assert.equal(m.helper.same({ a: 1 }, { a: 2 }), false);
    assert.equal(m.helper.same([1, 2], [2, 1]), false);
    assert.equal(m.helper.same({}, []), false); m.helper.destroy();
});

test('review derived fields rebase clean display name and preserve a later dirty draft', async () => {
    for (const dirty of [false, true]) {
        const response = deferred();
        const m = mount({ values: { displayName: 'Saved name', isAnonymous: false }, config: { parameters: ['isAnonymous'], manual: ['displayName'] }, save: () => response.promise });
        m.set('isAnonymous', true); await tick();
        if (dirty) m.set('displayName', 'Latest draft');
        response.resolve({ ok: true, revision: 2, fields: { isAnonymous: true }, derivedFields: { displayName: 'Anonymous' } }); await m.helper.settle();
        assert.equal(m.state.displayName, dirty ? 'Latest draft' : 'Anonymous');
        assert.equal(m.helper.baseline.displayName, 'Anonymous');
        assert.equal(m.helper.changed('displayName'), dirty); m.helper.destroy();
    }
});

test('author photo and home ACK preserve dirty manual name and bio including later input', async () => {
    for (const parameter of ['photo_path', 'home_role_text']) {
        for (const late of [false, true]) {
            const response = deferred();
            const m = mount({ values: { name: 'Saved name', bio: 'Saved bio', [parameter]: null }, config: { parameters: [parameter], manual: ['name', 'bio'] }, save: () => response.promise });
            if (!late) { m.set('name', 'Draft name'); m.set('bio', 'Draft bio'); }
            m.set(parameter, 'Saved parameter'); await tick();
            if (late) { m.set('name', 'Draft name'); m.set('bio', 'Draft bio'); }
            response.resolve({ ok: true, revision: 'new-hash', fields: { [parameter]: 'Saved parameter' } }); await m.helper.settle();
            assert.equal(m.state.name, 'Draft name'); assert.equal(m.state.bio, 'Draft bio');
            assert.equal(m.helper.baseline.name, 'Saved name'); assert.equal(m.helper.baseline.bio, 'Saved bio');
            assert.equal(m.helper.changed('name'), true); assert.equal(m.helper.changed('bio'), true);
            assert.equal(m.helper.changed(parameter), false); m.helper.destroy();
        }
    }
});

test('native upload processing defers parameter and full saves until ready', async () => {
    const m = mount({ config: { uploads: true } });
    m.form.dispatchEvent(new CustomEvent('form-processing-started'));
    m.set('subject_id', new Proxy({ file: 'uploaded-path' }, {})); await tick();
    assert.equal(m.calls.length, 0);
    const saved = m.helper.manual(); await tick(); assert.equal(m.nativeSubmits, 0);
    m.form.dispatchEvent(new CustomEvent('form-processing-finished')); await tick();
    assert.equal(m.calls.length, 1); assert.equal(m.nativeSubmits, 1);
    m.finish({ ok: true }); assert.equal(await saved, true); m.helper.destroy();
});

test('upload finish cannot erase a terminal reason or retry after stop', async () => {
    const m = mount({ config: { uploads: true } });
    m.form.dispatchEvent(new CustomEvent('form-processing-started'));
    m.set('subject_id', 2); m.helper.stop('Session expired');
    m.form.dispatchEvent(new CustomEvent('form-processing-finished')); await tick();
    assert.equal(m.helper.message, 'Session expired'); assert.equal(m.calls.length, 0); m.helper.destroy();
});

test('record selection restores current ID and draft before guard; Stay does not switch', async () => {
    const m = mount({ values: { id: 1 }, config: { recordId: 1, switchAction: 'author', switchArgument: 'authorId' } });
    m.set('body', 'Draft'); const target = { value: '2' }; let stopped = false;
    await m.helper.switchRecord({ target, preventDefault() {}, stopImmediatePropagation() { stopped = true; } });
    assert.equal(stopped, true); assert.equal(target.value, '1'); assert.equal(m.state.id, 1);
    assert.equal(m.helper.guard, true); assert.equal(m.unmounts, 0); assert.equal(m.mounts.length, 0);
    await m.helper.resolveGuard('stay'); assert.equal(m.state.body, 'Draft'); m.helper.destroy();
});

test('record discard uses native unmount/mount once; guard Save remounts after confirmed native unmount', async () => {
    for (const save of [false, true]) {
        const m = mount({ config: { recordId: 1, switchAction: 'author', switchArgument: 'authorId' } });
        m.set('body', 'Draft'); await m.helper.switchRecord({ target: { value: '2' }, preventDefault() {}, stopImmediatePropagation() {} });
        if (save) {
            const saved = m.helper.resolveGuard('save'); await tick();
            assert.equal(m.nativeSubmits, 1); assert.equal(m.mounts.length, 0);
            m.helper.destroy(); m.finish({ ok: true }); await saved;
            assert.equal(m.unmounts, 0);
        } else {
            await m.helper.resolveGuard('discard'); assert.equal(m.unmounts, 1);
        }
        assert.equal(m.mounts.length, 1); assert.equal(m.mounts[0].name, 'author');
        assert.equal(m.mounts[0].arguments.authorId, 2);
    }
});

test('failed record unmount keeps the current live draft and stops transition', async () => {
    const m = mount({ unmountError: true, config: { recordId: 1, switchAction: 'author', switchArgument: 'authorId' } });
    m.set('body', 'Draft'); await m.helper.switchRecord({ target: { value: '2' }, preventDefault() {}, stopImmediatePropagation() {} });
    await m.helper.resolveGuard('discard');
    assert.equal(m.mounts.length, 0); assert.equal(m.state.body, 'Draft'); assert.equal(m.helper.stopped, true);
    assert.equal(m.form.inert, false); m.helper.destroy();
});

test('author hash errors are terminal rather than editable validation', async () => {
    const m = mount({ config: { conflictField: 'expectedHash' } });
    const saved = m.helper.manual(); await tick();
    m.rejected({ 'mountedActions.0.data.expectedHash': ['Author changed'] });
    assert.equal(await saved, false); assert.equal(m.helper.stopped, true);
    assert.equal(m.helper.message, 'Author changed'); m.helper.destroy();
});





test('upload starting during parameter ACK wait blocks both full submit and clean leave', async () => {
    for (const full of [false, true]) {
        const response = deferred(); const m = mount({ save: () => response.promise, config: { uploads: true } }); let left = false;
        m.set('subject_id', 1); await tick();
        const waiting = full ? m.helper.manual() : m.helper.requestLeave(() => { left = true; });
        m.form.dispatchEvent(new CustomEvent('form-processing-started'));
        response.resolve({ ok: true, revision: 2, fields: { subject_id: 1 } }); await tick();
        assert.equal(m.helper.processing, 1); assert.equal(m.nativeSubmits, 0); assert.equal(left, false);
        assert.equal(m.helper.message, 'Загружается файл…');
        m.form.dispatchEvent(new CustomEvent('form-processing-finished')); await tick();
        if (full) { assert.equal(m.nativeSubmits, 1); assert.equal(m.helper.message, 'Сохраняется…'); m.finish({ ok: true }); }
        await waiting;
        assert.equal(full ? m.nativeSubmits : Number(left), 1); m.helper.destroy();
    }
});

test('upload starting in the deferred native handoff task cannot submit early', async () => {
    const m = mount({ config: { uploads: true } }); const saved = m.helper.manual();
    for (let checkpoint = 0; checkpoint < 3; checkpoint++) await Promise.resolve();
    assert.equal(m.helper.fullBusy, true);
    m.form.dispatchEvent(new CustomEvent('form-processing-started')); await tick();
    assert.equal(m.nativeSubmits, 0); assert.equal(m.form.inert, true);
    m.form.dispatchEvent(new CustomEvent('form-processing-finished')); await tick();
    assert.equal(m.nativeSubmits, 1); m.finish({ ok: true }); assert.equal(await saved, true); m.helper.destroy();
});


test('processing request failure during discard requires another decision without upload retry', async () => {
    let left = 0; const m = mount({ config: { uploads: true } });
    m.set('body', 'Draft'); await m.helper.requestLeave(() => { left++; });
    m.form.dispatchEvent(new CustomEvent('form-processing-started')); m.beginRequest();
    const discard = m.helper.resolveGuard('discard');
    assert.equal(m.form.inert, true); m.requestFailure(); await discard;
    assert.equal(left, 0); assert.equal(m.helper.guard, true); assert.equal(m.helper.processing, 1);
    assert.equal(m.helper.stopped, true); assert.equal(m.form.inert, false);
    const reason = m.helper.message; await m.helper.resolveGuard('discard');
    assert.equal(left, 1); assert.equal(m.calls.length, 0); assert.equal(m.helper.message, reason);
    m.helper.destroy();
});

test('standalone full ACK updates only accepted baseline and keeps later input dirty', async () => {
    const m = mount({ config: { standalone: true } });
    m.set('body', 'Submitted draft'); const saving = m.helper.manual(); await tick();
    assert.equal(m.nativeSubmits, 1); assert.equal(m.form.inert, true);
    m.set('body', 'Latest input');
    m.finish({ ok: true, revision: 9, fields: { body: 'Submitted draft' } });
    assert.equal(await saving, true); assert.equal(m.helper.revision, 9);
    assert.equal(m.state.body, 'Latest input'); assert.equal(m.helper.changed('body'), true);
    assert.equal(m.helper.baseline.title, 'Вопрос'); assert.equal(m.form.inert, false);
    m.helper.destroy();
});

test('standalone guard Save retains its destination until full ACK and leaves exactly once', async () => {
    let destinations = 0; const m = mount({ config: { standalone: true } });
    m.set('body', 'Standalone dirty text'); await m.helper.requestLeave(() => { destinations++; });
    const leaving = m.helper.resolveGuard('save'); await tick();
    assert.equal(destinations, 0); assert.equal(m.nativeSubmits, 1); assert.equal(m.form.inert, true);
    m.finish({ ok: true, revision: 3, fields: { body: 'Standalone dirty text' } }); await leaving;
    assert.equal(destinations, 1); assert.equal(m.helper.leaving, true);
    assert.equal(m.helper.dirty(), false); assert.equal(m.helper.message, 'Сохранено');
    m.helper.destroy();
});

test('existing standalone guard Save rechecks ordinary draft after ACK before its destination', async () => {
    const m = mount({ config: { standalone: true } }); let destinations = 0;
    m.set('body', 'Submitted'); await m.helper.requestLeave(() => { destinations++; });
    const saved = m.helper.resolveGuard('save'); await tick();
    m.set('body', 'Newer draft');
    m.finish({ ok: true, revision: 2, fields: { body: 'Submitted' } }); await saved;
    assert.equal(destinations, 0); assert.equal(m.helper.leaving, false);
    assert.equal(m.helper.guard, true); assert.equal(m.helper.ordinaryDirty(), true);
    assert.equal(m.state.body, 'Newer draft'); assert.equal(m.form.inert, false);
    assert.equal(m.helper.revision, 2); assert.equal(m.nativeSubmits, 1);
    m.helper.resolveGuard('stay'); assert.equal(m.helper.guard, false);
    assert.equal(m.state.body, 'Newer draft'); m.helper.destroy();
});

test('standalone normalization applies only to the accepted unchanged input', async () => {
    for (const latest of [false, true]) {
        const m = mount({ config: { standalone: true } }); m.set('title', 'Ignored anonymous name');
        const saving = m.helper.manual(); await tick();
        if (latest) m.set('title', 'Latest name');
        m.finish({ ok: true, fields: { title: 'Ignored anonymous name' }, normalized: { title: 'Anonymous' } });
        assert.equal(await saving, true); assert.equal(m.state.title, latest ? 'Latest name' : 'Anonymous');
        assert.equal(m.helper.changed('title'), latest); m.helper.destroy();
    }
});

test('late standalone ACK from an old opening cannot normalize the new live form', async () => {
    const m = mount({ config: { standalone: true } }); m.set('title', 'Submitted');
    const saving = m.helper.manual(); await tick(); m.state.faqOpening = 'other-opening';
    m.finish({ ok: true, revision: 99, fields: { title: 'Submitted' }, normalized: { title: 'Old normalized name' } });
    assert.equal(await saving, false); assert.equal(m.state.title, 'Submitted');
    assert.equal(m.helper.revision, 1); m.helper.destroy();
});

test('failed standalone refresh transition unlocks its guarded draft without retrying a write', async () => {
    const m = mount({ config: { standalone: true } }); m.set('body', 'Draft');
    await m.helper.requestLeave(() => { throw new Error('network'); });
    await m.helper.resolveGuard('discard');
    assert.equal(m.helper.guard, true); assert.equal(m.helper.stopped, true);
    assert.equal(m.helper.leaving, false); assert.equal(m.form.inert, false);
    assert.equal(m.state.body, 'Draft'); assert.equal(m.calls.length, 0); m.helper.destroy();
});

test('browser unload is guarded during server image preparation even before a field changes', () => {
    const m = mount({ config: { preparationPath: 'savingPreparation' } });
    m.set('savingPreparation', { pending: true, error: null }); let prevented = false;
    window.dispatchEvent({ type: 'beforeunload', preventDefault() { prevented = true; } });
    assert.equal(prevented, true); m.helper.destroy();
});

test('standalone validation can be corrected and resubmitted without replacing its draft', async () => {
    const m = mount({ config: { standalone: true } }); m.set('body', ' ');
    const refused = m.helper.manual(); await tick(); m.rejected({ 'mountedActions.0.data.body': ['Required'] });
    assert.equal(await refused, false); assert.equal(m.helper.stopped, false); assert.equal(m.state.body, ' ');
    m.wire.errorMessages = {}; m.set('body', 'Correction');
    const corrected = m.helper.manual(); await tick();
    m.finish({ ok: true, revision: 3, fields: { body: 'Correction' } });
    assert.equal(await corrected, true); assert.equal(m.helper.changed('body'), false); assert.equal(m.nativeSubmits, 2);
    m.helper.destroy();
});

test('scope and target are sent as one group while manual text survives intermediate validation', async () => {
    const m = mount({ values: { scope: 'all', subjectId: null, courseId: null },
        config: { parameters: ['scope', 'subjectId', 'courseId'], groups: [['scope', 'subjectId', 'courseId']] },
        save: (patch, revision) => patch.scope === 'subject' && !patch.subjectId ? { ok: false } : { ok: true, revision: revision + 1, fields: patch } });
    m.set('body', 'Manual draft'); m.set('scope', 'subject'); await m.helper.settle();
    assert.deepEqual(JSON.parse(JSON.stringify(m.calls[0].patch)), { scope: 'subject', subjectId: null, courseId: null });
    assert.equal(m.state.body, 'Manual draft'); assert.equal(m.helper.failed.size, 3);
    m.set('subjectId', 4); await m.helper.settle();
    assert.equal(m.calls[1].patch.subjectId, 4); assert.equal(m.helper.failed.size, 0);
    assert.equal(m.helper.changed('body'), true); m.helper.destroy();
});

test('review prepared asset barrier outlives transport finish and drains before full standalone submit', async () => {
    const m = mount({ values: { photo: {}, savingPreparation: { pending: false, error: null } },
        config: { standalone: true, parameters: ['photo'], uploads: true, preparationPath: 'savingPreparation' } });
    m.form.dispatchEvent(new CustomEvent('form-processing-started'));
    m.set('savingPreparation', { pending: true, error: null });
    m.set('photo', { file: 'review-authors/prepared.webp' });
    m.form.dispatchEvent(new CustomEvent('form-processing-finished'));
    const saving = m.helper.manual(); await tick();
    assert.equal(m.calls.length, 0); assert.equal(m.nativeSubmits, 0);
    m.set('savingPreparation', { pending: false, error: null }); await tick();
    assert.equal(m.calls.length, 1); assert.equal(m.nativeSubmits, 1);
    m.finish({ ok: true, revision: 3, fields: { photo: m.state.photo } });
    assert.equal(await saving, true); m.helper.destroy();
});

test('preparation starting during an ACK wait blocks leave and failure preserves its terminal reason', async () => {
    const response = deferred(); let left = 0;
    const m = mount({ save: () => response.promise, config: { preparationPath: 'savingPreparation' } });
    m.set('subject_id', 4); await tick(); const leaving = m.helper.requestLeave(() => { left++; });
    m.set('savingPreparation', { pending: true, error: null });
    response.resolve({ ok: true, revision: 2, fields: { subject_id: 4 } }); await tick();
    assert.equal(left, 0); m.set('savingPreparation', { pending: false, error: 'Preparation failed' }); await leaving;
    assert.equal(m.helper.guard, true); assert.equal(m.helper.stopped, true); assert.equal(left, 0);
    m.set('savingPreparation', { pending: false, error: null });
    assert.equal(m.helper.message, 'Preparation failed'); await m.helper.resolveGuard('discard');
    assert.equal(left, 1); assert.equal(m.calls.length, 1); m.helper.destroy();
});

test('explicit standalone terminal ACK is not mistaken for old field validation', async () => {
    const m = mount({ config: { standalone: true } }); m.wire.errorMessages = { body: ['Old validation'] };
    const saving = m.helper.manual(); await tick();
    m.finish({ ok: false, terminal: true, message: 'Review changed' });
    assert.equal(await saving, false); assert.equal(m.helper.stopped, true); assert.equal(m.helper.message, 'Review changed');
    assert.equal(m.form.inert, false); m.helper.destroy();
});

function mountUser() {
    const m = mount({ values: { 'profileData.name': 'Stored', 'profileData.gender': null, 'profileData.birth_date': null,
        'tagsData.tags': '', 'managerData.manager_id': null, 'commentData.comment': '' },
        save: (patch) => ({ ok: true, revision: 'sha2', fields: patch }),
        config: { statePath: '', standalone: true, revision: 'sha1',
            parameters: ['profileData.gender', 'profileData.birth_date'], manual: ['profileData.name', 'tagsData.tags'],
            commands: ['managerData.manager_id', 'commentData.comment'], guardForm: 'user-ordinary', submitMethod: 'saveOrdinary',
            submitMethods: ['saveProfile', 'saveTags', 'saveManager', 'addComment', 'newComment'] } });
    m.forms = {};
    for (const [id, method] of [['user-ordinary', 'saveOrdinary'], ['user-profile', 'saveProfile'], ['user-tags', 'saveTags'],
        ['manager-assignment', 'saveManager'], ['user-comment', 'addComment'], ['user-new-comment', 'newComment']]) {
        m.forms[method] = m.addSubmitForm(id, method);
    }
    // The aggregate owner is a DIV; only an actual child FORM can submit/validate.
    delete m.form.requestSubmit; delete m.form.reportValidity;
    return m;
}

test('aggregate command-only guard cannot save and supports Stay or explicit discard', async () => {
    const m = mountUser(); let left = 0;
    m.set('managerData.manager_id', 12); m.set('commentData.comment', 'Unsent command');
    await m.helper.requestLeave(() => { left++; });
    assert.equal(m.helper.guard, true); assert.equal(m.helper.ordinaryDirty(), false);
    await m.helper.resolveGuard('save'); assert.equal(m.nativeSubmits, 0);
    await m.helper.resolveGuard('stay'); assert.equal(m.state['commentData.comment'], 'Unsent command');
    await m.helper.requestLeave(() => { left++; }); await m.helper.resolveGuard('discard');
    assert.equal(left, 1); assert.equal(m.nativeSubmits, 0); assert.equal(m.calls.length, 0); m.helper.destroy();
});

test('mixed aggregate Save writes ordinary fields only and stays with both command drafts', async () => {
    const m = mountUser(); let left = 0;
    m.set('profileData.name', 'Manual'); m.set('tagsData.tags', 'Tag');
    m.set('managerData.manager_id', 12); m.set('commentData.comment', 'Unsent');
    await m.helper.requestLeave(() => { left++; }); const saving = m.helper.resolveGuard('save'); await tick();
    assert.deepEqual(m.submittedMethods, ['saveOrdinary']); assert.equal(m.form.inert, true);
    m.finish({ ok: true, revision: 'sha3', fields: { 'profileData.name': 'Manual', 'tagsData.tags': 'Tag' } }); await saving;
    assert.equal(left, 0); assert.equal(m.helper.guard, true); assert.equal(m.helper.leaving, false);
    assert.equal(m.helper.ordinaryDirty(), false); assert.equal(m.helper.commandDirty(), true); assert.equal(m.form.inert, false);
    assert.equal(m.state['managerData.manager_id'], 12); assert.equal(m.state['commentData.comment'], 'Unsent');
    await m.helper.resolveGuard('save'); assert.equal(m.nativeSubmits, 1);
    await m.helper.resolveGuard('discard'); assert.equal(left, 1); assert.deepEqual(m.submittedMethods, ['saveOrdinary']);
    m.helper.destroy();
});

test('ordinary-only aggregate Save leaves after ACK and cannot be bypassed by a clean sibling form', async () => {
    const m = mountUser(); let left = 0; m.set('tagsData.tags', 'Dirty tags');
    await m.helper.requestLeave(() => { left++; }); assert.equal(left, 0);
    const saving = m.helper.resolveGuard('save'); await tick();
    m.finish({ ok: true, revision: 'sha2', fields: { 'tagsData.tags': 'Dirty tags' } }); await saving;
    assert.equal(left, 1); assert.deepEqual(m.submittedMethods, ['saveOrdinary']); m.helper.destroy();
});

test('actual form validation and manager/comment submissions retain unrelated ordinary drafts', async () => {
    const m = mountUser(); m.set('profileData.name', 'Dirty name'); m.set('tagsData.tags', 'Dirty tags');
    m.forms.saveProfile.reportValidity = () => false;
    assert.equal(await m.helper.manual(m.forms.saveProfile), false); assert.equal(m.nativeSubmits, 0);
    m.set('managerData.manager_id', 12); const manager = m.helper.manual(m.forms.saveManager); await tick();
    m.finish({ ok: true, revision: 'sha2', fields: { 'managerData.manager_id': 12 } }); assert.equal(await manager, true);
    assert.equal(m.helper.changed('profileData.name'), true); assert.equal(m.helper.changed('tagsData.tags'), true);
    m.set('commentData.comment', 'Explicit comment'); const comment = m.helper.manual(m.forms.addComment); await tick();
    m.finish({ ok: true, revision: 'sha2', fields: { 'commentData.comment': 'Explicit comment' } }); assert.equal(await comment, true);
    assert.deepEqual(m.submittedMethods, ['saveManager', 'addComment']); assert.equal(m.helper.commandDirty(), false);
    assert.equal(m.helper.ordinaryDirty(), true); m.helper.destroy();
});

test('aggregate parameters use real field paths and string CAS while all other drafts survive', async () => {
    const m = mountUser(); m.set('profileData.name', 'Dirty'); m.set('tagsData.tags', 'Tags');
    m.set('managerData.manager_id', 12); m.set('commentData.comment', 'Unsent');
    m.set('profileData.gender', 'other'); await m.helper.settle();
    assert.equal(JSON.stringify(m.calls[0].patch), '{"profileData.gender":"other"}');
    assert.equal(m.calls[0].revision, 'sha1'); assert.equal(m.helper.revision, 'sha2');
    assert.equal(m.helper.changed('profileData.gender'), false); assert.equal(m.helper.ordinaryDirty(), true);
    assert.equal(m.helper.commandDirty(), true); assert.equal(m.nativeSubmits, 0); m.helper.destroy();
});

test('mixed aggregate validation keeps both draft types and permits a corrected ordinary-only Save', async () => {
    const m = mountUser(); let left = 0; m.set('profileData.name', ''); m.set('commentData.comment', 'Command');
    await m.helper.requestLeave(() => { left++; }); const refused = m.helper.resolveGuard('save'); await tick();
    m.rejected({ 'profileData.name': ['Required'] }); await refused;
    assert.equal(m.helper.stopped, false); assert.equal(left, 0); assert.equal(m.state['commentData.comment'], 'Command');
    m.wire.errorMessages = {}; m.set('profileData.name', 'Corrected'); const corrected = m.helper.resolveGuard('save'); await tick();
    m.finish({ ok: true, revision: 'sha2', fields: { 'profileData.name': 'Corrected' } }); await corrected;
    assert.equal(left, 0); assert.equal(m.helper.commandDirty(), true); assert.equal(m.helper.ordinaryDirty(), false);
    assert.deepEqual(m.submittedMethods, ['saveOrdinary', 'saveOrdinary']); m.helper.destroy();
});

function mountSupport(save) {
    return mount({ values: { status: 'new', contactEmail: 'stored@example.test', replyBody: '' }, save,
        config: { statePath: '', standalone: true, parameters: ['status'], manual: ['contactEmail'], commands: ['replyBody'],
            submitMethod: 'saveDetails', submitMethods: ['sendReply'], commandActionMethod: 'callMountedAction' } });
}

test('explicit header close ACK updates accepted status/revision and keeps dirty metadata/reply', async () => {
    const m = mountSupport(); m.set('contactEmail', 'manual@example.test'); m.set('replyBody', 'Unsent reply');
    const close = m.beginHeader('callMountedAction');
    close.resolve({ ok: true, revision: 2, fields: { status: 'closed' }, normalized: { status: 'closed' } }); await tick();
    assert.equal(m.helper.revision, 2); assert.equal(m.helper.baseline.status, 'closed'); assert.equal(m.state.status, 'closed');
    assert.equal(m.state.contactEmail, 'manual@example.test'); assert.equal(m.state.replyBody, 'Unsent reply');
    assert.equal(m.helper.ordinaryDirty(), true); assert.equal(m.helper.commandDirty(), true);
    assert.equal(m.calls.length, 0); assert.equal(m.nativeSubmits, 0); assert.equal(m.headerCalls.length, 1); m.helper.destroy();
});

test('late header normalization preserves newer status input and saves only that parameter intent', async () => {
    const m = mountSupport(); const close = m.beginHeader('callMountedAction');
    // Reproduce a missed/coalesced watch after dispatch; this is a newer input.
    m.state.status = 'waiting'; m.set('replyBody', 'Latest reply draft');
    close.resolve({ ok: true, revision: 2, fields: { status: 'closed' }, normalized: { status: 'closed' } }); await tick(); await m.helper.settle();
    assert.equal(m.state.status, 'waiting'); assert.equal(m.calls.length, 1);
    assert.equal(m.calls[0].patch.status, 'waiting'); assert.equal(m.calls[0].revision, 2);
    assert.equal(m.helper.revision, 3); assert.equal(m.state.replyBody, 'Latest reply draft');
    assert.equal(m.headerCalls.length, 1); assert.equal(m.nativeSubmits, 0); m.helper.destroy();
});

test('older retry ACK cannot roll back a newer in-flight parameter revision or clear its neighbors', async () => {
    const response = deferred(); const m = mountSupport(() => response.promise);
    m.set('status', 'in_progress'); await tick(); const retry = m.beginHeader('callMountedAction');
    m.set('contactEmail', 'Dirty email'); m.set('replyBody', 'Dirty reply');
    response.resolve({ ok: true, revision: 2, fields: { status: 'in_progress' } }); await m.helper.settle();
    retry.resolve({ ok: true, revision: 1, fields: {} }); await tick();
    assert.equal(m.helper.revision, 2); assert.equal(m.helper.baseline.status, 'in_progress');
    assert.equal(m.state.contactEmail, 'Dirty email'); assert.equal(m.state.replyBody, 'Dirty reply');
    assert.equal(m.calls.length, 1); assert.equal(m.headerCalls.length, 1); m.helper.destroy();
});

test('header terminal/error outcomes retain drafts and never execute or retry a command', async () => {
    for (const rejected of [false, true]) {
        const m = mountSupport(); m.set('replyBody', 'Unsent'); m.set('contactEmail', 'Dirty');
        const command = m.beginHeader('callMountedAction'); m.wire.errorMessages = { old: ['Old validation'] };
        if (rejected) command.reject({ status: 403 });
        else command.resolve({ ok: false, terminal: true, message: 'History changed' });
        await tick();
        assert.equal(m.helper.stopped, true); assert.equal(m.state.replyBody, 'Unsent'); assert.equal(m.state.contactEmail, 'Dirty');
        const reason = m.helper.message; m.set('status', 'waiting'); await tick();
        assert.equal(m.calls.length, 0); assert.equal(m.headerCalls.length, 1); assert.equal(m.helper.message, reason); m.helper.destroy();
    }
});

test('header validation is recoverable without touching metadata, revision or automatic retry', async () => {
    const m = mountSupport(); m.set('contactEmail', 'Dirty'); m.set('replyBody', 'Unsent');
    const retry = m.beginHeader('callMountedAction');
    retry.reject({ status: 422, errors: { message_id: ['Choose message'] } }); await tick();
    assert.equal(m.helper.stopped, false); assert.equal(m.helper.revision, 1);
    assert.equal(m.state.contactEmail, 'Dirty'); assert.equal(m.state.replyBody, 'Unsent');
    assert.equal(m.calls.length, 0); assert.equal(m.headerCalls.length, 1); m.helper.destroy();
});

test('header ACK from stale opening or after session failure cannot replace live state/terminal reason', async () => {
    for (const stale of [false, true]) {
        const m = mountSupport(); m.set('replyBody', 'Unsent'); const close = m.beginHeader('callMountedAction');
        if (stale) m.state.faqOpening = 'new-opening'; else m.requestError(419);
        const reason = m.helper.message;
        close.resolve({ ok: true, revision: 2, fields: { status: 'closed' }, normalized: { status: 'closed' } }); await tick();
        assert.equal(m.helper.revision, 1); assert.equal(m.state.status, 'new'); assert.equal(m.helper.baseline.status, 'new');
        assert.equal(m.helper.message, reason); assert.equal(m.state.replyBody, 'Unsent'); assert.equal(m.headerCalls.length, 1); m.helper.destroy();
    }
});

function mountAssessment() {
    const m = mount({ values: { 'drafts.1.scorePercent': '', 'drafts.1.comment': '', 'drafts.2.scorePercent': '', 'drafts.2.comment': '' },
        config: { statePath: '', standalone: true, parameters: [],
            manual: ['drafts.1.scorePercent', 'drafts.1.comment', 'drafts.2.scorePercent', 'drafts.2.comment'],
            submitMethod: 'saveOrdinary', submitMethods: ['saveItem', 'finalize'], commandActionMethod: 'editItem',
            guardForm: 'assessment-ordinary' } });
    m.first = m.addSubmitForm('item-1', 'saveItem'); m.second = m.addSubmitForm('item-2', 'saveItem');
    m.ordinary = m.addSubmitForm('assessment-ordinary', 'saveOrdinary');
    m.finalization = m.addSubmitForm('assessment-finalize', 'finalize');
    delete m.form.requestSubmit; delete m.form.reportValidity;
    return m;
}

test('assessment native item Save acknowledges its atomic pair and preserves a dirty sibling', async () => {
    const m = mountAssessment(); m.set('drafts.1.scorePercent', '90'); m.set('drafts.1.comment', 'First');
    m.set('drafts.2.scorePercent', '80'); m.set('drafts.2.comment', 'Sibling draft');
    const saved = m.helper.manual(m.first); await tick();
    assert.equal(m.submittedTargets[0], m.first); assert.deepEqual(m.submittedMethods, ['saveItem']);
    m.finish({ ok: true, revision: 2, fields: { 'drafts.1.scorePercent': '90', 'drafts.1.comment': 'First' } });
    assert.equal(await saved, true); assert.equal(m.helper.changed('drafts.1.comment'), false);
    assert.equal(m.helper.changed('drafts.2.comment'), true); assert.equal(m.state['drafts.2.comment'], 'Sibling draft');
    assert.equal(m.calls.length, 0); m.helper.destroy();
});

test('assessment delayed item ACK keeps newer same-item input dirty for the next explicit intent', async () => {
    const m = mountAssessment(); m.set('drafts.1.scorePercent', '90');
    const saved = m.helper.manual(m.first); await tick(); m.set('drafts.1.scorePercent', '95');
    m.finish({ ok: true, revision: 2, fields: { 'drafts.1.scorePercent': '90', 'drafts.1.comment': '' } });
    assert.equal(await saved, true); assert.equal(m.state['drafts.1.scorePercent'], '95'); assert.equal(m.helper.changed('drafts.1.scorePercent'), true);
    const corrected = m.helper.manual(m.first); await tick();
    m.finish({ ok: true, revision: 3, fields: { 'drafts.1.scorePercent': '95', 'drafts.1.comment': '' } });
    assert.equal(await corrected, true); assert.equal(m.helper.changed('drafts.1.scorePercent'), false);
    assert.deepEqual(m.submittedMethods, ['saveItem', 'saveItem']); m.helper.destroy();
});

test('assessment guard Save validates/corrects grade groups through ordinary handoff and never finalizes', async () => {
    const m = mountAssessment(); let left = 0;
    m.set('drafts.1.scorePercent', '90'); m.set('drafts.2.scorePercent', '101');
    await m.helper.requestLeave(() => { left++; }); const invalid = m.helper.resolveGuard('save'); await tick();
    assert.equal(m.submittedTargets[0], m.ordinary);
    m.rejected({ 'drafts.2.scorePercent': ['Too high'] }); await invalid;
    assert.equal(left, 0); assert.equal(m.helper.stopped, false); assert.equal(m.helper.guard, true);
    m.wire.errorMessages = {}; m.set('drafts.2.scorePercent', '80');
    const corrected = m.helper.resolveGuard('save'); await tick();
    m.finish({ ok: true, revision: 3, fields: { 'drafts.1.scorePercent': '90', 'drafts.1.comment': '', 'drafts.2.scorePercent': '80', 'drafts.2.comment': '' } }); await corrected;
    assert.equal(left, 1); assert.deepEqual(m.submittedMethods, ['saveOrdinary', 'saveOrdinary']);
    assert.equal(m.helper.dirty(), false); m.helper.destroy();
});

test('assessment finalization uses only its explicit native form and locks inputs until its ACK', async () => {
    const m = mountAssessment(); const finalized = m.helper.manual(m.finalization); await tick();
    assert.equal(m.form.inert, true); assert.equal(m.submittedTargets[0], m.finalization);
    assert.deepEqual(m.submittedMethods, ['finalize']);
    m.finish({ ok: true, revision: 7, fields: {} }); assert.equal(await finalized, true);
    assert.equal(m.form.inert, false); assert.equal(m.helper.revision, 7); assert.equal(m.calls.length, 0); m.helper.destroy();
});

test('assessment explicit edit ACK updates one authoritative group without resetting a sibling draft', async () => {
    const m = mountAssessment(); m.set('drafts.2.comment', 'Sibling'); const edit = m.beginHeader('editItem');
    // Livewire merges the authoritative fields returned by the explicit editItem call.
    m.state['drafts.1.scorePercent'] = '80.00'; m.state['drafts.1.comment'] = 'Stored';
    edit.resolve({ ok: true, revision: 2, fields: { 'drafts.1.scorePercent': '80.00', 'drafts.1.comment': 'Stored' } }); await tick();
    assert.equal(m.helper.changed('drafts.1.scorePercent'), false); assert.equal(m.helper.changed('drafts.2.comment'), true);
    assert.equal(m.state['drafts.2.comment'], 'Sibling'); assert.equal(m.nativeSubmits, 0); assert.equal(m.headerCalls.length, 1); m.helper.destroy();
});

test('assessment revision conflict is terminal and preserves all grade drafts without retry or finalize', async () => {
    const m = mountAssessment(); m.set('drafts.1.scorePercent', '90'); m.set('drafts.2.comment', 'Sibling');
    const saved = m.helper.manual(m.first); await tick(); m.finish({ ok: false, terminal: true, message: 'REVIEW_CONFLICT: history changed' });
    assert.equal(await saved, false); assert.equal(m.helper.stopped, true); assert.equal(m.helper.message, 'REVIEW_CONFLICT: history changed');
    assert.equal(m.state['drafts.1.scorePercent'], '90'); assert.equal(m.state['drafts.2.comment'], 'Sibling');
    assert.deepEqual(m.submittedMethods, ['saveItem']); assert.equal(m.calls.length, 0); m.helper.destroy();
});

(async () => {
    for (const [name, callback] of tests) {
        await callback();
        console.log(`PASS ${name}`);
    }
    console.log(`${tests.length} cases passed`);
})().catch((error) => { console.error(error); process.exitCode = 1; });
