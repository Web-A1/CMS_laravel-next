import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import test from "node:test";
import ts from "typescript";

const output = ts.transpileModule(readFileSync(new URL("./HomeEntranceMotion.tsx", import.meta.url), "utf8"), {
  compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
}).outputText;

const motionConfig = {};
new Function("exports", ts.transpileModule(readFileSync(new URL("./homeMotion.ts", import.meta.url), "utf8"), {
  compilerOptions: { module: ts.ModuleKind.CommonJS },
}).outputText)(motionConfig);

function setup(reduce = false, count = 1, mobile = false) {
  let now = 0;
  const listeners = new Map<string, (event?: unknown) => void>();
  class ElementMock {
    classes = new Set<string>();
    styles = new Map<string, string>();
    style = {
      setProperty: (name: string, value: string) => this.styles.set(name, value),
      removeProperty: (name: string) => this.styles.delete(name),
    };
    classList = {
      add: (name: string) => this.classes.add(name),
      remove: (name: string) => this.classes.delete(name),
      contains: (name: string) => this.classes.has(name),
    };
    getClientRects() { return [{}]; }
    contains(node: unknown) { return node === this; }
    closest() { return this.classes.has("is-home-entering") || this.classes.has("is-home-pending") ? this : null; }
  }
  class AnimationMock {
    animationName: string;
    target: ElementMock;
    constructor(animationName: string, target: ElementMock) {
      this.animationName = animationName;
      this.target = target;
    }
  }
  const elements = Array.from({ length: count }, () => new ElementMock());
  const mobileElements = Array.from({ length: 3 }, () => new ElementMock());
  const observed = new Set<ElementMock>();
  let callback: (entries: unknown[]) => void = () => {};
  let observerOptions: IntersectionObserverInit = {};
  class Observer {
    constructor(fn: typeof callback, options: IntersectionObserverInit) {
      callback = fn;
      observerOptions = options;
    }
    observe(target: ElementMock) { observed.add(target); }
    unobserve(target: ElementMock) { observed.delete(target); }
    disconnect() { observed.clear(); }
  }
  const events = {
    addEventListener: (name: string, fn: (event?: unknown) => void) => listeners.set(name, fn),
    removeEventListener: (name: string) => listeners.delete(name),
  };
  const motion = { matches: reduce, ...events };
  const mobileViewport = {
    matches: mobile,
    addEventListener: (_name: string, fn: (event?: unknown) => void) => listeners.set("viewport", fn),
    removeEventListener: () => listeners.delete("viewport"),
  };
  const page = { querySelectorAll: (selector: string) => selector.includes(".home-reviews__heading") ? mobileElements : elements, ...events };
  const documentMock = { hidden: false, activeElement: null as ElementMock | null, querySelector: () => page, ...events };
  let cleanup = () => {};
  const cascades: { root: ElementMock; selector: string; complete: () => void; cancelled: boolean }[] = [];
  const exports: { default?: () => null } = {};
  new Function("require", "exports", "window", "document", "IntersectionObserver", "Element", "AnimationEvent", "performance", output)(
    () => ({
      ...motionConfig,
      useEffect: (effect: () => () => void) => { cleanup = effect(); },
      animateHomeCardCascade: (root: ElementMock, selector: string, complete: () => void) => {
        const cascade = { root, selector, complete, cancelled: false };
        cascades.push(cascade);
        return () => { cascade.cancelled = true; };
      },
    }), exports,
    { IntersectionObserver: Observer, matchMedia: (query: string) => query.includes("48rem") ? mobileViewport : motion }, documentMock, Observer, ElementMock, AnimationMock, { now: () => now },
  );
  exports.default?.();
  return {
    elements, mobileElements, observed, listeners, cleanup, observerOptions, cascades,
    advance(time: number) { now = time; },
    enter(index = 0, isIntersecting = true) { callback([{ target: elements[index], isIntersecting }]); },
    enterTogether(indices: number[]) { callback(indices.map((index) => ({ target: elements[index], isIntersecting: true }))); },
    enterMobile(index: number) { callback([{ target: mobileElements[index], isIntersecting: true }]); },
    leaveMobile() { mobileViewport.matches = false; listeners.get("viewport")?.(); },
    enterMobileViewport() { mobileViewport.matches = true; listeners.get("viewport")?.(); },
    reduce() { motion.matches = true; listeners.get("change")?.(); },
    restoreMotion() { motion.matches = false; listeners.get("change")?.(); },
    hide() { documentMock.hidden = true; listeners.get("visibilitychange")?.(); },
    endAnimation(index = 0, name = "home-entrance") {
      listeners.get("animationend")?.(new AnimationMock(name, elements[index]));
    },
    focus(index = 0) {
      documentMock.activeElement = elements[index];
      listeners.get("focusin")?.({ target: elements[index] });
    },
  };
}

test("mobile heading, carousel shell and lesson block reveal independently and clear at desktop", () => {
  const state = setup(false, 1, true);
  assert.equal(state.observed.size, 4);
  state.enterMobile(0);
  assert.equal(state.mobileElements[0].classes.has("is-home-entering"), true);
  assert.equal(state.mobileElements[1].classes.has("is-home-pending"), true);
  state.enterMobile(1);
  assert.equal(state.mobileElements[1].classes.has("is-home-entering"), true);
  state.leaveMobile();
  assert.ok(state.mobileElements.every((element) => element.classes.size === 0));
  assert.equal(state.observed.size, 1);
  state.enterMobile(2);
  assert.equal(state.mobileElements[2].classes.size, 0);
  state.enterMobileViewport();
  assert.equal(state.observed.has(state.mobileElements[0]), false);
  assert.equal(state.observed.has(state.mobileElements[1]), false);
  assert.equal(state.observed.has(state.mobileElements[2]), true);
  state.enterMobile(2);
  assert.equal(state.mobileElements[2].classes.has("is-home-entering"), true);
  state.cleanup();
});

test("desktop mount registers unplayed mobile targets when entering the mobile viewport", () => {
  const state = setup();
  assert.equal(state.observed.size, 1);
  assert.ok(state.mobileElements.every((element) => element.classes.size === 0));
  state.enterMobileViewport();
  assert.equal(state.observed.size, 4);
  assert.ok(state.mobileElements.every((element) => element.classes.has("is-home-pending")));
  state.enterMobile(0);
  state.leaveMobile();
  state.enterMobileViewport();
  assert.equal(state.observed.has(state.mobileElements[0]), false);
  assert.equal(state.observed.has(state.mobileElements[1]), true);
  state.cleanup();
});

test("mobile pending targets survive a desktop detour before their first entrance", () => {
  const state = setup(false, 1, true);
  state.leaveMobile();
  state.enterMobile(0);
  assert.equal(state.mobileElements[0].classes.size, 0);
  state.enterMobileViewport();
  assert.equal(state.observed.size, 4);
  state.enterMobile(0);
  assert.equal(state.mobileElements[0].classes.has("is-home-entering"), true);
  state.cleanup();
});

test("reveal boundary is inset 80px from the bottom of the viewport", () => {
  const state = setup();
  assert.equal(state.observerOptions.rootMargin, "0px 0px -80px 0px");
  assert.equal(state.observerOptions.threshold, 0);
  state.cleanup();
});

test("content is prepared before intersection to avoid visible-hide-reveal flicker; entrance runs once", () => {
  const state = setup();
  assert.deepEqual([...state.elements[0].classes], ["is-home-pending"]);
  state.enter(0, false);
  assert.deepEqual([...state.elements[0].classes], ["is-home-pending"]);
  assert.equal(state.observed.size, 1);
  state.enter();
  assert.equal(state.elements[0].classes.has("is-home-entering"), true);
  assert.equal(state.elements[0].classes.has("is-home-pending"), false);
  assert.equal(state.observed.size, 0);
  state.endAnimation();
  state.enter();
  assert.equal(state.elements[0].classes.size, 0);
  state.cleanup();
});

test("heading and cards reveal independently; lower cards wait for their own intersection", () => {
  const state = setup(false, 5);
  state.enter(0);
  state.enter(1);
  assert.equal(state.observed.size, 3);
  assert.deepEqual(state.elements.map((element) => element.classes.has("is-home-entering")), [true, true, false, false, false]);
  state.endAnimation(0);
  assert.equal(state.elements[0].classes.size, 0);
  assert.equal(state.elements[1].classes.size, 1);
  state.enter(4);
  assert.equal(state.elements[4].classes.size, 1);
  state.endAnimation(4);
  assert.equal(state.elements[4].classes.size, 0);
  assert.equal(state.elements[1].classes.size, 1);
  state.cleanup();
});

test("unrelated animation events do not end an entrance", () => {
  const state = setup();
  state.enter();
  state.endAnimation(0, "unrelated-animation");
  assert.equal(state.elements[0].classes.size, 1);
  state.endAnimation(0, "home-entrance");
  assert.equal(state.elements[0].classes.size, 0);
  state.cleanup();
});

test("focus immediately ends an active entrance and prevents motion on focused content", () => {
  const state = setup(false, 2);
  state.enter(0);
  state.focus(0);
  assert.equal(state.elements[0].classes.size, 0);
  state.focus(1);
  assert.equal(state.elements[1].classes.size, 0);
  assert.equal(state.observed.size, 0);
  state.enter(1);
  assert.equal(state.elements[1].classes.size, 0);
  state.cleanup();
});

test("unmount restores pending content even when it never entered the viewport", () => {
  const state = setup(false, 3);
  assert.equal(state.elements.every((element) => element.classes.has("is-home-pending")), true);
  state.cleanup();
  assert.equal(state.elements.every((element) => element.classes.size === 0), true);
});

test("initial reduced motion observes nothing; enabling it cancels active and pending entrances", () => {
  const reduced = setup(true);
  assert.equal(reduced.observed.size, 0);
  reduced.cleanup();
  const state = setup(false, 3);
  state.enter(0);
  state.reduce();
  assert.equal(state.observed.size, 0);
  assert.equal(state.elements[0].classes.size, 0);
  state.restoreMotion();
  state.enter(0);
  state.enter(1);
  assert.equal(state.elements.every((element) => element.classes.size === 0), true);
  state.cleanup();
});

test("hidden documents end active entrances; unmount cleans every listener and target", () => {
  const state = setup(false, 3);
  state.enter(0);
  state.enter(1);
  state.hide();
  assert.equal(state.elements.every((element) => element.classes.size === 0), true);
  state.cleanup();
  assert.equal(state.observed.size, 0);
  assert.equal(state.listeners.size, 0);
});

test("FAQ rows below the viewport wait for their own entrance; visible rows cascade once", () => {
  const state = setup(false, 5);
  state.elements[2].classes.add("home-faq__item");
  state.elements[3].classes.add("home-faq__item");
  state.elements[4].classes.add("home-faq__more");
  state.enter(0);
  state.enter(1);
  assert.ok(state.elements.slice(2).every((element) => element.classes.has("is-home-pending")));
  state.enterTogether([2, 3]);
  assert.equal(state.elements[2].styles.get("--home-entrance-delay"), "0ms");
  assert.equal(state.elements[3].styles.get("--home-entrance-delay"), "80ms");
  assert.equal(state.elements[4].classes.has("is-home-pending"), true);
  assert.equal(state.cascades.length, 0);
  state.endAnimation(0);
  assert.equal(state.elements[2].classes.has("is-home-entering"), true);
  state.endAnimation(2);
  assert.equal(state.elements[2].classes.has("is-home-entering"), false);
  state.enter(2);
  assert.equal(state.elements[2].classes.has("is-home-entering"), false);
  state.advance(200);
  state.enter(4);
  assert.equal(state.elements[4].styles.get("--home-entrance-delay"), "0ms");
  state.cleanup();
  assert.ok(state.elements.every((element) => element.styles.size === 0));
});

test("FAQ button follows the last question even when intersections arrive in successive frames", () => {
  const state = setup(false, 3);
  state.elements[0].classes.add("home-faq__item");
  state.elements[1].classes.add("home-faq__item");
  state.elements[2].classes.add("home-faq__more");
  state.enterTogether([0, 1]);
  state.advance(20);
  state.enter(2);
  assert.deepEqual(state.elements.map((element) => element.styles.get("--home-entrance-delay")), ["0ms", "80ms", "140ms"]);
  state.cleanup();
});

test("focus and reduced motion cancel active FAQ rows and remove their delays", () => {
  for (const stop of ["focus", "reduce"] as const) {
    const state = setup();
    state.elements[0].classes.add("home-faq__item");
    state.enter();
    if (stop === "focus") state.focus();
    else state.reduce();
    assert.equal(state.elements[0].classes.has("is-home-entering"), false);
    assert.equal(state.elements[0].classes.has("is-home-pending"), false);
    assert.equal(state.elements[0].styles.size, 0);
    state.cleanup();
  }
});
