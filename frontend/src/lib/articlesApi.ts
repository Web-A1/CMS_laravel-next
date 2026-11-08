import { backendApiBaseUrl } from "@/lib/apiBaseUrl";
import { apiUnavailableError, rethrowNextDynamicServerError } from "@/lib/apiErrors";
import { isStaticFrontendModeEnabled } from "@/lib/staticFrontendMode";
import { normalizeFaqResult, type PublicFaqItem } from "@/lib/faqApi";

export type ArticlePlacement = "articles.index";

export interface PublicArticleCategory {
  readonly slug: string;
  readonly title: string;
}

export interface ArticleCategoryCount extends PublicArticleCategory { readonly count: number }
export interface ArticleSubject extends PublicArticleCategory { readonly id: number }
export interface ArticleSubjectCount extends ArticleSubject { readonly count: number }
export interface ArticleAuthor {
  readonly id: number;
  readonly name: string;
  readonly slug: string;
  readonly avatarUrl: string | null;
  readonly avatarVariants: ReadonlyArray<ArticleImageVariant>;
  readonly photoUrl: string | null;
  readonly photoVariants: ReadonlyArray<ArticleImageVariant>;
  readonly aboutUrl: string | null;
}
export const teacherCutoutKeys = ["demo-author-6", "demo-author-12", "demo-author-14", "demo-author-11", "demo-author-5", "demo-author-13", "demo-author-3", "demo-author-7"] as const;
export type TeacherCutoutKey = typeof teacherCutoutKeys[number];
export interface HomeAuthor extends ArticleAuthor {
  readonly homeRoleText: string;
  readonly cutoutKey: TeacherCutoutKey;
}
export interface ArticleImageVariant {
  readonly url: string;
  readonly format: "avif" | "webp";
  readonly width: number;
  readonly height: number;
  readonly bytes: number;
}
export interface ArticleCover {
  readonly url: string;
  readonly alt: string;
  readonly width: number | null;
  readonly height: number | null;
  readonly variants: ReadonlyArray<ArticleImageVariant>;
}

export interface PublicArticleSummary {
  readonly id: number;
  readonly slug: string;
  readonly title: string;
  readonly description: string | null;
  readonly category: PublicArticleCategory | null;
  readonly subject: ArticleSubject | null;
  readonly sortOrder: number;
  readonly publishedAt: string | null;
  readonly updatedAt: string | null;
  readonly excerpt: string | null;
  readonly author: ArticleAuthor | null;
  readonly cover: ArticleCover | null;
  readonly tags: ReadonlyArray<string>;
}

export interface PublicArticleDetail extends PublicArticleSummary {
  readonly attachments: ReadonlyArray<{ readonly url: string; readonly name: string; readonly bytes: number; readonly mime: string | null }>;
  readonly body: string;
  readonly seoTitle: string | null;
  readonly seoDescription: string | null;
  readonly bodyFormat: "html" | "text";
  readonly related: ReadonlyArray<PublicArticleSummary>;
  readonly relatedFaqs: ReadonlyArray<PublicFaqItem>;
}

export interface ArticleFilters {
  readonly q?: string;
  readonly subject?: string;
  readonly category?: string;
  readonly limit?: number;
  readonly page?: number;
  readonly fetchMode?: "live" | "revalidate";
  readonly revalidate?: number;
}

export interface ArticleMeta {
  readonly placement: ArticlePlacement;
  readonly limit: number | null;
  readonly total: number;
  readonly page: number;
  readonly perPage: number;
  readonly lastPage: number;
}

export interface ArticleListResult {
  readonly data: ReadonlyArray<PublicArticleSummary>;
  readonly meta: ArticleMeta;
}

type ArticleFetchInit = RequestInit & {
  readonly next?: {
    readonly revalidate: number;
  };
};

type JsonRecord = Record<string, unknown>;

function isRecord(value: unknown): value is JsonRecord {
  return typeof value === "object" && value !== null && !Array.isArray(value);
}

function hasExactKeys(value: JsonRecord, keys: ReadonlyArray<string>): boolean {
  const allowed = new Set(keys);
  return keys.every((key) => Object.hasOwn(value, key)) && Object.keys(value).every((key) => allowed.has(key));
}

function isInteger(value: unknown, minimum = 0): value is number {
  return Number.isInteger(value) && (value as number) >= minimum;
}

function isNullableString(value: unknown): value is string | null {
  return value === null || typeof value === "string";
}

function parseCategory(value: unknown): PublicArticleCategory | null {
  if (value === null) return null;
  if (
    !isRecord(value) ||
    !hasExactKeys(value, ["slug", "title"]) ||
    typeof value.slug !== "string" || value.slug === "" ||
    typeof value.title !== "string" || value.title === ""
  ) {
    throw new Error("unexpected response payload");
  }
  return { slug: value.slug, title: value.title };
}

function buildQuery(filters: ArticleFilters): string {
  const params = new URLSearchParams();

  if (filters.category) params.set("category", filters.category);
  if (filters.subject) params.set("subject", filters.subject);
  if (filters.q) params.set("q", filters.q);
  if (filters.limit) params.set("limit", String(filters.limit));
  if (filters.page) params.set("page", String(filters.page));

  return params.toString();
}

function defaultArticleMeta(data: ReadonlyArray<PublicArticleSummary>, filters: ArticleFilters): ArticleMeta {
  return {
    placement: "articles.index",
    limit: filters.limit ?? null,
    total: data.length,
    page: filters.page ?? 1,
    perPage: filters.limit ?? Math.max(data.length, 1),
    lastPage: 1,
  };
}

function emptyArticleListResult(filters: ArticleFilters): ArticleListResult {
  const data: ReadonlyArray<PublicArticleSummary> = [];

  return {
    data,
    meta: defaultArticleMeta(data, filters),
  };
}

const summaryKeys = ["id", "slug", "title", "description", "category", "subject", "sortOrder", "publishedAt", "updatedAt", "excerpt", "author", "cover", "tags"];

function parseSubject(value: unknown): ArticleSubject | null {
  if (value === null) return null;
  if (!isRecord(value) || !hasExactKeys(value, ["id", "slug", "title"]) || !isInteger(value.id, 1)) {
    throw new Error("unexpected article subject");
  }
  const subject = parseCategory({ slug: value.slug, title: value.title });
  if (!subject) throw new Error("missing article subject");
  return { id: value.id, ...subject };
}

function safeMediaUrl(value: unknown): value is string {
  return typeof value === "string" && (/^\/(?!\/)/u.test(value) || /^https?:\/\//u.test(value));
}

function parseAuthor(value: unknown): ArticleAuthor | null {
  if (value === null) return null;
  if (!isRecord(value) || !hasExactKeys(value, ["id", "name", "slug", "avatarUrl", "avatarVariants", "photoUrl", "photoVariants", "aboutUrl"]) ||
    !isInteger(value.id, 1) || typeof value.name !== "string" || !value.name || typeof value.slug !== "string" || !value.slug ||
    (value.avatarUrl !== null && !safeMediaUrl(value.avatarUrl)) ||
    (value.photoUrl !== null && !safeMediaUrl(value.photoUrl)) ||
    (value.aboutUrl !== null && value.aboutUrl !== "/about")) throw new Error("unexpected article author");
  return { id: value.id, name: value.name, slug: value.slug, avatarUrl: value.avatarUrl, avatarVariants: parseImageVariants(value.avatarVariants), photoUrl: value.photoUrl, photoVariants: parseImageVariants(value.photoVariants), aboutUrl: value.aboutUrl };
}

export function normalizeHomeAuthors(payload: unknown): ReadonlyArray<HomeAuthor> {
  if (!isRecord(payload) || !Array.isArray(payload.data)) throw new Error("unexpected home authors payload");
  const seen = new Set<number>();
  return payload.data.map((value: unknown) => {
    if (!isRecord(value) || !hasExactKeys(value, ["id", "name", "slug", "avatarUrl", "avatarVariants", "photoUrl", "photoVariants", "aboutUrl", "homeRoleText", "cutoutKey"]) ||
      typeof value.homeRoleText !== "string" || !value.homeRoleText || typeof value.cutoutKey !== "string" ||
      !teacherCutoutKeys.some((key) => key === value.cutoutKey)) throw new Error("unexpected home author");
    const { homeRoleText, cutoutKey, ...profile } = value;
    const author = parseAuthor(profile);
    if (!author || seen.has(author.id)) throw new Error("duplicate home author");
    seen.add(author.id);
    return { ...author, homeRoleText, cutoutKey: cutoutKey as TeacherCutoutKey };
  });
}

export async function listHomeAuthors(filters: Pick<ArticleFilters, "fetchMode" | "revalidate"> = {}): Promise<ReadonlyArray<HomeAuthor>> {
  try {
    const response = await fetch(`${backendApiBaseUrl()}/api/v1/content-authors?placement=home`, articleFetchInit(filters));
    if (!response.ok) throw new Error(`request failed with HTTP ${response.status}`);
    return normalizeHomeAuthors(await response.json());
  } catch (error) {
    rethrowNextDynamicServerError(error);
    if (isStaticFrontendModeEnabled()) return [];
    throw apiUnavailableError("Content authors API", error instanceof Error ? error.message : "request failed");
  }
}

export async function findArticleAuthorProfile(author: ArticleAuthor | null): Promise<HomeAuthor | null> {
  if (!author) return null;
  try {
    // Share this public directory across article requests using the articles' 120s cache lifetime.
    const authors = await listHomeAuthors({ fetchMode: "revalidate" });
    return authors.find((profile) => profile.id === author.id) ?? null;
  } catch (error) {
    rethrowNextDynamicServerError(error);
    console.error("Optional article author profile unavailable");
    return null;
  }
}

function parseImageVariants(value: unknown): ReadonlyArray<ArticleImageVariant> {
  if (!Array.isArray(value)) throw new Error("unexpected article image variants");
  return value.map((variant: unknown) => {
    if (!isRecord(variant) || !hasExactKeys(variant, ["url", "format", "width", "height", "bytes"]) ||
      !safeMediaUrl(variant.url) || /[\s,]/u.test(variant.url) || (variant.format !== "avif" && variant.format !== "webp") ||
      !isInteger(variant.width, 1) || !isInteger(variant.height, 1) || !isInteger(variant.bytes, 1)) {
      throw new Error("unexpected article image variant");
    }
    return { url: variant.url, format: variant.format, width: variant.width, height: variant.height, bytes: variant.bytes };
  });
}

function parseCover(value: unknown): ArticleCover | null {
  if (value === null) return null;
  if (!isRecord(value) || !hasExactKeys(value, ["url", "alt", "width", "height", ...(Object.hasOwn(value, "variants") ? ["variants"] : [])]) ||
    !safeMediaUrl(value.url) || typeof value.alt !== "string" ||
    (value.width !== null && !isInteger(value.width, 1)) || (value.height !== null && !isInteger(value.height, 1))) throw new Error("unexpected article cover");
  return { url: value.url, alt: value.alt, width: value.width, height: value.height, variants: parseImageVariants(Object.hasOwn(value, "variants") ? value.variants : []) };
}

function normalizeArticleSummary(item: unknown): PublicArticleSummary {
  if (
    !isRecord(item) ||
    !hasExactKeys(item, summaryKeys) ||
    !isInteger(item.id, 1) ||
    typeof item.slug !== "string" || item.slug === "" ||
    typeof item.title !== "string" || item.title === "" ||
    !isNullableString(item.description) ||
    !isInteger(item.sortOrder) ||
    !isNullableString(item.publishedAt) || !isNullableString(item.updatedAt) || !isNullableString(item.excerpt) ||
    !Array.isArray(item.tags) || !item.tags.every((tag: unknown) => typeof tag === "string")
  ) {
    throw new Error("unexpected response payload");
  }

  return {
    id: item.id,
    slug: item.slug,
    title: item.title,
    description: item.description ?? null,
    category: parseCategory(item.category),
    subject: parseSubject(item.subject),
    sortOrder: item.sortOrder,
    publishedAt: item.publishedAt ?? null,
    updatedAt: item.updatedAt,
    excerpt: item.excerpt,
    author: parseAuthor(item.author),
    cover: parseCover(item.cover),
    tags: item.tags,
  };
}

export function normalizeArticleListResult(payload: unknown, filters: ArticleFilters): ArticleListResult {
  if (!isRecord(payload) || !Array.isArray(payload.data)) {
    throw new Error("unexpected response payload");
  }

  const data = payload.data.map(normalizeArticleSummary);
  const fallbackMeta = defaultArticleMeta(data, filters);
  const meta = payload.meta;
  if (
    meta !== undefined &&
    (!isRecord(meta) ||
      !hasExactKeys(meta, ["placement", "limit", "total", "page", "perPage", "lastPage"]) ||
      meta.placement !== "articles.index" ||
      (meta.limit !== null && !isInteger(meta.limit, 1)) ||
      !isInteger(meta.total) || !isInteger(meta.page, 1) || !isInteger(meta.perPage, 1) || !isInteger(meta.lastPage, 1))
  ) {
    throw new Error("unexpected response payload");
  }

  return {
    data,
    meta: {
      placement: "articles.index",
      limit: meta ? (meta.limit as number | null) : fallbackMeta.limit,
      total: meta ? (meta.total as number) : fallbackMeta.total,
      page: meta ? (meta.page as number) : fallbackMeta.page,
      perPage: meta ? (meta.perPage as number) : fallbackMeta.perPage,
      lastPage: meta ? (meta.lastPage as number) : fallbackMeta.lastPage,
    },
  };
}

export function normalizeArticleDetail(payload: unknown): PublicArticleDetail {
  if (!isRecord(payload) || !isRecord(payload.data)) {
    throw new Error("unexpected response payload");
  }

  const item = payload.data;
  if (
    !hasExactKeys(item, [...summaryKeys, "body", "bodyFormat", "related", "attachments", "seoTitle", "seoDescription", ...(Object.hasOwn(item, "relatedFaqs") ? ["relatedFaqs"] : [])]) ||
    typeof item.body !== "string" ||
    !isNullableString(item.seoTitle) ||
    !isNullableString(item.seoDescription) || !["html", "text"].includes(String(item.bodyFormat)) || !Array.isArray(item.related) || !Array.isArray(item.attachments)
  ) {
    throw new Error("unexpected response payload");
  }

  const summary = normalizeArticleSummary(Object.fromEntries(summaryKeys.map((key) => [key, item[key]])));
  return {
    ...summary,
    body: item.body,
    seoTitle: item.seoTitle,
    seoDescription: item.seoDescription,
    bodyFormat: item.bodyFormat as "html" | "text",
    related: item.related.map(normalizeArticleSummary),
    relatedFaqs: normalizeFaqResult({ data: Object.hasOwn(item, "relatedFaqs") ? item.relatedFaqs : [] }, { placement: "faq.index" }).data,
    attachments: item.attachments.map((attachment: unknown) => {
      if (!isRecord(attachment) || !hasExactKeys(attachment, ["url", "name", "bytes", "mime"]) ||
        !safeMediaUrl(attachment.url) || typeof attachment.name !== "string" || !isInteger(attachment.bytes) || !isNullableString(attachment.mime)) throw new Error("unexpected article attachment");
      return { url: attachment.url, name: attachment.name, bytes: attachment.bytes, mime: attachment.mime };
    }),
  };
}

export async function articleCategories(): Promise<ReadonlyArray<ArticleCategoryCount>> {
  const response = await fetch(`${backendApiBaseUrl()}/api/v1/articles/categories`, { cache: "no-store" });
  if (!response.ok) throw apiUnavailableError("Articles API", `HTTP ${response.status}`);
  const payload: unknown = await response.json();
  if (!isRecord(payload) || !Array.isArray(payload.data)) throw new Error("unexpected categories payload");
  return payload.data.map((item: unknown) => {
    if (!isRecord(item) || !hasExactKeys(item, ["slug", "title", "count"]) || !isInteger(item.count)) throw new Error("unexpected category count");
    const category = parseCategory({ slug: item.slug, title: item.title });
    if (!category) throw new Error("missing category");
    return { ...category, count: item.count };
  });
}

export async function articleSubjects(query?: string): Promise<ReadonlyArray<ArticleSubjectCount>> {
  const search = new URLSearchParams();
  if (query) search.set("q", query);
  const response = await fetch(`${backendApiBaseUrl()}/api/v1/article-subjects${search.size ? `?${search}` : ""}`, { cache: "no-store" });
  if (!response.ok) throw apiUnavailableError("Articles API", `HTTP ${response.status}`);
  const payload: unknown = await response.json();
  if (!isRecord(payload) || !Array.isArray(payload.data)) throw new Error("unexpected subjects payload");
  return payload.data.map((item: unknown) => {
    if (!isRecord(item) || !hasExactKeys(item, ["id", "slug", "title", "count"]) || !isInteger(item.count)) throw new Error("unexpected subject count");
    const subject = parseSubject({ id: item.id, slug: item.slug, title: item.title });
    if (!subject) throw new Error("missing subject");
    return { ...subject, count: item.count };
  });
}

export async function legacyArticleTarget(path: string): Promise<string | null> {
  const response = await fetch(`${backendApiBaseUrl()}/api/v1/articles/redirect?path=${encodeURIComponent(path)}`, { cache: "no-store" });
  if (response.status === 404) return null;
  if (!response.ok) throw apiUnavailableError("Articles API", `HTTP ${response.status}`);
  const payload: unknown = await response.json();
  if (!isRecord(payload) || !isRecord(payload.data) || typeof payload.data.targetPath !== "string" ||
    !/^\/articles(?:\?|\/|$)/u.test(payload.data.targetPath)) throw new Error("unexpected redirect payload");
  return payload.data.targetPath;
}

function articleFetchInit(filters: ArticleFilters): ArticleFetchInit {
  if (filters.fetchMode === "revalidate") {
    return { next: { revalidate: filters.revalidate ?? 120 } };
  }

  return { cache: "no-store" };
}

export async function listArticlesResult(filters: ArticleFilters = {}): Promise<ArticleListResult> {
  const query = buildQuery(filters);
  const path = query ? `/api/v1/articles?${query}` : "/api/v1/articles";

  try {
    const response = await fetch(`${backendApiBaseUrl()}${path}`, articleFetchInit(filters));

    if (!response.ok) {
      throw new Error(`request failed with HTTP ${response.status}`);
    }

    return normalizeArticleListResult(await response.json(), filters);
  } catch (error) {
    rethrowNextDynamicServerError(error);

    if (isStaticFrontendModeEnabled()) {
      return emptyArticleListResult(filters);
    }

    throw apiUnavailableError("Articles API", error instanceof Error ? error.message : "request failed");
  }
}

export async function listArticles(filters: ArticleFilters = {}): Promise<ReadonlyArray<PublicArticleSummary>> {
  return (await listArticlesResult(filters)).data;
}

export async function findArticle(articleSlug: string, filters: ArticleFilters = {}): Promise<PublicArticleDetail | null> {
  try {
    const response = await fetch(`${backendApiBaseUrl()}/api/v1/articles/${encodeURIComponent(articleSlug)}`, articleFetchInit(filters));

    if (response.status === 404) {
      return null;
    }

    if (!response.ok) {
      throw new Error(`request failed with HTTP ${response.status}`);
    }

    return normalizeArticleDetail(await response.json());
  } catch (error) {
    rethrowNextDynamicServerError(error);

    if (isStaticFrontendModeEnabled()) {
      return null;
    }

    throw apiUnavailableError("Articles API", error instanceof Error ? error.message : "request failed");
  }
}
