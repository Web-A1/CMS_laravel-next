import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

const contentCssPath = new URL("./content.css", import.meta.url);
const faqCssPath = new URL("../../app/faq.css", import.meta.url);
const reviewsCssPath = new URL("../reviews/reviews.css", import.meta.url);
const learningLayoutCssPath = new URL("../learning/learning-layout.css", import.meta.url);

test("keeps mobile pill strips inside their own horizontal scrollport", async () => {
  const source = await readFile(contentCssPath, "utf8");

  assert.match(source, /@media \(max-width: 639\.98px\) \{[\s\S]*?\.ui-pill-strip \{[\s\S]*?contain: inline-size;/);
});

test("insets viewport-wide strips so vertical scrollbars do not create page overflow", async () => {
  const [learningSource, faqSource, reviewsSource] = await Promise.all([
    readFile(learningLayoutCssPath, "utf8"),
    readFile(faqCssPath, "utf8"),
    readFile(reviewsCssPath, "utf8"),
  ]);

  const dynamicViewportInset = /width: calc\(100dvw - var\(--page-edge-gutter\)\);[\s\S]*?margin-inline: calc\(50% - \(\(100dvw - var\(--page-edge-gutter\)\) \/ 2\)\);/;

  assert.match(learningSource, dynamicViewportInset);
  assert.match(faqSource, dynamicViewportInset);
  assert.match(reviewsSource, dynamicViewportInset);
});
