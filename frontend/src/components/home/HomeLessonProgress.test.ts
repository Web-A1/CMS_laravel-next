import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { createRequire } from "node:module";
import test from "node:test";
import ts from "typescript";

const { HOME_COUNTER_MOTION, HOME_ENTRANCE_MOTION } = await import(new URL("./homeMotion.ts", import.meta.url).href) as typeof import("./homeMotion");

const localRequire = createRequire(import.meta.url);
const compiled = ts.transpileModule(readFileSync(new URL("./HomeLessonProgress.tsx", import.meta.url), "utf8"), {
  compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022, jsx: ts.JsxEmit.ReactJSX },
}).outputText;

function mount(reducedInitially = false, observerAvailable = true, progressReady = true, entranceManaged = false, visible = true) {
  let skipMotion = false;
  const attributes = () => ({ values: new Map<string, string>(), setAttribute(key: string, value: string) { this.values.set(key, value); } });
  const reveal = attributes(), knob = attributes(), label = { textContent: "100%" }, card = {
    getBoundingClientRect: () => visible ? { top: 100, bottom: 300, height: 200 } : { top: 1000, bottom: 1200, height: 200 },
  };
  const refs = [{ closest: () => card }, reveal, knob, label];
  let refIndex = 0;
  const frames = new Map<number, FrameRequestCallback>();
  const motionHandlers = new Set<() => void>(), visibilityHandlers = new Set<() => void>();
  const reduced = { matches: reducedInitially, addEventListener: (_: string, fn: () => void) => motionHandlers.add(fn), removeEventListener: (_: string, fn: () => void) => motionHandlers.delete(fn) };
  const documentMock = { hidden: false, addEventListener: (_: string, fn: () => void) => visibilityHandlers.add(fn), removeEventListener: (_: string, fn: () => void) => visibilityHandlers.delete(fn) };
  let effect: (() => (() => void) | undefined) | undefined;
  let intersect: ((entries: { isIntersecting: boolean; intersectionRatio: number }[]) => void) | undefined;
  let nextFrame = 0, observed = 0, disconnected = 0;
  class Observer {
    constructor(callback: typeof intersect, options: IntersectionObserverInit) {
      intersect = callback;
      assert.equal(options.threshold, 0.25);
      assert.equal(options.rootMargin, HOME_ENTRANCE_MOTION.rootMargin);
    }
    observe(target: unknown) { assert.equal(target, card); observed++; }
    disconnect() { disconnected++; }
  }
  const exports = {} as typeof import("./HomeLessonProgress");
  new Function("require", "exports", "window", "document", "IntersectionObserver", compiled)((id: string) => {
    if (id === "react") return { useId: () => "test", useRef: () => ({ current: refs[refIndex++] }), useEffect: (fn: typeof effect) => { effect = fn; } };
    if (id === "./homeMotion") return { HOME_COUNTER_MOTION, HOME_ENTRANCE_MOTION };
    if (id === "./HomeFactsMotion") return { useHomeFactsMotion: () => ({ progressReady, skipMotion, entranceManaged }) };
    return localRequire(id);
  }, exports, {
    innerHeight: 900,
    matchMedia: () => reduced,
    requestAnimationFrame: (fn: FrameRequestCallback) => { frames.set(++nextFrame, fn); return nextFrame; },
    cancelAnimationFrame: (id: number) => frames.delete(id),
  }, documentMock, observerAvailable ? Observer : undefined);
  exports.default();
  assert.equal(label.textContent, "100%", "server markup remains complete before enhancement");
  let cleanup = effect?.();
  return {
    reveal, knob, label, frames,
    get observed() { return observed; }, get disconnected() { return disconnected; },
    enter(ratio: number) { intersect?.([{ isIntersecting: ratio > 0, intersectionRatio: ratio }]); },
    tick(time: number) { const pending = [...frames.values()]; frames.clear(); pending.forEach(fn => fn(time)); },
    settleCard(skip = false) { cleanup?.(); progressReady = true; skipMotion = skip; refIndex = 0; exports.default(); cleanup = effect?.(); },
    reduce() { reduced.matches = true; motionHandlers.forEach(fn => fn()); },
    hide(hidden: boolean) { documentMock.hidden = hidden; visibilityHandlers.forEach(fn => fn()); },
    cleanup() { cleanup?.(); assert.equal(frames.size, 0); assert.equal(motionHandlers.size, 0); assert.equal(visibilityHandlers.size, 0); },
  };
}

test("managed visible progress starts immediately without another observer notification", () => {
  const view = mount(false, true, true, true);
  assert.equal(view.frames.size, 1);
  view.tick(0); view.tick(100);
  assert.notEqual(view.label.textContent, "0%");
  view.cleanup();
});

test("managed progress has no observer or frames until its own entrance is ready", () => {
  const view = mount(false, true, false, true);
  assert.equal(view.observed, 0);
  assert.equal(view.frames.size, 0);
  assert.equal(view.label.textContent, "0%");
  view.settleCard();
  assert.equal(view.frames.size, 1);
  view.cleanup();
});

test("settling a waiting managed progress restores the complete static graphic", () => {
  const view = mount(false, true, false, true);
  view.settleCard(true);
  assert.equal(view.label.textContent, "100%");
  assert.equal(view.reveal.values.get("stroke-dashoffset"), "0");
  assert.equal(view.frames.size, 0);
  view.cleanup();
});

test("managed progress ready after its card left the viewport completes without a later entrance", () => {
  const view = mount(false, true, true, true, false);
  assert.equal(view.frames.size, 0);
  assert.equal(view.label.textContent, "100%");
  view.enter(1);
  assert.equal(view.frames.size, 0);
  view.cleanup();
});

test("leaving a running progress completes it and later entry keeps 100%", () => {
  const view = mount(false, true, true, true);
  view.tick(0); view.tick(100);
  view.enter(0);
  assert.equal(view.frames.size, 0);
  assert.equal(view.label.textContent, "100%");
  view.enter(1); view.tick(10000);
  assert.equal(view.frames.size, 0);
  assert.equal(view.label.textContent, "100%");
  view.cleanup();
});

test("progress waits for visibility, synchronizes ring/knob/count and completes once", () => {
  const view = mount();
  assert.equal(view.label.textContent, "0%");
  assert.equal(view.reveal.values.get("stroke-dashoffset"), "100");
  view.enter(0.1); assert.equal(view.frames.size, 0);
  view.enter(0.5); view.tick(0); view.tick(HOME_COUNTER_MOTION.durationMs / 2);
  assert.equal(view.label.textContent, "87%");
  assert.equal(view.reveal.values.get("stroke-dashoffset"), "12.5");
  assert.equal(view.knob.values.get("transform"), "rotate(315 50 50)");
  view.tick(HOME_COUNTER_MOTION.durationMs);
  assert.equal(view.label.textContent, "100%");
  assert.equal(view.reveal.values.get("stroke-dashoffset"), "0");
  assert.equal(view.knob.values.get("transform"), "rotate(360 50 50)");
  view.enter(0); view.enter(1); assert.equal(view.frames.size, 0);
  view.cleanup(); assert.ok(view.disconnected > 0);
});

test("reduced motion and missing observer retain the complete static graphic", () => {
  for (const view of [mount(true), mount(false, false)]) {
    assert.equal(view.label.textContent, "100%");
    assert.equal(view.observed, 0);
    view.cleanup();
  }
});

test("a visible progress ring waits for its own card to settle, then begins counting", () => {
  const view = mount(false, true, false);
  view.enter(1);
  assert.equal(view.frames.size, 0);
  assert.equal(view.label.textContent, "0%");
  view.settleCard();
  view.enter(1); view.tick(0); view.tick(HOME_COUNTER_MOTION.durationMs);
  assert.equal(view.label.textContent, "100%");
  view.cleanup();
});

test("enabling reduced motion cancels an active animation and settles at 100%", () => {
  const view = mount(); view.enter(1); view.tick(0); view.tick(100);
  view.reduce();
  assert.equal(view.label.textContent, "100%"); assert.equal(view.frames.size, 0);
  view.enter(1); assert.equal(view.frames.size, 0); view.cleanup();
});

test("backgrounding before entry preserves the entrance, backgrounding during it completes it", () => {
  const view = mount(); view.hide(true); view.enter(1);
  assert.equal(view.frames.size, 0);
  view.hide(false); assert.equal(view.observed, 2);
  view.enter(1); view.tick(0); view.hide(true);
  assert.equal(view.label.textContent, "100%"); assert.equal(view.frames.size, 0);
  view.cleanup();
});

test("unmount cancels the pending frame and leaves no listeners", () => {
  const view = mount(); view.enter(1); assert.equal(view.frames.size, 1);
  view.cleanup(); view.enter(1); assert.equal(view.frames.size, 0);
});
