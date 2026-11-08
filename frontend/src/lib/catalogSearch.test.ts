import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import { createRequire } from "node:module";
import test from "node:test";
import ts from "typescript";

const require = createRequire(import.meta.url);
type Element = { type: unknown; props: Record<string, unknown> };
type Params = { q?: string | string[]; category?: string; subject?: string; page?: string; review?: string; per_page?: string | string[] };
type PageModule = {
  default: (props: { searchParams: Promise<Params> }) => Promise<Element>;
  generateMetadata: (props: { searchParams: Promise<Params> }) => Promise<{ title: string; alternates?: { canonical: string }; robots?: { index: boolean; follow: boolean } }>;
};
const faqItems = [
  { id: 1, question: "Как получить доступ?", answer: "Оплатите курс.", category: { slug: "courses", title: "Курсы" } },
  { id: 2, question: "На сколько выдаются материалы?", answer: "Доступ на год.", category: { slug: "courses", title: "Курсы" } },
  { id: 3, question: "Что значит скидка 10%?", answer: "Стоимость уменьшается.", category: { slug: "payment", title: "Оплата" } },
];
const reviewCalls: unknown[] = [];
let indexingEnabled = true;
const paginationModulePath = "./catalogPagination.ts";
const paginationHelpers = await import(paginationModulePath);

async function load(relativePath: string): Promise<Record<string, unknown>> {
  const source = await readFile(new URL(relativePath, import.meta.url), "utf8");
  const output = ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022, jsx: ts.JsxEmit.ReactJSX } }).outputText;
  const sandbox = { exports: {} as Record<string, unknown> };
  Function("require", "module", "exports", output)((specifier: string): unknown => {
    if (specifier === "react/jsx-runtime") return require(specifier);
    if (specifier === "next/navigation") return { notFound: () => { throw new Error("404"); }, redirect: (href: string) => { throw new Error(`redirect ${href}`); } };
    if (specifier === "@/lib/catalogPagination") return paginationHelpers;
    if (specifier === "@/lib/articleSeo") return { articleSiteUrl: () => "https://school.test" };
    if (specifier === "@/lib/siteIndexingPolicy") return { isSiteIndexingEnabled: () => indexingEnabled };
    if (specifier === "@/lib/faqApi") return { listFaqsResult: async () => ({ data: faqItems }) };
    if (specifier === "@/lib/reviewsApi") return {
      listReviewsResult: async (filters: unknown) => {
        reviewCalls.push(filters);
        return { data: [], meta: { facets: { subjects: [] }, total: 0, totalPublished: 37, lastPage: 2, page: 1 } };
      },
      getReviewResult: async () => null,
    };
    if (specifier === "@/components/reviews/reviewSubjectOptions") return { ...reviewSubjectHelpers, reviewSubjectOptions: () => [] };
    if (specifier.endsWith(".css")) return {};
    if (specifier.startsWith("@/components/") || ["next/link", "next/form"].includes(specifier)) return { default: specifier };
    throw new Error(`Unexpected module: ${specifier}`);
  }, sandbox, sandbox.exports);
  return sandbox.exports;
}

function elements(value: unknown): Element[] {
  if (Array.isArray(value)) return value.flatMap(elements);
  if (!value || typeof value !== "object" || !("props" in value)) return [];
  const element = value as Element;
  return [element, ...elements(element.props.children), ...elements(element.props.search)];
}
const props = (params: Params) => ({ searchParams: Promise.resolve(params) });
const reviewSubjectHelpers = await load("../components/reviews/reviewSubjectOptions.ts");
const faq = await load("../app/(site)/faq/page.tsx") as unknown as PageModule;
const reviews = await load("../app/(site)/reviews/page.tsx") as unknown as PageModule;

test("FAQ searches questions and answers with case folding and literal characters", async () => {
  const nodes = elements(await faq.default(props({ q: "  ДОСТУП  " })));
  const list = nodes.find((node) => node.type === "@/components/faq/FaqDetailsList");
  assert.deepEqual((list?.props.faqs as typeof faqItems).map((item) => item.id), [1, 2]);
  assert.ok(nodes.some((node) => node.props.query === "ДОСТУП"));
  const literal = elements(await faq.default(props({ q: "%" })));
  assert.deepEqual((literal.find((node) => node.type === "@/components/faq/FaqDetailsList")?.props.faqs as typeof faqItems).map((item) => item.id), [3]);
});

test("FAQ keeps category and query in navigation and offers a scoped reset", async () => {
  const nodes = elements(await faq.default(props({ q: "доступ", category: "courses" })));
  assert.ok(nodes.some((node) => node.props.name === "category" && node.props.value === "courses"));
  assert.ok(nodes.some((node) => node.props.href === "/faq?category=payment&q=%D0%B4%D0%BE%D1%81%D1%82%D1%83%D0%BF"));
  assert.deepEqual(nodes.find((node) => node.type === "@/components/content/CatalogSearchForm")?.props.feedback, { text: "Найдено ответов: 2", resetHref: "/faq?category=courses" });
  const empty = elements(await faq.default(props({ q: "отсутствует" })));
  assert.ok(empty.some((node) => node.props["data-ui"] === "faq.empty" && String(node.props.children).includes("ничего не найдено")));
  const blank = elements(await faq.default(props({ q: "  " })));
  assert.equal((blank.find((node) => node.type === "@/components/faq/FaqDetailsList")?.props.faqs as typeof faqItems).length, 3);
  for (const q of [["доступ"], "я".repeat(201)]) await assert.rejects(faq.default(props({ q })), /404/u);
});

test("reviews expose the common public form and preserve subject without page or review id", async () => {
  reviewCalls.length = 0;
  const nodes = elements(await reviews.default(props({ q: "  анатомия  ", subject: "anatomy", page: "2", review: "7" })));
  assert.deepEqual(reviewCalls[0], { subject: "anatomy", search: "анатомия", page: 2, perPage: 25 });
  const form = nodes.find((node) => node.type === "@/components/content/CatalogSearchForm");
  assert.equal(form?.props.action, "/reviews");
  assert.equal(form?.props.query, "анатомия");
  const fields = elements(form).filter((node) => node.type === "input");
  assert.deepEqual(fields.map((field) => [field.props.name, field.props.value]), [["subject", "anatomy"]]);
  assert.equal(nodes.some((node) => node.type === "@/components/reviews/AdminReviewSearch"), false);
  assert.ok(nodes.some((node) => node.type === "@/components/content/PageIntroLayout"));
});

test("reviews page size survives navigation, search, filters and clamped pages", async () => {
  reviewCalls.length = 0;
  const nodes = elements(await reviews.default(props({ q: "курс", subject: "anatomy", page: "2", per_page: "10", review: "7" })));
  assert.deepEqual(reviewCalls[0], { subject: "anatomy", search: "курс", page: 2, perPage: 10 });
  const form = nodes.find((node) => node.type === "@/components/content/CatalogSearchForm");
  assert.ok(elements(form).some((node) => node.props.name === "per_page" && node.props.value === 10));
  assert.equal((form?.props.feedback as { resetHref: string }).resetHref, "/reviews?subject=anatomy&per_page=10");
  assert.equal(nodes.find((node) => node.type === "@/components/reviews/ReviewsFilters")?.props.perPage, 10);
  const pager = nodes.find((node) => node.type === "@/components/content/CatalogPagination");
  assert.equal(pager?.props.nextHref, "/reviews?subject=anatomy&q=%D0%BA%D1%83%D1%80%D1%81&page=2&per_page=10");
  const sizes = pager?.props.pageSizes as Array<{ value: number; href: string }>;
  assert.deepEqual(sizes.map((size) => size.value), [10, 25, 50]);
  assert.equal(sizes.find((size) => size.value === 25)?.href, "/reviews?subject=anatomy&q=%D0%BA%D1%83%D1%80%D1%81");
  await assert.rejects(reviews.default(props({ subject: "anatomy", q: "курс", page: "99", per_page: "10", review: "7" })), { message: "redirect /reviews?subject=anatomy&q=%D0%BA%D1%83%D1%80%D1%81&page=2&per_page=10" });
  const fallback = elements(await reviews.default(props({ page: "bad" })));
  assert.equal(fallback.find((node) => node.type === "@/components/content/CatalogPagination")?.props.currentPage, 1);
});

test("reviews validate page size in render and metadata and noindex its self canonical", async () => {
  for (const per_page of ["", "24", "48", "1000", ["10", "20"]]) {
    await assert.rejects(reviews.default(props({ per_page })), /404/u);
    await assert.rejects(reviews.generateMetadata(props({ per_page })), /404/u);
  }
  indexingEnabled = true;
  const meta = await reviews.generateMetadata(props({ subject: "anatomy", q: "курс", page: "2", per_page: "10", review: "7" }));
  assert.deepEqual(meta.robots, { index: false, follow: true });
  assert.equal(meta.alternates?.canonical, "https://school.test/reviews?subject=anatomy&q=%D0%BA%D1%83%D1%80%D1%81&page=2&per_page=10");
  assert.equal((await reviews.generateMetadata(props({ per_page: "25" }))).alternates, undefined);
  indexingEnabled = false;
  assert.deepEqual((await reviews.generateMetadata(props({ per_page: "10" }))).robots, { index: false, follow: false });
  indexingEnabled = true;
});

test("FAQ and reviews search URLs are noindex and retain the global indexing gate", async () => {
  for (const page of [faq, reviews]) {
    indexingEnabled = true;
    assert.deepEqual((await page.generateMetadata(props({ q: "доступ" }))).robots, { index: false, follow: true });
    assert.equal((await page.generateMetadata(props({ q: "  " }))).robots, undefined);
    indexingEnabled = false;
    assert.deepEqual((await page.generateMetadata(props({ q: "доступ" }))).robots, { index: false, follow: false });
  }
  indexingEnabled = true;
});

test("shared search form submits an accessible GET query", async () => {
  const shared = await load("../components/content/CatalogSearchForm.tsx") as { default: (props: Record<string, unknown>) => Element };
  const nodes = elements(shared.default({ action: "/articles", id: "search", label: "Поиск по статьям", submitLabel: "Найти статьи", query: "кровь", maxLength: 120, dataUi: "articles.search", feedback: { text: "Найдено статей: 2", resetHref: "/articles?subject=anatomy" } }));
  assert.ok(nodes.some((node) => node.type === "next/form" && node.props.action === "/articles" && node.props.role === "search"));
  assert.ok(nodes.some((node) => node.type === "input" && node.props.name === "q" && node.props.defaultValue === "кровь" && node.props.maxLength === 120));
  assert.ok(nodes.some((node) => node.type === "input" && node.props.placeholder === "Поиск по содержимому"));
  assert.ok(nodes.some((node) => node.type === "label" && node.props.htmlFor === "search"));
  assert.ok(nodes.some((node) => node.type === "button" && node.props.type === "submit" && node.props["aria-label"] === "Найти статьи"));
  assert.ok(nodes.some((node) => node.props.role === "status" && node.props.children === "Найдено статей: 2"));
  assert.ok(nodes.some((node) => node.type === "next/link" && node.props.href === "/articles?subject=anatomy" && node.props["data-ui"] === "articles.search.reset"));
});

test("shared submit clears real filters only for a nonempty query and restores them for blank submit", async () => {
  const shared = await load("../components/content/CatalogSearchForm.tsx") as { default: (props: Record<string, unknown>) => Element };
  const form = shared.default({ action: "/articles", id: "search", label: "Поиск по статьям", submitLabel: "Найти статьи", query: "", maxLength: 120, dataUi: "articles.search" });
  const input = { value: "  " };
  const fields = [{ name: "subject", disabled: false }, { name: "category", disabled: false }, { name: "per_page", disabled: false }];
  const event = { currentTarget: { elements: { namedItem: () => input }, querySelectorAll: () => fields } };
  const submit = form.props.onSubmit as (event: unknown) => void;
  assert.deepEqual(fields.map((field) => field.disabled), [false, false, false]);
  submit(event);
  assert.deepEqual(fields.map((field) => field.disabled), [false, false, false]);
  input.value = "  сердце  ";
  assert.deepEqual(fields.map((field) => field.disabled), [false, false, false]);
  submit(event);
  assert.deepEqual(fields.map((field) => field.disabled), [true, true, false]);
  input.value = "";
  submit(event);
  assert.deepEqual(fields.map((field) => field.disabled), [false, false, false]);
});
