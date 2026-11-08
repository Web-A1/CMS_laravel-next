import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { createRequire } from "node:module";
import test from "node:test";
import type { ReactElement } from "react";
import ts from "typescript";

const localRequire = createRequire(import.meta.url);
const compile = (file: string) => ts.transpileModule(readFileSync(new URL(file, import.meta.url), "utf8"), {
  compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022, jsx: ts.JsxEmit.ReactJSX },
}).outputText;

type VideoProps = { children: ReactElement<{ src: string }>[] | null; preload: string; onLoadedData: () => void };
type ToggleProps = { "aria-label": string; onClick: () => void; size: string; iconOnly: boolean };
type MediaTree = ReactElement<{ "data-state": string; "aria-hidden"?: string; children: [ReactElement<VideoProps>, ReactElement<ToggleProps> | null] }>;

for (const component of ["DeferredPromoVideo", "HomeHeroVideo"] as const) {
  test(`${component}: real control preserves manual pause through visibility, viewport and motion changes`, () => {
    const promo = component === "DeferredPromoVideo";
    let variant = "unknown", motion = "unknown", stateIndex = 0, effectIndex = 0;
    const state: unknown[] = [];
    const setters: ((value: unknown) => void)[] = [];
    const effects: { deps: unknown[]; cleanup?: () => void }[] = [];
    let pending: (() => void)[] = [];
    const listeners = new Set<() => void>();
    const doc = { hidden: false, addEventListener(_name: string, fn: () => void) { listeners.add(fn); }, removeEventListener(_name: string, fn: () => void) { listeners.delete(fn); } };
    const video = { plays: 0, pauses: 0, play() { this.plays++; return Promise.resolve(); }, pause() { this.pauses++; } };
    const ref = { current: video };
    const react = {
      useRef() { return ref; },
      useState(initial: unknown) {
        const index = stateIndex++;
        if (!(index in state)) state[index] = initial;
        setters[index] ??= (value: unknown) => { state[index] = typeof value === "function" ? value(state[index]) : value; };
        return [state[index], setters[index]];
      },
      useEffect(fn: () => (() => void) | undefined, deps: unknown[]) {
        const index = effectIndex++, previous = effects[index];
        if (previous && deps.every((value, i) => Object.is(value, previous.deps[i]))) return;
        pending.push(() => { previous?.cleanup?.(); effects[index] = { deps, cleanup: fn() }; });
      },
    };
    const observers: Observer[] = [];
    class Observer {
      active = true;
      readonly callback: (entries: { isIntersecting: boolean }[]) => void;
      constructor(callback: (entries: { isIntersecting: boolean }[]) => void) { this.callback = callback; observers.push(this); }
      observe(target: unknown) { assert.equal(target, video); }
      disconnect() { this.active = false; }
    }
    const playback = {} as typeof import("./useVisibleVideoPlayback");
    new Function("require", "exports", "document", "IntersectionObserver", compile("./useVisibleVideoPlayback.ts"))(() => react, playback, doc, Observer);
    const exports = {} as { default: (props: { className: string; ariaLabel: string; sources: { src: string; type: string }[] }) => MediaTree };
    new Function("require", "exports", "IntersectionObserver", compile(`./${component}.tsx`))((id: string) => {
      if (id === "react") return react;
      if (id === "@/components/button/Button") return { default: "button" };
      if (id === "./useMediaQueryState") return { useMediaQueryState: (query: string) => query.includes("480") ? variant : motion };
      if (id === "./useVisibleVideoPlayback") return playback;
      return localRequire(id);
    }, exports, Observer);
    function render() {
      stateIndex = 0; effectIndex = 0; pending = [];
      const tree = exports.default({ className: "media", ariaLabel: "Промо", sources: [{ src: "/loop.webm", type: "video/webm" }] });
      pending.forEach(fn => fn());
      return { tree, video: tree.props.children[0].props, toggle: tree.props.children[1]?.props };
    }
    function enter(visible: boolean) { observers.filter(observer => observer.active).forEach(observer => observer.callback([{ isIntersecting: visible }])); }
    function hidden(value: boolean) { doc.hidden = value; listeners.forEach(listener => listener()); }
    let result = render();
    assert.equal(result.video.children, null); assert.equal(result.toggle, undefined);
    variant = promo ? "not-matches" : "matches"; motion = "not-matches";
    result = render();
    if (promo) {
      assert.equal(observers.length, 0, "desktop never observes or downloads the mobile video");
      variant = "matches"; result = render();
      assert.equal(result.video.children, null);
      enter(false); assert.equal(render().video.children, null);
      enter(true); result = render();
    }
    assert.equal(result.video.children?.[0].props.src, "/loop.webm");
    assert.equal(result.video.preload, "metadata");
    assert.notEqual(result.tree.props["aria-hidden"], "true", "control must remain in the accessibility tree");
    assert.ok(result.toggle); assert.equal(result.toggle.size, "md"); assert.equal(result.toggle.iconOnly, true);
    assert.match(result.toggle["aria-label"], /Приостановить/);
    enter(true); assert.ok(video.plays > 0);
    result.video.onLoadedData(); result = render(); assert.equal(result.tree.props["data-state"], "video");
    enter(false); result = render();
    assert.match(result.toggle?.["aria-label"] ?? "", /Продолжить/, "viewport pause updates the real control");
    const viewportPausedPlays = video.plays;
    enter(true); hidden(true); hidden(false); result = render();
    assert.equal(video.plays, viewportPausedPlays, "returning after a viewport exit cannot autoplay");
    result.toggle?.onClick(); result = render(); enter(true);
    assert.ok(video.plays > viewportPausedPlays, "explicit play resumes the same loaded video");
    result.toggle?.onClick(); result = render();
    assert.match(result.toggle?.["aria-label"] ?? "", /Продолжить/);
    const pausedPlays = video.plays;
    enter(false); enter(true); hidden(true); hidden(false); render();
    assert.equal(video.plays, pausedPlays, "visibility changes cannot override manual pause");
    variant = "not-matches"; result = render(); assert.equal(result.toggle, undefined); assert.equal(result.video.children, null);
    variant = "matches"; result = render(); enter(true);
    assert.equal(video.plays, pausedPlays, "breakpoint changes retain manual pause");
    result.toggle?.onClick(); render(); enter(true); assert.ok(video.plays > pausedPlays);
    const resumedPlays = video.plays;
    motion = "matches"; result = render(); enter(true);
    assert.equal(result.video.children, null); assert.equal(result.toggle, undefined); assert.equal(video.plays, resumedPlays);
    effects.forEach(effect => effect.cleanup?.()); assert.equal(listeners.size, 0);
  });
}
