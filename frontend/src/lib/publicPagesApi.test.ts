import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";
import ts from "typescript";

type PagesModule = Pick<typeof import("./publicPagesApi"), "normalizePublicPage">;
async function loadModule(): Promise<PagesModule> {
  const source = await readFile(new URL("./publicPagesApi.ts", import.meta.url), "utf8");
  const output = ts.transpileModule(source, { compilerOptions: { esModuleInterop: true, module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText;
  const sandboxModule = { exports: {} as Record<string, unknown> };
  const requireStub = (specifier: string): unknown => {
    if (specifier === "@/lib/apiBaseUrl") return { backendApiBaseUrl: () => "https://backend.test" };
    if (specifier === "@/lib/apiErrors") return { apiUnavailableError: () => new Error(), rethrowNextDynamicServerError: () => undefined };
    if (specifier === "@/lib/staticFrontendMode") return { isStaticFrontendModeEnabled: () => false };
    throw new Error(`Unexpected module: ${specifier}`);
  };
  Function("require", "module", "exports", output)(requireStub, sandboxModule, sandboxModule.exports);
  return sandboxModule.exports as PagesModule;
}
const api = await loadModule();
const page = { slug: "about", title: "О школе", description: null, body: "Текст", sortOrder: 1, publishedAt: null, seoTitle: null, seoDescription: null } as const;

test("validates the complete public page DTO", () => {
  assert.deepEqual(api.normalizePublicPage({ data: page }), page);
});

test("rejects wrong slugs, nested primitive drift and protected fields", () => {
  for (const invalid of [{ ...page, slug: "draft" }, { ...page, sortOrder: "1" }, { ...page, body: null }, { ...page, internalDraft: "secret" }]) {
    assert.throws(() => api.normalizePublicPage({ data: invalid }));
  }
});
