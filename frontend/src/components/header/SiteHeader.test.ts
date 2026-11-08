import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

const siteHeaderSource = await readFile(new URL("./SiteHeader.tsx", import.meta.url), "utf8");

test("mounts one shared header above public and account layouts", async () => {
  const root = await readFile(new URL("../../app/layout.tsx", import.meta.url), "utf8");
  const site = await readFile(new URL("../../app/(site)/layout.tsx", import.meta.url), "utf8");
  assert.equal(root.match(/<SiteHeader\s+initialAccount=\{initialAccount\}\s*\/>/g)?.length, 1);
  assert.doesNotMatch(site, /<SiteHeader/);
});

test("keeps admin entry out of the shared header, workspace carts hidden and logout cancellable", () => {
  assert.doesNotMatch(siteHeaderSource, /header\.adminEntry|header\.mobileAdminEntry|showAdminEntry/);
  assert.match(siteHeaderSource, /const showFloatingCart = !isAdminRoute &&/);
  assert.match(siteHeaderSource, /if \(logoutInFlightRef\.current \|\| !requestClientAccountLogout\(\)\) return;/);
  assert.match(siteHeaderSource, /replaceWithFreshDocument\("\/auth\?mode=login"\)/);
  assert.match(siteHeaderSource, /const accountHref = resolveClientAccountHref\(accountProfile\)/);
  assert.match(siteHeaderSource, /href=\{accountHref\}/);
  assert.match(siteHeaderSource, /CLIENT_ACCOUNT_PROFILE_UPDATED_EVENT/);
  assert.match(siteHeaderSource, /isClientAccountProfile\(event\.detail\)/);
});

test("preserves native modified-click behavior on header links", () => {
  assert.match(siteHeaderSource, /function isModifiedLinkClick/);
  assert.match(siteHeaderSource, /event\.button !== 0/);
  assert.match(siteHeaderSource, /event\.metaKey/);
  assert.match(siteHeaderSource, /event\.ctrlKey/);
  assert.match(siteHeaderSource, /event\.shiftKey/);
  assert.match(siteHeaderSource, /event\.altKey/);
  assert.match(siteHeaderSource, /if \(isModifiedLinkClick\(event\)\) \{\n\s+return;\n\s+\}/);
});

test("loads staff account links as documents while preserving client navigation to LK", () => {
  assert.match(siteHeaderSource, /if \(href === "\/admin" \|\| href\.startsWith\("\/admin\/"\)\) \{\s*event\.preventDefault\(\);\s*window\.location\.assign\(href\);\s*return;/);
  assert.equal(siteHeaderSource.match(/prefetch=\{accountHref\.startsWith\("\/admin\/"\) \? false : null\}/g)?.length, 2);
  assert.match(siteHeaderSource, /event\.preventDefault\(\);\s*router\.push\(href\);/);
  assert.ok(siteHeaderSource.indexOf("if (isModifiedLinkClick(event))") < siteHeaderSource.indexOf('if (href === "/admin"'));
});

test("shows the subjects submenu from the desktop header", async () => {
  const headerStyles = await readFile(new URL("./header.css", import.meta.url), "utf8");

  assert.match(siteHeaderSource, /const subjectMenuItems = \[/);
  assert.match(siteHeaderSource, /\{ href: "\/subjects\/anatomiya", label: "Анатомия" \}/);
  assert.match(siteHeaderSource, /\{ href: "\/subjects\/luchevaya-diagnostika", label: "Лучевая диагностика" \}/);
  assert.match(siteHeaderSource, /data-ui="header\.subjectsMenu"/);
  assert.match(siteHeaderSource, /data-ui="header\.subjectsMenu\.link"/);
  assert.match(siteHeaderSource, /onNavigate\(subject\.href, event\)/);
  assert.match(headerStyles, /@media \(min-width: 1024px\) \{[\s\S]*?\.header__subjects-menu \{/);
  assert.match(headerStyles, /\.header__subjects-menu \{[\s\S]*?left: 0;/);
  assert.match(headerStyles, /\.header__subjects-menu-inner::before \{[\s\S]*?transform: rotate\(45deg\);/);
  assert.match(headerStyles, /grid-template-columns: minmax\(max-content, 1fr\);/);
  assert.match(headerStyles, /\.header__subjects-link \{[\s\S]*?padding: 0\.4375rem 0\.75rem;/);
  assert.match(headerStyles, /\.header__subjects-link \{[\s\S]*?font-weight: 400;/);
  assert.match(headerStyles, /\.header__subjects-link \{[\s\S]*?white-space: nowrap;/);
  assert.match(headerStyles, /\.header__nav-item--subjects\[data-open="true"\] \.header__subjects-menu/);
});

test("uses a soft nav hover without underlining header links", async () => {
  const headerStyles = await readFile(new URL("./header.css", import.meta.url), "utf8");

  assert.match(headerStyles, /\.header__nav-link:hover,[\s\S]*?box-shadow: 0 0 0 0\.375rem/);
  assert.match(headerStyles, /\.header--brand \.header__nav-link:hover,[\s\S]*?background: rgb\(255 255 255 \/ 14%\);/);
  assert.doesNotMatch(headerStyles, /\.header--brand \.header__nav-link:hover \{[\s\S]*?text-decoration-color: currentColor;/);
});

test("bootstraps cookie-backed account state once on public routes", () => {
  assert.match(siteHeaderSource, /checkedPublicAccountProfileRef/);
  assert.match(
    siteHeaderSource,
    /!isProtectedAccountRoute && !hasAuthenticatedMarker && checkedPublicAccountProfileRef\.current/,
  );
  assert.match(siteHeaderSource, /checkedPublicAccountProfileRef\.current = true;/);
});

test("keeps header state mounted across route transitions", () => {
  assert.doesNotMatch(siteHeaderSource, /<SiteHeaderContent\s+key=/);
  assert.match(siteHeaderSource, /routeSignatureRef/);
  assert.match(siteHeaderSource, /routeSignatureRef\.current = routeSignature;\n\s+closeMenu\(\);/);
});

test("uses glass chrome only on the home route and brand chrome elsewhere", () => {
  assert.match(siteHeaderSource, /const headerSurface = pathname === "\/" \? "glass" : "brand";/);
  assert.match(siteHeaderSource, /header--island header--\$\{headerSurface\}/);
  assert.match(siteHeaderSource, /data-surface=\{headerSurface\}/);
  assert.match(siteHeaderSource, /menu-overlay--\$\{headerSurface\}/);
});

test("hands mobile menu state back to desktop header at the desktop breakpoint", () => {
  assert.match(siteHeaderSource, /function updateHeaderViewportState\(\): void/);
  assert.match(siteHeaderSource, /const canHideInViewport = mediaQuery\.matches && !isAuthRoute;/);
  assert.match(siteHeaderSource, /if \(!canHideInViewport\) \{\n\s+setHeaderVisible\(true\);\n\s+closeMenu\(\);/);
  assert.match(siteHeaderSource, /mediaQuery\.addEventListener\("change", updateHeaderViewportState\);/);
});

test("keeps the shared header visible across auth screens while scrolling", () => {
  assert.match(siteHeaderSource, /const isAuthRoute = pathname === "\/auth";/);
  assert.match(siteHeaderSource, /const canHideInViewport = mediaQuery\.matches && !isAuthRoute;/);
  assert.match(siteHeaderSource, /if \(isAuthRoute\) \{\n\s+setHeaderVisible\(true\);/);
  assert.match(siteHeaderSource, /\}, \[isAuthRoute, menuOpen\]\);/);
});

test("keeps auth header in normal document flow instead of pinning it", async () => {
  const headerStyles = await readFile(new URL("./header.css", import.meta.url), "utf8");
  assert.match(siteHeaderSource, /isAuthRoute \? "header--auth" : ""/);
  assert.match(headerStyles, /\.header\.header--auth \{[\s\S]*?position: absolute;/);
});

test("removes the trigger from the accessible focus order while the mobile dialog is open", () => {
  assert.match(siteHeaderSource, /aria-label="Открыть меню"/);
  assert.match(siteHeaderSource, /aria-hidden=\{menuOpen \? true : undefined\}/);
  assert.match(siteHeaderSource, /tabIndex=\{menuOpen \? -1 : 0\}/);
  assert.match(siteHeaderSource, /menu-overlay__close/);
});
