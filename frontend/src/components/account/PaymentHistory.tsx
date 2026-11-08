"use client";

import { useEffect, useState } from "react";
import Button from "@/components/button/Button";

type HistoryOrder = {
  id: number; created_at: string; titles: string[]; currency: string; amount: number; paid: number; refunded: number;
  payment_status: string; access_status: string;
  refunds: Array<{ id: number; amount: number; status: string }>;
  receipts: Array<{ kind: string; status: string }>;
};
type HistoryPage = { orders: HistoryOrder[]; next_before_id: number | null };

function record(value: unknown): value is Record<string, unknown> {
  return typeof value === "object" && value !== null && !Array.isArray(value);
}
function integer(value: unknown): value is number {
  return typeof value === "number" && Number.isSafeInteger(value) && value >= 0;
}
export function parsePaymentHistory(value: unknown): HistoryPage {
  const error = new Error("Не удалось прочитать историю покупок.");
  if (!record(value) || !Array.isArray(value.orders) || !(value.next_before_id === null || integer(value.next_before_id))) throw error;
  const orders = value.orders.map((order: unknown): HistoryOrder => {
    if (!record(order) || !integer(order.id) || order.id < 1 || typeof order.created_at !== "string"
      || !Number.isFinite(Date.parse(order.created_at)) || !Array.isArray(order.titles) || !order.titles.every((title: unknown) => typeof title === "string")
      || typeof order.currency !== "string" || !/^[A-Z]{3}$/.test(order.currency)
      || !integer(order.amount) || !integer(order.paid) || !integer(order.refunded)
      || typeof order.payment_status !== "string" || typeof order.access_status !== "string"
      || !Array.isArray(order.refunds) || !Array.isArray(order.receipts)) throw error;
    const refunds = order.refunds.map((refund: unknown) => {
      if (!record(refund) || !integer(refund.id) || !integer(refund.amount) || typeof refund.status !== "string") throw error;
      return { id: refund.id, amount: refund.amount, status: refund.status };
    });
    const receipts = order.receipts.map((receipt: unknown) => {
      if (!record(receipt) || typeof receipt.kind !== "string" || typeof receipt.status !== "string") throw error;
      return { kind: receipt.kind, status: receipt.status };
    });
    return { id: order.id, created_at: order.created_at, titles: order.titles, currency: order.currency,
      amount: order.amount, paid: order.paid, refunded: order.refunded, payment_status: order.payment_status,
      access_status: order.access_status, refunds, receipts };
  });
  return { orders, next_before_id: value.next_before_id };
}

const paymentLabels: Record<string, string> = {
  draft: "Ожидает оформления", pending_payment: "Ожидает подтверждения оплаты", paid: "Оплачен",
  refunded: "Возврат подтверждён", cancelled: "Оплата не завершена",
};
const refundLabels: Record<string, string> = {
  pending: "Запрошен", submitted: "Обрабатывается", unknown: "Проверяем результат", failed: "Не выполнен", completed: "Подтверждён",
};
function money(amount: number, currency: string): string {
  return new Intl.NumberFormat("ru-RU", { style: "currency", currency }).format(amount / 100);
}

async function fetchHistory(apiBaseUrl: string, beforeId: number | null, signal?: AbortSignal): Promise<HistoryPage> {
  const query = beforeId === null ? "" : `?before_id=${beforeId}`;
  const response = await fetch(`${apiBaseUrl.replace(/\/+$/, "")}/api/v1/payments/history${query}`, {
    credentials: "include", headers: { Accept: "application/json" }, cache: "no-store", signal,
  });
  if (!response.ok) throw new Error("History request failed");
  return parsePaymentHistory(await response.json());
}

export default function PaymentHistory({ apiBaseUrl }: { readonly apiBaseUrl: string }) {
  const [page, setPage] = useState<HistoryPage | null>(null);
  const [error, setError] = useState("");
  const [loading, setLoading] = useState(true);
  useEffect(() => {
    const controller = new AbortController();
    void fetchHistory(apiBaseUrl, null, controller.signal).then((next) => {
      if (!controller.signal.aborted) { setPage(next); setError(""); }
    }).catch(() => {
      if (!controller.signal.aborted) setError("Не удалось загрузить историю покупок. Попробуйте ещё раз.");
    }).finally(() => { if (!controller.signal.aborted) setLoading(false); });
    return () => controller.abort();
  }, [apiBaseUrl]);
  async function loadMore(): Promise<void> {
    setLoading(true); setError("");
    try {
      const next = await fetchHistory(apiBaseUrl, page?.next_before_id ?? null);
      setPage((current) => ({ orders: [...(current?.orders ?? []), ...next.orders.filter((order) => !current?.orders.some((old) => old.id === order.id))], next_before_id: next.next_before_id }));
    } catch { setError("Не удалось загрузить историю покупок. Попробуйте ещё раз."); }
    finally { setLoading(false); }
  }

  return <div data-ui="lk.paymentHistory" aria-busy={loading}>
    {page?.orders.length === 0 ? <p className="text-sm ui-text-secondary">Покупок на новом сайте пока нет.</p> : null}
    {page ? <ol className="divide-y divide-[var(--color-border-subtle)]">
      {page.orders.map((order) => <li key={order.id} className="py-3" data-ui="lk.paymentHistory.order">
        <div className="flex flex-wrap items-baseline justify-between gap-2">
          <h2 className="text-sm font-medium">Заказ №{order.id}</h2>
          <time className="text-xs ui-text-secondary" dateTime={order.created_at}>{new Date(order.created_at).toLocaleDateString("ru-RU")}</time>
        </div>
        <p className="mt-2 text-sm">{order.titles.join(", ") || "Покупка"}</p>
        <p className="mt-1 text-sm ui-text-secondary">{paymentLabels[order.payment_status] ?? "Проверяем статус"} · {money(order.amount, order.currency)}</p>
        {order.refunded > 0 ? <p className="mt-1 text-sm">Возвращено: {money(order.refunded, order.currency)}</p> : null}
        {order.refunds.map((refund) => <p key={refund.id} className="mt-1 text-sm ui-text-secondary">Возврат №{refund.id}: {refundLabels[refund.status] ?? "Проверяем статус"} · {money(refund.amount, order.currency)}</p>)}
        {order.receipts.map((receipt, index) => <p key={`${receipt.kind}-${index}`} className="mt-1 text-xs ui-text-secondary">
          {receipt.kind === "refund" ? "Чек возврата" : "Чек оплаты"}: {receipt.status === "issued" ? "выпущен" : "выпуск пока не подтверждён"}
        </p>)}
      </li>)}
    </ol> : null}
    {loading ? <p role="status" className="text-sm ui-text-secondary">Загружаем покупки…</p> : null}
    {error ? <div role="alert"><p className="text-sm">{error}</p><Button type="button" variant="secondary" label="Повторить" disabled={loading} loading={loading} onClick={loadMore} /></div> : null}
    {page?.next_before_id !== null && page?.next_before_id !== undefined && !error ? <Button type="button" variant="secondary" label="Показать ещё" disabled={loading} loading={loading} onClick={loadMore} /> : null}
  </div>;
}
