import { fetchAccountBackend } from "./accountAuth";
import { exactCommerceKeys, isCommerceRecord, safeCommerceText } from "./commerceApi";
import type { LearningFileMaterial } from "./learningApi";

export type OwnedGuide = { readonly id: number; readonly slug: string; readonly title: string };
export type GuideResult<T> = { readonly status: "ready"; readonly data: T } | { readonly status: "denied" | "not_found" | "unavailable" };
function positive(value: unknown): value is number { return typeof value === "number" && Number.isSafeInteger(value) && value > 0; }
export function normalizeGuides(payload: unknown): ReadonlyArray<OwnedGuide> {
  if (!isCommerceRecord(payload) || !exactCommerceKeys(payload, ["data"]) || !Array.isArray(payload.data)) throw new Error("unexpected guide payload");
  const guides = payload.data.map((guide): OwnedGuide => {
    if (!isCommerceRecord(guide) || !exactCommerceKeys(guide, ["id", "slug", "title"]) || !positive(guide.id) || typeof guide.slug !== "string" || !/^[a-z0-9-]+$/.test(guide.slug) || !safeCommerceText(guide.title)) throw new Error("unexpected guide payload");
    return { id: guide.id, slug: guide.slug, title: guide.title };
  });
  if (new Set(guides.map((guide) => guide.id)).size !== guides.length || new Set(guides.map((guide) => guide.slug)).size !== guides.length) throw new Error("duplicate guides");
  return guides;
}
export function normalizeGuideFiles(payload: unknown): ReadonlyArray<LearningFileMaterial> {
  if (!isCommerceRecord(payload) || !exactCommerceKeys(payload, ["data"]) || !isCommerceRecord(payload.data) || !exactCommerceKeys(payload.data, ["files"]) || !Array.isArray(payload.data.files)) throw new Error("unexpected guide files");
  const files = payload.data.files.map((file): LearningFileMaterial => {
    if (!isCommerceRecord(file) || !exactCommerceKeys(file, ["materialId", "title"]) || !positive(file.materialId) || !safeCommerceText(file.title)) throw new Error("unexpected guide file");
    return { materialId: file.materialId, title: file.title };
  });
  if (new Set(files.map((file) => file.materialId)).size !== files.length) throw new Error("duplicate files");
  return files;
}
async function load<T>(base: string, path: string, referer: string, parse: (value: unknown) => T): Promise<GuideResult<T>> {
  try {
    const response = await fetchAccountBackend(base, path, referer);
    if (response.status === 401 || response.status === 403) return { status: "denied" };
    if (response.status === 404) return { status: "not_found" };
    if (!response.ok) return { status: "unavailable" };
    return { status: "ready", data: parse(await response.json()) };
  } catch { return { status: "unavailable" }; }
}
export function listOwnedGuides(base: string): Promise<GuideResult<ReadonlyArray<OwnedGuide>>> {
  return load(base, "/api/v1/learning/guides", "/lk/guides", normalizeGuides);
}
export function loadGuideFiles(base: string, slug: string): Promise<GuideResult<ReadonlyArray<LearningFileMaterial>>> {
  return load(base, `/api/v1/learning/guides/${encodeURIComponent(slug)}/file-materials`, `/lk/guides/${encodeURIComponent(slug)}`, normalizeGuideFiles);
}
