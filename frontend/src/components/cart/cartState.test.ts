import assert from "node:assert/strict";
import test from "node:test";
import type { CheckoutCatalogItem } from "../../lib/commerceApi";
const path = "./cartState.ts";
const cart: typeof import("./cartState") = await import(path);
const commercePath = "../../lib/commerceApi.ts";
const { parseCheckoutCatalogItem }: typeof import("../../lib/commerceApi") = await import(commercePath);

const skuOffer = parseCheckoutCatalogItem({ sku: "guide-1", kind: "guide", entityType: "guide", entityId: 1, title: "Методичка", price: 9900, currency: "RUB", priceVersion: 1, publicPath: "/offers/guide-1", accessPath: "/lk/guides/guide-1", tariff: null, atoms: [] });

function storageTest(run: (storage: Map<string, string>, events: Event[]) => void) {
  const storage = new Map<string, string>();
  const events: Event[] = [];
  const original = Object.getOwnPropertyDescriptor(globalThis, "window");
  Object.defineProperty(globalThis, "window", { configurable: true, value: {
    localStorage: { getItem: (key: string) => storage.get(key) ?? null, setItem: (key: string, value: string) => storage.set(key, value), removeItem: (key: string) => storage.delete(key) },
    dispatchEvent: (event: Event) => { events.push(event); return true; },
  } });
  try { run(storage, events); } finally {
    if (original) Object.defineProperty(globalThis, "window", original);
    else Reflect.deleteProperty(globalThis, "window");
  }
}

test("missing or mismatched offer cannot produce a cart success, count or highlight", () => storageTest((storage, events) => {
  assert.equal(cart.addCartItem("course:anatomy:bones", null).status, "unavailable");
  assert.equal(cart.addCartItem("missing-sku", skuOffer).status, "unavailable");
  assert.equal(storage.size, 0);
  assert.equal(events.length, 0);
  assert.equal(cart.readCartCount(), 0);
}));

test("legacy course and SKU additions deduplicate, count retained unavailable IDs, remove and clear", () => storageTest((storage) => {
  const legacy: CheckoutCatalogItem = { ...skuOffer, kind: "course", entityType: "course", itemId: "course:anatomy:bones", sku: "course-8" };
  assert.equal(cart.addCartItem(legacy.itemId, legacy).status, "added");
  assert.equal(cart.addCartItem(skuOffer.itemId, skuOffer).count, 2);
  assert.equal(cart.addCartItem(skuOffer.itemId, skuOffer).status, "exists");
  assert.equal(cart.readCartCount(), 2);
  // A later catalog withdrawal must not silently erase this stored position.
  assert.equal(cart.addCartItem(legacy.itemId, null).status, "unavailable");
  assert.equal(cart.readCartCount(), 2);
  cart.removeCartItem(legacy.itemId);
  assert.deepEqual(cart.readCartItems(), [skuOffer.itemId]);
  assert.equal(storage.get(cart.CART_COUNT_KEY), "1");
  cart.clearCart();
  assert.equal(cart.readCartCount(), 0);
  storage.set(cart.CART_COUNT_KEY, "7");
  assert.equal(cart.readCartCount(), 0);
}));
