import assert from "node:assert/strict";
import test from "node:test";
const modulePath = "./articleSeo.ts";
const { articleBreadcrumbItems, articleStructuredData, safeJsonLd, articleListPath, articleSiteUrl, articleDescription }: typeof import("./articleSeo") = await import(modulePath);
const article = {
  id: 1, slug: "study", title: "Заголовок </script>", description: "Анонс", excerpt: "Краткий текст", category: { slug: "anatomy", title: "Анатомия" },
  subject: { id: 1, slug: "anatomiya", title: "Анатомия" },
  sortOrder: 0, publishedAt: "2026-10-19T10:39:59Z", updatedAt: "2026-10-19T10:39:59Z", author: null, cover: null, tags: [],
  body: "<p>Текст</p>", bodyFormat: "html" as const, related: [], relatedFaqs: [], attachments: [], seoTitle: null, seoDescription: null,
};

test("visible article breadcrumbs and JSON-LD use the same title and subject without changing the article URL", () => {
  for (const subject of [article.subject, null]) {
    const entry = { ...article, subject, seoTitle: "SEO heading" };
    const crumbs = articleBreadcrumbItems(entry);
    assert.deepEqual(crumbs.map((crumb) => crumb.label), subject
      ? ["Главная", "Статьи", "Анатомия", article.title]
      : ["Главная", "Статьи", article.title]);
    assert.equal(crumbs.at(-1)?.href, "/articles/study");
    if (subject) assert.equal(crumbs[2].href, "/articles?subject=anatomiya");
    const graph = articleStructuredData(entry, "https://school.test")["@graph"];
    const trail = graph.find((node) => node["@type"] === "BreadcrumbList");
    assert.ok(trail && "itemListElement" in trail);
    assert.deepEqual(trail.itemListElement, crumbs.map((crumb, index) => ({
      "@type": "ListItem", position: index + 1, name: crumb.label, item: new URL(crumb.href, "https://school.test").href,
    })));
  }
});

test("SEO defaults preserve dates, null attribution and matching breadcrumbs", () => {
  const data = articleStructuredData(article, "https://school.test");
  assert.equal(articleDescription(article), "Краткий текст");
  const entry = data["@graph"][0];
  assert.ok("datePublished" in entry);
  assert.equal(entry.datePublished, article.publishedAt);
  assert.equal("author" in data["@graph"][0], false);
  assert.match(JSON.stringify(data), /articles\?subject=anatomiya/u);
  assert.equal(JSON.stringify(data).includes("category="), false);
  assert.equal(safeJsonLd(data).includes("</script>"), false);
  assert.deepEqual(JSON.parse(safeJsonLd(data)), data);
});

test("canonical paths distinguish subject and historical category with pagination", () => {
  assert.equal(articleListPath(), "/articles");
  assert.equal(articleListPath({ subject: "anatomiya" }, 2), "/articles?subject=anatomiya&page=2");
  assert.equal(articleListPath({ category: "anatomiya" }, 2), "/articles?category=anatomiya&page=2");
  assert.equal(articleListPath({ subject: "anatomiya", q: "курс" }, 2, 10), "/articles?subject=anatomiya&q=%D0%BA%D1%83%D1%80%D1%81&page=2&per_page=10");
  assert.equal(articleListPath({}, 1, 25), "/articles");
  assert.equal(JSON.stringify(articleStructuredData({ ...article, subject: null }, "https://school.test")).includes("?subject="), false);
});

test("public indexing requires an explicit trusted origin", () => {
  const previousUrl = process.env.SITE_URL;
  const previousIndexing = process.env.SITE_INDEXING_ENABLED;
  try {
    delete process.env.SITE_URL;
    process.env.SITE_INDEXING_ENABLED = "true";
    assert.throws(articleSiteUrl, /SITE_URL/u);
    process.env.SITE_URL = "https://school.test/path";
    assert.throws(articleSiteUrl);
    process.env.SITE_URL = "https://school.test";
    assert.equal(articleSiteUrl(), "https://school.test");
  } finally {
    if (previousUrl === undefined) delete process.env.SITE_URL; else process.env.SITE_URL = previousUrl;
    if (previousIndexing === undefined) delete process.env.SITE_INDEXING_ENABLED; else process.env.SITE_INDEXING_ENABLED = previousIndexing;
  }
});
