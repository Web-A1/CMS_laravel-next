import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

const loginFormSource = await readFile(new URL("./LoginModeForm.tsx", import.meta.url), "utf8");
const authCssSource = await readFile(new URL("./auth.css", import.meta.url), "utf8");
const authPageSource = await readFile(new URL("../../app/(site)/auth/AuthPageClient.tsx", import.meta.url), "utf8");

test("login submit button follows the centered login form width", () => {
  assert.match(loginFormSource, /className="auth-login-submit-button"/);
  assert.match(authCssSource, /\.button\.auth-login-submit-button \{[\s\S]*?min-width: 100%;/);
  assert.match(authCssSource, /@media \(min-width: 768px\) \{[\s\S]*?\.auth-shell \{[\s\S]*?padding-block-start: clamp\(3rem, 10vh, 5\.5rem\);/);
  assert.match(authCssSource, /@media \(min-width: 640px\) \{[\s\S]*?\.auth-card\[data-state="login"\] \{[\s\S]*?max-width: 20rem;/);
  assert.match(authCssSource, /@media \(min-width: 640px\) \{[\s\S]*?\.auth-card\[data-state="login"\] \[data-ui="auth\.login\.form"\] \{[\s\S]*?gap: 1\.25rem;/);
  assert.match(authCssSource, /@media \(min-width: 640px\) \{[\s\S]*?\.auth-card \.auth-floating-input \{[\s\S]*?height: 3rem;/);
  assert.match(authCssSource, /@media \(min-width: 640px\) \{[\s\S]*?\.auth-card \.auth-floating-input \{[\s\S]*?padding-inline: 1rem;/);
  assert.match(authCssSource, /@media \(min-width: 640px\) \{[\s\S]*?\.auth-card \.auth-floating-input\.pr-14 \{[\s\S]*?padding-right: 3rem;/);
  assert.match(authCssSource, /@media \(min-width: 640px\) \{[\s\S]*?\.button\.auth-login-submit-button \{[\s\S]*?align-self: center;/);
  assert.match(authCssSource, /@media \(min-width: 640px\) \{[\s\S]*?\.button\.auth-login-submit-button \{[\s\S]*?min-width: 100%;/);
});

test("desktop login mode links use quiet text styling", () => {
  assert.match(authPageSource, /auth-mode-links/);
  assert.match(authPageSource, /className="link-text auth-mode-link"/);
  assert.doesNotMatch(authPageSource, /sm:text-lg/);
  assert.match(authCssSource, /@media \(min-width: 640px\) \{[\s\S]*?\.auth-mode-links \{[\s\S]*?color: var\(--color-text-secondary\);/);
  assert.match(authCssSource, /@media \(min-width: 640px\) \{[\s\S]*?\.auth-mode-links \{[\s\S]*?font-size: var\(--text-sm\);/);
  assert.match(authCssSource, /@media \(min-width: 640px\) \{[\s\S]*?\.auth-mode-link \{[\s\S]*?color: inherit;/);
});
