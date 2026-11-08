"use client";

import Button from "@/components/button/Button";
import { clearCart } from "@/components/cart/cartState";
import CheckoutFlow from "@/components/checkout/CheckoutFlow";
import { LearningRouteAnnouncer } from "@/components/learning/LearningPathUi";
import type { CheckoutCatalogItem } from "@/lib/commerceApi";
import type { CartItemLabel } from "@/components/cart/cartCatalog";

type CartPageClientProps = {
  readonly apiBaseUrl: string;
  readonly courses: ReadonlyArray<CheckoutCatalogItem>;
  readonly knownItems: ReadonlyArray<CartItemLabel>;
};

export default function CartPageClient({ apiBaseUrl, courses, knownItems }: CartPageClientProps) {
  function handleClearCart(): void {
    clearCart();
  }

  return (
    <div className="mx-auto flex w-full flex-col gap-3">
      <div className="flex items-start justify-between gap-4">
        <h1 id="lk-cart-title" tabIndex={-1} className="text-2xl font-bold leading-tight ui-text-primary outline-none sm:text-3xl">
          Корзина
        </h1>
        <Button variant="ghost" size="sm" fullWidth={false}
          type="button"
          onClick={handleClearCart}
          data-ui="checkout.summary.clearCartButton"
        >
          Очистить корзину
        </Button>
      </div>
      <LearningRouteAnnouncer headingId="lk-cart-title" title="Корзина" />
      <CheckoutFlow apiBaseUrl={apiBaseUrl} courses={courses} knownItems={knownItems} />
    </div>
  );
}
