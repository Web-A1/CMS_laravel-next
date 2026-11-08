import assert from "node:assert/strict";
import test from "node:test";

const modulePath = "./horizontalScrollPolicy.ts";
const { keyboardHorizontalScrollTarget }: typeof import("./horizontalScrollPolicy") = await import(modulePath);

const metrics = {
  clientWidth: 428,
  scrollLeft: 0,
  scrollWidth: 2712,
} as const;

test("maps horizontal navigation keys to bounded scroll targets", () => {
  assert.equal(keyboardHorizontalScrollTarget("ArrowRight", metrics), 342);
  assert.equal(
    keyboardHorizontalScrollTarget("ArrowLeft", { ...metrics, scrollLeft: 500 }),
    158,
  );
  assert.equal(keyboardHorizontalScrollTarget("Home", { ...metrics, scrollLeft: 500 }), 0);
  assert.equal(keyboardHorizontalScrollTarget("End", metrics), 2284);
  assert.equal(
    keyboardHorizontalScrollTarget("ArrowRight", { ...metrics, scrollLeft: 2200 }),
    2284,
  );
  assert.equal(keyboardHorizontalScrollTarget("Tab", metrics), null);
});
