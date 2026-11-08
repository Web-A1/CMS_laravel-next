"use client";

import { useRef, useState, useSyncExternalStore } from "react";
import Button, { ButtonRouteLink } from "@/components/button/Button";
import { addCartItem, CART_UPDATED_EVENT, isCartItemAdded } from "@/components/cart/cartState";
import { loadCartOffer } from "@/components/cart/loadCartOffer";

function subscribe(callback: () => void): () => void {
  window.addEventListener(CART_UPDATED_EVENT, callback);
  return () => window.removeEventListener(CART_UPDATED_EVENT, callback);
}
export default function OfferBuyButton({ sku }: { readonly sku: string }) {
  const added = useSyncExternalStore(subscribe, () => isCartItemAdded(sku), () => false);
  const [checking, setChecking] = useState(false);
  const [error, setError] = useState("");
  const busy = useRef(false);
  async function add(): Promise<void> {
    if (busy.current) return;
    busy.current = true;
    setChecking(true);
    setError("");
    try {
      const result = addCartItem(sku, await loadCartOffer(sku));
      if (result.status === "unavailable") setError("Этот товар сейчас недоступен для покупки.");
    } catch {
      setError("Не удалось проверить доступность покупки. Попробуйте ещё раз.");
    } finally {
      busy.current = false;
      setChecking(false);
    }
  }
  return (
    <>
      {added ? (
        <ButtonRouteLink href="/lk/cart" data-ui="offer.cartLink">Перейти в корзину</ButtonRouteLink>
      ) : (
        <Button label="В корзину" loading={checking} data-ui="offer.buyButton" onClick={() => void add()} />
      )}
      {error ? <p role="alert" className="mt-3 text-sm ui-text-secondary">{error}</p> : null}
    </>
  );
}
