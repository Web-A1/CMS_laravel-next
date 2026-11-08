import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { createRequire } from "node:module";
import test from "node:test";
import ts from "typescript";

const require = createRequire(import.meta.url);
const source = ts.transpileModule(readFileSync(new URL("./SupportVoicePlayer.tsx", import.meta.url), "utf8"), {
  compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022, jsx: ts.JsxEmit.ReactJSX },
}).outputText;
type Element = { props: Record<string, unknown> };
function elements(value: unknown): Element[] {
  if (Array.isArray(value)) return value.flatMap(elements);
  if (!value || typeof value !== "object" || !("props" in value)) return [];
  const element = value as Element;
  return [element, ...elements(element.props.children)];
}
function mount(options: { seconds?: number; disabled?: boolean; play?: () => Promise<void> } = {}) {
  const states: unknown[] = [];
  const refs: { current: unknown }[] = [];
  let index = 0, refIndex = 0, playCalls = 0, pauseCalls = 0;
  let cleanup: (() => void) | undefined;
  let effect: (() => (() => void)) | undefined;
  const audio = { duration: Number.NaN, currentTime: 0, paused: true, ended: false,
    play: () => { playCalls++; return options.play?.() ?? Promise.resolve(); }, pause: () => { pauseCalls++; audio.paused = true; } };
  const exports = {} as { default: (props: Record<string, unknown>) => unknown };
  new Function("require", "exports", source)((id: string) => {
    if (id === "react") return {
      useState: (initial: unknown) => { const at = index++; if (!(at in states)) states[at] = initial; return [states[at], (next: unknown) => { states[at] = next; }]; },
      useRef: (initial: unknown) => refs[refIndex++] ?? (refs[refIndex - 1] = { current: initial }),
      useEffect: (callback: () => (() => void)) => { if (!cleanup) effect = callback; },
    };
    if (id.includes("/button/")) return { default: "button" };
    if (id.includes("/form/")) return { FormError: "error" };
    if (id.endsWith(".css")) return {};
    return require(id);
  }, exports);
  let tree: Element[] = [];
  const find = (key: string, value: unknown) => { const node = tree.find((node) => node.props[key] === value); assert.ok(node); return node; };
  function render() {
    index = 0; refIndex = 0;
    tree = elements(exports.default({ url: "blob:voice", seconds: options.seconds ?? 6, disabled: options.disabled }));
    const media = find("aria-label", "Голосовое сообщение");
    (media.props.ref as { current: unknown }).current = audio;
    if (!cleanup && effect) cleanup = effect();
  }
  const invoke = (node: Element, handler: string, event?: unknown) => (node.props[handler] as (event?: unknown) => unknown)(event);
  const event = (name: string) => invoke(find("aria-label", "Голосовое сообщение"), name, { currentTarget: audio });
  render();
  return { audio, render, find, invoke, event, unmount: () => cleanup?.(), get playCalls() { return playCalls; }, get pauseCalls() { return pauseCalls; } };
}

test("player retains real local audio, uses custom controls and finite WebM duration fallback", () => {
  const view = mount();
  const media = view.find("aria-label", "Голосовое сообщение");
  assert.equal(media.props.src, "blob:voice"); assert.equal(media.props.preload, "auto"); assert.equal(media.props.controls, undefined);
  view.audio.duration = Infinity; view.event("onLoadedMetadata"); view.render();
  assert.equal(view.find("data-ui", "support.voice.seek").props.max, 6);
  view.audio.duration = 6.27; view.event("onDurationChange"); view.render();
  assert.equal(view.find("data-ui", "support.voice.seek").props.max, 6.27);
  view.unmount();
});
test("play and pause follow native events; timeupdate and ended synchronize custom controls", async () => {
  const view = mount();
  await view.invoke(view.find("data-ui", "support.voice.play"), "onClick");
  assert.equal(view.playCalls, 1);
  view.audio.paused = false; view.event("onPlay"); view.render();
  assert.equal(view.find("data-ui", "support.voice.play").props["aria-label"], "Приостановить голосовое сообщение");
  view.audio.currentTime = 2; view.event("onTimeUpdate"); view.render();
  assert.equal(view.find("data-ui", "support.voice.seek").props.value, 2);
  assert.equal((view.find("data-ui", "support.voice.seek").props.style as Record<string, string>)["--voice-progress"], `${2 / 6 * 100}%`);
  await view.invoke(view.find("data-ui", "support.voice.play"), "onClick");
  assert.equal(view.pauseCalls, 1);
  view.event("onPause"); view.render();
  assert.equal(view.find("data-ui", "support.voice.play").props["aria-label"], "Воспроизвести голосовое сообщение");
  view.audio.currentTime = 6; view.audio.ended = true; view.event("onEnded"); view.render();
  assert.equal(view.find("data-ui", "support.voice.seek").props.value, 6);
  view.unmount();
});
test("range seeking clamps bounds and ignores nonfinite values", () => {
  const view = mount();
  const seek = (value: string) => view.invoke(view.find("data-ui", "support.voice.seek"), "onChange", { currentTarget: { value } });
  seek("3.5"); assert.equal(view.audio.currentTime, 3.5);
  seek("100"); assert.equal(view.audio.currentTime, 6);
  seek("-1"); assert.equal(view.audio.currentTime, 0);
  seek("NaN"); assert.equal(view.audio.currentTime, 0);
  view.unmount();
});
test("play rejection and media errors are exposed without leaving a playing state", async () => {
  const view = mount({ play: async () => { throw new Error("denied"); } });
  await view.invoke(view.find("data-ui", "support.voice.play"), "onClick"); view.render();
  assert.match(String(view.find("id", "support-voice-playback-error").props.children), /Не удалось воспроизвести/);
  assert.equal(view.find("data-ui", "support.voice.play").props.disabled, false);
  view.event("onError"); view.render();
  assert.match(String(view.find("id", "support-voice-playback-error").props.children), /Не удалось прочитать/);
  view.unmount();
});
test("pending disables controls, invalid duration remains finite, cleanup pauses audio", async () => {
  const view = mount({ seconds: Infinity, disabled: true });
  assert.equal(view.find("data-ui", "support.voice.seek").props.max, 1);
  assert.equal(view.find("data-ui", "support.voice.seek").props.disabled, true);
  assert.equal(view.find("data-ui", "support.voice.play").props.disabled, true);
  await view.invoke(view.find("data-ui", "support.voice.play"), "onClick");
  assert.equal(view.playCalls, 0);
  view.unmount(); assert.equal(view.pauseCalls, 1);
});
test("unmount during pending play ignores late rejection and pauses the recording", async () => {
  let reject: (error: Error) => void = () => {};
  const view = mount({ play: () => new Promise((_, fail) => { reject = fail; }) });
  const pending = view.invoke(view.find("data-ui", "support.voice.play"), "onClick");
  view.unmount(); reject(new Error("late")); await pending;
  assert.equal(view.pauseCalls, 1);
});
