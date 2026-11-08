import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";
import ts from "typescript";

type FaqModule = Pick<typeof import("./faqApi"), "normalizeFaqResult" | "listFaqsResult">;
async function loadModule(): Promise<FaqModule> {
  const source = await readFile(new URL("./faqApi.ts", import.meta.url), "utf8");
  const output = ts.transpileModule(source, { compilerOptions: { esModuleInterop: true, module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText;
  const sandboxModule = { exports: {} as Record<string, unknown> };
  const requireStub = (specifier: string): unknown => {
    if (specifier === "@/lib/apiBaseUrl") return { backendApiBaseUrl: () => "https://backend.test" };
    if (specifier === "@/lib/apiErrors") return { apiUnavailableError: () => new Error(), rethrowNextDynamicServerError: () => undefined };
    if (specifier === "@/lib/staticFrontendMode") return { isStaticFrontendModeEnabled: () => false };
    throw new Error(`Unexpected module: ${specifier}`);
  };
  Function("require", "module", "exports", output)(requireStub, sandboxModule, sandboxModule.exports);
  return sandboxModule.exports as FaqModule;
}
const api = await loadModule();
const item = { id: 1, slug: "payment", question: "Как оплатить?", answer: "Картой.", category: { slug: "orders", title: "Оплата" }, subject: null, sortOrder: 1, publishedAt: null } as const;

test("validates FAQ item, category and meta", () => {
  assert.deepEqual(api.normalizeFaqResult({ data: [item], meta: { placement: "home.faq", limit: 6, total: 1 } }, { placement: "home.faq", limit: 6 }).data, [item]);
});

test("rejects malformed nested FAQ DTO", () => {
  for (const invalid of [{ ...item, category: null as unknown as object }, { ...item, category: { slug: 1, title: "Оплата" } }, { ...item, sortOrder: "1" }, { ...item, internalNotes: "secret" }]) {
    if (invalid.category === null) continue;
    assert.throws(() => api.normalizeFaqResult({ data: [invalid] }, {}));
  }
  assert.throws(() => api.normalizeFaqResult({ data: [item], meta: { placement: "other", limit: 1, total: 1 } }, {}));
});

test("accepts only a canonical nullable FAQ subject", () => {
  const subject = { id: 1, slug: "anatomiya", title: "Анатомия" };
  assert.deepEqual(api.normalizeFaqResult({ data: [{ ...item, subject }] }, {}).data[0]?.subject, subject);
  for (const invalid of [undefined, { ...subject, id: 0 }, { ...subject, id: "1" }, { ...subject, title: "" }, { ...subject, extra: true }]) {
    assert.throws(() => api.normalizeFaqResult({ data: [{ ...item, subject: invalid }] }, {}));
  }
});

test("serializes subject together with the existing FAQ filters", async () => {
  const previousFetch = globalThis.fetch;
  let requested = "";
  try {
    globalThis.fetch = async (input) => {
      requested = String(input);
      return new Response(JSON.stringify({ data: [item] }));
    };
    await api.listFaqsResult({ subject: "anatomiya", category: "orders", placement: "home.faq", limit: 6 });
    const query = new URL(requested).searchParams;
    assert.equal(query.get("subject"), "anatomiya");
    assert.equal(query.get("category"), "orders");
    assert.equal(query.get("placement"), "home.faq");
    assert.equal(query.get("limit"), "6");
  } finally {
    globalThis.fetch = previousFetch;
  }
});
