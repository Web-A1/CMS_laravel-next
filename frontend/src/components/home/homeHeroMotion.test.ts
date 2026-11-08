import assert from "node:assert/strict";
import test from "node:test";
import { readFileSync } from "node:fs";
import ts from "typescript";

const shared = await import(new URL("./homeMotion.ts", import.meta.url).href) as typeof import("./homeMotion");
const output = ts.transpileModule(readFileSync(new URL("./heroSceneMotion.ts", import.meta.url), "utf8"), {
  compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
}).outputText;
const sandbox = { exports: {} as typeof import("./heroSceneMotion") };
new Function("require", "exports", output)((id: string) => { assert.equal(id, "./homeMotion"); return shared; }, sandbox.exports);
const { animateHomeHero } = sandbox.exports;

class Events {
  listeners = new Map<string, Set<() => void>>();
  addEventListener(name: string, fn: () => void) { if (!this.listeners.has(name)) this.listeners.set(name, new Set()); this.listeners.get(name)?.add(fn); }
  removeEventListener(name: string, fn: () => void) { this.listeners.get(name)?.delete(fn); }
  fire(name: string) { this.listeners.get(name)?.forEach(fn => fn()); }
}

function setup({ mobile = false, reduced = false, editing = false, hidden = false, hiddenCards = [] as string[] } = {}) {
  type Recorded = { element: Box; frames: Keyframe[]; timing: KeyframeAnimationOptions; startTime: number | null; playState: string; cancel: () => void; pause: () => void; play: () => void };
  const motions: Recorded[] = [];
  class Box extends Events {
    children: Box[] = [];
    parent?: Box;
    dataset: Record<string, string> = {};
    shown = true;
    left = 0;
    top = 0;
    className: string;
    tag: string;
    constructor(className = "", tag = "div") { super(); this.className = className; this.tag = tag; }
    append(...children: Box[]) { children.forEach(child => { child.parent = this; this.children.push(child); }); }
    querySelectorAll(selector: string): Box[] {
      const descendants = this.children.flatMap(child => [child, ...child.querySelectorAll("*")]);
      return selector === "*" ? descendants : descendants.filter(child => selector === "[data-card]" ? Boolean(child.dataset.card) : child.className === selector.slice(1));
    }
    querySelector(selector: string): Box | null { return selector === ":scope > picture" ? this.children.find(child => child.tag === "picture") ?? null : this.querySelectorAll(selector)[0] ?? null; }
    contains(element: unknown): boolean { return element === this || this.children.some(child => child.contains(element)); }
    getClientRects(): object[] { return this.shown ? [{}] : []; }
    getBoundingClientRect() { return { left: this.left, top: this.top, width: 100, height: 80 }; }
    animate(frames: Keyframe[], timing: KeyframeAnimationOptions) {
      const item = { element: this, frames, timing, startTime: null as number | null, playState: "running", cancel() { this.playState = "idle"; }, pause() { this.playState = "paused"; }, play() { this.playState = "running"; } };
      motions.push(item); return item;
    }
  }
  const hero = new Box("home-hero"), title = new Box("", "h1");
  hero.dataset.heroMotion = "pending";
  const description = new Box("home-hero__desktop-support"), cta = new Box("home-hero__cta"), video = new Box("home-hero__media");
  const scene = new Box("home-hero__non-mobile-scene"); scene.dataset.editing = String(editing);
  const cards = Array.from({ length: 9 }, (_, i) => {
    const card = new Box("home-hero__non-mobile-card");
    card.dataset.card = String(i + 1); card.shown = !hiddenCards.includes(card.dataset.card);
    card.left = i % 2 ? 900 : 100; card.top = 100 + i * 60;
    card.append(new Box("", "picture"), new Box("home-hero__float-shadow"));
    return card;
  });
  hero.append(title, description, cta, video, scene); scene.append(...cards);
  const reducedQuery = Object.assign(new Events(), { matches: reduced });
  const viewportQuery = Object.assign(new Events(), { matches: !mobile });
  const win = Object.assign(new Events(), { matchMedia: (q: string) => q.includes("480") ? viewportQuery : reducedQuery, location: { search: "" }, innerHeight: 844 });
  const doc = Object.assign(new Events(), { hidden, activeElement: null as unknown, documentElement: { clientWidth: 1200 }, timeline: { currentTime: 42 as number | null } });
  const observers: Observer[] = [];
  class Observer {
    disconnected = false;
    constructor(publicCallback: (entries: { isIntersecting: boolean }[]) => void) { this.callback = publicCallback; observers.push(this); }
    callback: (entries: { isIntersecting: boolean }[]) => void;
    observe() {}
    disconnect() { this.disconnected = true; }
  }
  const globals = { window: win, document: doc, IntersectionObserver: Observer };
  const originals = Object.fromEntries(Object.keys(globals).map(key => [key, Object.getOwnPropertyDescriptor(globalThis, key)]));
  Object.entries(globals).forEach(([key, value]) => Object.defineProperty(globalThis, key, { configurable: true, value }));
  const listenerTargets = [win, doc, reducedQuery, viewportQuery, hero];
  return { hero: hero as unknown as HTMLElement, title, description, cta, cards, video, motions, win, doc, reducedQuery, viewportQuery, observers,
    listenerCount: () => listenerTargets.reduce((total, target) => total + [...target.listeners.values()].reduce((sum, set) => sum + set.size, 0), 0),
    restore() { for (const key of Object.keys(globals)) { const descriptor = originals[key]; if (descriptor) Object.defineProperty(globalThis, key, descriptor); else Reflect.deleteProperty(globalThis, key); } },
  };
}

test("desktop starts the original flight immediately and leaves copy, CTA and video to CSS/SSR", () => {
  const env = setup();
  try {
    const cleanup = animateHomeHero(env.hero);
    assert.equal(env.motions.length, 45);
    assert.equal(env.hero.dataset.heroMotion, "playing");
    assert.ok(env.motions.every(item => item.startTime === 42));
    assert.ok([env.title, env.description, env.cta, env.video].every(element => !env.motions.some(item => item.element === element)));
    const flights = env.motions.filter(item => item.element.tag === "picture" && item.timing.iterations !== Infinity);
    assert.deepEqual(flights.map(item => item.timing.delay), Array.from({ length: 9 }, (_, i) => i * 100));
    for (const item of flights) {
      assert.equal(item.timing.duration, 1300);
      assert.equal(item.timing.easing, shared.HOME_TEAM_MOTION.easing);
      assert.match(String(item.frames[0].transform), /translate\([^)]+\) scale\(2\)/);
      assert.equal(item.frames[1].transform, "none");
      assert.ok(item.frames.every(frame => !("opacity" in frame)));
      const floating = env.motions.find(other => other.element === item.element && other.timing.iterations === Infinity);
      assert.equal(floating?.timing.delay, Number(item.timing.delay) + 1300);
      assert.ok(["translateY(14px)", "translateY(-14px)"].includes(String(floating?.frames[1].transform)));
    }
    cleanup();
    assert.ok(env.motions.every(item => item.playState === "idle"));
    assert.equal(env.listenerCount(), 0);
  } finally { env.restore(); }
});

test("phone, reduced motion, hidden tab, editor and existing focus stay static", () => {
  for (const options of [{ mobile: true }, { reduced: true }, { hidden: true }, { editing: true }]) {
    const env = setup(options);
    try { animateHomeHero(env.hero)(); assert.equal(env.motions.length, 0); assert.equal(env.hero.dataset.heroMotion, "static"); assert.equal(env.listenerCount(), 0); } finally { env.restore(); }
  }
  const env = setup();
  try { env.doc.activeElement = env.cta; animateHomeHero(env.hero)(); assert.equal(env.motions.length, 0); assert.equal(env.listenerCount(), 0); } finally { env.restore(); }
});

test("hidden responsive cards do not animate or allocate listeners when every card is hidden", () => {
  for (const hiddenCards of [["1", "8", "9"], Array.from({ length: 9 }, (_, i) => String(i + 1))]) {
    const env = setup({ hiddenCards });
    try {
      const cleanup = animateHomeHero(env.hero);
      assert.equal(env.motions.length, (9 - hiddenCards.length) * 5);
      if (hiddenCards.length === 9) assert.equal(env.listenerCount(), 0);
      for (const id of hiddenCards) assert.equal(env.motions.some(item => item.element.parent?.dataset.card === id), false);
      cleanup(); assert.equal(env.listenerCount(), 0);
    } finally { env.restore(); }
  }
});

test("resize, focus, reduced motion, breakpoint change and backgrounding cancel without replay", () => {
  for (const trigger of ["resize", "focusin", "reduce", "hidden", "viewport"]) {
    const env = setup();
    try {
      const cleanup = animateHomeHero(env.hero), count = env.motions.length;
      if (trigger === "resize") env.win.fire("resize");
      if (trigger === "focusin") (env.hero as unknown as Events).fire("focusin");
      if (trigger === "reduce") env.reducedQuery.fire("change");
      if (trigger === "viewport") env.viewportQuery.fire("change");
      if (trigger === "hidden") { env.doc.hidden = true; env.doc.fire("visibilitychange"); }
      assert.ok(env.motions.every(item => item.playState === "idle"));
      assert.equal(env.hero.dataset.heroMotion, "static");
      assert.equal(env.listenerCount(), 0);
      env.win.fire("resize"); env.viewportQuery.fire("change");
      assert.equal(env.motions.length, count);
      cleanup(); assert.equal(env.listenerCount(), 0);
    } finally { env.restore(); }
  }
});

test("a visible tab event does not cancel motion; a null timeline uses native animation start", () => {
  const env = setup();
  try {
    env.doc.timeline.currentTime = null;
    const cleanup = animateHomeHero(env.hero);
    env.doc.fire("visibilitychange");
    assert.ok(env.motions.every(item => item.playState === "running" && item.startTime === null));
    cleanup(); assert.equal(env.listenerCount(), 0);
  } finally { env.restore(); }
});

test("desktop arrow directions and anchored shadow growth/float retain their original motion", () => {
  const env = setup();
  try {
    const cleanup = animateHomeHero(env.hero);
    env.cards.forEach((card, i) => {
      const flight = env.motions.find(item => item.element === card.querySelector(":scope > picture") && item.timing.iterations !== Infinity);
      const match = String(flight?.frames[0].transform).match(/translate\(([-\d.]+)px, ([-\d.]+)px\)/);
      assert.ok(match);
      const x = Number(match[1]), y = Number(match[2]); assert.ok(y < 0);
      if (i < 4) assert.ok(x > 0); else if (i === 6) assert.equal(Math.abs(x), 0); else assert.ok(x < 0);
      const shadow = card.querySelector(".home-hero__float-shadow");
      const growth = env.motions.find(item => item.element === shadow && item.frames[0].transform === "scale(0)");
      assert.equal(growth?.timing.delay, flight?.timing.delay); assert.equal(growth?.timing.easing, "linear");
      assert.deepEqual(growth?.frames, [{ transform: "scale(0)" }, { transform: "scale(1)" }]);
      const floating = env.motions.find(item => item.element === shadow && item.timing.iterations === Infinity);
      assert.ok(floating?.frames.every(frame => !String(frame.transform).includes("translate")));
      assert.equal(floating?.frames[0].opacity, shared.HOME_HERO_MOTION.shadowOpacity);
    }); cleanup();
  } finally { env.restore(); }
});

test("offscreen floating pauses and resumes without replaying the flight", () => {
  const env = setup();
  try {
    const cleanup = animateHomeHero(env.hero), count = env.motions.length;
    env.observers[0].callback([{ isIntersecting: false }]);
    assert.ok(env.motions.filter(item => item.timing.iterations === Infinity).every(item => item.playState === "paused"));
    assert.ok(env.motions.filter(item => item.timing.iterations !== Infinity).every(item => item.playState === "running"));
    env.observers[0].callback([{ isIntersecting: true }]);
    assert.ok(env.motions.every(item => item.playState === "running")); assert.equal(env.motions.length, count);
    cleanup(); assert.equal(env.observers[0].disconnected, true);
    env.observers[0].callback([{ isIntersecting: true }]); assert.ok(env.motions.every(item => item.playState === "idle"));
  } finally { env.restore(); }
});
