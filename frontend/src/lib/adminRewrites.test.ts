import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";
import ts from "typescript";

const policyModulePath = "./siteIndexingPolicy.ts";
const indexingPolicy: typeof import("./siteIndexingPolicy") = await import(policyModulePath);

async function rewrites(env: Readonly<Record<string, string>>): Promise<ReadonlyArray<{ source: string; destination: string }>> {
  const source = await readFile(new URL("../../next.config.ts", import.meta.url), "utf8");
  const output = ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText;
  const sandbox = { exports: {} as { default?: { rewrites(): Promise<{ beforeFiles: ReadonlyArray<{ source: string; destination: string }> }> } } };
  Function("require", "module", "exports", "process", output)(
    (specifier: string): unknown => {
      if (specifier === "./src/lib/securityResponseHeaders") return { securityResponseHeaders: [] };
      if (specifier === "./src/lib/siteIndexingPolicy") return indexingPolicy;
      throw new Error(`Unexpected module: ${specifier}`);
    }, sandbox, sandbox.exports, { env },
  );
  assert.ok(sandbox.exports.default);
  return (await sandbox.exports.default.rewrites()).beforeFiles;
}

test("admin and Livewire use the staging API origin when only the public API URL is configured", async () => {
  const rules = await rewrites({ NEXT_PUBLIC_API_BASE_URL: "https://api-staging.christmedschool.com" });
  assert.equal(rules.find((rule) => rule.source === "/admin")?.destination, "https://api-staging.christmedschool.com/admin");
  assert.ok(rules.every((rule) => rule.destination.startsWith("https://api-staging.christmedschool.com/")));
});

test("explicit backend wins; relative browser proxy keeps the local backend; credentials are rejected", async () => {
  const rules = await rewrites({ BACKEND_API_BASE_URL: "https://backend.test", NEXT_PUBLIC_API_BASE_URL: "https://browser.test" });
  assert.equal(rules[0]?.destination, "https://backend.test/admin");
  const local = await rewrites({ NEXT_PUBLIC_API_BASE_URL: "/backend" });
  assert.equal(local[0]?.destination, "http://127.0.0.1:8000/admin");
  await assert.rejects(rewrites({ BACKEND_API_BASE_URL: "https://user:secret@backend.test" }));
});
