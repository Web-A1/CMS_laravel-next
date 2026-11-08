import { backendApiBaseUrl } from "@/lib/apiBaseUrl";
import { apiUnavailableError, rethrowNextDynamicServerError } from "@/lib/apiErrors";
import { isStaticFrontendModeEnabled } from "@/lib/staticFrontendMode";

export type FaqPlacement = "faq.index" | "home.faq";

export interface PublicFaqCategory {
  readonly slug: string;
  readonly title: string;
}

export interface PublicFaqItem {
  readonly id: number;
  readonly slug: string;
  readonly question: string;
  readonly answer: string;
  readonly category: PublicFaqCategory | null;
  readonly subject: (PublicFaqCategory & { readonly id: number }) | null;
  readonly sortOrder: number;
  readonly publishedAt: string | null;
}

export interface FaqFilters {
  readonly subject?: string;
  readonly placement?: FaqPlacement;
  readonly category?: string;
  readonly limit?: number;
  readonly fetchMode?: "live" | "revalidate";
  readonly revalidate?: number;
}

export interface FaqMeta {
  readonly placement: FaqPlacement;
  readonly limit: number | null;
  readonly total: number;
}

export interface FaqResult {
  readonly data: ReadonlyArray<PublicFaqItem>;
  readonly meta: FaqMeta;
}

type FaqFetchInit = RequestInit & {
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

function isFaqPlacement(value: unknown): value is FaqPlacement {
  return value === "faq.index" || value === "home.faq";
}

function parseCategory(value: unknown): PublicFaqCategory | null {
  if (value === null) return null;
  if (
    !isRecord(value) ||
    !hasExactKeys(value, ["slug", "title"]) ||
    typeof value.slug !== "string" ||
    value.slug === "" ||
    typeof value.title !== "string" ||
    value.title === ""
  ) {
    throw new Error("unexpected response payload");
  }
  return { slug: value.slug, title: value.title };
}

function parseSubject(value: unknown): PublicFaqItem["subject"] {
  if (value === null) return null;
  if (!isRecord(value) || !hasExactKeys(value, ["id", "slug", "title"]) || !isInteger(value.id, 1)) {
    throw new Error("unexpected FAQ subject");
  }
  const subject = parseCategory({ slug: value.slug, title: value.title });
  if (!subject) throw new Error("missing FAQ subject");
  return { id: value.id, ...subject };
}

function buildQuery(filters: FaqFilters): string {
  const params = new URLSearchParams();

  if (filters.placement) params.set("placement", filters.placement);
  if (filters.category) params.set("category", filters.category);
  if (filters.subject) params.set("subject", filters.subject);
  if (filters.limit) params.set("limit", String(filters.limit));

  return params.toString();
}

function defaultFaqMeta(data: ReadonlyArray<PublicFaqItem>, filters: FaqFilters): FaqMeta {
  return {
    placement: filters.placement ?? "faq.index",
    limit: filters.limit ?? null,
    total: data.length,
  };
}

function emptyFaqResult(filters: FaqFilters): FaqResult {
  const data: ReadonlyArray<PublicFaqItem> = [];

  return {
    data,
    meta: defaultFaqMeta(data, filters),
  };
}

export function normalizeFaqResult(payload: unknown, filters: FaqFilters): FaqResult {
  if (!isRecord(payload) || !Array.isArray(payload.data)) {
    throw new Error("unexpected response payload");
  }

  const data = payload.data.map((item): PublicFaqItem => {
    if (
      !isRecord(item) ||
      !hasExactKeys(item, ["id", "slug", "question", "answer", "category", "subject", "sortOrder", "publishedAt"]) ||
      !isInteger(item.id, 1) ||
      typeof item.slug !== "string" ||
      item.slug === "" ||
      typeof item.question !== "string" ||
      item.question === "" ||
      typeof item.answer !== "string" ||
      !isInteger(item.sortOrder) ||
      (item.publishedAt !== null && typeof item.publishedAt !== "string")
    ) {
      throw new Error("unexpected response payload");
    }

    return {
      id: item.id,
      slug: item.slug,
      question: item.question,
      answer: item.answer,
      category: parseCategory(item.category),
      subject: parseSubject(item.subject),
      sortOrder: item.sortOrder,
      publishedAt: item.publishedAt,
    };
  });
  const fallbackMeta = defaultFaqMeta(data, filters);
  const meta = payload.meta;
  if (
    meta !== undefined &&
    (!isRecord(meta) ||
      !hasExactKeys(meta, ["placement", "limit", "total"]) ||
      !isFaqPlacement(meta.placement) ||
      (meta.limit !== null && !isInteger(meta.limit, 1)) ||
      !isInteger(meta.total))
  ) {
    throw new Error("unexpected response payload");
  }

  return {
    data,
    meta: {
      placement: meta && isFaqPlacement(meta.placement) ? meta.placement : fallbackMeta.placement,
      limit: meta ? (meta.limit as number | null) : fallbackMeta.limit,
      total: meta ? (meta.total as number) : fallbackMeta.total,
    },
  };
}

function faqFetchInit(filters: FaqFilters): FaqFetchInit {
  if (filters.fetchMode === "revalidate") {
    return { next: { revalidate: filters.revalidate ?? 120 } };
  }

  return { cache: "no-store" };
}

export async function listFaqsResult(filters: FaqFilters = {}): Promise<FaqResult> {
  const query = buildQuery(filters);
  const path = query ? `/api/v1/faqs?${query}` : "/api/v1/faqs";

  try {
    const response = await fetch(`${backendApiBaseUrl()}${path}`, faqFetchInit(filters));

    if (!response.ok) {
      throw new Error(`request failed with HTTP ${response.status}`);
    }

    return normalizeFaqResult(await response.json(), filters);
  } catch (error) {
    rethrowNextDynamicServerError(error);

    if (isStaticFrontendModeEnabled()) {
      return emptyFaqResult(filters);
    }

    throw apiUnavailableError("FAQ API", error instanceof Error ? error.message : "request failed");
  }
}

export async function listFaqs(filters: FaqFilters = {}): Promise<ReadonlyArray<PublicFaqItem>> {
  return (await listFaqsResult(filters)).data;
}
