const assert = require('node:assert/strict');
const { createHash, randomUUID } = require('node:crypto');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const { test } = require('node:test');

function editor(save) {
    let factory;
    const listeners = new Map();
    const cleanups = [];
    const window = {
        jsMd5: (text) => createHash('md5').update(text).digest('hex'),
        location: { replace() { assert.fail('Navigation must use the confirmed Livewire effect'); } },
        addEventListener(name, callback) { listeners.set(name, [...(listeners.get(name) ?? []), callback]); },
        removeEventListener(name, callback) { listeners.set(name, (listeners.get(name) ?? []).filter((item) => item !== callback)); },
    };
    const wire = {
        editorOpen: true, entryId: null, revision: 0,
        data: { title: 'Unsaved title', body: '<p>Draft body</p>' },
        savedDataHash: window.jsMd5('{}'),
        __instance: { effects: {}, addCleanup(callback) { cleanups.push(callback); } },
        $watch() {},
        save() { return save(wire, component, unload); },
    };
    const context = { Alpine: { data: (_, callback) => { factory = callback; } }, window, crypto: { randomUUID }, queueMicrotask, Set };
    const source = fs.readFileSync(path.join(__dirname, '../../resources/views/filament/articles.blade.php'), 'utf8');
    vm.runInNewContext(source.match(/@script\s*<script>([\s\S]*?)<\/script>/)[1], context);
    vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../../vendor/filament/filament/resources/js/unsaved-changes-alert.js'), 'utf8'), context);
    const component = factory(wire);
    component.$el = { addEventListener() {} };
    component.init();
    window.setUpUnsavedDataChangesAlert({ $wire: wire });
    function unload() {
        let prevented = false;
        const event = { preventDefault() { prevented = true; } };
        for (const callback of listeners.get('beforeunload') ?? []) callback(event);
        return prevented;
    }
    return { wire, component, unload, destroy() { component.destroy(); cleanups.forEach((callback) => callback()); } };
}

test('confirmed first-create redirect is allowed before the save promise settles', async () => {
    const state = editor(async (wire, component, unload) => {
        assert.equal(component.fullBusy, true);
        assert.equal(unload(), true, 'In-flight save must still protect the draft');
        wire.entryId = 254;
        wire.__instance.effects.redirect = '/admin/articles/254';
        assert.equal(unload(), false, 'Both actual guards recognize the native redirect before promise settlement');
        return { ok: true, url: '/admin/articles/254' };
    });
    await state.component.save();
    assert.equal(state.component.message, 'Сохранено');
    state.destroy();
    assert.equal(state.unload(), false);
});

test('validation failure preserves unsaved input and does not bypass unload guards', async () => {
    const state = editor(async () => ({ ok: false }));
    await state.component.save();
    assert.equal(state.wire.data.title, 'Unsaved title');
    assert.equal(state.unload(), true);
    assert.equal(state.component.fullBusy, false);
    state.destroy();
});

test('failed requests and unfinished uploads retain unload protection without navigation', async () => {
    const state = editor(async () => { throw new Error('offline'); });
    await state.component.save();
    assert.equal(state.component.stopped, true);
    assert.equal(state.wire.data.body, '<p>Draft body</p>');
    assert.equal(state.unload(), true);
    state.wire.savedDataHash = createHash('md5').update(JSON.stringify(state.wire.data)).digest('hex');
    state.component.uploads = 1;
    assert.equal(state.unload(), true, 'A clean document with an unfinished upload still cannot leave');
    state.destroy();
});
