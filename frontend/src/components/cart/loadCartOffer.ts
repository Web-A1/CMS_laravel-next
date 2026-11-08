import { normalizeSellables, normalizeSubjects } from "@/lib/catalogApi";
import { adaptCourseCheckout, type CheckoutCatalogItem } from "@/lib/commerceApi";
import { browserApiBaseUrl } from "@/lib/apiBaseUrl";

// Both catalogs are public; legacy course IDs use the subjects adapter, not the SKU list.
export async function loadCartOffer(itemId: string): Promise<CheckoutCatalogItem | null> {
  const legacyCourse = itemId.startsWith("course:");
  const response = await fetch(`${browserApiBaseUrl()}/api/v1/catalog/${legacyCourse ? "subjects" : "sellables"}`, {
    cache: "no-store", signal: AbortSignal.timeout(10_000),
  });
  if (!response.ok) throw new Error("Не удалось проверить доступность покупки. Попробуйте ещё раз.");
  const payload: unknown = await response.json();
  if (legacyCourse) {
    const course = normalizeSubjects(payload).flatMap((subject) => subject.courses).find((item) => item.itemId === itemId);
    return course ? adaptCourseCheckout(course) : null;
  }
  return normalizeSellables(payload).find((offer) => offer.itemId === itemId) ?? null;
}
