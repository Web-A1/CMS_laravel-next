import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";
import ts from "typescript";
import type { CheckoutCatalogItem } from "../../lib/commerceApi";

const commercePath = "../../lib/commerceApi.ts";
const commerce: typeof import("../../lib/commerceApi") = await import(commercePath);

async function harness(payload: unknown, ok = true, apiBase = "/backend") {
  const urls: string[] = [];
  const catalogSource = await readFile(new URL("../../lib/catalogApi.ts", import.meta.url), "utf8");
  const source = await readFile(new URL("./loadCartOffer.ts", import.meta.url), "utf8");
  function load(source: string, require: (name: string) => unknown) {
    const mod = { exports: {} as Record<string, unknown> };
    const output = ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText;
    Function("require", "module", "exports", "fetch", output)(require, mod, mod.exports, async (url: string) => { urls.push(url); return { ok, json: async () => payload }; });
    return mod.exports;
  }
  const catalog = load(catalogSource, (name) => {
    if (name === "./commerceApi") return commerce;
    if (name === "@/lib/apiBaseUrl") return { backendApiBaseUrl: () => "http://unused.test" };
    if (name === "@/lib/apiErrors") return {};
    if (name === "@/lib/staticFrontendMode") return {};
    throw new Error(name);
  });
  const mod = load(source, (name) => {
    if (name === "@/lib/catalogApi") return catalog;
    if (name === "@/lib/commerceApi") return commerce;
    if (name === "@/lib/apiBaseUrl") return { browserApiBaseUrl: () => apiBase };
    throw new Error(name);
  });
  return { urls, load: mod.loadCartOffer as (id: string) => Promise<CheckoutCatalogItem | null> };
}

const course = { id: 8, itemId: "course:anatomiya:osteologiya", slug: "osteologiya", title: "Остеология", shortTitle: null, price: 1000, subjectSlug: "anatomiya", subjectTitle: "Анатомия", lessons: [], sellable: { sku: "course-8", kind: "course", price: 2000, currency: "RUB", priceVersion: 2 } };
const subjects = (sellable: typeof course.sellable | null) => ({ data: [{ id: 1, slug: "anatomiya", title: "Анатомия", courses: [{ ...course, sellable }] }] });

test("fresh legacy course offer is read from subjects and respects withdrawal", async () => {
  const active = await harness(subjects(course.sellable));
  assert.equal((await active.load(course.itemId))?.price, 2000);
  assert.deepEqual(active.urls, ["/backend/api/v1/catalog/subjects"]);
  const removed = await harness(subjects(null));
  assert.equal(await removed.load(course.itemId), null);
});

test("new SKU uses sellables, absent SKU is unavailable, API failure stays an error", async () => {
  const payload = { data: [{ sku: "guide-1", kind: "guide", entityType: "guide", entityId: 1, title: "Методичка", price: 1000, currency: "RUB", priceVersion: 1, publicPath: "/offers/guide-1", accessPath: "/lk/guides/guide-1", tariff: null, atoms: [] }] };
  const active = await harness(payload);
  assert.equal((await active.load("guide-1"))?.sku, "guide-1");
  assert.deepEqual(active.urls, ["/backend/api/v1/catalog/sellables"]);
  assert.equal(await active.load("missing"), null);
  const failed = await harness({}, false);
  await assert.rejects(failed.load("guide-1"), /проверить/);
  const malformed = await harness({ data: "broken" });
  await assert.rejects(malformed.load("guide-1"));
});

test("offer validation respects the configured browser API origin on staging", async () => {
  const active = await harness(subjects(course.sellable), true, "https://api-staging.school.test");
  assert.ok(await active.load(course.itemId));
  assert.deepEqual(active.urls, ["https://api-staging.school.test/api/v1/catalog/subjects"]);
});
