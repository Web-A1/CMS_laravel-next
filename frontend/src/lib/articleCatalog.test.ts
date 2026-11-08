import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import { createRequire } from "node:module";
import test from "node:test";
import ts from "typescript";
import type { ArticleFilters } from "./articlesApi";

const seoModulePath = "./articleSeo.ts";
const { articleListPath }: typeof import("./articleSeo") = await import(seoModulePath);
const paginationModulePath = "./catalogPagination.ts";
const paginationHelpers = await import(paginationModulePath);
const require = createRequire(import.meta.url);
const calls: ArticleFilters[] = [];
const subjectQueries: Array<string | undefined> = [];
let indexingEnabled = false;
const source = await readFile(new URL("../app/(site)/articles/page.tsx", import.meta.url), "utf8");
const output = ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022, jsx: ts.JsxEmit.ReactJSX } }).outputText;
const sandbox = { exports: {} as Record<string, unknown> };
const notFound = () => { throw new Error("404"); };
Function("require", "module", "exports", output)((specifier: string): unknown => {
  if (specifier === "react/jsx-runtime") return require(specifier);
  if (specifier === "react") return { cache: (fn: unknown) => fn };
  if (specifier === "next/navigation") return { notFound };
  if (specifier === "@/lib/siteIndexingPolicy") return { isSiteIndexingEnabled: () => indexingEnabled };
  if (specifier === "@/lib/articleSeo") return { articleListPath, articleSiteUrl: () => "https://school.test" };
  if (specifier === "@/lib/catalogPagination") return paginationHelpers;
  if (specifier === "@/lib/articlesApi") return {
    articleSubjects: async (q?: string) => {
      subjectQueries.push(q);
      return [
        { id: 1, slug: "anatomiya", title: "Анатомия", count: q === "нетсовпадений" ? 0 : q === "сердце" ? 12 : q ? 18 : 30 },
        { id: 2, slug: "hirurgiya", title: "Хирургия", count: 0 },
        { id: 3, slug: "gistologiya", title: "Гистология", count: q === "нетсовпадений" ? 0 : q === "сердце" ? 8 : q ? 10 : 20 },
      ];
    },
    articleCategories: async () => [{ slug: "old", title: "Архивная рубрика", count: 30 }],
    listArticlesResult: async (filters: ArticleFilters) => {
      calls.push(filters);
      if (filters.q === "сердце" || filters.q === "нетсовпадений") {
        const total = filters.q === "нетсовпадений" ? 0 : filters.subject === "anatomiya" ? 12 : filters.subject === "gistologiya" ? 8 : filters.subject === "hirurgiya" ? 0 : 23;
        return { data: [], meta: { total, lastPage: Math.max(1, Math.ceil(total / (filters.limit ?? 25))) } };
      }
      const total = filters.subject === "hirurgiya" ? 0 : filters.subject === "anatomiya" ? (filters.q ? 18 : 30) : filters.subject === "gistologiya" ? (filters.q ? 10 : 20) : filters.q || filters.category ? 30 : 53;
      return { data: [], meta: { total, lastPage: Math.max(1, Math.ceil(total / (filters.limit ?? 25))) } };
    },
  };
  if (specifier.endsWith(".css")) return {};
  if (["next/link", "@/components/content/PillStrip", "@/components/content/ArticleCard", "@/components/content/PageIntroLayout", "@/components/content/CatalogSearchForm", "@/components/content/CatalogPagination"].includes(specifier)) return { default: specifier };
  throw new Error(`Unexpected module: ${specifier}`);
}, sandbox, sandbox.exports);
type Params = { subject?: string | string[]; category?: string | string[]; page?: string | string[]; q?: string | string[]; per_page?: string | string[] };
type Element = { type: unknown; props: Record<string, unknown> };
const catalog = sandbox.exports as {
  default: (props: { searchParams: Promise<Params> }) => Promise<Element>;
  generateMetadata: (props: { searchParams: Promise<Params> }) => Promise<{ title: string; alternates: { canonical: string }; robots: { index: boolean; follow: boolean } }>;
};
function elements(value: unknown): Element[] {
  if (Array.isArray(value)) return value.flatMap(elements);
  if (!value || typeof value !== "object" || !("props" in value)) return [];
  const element = value as Element;
  return [element, ...elements(element.props.children), ...elements(element.props.title), ...elements(element.props.search)];
}
const props = (params: Params) => ({ searchParams: Promise.resolve(params) });

test("zero-count subject is selectable but hidden from chips", async () => {
  const nodes = elements(await catalog.default(props({ subject: "hirurgiya" })));
  assert.equal(nodes.find((node) => node.type === "h1")?.props.children, "Хирургия");
  const pills = nodes.filter((node) => node.props.className === "ui-pill-control");
  assert.deepEqual(pills.map((node) => node.props.href), ["/articles", "/articles?subject=anatomiya", "/articles?subject=gistologiya"]);
  assert.equal(pills.some((node) => node.props["aria-current"] === "page"), false);
  assert.equal(nodes.some((node) => node.props["data-ui"] === "articles.empty"), true);
});

test("historical category retains query and pagination without active navigation or rubric title", async () => {
  calls.length = 0;
  const nodes = elements(await catalog.default(props({ category: "old", page: "2" })));
  assert.deepEqual(calls[0], { category: "old", subject: undefined, page: 2, limit: 25 });
  assert.equal(nodes.find((node) => node.type === "h1")?.props.children, "Медицинские статьи для студентов");
  const strip = nodes.find((node) => node.props.ariaLabel === "Разделы статей");
  assert.equal(elements(strip).some((node) => node.props["aria-current"] === "page" || node.props["data-active"] === "true"), false);
  assert.ok(nodes.some((node) => node.props.previousHref === "/articles?category=old" && node.props.currentPage === 2));
  const metadata = await catalog.generateMetadata(props({ category: "old", page: "2" }));
  assert.equal(metadata.title, "Статьи — страница 2");
  assert.equal(metadata.alternates.canonical, "https://school.test/articles?category=old&page=2");
});

test("search reaches the API, survives navigation and resets page on subject change", async () => {
  calls.length = 0;
  const nodes = elements(await catalog.default(props({ q: "  кровь  ", page: "2" })));
  assert.deepEqual(calls[0], { category: undefined, subject: undefined, q: "кровь", page: 2, limit: 25 });
  assert.ok(nodes.some((node) => node.props.href === "/articles?subject=anatomiya&q=%D0%BA%D1%80%D0%BE%D0%B2%D1%8C"));
  assert.ok(nodes.some((node) => node.props.previousHref === "/articles?q=%D0%BA%D1%80%D0%BE%D0%B2%D1%8C" && node.props.currentPage === 2));
  assert.deepEqual(nodes.find((node) => node.type === "@/components/content/CatalogSearchForm")?.props.feedback, { text: "Найдено статей: 30", resetHref: "/articles" });
  assert.ok(nodes.some((node) => node.type === "@/components/content/CatalogSearchForm" && node.props.query === "кровь"));
  const filtered = elements(await catalog.default(props({ subject: "anatomiya", q: "кровь" })));
  assert.ok(filtered.some((node) => node.props.type === "hidden" && node.props.name === "subject" && node.props.value === "anatomiya"));
  assert.equal((filtered.find((node) => node.type === "@/components/content/CatalogSearchForm")?.props.feedback as { resetHref: string }).resetHref, "/articles?subject=anatomiya");
});

test("search metadata is noindex in an indexable site and preserves the staging gate", async () => {
  indexingEnabled = true;
  try {
    const searched = await catalog.generateMetadata(props({ q: " heart%_\\ ", subject: "anatomiya" }));
    assert.deepEqual(searched.robots, { index: false, follow: true });
    assert.equal(searched.alternates.canonical, "https://school.test/articles?subject=anatomiya&q=heart%25_%5C");
    assert.deepEqual((await catalog.generateMetadata(props({ q: "  " }))).robots, { index: true, follow: true });
    indexingEnabled = false;
    assert.deepEqual((await catalog.generateMetadata(props({ q: "heart" }))).robots, { index: false, follow: false });
  } finally {
    indexingEnabled = false;
  }
});

test("article page size reaches the API and survives scope, search and pagination", async () => {
  calls.length = 0;
  const nodes = elements(await catalog.default(props({ category: "old", q: "кровь", page: "2", per_page: "10" })));
  assert.deepEqual(calls[0], { category: "old", subject: undefined, q: "кровь", page: 2, limit: 10 });
  assert.ok(nodes.some((node) => node.props.href === "/articles?subject=anatomiya&q=%D0%BA%D1%80%D0%BE%D0%B2%D1%8C&per_page=10"));
  assert.ok(nodes.some((node) => node.props.name === "per_page" && node.props.value === 10));
  const pager = nodes.find((node) => node.type === "@/components/content/CatalogPagination");
  assert.equal(pager?.props.previousHref, "/articles?category=old&q=%D0%BA%D1%80%D0%BE%D0%B2%D1%8C&per_page=10");
  const sizes = pager?.props.pageSizes as Array<{ value: number; href: string }>;
  assert.equal(sizes.find((size) => size.value === 25)?.href, "/articles?category=old&q=%D0%BA%D1%80%D0%BE%D0%B2%D1%8C");
  assert.equal((nodes.find((node) => node.type === "@/components/content/CatalogSearchForm")?.props.feedback as { resetHref: string }).resetHref, "/articles?category=old&per_page=10");
});

test("article page size is strictly validated and nondefault URLs are self canonical noindex", async () => {
  for (const per_page of ["", "24", "48", "015", ["10", "20"]]) {
    await assert.rejects(catalog.default(props({ per_page })), /404/u);
    await assert.rejects(catalog.generateMetadata(props({ per_page })), /404/u);
  }
  indexingEnabled = true;
  try {
    const meta = await catalog.generateMetadata(props({ subject: "anatomiya", page: "2", per_page: "10" }));
    assert.equal(meta.alternates.canonical, "https://school.test/articles?subject=anatomiya&page=2&per_page=10");
    assert.deepEqual(meta.robots, { index: false, follow: true });
    const defaultMeta = await catalog.generateMetadata(props({ per_page: "25" }));
    assert.equal(defaultMeta.alternates.canonical, "https://school.test/articles");
    assert.deepEqual(defaultMeta.robots, { index: true, follow: true });
  } finally { indexingEnabled = false; }
});

test("search validates type and Unicode length and treats whitespace as no query", async () => {
  for (const q of [["heart"], "a".repeat(121), "🫀".repeat(121)]) {
    await assert.rejects(catalog.default(props({ q })), /404/u);
  }
  await catalog.default(props({ q: "🫀".repeat(120) }));
  calls.length = 0;
  await catalog.default(props({ q: "  " }));
  assert.equal(calls[0]?.q, undefined);
});

test("subject navigation and metadata use the canonical subject namespace", async () => {
  const nodes = elements(await catalog.default(props({ subject: "anatomiya" })));
  assert.ok(nodes.some((node) => node.props.href === "/articles?subject=anatomiya" && node.props["aria-current"] === "page"));
  const metadata = await catalog.generateMetadata(props({ subject: "anatomiya", page: "2" }));
  assert.equal(metadata.alternates.canonical, "https://school.test/articles?subject=anatomiya&page=2");
});

test("invalid, conflicting, repeated and out-of-range filters return 404", async () => {
  for (const params of [{ subject: "missing" }, { category: "missing" }, { subject: "anatomiya", category: "old" }, { subject: ["anatomiya", "anatomiya"] }, { category: ["old"] }, { page: ["1"] }, { page: "0" }, { page: "" }, { page: "-1" }, { page: "1.5" }, { page: "9007199254740992" }, { page: "4" }]) {
    await assert.rejects(catalog.default(props(params)), /404/u);
  }
  const nodes = elements(await catalog.default(props({ subject: "", category: "" })));
  assert.ok(nodes.some((node) => node.props.href === "/articles" && node.props["aria-current"] === "page"));
});

function pillCounts(nodes: Element[]): ReadonlyArray<readonly [string, number]> {
  return nodes.filter((node) => node.props.className === "ui-pill-control").map((pill) => {
    const children = elements(pill);
    return [
      children.find((node) => node.props.className === "ui-pill-control__label")?.props.children as string,
      children.find((node) => node.props.className === "ui-pill-control__count")?.props.children as number,
    ] as const;
  });
}

test("search pill counts span all matching articles independently of subject, page and size", async () => {
  const expected = [["Все", 23], ["Анатомия", 12], ["Гистология", 8]];
  for (const params of [{ q: "сердце", page: "2", per_page: "10" }, { q: "сердце", subject: "anatomiya", page: "2", per_page: "10" }, { q: "сердце", category: "old", per_page: "50" }]) {
    calls.length = 0;
    subjectQueries.length = 0;
    const nodes = elements(await catalog.default(props(params)));
    assert.deepEqual(pillCounts(nodes), expected);
    assert.deepEqual(subjectQueries, [undefined, "сердце"]);
    const globalCountCalls = calls.filter((call) => call.limit === 1);
    assert.equal(globalCountCalls.length, params.subject || params.category ? 1 : 0);
    if (globalCountCalls.length) assert.deepEqual(globalCountCalls[0], { limit: 1, q: "сердце" });
  }
  const selected = elements(await catalog.default(props({ q: "сердце", subject: "anatomiya" })));
  assert.equal((selected.find((node) => node.type === "@/components/content/CatalogSearchForm")?.props.feedback as { text: string }).text, "Найдено статей: 12");
  assert.equal((await catalog.generateMetadata(props({ q: "сердце", subject: "anatomiya" }))).alternates.canonical, "https://school.test/articles?subject=anatomiya&q=%D1%81%D0%B5%D1%80%D0%B4%D1%86%D0%B5");
});

test("empty search results keep eligible zero pills and blank search restores global counts", async () => {
  const empty = elements(await catalog.default(props({ q: "нетсовпадений" })));
  assert.deepEqual(pillCounts(empty), [["Все", 0], ["Анатомия", 0], ["Гистология", 0]]);
  assert.ok(empty.some((node) => node.props["data-ui"] === "articles.empty"));
  subjectQueries.length = 0;
  const cleared = elements(await catalog.default(props({ q: "  " })));
  assert.deepEqual(pillCounts(cleared), [["Все", 53], ["Анатомия", 30], ["Гистология", 20]]);
  assert.deepEqual(subjectQueries, [undefined]);
  subjectQueries.length = 0;
  await catalog.generateMetadata(props({ q: "сердце" }));
  assert.deepEqual(subjectQueries, [undefined]);
});
