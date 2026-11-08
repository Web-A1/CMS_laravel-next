import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import test from "node:test";
import ts from "typescript";

const output = ts.transpileModule(readFileSync(new URL("./useVisibleVideoPlayback.ts", import.meta.url), "utf8"), {
  compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
}).outputText;

function setup(enabled = true, withObserver = true, blocked = false) {
  let cleanup: (() => void) | undefined;
  let listener: (() => void) | undefined;
  let callback: ((entries: { isIntersecting: boolean }[]) => void) | undefined;
  let disconnected = false;
  const requestedPauses: boolean[] = [];
  const video = { plays: 0, pauses: 0, play() { this.plays++; return blocked ? Promise.reject(new Error("Autoplay blocked")) : Promise.resolve(); }, pause() { this.pauses++; } };
  const doc = { hidden: false, addEventListener(name: string, fn: () => void) { assert.equal(name, "visibilitychange"); listener = fn; }, removeEventListener(name: string, fn: () => void) { assert.equal(name, "visibilitychange"); assert.equal(fn, listener); listener = undefined; } };
  class Observer {
    constructor(fn: typeof callback, options: IntersectionObserverInit) { callback = fn; assert.equal(options.threshold, 0); }
    observe(element: unknown) { assert.equal(element, video); }
    disconnect() { disconnected = true; }
  }
  const exports = {} as typeof import("./useVisibleVideoPlayback");
  new Function("require", "exports", "document", "IntersectionObserver", output)((id: string) => {
    assert.equal(id, "react");
    return { useEffect: (fn: () => (() => void) | undefined) => { cleanup = fn(); } };
  }, exports, doc, withObserver ? Observer : undefined);
  exports.useVisibleVideoPlayback({ current: video as unknown as HTMLVideoElement }, enabled, (paused) => requestedPauses.push(paused));
  return { video, doc, requestedPauses, enter(visible: boolean) { callback?.([{ isIntersecting: visible }]); }, hidden(value: boolean) { doc.hidden = value; listener?.(); }, cleanup() { cleanup?.(); }, state: () => ({ disconnected, listening: Boolean(listener) }) };
}

test("first viewport exit latches pause before React cleanup; returning cannot autoplay", () => {
  const env = setup();
  assert.equal(env.video.plays, 0);
  env.enter(true); assert.equal(env.video.plays, 1);
  env.enter(false); assert.equal(env.video.pauses, 1);
  env.hidden(true); env.enter(true); assert.equal(env.video.plays, 1);
  env.hidden(false); assert.equal(env.video.plays, 1);
  assert.deepEqual(env.requestedPauses, [true]);
  env.cleanup();
  assert.deepEqual(env.state(), { disconnected: true, listening: false });
  assert.equal(env.video.pauses, 5);
  env.enter(true);
  assert.equal(env.video.plays, 1, "a queued observer callback cannot restart playback after cleanup");
});

test("initial offscreen state still allows the first autoplay; backgrounding alone can resume it", () => {
  const env = setup();
  env.enter(false);
  assert.deepEqual(env.requestedPauses, []);
  env.enter(true);
  assert.equal(env.video.plays, 1);
  env.hidden(true); env.hidden(false);
  assert.equal(env.video.plays, 2);
  assert.deepEqual(env.requestedPauses, []);
  env.cleanup();
});

test("disabled media pauses without observing or registering listeners", () => {
  const env = setup(false);
  assert.equal(env.video.pauses, 1); assert.equal(env.video.plays, 0);
  assert.deepEqual(env.state(), { disconnected: false, listening: false });
});

test("without IntersectionObserver playback still respects backgrounding and cleanup", () => {
  const env = setup(true, false);
  assert.equal(env.video.plays, 1);
  env.hidden(true); assert.equal(env.video.pauses, 1);
  env.cleanup(); assert.equal(env.state().listening, false);
});

test("autoplay rejection is handled without an unhandled promise", async () => {
  const env = setup(true, true, true);
  env.enter(true); await Promise.resolve();
  assert.equal(env.video.plays, 1); env.cleanup();
});
