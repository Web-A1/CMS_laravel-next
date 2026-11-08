import TextLink from "@/components/content/TextLink";
import type { CheckoutCatalogItem } from "@/lib/commerceApi";

export function commercePrice(price: number): string {
  return new Intl.NumberFormat("ru-RU", { style: "currency", currency: "RUB", maximumFractionDigits: 2 }).format(price / 100);
}
export default function OfferList({ offers }: { readonly offers: ReadonlyArray<CheckoutCatalogItem> }) {
  return <ul className="divide-y divide-[var(--color-border-subtle)]" data-ui="offers.list">
    {offers.map((offer) => <li key={offer.sku} className="flex flex-col gap-2 py-4 sm:flex-row sm:justify-between" data-ui="offers.item">
      <div className="min-w-0"><TextLink href={offer.publicPath}>{offer.title}</TextLink>{offer.tariff ? <p className="mt-1 text-sm ui-text-secondary">{offer.tariff.label}</p> : null}</div>
      <p className="shrink-0 ui-text-primary" data-ui="offers.price">{commercePrice(offer.price)}</p>
    </li>)}
  </ul>;
}
