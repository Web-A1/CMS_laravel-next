import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";
import ts from "typescript";
import type { ArticleFilters, ArticleListResult, PublicArticleDetail, PublicArticleSummary } from "./articlesApi";

const subject = { id: 1, slug: "gistologiya", title: "Гистология" };
function summary(id: number, overrides: Partial<PublicArticleSummary> = {}): PublicArticleSummary {
  return { id, slug: `article-${id}`, title: `Статья ${id}`, description: null, category: null, subject, sortOrder: id, publishedAt: null, updatedAt: null, excerpt: null, author: null, cover: null, tags: [], ...overrides };
}
function article(related: ReadonlyArray<PublicArticleSummary> = []): PublicArticleDetail {
  return { ...summary(225), related, body: "Текст", bodyFormat: "text", attachments: [], relatedFaqs: [], seoTitle: null, seoDescription: null };
}
function result(data: ReadonlyArray<PublicArticleSummary>, page = 1, lastPage = 1): ArticleListResult {
  return { data, meta: { placement: "articles.index", limit: 50, total: 101, page, perPage: 50, lastPage } };
}
async function loadSidebar(fetch: (filters: ArticleFilters) => Promise<ArticleListResult>, dynamicError?: Error) {
  const source = await readFile(new URL("./articleSidebar.ts", import.meta.url), "utf8");
  const output = ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText;
  const sandboxModule = { exports: {} };
  const warnings: unknown[][] = [];
  Function("require", "module", "exports", "console", output)((specifier: string) => {
    if (specifier === "@/lib/articlesApi") return { listArticlesResult: fetch };
    if (specifier === "@/lib/apiErrors") return { rethrowNextDynamicServerError: (error: unknown) => { if (error === dynamicError && dynamicError) throw error; } };
    throw new Error(`Unexpected module: ${specifier}`);
  }, sandboxModule, sandboxModule.exports, { warn: (...args: unknown[]) => warnings.push(args) });
  return { ...sandboxModule.exports as Pick<typeof import("./articleSidebar"), "articleSidebarItems">, warnings };
}

test("loads all subject pages beyond fifty candidates and keeps related pins first", async () => {
  const calls: ArticleFilters[] = [];
  const { articleSidebarItems } = await loadSidebar(async (filters) => {
    calls.push(filters);
    const page = filters.page ?? 1;
    return result(Array.from({ length: page === 3 ? 1 : 50 }, (_, index) => summary((page - 1) * 50 + index + 1)), page, 3);
  });
  const items = await articleSidebarItems(article([summary(80), summary(2)]));
  assert.equal(items.length, 101);
  assert.deepEqual(items.slice(0, 3).map((item) => item.id), [80, 2, 1]);
  assert.equal(items.at(-1)?.id, 101);
  assert.deepEqual(calls, [1, 2, 3].map((page) => ({ subject: "gistologiya", limit: 50, page })));
});

test("excludes the current article, duplicate ids and slugs, and off-subject fetched items", async () => {
  const { articleSidebarItems } = await loadSidebar(async () => result([
    summary(225), summary(1), summary(3, { slug: "article-1" }), summary(4, { subject: { id: 2, slug: "anatomiya", title: "Анатомия" } }), summary(5),
  ]));
  const items = await articleSidebarItems(article([summary(225), summary(1), summary(1, { slug: "duplicate-id" }), summary(9, { subject: { id: 2, slug: "anatomiya", title: "Анатомия" } }), summary(10, { subject: null })]));
  assert.deepEqual(items.map((item) => item.id), [1, 5]);
});

test("a failure on a later page restores original related items without failing the article", async () => {
  const { articleSidebarItems, warnings } = await loadSidebar(async ({ page }) => {
    if (page === 2) throw new Error("API unavailable");
    return result([summary(3)], 1, 2);
  });
  assert.deepEqual((await articleSidebarItems(article([summary(2)]))).map((item) => item.id), [2]);
  assert.equal(warnings.length, 1);
});

test("Next dynamic errors propagate through the fallback boundary", async () => {
  const dynamicError = new Error("dynamic server usage");
  const { articleSidebarItems, warnings } = await loadSidebar(async () => { throw dynamicError; }, dynamicError);
  await assert.rejects(articleSidebarItems(article()), (error) => error === dynamicError);
  assert.equal(warnings.length, 0);
});

test("missing subject preserves related without fetching a global catalog", async () => {
  const { articleSidebarItems } = await loadSidebar(async () => { throw new Error("must not fetch"); });
  assert.deepEqual((await articleSidebarItems({ ...article([summary(2)]), subject: null })).map((item) => item.id), [2]);
});
