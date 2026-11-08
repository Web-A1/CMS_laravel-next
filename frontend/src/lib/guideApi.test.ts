import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";
import ts from "typescript";
const commercePath = "./commerceApi.ts";
const commerce: typeof import("./commerceApi") = await import(commercePath);
const source = await readFile(new URL("./guideApi.ts", import.meta.url), "utf8");
const output = ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText;
const sandbox = { exports: {} };
let response = new Response();
Function("require", "module", "exports", output)((name: string) => name === "./commerceApi" ? commerce : { fetchAccountBackend: async () => response }, sandbox, sandbox.exports);
const api = sandbox.exports as typeof import("./guideApi");
test("guide and manifest safe payloads preserve empty library", () => {
  assert.deepEqual(api.normalizeGuides({ data: [] }), []);
  assert.equal(api.normalizeGuides({ data: [{ id: 3, slug: "legacy-file-100", title: "Методичка" }] })[0]?.id, 3);
  assert.deepEqual(api.normalizeGuideFiles({ data: { files: [{ materialId: 4, title: "PDF" }] } }), [{ materialId: 4, title: "PDF" }]);
});
test("guide parsers deny source leakage and malformed/duplicate materials", () => {
  assert.throws(() => api.normalizeGuides({ data: [{ id: 3, slug: "x", title: "PDF", sourceUrl: "/raw" }] }));
  assert.throws(() => api.normalizeGuideFiles({ data: { files: [{ materialId: 4, title: "PDF", downloadUrl: "/raw" }] } }));
  assert.throws(() => api.normalizeGuideFiles({ data: { files: [{ materialId: 4, title: "PDF" }, { materialId: 4, title: "PDF" }] } }));
});
test("private API distinguishes revoked access, missing owner and unavailable responses", async () => {
  for (const [status, expected] of [[401, "denied"], [403, "denied"], [404, "not_found"], [500, "unavailable"]] as const) {
    response = new Response(null, { status });
    assert.equal((await api.loadGuideFiles("/backend", "test")).status, expected);
  }
});
