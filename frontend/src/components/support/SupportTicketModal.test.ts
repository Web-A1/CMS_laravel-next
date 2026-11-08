import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { createRequire } from "node:module";
import test from "node:test";
import ts from "typescript";

const require = createRequire(import.meta.url);
const compile = (file: string) => ts.transpileModule(readFileSync(new URL(file, import.meta.url), "utf8"), {
  compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022, jsx: ts.JsxEmit.ReactJSX },
}).outputText;
const compiled = compile("./SupportTicketModal.tsx");
const helpers = { exports: {} as typeof import("./supportTicket") };
new Function("module", "exports", compile("./supportTicket.ts"))(helpers, helpers.exports);
const voiceHelpers = { exports: {} as typeof import("./supportVoice") };
new Function("module", "exports", compile("./supportVoice.ts"))(voiceHelpers, voiceHelpers.exports);

type Element = { props: Record<string, unknown> };
function elements(value: unknown): Element[] {
  if (Array.isArray(value)) return value.flatMap(elements);
  if (!value || typeof value !== "object" || !("props" in value)) return [];
  const element = value as Element;
  return [element, ...elements(element.props.children)];
}

function mount(response: import("./supportTicket").SupportResult | Promise<import("./supportTicket").SupportResult> = { ok: false, message: "Проверьте вложение", fields: { "images.0": ["Файл не принят"] } }, clipboard?: Pick<Clipboard, "read">, voiceFactory = voiceHelpers.exports.createSupportVoice) {
  const states: unknown[] = [];
  const refs: { current: unknown }[] = [];
  let stateIndex = 0, refIndex = 0, requests = 0;
  let sentDraft: import("./supportTicket").SupportDraft | undefined;
  const exports = {} as { default: (props: Record<string, never>) => unknown };
  new Function("require", "exports", "requestAnimationFrame", "navigator", compiled)((id: string) => {
    if (id === "react") return {
      useEffect: () => {},
      useState: (initial: unknown) => {
        const index = stateIndex++;
        if (!(index in states)) states[index] = initial;
        return [states[index], (next: unknown) => { states[index] = next; }];
      },
      useRef: (initial: unknown) => refs[refIndex++] ?? (refs[refIndex - 1] = { current: initial }),
    };
    if (id.includes("/button/") || id.includes("/form/")) return { default: "button", FormError: "error", FormStatus: "status" };
    if (id.includes("apiBaseUrl")) return { browserApiBaseUrl: () => "/backend" };
    if (id === "./supportTicket") return { ...helpers.exports, submitSupportTicket: async (_base: string, draft: import("./supportTicket").SupportDraft) => { requests++; sentDraft = draft; return response; } };
    if (id === "./supportVoice") return { ...voiceHelpers.exports, createSupportVoice: voiceFactory };
    if (id === "./SupportVoicePlayer") return { default: "voice-player" };
    return require(id);
  }, exports, (callback: () => void) => callback(), { clipboard });
  let tree: Element[] = [];
  function render() { stateIndex = 0; refIndex = 0; tree = elements(exports.default({})); }
  const find = (key: string, value: unknown) => {
    const element = tree.find((node) => node.props[key] === value);
    assert.ok(element, `${key}=${String(value)} exists`);
    return element;
  };
  const invoke = (node: Element, key: string, event?: unknown) => (node.props[key] as (event?: unknown) => unknown)(event);
  render();
  invoke(find("data-ui", "home.faq.supportButton"), "onClick");
  render();
  return { render, find, invoke, close: () => { const dialog = tree.find((node) => typeof node.props.onClose === "function"); assert.ok(dialog); invoke(dialog, "onClose"); render(); }, get requests() { return requests; }, get sentDraft() { return sentDraft; }, has: (key: string, value: unknown) => tree.some((node) => node.props[key] === value) };
}

const clipboardImage: ClipboardItem = { types: ["image/png", "text/html"], presentationStyle: "unspecified", getType: async () => new Blob(["image"], { type: "image/png" }) };

test("voice phases block submit, stop never sends, ready audio survives failed retry and can be deleted", async () => {
  let state: import("./supportVoice").VoiceState = voiceHelpers.exports.EMPTY_VOICE;
  let notify: (state: import("./supportVoice").VoiceState) => void = () => {};
  const factory: typeof voiceHelpers.exports.createSupportVoice = (onChange) => {
    notify = (next) => { state = next; onChange(next); };
    return { start: async () => notify({ phase: "requesting", seconds: 0 }), stop: () => notify({ phase: "stopping", seconds: 1 }), clear: () => notify(voiceHelpers.exports.EMPTY_VOICE), getState: () => state };
  };
  const view = mount({ ok: false, message: "Попробуйте снова", fields: { voice: ["Ошибка записи"] } }, undefined, factory);
  view.invoke(view.find("data-ui", "support.email"), "onChange", { target: { value: "voice@example.test" } });
  await view.invoke(view.find("data-ui", "support.voice.record"), "onClick");
  for (const phase of ["requesting", "recording", "stopping"] as const) {
    notify({ phase, seconds: 1 }); view.render();
    const insideTextarea = elements(view.find("data-ui", "support.description.wrapper"));
    const belowTextarea = elements(view.find("data-ui", "support.voice"));
    for (const control of ["support.voice.controls", "support.voice.timer", "support.voice.activity", "support.voice.cancel"]) {
      assert.ok(insideTextarea.some((node) => node.props["data-ui"] === control), `${control} stays inside textarea wrapper`);
      assert.equal(belowTextarea.some((node) => node.props["data-ui"] === control), false);
    }
    const capsule = elements(view.find("data-ui", "support.voice.pill"));
    const order = capsule.map((node) => node.props["data-ui"]).filter(Boolean);
    assert.deepEqual(order, ["support.voice.pill", "support.voice.timer", "support.voice.activity", phase === "recording" ? "support.voice.stop" : "support.voice.record"]);
    assert.equal(view.find("data-ui", "support.submit").props.disabled, true);
    await view.invoke(view.find("data-ui", "support.form"), "onSubmit", { preventDefault() {} });
    assert.equal(view.requests, 0);
  }
  notify({ phase: "recording", seconds: 2 }); view.render();
  assert.equal(view.find("data-ui", "support.voice.stop").props["aria-label"], "Остановить запись");
  assert.ok(elements(view.find("data-ui", "support.voice.activity")).some((node) => String(node.props.className).includes("motion-safe:animate-pulse")));
  view.invoke(view.find("data-ui", "support.voice.stop"), "onClick");
  assert.equal(view.requests, 0);
  const file = new File(["recorded"], "voice.webm", { type: "audio/webm" });
  notify({ phase: "ready", seconds: 2, file, url: "blob:voice" }); view.render();
  assert.equal(view.find("data-ui", "support.description").props.required, false);
  await view.invoke(view.find("data-ui", "support.form"), "onSubmit", { preventDefault() {} }); view.render();
  assert.equal(view.sentDraft?.voice, file);
  assert.ok(view.has("data-ui", "support.voice.preview"));
  const player = elements(view.find("data-ui", "support.voice.preview")).find((node) => node.props.url === "blob:voice");
  assert.ok(player);
  assert.equal(player.props.seconds, 2);
  assert.equal(player.props.disabled, false);
  assert.equal(view.find("dataUi", "support.voice.error").props.children, "Ошибка записи");
  await view.invoke(view.find("data-ui", "support.form"), "onSubmit", { preventDefault() {} }); view.render();
  assert.equal(view.requests, 2); assert.equal(view.sentDraft?.voice, file);
  view.invoke(view.find("data-ui", "support.voice.remove"), "onClick"); view.render();
  assert.equal(view.has("data-ui", "support.voice.preview"), false);
});

test("drop zone accepts files through shared validation and highlights file drags only", () => {
  const view = mount();
  let prevented = 0;
  const dataTransfer = { types: ["Files"], dropEffect: "none", files: [new File(["image"], "drop.png", { type: "image/png" })] };
  view.invoke(view.find("data-ui", "support.dropzone"), "onDragOver", { dataTransfer, preventDefault() { prevented++; } });
  view.render();
  assert.equal(view.find("data-ui", "support.dropzone").props["data-state"], "dragging");
  assert.equal(dataTransfer.dropEffect, "copy");
  view.invoke(view.find("data-ui", "support.dropzone"), "onDrop", { dataTransfer, preventDefault() { prevented++; } });
  view.render();
  assert.equal(prevented, 2);
  assert.ok(view.has("aria-label", "Удалить drop.png"));
  assert.equal(view.find("data-ui", "support.dropzone").props["data-state"], "idle");
});

test("clipboard button adds an image without overwriting files added while reading", async () => {
  let resolve: (items: ClipboardItem[]) => void = () => {};
  const view = mount(undefined, { read: () => new Promise((done) => { resolve = done; }) });
  const reading = view.invoke(view.find("data-ui", "support.paste"), "onClick");
  view.render();
  assert.equal(view.find("data-ui", "support.submit").props.disabled, true);
  paste(view, [new File(["other"], "other.png", { type: "image/png" })]);
  resolve([clipboardImage]);
  await reading;
  view.render();
  assert.ok(view.has("aria-label", "Удалить other.png"));
  assert.ok(view.has("aria-label", "Удалить screenshot-1.png"));
  assert.equal(view.find("data-ui", "support.submit").props.disabled, false);
});

test("clipboard button reports empty, unavailable and denied clipboard access", async () => {
  for (const [clipboard, expected] of [
    [{ read: async () => [] }, /нет изображения/],
    [undefined, /Ctrl\+V/],
    [{ read: async () => { throw new Error("denied"); } }, /Ctrl\+V/],
  ] as const) {
    const view = mount(undefined, clipboard);
    await view.invoke(view.find("data-ui", "support.paste"), "onClick");
    view.render();
    assert.match(String(view.find("id", "support-images-error").props.children), expected);
    assert.equal(view.has("data-ui", "support.previews"), false);
    assert.equal(view.find("data-ui", "support.paste").props.loading, false);
  }
});

test("closing cancels an outstanding clipboard read", async () => {
  let resolve: (items: ClipboardItem[]) => void = () => {};
  const view = mount(undefined, { read: () => new Promise((done) => { resolve = done; }) });
  const reading = view.invoke(view.find("data-ui", "support.paste"), "onClick");
  view.close();
  resolve([clipboardImage]);
  await reading;
  view.invoke(view.find("data-ui", "home.faq.supportButton"), "onClick");
  view.render();
  assert.equal(view.has("data-ui", "support.previews"), false);
  assert.equal(view.find("data-ui", "support.paste").props.loading, false);
});

function paste(view: ReturnType<typeof mount>, files: File[]) {
  let prevented = false;
  view.invoke(view.find("data-ui", "support.description"), "onPaste", {
    clipboardData: { files }, preventDefault() { prevented = true; },
  });
  view.render();
  return prevented;
}

test("pasted screenshot joins selected files and reaches the existing submission", async () => {
  const view = mount();
  const selected = new File(["selected"], "selected.png", { type: "image/png" });
  const screenshot = new File(["clipboard"], "clipboard.png", { type: "image/png" });
  view.invoke(view.find("data-ui", "support.images"), "onChange", { target: { files: [selected], value: "" } });
  view.invoke(view.find("data-ui", "support.email"), "onChange", { target: { value: "guest@example.test" } });
  view.invoke(view.find("data-ui", "support.description"), "onChange", { target: { value: "Текст обращения" } });
  view.render();
  assert.equal(paste(view, [screenshot]), true);
  assert.equal(view.find("data-ui", "support.description").props.value, "Текст обращения");
  assert.ok(view.has("aria-label", "Удалить clipboard.png"));
  await view.invoke(view.find("data-ui", "support.form"), "onSubmit", { preventDefault() {} });
  assert.deepEqual(view.sentDraft?.images, [selected, screenshot]);
  view.render();
  view.invoke(view.find("aria-label", "Удалить clipboard.png"), "onClick");
  view.render();
  assert.equal(view.has("aria-label", "Удалить clipboard.png"), false);
  assert.ok(view.has("aria-label", "Удалить selected.png"));
});

test("text and non-image pastes keep the browser default behavior", () => {
  const view = mount();
  assert.equal(paste(view, []), false);
  assert.equal(paste(view, [new File(["text"], "note.txt", { type: "text/plain" })]), false);
  assert.equal(view.has("data-ui", "support.previews"), false);
});

test("pasted images use the existing format, size and combined count limits", () => {
  const view = mount();
  const invalid = new File(["gif"], "unsupported.gif", { type: "image/gif" });
  const large = new File([new Uint8Array(helpers.exports.SUPPORT_MAX_IMAGE_BYTES + 1)], "large.png", { type: "image/png" });
  assert.equal(paste(view, [invalid, large]), true);
  assert.equal(view.has("data-ui", "support.previews"), false);
  assert.match(String(view.find("id", "support-images-error").props.children), /unsupported.gif.*large.png/);
  const files = Array.from({ length: 6 }, (_, i) => new File(["image"], `${i}.png`, { type: "image/png" }));
  view.invoke(view.find("data-ui", "support.images"), "onChange", { target: { files: files.slice(0, 4), value: "" } });
  view.render();
  paste(view, files.slice(4));
  assert.ok(view.has("aria-label", "Удалить 4.png"));
  assert.equal(view.has("aria-label", "Удалить 5.png"), false);
  assert.match(String(view.find("id", "support-images-error").props.children), /не более пяти/);
});

test("attachment button opens the hidden chooser; selected image can be removed", () => {
  const view = mount();
  const input = view.find("data-ui", "support.images");
  let opened = 0;
  (input.props.ref as { current: unknown }).current = { click() { opened++; } };
  view.invoke(view.find("data-ui", "support.attach"), "onClick");
  assert.equal(opened, 1);
  assert.equal(input.props.hidden, true);
  const target = { files: [new File(["fixture"], "screen.png", { type: "image/png" })], value: "screen.png" };
  view.invoke(input, "onChange", { target });
  assert.equal(target.value, "");
  view.render();
  assert.ok(view.has("data-ui", "support.previews"));
  view.invoke(view.find("aria-label", "Удалить screen.png"), "onClick");
  view.render();
  assert.equal(view.has("data-ui", "support.previews"), false);
});

test("attachment errors are exposed on the visible button; invalid drafts do not send", async () => {
  const view = mount();
  await view.invoke(view.find("data-ui", "support.form"), "onSubmit", { preventDefault() {} });
  view.render();
  assert.equal(view.requests, 0);
  assert.equal(view.find("data-ui", "support.email").props["aria-invalid"], true);
  view.invoke(view.find("data-ui", "support.email"), "onChange", { target: { value: "guest@example.test" } });
  view.invoke(view.find("data-ui", "support.description"), "onChange", { target: { value: "Не открывается урок" } });
  view.render();
  await view.invoke(view.find("data-ui", "support.form"), "onSubmit", { preventDefault() {} });
  view.render();
  assert.equal(view.requests, 1);
  assert.equal(view.find("data-ui", "support.images").props["aria-invalid"], true);
  assert.match(String(view.find("data-ui", "support.attach").props["aria-describedby"]), /support-images-error/);
  assert.equal(view.find("id", "support-images-error").props.children, "Файл не принят");
  assert.equal(view.find("data-ui", "support.description").props.value, "Не открывается урок");
});

test("pending blocks repeated sends and the accepted response opens success", async () => {
  let accept: (value: import("./supportTicket").SupportResult) => void = () => {};
  const response = new Promise<import("./supportTicket").SupportResult>((resolve) => { accept = resolve; });
  const view = mount(response);
  view.invoke(view.find("data-ui", "support.email"), "onChange", { target: { value: "guest@example.test" } });
  view.invoke(view.find("data-ui", "support.description"), "onChange", { target: { value: "Не открывается урок" } });
  view.render();
  const submitting = view.invoke(view.find("data-ui", "support.form"), "onSubmit", { preventDefault() {} });
  view.render();
  assert.equal(view.find("data-ui", "support.form").props["aria-busy"], true);
  assert.equal(view.find("data-ui", "support.attach").props.disabled, true);
  assert.equal(view.find("data-ui", "support.submit").props.loading, true);
  paste(view, [new File(["image"], "pending.png", { type: "image/png" })]);
  assert.equal(view.has("data-ui", "support.previews"), false);
  await view.invoke(view.find("data-ui", "support.form"), "onSubmit", { preventDefault() {} });
  assert.equal(view.requests, 1);
  accept({ ok: true, number: "SUP-TEST", warnings: [] });
  await submitting;
  view.render();
  assert.equal(view.has("data-ui", "support.success"), true);
  assert.equal(view.has("data-ui", "support.form"), false);
});
