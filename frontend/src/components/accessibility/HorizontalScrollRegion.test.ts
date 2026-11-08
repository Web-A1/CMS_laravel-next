import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import test from "node:test";
import ts from "typescript";

function compile(path: string): string {
  return ts.transpileModule(readFileSync(new URL(path, import.meta.url), "utf8"), {
    compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022, jsx: ts.JsxEmit.React, jsxFactory: "jsx" },
  }).outputText;
}

function setup(onKeyDown?: (event: KeyEvent) => void) {
  const policy = {};
  new Function("exports", compile("./horizontalScrollPolicy.ts"))(policy);
  let reduced = true;
  const queries: string[] = [];
  const scrolls: ScrollToOptions[] = [];
  const track = { clientWidth: 400, scrollWidth: 1200, scrollLeft: 0, scrollTo(options: ScrollToOptions) { scrolls.push(options); this.scrollLeft = options.left ?? 0; } };
  const exports: { default?: (props: { onKeyDown?: typeof onKeyDown }) => { onKeyDown: (event: KeyEvent) => void } } = {};
  new Function("require", "exports", "window", "jsx", compile("./HorizontalScrollRegion.tsx"))(
    () => policy, exports,
    { matchMedia: (query: string) => { queries.push(query); return { matches: reduced }; } },
    (_type: string, props: object) => props,
  );
  const component = exports.default?.({ onKeyDown });
  assert.ok(component);
  return {
    scrolls, queries, track,
    motion(value: boolean) { reduced = value; },
    key(key: string, child = false) {
      const event: KeyEvent = { key, target: child ? {} : track, currentTarget: track, defaultPrevented: false, preventDefault() { this.defaultPrevented = true; } };
      component.onKeyDown(event);
      return event;
    },
  };
}

type KeyEvent = { key: string; target: object; currentTarget: object; defaultPrevented: boolean; preventDefault(): void };

test("keyboard scrolling reads reduced motion on every key and switches instant/smooth", () => {
  const state = setup();
  assert.equal(state.key("ArrowRight").defaultPrevented, true);
  state.motion(false);
  state.key("ArrowRight");
  assert.deepEqual(state.scrolls, [{ left: 320, behavior: "instant" }, { left: 640, behavior: "smooth" }]);
  assert.deepEqual(state.queries, ["(prefers-reduced-motion: reduce)", "(prefers-reduced-motion: reduce)"]);
});

test("keyboard handler clamps boundaries and supports Home/End", () => {
  const state = setup();
  for (const key of ["ArrowLeft", "End", "ArrowRight", "Home"]) state.key(key);
  assert.deepEqual(state.scrolls.map(({ left }) => left), [0, 800, 800, 0]);
});

test("unknown keys, child targets and consumer preventDefault leave scrolling alone", () => {
  const state = setup();
  assert.equal(state.key("Enter").defaultPrevented, false);
  assert.equal(state.key("ArrowRight", true).defaultPrevented, false);
  assert.deepEqual(state.scrolls, []);
  assert.deepEqual(state.queries, []);
  let calls = 0;
  const prevented = setup((event) => { calls++; event.preventDefault(); });
  assert.equal(prevented.key("ArrowRight").defaultPrevented, true);
  assert.equal(calls, 1);
  assert.deepEqual(prevented.scrolls, []);
  assert.deepEqual(prevented.queries, []);
});
