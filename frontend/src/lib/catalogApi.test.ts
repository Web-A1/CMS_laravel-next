import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";
import ts from "typescript";
const commercePath = "./commerceApi.ts";
const commerce = await import(commercePath);

type CatalogModule = Pick<typeof import("./catalogApi"), "normalizeSubjects">;

async function loadModule(): Promise<CatalogModule> {
  const source = await readFile(new URL("./catalogApi.ts", import.meta.url), "utf8");
  const output = ts.transpileModule(source, { compilerOptions: { esModuleInterop: true, module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText;
  const sandboxModule = { exports: {} as Record<string, unknown> };
  const requireStub = (specifier: string): unknown => {
    if (specifier === "./commerceApi") return commerce;
    if (specifier === "@/lib/apiBaseUrl") return { backendApiBaseUrl: () => "https://backend.test" };
    if (specifier === "@/lib/apiErrors") return { apiUnavailableError: () => new Error(), rethrowNextDynamicServerError: () => undefined };
    if (specifier === "@/lib/staticFrontendMode") return { isStaticFrontendModeEnabled: () => false };
    throw new Error(`Unexpected module: ${specifier}`);
  };
  Function("require", "module", "exports", output)(requireStub, sandboxModule, sandboxModule.exports);
  return sandboxModule.exports as CatalogModule;
}

const api = await loadModule();
const lesson = { id: 3, slug: "intro", title: "Введение", sortOrder: 1 } as const;
const course = {
  id: 2, itemId: "course:anatomy:bones", slug: "bones", title: "Кости", shortTitle: null, price: 1000,
  sellable: { sku: "course-anatomy-bones", kind: "course", price: 1000, currency: "RUB", priceVersion: 1 },
  subjectSlug: "anatomy", subjectTitle: "Анатомия", lessons: [lesson],
} as const;
const subject = { id: 1, slug: "anatomy", title: "Анатомия", courses: [course] } as const;

test("validates the complete nested catalog DTO", () => {
  assert.equal(api.normalizeSubjects({ data: [subject] })[0]?.courses[0]?.lessons[0]?.slug, "intro");
});

test("rejects malformed and protected nested catalog fields", () => {
  const invalid = [
    { ...subject, courses: null },
    { ...subject, courses: [{ ...course, price: "1000" }] },
    { ...subject, courses: [{ ...course, lessons: [{ ...lesson, sortOrder: null }] }] },
    { ...subject, courses: [{ ...course, sellable: { ...course.sellable, priceVersion: 0 } }] },
    { ...subject, courses: [{ ...course, protectedVideoToken: "secret" }] },
  ];
  for (const value of invalid) assert.throws(() => api.normalizeSubjects({ data: [value] }));
});
