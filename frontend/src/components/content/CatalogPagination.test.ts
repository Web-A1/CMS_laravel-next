import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import { createRequire } from "node:module";
import test from "node:test";
import ts from "typescript";

const require = createRequire(import.meta.url);
const menuRef = { current: null as HTMLDetailsElement | null };
const source = await readFile(new URL("./CatalogPagination.tsx", import.meta.url), "utf8");
const output = ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022, jsx: ts.JsxEmit.ReactJSX } }).outputText;
type Element = { type: unknown; props: Record<string, unknown> };
type Props = Parameters<typeof import("./CatalogPagination").default>[0];
const sandbox = { exports: {} as { default: (props: Props) => Element | null } };
Function("require", "module", "exports", output)((specifier: string): unknown => {
  if (specifier === "react/jsx-runtime") return require(specifier);
  if (specifier === "react") return { useRef: () => menuRef };
  if (specifier === "next/link") return { default: "next/link" };
  if (specifier === "@/components/button/Button") return { ButtonRouteLink: "ButtonRouteLink" };
  throw new Error(`Unexpected module: ${specifier}`);
}, sandbox, sandbox.exports);
const props: Props = {
  currentPage: 1, totalPages: 9, total: 225, perPage: 25,
  previousHref: "/articles", nextHref: "/articles?page=2",
  pageSizes: [{ value: 10, href: "/articles?per_page=10" }, { value: 25, href: "/articles" }],
  label: "Страницы статей", pageSizeLabel: "Статей на странице", dataUi: "articles.pagination",
};
function elements(value: unknown): Element[] {
  if (Array.isArray(value)) return value.flatMap(elements);
  if (!value || typeof value !== "object" || !("props" in value)) return [];
  const element = value as Element;
  return [element, ...elements(element.props.children)];
}

test("pagination labels its icon links and disables the correct boundary", () => {
  const nodes = elements(sandbox.exports.default(props));
  assert.ok(nodes.some((node) => node.type === "nav" && node.props["aria-label"] === props.label));
  const links = nodes.filter((node) => node.type === "ButtonRouteLink");
  assert.deepEqual(links.map((node) => [node.props["aria-label"], node.props.disabled, node.props.href]), [
    ["Предыдущая страница", true, "/articles"], ["Следующая страница", false, "/articles?page=2"],
  ]);
  assert.ok(links.every((node) => node.props.iconOnly && node.props.variant === "ghost"));
  assert.ok(nodes.filter((node) => node.type === "svg").every((node) => node.props["aria-hidden"] === "true"));
  const last = elements(sandbox.exports.default({ ...props, currentPage: 9 }));
  assert.deepEqual(last.filter((node) => node.type === "ButtonRouteLink").map((node) => node.props.disabled), [false, true]);
});

test("page size disclosure links to supplied first page URLs and marks the current choice", () => {
  const nodes = elements(sandbox.exports.default(props));
  const summary = nodes.find((node) => node.type === "summary");
  assert.equal(summary?.props["aria-label"], "Статей на странице: 25");
  const choices = nodes.filter((node) => node.type === "next/link");
  assert.deepEqual(choices.map((node) => [node.props.href, node.props["aria-current"]]), [["/articles?per_page=10", undefined], ["/articles", "page"]]);
});

test("one page keeps the size choice and two disabled links; empty results hide pagination", () => {
  const nodes = elements(sandbox.exports.default({ ...props, total: 1, totalPages: 1 }));
  assert.equal(nodes.filter((node) => node.type === "summary").length, 1);
  assert.deepEqual(nodes.filter((node) => node.type === "ButtonRouteLink").map((node) => node.props.disabled), [true, true]);
  assert.equal(sandbox.exports.default({ ...props, total: 0, totalPages: 1 }), null);
});

test("size menu keyboard navigation enters from both ends and Escape restores the trigger", () => {
  let focused = -1;
  const links = Array.from({ length: 3 }, (_, index) => ({ focus: () => { focused = index; } }));
  const summary = { focus: () => { focused = -1; } };
  const menu = { open: false, querySelectorAll: () => links, querySelector: () => summary };
  menuRef.current = menu as unknown as HTMLDetailsElement;
  const details = elements(sandbox.exports.default(props)).find((node) => node.type === "details");
  const onKeyDown = details?.props.onKeyDown as (event: unknown) => void;
  const press = (key: string, target: unknown = summary) => onKeyDown({ key, target, preventDefault: () => undefined });
  press("ArrowUp");
  assert.equal(focused, 2);
  assert.equal(menu.open, true);
  press("ArrowDown");
  assert.equal(focused, 0);
  press("End", links[0]);
  assert.equal(focused, 2);
  press("Home", links[2]);
  assert.equal(focused, 0);
  press("Escape", links[0]);
  assert.equal(menu.open, false);
  assert.equal(focused, -1);
  menuRef.current = null;
});
