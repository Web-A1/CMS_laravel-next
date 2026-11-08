"use client";

import type { CheckoutCatalogItem } from "../../lib/commerceApi";

export const CART_COUNT_KEY = "cms_cart_count";
export const CART_ITEMS_KEY = "cms_cart_items";
export const CART_HIGHLIGHT_EVENT = "cms:cart-highlight";
export const CART_UPDATED_EVENT = "cms:cart-updated";

type CartAddResult = {
  readonly status: "added" | "exists" | "unavailable";
  readonly count: number;
};

function normalizeItemId(itemId: string): string {
  return itemId.trim();
}

function uniqueItems(items: ReadonlyArray<unknown>): ReadonlyArray<string> {
  return Array.from(
    new Set(
      items
        .filter((item): item is string => typeof item === "string")
        .map((item) => normalizeItemId(item))
        .filter(Boolean),
    ),
  );
}

function publishCartUpdate(count: number): void {
  window.dispatchEvent(new CustomEvent(CART_UPDATED_EVENT, { detail: { count } }));
}

function writeCartItems(items: ReadonlyArray<string>): void {
  window.localStorage.setItem(CART_ITEMS_KEY, JSON.stringify(items));
  window.localStorage.setItem(CART_COUNT_KEY, String(items.length));
  publishCartUpdate(items.length);
}

export function requestCartHighlight(): void {
  if (typeof window === "undefined") {
    return;
  }

  window.dispatchEvent(new CustomEvent(CART_HIGHLIGHT_EVENT));
}

export function readCartItems(): ReadonlyArray<string> {
  if (typeof window === "undefined") {
    return [];
  }

  const raw = window.localStorage.getItem(CART_ITEMS_KEY);
  if (!raw) {
    return [];
  }

  try {
    const parsed: unknown = JSON.parse(raw);
    return Array.isArray(parsed) ? uniqueItems(parsed) : [];
  } catch {
    return [];
  }
}

export function readCartCount(): number {
  return readCartItems().length;
}

export function isCartItemAdded(itemId: string): boolean {
  const normalizedItemId = normalizeItemId(itemId);
  return normalizedItemId ? readCartItems().includes(normalizedItemId) : false;
}

export function addCartItem(itemId: string, offer: CheckoutCatalogItem | null): CartAddResult {
  if (typeof window === "undefined") {
    return { status: "exists", count: 0 };
  }

  const normalizedItemId = normalizeItemId(itemId);
  const currentItems = readCartItems();
  if (!normalizedItemId || !offer || offer.itemId !== normalizedItemId || !offer.sku) {
    return { status: "unavailable", count: currentItems.length };
  }
  const itemExists = normalizedItemId ? currentItems.includes(normalizedItemId) : true;

  if (itemExists) {
    const count = currentItems.length;
    window.localStorage.setItem(CART_COUNT_KEY, String(count));
    publishCartUpdate(count);
    requestCartHighlight();
    return { status: "exists", count };
  }

  const nextItems = [...currentItems, normalizedItemId];
  writeCartItems(nextItems);
  requestCartHighlight();
  return { status: "added", count: nextItems.length };
}

export function removeCartItem(itemId: string): void {
  if (typeof window === "undefined") {
    return;
  }

  const normalizedItemId = normalizeItemId(itemId);
  const nextItems = readCartItems().filter((item) => item !== normalizedItemId);
  writeCartItems(nextItems);
}

export function clearCart(): void {
  if (typeof window === "undefined") {
    return;
  }

  window.localStorage.removeItem(CART_ITEMS_KEY);
  window.localStorage.setItem(CART_COUNT_KEY, "0");
  publishCartUpdate(0);
}

export function writeCartCount(nextCount: number): void {
  if (typeof window === "undefined") {
    return;
  }

  const normalized = Math.max(0, Math.floor(nextCount));
  if (normalized === 0) {
    window.localStorage.removeItem(CART_ITEMS_KEY);
  }

  window.localStorage.setItem(CART_COUNT_KEY, String(normalized));
  publishCartUpdate(normalized);
}
