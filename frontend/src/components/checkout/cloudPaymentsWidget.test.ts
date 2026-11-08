import assert from "node:assert/strict";
import test from "node:test";
const modulePath = "./cloudPaymentsWidget.ts";
const { cloudPaymentsOptions, loadCloudPaymentsScript, openCloudPaymentsWidget }: typeof import("./cloudPaymentsWidget") = await import(modulePath);
import type { CheckoutWidgetPayload } from "./checkoutContract.ts";

const payload: CheckoutWidgetPayload = {
  mode: "widget", public_id: "pk_test", invoice_id: "cp-42-7",
  amount: "1500.00", currency: "RUB", description: "Оплата заказа #42",
};

test("card, SBP and Mir Pay use backend money and allow only the selected method", () => {
  const selected = { "bank-card": "Card", sbp: "Sbp", "mir-pay": "MirPay" } as const;
  for (const method of ["bank-card", "sbp", "mir-pay"] as const) {
    const options = cloudPaymentsOptions(payload, method);
    assert.equal(options.amount, 1500);
    assert.equal(options.externalId, "cp-42-7");
    assert.equal(options.paymentSchema, "Single");
    assert.equal(options.tokenize, false);
    assert.equal(options.retryPayment, false);
    assert.equal(options.restrictedPaymentMethods.includes(selected[method]), false);
    for (const other of Object.values(selected).filter((value) => value !== selected[method])) {
      assert.equal(options.restrictedPaymentMethods.includes(other), true);
    }
    assert.equal(options.restrictedPaymentMethods.includes("Dolyame"), true);
  }
});

test("failed script reloads; duplicate opens stay locked until close; callbacks only request status", async () => {
  const originalWindow = Object.getOwnPropertyDescriptor(globalThis, "window");
  const originalDocument = Object.getOwnPropertyDescriptor(globalThis, "document");
  type ScriptStub = { onload: (() => void) | null; onerror: (() => void) | null; remove: () => void };
  let script: ScriptStub | null = null;
  let removed = 0;
  let created = 0;
  let opened = 0;
  let closed: (() => void) | undefined;
  let completed: (() => void) | undefined;
  let refreshes = 0;
  const browser: { cp?: { CloudPayments: typeof Widget } } = {};
  class Widget {
    oncomplete?: () => void;
    start() {
      opened += 1;
      completed = () => this.oncomplete?.();
      return new Promise<void>((resolve) => { closed = resolve; });
    }
  }
  Object.defineProperty(globalThis, "window", { configurable: true, value: browser });
  Object.defineProperty(globalThis, "document", { configurable: true, value: {
    getElementById: () => null,
    createElement: () => {
      created += 1;
      script = { onload: null, onerror: null, remove: () => { removed += 1; } };
      return script;
    },
    head: { appendChild: () => undefined },
  } });
  try {
    const failed = loadCloudPaymentsScript();
    assert.equal(failed, loadCloudPaymentsScript());
    assert.ok(script);
    (script as ScriptStub).onerror?.();
    await assert.rejects(failed, /Не удалось загрузить/);
    assert.equal(removed, 1);
    const retry = loadCloudPaymentsScript();
    browser.cp = { CloudPayments: Widget };
    assert.ok(script);
    (script as ScriptStub).onload?.();
    await retry;
    assert.equal(created, 2);
    const payment = openCloudPaymentsWidget(payload, "bank-card", () => { refreshes += 1; });
    await Promise.resolve();
    await openCloudPaymentsWidget(payload, "bank-card", () => { refreshes += 1; });
    assert.equal(opened, 1);
    completed?.();
    assert.equal(refreshes, 1);
    closed?.();
    await payment;
    const second = openCloudPaymentsWidget(payload, "sbp", () => { refreshes += 1; });
    await Promise.resolve();
    assert.equal(opened, 2);
    closed?.();
    await second;
  } finally {
    if (originalWindow) Object.defineProperty(globalThis, "window", originalWindow);
    else Reflect.deleteProperty(globalThis, "window");
    if (originalDocument) Object.defineProperty(globalThis, "document", originalDocument);
    else Reflect.deleteProperty(globalThis, "document");
  }
});

test("SDK errors release the lock and an obsolete checkout never opens a form", async () => {
  const original = Object.getOwnPropertyDescriptor(globalThis, "window");
  let opens = 0;
  let reject = false;
  class Widget {
    async start() {
      opens += 1;
      if (reject) throw new Error("SDK failed");
      return { type: "error", status: "fail" };
    }
  }
  Object.defineProperty(globalThis, "window", { configurable: true, value: { cp: { CloudPayments: Widget } } });
  try {
    await openCloudPaymentsWidget(payload, "bank-card", () => undefined, () => false);
    assert.equal(opens, 0);
    await assert.rejects(openCloudPaymentsWidget(payload, "bank-card", () => undefined), /Не удалось открыть/);
    reject = true;
    await assert.rejects(openCloudPaymentsWidget(payload, "bank-card", () => undefined), /SDK failed/);
    assert.equal(opens, 2);
  } finally {
    if (original) Object.defineProperty(globalThis, "window", original);
    else Reflect.deleteProperty(globalThis, "window");
  }
});
