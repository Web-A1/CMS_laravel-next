import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

const componentPath = new URL("./TrustedBrowserPanel.tsx", import.meta.url);
const lkPagePath = new URL("../../app/(site)/lk/page.tsx", import.meta.url);

test("renders every server-owned browser state without deriving trust", async () => {
  const source = await readFile(componentPath, "utf8");

  for (const state of [
    "trusted",
    "unknown_can_confirm",
    "unknown_support_required",
    "revoked_current",
    "risk_step_up_required",
  ]) {
    assert.match(source, new RegExp(`case "${state}"`));
  }

  assert.match(source, /data-state=\{panel\.trust\.state\}/);
  assert.match(source, /panel\.trust\.state === "unknown_can_confirm"/);
  assert.doesNotMatch(source, /slots\.filter[\s\S]*unknown_can_confirm/);
});

test("renders the three stable server slot rows and safe display labels", async () => {
  const source = await readFile(componentPath, "utf8");

  assert.match(source, /panel\.slots\.map\(\(slot\) =>/);
  assert.match(source, /data-slot=\{slot\.slotNumber\}/);
  assert.match(source, /slot\.browserLabel, slot\.osLabel, slot\.deviceLabel/);
  assert.match(source, /slotStatusText\(slot\)/);
  assert.match(source, /Этот браузер/);
  assert.match(source, /Отключение сразу прекращает доступ[\s\S]*не освобождает его слот/);
  assert.doesNotMatch(source, /Удалить устройство/);
});

test("implements enroll OTP, resend cooldown, and verification refresh", async () => {
  const source = await readFile(componentPath, "utf8");

  assert.match(source, /createTrustedBrowserChallenge\(\{ purpose: "enroll" \}, apiBaseUrl\)/);
  assert.match(source, /verifyTrustedBrowserChallenge\(challengeFlow\.challenge\.challengeId, code, apiBaseUrl\)/);
  assert.match(source, /setResendSeconds\(challenge\.resendAfterSeconds\)/);
  assert.match(source, /window\.setInterval/);
  assert.match(source, /inputMode="numeric"/);
  assert.match(source, /autoComplete="one-time-code"/);
  assert.match(source, /pattern="\[0-9\]\{6\}"/);
  assert.match(source, /await reloadPanel\(\)/);
});

test("serializes challenge actions before React can re-render controls", async () => {
  const source = await readFile(componentPath, "utf8");

  assert.match(source, /const actionInFlightRef = useRef\(false\)/);
  assert.match(source, /if \(actionInFlightRef\.current\)/);
  assert.match(source, /actionInFlightRef\.current = true/);
  assert.match(source, /challengeFlow !== null \|\| !beginAsyncAction\("enroll_start"\)/);
  assert.match(source, /!beginAsyncAction\("challenge_resend"\)/);
  assert.match(source, /actionInFlightRef\.current = false/);
  assert.match(source, /panel\.trust\.state === "unknown_can_confirm" && !flowOpen/);
});

test("uses direct revoke for trusted browsers and password plus OTP step-up otherwise", async () => {
  const source = await readFile(componentPath, "utf8");

  assert.match(source, /panel\.trust\.state === "trusted"/);
  assert.match(source, /await revokeTrustedBrowser\(slotNumber, apiBaseUrl\)/);
  assert.match(source, /type="password"/);
  assert.match(source, /autoComplete="current-password"/);
  assert.match(source, /purpose: "revoke", slotNumber: showRevokePasswordFor, password/);
  assert.match(source, /Пароль принят\. Код отправлен/);
  assert.match(source, /Слот остаётся занятым до освобождения поддержкой/);
  assert.match(source, /const flowOpen =/);
  assert.match(source, /disabled=\{actionBusy \|\| flowOpen\}/);
});

test("keeps support recovery honest and accessible", async () => {
  const source = await readFile(componentPath, "utf8");

  assert.match(source, /Все три слота заняты\. Чтобы подтвердить этот браузер, обратитесь в поддержку\./);
  assert.doesNotMatch(source, /href=.*support/i);
  assert.match(source, /aria-labelledby="trusted-browsers-title"/);
  assert.match(source, /aria-busy="true"/);
  assert.match(source, /aria-invalid=\{actionError \? true : undefined\}/);
  assert.match(source, /aria-describedby=\{actionError \? actionErrorId : undefined\}/);
  assert.match(source, /dataUi="lk\.trustedBrowsers\.actionStatus"/);
  assert.match(source, /dataUi="lk\.trustedBrowsers\.actionError"/);
  assert.match(source, /<label htmlFor="trusted-browser-revoke-password"/);
  assert.match(source, /<label htmlFor="trusted-browser-email-code"/);
});

test("mounts the trusted-browser panel on the account page", async () => {
  const pageSource = await readFile(lkPagePath, "utf8");

  assert.match(pageSource, /TrustedBrowserPanel/);
  assert.match(pageSource, /<TrustedBrowserPanel apiBaseUrl=\{apiBaseUrl\} \/>/);
});
