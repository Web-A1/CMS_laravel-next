import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";
import ts from "typescript";

const source = await readFile(new URL("./apiBaseUrl.ts", import.meta.url), "utf8");
const output = ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.CommonJS } }).outputText;
function browserBase(configured?: string): string {
  const sandboxModule = { exports: {} as { browserApiBaseUrl: () => string } };
  Function("module", "exports", "process", output)(sandboxModule, sandboxModule.exports, { env: { NEXT_PUBLIC_API_BASE_URL: configured } });
  return sandboxModule.exports.browserApiBaseUrl();
}

test("browser API keeps local proxy and configured staging origin without a trailing slash", () => {
  assert.equal(browserBase(), "/backend");
  assert.equal(browserBase("/backend/"), "/backend");
  assert.equal(browserBase("https://api-staging.school.test/"), "https://api-staging.school.test");
  assert.equal(browserBase("invalid"), "/backend");
});
