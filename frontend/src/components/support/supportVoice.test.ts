import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import test from "node:test";
import ts from "typescript";

const compiled = ts.transpileModule(readFileSync(new URL("./supportVoice.ts", import.meta.url), "utf8"), { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText;
function setup(options: { denied?: boolean; late?: boolean; unsupported?: boolean; broken?: boolean; supported?: string[]; actualMime?: string; deferStart?: boolean } = {}) {
  let resolve: (stream: unknown) => void = () => {};
  let tracksStopped = 0, revoked = 0, permissionRequests = 0, now = 0;
  let scheduledTimers = 0;
  let tick = () => {}, timeout = () => {};
  const stream = { getTracks: () => [{ stop: () => { tracksStopped++; } }] };
  const instances: Recorder[] = [];
  class Recorder {
    static isTypeSupported(mime: string) { return !options.unsupported && (options.supported ?? ["audio/webm;codecs=opus"]).includes(mime); }
    state = "inactive";
    startArguments: unknown[] = [];
    mimeType = "audio/webm;codecs=opus";
    ondataavailable: ((event: { data: Blob }) => void) | null = null;
    onstart: (() => void) | null = null;
    onstop: (() => void) | null = null;
    onerror: (() => void) | null = null;
    constructor(stream: unknown, config: { mimeType: string }) { assert.ok(stream); if (options.broken) throw new Error("broken"); this.mimeType = options.actualMime ?? config.mimeType; instances.push(this); }
    start(...args: unknown[]) { this.startArguments = args; this.state = "recording"; if (!options.deferStart) this.onstart?.(); }
    stop() { this.state = "inactive"; }
    finish() { this.ondataavailable?.({ data: new Blob(["audio"]) }); this.onstop?.(); }
  }
  const sandbox = { exports: {} as typeof import("./supportVoice") };
  new Function("module", "exports", "navigator", "MediaRecorder", "URL", "setInterval", "clearInterval", "setTimeout", "clearTimeout", "Date", compiled)(sandbox, sandbox.exports,
    { mediaDevices: { getUserMedia: () => { permissionRequests++; return options.denied ? Promise.reject(new DOMException("Denied", "NotAllowedError")) : options.late ? new Promise((done) => { resolve = done; }) : Promise.resolve(stream); } } }, Recorder,
    { createObjectURL: () => "blob:voice", revokeObjectURL: () => { revoked++; } }, (callback: () => void) => { scheduledTimers++; tick = callback; return 1; }, () => { tick = () => {}; },
    (callback: () => void) => { scheduledTimers++; timeout = callback; return 2; }, () => { timeout = () => {}; }, { now: () => now });
  const session = sandbox.exports.createSupportVoice(() => {});
  return { session, instances, resolve: () => resolve(stream), tick: () => tick(), timeout: () => timeout(), advance: (seconds: number) => { now = seconds * 1000; }, get tracksStopped() { return tracksStopped; }, get revoked() { return revoked; }, get permissionRequests() { return permissionRequests; }, get scheduledTimers() { return scheduledTimers; } };
}

test("recording phase and 180 second clock begin only after asynchronous recorder start event", async () => {
  const view = setup({ deferStart: true });
  await view.session.start();
  assert.equal(view.session.getState().phase, "requesting");
  assert.equal(view.scheduledTimers, 0);
  view.advance(5); view.tick(); view.session.stop();
  assert.equal(view.session.getState().phase, "requesting");
  assert.equal(view.instances[0].state, "recording");
  view.instances[0].onstart?.();
  assert.equal(view.session.getState().phase, "recording");
  assert.equal(view.session.getState().seconds, 0);
  assert.equal(view.scheduledTimers, 2);
  view.advance(6); view.tick(); assert.equal(view.session.getState().seconds, 1);
  view.advance(184); view.tick(); assert.equal(view.session.getState().phase, "recording");
  view.advance(185); view.tick(); assert.equal(view.session.getState().phase, "stopping");
  assert.equal(view.session.getState().seconds, 180);
  view.instances[0].finish(); view.session.clear();
});

test("cancel before asynchronous start detaches handler and ignores stale start even after retry", async () => {
  const view = setup({ deferStart: true });
  await view.session.start();
  const staleStart = view.instances[0].onstart;
  view.session.clear();
  assert.equal(view.instances[0].onstart, null);
  assert.equal(view.tracksStopped, 1);
  staleStart?.();
  assert.equal(view.session.getState().phase, "idle");
  assert.equal(view.scheduledTimers, 0);
  await view.session.start(); staleStart?.();
  assert.equal(view.session.getState().phase, "requesting");
  assert.equal(view.scheduledTimers, 0);
  view.instances[1].onstart?.();
  assert.equal(view.session.getState().phase, "recording");
  assert.equal(view.scheduledTimers, 2);
  view.session.clear();
});

test("stop waits for final chunk, yields one attachment and releases tracks and URL", async () => {
  const view = setup();
  await view.session.start();
  await view.session.start();
  assert.equal(view.permissionRequests, 1);
  assert.equal(view.session.getState().phase, "recording");
  view.instances[0].ondataavailable?.({ data: new Blob(["initial-chunk|"]) });
  view.advance(12); view.session.stop();
  assert.equal(view.session.getState().phase, "stopping");
  assert.equal(view.session.getState().file, undefined);
  view.instances[0].finish();
  assert.equal(view.session.getState().file?.type, "audio/webm;codecs=opus");
  assert.equal(await view.session.getState().file?.text(), "initial-chunk|audio");
  assert.equal(view.session.getState().seconds, 12);
  assert.equal(view.tracksStopped, 1);
  view.session.clear();
  assert.equal(view.revoked, 1);
  assert.equal(view.session.getState().phase, "idle");
});
test("late permission after close stops tracks and cannot create recorder", async () => {
  const view = setup({ late: true });
  const request = view.session.start();
  assert.equal(view.session.getState().phase, "requesting");
  view.session.clear(); view.resolve(); await request;
  assert.equal(view.tracksStopped, 1);
  assert.equal(view.instances.length, 0);
  assert.equal(view.session.getState().phase, "idle");
});
test("explicit MP4 AAC is preferred, then generic MP4, with WebM fallback", async () => {
  const aac = "audio/mp4;codecs=mp4a.40.2";
  const opus = "audio/webm;codecs=opus";
  for (const [supported, expected] of [[[opus, aac, "audio/mp4"], aac], [[opus, "audio/mp4"], "audio/mp4"], [["audio/mp4"], "audio/mp4"], [[opus], opus]] as const) {
    const view = setup({ supported: [...supported] }); await view.session.start();
    assert.equal(view.instances[0].mimeType, expected);
    view.session.stop(); view.instances[0].finish();
    assert.equal(view.session.getState().file?.type, expected);
    assert.equal(view.session.getState().file?.name, expected.startsWith("audio/mp4") ? "voice.m4a" : "voice.webm");
    assert.equal(await view.session.getState().file?.text(), "audio");
    view.session.clear();
  }
});
test("ready attachment uses actual recorder MIME rather than requested codec string", async () => {
  const view = setup({ supported: ["audio/mp4;codecs=mp4a.40.2"], actualMime: "audio/mp4" });
  await view.session.start(); view.session.stop(); view.instances[0].finish();
  assert.equal(view.session.getState().file?.type, "audio/mp4");
  assert.equal(view.session.getState().file?.name, "voice.m4a");
  view.session.clear();
});
test("continuous AAC recording requests no periodic slices and publishes the final blob only after stop", async () => {
  const view = setup({ supported: ["audio/mp4;codecs=mp4a.40.2"] });
  await view.session.start();
  assert.deepEqual(view.instances[0].startArguments, []);
  view.advance(1); view.tick();
  assert.equal(view.session.getState().phase, "recording");
  assert.equal(view.session.getState().file, undefined);
  view.session.stop();
  assert.equal(view.session.getState().phase, "stopping");
  assert.equal(view.session.getState().file, undefined);
  view.instances[0].finish();
  assert.equal(view.session.getState().phase, "ready");
  assert.equal(await view.session.getState().file?.text(), "audio");
  assert.equal(view.session.getState().file?.name, "voice.m4a");
  assert.equal(view.tracksStopped, 1);
  view.session.clear();
});
test("180 second deadline stops without sending or publishing incomplete attachment", async () => {
  const view = setup(); await view.session.start(); view.advance(180); view.timeout();
  assert.equal(view.session.getState().phase, "stopping");
  view.instances[0].finish();
  assert.equal(view.session.getState().phase, "ready");
  assert.equal(view.session.getState().seconds, 180);
  view.session.clear();
});
test("permission denial, unsupported format and constructor failure are recoverable", async () => {
  for (const options of [{ denied: true }, { unsupported: true }, { broken: true }]) {
    const view = setup(options); await view.session.start();
    assert.equal(view.session.getState().phase, "error");
    assert.ok(view.session.getState().error);
    assert.equal(view.tracksStopped, options.broken ? 1 : 0);
    view.session.clear();
  }
});
test("cancel detaches recorder callbacks and stops recording resources", async () => {
  const view = setup(); await view.session.start(); const recorder = view.instances[0]; view.session.clear();
  assert.equal(recorder.onstop, null); assert.equal(recorder.ondataavailable, null); assert.equal(recorder.onerror, null);
  assert.equal(recorder.state, "inactive"); assert.equal(view.tracksStopped, 1);
  recorder.finish(); assert.equal(view.session.getState().file, undefined);
});
test("oversized data and recorder failure release microphone", async () => {
  const view = setup(); await view.session.start();
  view.instances[0].ondataavailable?.({ data: new Blob([new Uint8Array(10 * 1024 * 1024 + 1)]) });
  assert.equal(view.session.getState().phase, "error"); assert.equal(view.tracksStopped, 1);
  await view.session.start(); view.instances[1].onerror?.();
  assert.equal(view.session.getState().phase, "error"); assert.equal(view.tracksStopped, 2);
});
