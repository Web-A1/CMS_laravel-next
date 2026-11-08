import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

const componentPath = new URL("./ProtectedFileDeliveryPanel.tsx", import.meta.url);
const apiPath = new URL("../../lib/protectedFileApi.ts", import.meta.url);

test("preserves the existing protected PDF preparation and download lifecycle", async () => {
  const source = await readFile(componentPath, "utf8");

  assert.match(source, /requestProtectedFileDelivery\(/);
  assert.match(source, /loadProtectedFileDelivery\(/);
  assert.match(source, /grantProtectedFileAccess\(/);
  assert.match(source, /prewarmProtectedFileDeliveries\(/);
  assert.match(source, /window\.location\.assign\(result\.downloadUrl\)/);
  assert.match(source, /const POLLING_INTERVAL_MS = 3_000/);
  assert.match(source, /const POLLING_TIMEOUT_MS = 5 \* 60_000/);
  assert.match(source, /downloadAfterReady: true/);
  assert.match(source, /LEARNING_FILE_NOT_READY|not_ready/);
});

test("acknowledgement and panel state are scoped to owner kind and stable path", async () => {
  const source = await readFile(componentPath, "utf8");
  assert.match(source, /FILE_CONFIDENTIALITY_STORAGE_PREFIX\}:\$\{route.kind\}:\$\{protectedFileOwnerPath\(route\)\}/);
  assert.match(source, /key=\{protectedFileOwnerPath\(route\)\}/);
  assert.match(source, /kind: "guide", guideSlug/);
  assert.match(source, /kind: "lesson", subjectSlug, courseSlug, lessonSlug/);
});

test("requires the confidentiality warning once per lesson and browser session before downloading", async () => {
  const [source, apiSource] = await Promise.all([
    readFile(componentPath, "utf8"),
    readFile(apiPath, "utf8"),
  ]);

  assert.match(source, /const FILE_CONFIDENTIALITY_STORAGE_PREFIX = "protected-file:confidentiality-warning"/);
  assert.match(source, /function fileConfidentialityStorageKey\(/);
  assert.match(source, /subjectSlug[\s\S]*courseSlug[\s\S]*lessonSlug/);
  assert.match(source, /window\.sessionStorage\.getItem\(storageKey\)/);
  assert.match(source, /window\.sessionStorage\.setItem\(storageKey, FILE_CONFIDENTIALITY_STORAGE_VALUE\)/);
  assert.match(source, /if \(!confidentialityAcknowledged && !readFileConfidentialityAcknowledged\(confidentialityStorageKey\)\)/);
  assert.match(source, /setVisibleConfidentialityStorageKey\(confidentialityStorageKey\);\s+return;/);
  assert.match(source, /setVisibleConfidentialityStorageKey\(null\);\s+switch \(state\.status\)/);
  assert.match(source, /data-ui="lk\.learning\.lessonFile\.confidentialityWarning"/);
  assert.match(source, /absolute left-0 top-full[\s\S]*?sm:left-auto sm:right-0/);
  assert.match(source, /before:left-8[\s\S]*?sm:before:left-auto sm:before:right-8/);
  assert.match(source, /role="status"/);
  assert.match(source, /aria-live="polite"/);
  assert.match(source, /aria-expanded=\{confidentialityWarningVisible \? true : undefined\}/);
  assert.match(source, /Файл предназначен только для личного обучения\. Его нельзя распространять\./);
  assert.match(source, /Чтобы скачать файл, нажмите «Скачать» ещё раз\./);
  assert.match(source, /event\.key === "Escape"/);
  assert.doesNotMatch(source, /id=\{warningId\} className="sr-only"/);
  assert.match(apiSource, /confidentialityAcknowledged: true/);
});

test("keeps entitlement denial distinct from temporary PDF failures", async () => {
  const source = await readFile(componentPath, "utf8");

  assert.match(source, /readonly status: "denied"/);
  assert.match(source, /Доступ к файлу не подтверждён\./);
  assert.match(source, /readonly status: "unavailable"/);
  assert.match(source, /Файл временно недоступен\. Попробуйте ещё раз\./);
  assert.match(source, /case "denied":\s+void requestDelivery\(\)/);
});

test("surfaces only trusted-browser denial codes through the PDF recovery state", async () => {
  const componentSource = await readFile(componentPath, "utf8");
  const apiSource = await readFile(apiPath, "utf8");

  assert.match(apiSource, /response\.status === 401 \|\| response\.status === 403/);
  assert.match(apiSource, /trustedBrowserProtectedErrorCode\(payload\)/);
  assert.match(apiSource, /readonly status: "trusted_browser_recovery"/);
  assert.match(apiSource, /return \{ status: "denied" \}/);
  assert.match(componentSource, /readonly status: "trusted_browser_recovery"/);
  assert.match(componentSource, /TRUSTED_BROWSER_REQUIRED/);
  assert.match(componentSource, /TRUSTED_BROWSER_SUPPORT_REQUIRED/);
  assert.match(componentSource, /TRUSTED_BROWSER_RISK_LOCKED/);
  assert.match(componentSource, /href="\/lk#trusted-browsers"/);
});
