"use client";

import { useRef, useState, useSyncExternalStore } from "react";
import Button, { ButtonRouteLink } from "@/components/button/Button";
import { addCartItem, CART_UPDATED_EVENT, isCartItemAdded } from "../cart/cartState";
import { loadCartOffer } from "../cart/loadCartOffer";

type CoursePurchaseCtaProps = {
  readonly itemId: string;
  readonly available: boolean;
};

type PurchaseNotice = "idle" | "added";

function subscribeCartItems(onStoreChange: () => void): () => void {
  window.addEventListener(CART_UPDATED_EVENT, onStoreChange);
  return () => window.removeEventListener(CART_UPDATED_EVENT, onStoreChange);
}

function getServerCartItemSnapshot(): boolean {
  return false;
}

export default function CoursePurchaseCta({ itemId, available }: CoursePurchaseCtaProps) {
  const [notice, setNotice] = useState<PurchaseNotice>("idle");
  const [checking, setChecking] = useState(false);
  const [error, setError] = useState("");
  const [offerUnavailable, setOfferUnavailable] = useState(false);
  const unavailable = !available || offerUnavailable;
  const busy = useRef(false);
  const inCart = useSyncExternalStore(
    subscribeCartItems,
    () => isCartItemAdded(itemId),
    getServerCartItemSnapshot,
  );

  async function handlePurchaseClick(): Promise<void> {
    if (busy.current || unavailable) return;
    busy.current = true;
    setChecking(true);
    setError("");
    try {
      const offer = await loadCartOffer(itemId);
      const result = addCartItem(itemId, offer);
      setOfferUnavailable(result.status === "unavailable");
      setNotice(result.status === "added" ? "added" : "idle");
    } catch {
      setError("Не удалось проверить доступность покупки. Попробуйте ещё раз.");
    } finally {
      busy.current = false;
      setChecking(false);
    }
  }

  const noticeText = inCart && !unavailable
    ? notice === "added"
      ? "Курс добавлен в корзину."
      : "Курс уже в корзине."
    : null;

  return (
    <div className="mt-1">
      {unavailable ? <p className="text-sm ui-text-secondary" role="status" data-ui="course.purchase.unavailable">Покупка курса сейчас недоступна.</p> : null}
      {unavailable && inCart ? <ButtonRouteLink href="/lk/cart" className="mt-3 w-full min-[480px]:w-fit" fullWidth={false} data-ui="course.purchase.cartLink">Перейти в корзину</ButtonRouteLink> : null}
      {!inCart && !unavailable ? (
        <Button
          type="button"
          fullWidth={false}
          className="w-full min-[480px]:w-fit"
          onClick={() => void handlePurchaseClick()}
          loading={checking}
          data-ui="course.purchase.buyButton"
          data-state="idle"
        >
          Купить
        </Button>
      ) : null}
      {error ? <p className="mt-3 text-sm ui-text-secondary" role="alert" data-ui="course.purchase.error">{error}</p> : null}

      {noticeText ? (
        <div
          className={`${inCart ? "" : "mt-3"} flex flex-col gap-3 sm:flex-row sm:items-center`}
          data-ui="course.purchase.notice"
          data-state={notice === "added" ? "added" : "in-cart"}
        >
          <span className="text-sm ui-text-tertiary">{noticeText}</span>
          <ButtonRouteLink
            href="/lk/cart"
            className="w-full min-[480px]:w-fit" fullWidth={false}
            data-ui="course.purchase.cartLink"
          >
            Перейти в корзину
          </ButtonRouteLink>
        </div>
      ) : null}
    </div>
  );
}
