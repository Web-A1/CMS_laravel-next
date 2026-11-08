import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { createRequire } from "node:module";
import test from "node:test";
import ts from "typescript";

const require = createRequire(import.meta.url);
const compiled = ts.transpileModule(readFileSync(new URL("./ImageViewer.tsx", import.meta.url), "utf8"), {
  compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022, jsx: ts.JsxEmit.ReactJSX },
}).outputText;

function mount(alreadyLocked = false) {
  const locks = new Set(alreadyLocked ? ["menu-scroll-lock"] : []);
  const handlers = new Map<string, (event: KeyEvent) => void>();
  let effect: (() => (() => void) | undefined) | undefined;
  let closed = 0;
  class Element {
    isConnected = true;
    focus(options?: FocusOptions) { document.activeElement = this; this.lastFocusOptions = options; }
    lastFocusOptions?: FocusOptions;
  }
  const trigger = new Element(), close = new Element(), stage = new Element();
  const document = {
    activeElement: trigger,
    body: { classList: { contains: (name: string) => locks.has(name), add: (name: string) => locks.add(name), remove: (name: string) => locks.delete(name) } },
    addEventListener: (name: string, handler: (event: KeyEvent) => void) => handlers.set(name, handler),
    removeEventListener: (name: string) => handlers.delete(name),
  };
  const dialog = {
    open: false,
    querySelectorAll() { return [close]; },
    showModal() { this.open = true; document.activeElement = close; },
    close() { this.open = false; },
  };
  const refs = [dialog, close, stage];
  let refIndex = 0;
  const exports = {} as typeof import("./ImageViewer");
  new Function("require", "exports", "document", "HTMLElement", compiled)((id: string) => {
    if (id === "react") return { useRef: () => ({ current: refs[refIndex++] }), useLayoutEffect: (callback: typeof effect) => { effect = callback; } };
    if (id === "./useImageViewer") return { useImageViewer: () => ({ viewerZoom: 1, viewerOffset: { x: 0, y: 0 }, updateImageViewerZoom: () => {} }) };
    if (id === "./imageViewerUtils") return { IMAGE_VIEWER_MIN_ZOOM: 1, IMAGE_VIEWER_ZOOM_STEP: 0.25 };
    if (id.endsWith(".css")) return {};
    if (id.includes("CloseButton")) return { default: () => null };
    return require(id);
  }, exports, document, Element);
  exports.default({ label: "Просмотр", onClose: () => { closed++; }, children: () => null });
  const cleanup = effect?.();
  return {
    document, dialog, trigger, stage, close, locks,
    get closed() { return closed; },
    key(key: string) {
      const event: KeyEvent = { key, prevented: false, stopped: false, preventDefault() { this.prevented = true; }, stopPropagation() { this.stopped = true; } };
      handlers.get("keydown")?.(event);
      return event;
    },
    cleanup() { cleanup?.(); assert.equal(handlers.size, 0); },
  };
}

type KeyEvent = {
  key: string; prevented: boolean; stopped: boolean;
  preventDefault(): void; stopPropagation(): void;
};

test("starts on the document instead of leaving native dialog autofocus on close", () => {
  const view = mount();
  assert.equal(view.dialog.open, true);
  assert.equal(view.document.activeElement, view.stage);
  assert.deepEqual(view.stage.lastFocusOptions, { preventScroll: true });
  assert.equal(view.locks.has("menu-scroll-lock"), true);
  view.cleanup();
});

test("Tab exposes the close control, Escape closes and cleanup restores the trigger", () => {
  const view = mount();
  const tab = view.key("Tab");
  assert.equal(tab.prevented, true);
  assert.equal(tab.stopped, true);
  assert.equal(view.document.activeElement, view.close);
  assert.equal(view.key("Escape").prevented, true);
  assert.equal(view.closed, 1);
  view.cleanup();
  assert.equal(view.dialog.open, false);
  assert.equal(view.document.activeElement, view.trigger);
  assert.deepEqual(view.trigger.lastFocusOptions, { preventScroll: true });
  assert.equal(view.locks.has("menu-scroll-lock"), false);
});

test("closing a nested viewer preserves the existing scroll lock", () => {
  const view = mount(true);
  view.cleanup();
  assert.equal(view.locks.has("menu-scroll-lock"), true);
});
