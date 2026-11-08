import { backendApiBaseUrl, browserApiBaseUrl } from "@/lib/apiBaseUrl";
import { apiUnavailableError, rethrowNextDynamicServerError } from "@/lib/apiErrors";
import { isStaticFrontendModeEnabled } from "@/lib/staticFrontendMode";

export interface ReviewImageVariant {
  readonly url: string;
  readonly format: "avif" | "webp";
  readonly width: number;
  readonly height: number;
}

export interface ReviewMediaItem {
  readonly id: string;
  readonly type: string;
  readonly url: string;
  readonly alt: string | null;
  readonly width: number | null;
  readonly height: number | null;
  readonly provenance: string | null;
  readonly sourceType: string | null;
  readonly variants?: ReadonlyArray<ReviewImageVariant>;
}

export type ReviewSourceLinkType = "vk_webarchive" | "yandex_maps_webarchive" | "manual";

export interface ReviewSourceLink {
  readonly type: ReviewSourceLinkType;
  readonly label: string;
  readonly url: string;
}

export interface PublicReview {
  readonly id: number;
  readonly displayName: string;
  readonly authorPhotoUrl: string | null;
  readonly authorPhotoVariants?: ReadonlyArray<ReviewImageVariant>;
  readonly text: string;
  readonly reviewWrittenAt: string | null;
  readonly publishedAt: string | null;
  readonly source: string;
  readonly importOrigin: string;
  readonly subject: {
    readonly id: number;
    readonly slug: string;
    readonly title: string;
  } | null;
  readonly course: {
    readonly id: number;
    readonly itemId: string;
    readonly slug: string;
    readonly title: string;
    readonly shortTitle: string | null;
    readonly price: number;
    readonly subjectSlug: string | null;
    readonly subjectTitle: string | null;
  } | null;
  readonly sourceLinks: ReadonlyArray<ReviewSourceLink>;
  readonly media: ReadonlyArray<ReviewMediaItem>;
}

export interface ReviewFilters {
  readonly search?: string;
  readonly placement?: "home";
  readonly subject?: string;
  readonly course?: string;
  readonly limit?: number;
  readonly page?: number;
  readonly perPage?: number;
  readonly fetchMode?: "live" | "revalidate";
  readonly revalidate?: number;
}

export interface ReviewSubjectFacet {
  readonly slug: string;
  readonly title: string;
  readonly reviewsCount: number;
}

export interface ReviewCourseFacet {
  readonly itemId: string;
  readonly courseSlug: string;
  readonly title: string;
  readonly shortTitle: string | null;
  readonly subjectSlug: string;
  readonly subjectTitle: string;
  readonly reviewsCount: number;
}

export interface ReviewFacets {
  readonly subjects: ReadonlyArray<ReviewSubjectFacet>;
  readonly courses: ReadonlyArray<ReviewCourseFacet>;
}

export interface ReviewsMeta {
  readonly limit: number;
  readonly page: number;
  readonly perPage: number;
  readonly total: number;
  readonly lastPage: number;
  readonly sort: "latest";
  readonly totalPublished: number;
  readonly filters: {
    readonly subject: string | null;
    readonly course: string | null;
  };
  readonly facets: ReviewFacets;
}

export interface ReviewsResult {
  readonly data: ReadonlyArray<PublicReview>;
  readonly meta: ReviewsMeta;
}

export interface ReviewNavigation {
  readonly previousId: number | null;
  readonly nextId: number | null;
  readonly position: number;
  readonly total: number;
  readonly filters: {
    readonly subject: string | null;
    readonly course: string | null;
  };
}

export interface ReviewResult {
  readonly data: PublicReview | null;
  readonly navigation: ReviewNavigation | null;
}

type JsonRecord = Record<string, unknown>;

function isRecord(value: unknown): value is JsonRecord {
  return typeof value === "object" && value !== null && !Array.isArray(value);
}

function hasExactKeys(value: JsonRecord, keys: ReadonlyArray<string>): boolean {
  const allowed = new Set(keys);
  return keys.every((key) => Object.hasOwn(value, key)) && Object.keys(value).every((key) => allowed.has(key));
}

function isInteger(value: unknown, minimum = 0): value is number {
  return Number.isSafeInteger(value) && (value as number) >= minimum;
}

function isNullableString(value: unknown): value is string | null {
  return value === null || typeof value === "string";
}

function isSafePublicUrl(value: unknown): value is string {
  if (typeof value !== "string" || value === "" || value.trim() !== value || /[\u0000-\u001F\u007F\\]/.test(value)) {
    return false;
  }
  if (value.startsWith("/") && !value.startsWith("//")) return true;

  try {
    const url = new URL(value);
    return (
      (url.protocol === "https:" || url.protocol === "http:") &&
      url.username === "" &&
      url.password === ""
    );
  } catch {
    return false;
  }
}

function parseSubject(value: unknown): PublicReview["subject"] {
  if (value === null) return null;
  if (
    !isRecord(value) ||
    !hasExactKeys(value, ["id", "slug", "title"]) ||
    !isInteger(value.id, 1) ||
    typeof value.slug !== "string" || value.slug === "" ||
    typeof value.title !== "string" || value.title === ""
  ) {
    throw new Error("unexpected response payload");
  }
  return { id: value.id, slug: value.slug, title: value.title };
}

function parseCourse(value: unknown): PublicReview["course"] {
  if (value === null) return null;
  if (
    !isRecord(value) ||
    !hasExactKeys(value, ["id", "itemId", "slug", "title", "shortTitle", "price", "subjectSlug", "subjectTitle"]) ||
    !isInteger(value.id, 1) ||
    typeof value.itemId !== "string" || value.itemId === "" ||
    typeof value.slug !== "string" || value.slug === "" ||
    typeof value.title !== "string" || value.title === "" ||
    !isNullableString(value.shortTitle) ||
    !isInteger(value.price) ||
    !isNullableString(value.subjectSlug) ||
    !isNullableString(value.subjectTitle)
  ) {
    throw new Error("unexpected response payload");
  }
  return {
    id: value.id,
    itemId: value.itemId,
    slug: value.slug,
    title: value.title,
    shortTitle: value.shortTitle,
    price: value.price,
    subjectSlug: value.subjectSlug,
    subjectTitle: value.subjectTitle,
  };
}

function parseSourceLink(value: unknown): ReviewSourceLink {
  if (
    !isRecord(value) ||
    !hasExactKeys(value, ["type", "label", "url"]) ||
    (value.type !== "vk_webarchive" && value.type !== "yandex_maps_webarchive" && value.type !== "manual") ||
    typeof value.label !== "string" || value.label === "" ||
    !isSafePublicUrl(value.url)
  ) {
    throw new Error("unexpected response payload");
  }
  return { type: value.type, label: value.label, url: value.url };
}

function parseImageVariants(value: unknown): ReadonlyArray<ReviewImageVariant> {
  if (value === undefined) return [];
  if (!Array.isArray(value) || value.length > 32) throw new Error("unexpected response payload");
  const seen = new Set<string>();
  const variants = value.map((item: unknown): ReviewImageVariant => {
    if (!isRecord(item) || !hasExactKeys(item, ["url", "format", "width", "height"]) || !isSafePublicUrl(item.url) ||
      (item.format !== "avif" && item.format !== "webp") || !isInteger(item.width, 1) || !isInteger(item.height, 1) ||
      Number(item.width) > 20000 || Number(item.height) > 20000) throw new Error("unexpected response payload");
    const key = `${item.format}:${item.width}`;
    if (seen.has(key)) throw new Error("unexpected response payload");
    seen.add(key);
    return { url: item.url, format: item.format, width: Number(item.width), height: Number(item.height) };
  });
  for (const variant of variants) {
    if (!variants.some((pair) => pair.width === variant.width && pair.height === variant.height && pair.format !== variant.format)) {
      throw new Error("unexpected response payload");
    }
  }
  return variants;
}

function parseMedia(value: unknown): ReviewMediaItem {
  if (
    !isRecord(value) ||
    !hasExactKeys(value, ["id", "type", "url", "alt", "width", "height", "provenance", "sourceType", ...(Object.hasOwn(value, "variants") ? ["variants"] : [])]) ||
    typeof value.id !== "string" || value.id === "" ||
    typeof value.type !== "string" || value.type === "" ||
    !isSafePublicUrl(value.url) ||
    !isNullableString(value.alt) ||
    (value.width !== null && !isInteger(value.width, 1)) ||
    (value.height !== null && !isInteger(value.height, 1)) ||
    !isNullableString(value.provenance) ||
    !isNullableString(value.sourceType)
  ) {
    throw new Error("unexpected response payload");
  }
  return {
    id: value.id,
    type: value.type,
    url: value.url,
    alt: value.alt,
    width: value.width as number | null,
    height: value.height as number | null,
    provenance: value.provenance,
    sourceType: value.sourceType,
    variants: parseImageVariants(value.variants),
  };
}

function buildQuery(filters: ReviewFilters): string {
  const params = new URLSearchParams();
  params.set("image_variants", "1");

  if (filters.placement) params.set("placement", filters.placement);

  if (filters.subject) params.set("subject", filters.subject);
  if (filters.search) params.set("search", filters.search);
  if (filters.course) params.set("course", filters.course);
  if (filters.limit) params.set("limit", String(filters.limit));
  if (filters.page && filters.page > 1) params.set("page", String(filters.page));
  if (filters.perPage) params.set("per_page", String(filters.perPage));

  return params.toString();
}

function defaultReviewsMeta(data: ReadonlyArray<PublicReview>, filters: ReviewFilters): ReviewsMeta {
  const perPage = filters.perPage ?? filters.limit ?? data.length;

  return {
    limit: perPage,
    page: filters.page ?? 1,
    perPage,
    total: data.length,
    lastPage: data.length > 0 && perPage > 0 ? Math.ceil(data.length / perPage) : 1,
    sort: "latest",
    totalPublished: data.length,
    filters: {
      subject: filters.subject ?? null,
      course: filters.course ?? null,
    },
    facets: {
      subjects: [],
      courses: [],
    },
  };
}

function emptyReviewsResult(filters: ReviewFilters): ReviewsResult {
  const data: ReadonlyArray<PublicReview> = [];

  return {
    data,
    meta: defaultReviewsMeta(data, filters),
  };
}

function reviewDetailFallback(): ReviewResult {
  return {
    data: null,
    navigation: null,
  };
}

function normalizeReview(review: unknown): PublicReview {
  if (
    !isRecord(review) ||
    // Older API instances can omit the additive photo field during a rolling deployment.
    !hasExactKeys(review, ["id", "displayName", "text", "reviewWrittenAt", "publishedAt", "source", "importOrigin", "course", "subject", "sourceLinks", "media", ...(Object.hasOwn(review, "authorPhotoUrl") ? ["authorPhotoUrl"] : []), ...(Object.hasOwn(review, "authorPhotoVariants") ? ["authorPhotoVariants"] : [])]) ||
    !isInteger(review.id, 1) ||
    typeof review.displayName !== "string" || review.displayName === "" ||
    (Object.hasOwn(review, "authorPhotoUrl") && review.authorPhotoUrl !== null && !isSafePublicUrl(review.authorPhotoUrl)) ||
    typeof review.text !== "string" ||
    !isNullableString(review.reviewWrittenAt) ||
    !isNullableString(review.publishedAt) ||
    typeof review.source !== "string" || review.source === "" ||
    typeof review.importOrigin !== "string" || review.importOrigin === "" ||
    !Array.isArray(review.sourceLinks) ||
    !Array.isArray(review.media)
  ) {
    throw new Error("unexpected response payload");
  }

  return {
    id: review.id,
    displayName: review.displayName,
    authorPhotoUrl: typeof review.authorPhotoUrl === "string" ? review.authorPhotoUrl : null,
    authorPhotoVariants: parseImageVariants(review.authorPhotoVariants),
    text: review.text,
    reviewWrittenAt: review.reviewWrittenAt,
    publishedAt: review.publishedAt,
    source: review.source,
    importOrigin: review.importOrigin,
    course: parseCourse(review.course),
    subject: parseSubject(review.subject),
    sourceLinks: review.sourceLinks.map(parseSourceLink),
    media: review.media.map(parseMedia),
  };
}

function parseNullableFilter(value: unknown): string | null {
  if (value === null || typeof value === "string") return value;
  throw new Error("unexpected response payload");
}

function parseSubjectFacet(value: unknown): ReviewSubjectFacet {
  if (!isRecord(value) || !hasExactKeys(value, ["slug", "title", "reviewsCount"]) ||
      typeof value.slug !== "string" || value.slug === "" || typeof value.title !== "string" || value.title === "" ||
      !isInteger(value.reviewsCount)) throw new Error("unexpected response payload");
  return { slug: value.slug, title: value.title, reviewsCount: value.reviewsCount };
}

function parseCourseFacet(value: unknown): ReviewCourseFacet {
  if (!isRecord(value) || !hasExactKeys(value, ["itemId", "courseSlug", "title", "shortTitle", "subjectSlug", "subjectTitle", "reviewsCount"]) ||
      typeof value.itemId !== "string" || value.itemId === "" || typeof value.courseSlug !== "string" || value.courseSlug === "" ||
      typeof value.title !== "string" || value.title === "" || !isNullableString(value.shortTitle) ||
      typeof value.subjectSlug !== "string" || value.subjectSlug === "" || typeof value.subjectTitle !== "string" || value.subjectTitle === "" ||
      !isInteger(value.reviewsCount)) throw new Error("unexpected response payload");
  return {
    itemId: value.itemId, courseSlug: value.courseSlug, title: value.title, shortTitle: value.shortTitle,
    subjectSlug: value.subjectSlug, subjectTitle: value.subjectTitle, reviewsCount: value.reviewsCount,
  };
}

function parseReviewsMeta(value: unknown, fallback: ReviewsMeta): ReviewsMeta {
  if (value === undefined) return fallback;
  if (!isRecord(value)) throw new Error("unexpected response payload");
  const filters = value.filters;
  const facets = value.facets;
  if (
    !isInteger(value.limit) || !isInteger(value.page, 1) || !isInteger(value.perPage, 1) || !isInteger(value.total) ||
    !isInteger(value.lastPage, 1) || value.sort !== "latest" || !isInteger(value.totalPublished) ||
    !isRecord(filters) || !hasExactKeys(filters, ["subject", "course"]) ||
    !isRecord(facets) || !hasExactKeys(facets, ["subjects", "courses"]) ||
    !Array.isArray(facets.subjects) || !Array.isArray(facets.courses)
  ) {
    throw new Error("unexpected response payload");
  }
  return {
    limit: value.limit,
    page: value.page,
    perPage: value.perPage,
    total: value.total,
    lastPage: value.lastPage,
    sort: "latest",
    totalPublished: value.totalPublished,
    filters: { subject: parseNullableFilter(filters.subject), course: parseNullableFilter(filters.course) },
    facets: { subjects: facets.subjects.map(parseSubjectFacet), courses: facets.courses.map(parseCourseFacet) },
  };
}

export function normalizeReviews(payload: unknown, filters: ReviewFilters): ReviewsResult {
  if (!isRecord(payload) || !Array.isArray(payload.data)) {
    throw new Error("unexpected response payload");
  }

  const data = payload.data.map(normalizeReview);
  const meta = parseReviewsMeta(payload.meta, defaultReviewsMeta(data, filters));

  return {
    data,
    meta,
  };
}

function parseReviewNavigation(value: unknown): ReviewNavigation | null {
  if (value === null || value === undefined) return null;
  if (!isRecord(value) || !hasExactKeys(value, ["previousId", "nextId", "position", "total", "filters"]) ||
      (value.previousId !== null && !isInteger(value.previousId, 1)) ||
      (value.nextId !== null && !isInteger(value.nextId, 1)) || !isInteger(value.position, 1) || !isInteger(value.total) ||
      !isRecord(value.filters) || !hasExactKeys(value.filters, ["subject", "course"])) {
    throw new Error("unexpected response payload");
  }
  return {
    previousId: value.previousId as number | null,
    nextId: value.nextId as number | null,
    position: value.position,
    total: value.total,
    filters: { subject: parseNullableFilter(value.filters.subject), course: parseNullableFilter(value.filters.course) },
  };
}

export function normalizeReviewResult(payload: unknown): ReviewResult {
  if (!isRecord(payload) || !isRecord(payload.data)) {
    throw new Error("unexpected response payload");
  }

  const meta = payload.meta;
  if (meta !== undefined && !isRecord(meta)) throw new Error("unexpected response payload");

  return {
    data: normalizeReview(payload.data),
    navigation: parseReviewNavigation(meta?.navigation),
  };
}

function reviewsApiBaseUrl(): string {
  return typeof window === "undefined" ? backendApiBaseUrl() : browserApiBaseUrl();
}

type ReviewsListFetchInit = RequestInit & {
  readonly next?: {
    readonly revalidate: number;
  };
};

function reviewsListFetchInit(filters: ReviewFilters): ReviewsListFetchInit {
  if (filters.fetchMode === "revalidate") {
    return { next: { revalidate: filters.revalidate ?? 60 } };
  }

  return { cache: "no-store" };
}

export async function listReviewsResult(filters: ReviewFilters = {}): Promise<ReviewsResult> {
  const query = buildQuery(filters);
  const path = query ? `/api/v1/reviews?${query}` : "/api/v1/reviews";

  try {
    const response = await fetch(`${reviewsApiBaseUrl()}${path}`, reviewsListFetchInit(filters));

    if (!response.ok) {
      throw new Error(`request failed with HTTP ${response.status}`);
    }

    return normalizeReviews(await response.json(), filters);
  } catch (error) {
    rethrowNextDynamicServerError(error);

    if (isStaticFrontendModeEnabled()) {
      return emptyReviewsResult(filters);
    }

    throw apiUnavailableError("Reviews API", error instanceof Error ? error.message : "request failed");
  }
}

export async function listReviews(filters: ReviewFilters = {}): Promise<ReadonlyArray<PublicReview>> {
  return (await listReviewsResult(filters)).data;
}

export async function getReviewResult(reviewId: number, filters: Pick<ReviewFilters, "subject" | "course" | "search"> = {}): Promise<ReviewResult> {
  const query = buildQuery(filters);
  const path = query ? `/api/v1/reviews/${reviewId}?${query}` : `/api/v1/reviews/${reviewId}`;

  try {
    const response = await fetch(`${reviewsApiBaseUrl()}${path}`, {
      cache: "no-store",
    });

    if (!response.ok) {
      if (response.status === 404) {
        return reviewDetailFallback();
      }

      throw new Error(`request failed with HTTP ${response.status}`);
    }

    return normalizeReviewResult(await response.json());
  } catch (error) {
    rethrowNextDynamicServerError(error);

    if (isStaticFrontendModeEnabled()) {
      return reviewDetailFallback();
    }

    throw apiUnavailableError("Review API", error instanceof Error ? error.message : "request failed");
  }
}
