import { notFound } from "next/navigation";
import { listSellables } from "@/lib/catalogApi";
import OfferBuyButton from "@/components/commerce/OfferBuyButton";
import { commercePrice } from "@/components/commerce/OfferList";

export default async function OfferPage({ params }: { readonly params: Promise<{ sku: string }> }) {
  const { sku } = await params;
  if (!/^[a-zA-Z0-9_-]{1,120}$/.test(sku)) notFound();
  const offer = (await listSellables()).find((item) => item.sku === sku);
  if (!offer) notFound();
  return <main className="content-shell page-content page-gutter" data-ui="offer.screen"><div className="max-w-3xl"><h1 className="text-3xl font-bold ui-text-primary">{offer.title}</h1>
    {offer.tariff ? <section className="mt-5" data-ui="offer.tariff"><h2 className="text-xl font-semibold">Тариф «{offer.tariff.label}»</h2><p className="mt-2 ui-text-secondary">{offer.tariff.supportDescription}</p></section> : null}
    <section className="mt-6" aria-labelledby="offer-contents"><h2 id="offer-contents" className="text-xl font-semibold">Состав</h2><ul className="mt-3 divide-y divide-[var(--color-border-subtle)]">{offer.atoms.map((atom) => <li className="py-3" key={`${atom.kind}:${atom.id}`}>{atom.title}<span className="ml-2 text-sm ui-text-secondary">{atom.kind === "guide" ? "PDF" : "Урок"}</span></li>)}</ul></section>
    <div className="mt-6 grid gap-3 border-t border-[var(--color-border-subtle)] pt-5"><p className="text-2xl font-semibold" data-ui="offer.price">{commercePrice(offer.price)}</p><OfferBuyButton sku={offer.sku} /></div>
  </div></main>;
}
