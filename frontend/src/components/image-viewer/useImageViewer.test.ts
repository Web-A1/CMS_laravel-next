import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import test from "node:test";
import type { PointerEvent } from "react";
import ts from "typescript";
import type { ImageViewerSwipe, useImageViewer } from "./useImageViewer";

const compile = (file: string) => ts.transpileModule(readFileSync(new URL(file, import.meta.url), "utf8"), {
  compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
}).outputText;

function mount(withSwipe = true) {
  const state: unknown[] = [], refs: { current: unknown }[] = [];
  let stateIndex = 0, refIndex = 0;
  const swipes: ImageViewerSwipe[] = [];
  const react = {
    useState(initial: unknown) {
      const index = stateIndex++;
      if (!(index in state)) state[index] = initial;
      return [state[index], (value: unknown) => { state[index] = typeof value === "function" ? value(state[index]) : value; }];
    },
    useRef(initial: unknown) {
      const index = refIndex++;
      return refs[index] ??= { current: initial };
    },
    useCallback<T>(callback: T) { return callback; },
  };
  const utils = {};
  new Function("exports", compile("./imageViewerUtils.ts"))(utils);
  const hook = {} as { useImageViewer: typeof useImageViewer };
  new Function("require", "exports", "window", compile("./useImageViewer.ts"))(
    (id: string) => id === "react" ? react : utils, hook, { innerWidth: 400, innerHeight: 750 },
  );
  function render() {
    stateIndex = 0; refIndex = 0;
    return hook.useImageViewer(withSwipe ? direction => swipes.push(direction) : undefined);
  }
  let current = render();
  function pointer(type: string, x: number, y = 300, id = 1, pointerType = "touch") {
    const event = { type, clientX: x, clientY: y, pointerId: id, pointerType, currentTarget: { setPointerCapture() {} } } as unknown as PointerEvent<HTMLDivElement>;
    if (type === "pointerdown") current.startImageViewerGesture(event);
    else if (type === "pointermove") current.moveImageViewerGesture(event);
    else current.finishImageViewerGesture(event);
    current = render();
  }
  return {
    swipes, pointer,
    get zoom() { return current.viewerZoom; },
    get offset() { return current.viewerOffset; },
    zoomTo(value: number) { current.updateImageViewerZoom(value); current = render(); },
  };
}

test("touch swipe changes direction once per completed gesture", () => {
  const view = mount();
  view.pointer("pointerdown", 300);
  view.pointer("pointermove", 120);
  assert.deepEqual(view.swipes, []);
  view.pointer("pointerup", 100);
  view.pointer("lostpointercapture", 100);
  view.pointer("pointerdown", 100);
  view.pointer("pointerup", 300);
  assert.deepEqual(view.swipes, ["next", "previous"]);
});

test("tap, short horizontal movement and vertical or diagonal gestures do not turn pages", () => {
  const view = mount();
  for (const [x, y] of [[200, 300], [153, 300], [180, 450], [140, 340]]) {
    view.pointer("pointerdown", 200);
    view.pointer("pointerup", x, y);
  }
  assert.deepEqual(view.swipes, []);
  view.pointer("pointerdown", 200);
  view.pointer("pointerup", 152);
  assert.deepEqual(view.swipes, ["next"]);
});

test("cancel and lost capture discard the candidate before a later up event", () => {
  for (const cancellation of ["pointercancel", "lostpointercapture"]) {
    const view = mount();
    view.pointer("pointerdown", 300);
    view.pointer("pointermove", 100);
    view.pointer(cancellation, 100);
    view.pointer("pointerup", 100);
    assert.deepEqual(view.swipes, []);
  }
});

test("mouse and pen movement do not turn pages", () => {
  for (const pointerType of ["mouse", "pen"]) {
    const view = mount();
    view.pointer("pointerdown", 300, 300, 1, pointerType);
    view.pointer("pointerup", 100, 300, 1, pointerType);
    assert.deepEqual(view.swipes, []);
  }
});

test("pinch can zoom in and back out without turning a page on remaining finger release", () => {
  const view = mount();
  view.pointer("pointerdown", 100);
  view.pointer("pointerdown", 200, 300, 2);
  view.pointer("pointermove", 300, 300, 2);
  assert.equal(view.zoom, 2);
  view.pointer("pointermove", 200, 300, 2);
  assert.equal(view.zoom, 1);
  view.pointer("pointerup", 200, 300, 2);
  view.pointer("pointerup", 300);
  assert.deepEqual(view.swipes, []);
  view.pointer("pointerdown", 300);
  view.pointer("pointerup", 100);
  assert.deepEqual(view.swipes, ["next"]);
});

test("zoomed touch and mouse drag pan the document without turning a page", () => {
  for (const pointerType of ["touch", "mouse"]) {
    const view = mount();
    view.zoomTo(2);
    view.pointer("pointerdown", 300, 300, 1, pointerType);
    view.pointer("pointermove", 100, 350, 1, pointerType);
    view.pointer("pointerup", 100, 350, 1, pointerType);
    assert.deepEqual(view.offset, { x: -200, y: 50 });
    assert.deepEqual(view.swipes, []);
  }
});

test("zoom change cancels a pending swipe even when reset to fitted scale", () => {
  const view = mount();
  view.pointer("pointerdown", 300);
  view.zoomTo(2);
  view.zoomTo(1);
  view.pointer("pointerup", 100);
  assert.deepEqual(view.swipes, []);
});

test("viewers without pagination retain zoom and pan", () => {
  const view = mount(false);
  view.pointer("pointerdown", 300);
  view.pointer("pointerup", 100);
  view.zoomTo(2);
  view.pointer("pointerdown", 200);
  view.pointer("pointermove", 150, 330);
  view.pointer("pointerup", 150, 330);
  assert.equal(view.zoom, 2);
  assert.deepEqual(view.offset, { x: -50, y: 30 });
  assert.deepEqual(view.swipes, []);
});
