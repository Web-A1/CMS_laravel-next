import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

const headerStyles = await readFile(new URL("./header.css", import.meta.url), "utf8");
const buttonStyles = await readFile(new URL("../button/button.css", import.meta.url), "utf8");
const globalStyles = await readFile(new URL("../../app/globals.css", import.meta.url), "utf8");

test("uses a visible focus ring for header controls and breadcrumbs", () => {
  assert.match(headerStyles, /\.burger:focus-visible \{\n\s+outline: 2px solid var\(--color-brand-deep\);/);
  assert.match(
    buttonStyles,
    /\.button:focus-visible \{ outline: 2px solid var\(--color-brand-ink\);/,
  );
  assert.match(
    globalStyles,
    /\.breadcrumbs__link:focus-visible \{[\s\S]+outline: 2px solid var\(--color-brand-deep\);/,
  );
  assert.match(
    headerStyles,
    /\.header--brand \.header__nav-link:focus-visible \{\n\s+outline: 2px solid var\(--color-header-brand-text\);/,
  );
  assert.match(buttonStyles, /\.button\[data-surface="brand"\]:focus-visible,\s*\.button\[data-surface="dark"\]:focus-visible \{ outline-color: var\(--color-surface\);/);

  assert.doesNotMatch(headerStyles, /:focus-visible[\s\S]{0,120}var\(--color-focus-ring\)/);
  assert.doesNotMatch(headerStyles, /:focus-visible[\s\S]{0,120}var\(--color-brand-focus\)/);
});
