import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

const source = await readFile(new URL("./DeferredPromoVideo.tsx", import.meta.url), "utf8");
const homeStyles = await readFile(new URL("../../app/home.css", import.meta.url), "utf8");
const heroVideoSource = await readFile(new URL("./HomeHeroVideo.tsx", import.meta.url), "utf8");

test("promo media loads only on mobile after it enters the viewport", () => {
  assert.match(source, /IntersectionObserver/);
  assert.match(source, /mobileState === "matches"/);
  assert.match(source, /\{canLoadVideo \? sources\.map\(/);
  assert.doesNotMatch(source, /<GeneratedImage|poster=/);
  assert.match(
    homeStyles,
    /@media \(width <= 480px\) \{\s*\.home-promo-video__media \{\s*background-image: image-set\(/,
  );
});

test("phone video and hero loading use complementary queries at the inclusive 480px boundary", () => {
  assert.match(source, /const mobileQuery = "\(width <= 480px\)"/);
  assert.match(heroVideoSource, /const nonMobileQuery = "\(width > 480px\)"/);
  assert.match(homeStyles, /@media \(width > 480px\) \{\s*\.home-promo-video \{\s*display: none;/);
  assert.doesNotMatch(homeStyles, /450px|30rem <= width|min-width: 30rem/);
});
