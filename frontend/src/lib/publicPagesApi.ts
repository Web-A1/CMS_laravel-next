import { backendApiBaseUrl } from "@/lib/apiBaseUrl";
import { apiUnavailableError, rethrowNextDynamicServerError } from "@/lib/apiErrors";
import { isStaticFrontendModeEnabled } from "@/lib/staticFrontendMode";

export type PublicPageSlug = "about";

export interface PublicPageDetail {
  readonly slug: PublicPageSlug;
  readonly title: string;
  readonly description: string | null;
  readonly body: string;
  readonly sortOrder: number;
  readonly publishedAt: string | null;
  readonly seoTitle: string | null;
  readonly seoDescription: string | null;
}

type PublicPageFetchInit = RequestInit & {
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

function isNullableString(value: unknown): value is string | null {
  return value === null || typeof value === "string";
}

export interface PublicPageFilters {
  readonly fetchMode?: "live" | "revalidate";
  readonly revalidate?: number;
}

function pageFetchInit(filters: PublicPageFilters): PublicPageFetchInit {
  if (filters.fetchMode === "revalidate") {
    return { next: { revalidate: filters.revalidate ?? 120 } };
  }

  return { cache: "no-store" };
}

export function normalizePublicPage(payload: unknown): PublicPageDetail {
  if (!isRecord(payload) || !isRecord(payload.data)) {
    throw new Error("unexpected response payload");
  }

  const data = payload.data;
  if (
    !hasExactKeys(data, ["slug", "title", "description", "body", "sortOrder", "publishedAt", "seoTitle", "seoDescription"]) ||
    data.slug !== "about" ||
    typeof data.title !== "string" || data.title === "" ||
    !isNullableString(data.description) ||
    typeof data.body !== "string" ||
    !Number.isInteger(data.sortOrder) || (data.sortOrder as number) < 0 ||
    !isNullableString(data.publishedAt) ||
    !isNullableString(data.seoTitle) ||
    !isNullableString(data.seoDescription)
  ) {
    throw new Error("unexpected response payload");
  }

  return {
    slug: data.slug,
    title: data.title,
    description: data.description,
    body: data.body,
    sortOrder: data.sortOrder as number,
    publishedAt: data.publishedAt,
    seoTitle: data.seoTitle,
    seoDescription: data.seoDescription,
  };
}

export async function findPublicPage(pageSlug: PublicPageSlug, filters: PublicPageFilters = {}): Promise<PublicPageDetail | null> {
  try {
    const response = await fetch(`${backendApiBaseUrl()}/api/v1/pages/${encodeURIComponent(pageSlug)}`, pageFetchInit(filters));

    if (response.status === 404) {
      return null;
    }

    if (!response.ok) {
      throw new Error(`request failed with HTTP ${response.status}`);
    }

    return normalizePublicPage(await response.json());
  } catch (error) {
    rethrowNextDynamicServerError(error);

    if (isStaticFrontendModeEnabled()) {
      return null;
    }

    throw apiUnavailableError("Public pages API", error instanceof Error ? error.message : "request failed");
  }
}
