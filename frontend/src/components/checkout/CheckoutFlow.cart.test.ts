import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import { createRequire } from "node:module";
import test from "node:test";
import React from "react";
import { renderToStaticMarkup } from "react-dom/server";
import ts from "typescript";
import type { CheckoutCatalogItem } from "../../lib/commerceApi";

const require = createRequire(import.meta.url);
const policyPath = "./checkoutCartPolicy.ts";
const policy: typeof import("./checkoutCartPolicy") = await import(policyPath);
const catalogPath = "../cart/cartCatalog.ts";
const catalog: typeof import("../cart/cartCatalog") = await import(catalogPath);
const source = await readFile(new URL("./CheckoutFlow.tsx", import.meta.url), "utf8");
const output = ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022, jsx: ts.JsxEmit.ReactJSX, esModuleInterop: true } }).outputText;

function renderCart(ids: string[], offers: CheckoutCatalogItem[]) {
  const sandboxModule = { exports: {} as { default: React.ComponentType<{ apiBaseUrl: string; courses: CheckoutCatalogItem[]; knownItems: { itemId: string; title: string }[] }> } };
  function Button(props: Record<string, unknown>) {
    const attributes = Object.fromEntries(Object.entries(props).filter(([name]) => !["label", "children", "fullWidth", "loading", "variant", "size", "iconOnly"].includes(name)));
    return React.createElement("button", attributes, (props.children ?? props.label) as React.ReactNode);
  }
  const requireStub = (name: string): unknown => {
    if (name === "react") return { ...React, useSyncExternalStore: () => ids.join("\n") };
    if (name === "react/jsx-runtime") return require(name);
    if (name === "@/components/button/Button") return { __esModule: true, default: Button, ButtonRouteLink: Button, ButtonLink: Button };
    if (name === "@/components/checkout/checkoutCartPolicy") return policy;
    if (name === "@/components/cart/cartCatalog") return catalog;
    if (name === "@/components/cart/cartState" || name === "@/components/checkout/checkoutContract" || name === "@/lib/commerceApi" || name === "./cloudPaymentsWidget" || name === "@/lib/phone" || name === "@/components/content/TextLink") return {};
    throw new Error(name);
  };
  Function("require", "module", "exports", output)(requireStub, sandboxModule, sandboxModule.exports);
  return renderToStaticMarkup(React.createElement(sandboxModule.exports.default, { apiBaseUrl: "/backend", courses: offers, knownItems: [{ itemId: "removed", title: "Эмбриология" }] }));
}

const offer: CheckoutCatalogItem = { itemId: "course:anatomy:bones", sku: "course-8", kind: "course", entityType: "course", entityId: 8, title: "Остеология", price: 9900, currency: "RUB", priceVersion: 1, publicPath: "/subjects/anatomy/bones", accessPath: "/lk/learning/anatomy/bones", tariff: null, atoms: [] };

test("an unavailable-only cart renders its saved rows and removal instead of an empty cart", () => {
  const html = renderCart(["removed", "unknown"], []);
  assert.ok(html.includes("Эмбриология"));
  assert.ok(html.includes("Недоступный товар"));
  assert.equal(html.match(/data-ui="checkout.unavailableItem.removeButton"/g)?.length, 2);
  assert.ok(!html.includes('data-ui="checkout.emptyCart"'));
  assert.ok(!html.includes('data-ui="checkout.summary.payButton"'));
});

test("mixed cart preserves available and unavailable rows, but cannot advance checkout", () => {
  const html = renderCart([offer.itemId, "removed"], [offer]);
  assert.ok(html.includes('data-ui="checkout.courseCard"'));
  assert.ok(html.includes('data-ui="checkout.unavailableItem"'));
  assert.match(html, /disabled="" data-ui="checkout.summary.reviewButton"/);
  assert.ok(html.includes("Удалите недоступные товары"));
});

test("available legacy IDs and new SKU rows still allow checkout; deleting the last row shows empty state", () => {
  for (const item of [offer, { ...offer, itemId: "guide-1", sku: "guide-1" }]) {
    const html = renderCart([item.itemId], [item]);
    assert.ok(html.includes('data-ui="checkout.courseCard"'));
    assert.ok(!html.includes('disabled="" data-ui="checkout.summary.reviewButton"'));
  }
  assert.ok(renderCart([], []).includes('data-ui="checkout.emptyCart"'));
});
