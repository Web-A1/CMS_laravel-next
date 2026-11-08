import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

const headerStyles = await readFile(new URL("./header.css", import.meta.url), "utf8");
const globalStyles = await readFile(new URL("../../app/globals.css", import.meta.url), "utf8");

test("reuses the existing mint brand token for internal header chrome", () => {
  assert.match(globalStyles, /--color-mint: #5da08d;/);
  assert.match(globalStyles, /--color-brand: var\(--color-mint\);/);
  assert.match(globalStyles, /--color-header-brand-surface: var\(--color-brand\);/);
  assert.match(globalStyles, /--color-header-brand-text: #ffffff;/);
});

test("keeps glass chrome on home and gives internal routes a quiet translucent mint surface", () => {
  assert.match(
    headerStyles,
    /\.header--brand \.header__row,[\s\S]*?\.menu-overlay--brand \.menu-overlay__header \{[\s\S]*?color-mix\(in srgb, var\(--color-header-brand-surface\) 98%, transparent\)/,
  );
  assert.match(
    headerStyles,
    /\.header--brand \.header__row,[\s\S]*?border: 1px solid transparent;[\s\S]*?inset 0 1px 0 rgb\(255 255 255 \/ 28%\),[\s\S]*?inset 0 -1px 0 rgb\(49 95 83 \/ 16%\);[\s\S]*?backdrop-filter: blur\(2px\);/,
  );
  assert.match(
    headerStyles,
    /\.header--brand \.header__row::before,[\s\S]*?\.menu-overlay--brand \.menu-overlay__header::before \{\s*content: none;/,
  );
  assert.match(
    headerStyles,
    /\.header--brand \.header__row::after,[\s\S]*?\.menu-overlay--brand \.menu-overlay__header::after \{\s*content: none;/,
  );
  assert.match(
    headerStyles,
    /\.header--brand \.header__logo,[\s\S]*?\.menu-overlay--brand \.menu-overlay__logo \{[\s\S]*?color: var\(--color-header-brand-text\);/,
  );
});
