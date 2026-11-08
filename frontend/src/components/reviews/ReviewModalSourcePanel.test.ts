import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import { createRequire } from "node:module";
import test from "node:test";
import type { ReactNode } from "react";
import { renderToStaticMarkup } from "react-dom/server";
import ts from "typescript";
import type { ReviewSourceLink } from "../../lib/reviewsApi";

const source = await readFile(new URL("./ReviewModalSourcePanel.tsx", import.meta.url), "utf8");
const output = ts.transpileModule(source, {
  compilerOptions: { jsx: ts.JsxEmit.ReactJSX, module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
}).outputText;
const compiled = { exports: {} as { default: (props: { review: { sourceLinks: ReadonlyArray<ReviewSourceLink> } }) => ReactNode } };
Function("require", "module", "exports", output)(createRequire(import.meta.url), compiled, compiled.exports);
const render = (sourceLinks: ReadonlyArray<ReviewSourceLink>): string => renderToStaticMarkup(compiled.exports.default({ review: { sourceLinks } }));

test("generic source renders the original URL without a platform mark", () => {
  const html = render([{ type: "manual", label: "Источник", url: "https://example.org/review/42" }]);
  assert.match(html, /href="https:\/\/example.org\/review\/42"/);
  assert.match(html, />Источник<\/a>/);
  assert.match(html, /target="_blank"/);
  assert.match(html, /rel="noreferrer"/);
  assert.doesNotMatch(html, /<svg/);
});

test("VK and Yandex keep their platform marks and empty source stays hidden", () => {
  for (const type of ["vk_webarchive", "yandex_maps_webarchive"] as const) {
    assert.match(render([{ type, label: "Оригинал", url: "https://example.org/review" }]), /<svg/);
  }
  assert.equal(render([]), "");
});
