import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";
import ts from "typescript";

type Mount = typeof import("./homeReviewsMarquee").mountHomeReviewsMarquee;

const motionConfig = {};
new Function("exports", ts.transpileModule(await readFile(new URL("./homeMotion.ts", import.meta.url), "utf8"), {
  compilerOptions: { module: ts.ModuleKind.CommonJS },
}).outputText)(motionConfig);

async function setup({ desktop = true, heights = [200, 240, 180, 300, 220], observers = true } = {}) {
  const source = await readFile(new URL("./homeReviewsMarquee.ts", import.meta.url), "utf8");
  const output = ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText;
  class ElementMock {
    attributes = new Map<string, string>();
    properties = new Map<string, string>();
    writes = 0;
    clientHeight = 608;
    height = 200;
    style = {
      setProperty: (key: string, value: string) => { this.properties.set(key, value); this.writes++; },
      removeProperty: (key: string) => { this.properties.delete(key); },
    };
    setAttribute(key: string, value: string) { this.attributes.set(key, value); }
    removeAttribute(key: string) { this.attributes.delete(key); }
    getBoundingClientRect() { return { height: this.height }; }
    querySelectorAll() { return cards; }
  }
  const cards = heights.map(height => Object.assign(new ElementMock(), { height }));
  const viewport = new ElementMock();
  const track = new ElementMock();
  let intersect: (entries: Array<{ isIntersecting: boolean }>) => void = () => {};
  let resize = () => {};
  const observations = new Set<ElementMock>();
  let intersectionConnected = false;
  const mediaEvents = new Map<string, () => void>();
  const documentEvents = new Map<string, () => void>();
  const media = {
    matches: desktop,
    addEventListener: (key: string, value: () => void) => mediaEvents.set(key, value),
    removeEventListener: (key: string) => mediaEvents.delete(key),
  };
  const documentMock = {
    visibilityState: "visible",
    addEventListener: (key: string, value: () => void) => documentEvents.set(key, value),
    removeEventListener: (key: string) => documentEvents.delete(key),
  };
  const windowMock = {
    matchMedia: () => media,
    getComputedStyle: () => ({ rowGap: "20px" }),
    ...(observers ? {
      IntersectionObserver: class {
        constructor(callback: typeof intersect) { intersect = callback; }
        observe() { intersectionConnected = true; }
        disconnect() { intersectionConnected = false; }
      },
      ResizeObserver: class {
        constructor(callback: typeof resize) { resize = callback; }
        observe(element: ElementMock) { observations.add(element); }
        disconnect() { observations.clear(); }
      },
    } : {}),
  };
  const exports: { mountHomeReviewsMarquee?: Mount } = {};
  new Function("exports", "require", "window", "document", output)(exports, () => motionConfig, windowMock, documentMock);
  const cleanup = exports.mountHomeReviewsMarquee!(viewport as unknown as HTMLElement, track as unknown as HTMLElement);
  return {
    viewport, track, cards, cleanup, observations, mediaEvents, documentEvents,
    enter(isIntersecting = true) { intersect([{ isIntersecting }]); },
    hide(hidden = true) { documentMock.visibilityState = hidden ? "hidden" : "visible"; documentEvents.get("visibilitychange")?.(); },
    changeMode(matches: boolean) { media.matches = matches; mediaEvents.get("change")?.(); },
    resize() { resize(); },
    intersectionConnected: () => intersectionConnected,
  };
}

test("runs only when the desktop viewport intersects and the document is visible", async () => {
  const ui = await setup();
  assert.equal(ui.viewport.attributes.get("data-marquee-ready"), "true");
  assert.notEqual(ui.viewport.attributes.get("data-marquee-active"), "true");
  ui.enter();
  assert.equal(ui.viewport.attributes.get("data-marquee-active"), "true");
  ui.hide();
  assert.equal(ui.viewport.attributes.get("data-marquee-active"), "false");
  ui.hide(false);
  assert.equal(ui.viewport.attributes.get("data-marquee-active"), "true");
  ui.enter(false);
  assert.equal(ui.viewport.attributes.get("data-marquee-active"), "false");
  ui.cleanup();
});

test("pausing and resuming preserves geometry and delays without restarting animations", async () => {
  const ui = await setup();
  const before = ui.cards.map(card => [...card.properties]);
  const writes = ui.viewport.writes;
  ui.enter(); ui.enter(false); ui.enter(); ui.hide(); ui.hide(false);
  assert.deepEqual(ui.cards.map(card => [...card.properties]), before);
  assert.equal(ui.viewport.writes, writes);
  ui.cleanup();
});

test("uses one loop with per-card phases, correct spacing and an offscreen wrap", async () => {
  const ui = await setup();
  const cycle = 1240;
  assert.equal(ui.viewport.properties.get("--home-reviews-loop-from"), "608px");
  assert.equal(ui.viewport.properties.get("--home-reviews-loop-to"), `${608 - cycle}px`);
  assert.equal(ui.viewport.properties.get("--home-reviews-duration"), "24800ms");
  let offset = 0;
  for (const card of ui.cards) {
    const delay = Number.parseFloat(card.properties.get("--home-reviews-card-delay")!);
    const phase = -delay / 24800;
    const position = 608 - phase * cycle;
    assert.ok(Math.abs(((position - offset) % cycle + cycle) % cycle) < 0.001 || Math.abs(position - offset) < 0.001);
    offset += card.height + 20;
  }
  assert.ok(608 - cycle + Math.max(...ui.cards.map(card => card.height)) < 0);
  ui.cleanup();
});

test("unchanged resize observations do not rewrite styles; changed heights are remeasured", async () => {
  const ui = await setup();
  const writes = ui.viewport.writes;
  ui.resize();
  assert.equal(ui.viewport.writes, writes);
  ui.cards[0].height += 40;
  ui.resize();
  assert.equal(ui.viewport.properties.get("--home-reviews-loop-to"), "-672px");
  assert.equal(ui.viewport.properties.get("--home-reviews-duration"), "25600ms");
  ui.cleanup();
});

test("keeps 50 pixels per second across review counts and mixed card heights", async () => {
  for (const heights of [Array(6).fill(220), Array(17).fill(220), [360, 180, 520, 260, 440, 300, 200]]) {
    const ui = await setup({ heights });
    const from = Number.parseFloat(ui.viewport.properties.get("--home-reviews-loop-from")!);
    const to = Number.parseFloat(ui.viewport.properties.get("--home-reviews-loop-to")!);
    const durationSeconds = Number.parseFloat(ui.viewport.properties.get("--home-reviews-duration")!) / 1000;
    assert.equal((from - to) / durationSeconds, 50);
    for (const card of ui.cards) {
      const delay = Number.parseFloat(card.properties.get("--home-reviews-card-delay")!);
      assert.ok(Number.isFinite(delay));
      assert.ok(delay <= 0 && delay > -durationSeconds * 1000);
    }
    ui.cleanup();
  }
});

test("mobile/reduced motion remove positioning and restoring desktop prepares the loop", async () => {
  const ui = await setup({ desktop: false });
  assert.equal(ui.viewport.attributes.has("data-marquee-ready"), false);
  assert.equal(ui.cards[0].properties.size, 0);
  ui.changeMode(true); ui.enter();
  assert.equal(ui.viewport.attributes.get("data-marquee-active"), "true");
  ui.changeMode(false);
  assert.equal(ui.viewport.attributes.has("data-marquee-ready"), false);
  assert.equal(ui.cards[0].properties.size, 0);
  ui.cleanup();
});

test("short content and unavailable observers keep the static manual fallback", async () => {
  for (const options of [{ heights: [100, 100, 100, 100, 100] }, { observers: false }]) {
    const ui = await setup(options);
    ui.enter();
    assert.equal(ui.viewport.attributes.has("data-marquee-ready"), false);
    ui.cleanup();
  }
});

test("cleanup disconnects all observers and listeners and removes only owned styles", async () => {
  const ui = await setup();
  ui.cards[0].properties.set("color", "red");
  ui.cleanup();
  assert.equal(ui.observations.size, 0);
  assert.equal(ui.intersectionConnected(), false);
  assert.equal(ui.documentEvents.size, 0);
  assert.equal(ui.mediaEvents.size, 0);
  assert.equal(ui.viewport.attributes.size, 0);
  assert.deepEqual([...ui.cards[0].properties], [["color", "red"]]);
});
