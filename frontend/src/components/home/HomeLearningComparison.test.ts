import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { createRequire } from "node:module";
import test from "node:test";
import { isValidElement, type ReactNode, type ComponentProps, type PointerEvent, type KeyboardEvent, type MouseEvent } from "react";
import ts from "typescript";

const require = createRequire(import.meta.url);
const compiled = ts.transpileModule(readFileSync(new URL("./HomeLearningComparison.tsx", import.meta.url), "utf8"), {
  compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022, jsx: ts.JsxEmit.ReactJSX, esModuleInterop: true },
}).outputText;

function slider(node: ReactNode): ComponentProps<"button"> {
  if (Array.isArray(node)) {
    for (const child of node) { const found = slider(child); if (found.role === "slider") return found; }
  } else if (isValidElement<{ role?: string; children?: ReactNode }>(node)) {
    if (node.props.role === "slider") return node.props;
    return slider(node.props.children);
  }
  return {};
}

function mount(horizontal = true, reduced = false) {
  const values: Array<number | boolean> = [100, false];
  const captured = new Set<number>();
  const handle = {
    focus() {},
    setPointerCapture(id: number) { captured.add(id); },
    hasPointerCapture(id: number) { return captured.has(id); },
    releasePointerCapture(id: number) { captured.delete(id); },
  };
  const refs = [{ current: { getBoundingClientRect: () => ({ left: 10, top: 20, width: 1000, height: 500 }) } }, { current: handle }, { current: null as number | null }, { current: () => {} }];
  const effects: Array<() => (() => void)> = [];
  let stateIndex = 0, refIndex = 0, mounted = false;
  let change: (() => void) | undefined;
  const media = {
    matches: horizontal,
    addEventListener(_name: string, callback: () => void) { change = callback; },
    removeEventListener() { change = undefined; },
  };
  let motionChange: (() => void) | undefined;
  const motion = {
    matches: reduced,
    addEventListener(_name: string, callback: () => void) { motionChange = callback; },
    removeEventListener() { motionChange = undefined; },
  };
  let intersect: ((entries: Array<{ isIntersecting: boolean; intersectionRatio: number }>) => void) | undefined;
  const frames = new Map<number, FrameRequestCallback>();
  let frameId = 0;
  class Observer {
    constructor(callback: typeof intersect, options: IntersectionObserverInit) {
      assert.equal(options.threshold, 0);
      assert.equal(options.rootMargin, "0px 0px -80px 0px");
      intersect = callback;
    }
    observe() {}
    disconnect() { intersect = undefined; }
  }
  const exports = {} as typeof import("./HomeLearningComparison");
  new Function("require", "exports", "window", "IntersectionObserver", "requestAnimationFrame", "cancelAnimationFrame", compiled)((id: string) => {
    if (id === "react") return {
      useRef: () => refs[refIndex++],
      useState: () => {
        const index = stateIndex++;
        return [values[index], (next: number | boolean | ((value: number | boolean) => number | boolean)) => {
          values[index] = typeof next === "function" ? next(values[index]) : next;
        }];
      },
      useEffect: (effect: () => (() => void)) => { if (!mounted) effects.push(effect); },
    };
    if (id === "next/image") return { __esModule: true, default: "img" };
    if (id.includes("button/Button")) return { __esModule: true, default: "button" };
    if (id.includes("content/GeneratedImage")) return { __esModule: true, default: "picture" };
    if (id === "./homeMotion") return require("./homeMotion.ts");
    if (id === "./comparisonContent") return require("./comparisonContent.ts");
    if (id.endsWith(".css")) return {};
    return require(id);
  }, exports, { matchMedia: (query: string) => query.includes("prefers-reduced-motion") ? motion : media }, Observer,
  (callback: FrameRequestCallback) => { frames.set(++frameId, callback); return frameId; },
  (id: number) => frames.delete(id));
  const portrait = { avifSrc: "/portrait.avif", webpSrc: "/portrait.webp", width: 540, height: 900 };
  const image = { src: "/wide.svg", phonePortraitImage: portrait, tabletPortraitImage: { ...portrait, width: 1150, height: 1150 }, alt: "Демо" };
  function renderTree() { stateIndex = 0; refIndex = 0; return exports.default({ before: image, after: image }); }
  function render() { return slider(renderTree()); }
  render();
  const cleanups = effects.map(effect => effect());
  mounted = true;
  return {
    render, captured,
    enter(ratio = 1) { intersect?.([{ isIntersecting: ratio > 0, intersectionRatio: ratio }]); },
    tick(now: number) { const pending = [...frames.values()]; frames.clear(); pending.forEach(callback => callback(now)); },
    pending() { return frames.size; },
    reduce() { motion.matches = true; motionChange?.(); },
    click() { render().onClick?.({} as MouseEvent<HTMLButtonElement>); },
    pointer(type: "down" | "move" | "up" | "cancel" | "lost", x: number, y = 270, id = 1, isPrimary = true, button = 0) {
      const event = { clientX: x, clientY: y, pointerId: id, pointerType: "touch", isPrimary, button, currentTarget: handle, preventDefault() {} } as unknown as PointerEvent<HTMLButtonElement>;
      const props = render();
      if (type === "down") props.onPointerDown?.(event);
      if (type === "move") props.onPointerMove?.(event);
      if (type === "up") props.onPointerUp?.(event);
      if (type === "cancel") props.onPointerCancel?.(event);
      if (type === "lost") { captured.delete(id); props.onLostPointerCapture?.(event); }
    },
    key(key: string) {
      let prevented = false;
      render().onKeyDown?.({ key, preventDefault() { prevented = true; } } as unknown as KeyboardEvent<HTMLButtonElement>);
      return prevented;
    },
    rotate() { media.matches = !media.matches; change?.(); },
    cleanup() { cleanups.forEach(cleanup => cleanup()); assert.equal(change, undefined); assert.equal(motionChange, undefined); assert.equal(intersect, undefined); assert.equal(frames.size, 0); },
  };
}

test("starts with the whole textbook and maps horizontal pointer capture into clamped percentages", () => {
  const view = mount();
  assert.equal(view.render()["aria-valuenow"], 100);
  assert.equal(view.render()["aria-orientation"], "horizontal");
  view.pointer("down", 260);
  view.pointer("move", 260);
  assert.equal(view.render()["aria-valuenow"], 25);
  assert.equal(view.captured.has(1), true);
  view.pointer("move", 1510);
  assert.equal(view.render()["aria-valuenow"], 100);
  view.pointer("move", -90);
  assert.equal(view.render()["aria-valuenow"], 0);
  view.pointer("up", -90);
  assert.equal(view.captured.size, 0);
  view.pointer("move", 510);
  assert.equal(view.render()["aria-valuenow"], 0);
  view.cleanup();
});

test("touch pointer reveals notes above and rotation keeps the material ratio while ending drag", () => {
  const view = mount(false);
  view.pointer("down", 900, 145);
  view.pointer("move", 900, 145);
  assert.equal(view.render()["aria-valuenow"], 25);
  assert.equal(view.render()["aria-orientation"], "vertical");
  view.rotate();
  assert.equal(view.render()["aria-valuenow"], 75);
  assert.equal(view.render()["aria-valuetext"], "Учебник 75%, конспект 25%");
  assert.equal(view.render()["aria-orientation"], "horizontal");
  assert.equal(view.captured.size, 0);
  view.pointer("move", 900);
  assert.equal(view.render()["aria-valuenow"], 75);
  view.cleanup();
});

test("secondary pointers and mouse buttons cannot steal an active drag", () => {
  const view = mount();
  view.pointer("down", 100, 270, 2, false);
  view.pointer("down", 100, 270, 3, true, 2);
  assert.equal(view.render()["aria-valuenow"], 100);
  view.pointer("down", 260);
  view.pointer("move", 260);
  view.pointer("down", 910, 270, 4);
  view.pointer("move", 910, 270, 4);
  assert.equal(view.render()["aria-valuenow"], 25);
  view.pointer("cancel", 260);
  view.pointer("move", 910);
  assert.equal(view.render()["aria-valuenow"], 25);
  view.pointer("down", 510);
  view.pointer("move", 510);
  view.pointer("lost", 510);
  view.pointer("move", 910);
  assert.equal(view.render()["aria-valuenow"], 50);
  view.cleanup();
});

test("keyboard exposes whole materials, steps by one and leaves unrelated keys alone", () => {
  const view = mount();
  assert.equal(view.key("Tab"), false);
  assert.equal(view.key("Home"), true);
  assert.equal(view.render()["aria-valuenow"], 0);
  view.key("ArrowRight");
  assert.equal(view.render()["aria-valuenow"], 1);
  view.key("ArrowUp"); view.key("ArrowLeft");
  assert.equal(view.render()["aria-valuenow"], 0);
  view.key("End"); view.key("ArrowDown");
  assert.equal(view.render()["aria-valuenow"], 100);
  assert.equal(view.render()["aria-valuetext"], "Учебник 100%, конспект 0%");
  view.cleanup();
});

test("first visibility sweeps to full notes, returns faster to center and never restarts", () => {
  const view = mount();
  assert.equal(view.pending(), 0);
  view.enter(0.1);
  assert.equal(view.pending(), 1);
  view.enter(0.5); view.enter(0.7); view.enter(0.99);
  assert.equal(view.pending(), 1, "additional intersections cannot start overlapping sweeps");
  assert.equal(view.render()["aria-valuenow"], 100);
  view.enter(); view.tick(0); view.tick(1200);
  assert.equal(view.render()["aria-valuenow"], 50);
  view.tick(2400);
  assert.equal(view.render()["aria-valuenow"], 0);
  view.tick(2825);
  assert.equal(view.render()["aria-valuenow"], 25);
  view.tick(3250);
  assert.equal(view.render()["aria-valuenow"], 50);
  assert.equal(view.pending(), 0);
  view.enter(); view.tick(5000);
  assert.equal(view.render()["aria-valuenow"], 50);
  view.cleanup();
});

test("leaving during either sweep settles at center and returning cannot restart it", () => {
  for (const elapsed of [400, 2600]) {
    const view = mount();
    view.enter(0.1); view.tick(0);
    if (elapsed > 2400) view.tick(2400);
    view.tick(elapsed);
    view.enter(0);
    assert.equal(view.pending(), 0);
    assert.equal(view.render()["aria-valuenow"], 50);
    view.enter(); view.tick(10000);
    assert.equal(view.render()["aria-valuenow"], 50);
    view.key("End");
    assert.equal(view.render()["aria-valuenow"], 100, "manual control remains available after leaving");
    view.cleanup();
  }
});

test("manual control before visibility and during either phase cancels the intro", () => {
  for (const elapsed of [null, 1200, 2600]) {
    const view = mount();
    if (elapsed !== null) {
      view.enter(); view.tick(0);
      if (elapsed > 2400) view.tick(2400);
      view.tick(elapsed);
    }
    view.pointer("down", 510); view.pointer("move", 510); view.pointer("up", 510);
    view.enter(); view.tick(10000);
    assert.equal(view.render()["aria-valuenow"], 50);
    assert.equal(view.pending(), 0);
    view.cleanup();
  }
});

test("phone intro moves from top to bottom revealing notes, then returns to center", () => {
  const view = mount(false);
  assert.equal(view.render()["aria-valuenow"], 0);
  assert.equal(view.render()["aria-valuetext"], "Учебник 100%, конспект 0%");
  view.enter(); view.tick(0); view.tick(1200);
  assert.equal(view.render()["aria-valuenow"], 50);
  view.tick(2400);
  assert.equal(view.render()["aria-valuenow"], 100);
  assert.equal(view.render()["aria-valuetext"], "Учебник 0%, конспект 100%");
  view.tick(3250);
  assert.equal(view.render()["aria-valuenow"], 50);
  view.key("Home");
  assert.equal(view.render()["aria-valuenow"], 0);
  assert.equal(view.render()["aria-valuetext"], "Учебник 100%, конспект 0%");
  view.key("ArrowDown");
  assert.equal(view.render()["aria-valuenow"], 1);
  view.key("End");
  assert.equal(view.render()["aria-valuetext"], "Учебник 0%, конспект 100%");
  view.cleanup();
});

test("Enter, Space and assistive clicks stop movement without changing the split", () => {
  for (const key of ["Enter", " "]) {
    const view = mount();
    view.enter(); view.tick(0); view.tick(1200);
    assert.equal(view.key(key), true);
    view.tick(2400);
    assert.equal(view.render()["aria-valuenow"], 50);
    view.cleanup();
  }
  const view = mount();
  view.enter(); view.tick(0); view.tick(1200);
  view.click();
  view.tick(2400);
  assert.equal(view.render()["aria-valuenow"], 50);
  view.cleanup();
});

test("reduced motion starts at center and changing the preference stops a running intro", () => {
  const reduced = mount(false, true);
  assert.equal(reduced.render()["aria-valuenow"], 50);
  reduced.enter(); reduced.tick(5000);
  assert.equal(reduced.pending(), 0);
  reduced.cleanup();
  const view = mount();
  view.enter(); view.tick(0); view.tick(800); view.reduce(); view.tick(5000);
  assert.equal(view.render()["aria-valuenow"], 50);
  assert.equal(view.pending(), 0);
  view.cleanup();
});

test("unmount cancels an active animation frame", () => {
  const view = mount();
  view.enter(); view.tick(0);
  assert.equal(view.pending(), 1);
  view.cleanup();
});
