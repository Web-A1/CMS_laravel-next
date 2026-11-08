import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import test from "node:test";
import ts from "typescript";

const motionConfig = {};
new Function("exports", ts.transpileModule(readFileSync(new URL("./homeMotion.ts", import.meta.url), "utf8"), {
  compilerOptions: { module: ts.ModuleKind.CommonJS },
}).outputText)(motionConfig);

function setup(withCompanion = false) {
  let now = 0;
  let mutate = () => {};
  let complete = () => {};
  let deadline = 0;
  let disconnected = false;
  let scroll: (() => void) | undefined;
  class Card {
    left: number;
    animations: { frames: Keyframe[]; timing: KeyframeAnimationOptions; currentTime: number; cancelled: boolean; cancel: () => void }[] = [];
    constructor(left: number) { this.left = left; }
    getBoundingClientRect() { return { left: this.left, right: this.left + 80, width: 80 }; }
    getClientRects() { return [{}]; }
    addEventListener() {}
    removeEventListener() {}
    animate(frames: Keyframe[], timing: KeyframeAnimationOptions) {
      const animation = { frames, timing, currentTime: 0, cancelled: false, cancel() { this.cancelled = true; } };
      this.animations.push(animation);
      return animation;
    }
  }
  const cards = [new Card(0), new Card(90), new Card(180)];
  const companion = new Card(0);
  const classes = new Set<string>();
  const root = {
    classList: { add: (name: string) => classes.add(name), remove: (name: string) => classes.delete(name) },
    querySelectorAll: () => cards,
    getBoundingClientRect: () => ({ left: 0, right: 120 }),
    contains: (card: Card) => cards.includes(card),
    addEventListener: (_name: string, callback: () => void) => { scroll = callback; },
    removeEventListener: () => { scroll = undefined; },
  };
  const exports: { animateHomeCardCascade?: (root: unknown, selector: string, complete: () => void, companion?: unknown) => () => void } = {};
  new Function("exports", "require", "performance", "window", "getComputedStyle", "MutationObserver", "setTimeout", "clearTimeout",
    ts.transpileModule(readFileSync(new URL("./homeCardCascade.ts", import.meta.url), "utf8"), {
      compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
    }).outputText,
  )(exports, () => motionConfig,
    { now: () => now }, { innerWidth: 120 }, () => ({ getPropertyValue: () => "ease-out" }),
    class { constructor(callback: () => void) { mutate = callback; } observe() {} disconnect() { disconnected = true; } },
    (callback: () => void, delay: number) => { complete = callback; deadline = now + delay; return 1; }, () => {},
  );
  let completed = false;
  const cleanup = exports.animateHomeCardCascade?.(root, ".card", () => { completed = true; }, withCompanion ? companion : undefined);
  return { cards, companion, classes, Card, cleanup, mutate, complete, scroll: () => scroll?.(), get completed() { return completed; }, get deadline() { return deadline; }, get disconnected() { return disconnected; }, advance(time: number) { now = time; } };
}

test("visible cards cascade from right to left with shared timing; offscreen cards stay untouched", () => {
  const state = setup();
  assert.equal(state.cards[1].animations[0].timing.delay, 0);
  assert.equal(state.cards[0].animations[0].timing.delay, 80);
  assert.equal(state.cards[0].animations[0].timing.duration, 900);
  assert.equal(state.cards[0].animations[0].frames[0].translate, "100px 0");
  assert.equal(state.cards[2].animations.length, 0);
  assert.equal(state.deadline, 980);
  state.scroll();
  assert.equal(state.completed, true);
  state.cleanup?.();
  assert.equal(state.disconnected, true);
  assert.ok(state.cards.slice(0, 2).every((card) => card.animations[0].cancelled));
});

test("lazy replacement resumes the existing timeline instead of restarting the entrance", () => {
  const state = setup();
  const original = state.cards[0];
  state.advance(300);
  state.cards[0] = new state.Card(0);
  state.mutate();
  assert.equal(original.animations[0].cancelled, true);
  assert.equal(state.cards[0].animations[0].currentTime, 300);
  assert.equal(state.cards[1].animations.length, 1);
  assert.equal(state.deadline, 980);
  state.complete();
  assert.equal(state.completed, true);
  state.cleanup?.();
});

test("more link follows the second visible card and carousel snapping is restored on cleanup", () => {
  const state = setup(true);
  assert.ok(state.classes.has("is-home-cascade"));
  assert.equal(state.companion.animations[0].timing.delay, state.cards[1].animations[0].timing.delay);
  assert.equal(state.companion.animations[0].timing.duration, state.cards[1].animations[0].timing.duration);
  state.cleanup?.();
  assert.equal(state.classes.size, 0);
  assert.equal(state.companion.animations[0].cancelled, true);
});
