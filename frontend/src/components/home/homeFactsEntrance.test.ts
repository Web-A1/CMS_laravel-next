import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import test from "node:test";
import ts from "typescript";

const motion = await import(new URL("./homeMotion.ts", import.meta.url).href) as typeof import("./homeMotion");
const compiled = ts.transpileModule(readFileSync(new URL("./homeFactsEntrance.ts", import.meta.url), "utf8"), {
  compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
}).outputText;

function setup(initialReduce = false, supported = true, wide = true, phone = false) {
  const events: string[] = [];
  const animations: { target: number; keyframes: Keyframe[]; options: KeyframeAnimationOptions; cancelled: boolean; onfinish?: () => void; cancel(): void }[] = [];
  const listeners = new Map<string, () => void>();
  const frames = new Map<number, FrameRequestCallback>();
  let nextFrame = 0;
  let easedProgress = 0;
  const targets = Array.from({ length: 4 }, (_, target) => ({
    style: { opacity: "", removeProperty() { this.opacity = ""; } },
    animate(keyframes: Keyframe[], options: KeyframeAnimationOptions) {
      const animation = { target, keyframes, options, effect: { getComputedTiming: () => ({ progress: easedProgress }) }, cancelled: false, cancel() { this.cancelled = true; } };
      animations.push(animation);
      return animation;
    },
  }));
  const section = {
    querySelector(selector: string) { return targets[["#home-facts-title", ".home-fact-card--license", ".home-fact-card--feature", ".home-fact-card--lesson"].indexOf(selector)]; },
    addEventListener: (name: string, fn: () => void) => listeners.set(name, fn),
    removeEventListener: (name: string) => listeners.delete(name),
  };
  const reduced = { matches: initialReduce, addEventListener: section.addEventListener, removeEventListener: section.removeEventListener };
  const desktop = {
    matches: wide,
    addEventListener: (_name: string, fn: () => void) => listeners.set("viewport", fn),
    removeEventListener: () => listeners.delete("viewport"),
  };
  const documentMock = { hidden: false, ...section };
  let intersect: ((entries: { target: object; isIntersecting: boolean; intersectionRatio: number }[]) => void) | undefined;
  const observed = new Set<object>();
  class Observer {
    constructor(callback: typeof intersect, options: IntersectionObserverInit) {
      intersect = callback;
      assert.deepEqual(options.threshold, [0, 0.15]);
      assert.equal(options.rootMargin, "0px");
    }
    observe(target: object) { observed.add(target); }
    unobserve(target: object) { observed.delete(target); }
    disconnect() { observed.clear(); }
  }
  const exports = {} as typeof import("./homeFactsEntrance");
  new Function("require", "exports", "window", "document", "IntersectionObserver", "getComputedStyle", compiled)(
    () => motion, exports, {
      matchMedia: (query: string) => query.includes("480px") ? { matches: phone } : query.includes("64rem") ? desktop : reduced,
      requestAnimationFrame: (callback: FrameRequestCallback) => { frames.set(++nextFrame, callback); return nextFrame; },
      cancelAnimationFrame: (id: number) => frames.delete(id),
    }, documentMock, supported ? Observer : undefined,
    () => ({ getPropertyValue: () => "linear(0, 1)" }),
  );
  const controller = exports.mountHomeFactsEntrance(section as unknown as HTMLElement, {
    startPuzzle: () => events.push("puzzle"), startProgress: () => events.push("progress"), settle: () => events.push("settled"),
  });
  return {
    targets, animations, events, observed, listeners, controller,
    tick(progress: number) {
      easedProgress = progress;
      const pending = [...frames.values()];
      frames.clear();
      pending.forEach(callback => callback(0));
    },
    enter(...indices: number[]) { intersect?.(indices.map(index => ({ target: targets[index], isIntersecting: true, intersectionRatio: 1 }))); },
    enterPartially(index: number, ratio: number) { intersect?.([{ target: targets[index], isIntersecting: ratio > 0, intersectionRatio: ratio }]); },
    leave(index: number) { intersect?.([{ target: targets[index], isIntersecting: false, intersectionRatio: 0 }]); },
    finish(target: number) { const animation = animations.find(item => item.target === target); if (animation && !animation.cancelled) animation.onfinish?.(); },
    reduce() { reduced.matches = true; listeners.get("change")?.(); },
    hide(hidden: boolean) { documentMock.hidden = hidden; listeners.get("visibilitychange")?.(); },
    focus() { listeners.get("focusin")?.(); },
    resize(wide: boolean) { desktop.matches = wide; listeners.get("viewport")?.(); },
  };
}

test("phone cards wait until fifteen percent is visible while desktop starts at the edge", () => {
  const phone = setup(false, true, false, true);
  phone.enterPartially(1, 0.01);
  phone.enterPartially(1, 0.14);
  assert.equal(phone.animations.length, 0);
  phone.enterPartially(1, 0.15);
  assert.equal(phone.animations.length, 1);
  const desktop = setup();
  desktop.enterPartially(1, 0.01);
  assert.equal(desktop.animations.length, 1);
  phone.controller.dispose();
  desktop.controller.dispose();
});

test("entered cards are no longer observed and finished sections stay disconnected", () => {
  const view = setup();
  view.enter(0, 1);
  assert.deepEqual([...view.observed], view.targets.slice(2));
  view.enter(2, 3);
  assert.equal(view.observed.size, 0);
  view.hide(false);
  assert.equal(view.observed.size, 0);
  view.controller.dispose();
});

test("phone cards each enter from the right only when individually visible", () => {
  const view = setup(false, true, false, true);
  view.enter(0);
  assert.equal(view.animations[0].keyframes[0].translate, "-100px 0");
  for (const index of [1, 2, 3]) {
    view.enter(index);
    assert.equal(view.animations.length, index + 1);
    assert.equal(view.animations[index].keyframes[0].translate, "100px 0");
    assert.equal(view.animations[index].options.delay, 0);
  }
  view.controller.dispose();
});

test("phone siblings visible together retain the shared cascade step", () => {
  const view = setup(false, true, false, true);
  view.enter(1, 2, 3);
  assert.deepEqual(view.animations.map(({ options }) => options.delay), [0, 80, 160]);
  assert.ok(view.animations.every(({ keyframes }) => keyframes[0].translate === "100px 0"));
  view.controller.dispose();
});

test("illustrations overlap the final four pixels of travel without waiting for the spring tail", () => {
  const view = setup();
  view.enter(0, 1, 2, 3);
  view.tick(0.95);
  assert.deepEqual(view.events, []);
  view.tick(0.97);
  assert.deepEqual(view.events, ["puzzle", "progress"]);
  view.finish(2);
  view.finish(3);
  assert.deepEqual(view.events, ["puzzle", "progress"], "finish must not restart illustrations");
  view.controller.dispose();
});

test("all cards use equal homepage cascade intervals and the existing directions and spring", () => {
  const view = setup();
  view.enter(0, 1, 2, 3);
  assert.deepEqual(view.animations.map(({ target }) => target), [0, 1, 2, 3]);
  assert.deepEqual(view.animations.map(({ options }) => options.delay), [0, 80, 160, 240]);
  assert.deepEqual(view.animations.map(({ keyframes }) => keyframes[0].translate), ["-100px 0", "-100px 0", "100px 0", "100px 0"]);
  assert.ok(view.animations.every(({ options }) => options.duration === motion.HOME_ENTRANCE_MOTION.durationMs && options.easing === "linear(0, 1)"));
  assert.deepEqual(view.events, []);
  view.finish(1); assert.deepEqual(view.events, []);
  view.finish(2); assert.deepEqual(view.events, ["puzzle"]);
  view.finish(3);
  assert.equal(view.animations[3].target, 3);
  assert.equal(view.animations[3].keyframes[0].translate, "100px 0");
  assert.equal(view.animations[3].options.delay, 3 * motion.HOME_ENTRANCE_MOTION.staggerMs);
  assert.deepEqual(view.events, ["puzzle", "progress"]);
  view.enter(0, 1, 2, 3);
  assert.equal(view.animations.length, 4, "all entrances are one-shot");
  view.controller.dispose();
});

test("desktop offscreen cards wait for their own viewport entry", () => {
  const view = setup();
  view.enter(0); assert.equal(view.animations.length, 1);
  view.enter(1);
  assert.deepEqual(view.animations.slice(1).map(({ target, options }) => [target, options.delay]), [[1, 0]]);
  view.enter(2, 3);
  assert.deepEqual(view.animations.slice(2).map(({ target, options }) => [target, options.delay]), [[2, 0], [3, 80]]);
  assert.deepEqual(view.events, []);
  view.finish(3); assert.deepEqual(view.events, ["progress"], "scale readiness is independent of puzzle completion");
  view.finish(2); assert.deepEqual(view.events, ["progress", "puzzle"]);
  view.controller.dispose();
});

test("scrolling straight to the final card does not animate offscreen siblings", () => {
  const view = setup(); view.enter(3);
  assert.deepEqual(view.events, []);
  assert.deepEqual(view.animations.map(({ target }) => target), [3]);
  view.finish(3); assert.deepEqual(view.events, ["progress"]);
  view.controller.dispose();
});

test("stacked layouts wait for each visible card and preserve the order of visible siblings", () => {
  const view = setup(false, true, false);
  view.enter(0, 1);
  assert.deepEqual(view.animations.map(({ target }) => target), [0, 1]);
  view.finish(1); assert.deepEqual(view.events, []);
  view.enter(2, 3);
  assert.deepEqual(view.animations.slice(2).map(({ target, options }) => [target, options.delay]), [[2, 0], [3, 80]]);
  view.finish(2); view.finish(3);
  assert.deepEqual(view.events, ["puzzle", "progress"]);
  view.controller.dispose();
});

test("resizing does not release offscreen cards or replay earlier stages", () => {
  const view = setup(false, true, false);
  view.enter(0, 1); view.finish(0); view.finish(1);
  view.resize(true); view.enter(2);
  assert.deepEqual(view.animations.map(({ target }) => target), [0, 1, 2]);
  view.resize(false); view.enter(2, 3);
  assert.equal(view.animations.length, 4);
  view.controller.dispose();
});

test("reduced motion and missing observer keep all content visible", () => {
  for (const view of [setup(true), setup(false, false)]) {
    assert.deepEqual(view.events, ["settled"]);
    assert.ok(view.targets.every(({ style }) => style.opacity === ""));
    assert.equal(view.animations.length, 0);
    view.controller.dispose();
  }
});

test("focus, backgrounding and reduced-motion change settle active work", () => {
  for (const settle of [(view: ReturnType<typeof setup>) => view.focus(), (view: ReturnType<typeof setup>) => view.hide(true), (view: ReturnType<typeof setup>) => view.reduce()]) {
    const view = setup(); view.enter(0, 1, 2, 3); settle(view);
    assert.deepEqual(view.events, ["settled"]);
    assert.ok(view.animations.every(({ cancelled }) => cancelled));
    assert.ok(view.targets.every(({ style }) => style.opacity === ""));
    view.controller.dispose(); assert.equal(view.listeners.size, 0);
  }
});

test("cleanup cancels entrances and restores every target without starting illustrations", () => {
  const view = setup(); view.enter(0, 1, 2, 3); view.controller.dispose();
  view.animations.forEach(animation => animation.onfinish?.());
  assert.deepEqual(view.events, []);
  assert.equal(view.observed.size, 0);
  assert.equal(view.listeners.size, 0);
  assert.ok(view.targets.every(({ style }) => style.opacity === ""));
});
