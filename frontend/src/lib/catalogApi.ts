import { backendApiBaseUrl } from "@/lib/apiBaseUrl";
import { apiUnavailableError, rethrowNextDynamicServerError } from "@/lib/apiErrors";
import { isStaticFrontendModeEnabled } from "@/lib/staticFrontendMode";
import { exactCommerceKeys, isCommerceRecord, parseCheckoutCatalogItem, type CheckoutCatalogItem } from "./commerceApi";

export interface CourseCatalogItem {
  readonly id: number;
  readonly itemId: string;
  readonly subjectSlug: string;
  readonly subjectTitle: string;
  readonly courseSlug: string;
  readonly title: string;
  readonly shortTitle: string | null;
  readonly price: number;
  readonly sellable: CourseSellableCatalogItem | null;
  readonly lessons: ReadonlyArray<LessonCatalogItem>;
}

export interface CourseSellableCatalogItem {
  readonly sku: string;
  readonly kind: string;
  readonly price: number;
  readonly currency: string;
  readonly priceVersion: number;
}

export interface LessonCatalogItem {
  readonly id: number;
  readonly slug: string;
  readonly title: string;
  readonly sortOrder: number;
}

export interface SubjectCatalogItem {
  readonly id: number;
  readonly slug: string;
  readonly title: string;
  readonly courses: ReadonlyArray<CourseCatalogItem>;
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
  return Number.isInteger(value) && (value as number) >= minimum;
}

function isString(value: unknown): value is string {
  return typeof value === "string" && value !== "";
}

function isNullableString(value: unknown): value is string | null {
  return value === null || typeof value === "string";
}

function parseLesson(value: unknown): LessonCatalogItem {
  if (
    !isRecord(value) ||
    !hasExactKeys(value, ["id", "slug", "title", "sortOrder"]) ||
    !isInteger(value.id, 1) ||
    !isString(value.slug) ||
    !isString(value.title) ||
    !isInteger(value.sortOrder)
  ) {
    throw new Error("unexpected response payload");
  }

  return { id: value.id, slug: value.slug, title: value.title, sortOrder: value.sortOrder };
}

function parseSellable(value: unknown): CourseSellableCatalogItem | null {
  if (value === null) return null;
  if (
    !isRecord(value) ||
    !hasExactKeys(value, ["sku", "kind", "price", "currency", "priceVersion"]) ||
    !isString(value.sku) ||
    !isString(value.kind) ||
    !isInteger(value.price) ||
    !isString(value.currency) ||
    !isInteger(value.priceVersion, 1)
  ) {
    throw new Error("unexpected response payload");
  }

  return {
    sku: value.sku,
    kind: value.kind,
    price: value.price,
    currency: value.currency,
    priceVersion: value.priceVersion,
  };
}

export function normalizeSubjects(payload: unknown): ReadonlyArray<SubjectCatalogItem> {
  if (!isRecord(payload) || !Array.isArray(payload.data)) {
    throw new Error("unexpected response payload");
  }

  return payload.data.map((subject): SubjectCatalogItem => {
    if (
      !isRecord(subject) ||
      !hasExactKeys(subject, ["id", "slug", "title", "courses"]) ||
      !isInteger(subject.id, 1) ||
      !isString(subject.slug) ||
      !isString(subject.title) ||
      !Array.isArray(subject.courses)
    ) {
      throw new Error("unexpected response payload");
    }

    const subjectSlug = subject.slug;
    const subjectTitle = subject.title;

    const courses = subject.courses.map((course): CourseCatalogItem => {
      if (
        !isRecord(course) ||
        !hasExactKeys(course, ["id", "itemId", "slug", "title", "shortTitle", "price", "sellable", "subjectSlug", "subjectTitle", "lessons"]) ||
        !isInteger(course.id, 1) ||
        !isString(course.itemId) ||
        !isString(course.slug) ||
        !isString(course.title) ||
        !isNullableString(course.shortTitle) ||
        !isInteger(course.price) ||
        !isNullableString(course.subjectSlug) ||
        !isNullableString(course.subjectTitle) ||
        !Array.isArray(course.lessons)
      ) {
        throw new Error("unexpected response payload");
      }

      return {
        id: course.id,
        itemId: course.itemId,
        subjectSlug: course.subjectSlug ?? subjectSlug,
        subjectTitle: course.subjectTitle ?? subjectTitle,
        courseSlug: course.slug,
        title: course.title,
        shortTitle: course.shortTitle,
        price: course.price,
        sellable: parseSellable(course.sellable),
        lessons: course.lessons.map(parseLesson),
      };
    });

    return { id: subject.id, slug: subjectSlug, title: subjectTitle, courses };
  });
}

export async function listSubjects(): Promise<ReadonlyArray<SubjectCatalogItem>> {
  try {
    const response = await fetch(`${backendApiBaseUrl()}/api/v1/catalog/subjects`, {
      cache: "no-store",
    });

    if (!response.ok) {
      throw new Error(`request failed with HTTP ${response.status}`);
    }

    return normalizeSubjects(await response.json());
  } catch (error) {
    rethrowNextDynamicServerError(error);

    if (isStaticFrontendModeEnabled()) {
      return [];
    }

    throw apiUnavailableError("Catalog API", error instanceof Error ? error.message : "request failed");
  }
}

export async function listCourses(): Promise<ReadonlyArray<CourseCatalogItem>> {
  const subjects = await listSubjects();
  return subjects.flatMap((subject) => subject.courses);
}

export function normalizeSellables(payload: unknown): ReadonlyArray<CheckoutCatalogItem> {
  if (!isCommerceRecord(payload) || !exactCommerceKeys(payload, ["data"]) || !Array.isArray(payload.data)) throw new Error("unexpected catalog payload");
  const items = payload.data.map(parseCheckoutCatalogItem);
  if (new Set(items.map((item) => item.sku)).size !== items.length) throw new Error("duplicate catalog SKU");
  return items;
}

export async function listSellables(): Promise<ReadonlyArray<CheckoutCatalogItem>> {
  try {
    const response = await fetch(`${backendApiBaseUrl()}/api/v1/catalog/sellables`, { cache: "no-store", signal: AbortSignal.timeout(10_000) });
    if (!response.ok) throw new Error(`request failed with HTTP ${response.status}`);
    return normalizeSellables(await response.json());
  } catch (error) {
    rethrowNextDynamicServerError(error);
    if (isStaticFrontendModeEnabled()) return [];
    throw apiUnavailableError("Catalog API", error instanceof Error ? error.message : "request failed");
  }
}

export async function findSubject(subjectSlug: string): Promise<SubjectCatalogItem | undefined> {
  const subjects = await listSubjects();
  return subjects.find((subject) => subject.slug === subjectSlug);
}

export async function findCourseBySlugs(
  subjectSlug: string,
  courseSlug: string,
): Promise<CourseCatalogItem | undefined> {
  const subject = await findSubject(subjectSlug);
  return subject?.courses.find((course) => course.courseSlug === courseSlug);
}
