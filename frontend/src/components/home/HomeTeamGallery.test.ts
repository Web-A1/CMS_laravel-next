import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import test from "node:test";
import ts from "typescript";

function compile(path: string): string {
  return ts.transpileModule(readFileSync(new URL(path, import.meta.url), "utf8"), {
    compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022, jsx: ts.JsxEmit.React, jsxFactory: "jsx" },
  }).outputText;
}

type Listener = () => void;
type Motion = { card: number; frames: Keyframe[]; options: KeyframeAnimationOptions; cancelled: boolean; cancel(): void };
type Props = { ref?: { current: unknown }; className?: string; "data-ui"?: string; onClick?: () => void };

function setup(reduce = false) {
  const animations: Motion[] = [];
  const frames = new Map<number, (now: number) => void>();
  const cancelledFrames: number[] = [];
  const controls = new Map<string, () => void>();
  const phases: unknown[] = [];
  let frameId = 0;
  let now = 0;
  function element() {
    const listeners = new Map<string, Listener>();
    const classes = new Set<string>();
    const properties = new Map<string, string>();
    const style = { opacity: "", translate: "", strokeDashoffset: "", removeProperty(name: string) {
      properties.delete(name);
      if (name === "stroke-dashoffset") this.strokeDashoffset = "";
      if (name === "opacity") this.opacity = "";
      if (name === "translate") this.translate = "";
    }, setProperty(name: string, value: string) { properties.set(name, value); },
      getPropertyValue(name: string) { return properties.get(name) ?? ""; } };
    return { style, listeners, classes, classList: { add: (name: string) => classes.add(name), remove: (name: string) => classes.delete(name) },
      contains: (node: unknown) => node === track,
      addEventListener: (name: string, callback: Listener) => listeners.set(name, callback),
      removeEventListener: (name: string) => listeners.delete(name),
    };
  }
  const gallery = element();
  const thread = element();
  const path = element();
  const scrolls: ScrollToOptions[] = [];
  const track = { ...element(), clientWidth: 400, scrollWidth: 1200, scrollLeft: 0,
    get clientHeight(): number { return Number.parseFloat(this.style.getPropertyValue("--home-team-track-height")) || 400; },
    scrollTo(options: ScrollToOptions) { scrolls.push(options); this.scrollLeft = options.left ?? 0; },
    getBoundingClientRect: () => ({ left: 0, right: 400 }), querySelectorAll: () => cards,
  };
  const cards = Array.from({ length: 12 }, (_, index) => ({ ...element(), visualOffset: 0, computedOpacity: 1, naturalHeight: 200 + index * 10.25,
    getBoundingClientRect() { const left = index * 100 - track.scrollLeft + this.visualOffset; return { left, right: left + 100, height: this.naturalHeight }; },
    animate(keyframes: Keyframe[], options: KeyframeAnimationOptions) {
      const animation: Motion = { card: index, frames: keyframes, options, cancelled: false, cancel() { this.cancelled = true; } };
      animations.push(animation);
      return animation;
    },
  }));
  function media(matches: boolean) { return { matches, listeners: new Map<string, Listener>(), addEventListener(name: string, callback: Listener) { this.listeners.set(name, callback); }, removeEventListener(name: string) { this.listeners.delete(name); } }; }
  const reduced = media(reduce);
  const desktop = media(true);
  const documentMock = { hidden: false, activeElement: null as unknown, listeners: new Map<string, Listener>(), addEventListener(name: string, callback: Listener) { this.listeners.set(name, callback); }, removeEventListener(name: string) { this.listeners.delete(name); } };
  let intersection: (entries: { isIntersecting: boolean }[]) => void = () => {};
  let observerOptions: IntersectionObserverInit = {};
  let observed = false;
  let resized = false;
  let resize: (entries: { target: unknown; contentRect: { width: number } }[]) => void = () => {};
  class IntersectionObserverMock {
    constructor(callback: typeof intersection, options: IntersectionObserverInit) { intersection = callback; observerOptions = options; }
    observe() { observed = true; }
    disconnect() { observed = false; }
  }
  class ResizeObserverMock {
    constructor(callback: typeof resize) { resize = callback; }
    observe() { resized = true; } disconnect() { resized = false; }
  }
  const effects: (() => (() => void))[] = [];
  let stateIndex = 0;
  const react = {
    useRef: (current: unknown) => ({ current }), useId: () => "test-id",
    useState: (initial: unknown) => { const index = stateIndex++; return [initial, (value: unknown) => { if (index === 1) phases.push(value); }]; },
    useCallback: (callback: unknown) => callback, useEffect: (effect: () => (() => void)) => effects.push(effect),
  };
  const config = {};
  new Function("exports", compile("./homeMotion.ts"))(config);
  const exports: { default?: (props: { total: number; children: null }) => unknown } = {};
  new Function("require", "exports", "window", "document", "IntersectionObserver", "ResizeObserver", "getComputedStyle", "performance", "jsx", compile("./HomeTeamGallery.tsx"))(
    (name: string) => name === "react" ? react : name === "./homeMotion" ? config : name === "./homeCardCascade" ? { animateHomeCardCascade: () => () => {} } : { default: "component" },
    exports,
    { IntersectionObserver: IntersectionObserverMock, matchMedia: (query: string) => query.includes("64rem") ? desktop : reduced,
      requestAnimationFrame: (callback: (time: number) => void) => { frames.set(++frameId, callback); return frameId; },
      cancelAnimationFrame: (id: number) => { cancelledFrames.push(id); frames.delete(id); },
    }, documentMock, IntersectionObserverMock, ResizeObserverMock,
    (node: object) => ({ opacity: "computedOpacity" in node ? String(node.computedOpacity) : "0.6", strokeDashoffset: "0.4" }),
    { now: () => now },
    (_type: unknown, props: Props | null, ...children: unknown[]) => {
      if (props?.ref) props.ref.current = props.className === "home-team__gallery" ? gallery : props.className === "home-team__thread" ? thread : props.className === "home-team__thread-path" ? path : track;
      if (props?.onClick && props["data-ui"]) controls.set(props["data-ui"], props.onClick);
      return { props, children };
    },
  );
  exports.default?.({ total: 12, children: null });
  const cleanups = effects.map((effect) => effect());
  return {
    animations, frames, cancelledFrames, cards, gallery, thread, path, track, scrolls, phases, observerOptions,
    get observed() { return observed; }, get resized() { return resized; },
    enter(isIntersecting = true) { intersection([{ isIntersecting }]); },
    next() { controls.get("home.team.next")?.(); }, previous() { controls.get("home.team.previous")?.(); },
    tick(time: number) { now = time; const pending = [...frames.values()]; frames.clear(); pending.forEach((callback) => callback(time)); },
    focus() { documentMock.activeElement = track; gallery.listeners.get("focusin")?.(); },
    reduce() { reduced.matches = true; reduced.listeners.get("change")?.(); },
    mobile() { desktop.matches = false; desktop.listeners.get("change")?.(); },
    resizeCard(index: number, height: number) { cards[index].naturalHeight = height; resize([{ target: cards[index], contentRect: { width: 100 } }]); },
    cleanup() { cleanups.forEach((cleanup) => cleanup()); },
    listenerCount() { return gallery.listeners.size + track.listeners.size + reduced.listeners.size + desktop.listeners.size + documentMock.listeners.size; },
  };
}

test("desktop height follows the current page and protects outgoing cards until transition ends", () => {
  const state = setup();
  const height = () => state.track.style.getPropertyValue("--home-team-track-height");
  assert.equal(height(), "231px");
  state.next();
  assert.equal(height(), "272px");
  state.tick(1820);
  state.previous();
  assert.equal(height(), "272px");
  state.tick(3640);
  assert.equal(height(), "231px");
  state.resizeCard(0, 250.5);
  assert.equal(height(), "251px");
  state.mobile();
  assert.equal(height(), "");
  state.cleanup();
});

test("desktop first entrance uses actual profile timing and reveals once at the inset boundary", () => {
  const state = setup();
  assert.equal(state.observerOptions.rootMargin, "0px 0px -160px 0px");
  assert.equal(state.observerOptions.threshold, 0);
  assert.equal(state.gallery.classes.has("is-team-pending"), true);
  state.enter(false);
  assert.equal(state.animations.length, 0);
  state.enter();
  assert.deepEqual(state.animations.map(({ card }) => card), [0, 1, 2, 3]);
  for (const [index, animation] of state.animations.entries()) {
    assert.deepEqual(animation.options, { duration: 1300, delay: 100 + index * 140, easing: "cubic-bezier(0.25, 0.1, 0.25, 1)", fill: "both" });
    assert.deepEqual(animation.frames, [{ opacity: 0, translate: "56px 0" }, { opacity: 1, offset: 0.5 }, { opacity: 1, translate: "0 0" }]);
  }
  assert.equal(state.gallery.classes.size, 0);
  assert.equal(state.observed, false);
  state.enter();
  assert.equal(state.animations.length, 4);
  state.tick(450);
  assert.equal(state.phases.at(-1), "drawing");
  assert.equal(state.thread.style.opacity, "0");
  assert.equal(state.path.style.strokeDashoffset, "1");
  state.tick(1000);
  assert.equal(state.thread.style.opacity, "1");
  assert.equal(state.path.style.strokeDashoffset, "0.5");
  state.tick(1819);
  assert.equal(state.frames.size, 1);
  state.tick(1820);
  assert.ok(state.animations.every(({ cancelled }) => cancelled));
  assert.equal(state.frames.size, 0);
  assert.equal(state.phases.at(-1), "idle");
  assert.equal(state.thread.style.opacity, "");
  assert.equal(state.path.style.strokeDashoffset, "");
  state.cleanup();
});

test("next and previous run outgoing/incoming animations in the matching direction", () => {
  const state = setup();
  state.next();
  assert.deepEqual(state.animations.map(({ card }) => card), [0, 1, 2, 3, 4, 5, 6, 7]);
  state.animations.slice(0, 4).forEach((animation, index) => {
    assert.deepEqual(animation.options, { duration: 420, delay: index * 35, easing: "ease-in-out", fill: "both" });
    assert.deepEqual(animation.frames, [{ opacity: 1, translate: "400px 0" }, { opacity: 0, translate: "360px 0" }]);
  });
  state.tick(110);
  assert.equal(state.thread.style.opacity, "0.3");
  assert.equal(state.path.style.strokeDashoffset, "0.4");
  state.tick(220);
  assert.equal(state.thread.style.opacity, "0");
  assert.equal(state.path.style.strokeDashoffset, "1");
  state.tick(1820);
  const start = state.animations.length;
  state.previous();
  assert.deepEqual(state.animations.slice(start).map(({ card }) => card), [7, 6, 5, 4, 3, 2, 1, 0]);
  assert.equal(state.animations[start].frames[1].translate, "-360px 0");
  assert.equal(state.animations[start + 4].frames[0].translate, "-56px 0");
  assert.deepEqual(state.scrolls.map(({ left }) => left), [400, 0]);
  state.cleanup();
});

test("rapid reversal cancels old WAAPI/RAF and starts from current visual snapshots", () => {
  const state = setup();
  state.next();
  const first = [...state.animations];
  const oldFrame = [...state.frames.keys()][0];
  state.cards[4].visualOffset = 24;
  state.cards[4].computedOpacity = 0.37;
  state.cards[0].visualOffset = 450;
  state.cards[0].computedOpacity = 0.42;
  state.previous();
  assert.ok(first.every(({ cancelled }) => cancelled));
  assert.ok(state.cancelledFrames.includes(oldFrame));
  assert.equal(state.frames.has(oldFrame), false);
  const reversedCard = state.animations.slice(first.length).find(({ card }) => card === 4);
  assert.ok(reversedCard);
  assert.deepEqual(reversedCard.frames, [{ opacity: 0.37, translate: "-400px 0" }, { opacity: 0, translate: "-360px 0" }]);
  const overlappingIncoming = state.animations.slice(first.length).find(({ card }) => card === 0);
  assert.ok(overlappingIncoming);
  assert.deepEqual(overlappingIncoming.frames[0], { opacity: 0.42, translate: "-400px 0" });
  assert.equal(state.frames.size, 1);
  state.cleanup();
});

test("focus cancellation aligns the selected page and removes all hidden styles", () => {
  const state = setup();
  state.enter();
  state.next();
  state.tick(110);
  state.cards[0].style.opacity = "0";
  state.cards[0].style.translate = "56px 0";
  state.focus();
  assert.ok(state.animations.every(({ cancelled }) => cancelled));
  assert.equal(state.frames.size, 0);
  assert.equal(state.track.scrollLeft, 400);
  assert.equal(state.scrolls.at(-1)?.behavior, "instant");
  assert.equal(state.gallery.classes.size, 0);
  assert.equal(state.thread.style.opacity, "");
  assert.equal(state.path.style.strokeDashoffset, "");
  assert.ok(state.cards.every(({ style }) => style.opacity === "" && style.translate === ""));
  assert.equal(state.phases.at(-1), "idle");
  state.cleanup();
});

test("initial and live reduced motion complete instantly and unmount cleans observers/listeners", () => {
  const reduced = setup(true);
  assert.equal(reduced.observed, false);
  reduced.next();
  assert.deepEqual(reduced.scrolls, [{ left: 400, behavior: "instant" }]);
  assert.equal(reduced.animations.length, 0);
  assert.equal(reduced.frames.size, 0);
  reduced.cleanup();
  const state = setup();
  state.enter();
  state.reduce();
  assert.ok(state.animations.every(({ cancelled }) => cancelled));
  assert.equal(state.frames.size, 0);
  state.next();
  assert.equal(state.animations.length, 4);
  assert.equal(state.scrolls.at(-1)?.behavior, "instant");
  state.cleanup();
  assert.equal(state.listenerCount(), 0);
  assert.equal(state.resized, false);
  assert.equal(state.observed, false);
  const pending = setup();
  pending.cleanup();
  assert.equal(pending.gallery.classes.size, 0);
  assert.equal(pending.listenerCount(), 0);
});
