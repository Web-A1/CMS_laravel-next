import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";
import ts from "typescript";

type ReviewsModule = Pick<typeof import("./reviewsApi"), "normalizeReviews" | "normalizeReviewResult" | "listReviewsResult" | "getReviewResult">;
async function loadModule(browserFetch?: (url: string) => Promise<unknown>): Promise<ReviewsModule> {
  const source = await readFile(new URL("./reviewsApi.ts", import.meta.url), "utf8");
  const output = ts.transpileModule(source, { compilerOptions: { esModuleInterop: true, module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText;
  const sandboxModule = { exports: {} as Record<string, unknown> };
  const requireStub = (specifier: string): unknown => {
    if (specifier === "@/lib/apiBaseUrl") return { backendApiBaseUrl: () => "https://backend.test", browserApiBaseUrl: () => "/browser-proxy" };
    if (specifier === "@/lib/apiErrors") return { apiUnavailableError: () => new Error(), rethrowNextDynamicServerError: () => undefined };
    if (specifier === "@/lib/staticFrontendMode") return { isStaticFrontendModeEnabled: () => false };
    throw new Error(`Unexpected module: ${specifier}`);
  };
  Function("require", "module", "exports", "window", "fetch", "process", output)(requireStub, sandboxModule, sandboxModule.exports, browserFetch ? {} : undefined, browserFetch, { env: { NEXT_PUBLIC_API_BASE_URL: "/browser-proxy" } });
  return sandboxModule.exports as ReviewsModule;
}
const api = await loadModule();

test("homepage requests selected reviews while catalog remains unfiltered", async () => {
  const urls: string[] = [];
  const browserApi = await loadModule(async (url) => {
    urls.push(url);
    return { ok: true, json: async () => ({ data: [], meta: { ...meta, total: 0, lastPage: 1 } }) };
  });
  await browserApi.listReviewsResult({ placement: "home" });
  await browserApi.listReviewsResult();
  assert.equal(new URL(urls[0], "https://school.test").searchParams.get("placement"), "home");
  assert.equal(new URL(urls[1], "https://school.test").searchParams.has("placement"), false);
});
const review = {
  id: 1, displayName: "Студент", authorPhotoUrl: null, text: "Отзыв", reviewWrittenAt: null, publishedAt: null, source: "vk", importOrigin: "vk",
  subject: { id: 2, slug: "anatomy", title: "Анатомия" }, course: null,
  sourceLinks: [{ type: "vk_webarchive", label: "Источник", url: "https://example.invalid/demo" }],
  media: [{ id: "review_media:1", type: "image", url: "/media/review.webp", alt: null, width: 640, height: 480, provenance: null, sourceType: null }],
} as const;

test("manual public source links are accepted and unsafe targets remain rejected", () => {
  const manual = { ...review, sourceLinks: [{ type: "manual", label: "Источник", url: "https://example.org/review/42" }] };
  assert.deepEqual(api.normalizeReviews({ data: [manual], meta }, {}).data[0].sourceLinks, manual.sourceLinks);
  for (const url of ["javascript:alert(1)", "data:text/html,hello", "//example.org/review"]) {
    assert.throws(() => api.normalizeReviews({ data: [{ ...manual, sourceLinks: [{ type: "manual", label: "Источник", url }] }], meta }, {}));
  }
});
const meta = { limit: 8, page: 1, perPage: 8, total: 1, lastPage: 1, sort: "latest", totalPublished: 1, filters: { subject: null, course: null }, facets: { subjects: [], courses: [] } } as const;

test("browser filter lookup and detail navigation use the same browser API origin", async () => {
  const urls: string[] = [];
  const browserApi = await loadModule(async (url) => {
    urls.push(url);
    return { ok: true, json: async () => url.includes("/reviews/1") ? { data: review } : { data: [review], meta } };
  });
  await browserApi.listReviewsResult({ subject: "anatomy", perPage: 1 });
  await browserApi.getReviewResult(1, { subject: "anatomy" });
  assert.equal(urls.length, 2);
  assert.ok(urls.every((url) => url.startsWith("/browser-proxy/api/v1/reviews")));
  assert.ok(urls.every((url) => new URL(url, "https://school.test").searchParams.get("subject") === "anatomy"));
});

test("list and modal detail preserve the literal search criterion alongside subject", async () => {
  const urls: string[] = [];
  const browserApi = await loadModule(async (url) => {
    urls.push(url);
    return { ok: true, json: async () => url.includes("/reviews/1?") ? { data: review } : { data: [review], meta } };
  });
  const search = "Анна 100% a_b";
  await browserApi.listReviewsResult({ search, subject: "anatomy", page: 2, perPage: 30 });
  await browserApi.getReviewResult(1, { search, subject: "anatomy" });
  for (const url of urls) {
    const params = new URL(url, "https://school.test").searchParams;
    assert.equal(params.get("search"), search);
    assert.equal(params.get("subject"), "anatomy");
  }
  assert.equal(new URL(urls[0], "https://school.test").searchParams.get("page"), "2");
});

test("validates nested reviews and navigation DTOs", () => {
  assert.equal(api.normalizeReviews({ data: [review], meta }, {}).data[0]?.media[0]?.width, 640);
  assert.equal(api.normalizeReviewResult({ data: review, meta: { navigation: { previousId: null, nextId: 2, position: 1, total: 2, filters: { subject: null, course: null } } } }).navigation?.nextId, 2);
});

test("rejects malformed media, facets and protected fields", () => {
  assert.throws(() => api.normalizeReviews({ data: [{ ...review, id: Number.MAX_SAFE_INTEGER + 1 }], meta }, {}));
  assert.throws(() => api.normalizeReviews({ data: [{ ...review, media: [{ ...review.media[0], width: "640" }] }], meta }, {}));
  assert.throws(() => api.normalizeReviews({ data: [review], meta: { ...meta, facets: { subjects: null, courses: [] } } }, {}));
  assert.throws(() => api.normalizeReviews({ data: [{ ...review, privateEvidence: "secret" }], meta }, {}));
  assert.throws(() => api.normalizeReviewResult({ data: review, meta: { navigation: { previousId: null, nextId: null, position: 0, total: 1, filters: { subject: null, course: null } } } }));
});

test("accepts nullable author photos and rejects unsafe or malformed photo URLs", () => {
  const withPhoto = { ...review, authorPhotoUrl: "https://backend.test/storage/review-authors/author.webp" };
  assert.equal(api.normalizeReviews({ data: [withPhoto], meta }, {}).data[0]?.authorPhotoUrl, withPhoto.authorPhotoUrl);
  assert.equal(api.normalizeReviews({ data: [review], meta }, {}).data[0]?.authorPhotoUrl, null);
  for (const authorPhotoUrl of ["javascript:alert(1)", "//other.test/photo.jpg", "https://user:secret@other.test/a.jpg", 123, ""]) {
    assert.throws(() => api.normalizeReviews({ data: [{ ...review, authorPhotoUrl }], meta }, {}));
  }
});

test("normalizes a legacy review without the additive photo field during deployment", () => {
  const legacyReview: Record<string, unknown> = { ...review };
  delete legacyReview.authorPhotoUrl;
  assert.equal(api.normalizeReviews({ data: [legacyReview], meta }, {}).data[0]?.authorPhotoUrl, null);
  assert.equal(api.normalizeReviewResult({ data: legacyReview }).data?.authorPhotoUrl, null);
  assert.throws(() => api.normalizeReviews({ data: [{ ...legacyReview, privateEvidence: "secret" }], meta }, {}));
});

test("accepts ready variant pairs and rejects unsafe, duplicate or incomplete manifests", () => {
  const variants = (["avif", "webp"] as const).map((format) => ({ url: `/storage/review-screenshots/id/192.${format}`, format, width: 192, height: 300 }));
  const payload = { ...review, authorPhotoVariants: [], media: [{ ...review.media[0], variants }] };
  assert.equal(api.normalizeReviews({ data: [payload], meta }, {}).data[0]?.media[0]?.variants?.length, 2);
  assert.deepEqual(api.normalizeReviewResult({ data: review }).data?.media[0]?.variants, []);
  for (const malformed of [variants.slice(0, 1), [...variants, variants[0]], [{ ...variants[0], url: "javascript:alert(1)" }, variants[1]],
    [{ ...variants[0], source_path: "private/source" }, variants[1]], [{ ...variants[0], width: 0 }, variants[1]]]) {
    assert.throws(() => api.normalizeReviews({ data: [{ ...review, media: [{ ...review.media[0], variants: malformed }] }], meta }, {}));
  }
});
