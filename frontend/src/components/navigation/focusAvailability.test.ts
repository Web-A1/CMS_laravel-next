import assert from "node:assert/strict";
import test from "node:test";

const modulePath = "./focusAvailability.ts";
const focusAvailability: typeof import("./focusAvailability") = await import(modulePath);

test("keeps top, focused and open-menu headers available", () => {
  const base = { atTop: false, currentVisible: false, focusInsideHeader: false, menuOpen: false, scrollDirection: "none" } as const;
  assert.equal(focusAvailability.resolveHeaderVisible({ ...base, atTop: true }), true);
  assert.equal(focusAvailability.resolveHeaderVisible({ ...base, focusInsideHeader: true }), true);
  assert.equal(focusAvailability.resolveHeaderVisible({ ...base, menuOpen: true }), true);
});

test("removes only scrolled-hidden controls from sequential focus", () => {
  const base = { atTop: false, currentVisible: true, focusInsideHeader: false, menuOpen: false } as const;
  assert.equal(focusAvailability.resolveHeaderVisible({ ...base, scrollDirection: "down" }), false);
  assert.equal(focusAvailability.resolveHeaderVisible({ ...base, currentVisible: false, scrollDirection: "up" }), true);
  assert.equal(focusAvailability.headerIsInert(false), true);
  assert.equal(focusAvailability.headerIsInert(false, false), false);
  assert.equal(focusAvailability.hiddenControlTabIndex(false), -1);
  assert.equal(focusAvailability.hiddenControlTabIndex(true), 0);
});
