export type CheckoutPhase =
  | "cart"
  | "review"
  | "payment"
  | "pending"
  | "success"
  | "failed"
  | "cancelled"
  | "access";

export type CheckoutWidgetPayload = {
  readonly mode: "widget";
  readonly public_id: string;
  readonly invoice_id: string;
  readonly amount: string;
  readonly currency: string;
  readonly description: string;
};

export type CheckoutOrderStatus = {
  readonly order_id: number;
  readonly payment_status: string;
  readonly access_status: string;
  readonly pricing?: CheckoutPricing | null;
  readonly active_attempt: {
    readonly id: number;
    readonly provider: string;
    readonly status: string;
    readonly provider_payment_id: string | null;
    readonly provider_payment_url: string | null;
    readonly amount: number;
    readonly currency: string;
    readonly provider_payload: CheckoutWidgetPayload | null;
  } | null;
};

export type CheckoutPricing = {
  readonly discount_type: "coins" | "promo" | null;
  readonly subtotal: number;
  readonly discount: number;
  readonly payable_total: number;
  readonly coins_units: number;
  readonly reservation_status: string | null;
  readonly expires_at: string | null;
};

export function parseCheckoutPricing(value: unknown, orderId: number): CheckoutPricing {
  if (!isRecord(value) || value.order_id !== orderId || value.currency !== "RUB") throw new Error(CONTRACT_ERROR);
  for (const field of ["subtotal", "discount", "payable_total", "coins_units"]) {
    if (!Number.isSafeInteger(value[field]) || (value[field] as number) < 0) throw new Error(CONTRACT_ERROR);
  }
  const subtotal = readNonNegativeInteger(value, "subtotal");
  const discount = readNonNegativeInteger(value, "discount");
  const payable = readNonNegativeInteger(value, "payable_total");
  if (discount > subtotal || subtotal - discount !== payable
    || (value.discount_type != null && value.discount_type !== "coins" && value.discount_type !== "promo")
    || (value.reservation_status !== null && typeof value.reservation_status !== "string")
    || (value.expires_at !== null && (typeof value.expires_at !== "string" || !Number.isFinite(Date.parse(value.expires_at))))) {
    throw new Error(CONTRACT_ERROR);
  }
  return { discount_type: value.discount_type ?? null, subtotal, discount, payable_total: payable, coins_units: readNonNegativeInteger(value, "coins_units"),
    reservation_status: value.reservation_status, expires_at: value.expires_at };
}

type JsonRecord = Record<string, unknown>;

const CONTRACT_ERROR = "Платежный сервис вернул неожиданный ответ.";

function isRecord(value: unknown): value is JsonRecord {
  return typeof value === "object" && value !== null && !Array.isArray(value);
}

function readPositiveInteger(source: JsonRecord, field: string): number {
  const value = source[field];
  if (!Number.isSafeInteger(value) || (value as number) < 1) {
    throw new Error(CONTRACT_ERROR);
  }

  return value as number;
}

function readNonNegativeInteger(source: JsonRecord, field: string, fallback = 0): number {
  const value = source[field];
  if (value === undefined) {
    return fallback;
  }
  if (!Number.isSafeInteger(value) || (value as number) < 0) {
    throw new Error(CONTRACT_ERROR);
  }

  return value as number;
}

function readNonEmptyString(source: JsonRecord, field: string): string {
  const value = source[field];
  if (typeof value !== "string" || value.trim() === "") {
    throw new Error(CONTRACT_ERROR);
  }

  return value;
}

function parseWidgetPayload(value: unknown): CheckoutWidgetPayload | null {
  if (value === undefined || value === null) {
    return null;
  }
  if (!isRecord(value) || value.mode !== "widget") {
    throw new Error(CONTRACT_ERROR);
  }

  const amount = readNonEmptyString(value, "amount");
  const currency = readNonEmptyString(value, "currency").toUpperCase();
  if (!/^\d{1,12}\.\d{2}$/.test(amount) || Number(amount) <= 0 || !/^[A-Z]{3}$/.test(currency)) {
    throw new Error(CONTRACT_ERROR);
  }

  const description = value.description;
  if (description !== undefined && typeof description !== "string") {
    throw new Error(CONTRACT_ERROR);
  }

  return {
    mode: "widget",
    public_id: readNonEmptyString(value, "public_id"),
    invoice_id: readNonEmptyString(value, "invoice_id"),
    amount,
    currency,
    description: description ?? "",
  };
}

export function normalizeProviderPaymentUrl(value: unknown, trustedOrigin: string): string | null {
  if (value === null) {
    return null;
  }
  if (typeof value !== "string" || value === "" || value.trim() !== value || /[\u0000-\u001F\u007F\\]/.test(value)) {
    throw new Error(CONTRACT_ERROR);
  }

  try {
    const trustedUrl = new URL(trustedOrigin);
    const isRootRelative = value.startsWith("/") && !value.startsWith("//");
    const url = isRootRelative ? new URL(value, trustedUrl.origin) : new URL(value);

    if (
      url.username !== "" ||
      url.password !== "" ||
      url.hash !== "" ||
      (url.protocol !== "https:" && url.origin !== trustedUrl.origin)
    ) {
      throw new Error(CONTRACT_ERROR);
    }

    return url.href;
  } catch (error) {
    if (error instanceof Error && error.message === CONTRACT_ERROR) {
      throw error;
    }
    throw new Error(CONTRACT_ERROR);
  }
}

export function parseCheckoutOrderStatus(payload: unknown, trustedOrigin: string): CheckoutOrderStatus {
  if (!isRecord(payload)) {
    throw new Error(CONTRACT_ERROR);
  }

  let activeAttempt: CheckoutOrderStatus["active_attempt"] = null;
  if (payload.active_attempt !== undefined && payload.active_attempt !== null) {
    if (!isRecord(payload.active_attempt)) {
      throw new Error(CONTRACT_ERROR);
    }

    activeAttempt = {
      id: readPositiveInteger(payload.active_attempt, "id"),
      provider: readNonEmptyString(payload.active_attempt, "provider"),
      status: readNonEmptyString(payload.active_attempt, "status"),
      provider_payment_id:
        payload.active_attempt.provider_payment_id === undefined || payload.active_attempt.provider_payment_id === null
          ? null
          : readNonEmptyString(payload.active_attempt, "provider_payment_id"),
      provider_payment_url: normalizeProviderPaymentUrl(
        payload.active_attempt.provider_payment_url,
        trustedOrigin,
      ),
      amount: readNonNegativeInteger(payload.active_attempt, "amount"),
      currency:
        payload.active_attempt.currency === undefined
          ? "RUB"
          : readNonEmptyString(payload.active_attempt, "currency").toUpperCase(),
      provider_payload: parseWidgetPayload(payload.active_attempt.provider_payload),
    };
    const widget = activeAttempt.provider_payload;
    if (widget && (activeAttempt.provider !== "cloudpayments"
      || widget.invoice_id !== `cp-${readPositiveInteger(payload, "order_id")}-${activeAttempt.id}`
      || Math.round(Number(widget.amount) * 100) !== activeAttempt.amount
      || widget.currency !== activeAttempt.currency)) {
      throw new Error(CONTRACT_ERROR);
    }
  }

  return {
    order_id: readPositiveInteger(payload, "order_id"),
    payment_status: readNonEmptyString(payload, "payment_status"),
    access_status: readNonEmptyString(payload, "access_status"),
    pricing: payload.pricing == null ? null : parseCheckoutPricing(payload.pricing, readPositiveInteger(payload, "order_id")),
    active_attempt: activeAttempt,
  };
}

export function resolveCheckoutPhase(order: CheckoutOrderStatus): CheckoutPhase {
  if (order.payment_status === "paid" && order.access_status === "granted") {
    return "access";
  }
  if (order.payment_status === "paid") {
    return "success";
  }
  if (order.payment_status === "cancelled" || order.payment_status === "refunded") {
    return "cancelled";
  }
  if (order.active_attempt?.status === "failed") {
    return "failed";
  }
  if (order.payment_status === "pending_payment") {
    return "pending";
  }

  return "payment";
}

export function isRetryableCheckoutFailure(status: number): boolean {
  return status === 0 || status === 408 || status === 429 || status >= 500;
}
