import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";
import ts from "typescript";

type ArticlesModule = Pick<typeof import("./articlesApi"), "normalizeArticleListResult" | "normalizeArticleDetail" | "articleSubjects" | "listArticlesResult" | "normalizeHomeAuthors" | "listHomeAuthors" | "findArticleAuthorProfile">;
async function loadModule(): Promise<ArticlesModule> {
  const source = await readFile(new URL("./articlesApi.ts", import.meta.url), "utf8");
  const output = ts.transpileModule(source, { compilerOptions: { esModuleInterop: true, module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText;
  const sandboxModule = { exports: {} as Record<string, unknown> };
  const faqModule = { exports: {} as Record<string, unknown> };
  const errorsModule = { exports: {} as Record<string, unknown> };
  const requireStub = (specifier: string): unknown => {
    if (specifier === "@/lib/apiBaseUrl") return { backendApiBaseUrl: () => "https://backend.test" };
    if (specifier === "@/lib/apiErrors") return errorsModule.exports;
    if (specifier === "@/lib/staticFrontendMode") return { isStaticFrontendModeEnabled: () => false };
    if (specifier === "@/lib/faqApi") return faqModule.exports;
    throw new Error(`Unexpected module: ${specifier}`);
  };
  const errorsSource = await readFile(new URL("./apiErrors.ts", import.meta.url), "utf8");
  const errorsOutput = ts.transpileModule(errorsSource, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText;
  Function("require", "module", "exports", errorsOutput)(requireStub, errorsModule, errorsModule.exports);
  const faqSource = await readFile(new URL("./faqApi.ts", import.meta.url), "utf8");
  const faqOutput = ts.transpileModule(faqSource, { compilerOptions: { esModuleInterop: true, module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText;
  Function("require", "module", "exports", faqOutput)(requireStub, faqModule, faqModule.exports);
  Function("require", "module", "exports", output)(requireStub, sandboxModule, sandboxModule.exports);
  return sandboxModule.exports as ArticlesModule;
}
const api = await loadModule();
const summary = { id: 1, slug: "study", title: "Учёба", description: null, category: { slug: "tips", title: "Советы" }, subject: { id: 1, slug: "anatomiya", title: "Анатомия" }, sortOrder: 1, publishedAt: null, updatedAt: null, excerpt: null, author: null, cover: null, tags: [] } as const;

test("validates article list and detail DTOs", () => {
  assert.equal(api.normalizeArticleListResult({ data: [summary], meta: { placement: "articles.index", limit: null, total: 1, page: 1, perPage: 24, lastPage: 1 } }, {}).data[0]?.slug, "study");
  assert.equal(api.normalizeArticleDetail({ data: { ...summary, body: "Текст", bodyFormat: "text", related: [], attachments: [], seoTitle: null, seoDescription: null } }).body, "Текст");
});

test("related FAQ detail reuses the public FAQ contract and supports the previous article response", () => {
  const detail = { ...summary, body: "Текст", bodyFormat: "text", related: [], attachments: [], seoTitle: null, seoDescription: null };
  const faq = { id: 9, slug: "study-question", question: "Как учиться?", answer: "Системно", category: null, subject: summary.subject, sortOrder: 1, publishedAt: null };
  assert.deepEqual(api.normalizeArticleDetail({ data: detail }).relatedFaqs, []);
  assert.deepEqual(api.normalizeArticleDetail({ data: { ...detail, relatedFaqs: [faq] } }).relatedFaqs, [faq]);
  for (const relatedFaqs of [null, {}, [{ ...faq, private: true }], [{ ...faq, id: -1 }], [{ ...faq, answer: null }], [{ ...faq, subject: { ...faq.subject, private: true } }]]) {
    assert.throws(() => api.normalizeArticleDetail({ data: { ...detail, relatedFaqs } }));
  }
  assert.throws(() => api.normalizeArticleListResult({ data: [{ ...summary, relatedFaqs: [faq] }] }, {}));
});

test("rejects unsafe media URLs and validates related articles", () => {
  assert.throws(() => api.normalizeArticleListResult({ data: [{ ...summary, cover: { url: "javascript:alert(1)", alt: "", width: null, height: null, variants: [] } }] }, {}));
  assert.throws(() => api.normalizeArticleDetail({ data: { ...summary, body: "<p>Текст</p>", bodyFormat: "html", related: [{ ...summary, id: -1 }], attachments: [], seoTitle: null, seoDescription: null } }));
});

test("validates responsive image manifests for covers and author photos", () => {
  const variant = { url: "https://media.test/article-media/variants/hash/v1/320.avif", format: "avif", width: 320, height: 240, bytes: 1400 };
  const cover = { url: "https://media.test/original.png", alt: "Схема", width: 640, height: 480, variants: [variant] };
  const author = { id: 3, name: "Автор", slug: "author", avatarUrl: cover.url, avatarVariants: [variant], photoUrl: cover.url, photoVariants: [variant], aboutUrl: "/about" };
  const result = api.normalizeArticleListResult({ data: [{ ...summary, cover, author }] }, {}).data[0];
  assert.equal(result?.cover?.variants[0]?.width, 320);
  assert.equal(result?.author?.photoVariants[0]?.format, "avif");
  assert.equal(result?.author?.id, 3);
  assert.equal(result?.author?.avatarVariants[0]?.format, "avif");
  const { variants: previousVariants, ...oldCover } = cover;
  assert.equal(previousVariants.length, 1);
  assert.deepEqual(api.normalizeArticleListResult({ data: [{ ...summary, cover: oldCover }] }, {}).data[0]?.cover?.variants, []);
  for (const invalid of [{ ...variant, width: 0 }, { ...variant, format: "svg" }, { ...variant, bytes: -1 }, { ...variant, url: "javascript:alert(1)" }, { ...variant, url: "https://media.test/a 320w,https://other.test/b" }]) {
    assert.throws(() => api.normalizeArticleListResult({ data: [{ ...summary, cover: { ...cover, variants: [invalid] } }] }, {}));
  }
});

test("home and article authors share public identity and reject private fields", async () => {
  const author = { id: 3, name: "Демо Автор 2", slug: "demo-author-8", avatarUrl: "/storage/avatar.webp", avatarVariants: [], photoUrl: "/storage/original.webp", photoVariants: [], aboutUrl: "/about" };
  const homeAuthor = { ...author, homeRoleText: "Преподаватель гистологии", cutoutKey: "demo-author-12" };
  const articleAuthor = api.normalizeArticleListResult({ data: [{ ...summary, author }] }, {}).data[0]?.author;
  const home = api.normalizeHomeAuthors({ data: [homeAuthor] });
  assert.equal(home[0]?.id, articleAuthor?.id);
  assert.equal(home[0]?.avatarUrl, articleAuthor?.avatarUrl);
  for (const extra of [{ user_id: 1 }, { email: "private@test.invalid" }, { roles: ["admin"] }, { bio: "private" }]) {
    assert.throws(() => api.normalizeHomeAuthors({ data: [{ ...homeAuthor, ...extra }] }));
    assert.throws(() => api.normalizeArticleListResult({ data: [{ ...summary, author: { ...author, ...extra } }] }, {}));
  }
  assert.throws(() => api.normalizeHomeAuthors({ data: [homeAuthor, homeAuthor] }));
  assert.throws(() => api.normalizeHomeAuthors({ data: [{ ...homeAuthor, cutoutKey: "../../other" }] }));
  assert.throws(() => api.normalizeHomeAuthors({ data: [{ ...homeAuthor, avatarUrl: "javascript:alert(1)" }] }));
  const previousFetch = globalThis.fetch;
  try {
    globalThis.fetch = async (url) => {
      assert.equal(String(url), "https://backend.test/api/v1/content-authors?placement=home");
      return new Response(JSON.stringify({ data: [homeAuthor] }));
    };
    assert.deepEqual(await api.listHomeAuthors(), home);
    globalThis.fetch = async () => new Response("", { status: 503 });
    await assert.rejects(api.listHomeAuthors());
  } finally {
    globalThis.fetch = previousFetch;
  }
});

test("optional article author profile uses the shared public cache and preserves article identity", async () => {
  const author = { id: 3, name: "Автор статьи", slug: "article-author", avatarUrl: null, avatarVariants: [], photoUrl: null, photoVariants: [], aboutUrl: "/about" } as const;
  const profile = { ...author, name: "Имя в справочнике", homeRoleText: "Преподаватель", cutoutKey: "demo-author-12" };
  const article = api.normalizeArticleDetail({ data: { ...summary, author, body: "Текст", bodyFormat: "text", related: [], attachments: [], seoTitle: null, seoDescription: null } });
  const previousFetch = globalThis.fetch;
  try {
    globalThis.fetch = async (url, init) => {
      assert.equal(String(url), "https://backend.test/api/v1/content-authors?placement=home");
      assert.deepEqual(init, { next: { revalidate: 120 } });
      return Response.json({ data: [profile] });
    };
    assert.deepEqual(await api.findArticleAuthorProfile(article.author), profile);
    assert.deepEqual(article.author, author);
    globalThis.fetch = async () => Response.json({ data: [{ ...profile, id: 4 }] });
    assert.equal(await api.findArticleAuthorProfile(article.author), null);
    globalThis.fetch = async () => { throw new Error("should not fetch without an author"); };
    assert.equal(await api.findArticleAuthorProfile(null), null);
  } finally {
    globalThis.fetch = previousFetch;
  }
});

test("optional author lookup survives HTTP/network/DTO failures while strict home lookup still rejects them", async () => {
  const author = { id: 3, name: "Автор статьи", slug: "article-author", avatarUrl: null, avatarVariants: [], photoUrl: null, photoVariants: [], aboutUrl: null };
  const profile = { ...author, homeRoleText: "Преподаватель", cutoutKey: "demo-author-12" };
  const previousFetch = globalThis.fetch;
  const previousError = console.error;
  const messages: unknown[][] = [];
  try {
    console.error = (...args: unknown[]) => { messages.push(args); };
    const failures: ReadonlyArray<typeof fetch> = [
      async () => new Response("", { status: 429 }),
      async () => new Response("", { status: 503 }),
      async () => { throw new TypeError("network failure"); },
      async () => Response.json({ data: [{ ...profile, email: "private@test.invalid" }] }),
      async () => Response.json({ data: [{ ...profile, cutoutKey: "unknown" }] }),
    ];
    for (const fetchFailure of failures) {
      globalThis.fetch = fetchFailure;
      assert.equal(await api.findArticleAuthorProfile(author), null);
      await assert.rejects(api.listHomeAuthors());
      assert.equal(author.name, "Автор статьи");
    }
    assert.equal(messages.length, failures.length);
    assert.equal(JSON.stringify(messages).includes("private@test.invalid"), false);
    const dynamicError = Object.assign(new Error("dynamic usage"), { digest: "DYNAMIC_SERVER_USAGE" });
    globalThis.fetch = async () => { throw dynamicError; };
    await assert.rejects(api.findArticleAuthorProfile(author), (error: unknown) => error === dynamicError);
  } finally {
    globalThis.fetch = previousFetch;
    console.error = previousError;
  }
});

test("rejects malformed article primitives and unexpected protected fields", () => {
  assert.throws(() => api.normalizeArticleListResult({ data: [{ ...summary, category: { slug: null, title: "Советы" } }] }, {}));
  assert.throws(() => api.normalizeArticleDetail({ data: { ...summary, body: null, seoTitle: null, seoDescription: null } }));
  assert.throws(() => api.normalizeArticleDetail({ data: { ...summary, body: "Текст", seoTitle: null, seoDescription: null, draftBody: "secret" } }));
});

test("validates canonical subjects in summary, detail and related DTOs", () => {
  assert.equal(api.normalizeArticleListResult({ data: [{ ...summary, subject: null }] }, {}).data[0]?.subject, null);
  const detail = { ...summary, body: "Текст", bodyFormat: "text", related: [summary], attachments: [], seoTitle: null, seoDescription: null };
  assert.deepEqual(api.normalizeArticleDetail({ data: detail }).related[0]?.subject, summary.subject);
  for (const subject of [undefined, { ...summary.subject, id: "1" }, { ...summary.subject, id: 0 }, { ...summary.subject, slug: "" }, { ...summary.subject, title: null }, { ...summary.subject, private: true }]) {
    assert.throws(() => api.normalizeArticleListResult({ data: [{ ...summary, subject }] }, {}));
    assert.throws(() => api.normalizeArticleDetail({ data: { ...detail, related: [{ ...summary, subject }] } }));
  }
});

test("fetches all active subject counts and keeps subject/category query namespaces", async () => {
  const previousFetch = globalThis.fetch;
  const urls: string[] = [];
  const subjects = [{ ...summary.subject, count: 0 }];
  try {
    globalThis.fetch = async (input) => {
      const url = String(input);
      urls.push(url);
      return new Response(JSON.stringify({ data: new URL(url).pathname.endsWith("/article-subjects") ? subjects : [] }));
    };
    assert.deepEqual(await api.articleSubjects(), subjects);
    await api.listArticlesResult({ subject: "anatomiya", page: 2, limit: 24 });
    await api.listArticlesResult({ category: "anatomiya", page: 2, limit: 24 });
    assert.equal(urls[0], "https://backend.test/api/v1/article-subjects");
    assert.equal(new URL(urls[1]).searchParams.get("subject"), "anatomiya");
    assert.equal(new URL(urls[1]).searchParams.has("category"), false);
    assert.equal(new URL(urls[2]).searchParams.get("category"), "anatomiya");
    assert.equal(new URL(urls[2]).searchParams.has("subject"), false);
    assert.deepEqual(await api.articleSubjects("сердце & 100%_\\"), subjects);
    const countedUrl = new URL(urls[3]);
    assert.equal(countedUrl.pathname, "/api/v1/article-subjects");
    assert.equal(countedUrl.searchParams.get("q"), "сердце & 100%_\\");
    assert.equal(countedUrl.searchParams.has("subject"), false);
    for (const invalid of [{ ...subjects[0], count: -1 }, { ...subjects[0], id: "1" }, { ...subjects[0], extra: true }]) {
      globalThis.fetch = async () => new Response(JSON.stringify({ data: [invalid] }));
      await assert.rejects(api.articleSubjects());
    }
  } finally {
    globalThis.fetch = previousFetch;
  }
});
