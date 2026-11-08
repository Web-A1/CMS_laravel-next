"use client";

import { useCallback, useEffect, useMemo, useRef, useState, useSyncExternalStore } from "react";
import { openCloudPaymentsWidget, type CheckoutPaymentMethod } from "./cloudPaymentsWidget";

import Button, { ButtonRouteLink, ButtonLink } from "@/components/button/Button";
import TextLink from "@/components/content/TextLink";
import { CART_UPDATED_EVENT, readCartItems, removeCartItem } from "@/components/cart/cartState";
import { resolveCartEntries, type CartItemLabel } from "@/components/cart/cartCatalog";
import {
  MULTI_COURSE_CHECKOUT_MESSAGE,
  multiCourseCheckoutMessage,
} from "@/components/checkout/checkoutCartPolicy";
import {
  parseCheckoutOrderStatus,
  resolveCheckoutPhase,
  type CheckoutOrderStatus,
  type CheckoutPhase,
} from "@/components/checkout/checkoutContract";
import { matchedOrderTariff, type CheckoutCatalogItem, type SellableTariff } from "@/lib/commerceApi";
type CommerceOrderStatus = CheckoutOrderStatus & { readonly tariff: SellableTariff | null };
import { applyRuPhoneMask, isCompleteRuPhone } from "@/lib/phone";

type CheckoutFlowProps = {
  readonly apiBaseUrl: string;
  readonly courses: ReadonlyArray<CheckoutCatalogItem>;
  readonly knownItems?: ReadonlyArray<CartItemLabel>;
};

type PaymentMethod = CheckoutPaymentMethod | "yandex-split" | "robokassa" | "podeli";
type PodeliCustomer = { first_name: string; last_name: string; phone: string; email: string };
type DiscountMode = "none" | "promo" | "coins";
type CheckoutTimelineStep = "cart" | "payment" | "access";


const checkoutTimelineSteps: ReadonlyArray<{
  readonly id: CheckoutTimelineStep;
  readonly label: string;
}> = [
  { id: "cart", label: "Корзина" },
  { id: "payment", label: "Оплата" },
  { id: "access", label: "Доступ" },
];

const paymentMethods: ReadonlyArray<{
  readonly id: PaymentMethod;
  readonly title: string;
  readonly description: string;
}> = [
  {
    id: "bank-card",
    title: "Банковская карта",
    description: "Переход на защищенную страницу провайдера.",
  },
  {
    id: "sbp",
    title: "СБП",
    description: "Оплата через приложение банка.",
  },
  {
    id: "mir-pay",
    title: "Mir Pay",
    description: "Оплата через приложение Mir Pay.",
  },
  { id: "yandex-split", title: "Яндекс Сплит", description: "Оплата частями через Яндекс." },
  { id: "robokassa", title: "Робокасса", description: "Оплата на странице Робокассы." },
  { id: "podeli", title: "Подели", description: "Оплата частями через Подели." },
];

const discountModes: ReadonlyArray<{
  readonly id: DiscountMode;
  readonly title: string;
  readonly description: string;
}> = [
  {
    id: "none",
    title: "Без скидки",
    description: "Оплатить товар без промокода и монеток.",
  },
  {
    id: "promo",
    title: "Промокод",
    description: "Проверим код и пересчитаем итоговую сумму.",
  },
  {
    id: "coins",
    title: "Монетки",
    description: "Проверим баланс и подготовим списание.",
  },
];

const checkoutSurfaceClass = "rounded-[var(--radius-surface)] border border-[var(--color-border-subtle)] bg-[var(--color-surface)] p-5 sm:p-6";
const checkoutAccessSurfaceClass =
  "rounded-[var(--radius-surface)] border border-[var(--color-brand)] bg-[var(--color-surface)] p-5 sm:p-6";
const checkoutActionGroupClass = "mt-5 flex flex-col gap-3";
const checkoutTextActionClass = "text-sm font-normal ui-text-muted-ink underline-offset-4 transition ui-text-soft-ink-hover hover:underline";
const phoneVerificationPollDelays = [1000, 1500, 2000, 2500, 3000, 4000, 5000];

function formatRubles(amount: number): string {
  return new Intl.NumberFormat("ru-RU", {
    style: "currency",
    currency: "RUB",
    minimumFractionDigits: 0,
    maximumFractionDigits: 2,
  }).format(amount / 100);
}

function subscribeCartItems(onStoreChange: () => void): () => void {
  window.addEventListener(CART_UPDATED_EVENT, onStoreChange);
  return () => window.removeEventListener(CART_UPDATED_EVENT, onStoreChange);
}

function getCartItemSnapshot(): string {
  return readCartItems().join("\n");
}

function getServerCartItemSnapshot(): string {
  return "";
}

function getTimelineStep(phase: CheckoutPhase): CheckoutTimelineStep {
  if (phase === "access") {
    return "access";
  }

  if (phase === "cart" || phase === "review") {
    return "cart";
  }

  return "payment";
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === "object" && value !== null && !Array.isArray(value);
}

function checkoutCoursePrice(course: CheckoutCatalogItem): number {
  return course.price;
}

function providerForPaymentMethod(paymentMethod: PaymentMethod): string {
  switch (paymentMethod) {
    case "bank-card":
    case "sbp":
    case "mir-pay":
      return "cloudpayments";
    case "yandex-split":
      return "yandex_split";
    case "robokassa":
    case "podeli":
      return paymentMethod;
  }
}

function readXsrfToken(): string {
  const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
  if (!match?.[1]) {
    return "";
  }

  try {
    return decodeURIComponent(match[1]);
  } catch {
    return "";
  }
}

async function ensureCsrfCookie(apiBaseUrl: string): Promise<void> {
  await fetch(`${apiBaseUrl}/sanctum/csrf-cookie`, {
    method: "GET",
    credentials: "include",
    headers: { Accept: "application/json" },
  });
}

async function parseCheckoutApiError(response: Response): Promise<string> {
  const payload = (await response.json().catch(() => null)) as unknown;
  if (!isRecord(payload)) {
    return "Платежный сервис временно недоступен.";
  }

  if (isRecord(payload.error) && typeof payload.error.message === "string" && payload.error.message !== "") {
    if (payload.error.code === "PAYMENT_CONFIRMATION_PENDING") {
      return "Банк ещё подтверждает платёж. Дождитесь обновления статуса перед повторной оплатой.";
    }
    if (payload.error.code === "PAYMENT_PROVIDER_NOT_CONFIGURED" || payload.error.code === "PAYMENT_CLOUDPAYMENTS_PUBLIC_ID_MISSING") {
      return "Оплата пока не подключена. Попробуйте позже.";
    }
    return payload.error.message;
  }

  if (typeof payload.message === "string" && payload.message !== "") {
    return payload.message;
  }

  return "Платежный сервис временно недоступен.";
}

async function fetchCheckoutJson(
  apiBaseUrl: string,
  path: string,
  options: {
    readonly method: "GET" | "POST";
    readonly body?: Record<string, unknown>;
    readonly idempotencyKey?: string;
  },
): Promise<unknown> {
  const headers: Record<string, string> = {
    Accept: "application/json",
  };

  let body: string | undefined;
  if (options.idempotencyKey) headers["Idempotency-Key"] = options.idempotencyKey;
  if (options.method === "POST") {
    await ensureCsrfCookie(apiBaseUrl);
    headers["Content-Type"] = "application/json";
    const xsrf = readXsrfToken();
    if (xsrf) {
      headers["X-XSRF-TOKEN"] = xsrf;
    }
    body = JSON.stringify(options.body ?? {});
  }

  const response = await fetch(`${apiBaseUrl}${path}`, {
    method: options.method,
    credentials: "include",
    headers,
    body,
  });

  if (!response.ok) {
    throw new Error(await parseCheckoutApiError(response));
  }

  return response.json();
}

async function createCheckoutOrder(apiBaseUrl: string, item: CheckoutCatalogItem): Promise<CommerceOrderStatus> {
  const payload = await fetchCheckoutJson(apiBaseUrl, "/api/v1/payments/orders", { method: "POST", body: { sellable_sku: item.sku } });
  return { ...parseCheckoutOrderStatus(payload, window.location.origin), tariff: matchedOrderTariff(payload, item) };
}

async function createPaymentAttempt(
  apiBaseUrl: string,
  orderId: number,
  paymentMethod: PaymentMethod,
  customer: PodeliCustomer,
): Promise<void> {
  const origin = window.location.origin;
  await fetchCheckoutJson(apiBaseUrl, `/api/v1/payments/orders/${orderId}/attempts`, {
    method: "POST",
    body: {
      provider: providerForPaymentMethod(paymentMethod),
      return_url: `${origin}/lk/cart`,
      cancel_url: `${origin}/lk/cart`,
      ...(paymentMethod === "podeli" ? { client_info: customer } : {}),
    },
  });
}

async function loadCheckoutOrder(apiBaseUrl: string, orderId: number, item: CheckoutCatalogItem | null): Promise<CommerceOrderStatus> {
  const payload = await fetchCheckoutJson(apiBaseUrl, `/api/v1/payments/orders/${orderId}`, { method: "GET" });
  if (item === null) throw new Error("Состав заказа не совпадает с товаром в корзине.");
  return { ...parseCheckoutOrderStatus(payload, window.location.origin), tariff: matchedOrderTariff(payload, item) };
}

export default function CheckoutFlow(props: CheckoutFlowProps) {
  const cartItemSnapshot = useSyncExternalStore(subscribeCartItems, getCartItemSnapshot, getServerCartItemSnapshot);
  const selectedIds = cartItemSnapshot.split("\n");
  const skuSnapshot = props.courses.filter((course) => selectedIds.includes(course.itemId)).map((course) => course.sku).join("\n");
  return <CheckoutFlowState key={`${cartItemSnapshot}:${skuSnapshot}`} {...props} cartItemSnapshot={cartItemSnapshot} />;
}

function CheckoutFlowState({ apiBaseUrl, courses, knownItems, cartItemSnapshot }: CheckoutFlowProps & { readonly cartItemSnapshot: string }) {
  const [phase, setPhase] = useState<CheckoutPhase>("cart");
  const [paymentMethod, setPaymentMethod] = useState<PaymentMethod>("bank-card");
  const [additionalMethods, setAdditionalMethods] = useState<ReadonlyArray<string>>([]);
  const [podeliCustomer, setPodeliCustomer] = useState<PodeliCustomer>({ first_name: "", last_name: "", phone: "", email: "" });
  const [checkoutPhone, setCheckoutPhone] = useState("");
  const [checkoutPhoneVerified, setCheckoutPhoneVerified] = useState(false);
  const [checkoutPhonePending, setCheckoutPhonePending] = useState(false);
  const [checkoutPhoneCallNumber, setCheckoutPhoneCallNumber] = useState("");
  const [checkoutPhoneError, setCheckoutPhoneError] = useState("");
  const [discountMode, setDiscountMode] = useState<DiscountMode>("none");
  const [coinBalance, setCoinBalance] = useState<number | null>(null);
  const [coinInput, setCoinInput] = useState("");
  const [promoInput, setPromoInput] = useState("");
  const [applyingCoins, setApplyingCoins] = useState(false);
  const [reservationClock, setReservationClock] = useState(0);
  useEffect(() => {
    const timer = window.setInterval(() => setReservationClock(Date.now()), 1000);
    return () => window.clearInterval(timer);
  }, []);
  const coinRequestKey = useRef<string | null>(null);
  const [checkoutError, setCheckoutError] = useState("");
  const [isSubmittingPayment, setIsSubmittingPayment] = useState(false);
  const [isRefreshingStatus, setIsRefreshingStatus] = useState(false);
  const [orderStatus, setOrderStatus] = useState<CommerceOrderStatus | null>(null);
  const paymentBusy = useRef(false);
  const preparingPayment = useRef(false);
  const statusRequest = useRef(0);
  const currentOrderId = useRef<number | null>(null);
  const acceptedOrder = useRef<CommerceOrderStatus | null>(null);
  const mounted = useRef(true);
  useEffect(() => {
    mounted.current = true;
    return () => { mounted.current = false; statusRequest.current += 1; };
  }, []);
  const normalizedApiBaseUrl = apiBaseUrl.replace(/\/+$/, "");
  useEffect(() => {
    let active = true;
    void fetchCheckoutJson(normalizedApiBaseUrl, "/api/v1/payments/coins", { method: "GET" }).then((payload: unknown) => {
      if (active && isRecord(payload) && payload.enabled === true && payload.coin_minor_units === 100
        && typeof payload.available_units === "number" && Number.isSafeInteger(payload.available_units) && payload.available_units >= 0) {
        setCoinBalance(payload.available_units);
      }
    }).catch(() => { /* Keep unavailable balances unknown. */ });
    return () => { active = false; };
  }, [normalizedApiBaseUrl]);
  useEffect(() => {
    let active = true;
    void fetchCheckoutJson(normalizedApiBaseUrl, "/api/v1/auth/me", { method: "GET" }).then((payload: unknown) => {
      if (!active || !isRecord(payload)) return;
      const verified = payload.phone_verified === true;
      setCheckoutPhoneVerified(verified);
      const verifiedPhone = typeof payload.phone === "string" ? payload.phone : null;
      if (verified && verifiedPhone) {
        setCheckoutPhone(verifiedPhone);
        setPodeliCustomer((current) => ({ ...current, phone: verifiedPhone.replace(/\D/g, "").replace(/^8/, "7") }));
      }
    }).catch(() => { /* Payment request remains backend-authoritative. */ });
    return () => { active = false; };
  }, [normalizedApiBaseUrl]);
  useEffect(() => {
    let active = true;
    void fetchCheckoutJson(normalizedApiBaseUrl, "/api/v1/payments/methods", { method: "GET" }).then((payload: unknown) => {
      if (active && isRecord(payload) && Array.isArray(payload.additional_methods)) {
        setAdditionalMethods(payload.additional_methods.filter((value): value is string => typeof value === "string"));
      }
    }).catch(() => { /* Additional providers stay hidden until backend confirms configuration. */ });
    return () => { active = false; };
  }, [normalizedApiBaseUrl]);
  const visiblePaymentMethods = paymentMethods.filter((method) =>
    ["bank-card", "sbp", "mir-pay"].includes(method.id) || additionalMethods.includes(method.id));
  const cartItemIds = useMemo(() => cartItemSnapshot.split("\n").filter(Boolean), [cartItemSnapshot]);
  const cartEntries = useMemo(() => resolveCartEntries(cartItemIds, courses, knownItems), [cartItemIds, courses, knownItems]);
  const unavailableEntries = cartEntries.filter((entry) => entry.status === "unavailable");
  const cartCourses = useMemo(
    () => cartEntries.flatMap((entry) => entry.offer ? [entry.offer] : []),
    [cartEntries],
  );
  const cartSubtotal = cartCourses.reduce((sum, course) => sum + checkoutCoursePrice(course), 0);
  const activeTimelineStep = getTimelineStep(phase);
  const activeTimelineIndex = checkoutTimelineSteps.findIndex((step) => step.id === activeTimelineStep);
  const selectedPaymentMethod = useMemo(
    () => paymentMethods.find((method) => method.id === paymentMethod) ?? paymentMethods[0],
    [paymentMethod],
  );
  const discountSummaryLabel =
    orderStatus?.pricing?.discount_type === "promo" ? "Промокод" : orderStatus?.pricing?.coins_units ? "Монетки" : discountMode === "promo" ? "Промокод" : discountMode === "coins" ? "Монетки" : "Скидка";
  const coinBinding = Boolean(orderStatus?.pricing?.coins_units || orderStatus?.pricing?.discount_type === "promo");
  const isZeroCashOrder = coinBinding && orderStatus?.pricing?.payable_total === 0;
  const paymentActionLabel = isZeroCashOrder ? "Подтвердить заказ" : "Перейти к оплате";
  const paymentProgressLabel = isZeroCashOrder ? "Подтверждаем заказ" : "Создаем платеж";
  const canRestartCoinOrder = coinBinding && orderStatus?.payment_status !== "paid" && orderStatus?.payment_status !== "refunded"
    && orderStatus?.active_attempt?.status !== "pending"
    && (orderStatus?.payment_status === "cancelled" || orderStatus?.pricing?.reservation_status === "released"
      || Boolean(orderStatus?.pricing?.expires_at && Date.parse(orderStatus.pricing.expires_at) <= reservationClock));
  const discountSummaryValue = orderStatus?.pricing ? formatRubles(orderStatus.pricing.discount) : discountMode === "none" ? formatRubles(0) : "После проверки";
  const totalSummaryValue = orderStatus?.pricing ? formatRubles(orderStatus.pricing.payable_total) : discountMode === "none" ? formatRubles(cartSubtotal) : "После проверки";
  const selectedCartCourse = cartCourses.length === 1 && unavailableEntries.length === 0 ? cartCourses[0] : null;
  const selectedCourseId = selectedCartCourse;
  const multiCourseBlockerMessage = multiCourseCheckoutMessage(cartCourses.length);
  const cartBlockerMessage = unavailableEntries.length
    ? "Удалите недоступные товары, чтобы продолжить оформление."
    : multiCourseBlockerMessage;
  const selectedCourseUnavailableMessage =
    selectedCartCourse && !selectedCartCourse.sku
      ? "Этот товар временно недоступен для оплаты: не найден активный SKU."
      : "";
  const podeliCustomerReady = podeliCustomer.first_name.trim() !== "" && podeliCustomer.last_name.trim() !== ""
    && /^7\d{10}$/.test(podeliCustomer.phone) && /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(podeliCustomer.email);
  const canStartPayment = Boolean(selectedCartCourse?.sku) && !isSubmittingPayment
    && !applyingCoins && (coinBinding || discountMode === "none")
    && checkoutPhoneVerified
    && (orderStatus?.pricing?.payable_total === 0 || paymentMethod !== "podeli" || podeliCustomerReady);
  const paymentBlockerMessage =
    checkoutError || cartBlockerMessage || (phase === "payment" ? selectedCourseUnavailableMessage : "");
  const providerPaymentUrl = orderStatus?.active_attempt?.provider_payment_url ?? null;
  const widgetPayload = orderStatus?.active_attempt?.provider_payload ?? null;

  async function startCheckoutPhoneVerification(): Promise<void> {
    if (checkoutPhonePending || checkoutPhoneVerified) return;
    setCheckoutPhoneError("");
    setCheckoutPhoneCallNumber("");
    if (!isCompleteRuPhone(checkoutPhone)) {
      setCheckoutPhoneError("Введите телефон полностью.");
      return;
    }
    setCheckoutPhonePending(true);
    try {
      const startPayload = await fetchCheckoutJson(normalizedApiBaseUrl, "/api/v1/auth/phone-verification/start", {
        method: "POST", body: { phone: checkoutPhone },
      });
      if (!isRecord(startPayload) || typeof startPayload.verification_id !== "string") {
        throw new Error("Не удалось начать подтверждение телефона.");
      }
      const verificationId = startPayload.verification_id;
      let callNumber = typeof startPayload.call_number === "string" ? startPayload.call_number : "";
      setCheckoutPhoneCallNumber(callNumber);
      for (const delay of phoneVerificationPollDelays) {
        await new Promise((resolve) => setTimeout(resolve, delay));
        const statusPayload = await fetchCheckoutJson(
          normalizedApiBaseUrl,
          `/api/v1/auth/phone-verification/status?verification_id=${encodeURIComponent(verificationId)}`,
          { method: "GET" },
        );
        if (!isRecord(statusPayload)) throw new Error("Не удалось проверить телефон.");
        if (typeof statusPayload.call_number === "string" && statusPayload.call_number !== callNumber) {
          callNumber = statusPayload.call_number;
          setCheckoutPhoneCallNumber(callNumber);
        }
        if (statusPayload.status === "confirmed") {
          setCheckoutPhoneVerified(true);
          setCheckoutPhoneCallNumber("");
          setPodeliCustomer((current) => ({ ...current, phone: checkoutPhone.replace(/\D/g, "").replace(/^8/, "7") }));
          return;
        }
        if (statusPayload.status === "failed") throw new Error("Подтверждение не выполнено. Повторите попытку.");
      }
      throw new Error("Подтверждение еще в обработке. Повторите проверку через несколько секунд.");
    } catch (error) {
      setCheckoutPhoneError(error instanceof Error ? error.message : "Не удалось подтвердить телефон.");
    } finally {
      setCheckoutPhonePending(false);
    }
  }

  const applyOrderStatus = useCallback((nextOrderStatus: CommerceOrderStatus): void => {
    currentOrderId.current = nextOrderStatus.order_id;
    acceptedOrder.current = nextOrderStatus;
    setOrderStatus(nextOrderStatus);
    if (nextOrderStatus.pricing?.discount_type) setDiscountMode(nextOrderStatus.pricing.discount_type);
    setPhase(resolveCheckoutPhase(nextOrderStatus));
  }, []);

  function resetBackendPaymentState(): void {
    statusRequest.current += 1;
    currentOrderId.current = null;
    acceptedOrder.current = null;
    coinRequestKey.current = null;
    setCheckoutError("");
    setOrderStatus(null);
    const url = new URL(window.location.href);
    url.searchParams.delete("payment_order");
    url.searchParams.delete("payment_sku");
    window.history.replaceState(null, "", url);
  }

  function handleRemoveCartItem(itemId: string): void {
    if (paymentBusy.current) return;
    resetBackendPaymentState();
    setPhase("cart");
    removeCartItem(itemId);
  }

  const refreshOrderById = useCallback(async (orderId: number): Promise<void> => {
    const requestId = ++statusRequest.current;
    setIsRefreshingStatus(true);
    try {
      const nextOrder = await loadCheckoutOrder(normalizedApiBaseUrl, orderId, selectedCourseId);
      if (requestId !== statusRequest.current || currentOrderId.current !== orderId) return;
      if (["paid", "cancelled", "refunded"].includes(nextOrder.payment_status)) setCheckoutError("");
      applyOrderStatus(nextOrder);
    } catch (error) {
      if (requestId === statusRequest.current && currentOrderId.current === orderId) {
        setCheckoutError(error instanceof Error ? error.message : "Не удалось обновить статус платежа.");
      }
    } finally {
      if (requestId === statusRequest.current) setIsRefreshingStatus(false);
    }
  }, [normalizedApiBaseUrl, applyOrderStatus, selectedCourseId]);

  const selectedSku = selectedCartCourse?.sku;
  useEffect(() => {
    const url = new URL(window.location.href);
    const id = Number(url.searchParams.get("payment_order"));
    if (selectedSku && url.searchParams.get("payment_sku") === selectedSku
      && Number.isSafeInteger(id) && id > 0) {
      currentOrderId.current = id;
      const requestId = ++statusRequest.current;
      void loadCheckoutOrder(normalizedApiBaseUrl, id, selectedCourseId).then((order) => {
        if (requestId === statusRequest.current && currentOrderId.current === id) applyOrderStatus(order);
      }).catch((error: unknown) => {
        if (requestId === statusRequest.current) {
          setCheckoutError(error instanceof Error ? error.message : "Не удалось восстановить заказ.");
        }
      });
    }
    return () => { statusRequest.current += 1; };
  }, [selectedSku, selectedCourseId, normalizedApiBaseUrl, applyOrderStatus]);

  const pendingOrderId = orderStatus && (phase === "pending" || phase === "success") ? orderStatus.order_id : null;
  useEffect(() => {
    if (pendingOrderId === null) return;
    let stopped = false;
    let timer: ReturnType<typeof setTimeout>;
    const deadline = Date.now() + 120_000;
    const poll = async () => {
      if (!preparingPayment.current) await refreshOrderById(pendingOrderId);
      if (!stopped && Date.now() < deadline) timer = setTimeout(() => void poll(), 2_000);
    };
    timer = setTimeout(() => void poll(), 2_000);
    return () => { stopped = true; clearTimeout(timer); };
  }, [pendingOrderId, refreshOrderById]);

  async function refreshOrderStatus(): Promise<void> {
    if (applyingCoins) return;
    setCheckoutError("");
    if (orderStatus) await refreshOrderById(orderStatus.order_id);
  }

  async function applyCoins(mode: "coins" | "promo" = "coins"): Promise<void> {
    if (paymentBusy.current || !selectedCartCourse?.sku || coinBalance === null) return;
    const units = Number(coinInput);
    if (mode === "coins" && (!Number.isSafeInteger(units) || units <= 0 || units > coinBalance)) {
      setCheckoutError("Укажите целое число монеток в пределах доступного баланса.");
      return;
    }
    if (mode === "promo" && !/^[A-Za-z0-9_-]{1,64}$/.test(promoInput)) {
      setCheckoutError("Введите промокод без пробелов.");
      return;
    }
    paymentBusy.current = true;
    preparingPayment.current = true;
    statusRequest.current += 1;
    setApplyingCoins(true);
    setIsRefreshingStatus(false);
    setCheckoutError("");
    try {
      let order = currentOrderId.current === null ? null
        : await loadCheckoutOrder(normalizedApiBaseUrl, currentOrderId.current, selectedCourseId);
      if (!mounted.current) return;
      if (!order || order.payment_status === "cancelled") {
        coinRequestKey.current = null;
        order = await createCheckoutOrder(normalizedApiBaseUrl, selectedCartCourse);
        if (!mounted.current) return;
        currentOrderId.current = order.order_id;
        const url = new URL(window.location.href);
        url.searchParams.set("payment_order", String(order.order_id));
        url.searchParams.set("payment_sku", selectedCartCourse.sku);
        window.history.replaceState(null, "", url);
      }
      if (!order.pricing?.coins_units && !order.pricing?.discount_type) {
        coinRequestKey.current ??= crypto.randomUUID();
        await fetchCheckoutJson(normalizedApiBaseUrl, `/api/v1/payments/orders/${order.order_id}/${mode === "coins" ? "coins" : "promo"}`, {
          method: "POST", body: mode === "coins" ? { coins_units: units } : { code: promoInput }, idempotencyKey: coinRequestKey.current,
        });
      }
      const requestId = ++statusRequest.current;
      const updated = await loadCheckoutOrder(normalizedApiBaseUrl, order.order_id, selectedCourseId);
      if (!mounted.current || requestId !== statusRequest.current) return;
      applyOrderStatus(updated);
      if (updated.payment_status === "pending_payment") setPhase("cart");
    } catch (error) {
      if (mounted.current) setCheckoutError(error instanceof Error ? error.message : "Не удалось применить скидку.");
    } finally {
      paymentBusy.current = false;
      preparingPayment.current = false;
      if (mounted.current) setApplyingCoins(false);
    }
  }

  async function startPayment(): Promise<void> {
    if (paymentBusy.current) return;
    setCheckoutError("");
    if (cartCourses.length !== 1) {
      setCheckoutError(MULTI_COURSE_CHECKOUT_MESSAGE);
      setPhase("cart");
      return;
    }
    if (!selectedCartCourse?.sku) {
      setCheckoutError(selectedCourseUnavailableMessage);
      return;
    }
    if (!checkoutPhoneVerified) {
      setCheckoutPhoneError("Подтвердите телефон перед оплатой.");
      return;
    }
    if (!coinBinding && discountMode !== "none") {
      setCheckoutError("Сначала примените скидку или выберите оплату без скидки.");
      return;
    }
    if (orderStatus?.pricing?.payable_total !== 0 && paymentMethod === "podeli" && !podeliCustomerReady) {
      setCheckoutError("Укажите имя, фамилию, email и телефон в формате 79991234567.");
      return;
    }

    paymentBusy.current = true;
    preparingPayment.current = true;
    setIsSubmittingPayment(true);
    let latestOrder: CommerceOrderStatus | null = null;
    let operationRequest = ++statusRequest.current;
    let formStarted = false;
    try {
      let order = currentOrderId.current === null
        ? null
        : await loadCheckoutOrder(normalizedApiBaseUrl, currentOrderId.current, selectedCourseId);
      if (operationRequest !== statusRequest.current) return;
      latestOrder = order;
      if (order && (order.payment_status === "paid" || order.payment_status === "refunded")) {
        applyOrderStatus(order);
        return;
      }
      if (!order || order.payment_status === "cancelled") {
        if (coinBinding) throw new Error("Заказ отменён. Рассчитайте новый заказ и примените скидку заново.");
        order = await createCheckoutOrder(normalizedApiBaseUrl, selectedCartCourse);
        if (operationRequest !== statusRequest.current) return;
        latestOrder = order;
        currentOrderId.current = order.order_id;
        setOrderStatus(order);
        const url = new URL(window.location.href);
        url.searchParams.set("payment_order", String(order.order_id));
        url.searchParams.set("payment_sku", selectedCartCourse.sku);
        window.history.replaceState(null, "", url);
      }
      if (order.pricing?.payable_total === 0 && (order.pricing.coins_units > 0 || order.pricing.discount_type === "promo")) {
        await fetchCheckoutJson(normalizedApiBaseUrl, `/api/v1/payments/orders/${order.order_id}/discount-settlement`, {
          method: "POST", idempotencyKey: `discount-settle:${order.order_id}`,
        });
      } else {
        await createPaymentAttempt(normalizedApiBaseUrl, order.order_id, paymentMethod, podeliCustomer);
      }
      if (operationRequest !== statusRequest.current) return;
      operationRequest = ++statusRequest.current;
      const nextOrder = await loadCheckoutOrder(normalizedApiBaseUrl, order.order_id, selectedCourseId);
      if (operationRequest !== statusRequest.current) return;
      latestOrder = nextOrder;
      applyOrderStatus(nextOrder);
      const payload = nextOrder.active_attempt?.provider_payload;
      if (nextOrder.payment_status === "pending_payment" && payload
        && nextOrder.active_attempt?.provider === "cloudpayments"
        && (paymentMethod === "bank-card" || paymentMethod === "sbp" || paymentMethod === "mir-pay")) {
        preparingPayment.current = false;
        formStarted = true;
        try {
          await openCloudPaymentsWidget(payload, paymentMethod, () => {
            if (mounted.current) void refreshOrderById(nextOrder.order_id);
          }, () => mounted.current && acceptedOrder.current?.payment_status === "pending_payment");
        } finally {
          if (mounted.current) await refreshOrderById(nextOrder.order_id);
        }
      }
    } catch (error) {
      if (!mounted.current) return;
      if (!formStarted && operationRequest !== statusRequest.current) return;
      if (!formStarted && latestOrder?.payment_status === "pending_payment") {
        await refreshOrderById(latestOrder.order_id);
      }
      const current = acceptedOrder.current;
      if (current && ["paid", "cancelled", "refunded"].includes(current.payment_status)) {
        applyOrderStatus(current);
        return;
      }
      setCheckoutError(error instanceof Error ? error.message : "Не удалось подготовить платеж.");
      setPhase(latestOrder?.payment_status === "pending_payment" ? "pending" : "failed");
    } finally {
      paymentBusy.current = false;
      preparingPayment.current = false;
      setIsSubmittingPayment(false);
    }
  }

  return (
    <div className="mx-auto flex min-w-0 w-full flex-col gap-4" data-ui="checkout.flow" data-state={phase}>
      <div className="flex flex-col gap-0">
        {cartEntries.length === 0 ? (
          <section className={checkoutSurfaceClass} aria-labelledby="checkout-empty-title" data-ui="checkout.emptyCart" data-state="empty">
            <h2 id="checkout-empty-title" className="text-xl font-bold leading-tight">
              Корзина пуста
            </h2>
            <p className="mt-3 text-sm leading-6 ui-text-secondary">
              Добавьте товар из каталога, чтобы перейти к проверке данных и оплате.
            </p>
            <div className={checkoutActionGroupClass}>
              <ButtonRouteLink href="/subjects" data-ui="checkout.emptyCart.subjectsLink">
                Выбрать курс
              </ButtonRouteLink>
            </div>
          </section>
        ) : (
          <>
          {unavailableEntries.length ? (
            <section className="border-t border-[var(--color-border-subtle)] py-2" aria-label="Недоступные товары" data-ui="checkout.unavailableItems">
              <p className="py-3 text-sm ui-text-secondary" role="status">Некоторые товары сейчас недоступны для покупки. Удалите их, чтобы продолжить оформление.</p>
              <ul className="divide-y divide-[var(--color-border-subtle)]">
                {unavailableEntries.map((entry) => (
                  <li key={entry.itemId} className="py-4" data-ui="checkout.unavailableItem" data-cart-item-id={entry.itemId}>
                    <p className="text-base ui-text-primary">{entry.title}</p>
                    <p className="mt-1 text-sm ui-text-secondary">Сейчас недоступен для покупки. В сумму заказа не включён.</p>
                    <Button type="button" variant="ghost" size="sm" fullWidth={false} className="mt-1" data-ui="checkout.unavailableItem.removeButton" onClick={() => handleRemoveCartItem(entry.itemId)}>Удалить</Button>
                  </li>
                ))}
              </ul>
            </section>
          ) : null}
          {cartCourses.length > 0 ? <>
          <section className="border-t border-[var(--color-border-subtle)] py-2" aria-label="Товары в заказе" data-ui="checkout.courseList">
            <ul className="divide-y divide-[var(--color-border-subtle)]">
              {cartCourses.map((course) => (
                <li
                  key={course.itemId}
                  className="flex items-start justify-between gap-4 py-4"
                  data-ui="checkout.courseCard"
                  data-cart-item-id={course.itemId}
                >
                  <div className="min-w-0">
                    <p className="truncate text-base font-normal ui-text-primary" data-ui="checkout.courseCard.service">
                      {course.title}
                    </p>
                    {(orderStatus ? orderStatus.tariff : course.tariff) ? <div className="mt-2 text-sm ui-text-secondary" data-ui="checkout.tariff"><p>{(orderStatus ? orderStatus.tariff : course.tariff)?.label}</p><p>{(orderStatus ? orderStatus.tariff : course.tariff)?.supportDescription}</p></div> : null}
                    <Button variant="ghost" size="sm" fullWidth={false}
                      type="button"
                      className="mt-1"
                      data-ui="checkout.courseCard.removeButton"
                      onClick={() => handleRemoveCartItem(course.itemId)}
                    >
                      Удалить
                    </Button>
                  </div>
                  <div className="shrink-0 text-right text-base font-normal ui-text-primary" data-ui="checkout.courseCard.price">
                    {formatRubles(checkoutCoursePrice(course))}
                  </div>
                </li>
              ))}
            </ul>
          </section>

          <details className="border-t border-[var(--color-border-subtle)] py-4" data-ui="checkout.discount" data-state={discountMode}>
            <summary className="flex cursor-pointer list-none items-center justify-between gap-4">
              <span className="text-sm font-normal ui-text-secondary">Промокод или монетки</span>
              <span className={checkoutTextActionClass}>Выбрать</span>
            </summary>

            <fieldset className="mt-4 space-y-2" data-ui="checkout.discount.modes">
              <legend className="sr-only">Выберите способ уменьшить сумму заказа</legend>
              {discountModes.map((mode) => (
                <label
                  key={mode.id}
                  className={`flex min-h-12 cursor-pointer items-start gap-3 rounded-[var(--radius-control)] border px-4 py-3 transition ${
                    discountMode === mode.id
                      ? "border-[var(--color-brand)] bg-[var(--color-brand-surface)]"
                      : "border-[var(--color-border-subtle)] bg-white ui-surface-muted-hover"
                  }`}
                  data-ui={`checkout.discount.mode.${mode.id}`}
                  data-state={discountMode === mode.id ? "selected" : "idle"}
                >
                  <input
                    type="radio"
                    name="discount-mode"
                    value={mode.id}
                    checked={discountMode === mode.id}
                    disabled={applyingCoins || isSubmittingPayment || coinBinding}
                    onChange={() => setDiscountMode(mode.id)}
                    className="mt-1 h-4 w-4 accent-[var(--color-brand)]"
                    data-ui={`checkout.discount.mode.${mode.id}.input`}
                  />
                  <span>
                    <span className="block text-sm font-normal ui-text-primary">{mode.title}</span>
                    <span className="mt-1 block text-sm leading-6 ui-text-secondary">{mode.description}</span>
                  </span>
                </label>
              ))}
            </fieldset>

            {discountMode === "promo" ? (
              <div className="mt-4 rounded-[var(--radius-inner)] border border-[var(--color-border-subtle)] bg-white p-4" data-ui="checkout.discount.promoForm" data-state="quote-pending">
                <label className="block text-sm font-semibold ui-text-primary" htmlFor="checkout-promo-code">
                  Промокод
                </label>
                <input
                  id="checkout-promo-code"
                  type="text"
                  placeholder="Введите промокод"
                  value={promoInput}
                  onChange={(event) => setPromoInput(event.target.value)}
                  className="ui-input mt-3 h-11 w-full rounded-[var(--radius-control)] border px-4 text-sm uppercase outline-none"
                  disabled={coinBalance === null || applyingCoins || coinBinding || isSubmittingPayment}
                  data-ui="checkout.discount.promoInput"
                />
                <Button
                  type="button"
                  variant="secondary"
                  className="mt-3"
                  disabled={coinBalance === null || applyingCoins || coinBinding || isSubmittingPayment || !selectedCartCourse?.sku}
                  onClick={() => void applyCoins("promo")}
                  data-ui="checkout.discount.promoApplyButton"
                  label={orderStatus?.pricing?.discount_type === "promo" ? "Промокод применён" : applyingCoins ? "Проверяем…" : coinBalance === null ? "Проверка пока недоступна" : "Применить промокод"}
                />
              </div>
            ) : null}

            {discountMode === "coins" ? (
              <div className="mt-4 rounded-[var(--radius-inner)] border border-[var(--color-border-subtle)] bg-white p-4" data-ui="checkout.discount.coinsForm" data-state="quote-pending">
                <p className="text-sm leading-6 ui-text-secondary">{coinBinding ? `Применено ${orderStatus?.pricing?.coins_units} монеток.` : coinBalance === null ? "Списание монеток пока недоступно." : `Доступно ${coinBalance} монеток. 1 монетка = 1 ₽.`}</p>
                <input
                  type="number"
                  inputMode="numeric"
                  aria-label="Количество монеток для списания"
                  min={1}
                  step={1}
                  value={coinInput}
                  onChange={(event) => setCoinInput(event.target.value)}
                  placeholder="Сколько списать"
                  className="ui-input mt-4 h-11 w-full rounded-[var(--radius-control)] border px-4 text-sm outline-none"
                  disabled={coinBalance === null || applyingCoins || coinBinding || isSubmittingPayment}
                  data-ui="checkout.discount.coinsInput"
                />
                <Button
                  type="button"
                  variant="secondary"
                  className="mt-3"
                  disabled={coinBalance === null || applyingCoins || coinBinding || isSubmittingPayment || !selectedCartCourse?.sku}
                  onClick={() => void applyCoins()}
                  data-ui="checkout.discount.coinsMaxButton"
                  label={coinBinding ? "Монетки применены" : applyingCoins ? "Проверяем…" : coinBalance === null ? "Списание пока недоступно" : "Применить монетки"}
                />
              </div>
            ) : null}

            {canRestartCoinOrder ? (
              <Button type="button" variant="secondary" className="mt-3" label="Рассчитать новый заказ"
                disabled={applyingCoins || isSubmittingPayment} data-ui="checkout.discount.coinsRestart"
                onClick={() => {
                  statusRequest.current += 1;
                  currentOrderId.current = null;
                  acceptedOrder.current = null;
                  coinRequestKey.current = null;
                  setOrderStatus(null);
                  setDiscountMode(orderStatus?.pricing?.discount_type === "promo" ? "promo" : "coins");
                  setCheckoutError("");
                  setPhase("cart");
                  const url = new URL(window.location.href);
                  url.searchParams.delete("payment_order");
                  url.searchParams.delete("payment_sku");
                  window.history.replaceState(null, "", url);
                }} />
            ) : null}

            <p className="mt-4 text-sm leading-6 ui-text-secondary" data-ui="checkout.discount.ruleHint">
              Промокод и монетки вместе не применяются.
            </p>
          </details>

          {paymentBlockerMessage ? (
            <p className="border-t border-[var(--color-border-subtle)] py-4 text-sm leading-6 text-red-700" role="alert" data-ui="checkout.error">
              {paymentBlockerMessage}
            </p>
          ) : null}

          {phase === "review" ? (
            <section className={checkoutSurfaceClass} aria-labelledby="checkout-review-title" data-ui="checkout.reviewCard">
              <h2 id="checkout-review-title" className="text-xl font-bold leading-tight">
                {isZeroCashOrder ? "Подтвердите заказ" : "Все готово к созданию платежа"}
              </h2>
              <p className="mt-3 text-sm leading-6 ui-text-secondary">
                {isZeroCashOrder ? "Скидка полностью покрывает стоимость. Доступ откроется после подтверждения заказа." : "На следующем шаге выберите способ оплаты. Доступ откроется после подтверждения платежа."}
              </p>
              <div className={checkoutActionGroupClass}>
                <Button
                  type="button"
                  label={isZeroCashOrder ? "Продолжить" : "Выбрать способ оплаты"}
                  disabled={Boolean(cartBlockerMessage)}
                  data-ui="checkout.reviewCard.continueButton"
                  onClick={() => setPhase("payment")}
                />
                <Button
                  type="button"
                  variant="secondary"
                  label="Вернуться к корзине"
                  data-ui="checkout.reviewCard.backButton"
                  onClick={() => setPhase("cart")}
                />
              </div>
            </section>
          ) : null}

          {phase === "payment" ? (
            <section className={checkoutSurfaceClass} aria-labelledby="checkout-payment-title" data-ui="checkout.paymentCard">
              <h2 id="checkout-payment-title" className="text-xl font-bold leading-tight">
                {isZeroCashOrder ? "Подтвердите заказ" : "Подготовка перехода к провайдеру"}
              </h2>
              <section className="mt-5 border-t border-[var(--color-border-subtle)] pt-5" aria-labelledby="checkout-phone-title" data-ui="checkout.phoneVerification" data-state={checkoutPhoneVerified ? "confirmed" : checkoutPhonePending ? "pending" : "idle"}>
                <h3 id="checkout-phone-title" className="text-base font-semibold ui-text-primary">Телефон для подтверждения покупки</h3>
                {checkoutPhoneVerified ? (
                  <p className="mt-2 text-sm leading-6 ui-text-secondary" data-ui="checkout.phoneVerification.confirmed">Телефон подтверждён. Можно продолжить оформление.</p>
                ) : (
                  <>
                    <p className="mt-2 text-sm leading-6 ui-text-secondary">Укажите номер. Мы попросим подтвердить его звонком перед оплатой.</p>
                    <div className="mt-3 flex flex-col gap-3 sm:flex-row sm:items-end" data-ui="checkout.phoneVerification.form">
                      <label className="block w-full text-sm font-medium sm:max-w-xs" htmlFor="checkout-phone-input">
                        Телефон
                        <input
                          id="checkout-phone-input"
                          type="tel"
                          autoComplete="tel"
                          inputMode="tel"
                          value={checkoutPhone}
                          disabled={checkoutPhonePending}
                          onChange={(event) => {
                            setCheckoutPhone(applyRuPhoneMask(checkoutPhone, event.target.value));
                            setCheckoutPhoneError("");
                          }}
                          className="ui-input mt-2 w-full rounded-[var(--radius-control)] border px-4 py-3"
                          placeholder="+7 (999) 123-45-67"
                          data-ui="checkout.phoneVerification.input"
                        />
                      </label>
                      <Button
                        type="button"
                        variant="secondary"
                        label={checkoutPhonePending ? "Проверяем звонок" : "Подтвердить телефон"}
                        loading={checkoutPhonePending}
                        disabled={checkoutPhonePending || !isCompleteRuPhone(checkoutPhone)}
                        onClick={() => void startCheckoutPhoneVerification()}
                        data-ui="checkout.phoneVerification.startButton"
                      />
                    </div>
                    {checkoutPhoneCallNumber ? <p className="mt-3 text-sm leading-6 ui-text-secondary" data-ui="checkout.phoneVerification.callPrompt">Позвоните на <a className="link-text" href={`tel:${checkoutPhoneCallNumber}`}>{checkoutPhoneCallNumber}</a>. Звонок бесплатный и будет сброшен.</p> : null}
                    {checkoutPhoneError ? <p className="mt-2 text-sm font-medium ui-text-danger" role="alert" data-ui="checkout.phoneVerification.error">{checkoutPhoneError}</p> : null}
                  </>
                )}
              </section>
              {isZeroCashOrder ? null : <fieldset className="mt-5 space-y-3" data-ui="checkout.paymentCard.methods">
                <legend className="sr-only">Выберите способ оплаты</legend>
                {visiblePaymentMethods.map((method) => (
                  <label
                    key={method.id}
                    className={`block cursor-pointer rounded-[var(--radius-control)] border px-5 py-4 transition ${
                      paymentMethod === method.id
                        ? "border-[var(--color-brand)] bg-[var(--color-brand-surface)]"
                        : "border-[var(--color-border-subtle)] bg-white ui-surface-muted-hover"
                    }`}
                    data-ui={`checkout.paymentCard.method.${method.id}`}
                    data-state={paymentMethod === method.id ? "selected" : "idle"}
                  >
                    <span className="flex items-start gap-3">
                      <input
                        type="radio"
                        name="payment-method"
                        value={method.id}
                        checked={paymentMethod === method.id}
                        onChange={() => setPaymentMethod(method.id)}
                        className="mt-1 h-4 w-4 accent-[var(--color-brand)]"
                        data-ui={`checkout.paymentCard.method.${method.id}.input`}
                      />
                      <span>
                        <span className="block text-sm font-semibold ui-text-primary">{method.title}</span>
                        <span className="mt-1 block text-sm leading-6 ui-text-secondary">{method.description}</span>
                      </span>
                    </span>
                  </label>
                ))}
              </fieldset>}
              {!isZeroCashOrder && paymentMethod === "podeli" ? (
                <fieldset className="mt-5 grid gap-4 sm:grid-cols-2" data-ui="checkout.paymentCard.podeliCustomer">
                  <legend className="mb-3 text-sm font-semibold">Данные для оформления оплаты в Подели</legend>
                  {([
                    ["first_name", "Имя", "text", "given-name"],
                    ["last_name", "Фамилия", "text", "family-name"],
                    ["email", "Email", "email", "email"],
                  ] as const).map(([field, label, type, autoComplete]) => (
                    <label key={field} className="block text-sm font-medium">
                      {label}
                      <input type={type} autoComplete={autoComplete} required maxLength={field === "email" ? 250 : 60}
                        className="ui-input mt-2 w-full rounded-[var(--radius-control)] border px-4 py-3"
                        value={podeliCustomer[field]}
                        onChange={(event) => setPodeliCustomer((current) => ({ ...current, [field]: event.target.value }))}
                        data-ui={`checkout.paymentCard.podeliCustomer.${field}`} />
                    </label>
                  ))}
                </fieldset>
              ) : null}
              <div className={checkoutActionGroupClass}>
                <Button
                  type="button"
                  label={isSubmittingPayment ? paymentProgressLabel : paymentActionLabel}
                  loading={isSubmittingPayment}
                  disabled={!canStartPayment}
                  data-ui="checkout.paymentCard.providerButton"
                  onClick={() => void startPayment()}
                />
                <Button
                  type="button"
                  variant="secondary"
                  label="Назад к проверке"
                  data-ui="checkout.paymentCard.backButton"
                  onClick={() => setPhase("review")}
                />
              </div>
            </section>
          ) : null}

          {phase === "pending" ? (
            <section className={checkoutSurfaceClass} aria-labelledby="checkout-pending-title" data-ui="checkout.pendingCard">
              <h2 id="checkout-pending-title" className="text-xl font-bold leading-tight">
                Форма оплаты CloudPayments
              </h2>
              <p className="mt-3 text-sm leading-6 ui-text-secondary" data-ui="checkout.pendingCard.status" data-status="pending">
                {widgetPayload
                  ? "Откройте форму CloudPayments и завершите оплату. Доступ откроется только после подтверждения платежа."
                  : `Выбран способ: ${selectedPaymentMethod.title}. Доступ откроется только после подтверждения платежа.`}
              </p>
              {orderStatus ? (
                <p className="mt-3 text-sm leading-6 ui-text-muted-ink" data-ui="checkout.pendingCard.orderStatus">
                  Заказ #{orderStatus.order_id}: {orderStatus.payment_status}, доступ: {orderStatus.access_status}.
                </p>
              ) : null}
              {orderStatus?.active_attempt ? (
                <p
                  className="mt-2 text-sm leading-6 ui-text-muted-ink"
                  data-ui="checkout.pendingCard.providerStatus"
                  data-status={orderStatus.active_attempt.status}
                >
                  Провайдер: {orderStatus.active_attempt.provider}, попытка: {orderStatus.active_attempt.status}.
                </p>
              ) : null}
              <div className={checkoutActionGroupClass}>
                {widgetPayload && orderStatus ? (
                  <Button
                    type="button"
                    label={isSubmittingPayment ? "Открываем форму" : "Открыть форму оплаты"}
                    loading={isSubmittingPayment}
                    disabled={isSubmittingPayment}
                    data-ui="checkout.pendingCard.widgetButton"
                    onClick={() => void startPayment()}
                  />
                ) : null}
                {providerPaymentUrl ? (
                  <ButtonLink href={providerPaymentUrl} data-ui="checkout.pendingCard.providerLink">
                    Открыть страницу оплаты
                  </ButtonLink>
                ) : null}
                <Button
                  type="button"
                  label={isRefreshingStatus ? "Проверяем статус" : "Проверить статус"}
                  loading={isRefreshingStatus}
                  disabled={!orderStatus || isRefreshingStatus}
                  data-ui="checkout.pendingCard.refreshButton"
                  onClick={() => void refreshOrderStatus()}
                />
                <Button
                  type="button"
                  variant="secondary"
                  label="Вернуться к способу оплаты"
                  data-ui="checkout.pendingCard.backButton"
                  onClick={() => setPhase("payment")}
                />
              </div>
            </section>
          ) : null}

          {phase === "success" ? (
            <ResultCard
              title="Платеж принят"
              status="success"
              description="Оплата подтверждена. Доступ появится после серверного подтверждения прав на курс."
              primaryLabel={isRefreshingStatus ? "Проверяем доступ" : "Проверить доступ"}
              primaryDataUi="checkout.result.success.accessButton"
              primaryLoading={isRefreshingStatus}
              primaryDisabled={!orderStatus || isRefreshingStatus}
              onPrimaryClick={() => void refreshOrderStatus()}
              secondaryLabel="Вернуться в кабинет"
              secondaryDataUi="checkout.result.success.backButton"
              onSecondaryClick={() => setPhase("cart")}
            />
          ) : null}

          {phase === "failed" ? (
            <ResultCard
              title="Оплата не прошла"
              status="failed"
              description={checkoutError || "Платеж не подтвержден. Доступ не выдается, можно выбрать способ оплаты заново."}
              primaryLabel="Повторить оплату"
              primaryDataUi="checkout.result.failed.retryButton"
              onPrimaryClick={() => setPhase("payment")}
              secondaryLabel="Вернуться к корзине"
              secondaryDataUi="checkout.result.failed.cartButton"
              onSecondaryClick={() => setPhase("cart")}
            />
          ) : null}

          {phase === "cancelled" ? (
            <ResultCard
              title="Оплата отменена"
              status="cancelled"
              description="Платеж не подтвержден. Заказ можно подготовить заново, доступ к курсу не выдается."
              primaryLabel="Выбрать оплату"
              primaryDataUi="checkout.result.cancelled.retryButton"
              onPrimaryClick={() => setPhase("payment")}
              secondaryLabel="Вернуться к курсам"
              secondaryDataUi="checkout.result.cancelled.coursesButton"
              onSecondaryClick={() => setPhase("cart")}
            />
          ) : null}

          {phase === "access" ? (
            <section className={checkoutAccessSurfaceClass} aria-labelledby="checkout-access-title" data-ui="checkout.accessCard" data-status="paid">
              <h2 id="checkout-access-title" className="text-xl font-bold leading-tight">
                Доступ открыт
              </h2>
              <p className="mt-3 text-sm leading-6 ui-text-secondary">
                Оплата подтверждена. Теперь можно открыть купленные материалы.
              </p>
              <div className={checkoutActionGroupClass}>
                {selectedCartCourse?.atoms.map((atom) => <TextLink key={`${atom.kind}:${atom.id}`} href={atom.accessPath} className="min-w-0 max-w-full [&>span]:min-w-0 [&>span]:whitespace-normal! [&>span]:break-words" data-ui="checkout.accessCard.atom">{atom.title}</TextLink>)}
                <ButtonRouteLink
                  href={selectedCartCourse?.accessPath ?? "/lk/learning"}
                  data-ui="checkout.accessCard.learningLink"
                >
                  Открыть материалы
                </ButtonRouteLink>
                <Button
                  type="button"
                  variant="secondary"
                  label="Вернуться к корзине"
                  data-ui="checkout.accessCard.cartButton"
                  onClick={() => setPhase("cart")}
                />
              </div>
            </section>
          ) : null}
        <aside className="border-t border-[var(--color-border-subtle)] pt-4" aria-label="Итого к оплате" data-ui="checkout.summary">
          <dl className="space-y-3 text-sm">
            <div className="flex items-center justify-between gap-4" data-ui="checkout.summary.coursePrice">
              <dt className="ui-text-secondary">Товары</dt>
              <dd className="font-normal ui-text-primary">{formatRubles(cartSubtotal)}</dd>
            </div>
            <div className="flex items-center justify-between gap-4" data-ui="checkout.summary.discount">
              <dt className="ui-text-secondary">{discountSummaryLabel}</dt>
              <dd className="text-right font-normal ui-text-primary">{discountSummaryValue}</dd>
            </div>
            <div className="flex items-end justify-between gap-4 border-t border-[var(--color-border-subtle)] pt-4 text-base" data-ui="checkout.summary.total">
              <dt className="font-normal ui-text-primary">К оплате</dt>
              <dd className="text-right text-2xl font-bold leading-tight ui-text-primary">{totalSummaryValue}</dd>
            </div>
          </dl>

          <div className="mt-5">
            {phase === "cart" ? (
              <Button
                type="button"
                label="Продолжить"
                disabled={Boolean(cartBlockerMessage)}
                data-ui="checkout.summary.reviewButton"
                onClick={() => setPhase("review")}
              />
            ) : null}
            {phase === "review" ? (
              <Button
                type="button"
                label={isZeroCashOrder ? "Продолжить" : "Выбрать оплату"}
                disabled={Boolean(cartBlockerMessage)}
                data-ui="checkout.summary.paymentButton"
                onClick={() => setPhase("payment")}
              />
            ) : null}
            {phase === "payment" ? (
              <Button
                type="button"
                label={isSubmittingPayment ? paymentProgressLabel : isZeroCashOrder ? paymentActionLabel : "Перейти к провайдеру"}
                loading={isSubmittingPayment}
                disabled={!canStartPayment}
                data-ui="checkout.summary.payButton"
                onClick={() => void startPayment()}
              />
            ) : null}
            {phase === "pending" && widgetPayload && orderStatus ? (
              <Button
                type="button"
                label={isSubmittingPayment ? "Открываем форму" : "Открыть форму оплаты"}
                loading={isSubmittingPayment}
                disabled={isSubmittingPayment}
                data-ui="checkout.summary.pendingButton"
                onClick={() => void startPayment()}
              />
            ) : null}
            {phase === "success" ? (
              <Button
                type="button"
                label={isRefreshingStatus ? "Проверяем доступ" : "Проверить доступ"}
                loading={isRefreshingStatus}
                disabled={!orderStatus || isRefreshingStatus}
                data-ui="checkout.summary.accessButton"
                onClick={() => void refreshOrderStatus()}
              />
            ) : null}
            {phase === "failed" || phase === "cancelled" ? (
              <Button type="button" label="Повторить оплату" data-ui="checkout.summary.retryButton" onClick={() => setPhase("payment")} />
            ) : null}
            {phase === "access" ? (
              <ButtonRouteLink
                href={selectedCartCourse?.accessPath ?? "/lk/learning"}
                data-ui="checkout.summary.learningLink"
              >
                Открыть материалы
              </ButtonRouteLink>
            ) : null}
          </div>

          <ol className="mt-5 flex w-full items-start text-center" aria-label="Этапы оформления" data-ui="checkout.steps">
            {checkoutTimelineSteps.map((step, index) => {
              const isComplete = index < activeTimelineIndex;
              const isActive = step.id === activeTimelineStep;
              const markerClass = isActive || isComplete ? "border-[var(--color-brand)] bg-[var(--color-brand)]" : "ui-border-control bg-white";
              const leftLineClass = index <= activeTimelineIndex ? "bg-[var(--color-brand)]" : "ui-surface-pressed";
              const rightLineClass = index < activeTimelineIndex ? "bg-[var(--color-brand)]" : "ui-surface-pressed";
              const labelClass = isActive ? "ui-text-primary" : isComplete ? "ui-text-muted-ink" : "ui-text-muted";

              return (
                <li
                  key={step.id}
                  className="min-w-0 flex-1"
                  data-ui={`checkout.steps.${step.id}`}
                  data-state={isActive ? "active" : isComplete ? "complete" : "idle"}
                >
                  <span className="flex w-full items-center" aria-hidden="true">
                    <span className={`h-px flex-1 ${index === 0 ? "bg-transparent" : leftLineClass}`} />
                    <span className={`h-2 w-2 rounded-full border ${markerClass}`} />
                    <span className={`h-px flex-1 ${index === checkoutTimelineSteps.length - 1 ? "bg-transparent" : rightLineClass}`} />
                  </span>
                  <span className={`mt-2 block truncate text-xs font-normal ${labelClass}`}>{step.label}</span>
                </li>
              );
            })}
          </ol>
        </aside>
          </> : null}
          </>
        )}
      </div>
    </div>
  );
}

function ResultCard({
  title,
  status,
  description,
  primaryLabel,
  primaryDataUi,
  primaryDisabled,
  primaryLoading,
  onPrimaryClick,
  secondaryLabel,
  secondaryDataUi,
  onSecondaryClick,
}: {
  readonly title: string;
  readonly status: "success" | "failed" | "cancelled";
  readonly description: string;
  readonly primaryLabel: string;
  readonly primaryDataUi: string;
  readonly primaryDisabled?: boolean;
  readonly primaryLoading?: boolean;
  readonly onPrimaryClick: () => void;
  readonly secondaryLabel: string;
  readonly secondaryDataUi: string;
  readonly onSecondaryClick: () => void;
}) {
  return (
    <section className={checkoutSurfaceClass} aria-labelledby={`checkout-result-${status}`} data-ui={`checkout.result.${status}`} data-status={status}>
      <h2 id={`checkout-result-${status}`} className="text-xl font-bold leading-tight">
        {title}
      </h2>
      <p className="mt-3 text-sm leading-6 ui-text-secondary">{description}</p>
      <div className={checkoutActionGroupClass}>
        <Button
          type="button"
          label={primaryLabel}
          loading={primaryLoading}
          disabled={primaryDisabled}
          data-ui={primaryDataUi}
          onClick={onPrimaryClick}
        />
        <Button
          type="button"
          variant="secondary"
          label={secondaryLabel}
          data-ui={secondaryDataUi}
          onClick={onSecondaryClick}
        />
      </div>
    </section>
  );
}
