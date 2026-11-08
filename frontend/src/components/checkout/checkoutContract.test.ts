import assert from "node:assert/strict";
import test from "node:test";

const modulePath = "./checkoutContract.ts";
const checkoutContract: typeof import("./checkoutContract") = await import(modulePath);

const trustedOrigin = "https://school.example";
test("validates server coin pricing without inferring payment or access", () => {
  const pricing = { order_id: 42, currency: "RUB", subtotal: 10000, discount: 10000,
    payable_total: 0, coins_units: 100, reservation_status: "pending", expires_at: "2026-09-15T15:00:00Z" };
  assert.equal(checkoutContract.parseCheckoutPricing(pricing, 42).payable_total, 0);
  assert.equal(checkoutContract.parseCheckoutPricing({ ...pricing, coins_units: 0, discount_type: "promo" }, 42).discount_type, "promo");
  for (const changed of [{ order_id: 43 }, { currency: "USD" }, { payable_total: 1 },
    { coins_units: 1.5 }, { subtotal: -1 }, { expires_at: "invalid" }, { discount: undefined }, { discount_type: "unknown" }]) {
    assert.throws(() => checkoutContract.parseCheckoutPricing({ ...pricing, ...changed }, 42));
  }
});
const baseOrder = {
  order_id: 42,
  payment_status: "pending_payment",
  access_status: "not_granted",
  active_attempt: {
    id: 7,
    provider: "provider",
    status: "pending",
    provider_payment_url: "https://payments.example/session/42",
  },
} as const;

test("parses a valid checkout order and resolves pending", () => {
  const order = checkoutContract.parseCheckoutOrderStatus(baseOrder, trustedOrigin);

  assert.equal(order.active_attempt?.provider_payment_url, "https://payments.example/session/42");
  assert.equal(checkoutContract.resolveCheckoutPhase(order), "pending");
});

test("parses a backend-owned CloudPayments widget payload", () => {
  const order = checkoutContract.parseCheckoutOrderStatus(
    {
      ...baseOrder,
      active_attempt: {
        ...baseOrder.active_attempt,
        provider: "cloudpayments",
        amount: 150000,
        currency: "RUB",
        provider_payment_id: null,
        provider_payload: {
          mode: "widget",
          public_id: "pk_test_123",
          invoice_id: `cp-42-${baseOrder.active_attempt.id}`,
          amount: "1500.00",
          currency: "RUB",
          description: "Оплата заказа #42",
        },
      },
    },
    trustedOrigin,
  );

  assert.deepEqual(order.active_attempt?.provider_payload, {
    mode: "widget",
    public_id: "pk_test_123",
    invoice_id: `cp-42-${baseOrder.active_attempt.id}`,
    amount: "1500.00",
    currency: "RUB",
    description: "Оплата заказа #42",
  });
});

test("rejects malformed orders and attempts", () => {
  const invalidPayloads: ReadonlyArray<unknown> = [
    null,
    {},
    { ...baseOrder, order_id: "42" },
    { ...baseOrder, active_attempt: [] },
    { ...baseOrder, active_attempt: { ...baseOrder.active_attempt, id: 0 } },
    { ...baseOrder, active_attempt: { ...baseOrder.active_attempt, provider: "" } },
  ];

  for (const payload of invalidPayloads) {
    assert.throws(() => checkoutContract.parseCheckoutOrderStatus(payload, trustedOrigin));
  }
});

test("rejects widget amounts, currencies and invoices that differ from the server attempt", () => {
  const valid = {
    mode: "widget", public_id: "pk_test", invoice_id: "cp-42-7",
    amount: "1500.00", currency: "RUB", description: "Order 42",
  };
  for (const changed of [
    { amount: "0.00" }, { amount: "1.00" }, { amount: "1e3" },
    { amount: "10000000000000.00" }, { currency: "USD" },
    { invoice_id: "cp-42-8" }, { invoice_id: "cp-43-7" },
  ]) {
    assert.throws(() => checkoutContract.parseCheckoutOrderStatus({
      ...baseOrder,
      active_attempt: {
        ...baseOrder.active_attempt, provider: "cloudpayments", amount: 150000, currency: "RUB",
        provider_payload: { ...valid, ...changed },
      },
    }, trustedOrigin));
  }
});

test("rejects unsafe provider payment URLs", () => {
  const invalidUrls = [
    "javascript:alert(1)",
    "http://payments.example/session/42",
    "//evil.example/session/42",
    "https://user:pass@payments.example/session/42",
    "https://payments.example/session/42#token",
    "https://payments.example/session/42\\backslash",
  ];

  for (const providerPaymentUrl of invalidUrls) {
    assert.throws(() =>
      checkoutContract.parseCheckoutOrderStatus(
        { ...baseOrder, active_attempt: { ...baseOrder.active_attempt, provider_payment_url: providerPaymentUrl } },
        trustedOrigin,
      ),
    );
  }
});

test("never promotes failed, cancelled or pending payments to access", () => {
  const states = [
    [{ ...baseOrder, payment_status: "cancelled" }, "cancelled"],
    [{ ...baseOrder, active_attempt: { ...baseOrder.active_attempt, status: "failed" } }, "failed"],
    [baseOrder, "pending"],
    [{ ...baseOrder, payment_status: "paid", access_status: "not_granted" }, "success"],
    [{ ...baseOrder, payment_status: "paid", access_status: "granted" }, "access"],
  ] as const;

  for (const [payload, expected] of states) {
    const order = checkoutContract.parseCheckoutOrderStatus(payload, trustedOrigin);
    assert.equal(checkoutContract.resolveCheckoutPhase(order), expected);
  }
});

test("classifies network and server failures as retryable", () => {
  for (const status of [0, 408, 429, 500, 503]) {
    assert.equal(checkoutContract.isRetryableCheckoutFailure(status), true);
  }
  for (const status of [400, 401, 403, 422]) {
    assert.equal(checkoutContract.isRetryableCheckoutFailure(status), false);
  }
});
