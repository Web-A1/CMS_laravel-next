import type { CourseCatalogItem } from "./catalogApi";

export type CommerceKind = "course" | "lesson" | "guide" | "box" | "consultation";
export type SellableTariff = { readonly code: "ordinary" | "extended"; readonly label: string; readonly supportDescription: string };
export type CommerceAtom = { readonly kind: "guide" | "lesson"; readonly id: number; readonly title: string; readonly accessPath: string };
type Identity = { [K in CommerceKind]: { readonly kind: K; readonly entityType: K; readonly entityId: number } }[CommerceKind];
export type CheckoutCatalogItem = Identity & {
  readonly itemId: string; readonly sku: string; readonly title: string; readonly price: number;
  readonly currency: "RUB"; readonly priceVersion: number; readonly publicPath: string; readonly accessPath: string;
  readonly tariff: SellableTariff | null; readonly atoms: ReadonlyArray<CommerceAtom>;
};

export function isCommerceRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === "object" && value !== null && !Array.isArray(value);
}
export function exactCommerceKeys(value: Record<string, unknown>, keys: ReadonlyArray<string>): boolean {
  return Object.keys(value).length === keys.length && keys.every((key) => Object.hasOwn(value, key));
}
export function safeCommerceText(value: unknown, max = 500): value is string {
  return typeof value === "string" && value.trim() === value && value !== "" && Array.from(value).length <= max && !/[<>\u0000-\u001f\u007f-\u009f]/u.test(value);
}
export function safeCommercePath(value: unknown): value is string {
  if (typeof value !== "string" || !value.startsWith("/") || value.startsWith("//") || /[\\?#\s\u0000-\u001f]/u.test(value)) return false;
  try { return new URL(value, "https://same-site.invalid").pathname === value && !/%(?:2f|5c|00)/i.test(value); } catch { return false; }
}
function positive(value: unknown): value is number { return typeof value === "number" && Number.isSafeInteger(value) && value > 0; }
export function parseSellableTariff(value: unknown): SellableTariff | null {
  if (value === null) return null;
  if (!isCommerceRecord(value) || !exactCommerceKeys(value, ["code", "label", "supportDescription"]) || (value.code !== "ordinary" && value.code !== "extended") || !safeCommerceText(value.label, 80) || !safeCommerceText(value.supportDescription, 500)) throw new Error("unexpected tariff payload");
  return { code: value.code, label: value.label, supportDescription: value.supportDescription };
}
export function parseCheckoutCatalogItem(value: unknown): CheckoutCatalogItem {
  if (!isCommerceRecord(value) || !exactCommerceKeys(value, ["sku", "kind", "entityType", "entityId", "title", "price", "currency", "priceVersion", "publicPath", "accessPath", "tariff", "atoms"]) || !safeCommerceText(value.sku, 120) || !/^[a-zA-Z0-9_-]+$/.test(value.sku) || !positive(value.entityId) || !safeCommerceText(value.title) || typeof value.price !== "number" || !Number.isSafeInteger(value.price) || value.price < 0 || value.currency !== "RUB" || !positive(value.priceVersion) || !safeCommercePath(value.publicPath) || !safeCommercePath(value.accessPath) || !value.accessPath.startsWith("/lk/") || !Array.isArray(value.atoms)) throw new Error("unexpected commerce payload");
  const atoms = value.atoms.map((atom): CommerceAtom => {
    if (!isCommerceRecord(atom) || !exactCommerceKeys(atom, ["kind", "id", "title", "accessPath"]) || (atom.kind !== "guide" && atom.kind !== "lesson") || !positive(atom.id) || !safeCommerceText(atom.title) || !safeCommercePath(atom.accessPath) || !atom.accessPath.startsWith("/lk/")) throw new Error("unexpected atom payload");
    return { kind: atom.kind, id: atom.id, title: atom.title, accessPath: atom.accessPath };
  });
  if (new Set(atoms.map((atom) => `${atom.kind}:${atom.id}`)).size !== atoms.length) throw new Error("duplicate atoms");
  const common = { itemId: value.sku, sku: value.sku, title: value.title, price: value.price, currency: value.currency, priceVersion: value.priceVersion, publicPath: value.publicPath, accessPath: value.accessPath, tariff: parseSellableTariff(value.tariff), atoms };
  if (value.kind === "course" && value.entityType === "course") return { ...common, currency: "RUB", kind: "course", entityType: "course", entityId: value.entityId };
  if (value.kind === "lesson" && value.entityType === "lesson") return { ...common, currency: "RUB", kind: "lesson", entityType: "lesson", entityId: value.entityId };
  if (value.kind === "guide" && value.entityType === "guide") return { ...common, currency: "RUB", kind: "guide", entityType: "guide", entityId: value.entityId };
  if (value.kind === "box" && value.entityType === "box") return { ...common, currency: "RUB", kind: "box", entityType: "box", entityId: value.entityId };
  if (value.kind === "consultation" && value.entityType === "consultation") return { ...common, currency: "RUB", kind: "consultation", entityType: "consultation", entityId: value.entityId };
  throw new Error("unexpected commerce identity");
}
export function adaptCourseCheckout(course: CourseCatalogItem): CheckoutCatalogItem | null {
  if (!course.sellable || course.sellable.kind !== "course" || course.sellable.currency !== "RUB") return null;
  return { itemId: course.itemId, sku: course.sellable.sku, kind: "course", entityType: "course", entityId: course.id, title: course.title, price: course.sellable.price, currency: "RUB", priceVersion: course.sellable.priceVersion, publicPath: `/subjects/${course.subjectSlug}/${course.courseSlug}`, accessPath: `/lk/learning/${course.subjectSlug}/${course.courseSlug}`, tariff: null, atoms: [] };
}
export function matchedOrderTariff(payload: unknown, item: CheckoutCatalogItem): SellableTariff | null {
  if (!isCommerceRecord(payload) || !Array.isArray(payload.items) || payload.items.length !== 1) throw new Error("Состав заказа не совпадает с товаром в корзине.");
  const purchased = payload.items[0];
  if (!isCommerceRecord(purchased) || purchased.sellable_sku !== item.sku || purchased.sellable_kind !== item.kind || purchased.purchased_entity_type !== item.entityType || purchased.purchased_entity_id !== item.entityId) throw new Error("Состав заказа не совпадает с товаром в корзине.");
  return parseSellableTariff(purchased.tariff);
}
