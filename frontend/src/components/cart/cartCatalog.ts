import type { CheckoutCatalogItem } from "../../lib/commerceApi";

export type CartItemLabel = { readonly itemId: string; readonly title: string };
export type CartEntry =
  | { readonly status: "available"; readonly itemId: string; readonly title: string; readonly offer: CheckoutCatalogItem }
  | { readonly status: "unavailable"; readonly itemId: string; readonly title: string; readonly offer: null };

export function resolveCartEntries(
  ids: ReadonlyArray<string>,
  offers: ReadonlyArray<CheckoutCatalogItem>,
  labels: ReadonlyArray<CartItemLabel> = [],
): ReadonlyArray<CartEntry> {
  const byId = new Map(offers.map((offer) => [offer.itemId, offer]));
  const titles = new Map(labels.map((item) => [item.itemId, item.title]));
  return ids.map((itemId) => {
    const offer = byId.get(itemId);
    return offer
      ? { status: "available", itemId, title: offer.title, offer }
      : { status: "unavailable", itemId, title: titles.get(itemId) ?? "Недоступный товар", offer: null };
  });
}
