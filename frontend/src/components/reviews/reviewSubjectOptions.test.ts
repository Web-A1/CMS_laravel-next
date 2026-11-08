import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";
import ts from "typescript";

const source = await readFile(new URL("./reviewSubjectOptions.ts", import.meta.url), "utf8");
const output = ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.CommonJS } }).outputText;
const sandbox = { exports: {} as typeof import("./reviewSubjectOptions") };
Function("exports", output)(sandbox.exports);

test("subject changes preserve search while clearing page and selected review", () => {
  const options = sandbox.exports.reviewSubjectOptions([{ slug: "anatomy", title: "Анатомия", reviewsCount: 8 }], 20, "Имя & текст", 10);
  for (const option of options) {
    const params = new URL(option.href, "https://school.test").searchParams;
    assert.equal(params.get("q"), "Имя & текст");
    assert.equal(params.has("page"), false);
    assert.equal(params.has("review"), false);
    assert.equal(params.get("per_page"), "10");
  }
  assert.equal(sandbox.exports.reviewsHref("anatomy"), "/reviews?subject=anatomy");
  assert.equal(sandbox.exports.reviewsHref(), "/reviews");
});
