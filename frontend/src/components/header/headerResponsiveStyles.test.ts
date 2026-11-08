import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

const headerStyles = await readFile(new URL("./header.css", import.meta.url), "utf8");
const globalStyles = await readFile(new URL("../../app/globals.css", import.meta.url), "utf8");

test("keeps header shell content-driven across tablet and desktop breakpoints", () => {
  assert.match(headerStyles, /--header-shell-width: min\(100%, var\(--mobile-shell-max-width\)\);/);
  assert.match(headerStyles, /\.menu__panel \{[\s\S]*?width: var\(--header-shell-width\);[\s\S]*?transform: translateX\(-50%\);/);
  assert.match(
    headerStyles,
    /@media \(480px < width < 64rem\) \{[\s\S]*?--header-shell-width: calc\(100% - 2\.5rem\);[\s\S]*?--header-glass-inset: 0px;/,
  );
  assert.match(
    headerStyles,
    /@media \(min-width: 1024px\) \{[\s\S]*?--header-shell-width: min\(calc\(100% - 2rem\), 75rem\);[\s\S]*?\.header__nav \{[\s\S]*?display: flex;/,
  );
});

test("keeps breadcrumbs below the fixed header and skip link inside the viewport", () => {
  assert.match(
    globalStyles,
    /\.header-spacer \{[\s\S]*?height: calc\(var\(--header-height\) \+ var\(--breadcrumbs-gap\) \+ env\(safe-area-inset-top\)\);/,
  );
  assert.match(
    globalStyles,
    /\.button\.skip-link \{[\s\S]*?position: fixed;[\s\S]*?max-width: calc\(100vw - 2rem\);[\s\S]*?transform: translateY\(-100%\);/,
  );
  assert.match(globalStyles, /\.button\.skip-link:focus-visible \{[\s\S]*?transform: translateY\(1rem\);/);
});

test("site content has a fluid shared shell instead of a global phone-width constraint", () => {
  assert.match(globalStyles, /--site-shell-max-width: 76rem;/);
  assert.match(globalStyles, /\.site-shell \{\s*width: min\(100%, var\(--site-shell-max-width\)\);/);
  assert.doesNotMatch(globalStyles, /site-mobile-shell|--site-shell-max-width: var\(--mobile-shell-max-width\)|--site-shell-max-width: 44rem/);
});
