import type { Metadata } from "next";
import { accountApiBaseUrl, loadAccountProfile } from "@/lib/accountAuth";
import { listCourses, listSellables } from "@/lib/catalogApi";

import { adaptCourseCheckout, type CheckoutCatalogItem } from "@/lib/commerceApi";

import CartPageClient from "./CartPageClient";

export const metadata: Metadata = {
  title: "Корзина",
  description: "Корзина Christmedschool для оформления выбранных товаров.",
  robots: {
    index: false,
    follow: false,
  },
};

export default async function CartPage() {
  const apiBaseUrl = accountApiBaseUrl();
  const account = await loadAccountProfile(apiBaseUrl, "/lk/cart");
  if (account.status === "authenticated" && account.profile.impersonation?.status === "active") {
    return (
      <main className="bg-white ui-text-primary" data-ui="checkout.screen" data-state="read-only">
        <section className="content-shell page-content page-gutter relative z-0 mx-auto w-full">
          <div className="mx-auto max-w-xl rounded-[var(--radius-surface)] border border-[var(--color-border-subtle)] bg-white p-5 sm:p-6" role="status" data-ui="checkout.readOnlyState">
            <h1 className="text-2xl font-bold leading-tight">Корзина недоступна</h1>
            <p className="mt-3 text-sm leading-6 ui-text-secondary">Оформление заказов отключено в режиме просмотра пользователя.</p>
          </div>
        </section>
      </main>
    );
  }
  const [legacyCourses, offers] = await Promise.all([listCourses(), listSellables()]);
  const courses = [...legacyCourses.map(adaptCourseCheckout).filter((item): item is CheckoutCatalogItem => item !== null), ...offers];

  return (
    <main className="bg-white ui-text-primary" data-ui="checkout.screen">
      <section className="content-shell page-content page-gutter relative z-0 mx-auto w-full" data-ui="checkout.content">
        <CartPageClient apiBaseUrl={apiBaseUrl} courses={courses} knownItems={legacyCourses.map(({ itemId, title }) => ({ itemId, title }))} />
      </section>
    </main>
  );
}
