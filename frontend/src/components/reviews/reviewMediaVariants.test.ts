import test from "node:test";
import assert from "node:assert/strict";
// @ts-expect-error Node's native TypeScript test runner requires the source extension.
import { reviewImageCandidates, reviewImageSrcSet, reviewContainedImageSizes } from "./reviewMediaVariants.ts";

const variants = [160, 320, 640, 960, 2400].flatMap((width) => (["avif", "webp"] as const).map((format) => ({
  width, height: width * 2, format, url: `/storage/review-screenshots/test/${width}.${format}`,
})));

test("preview and modal exclude the large viewer resource", () => {
  assert.deepEqual([...new Set(reviewImageCandidates(variants, "preview").map((item) => item.width))], [160, 320]);
  assert.equal(reviewImageCandidates(variants, "display").some((item) => item.width === 2400), false);
  assert.equal(reviewImageCandidates(variants, "viewer").length, variants.length);
  assert.equal(reviewImageSrcSet(reviewImageCandidates(variants, "preview"), "avif"), "/storage/review-screenshots/test/160.avif 160w, /storage/review-screenshots/test/320.avif 320w");
});

test("tiny source remains usable and zoom requests the detailed version", () => {
  assert.equal(reviewImageCandidates(variants.slice(0, 2), "preview").length, 2);
  assert.match(reviewContainedImageSizes(600, 1200, "viewer"), /0.5/);
  assert.equal(reviewContainedImageSizes(600, 1200, "viewer", 2), "600px");
});
