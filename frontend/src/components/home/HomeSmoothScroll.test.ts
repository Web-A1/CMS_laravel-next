import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import test from "node:test";
import ts from "typescript";

const source = readFileSync(new URL("./HomeSmoothScroll.tsx", import.meta.url), "utf8");
const output = ts.transpileModule(source, {
  compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022, esModuleInterop: true },
}).outputText;

function setup({ fine = true, reduce = false, locked = false } = {}) {
  function media(matches: boolean) {
    const listeners = new Set<() => void>();
    return {
      matches,
      listeners,
      addEventListener: (_: string, fn: () => void) => listeners.add(fn),
      removeEventListener: (_: string, fn: () => void) => listeners.delete(fn),
      change(value: boolean) { this.matches = value; listeners.forEach((fn) => fn()); },
    };
  }
  const pointer = media(fine);
  const motion = media(reduce);
  const instances: FakeLenis[] = [];
  class FakeLenis {
    stopped = false;
    destroyed = false;
    constructor() { instances.push(this); }
    start() { this.stopped = false; }
    stop() { this.stopped = true; }
    destroy() { this.destroyed = true; }
  }
  let onMutation = () => {};
  let disconnected = false;
  class Observer {
    constructor(callback: () => void) { onMutation = callback; }
    observe() {}
    disconnect() { disconnected = true; }
  }
  let cleanup = () => {};
  const exports: { default?: () => null } = {};
  const requireMock = (id: string) => {
    if (id === "react") return { useEffect: (effect: () => () => void) => { cleanup = effect(); } };
    if (id === "lenis") return FakeLenis;
    if (id === "lenis/dist/lenis.css") return {};
    throw new Error(`Unexpected import: ${id}`);
  };
  new Function("require", "exports", "window", "document", "MutationObserver", output)(
    requireMock, exports,
    { matchMedia: (query: string) => query.includes("prefers-reduced-motion") ? motion : pointer },
    { body: { classList: { contains: () => locked } } }, Observer,
  );
  exports.default?.();
  return {
    instances, pointer, motion,
    lock(value: boolean) { locked = value; onMutation(); },
    cleanup() { cleanup(); return disconnected; },
  };
}

test("touch and reduced motion keep native scrolling; preference changes destroy and recreate once", () => {
  for (const options of [{ fine: false }, { reduce: true }]) {
    const state = setup(options);
    assert.equal(state.instances.length, 0);
    state.cleanup();
  }
  const state = setup();
  state.motion.change(true);
  assert.equal(state.instances[0].destroyed, true);
  state.motion.change(false);
  state.motion.change(false);
  assert.equal(state.instances.length, 2);
  state.pointer.change(false);
  assert.equal(state.instances[1].destroyed, true);
  state.cleanup();
});

test("an existing or newly opened body lock stops smoothing and closing resumes it", () => {
  const state = setup({ locked: true });
  assert.equal(state.instances[0].stopped, true);
  state.lock(false);
  assert.equal(state.instances[0].stopped, false);
  state.lock(true);
  assert.equal(state.instances[0].stopped, true);
  state.cleanup();
});

test("unmount disconnects observers, removes preference listeners and destroys Lenis", () => {
  const state = setup();
  assert.equal(state.cleanup(), true);
  assert.equal(state.instances[0].destroyed, true);
  assert.equal(state.pointer.listeners.size, 0);
  assert.equal(state.motion.listeners.size, 0);
  state.motion.change(false);
  assert.equal(state.instances.length, 1);
});
