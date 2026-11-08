import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { createRequire } from "node:module";
import test from "node:test";
import type { ReactElement } from "react";
import ts from "typescript";

const localRequire = createRequire(import.meta.url);
const compiled = ts.transpileModule(readFileSync(new URL("./SiteHeader.tsx", import.meta.url), "utf8"), {
  compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022, jsx: ts.JsxEmit.ReactJSX },
}).outputText + "\nexports.SubjectsMenu = SubjectsMenu;";

test("desktop subjects dismisses for pointer or keyboard and reopens only on fresh entry", () => {
  let open = false, hovered = false, refIndex = 0;
  let effectEnabled: boolean | undefined, cleanup: (() => void) | undefined;
  let listener: ((event: { key: string }) => void) | undefined;
  const trigger = { focus() { doc.activeElement = trigger; } };
  const link = {};
  const outside = {};
  const doc = {
    activeElement: outside,
    addEventListener(_name: string, fn: typeof listener) { listener = fn; },
    removeEventListener() { listener = undefined; },
  };
  const container = { contains(target: unknown) { return target === trigger || target === link; }, matches() { return hovered; } };
  const refs = [{ current: { contains(target: unknown) { return target === link; } } }, { current: trigger }];
  const react = {
    useRef() { return refs[refIndex++]; },
    useState() { return [open, (value: boolean) => { open = value; }]; },
    useEffect(fn: () => (() => void) | undefined, deps: boolean[]) {
      if (deps[0] === effectEnabled) return;
      cleanup?.(); effectEnabled = deps[0]; cleanup = fn();
    },
  };
  type Entry = { currentTarget: typeof container; relatedTarget: unknown };
  type Props = {
    "data-open": boolean;
    onMouseEnter: () => void;
    onMouseLeave: (event: Entry) => void;
    onFocus: (event: Entry) => void;
    onBlur: (event: Entry) => void;
    children: [ReactElement<{ href: string; "aria-expanded": boolean; onClick: () => void }>, ReactElement<{ inert: boolean }>];
  };
  const exports = {} as { SubjectsMenu: (props: { onNavigate: (href: string) => void }) => ReactElement<Props> };
  new Function("require", "exports", "document", compiled)((id: string) => {
    if (id === "react") return react;
    if (id === "react/jsx-runtime") return localRequire(id);
    return {};
  }, exports, doc);
  let navigation = "";
  function render() {
    refIndex = 0;
    return exports.SubjectsMenu({ onNavigate: (href) => { navigation = href; } }).props;
  }
  function escape() { assert.ok(listener); listener({ key: "Escape" }); }
  const entry = (relatedTarget: unknown): Entry => ({ currentTarget: container, relatedTarget });
  let result = render(); assert.equal(result["data-open"], false); assert.equal(result.children[1].props.inert, true);
  hovered = true; result.onMouseEnter(); result = render(); assert.equal(result["data-open"], true);
  escape(); result = render(); assert.equal(result["data-open"], false); assert.equal(doc.activeElement, outside);
  result.onFocus(entry(trigger)); result = render(); assert.equal(result["data-open"], false, "internal focus changes do not reopen a dismissed panel");
  hovered = false; result.onMouseLeave(entry(null)); hovered = true; result.onMouseEnter(); result = render(); assert.equal(result["data-open"], true);
  doc.activeElement = link; escape(); result = render();
  assert.equal(result["data-open"], false); assert.equal(doc.activeElement, trigger, "hidden panel cannot retain focus");
  result.onFocus(entry(link)); result = render(); assert.equal(result["data-open"], false, "restored trigger focus does not reopen");
  doc.activeElement = outside; hovered = false; result.onBlur(entry(outside));
  result.onFocus(entry(outside)); result = render(); assert.equal(result["data-open"], true);
  assert.equal(result.children[0].props["aria-expanded"], true);
  result.onFocus(entry(trigger)); result = render(); assert.equal(result["data-open"], true, "Tab within panel retains it");
  result.children[0].props.onClick(); assert.equal(navigation, "/subjects"); assert.equal(result.children[0].props.href, "/subjects");
  result.onBlur(entry(outside)); result = render(); assert.equal(result["data-open"], false);
  cleanup?.(); assert.equal(listener, undefined);
});
