import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { createRequire } from "node:module";
import test from "node:test";
import ts from "typescript";

const motion = await import(new URL("./homeMotion.ts", import.meta.url).href) as typeof import("./homeMotion");
const localRequire = createRequire(import.meta.url);
const compiled = ts.transpileModule(readFileSync(new URL("./HomeUnderstandingPuzzle.tsx", import.meta.url), "utf8"), {
  compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022, jsx: ts.JsxEmit.ReactJSX },
}).outputText;

function mount(ready = true, managed = false, visible = true, reduce = false) {
  const card = { getBoundingClientRect: () => visible ? { top: 100, bottom: 300, height: 200 } : { top: 1000, bottom: 1200, height: 200 } };
  const observed = new Set<unknown>();
  const style = { transform: "", opacity: "", removeProperty(key: "transform" | "opacity") { this[key] = ""; } };
  const animations: { cancelled: boolean; onfinish?: () => void; cancel(): void }[] = [];
  const piece = { style, animate() {
    const animation = { cancelled: false, cancel() { this.cancelled = true; } };
    animations.push(animation);
    return animation;
  } };
  const refs = [{ closest: (selector: string) => selector === "article" ? card : null }, piece];
  const motionListeners = new Set<() => void>(), visibilityListeners = new Set<() => void>();
  const reduced = { matches: reduce, addEventListener: (_: string, fn: () => void) => motionListeners.add(fn), removeEventListener: (_: string, fn: () => void) => motionListeners.delete(fn) };
  let effect: (() => (() => void) | undefined) | undefined;
  let intersect: ((entries: { isIntersecting: boolean; intersectionRatio: number }[]) => void) | undefined;
  class Observer {
    constructor(callback: typeof intersect) { intersect = callback; }
    observe(target: unknown) { observed.add(target); }
    disconnect() { observed.clear(); }
  }
  let refIndex = 0;
  const exports = {} as typeof import("./HomeUnderstandingPuzzle");
  const documentMock = { hidden: false, addEventListener: (_: string, fn: () => void) => visibilityListeners.add(fn), removeEventListener: (_: string, fn: () => void) => visibilityListeners.delete(fn) };
  new Function("require", "exports", "window", "document", "IntersectionObserver", compiled)((id: string) => {
    if (id === "react") return { useRef: () => ({ current: refs[refIndex++] }), useEffect: (fn: typeof effect) => { effect = fn; } };
    if (id === "./homeMotion") return motion;
    if (id === "./HomeFactsMotion") return { useHomeFactsMotion: () => ({ puzzleReady: ready, entranceManaged: managed, skipMotion: false }) };
    return localRequire(id);
  }, exports, { matchMedia: () => reduced, innerHeight: 900 }, documentMock, Observer);
  exports.default();
  const cleanup = effect?.();
  return {
    card, observed, animations, style,
    enter() { intersect?.([{ isIntersecting: true, intersectionRatio: 1 }]); },
    leave() { intersect?.([{ isIntersecting: false, intersectionRatio: 0 }]); },
    hide() { documentMock.hidden = true; visibilityListeners.forEach(fn => fn()); },
    cleanup() { cleanup?.(); assert.equal(motionListeners.size, 0); assert.equal(visibilityListeners.size, 0); },
  };
}

test("standalone puzzle observes only its own card and animates once", () => {
  const view = mount();
  assert.deepEqual([...view.observed], [view.card]);
  assert.equal(view.animations.length, 0);
  view.enter(); view.enter();
  assert.equal(view.animations.length, 1);
  view.animations[0].onfinish?.();
  assert.equal(view.style.opacity, "");
  view.cleanup();
});

test("waiting managed puzzle installs no observer or listeners before its entrance signal", () => {
  const view = mount(false, true);
  assert.equal(view.observed.size, 0);
  assert.equal(view.style.opacity, "0");
  view.enter();
  assert.equal(view.animations.length, 0);
  view.cleanup();
  assert.equal(view.style.opacity, "");
});

test("managed visible puzzle starts immediately; an offscreen ready card keeps its completed puzzle", () => {
  const visible = mount(true, true);
  assert.equal(visible.animations.length, 1);
  visible.cleanup();
  const offscreen = mount(true, true, false);
  assert.equal(offscreen.animations.length, 0);
  assert.equal(offscreen.observed.size, 0);
  assert.equal(offscreen.style.opacity, "");
  offscreen.enter();
  assert.equal(offscreen.animations.length, 0);
  offscreen.cleanup();
});

test("leaving during puzzle motion completes it; returning does not animate again", () => {
  const view = mount(true, true);
  assert.equal(view.animations.length, 1);
  view.leave();
  assert.equal(view.animations[0].cancelled, true);
  assert.equal(view.style.opacity, "");
  view.enter();
  assert.equal(view.animations.length, 1);
  view.cleanup();
});

test("reduced motion retains the complete puzzle; backgrounding cancels active motion", () => {
  const reduced = mount(true, true, true, true);
  assert.equal(reduced.animations.length, 0);
  assert.equal(reduced.style.opacity, "");
  reduced.cleanup();
  const active = mount(true, true);
  active.hide();
  assert.equal(active.animations[0].cancelled, true);
  assert.equal(active.style.opacity, "");
  active.cleanup();
});
