import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

const source = await readFile(new URL("./SiteHeader.tsx", import.meta.url), "utf8");
const styles = await readFile(new URL("./header.css", import.meta.url), "utf8");

test("renders the return control before account and logout with a native browser tooltip", () => {
  assert.match(source, /data-ui="header\.impersonationReturn"/);
  assert.match(source, /aria-label="Вернуться в админку"/);
  assert.match(source, /title="Вернуться в админку"/);
  assert.doesNotMatch(source, /header__impersonation-tooltip/);
  assert.match(source, /viewBox="0 0 16 16"/);
  assert.match(source, /fill="currentColor"/);
  assert.ok(source.indexOf("header.impersonationReturn") < source.indexOf("header.account.link"));
  assert.ok(source.indexOf("header.account.link") < source.indexOf("header.logoutButton"));
});

test("duplicates return access in the mobile account menu", () => {
  assert.match(source, /data-ui="header\.mobileImpersonationReturn"/);
  assert.match(source, /menu__account-impersonation/);
});

test("styles hover and keyboard focus feedback for the return control", () => {
  assert.doesNotMatch(styles, /header__impersonation-tooltip/);
  assert.match(source, /<Button fullWidth=\{false\} variant="ghost"/);
  assert.doesNotMatch(styles, /\.header__impersonation-button:hover/);
  assert.doesNotMatch(styles, /\.menu__account-impersonation:focus-visible/);
});

test("refreshes impersonation state on public and protected navigation", () => {
  assert.match(source, /void loadImpersonationContext\(apiBaseUrl\)/);
  assert.match(source, /\[apiBaseUrl, pathname\]/);
  assert.doesNotMatch(source, /const visibleImpersonation = isProtectedAccountRoute \?/);
});

test("keeps return controls stable until navigation and hides guest CTA on protected routes", () => {
  assert.match(
    source,
    /const result = await stopImpersonation\(apiBaseUrl\);\n\s+replaceWithFreshDocument\(/,
  );
  assert.doesNotMatch(
    source,
    /const result = await stopImpersonation\(apiBaseUrl\);\n\s+setImpersonation\(result\);/,
  );
  assert.match(source, /\) : !showReturnToAdmin && !isProtectedAccountRoute && isAccountProfileResolved \?/);
  assert.match(source, /\) : !isProtectedAccountRoute && isAccountProfileResolved \?/);
});
