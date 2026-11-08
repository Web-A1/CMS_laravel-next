import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import test from "node:test";

const modulePath = "./checkoutCartPolicy.ts";
const {
  MULTI_COURSE_CHECKOUT_MESSAGE,
  multiCourseCheckoutMessage,
}: typeof import("./checkoutCartPolicy") = await import(modulePath);

test("explains the one-course checkout restriction before payment", () => {
  assert.equal(multiCourseCheckoutMessage(0), "");
  assert.equal(multiCourseCheckoutMessage(1), "");
  assert.equal(multiCourseCheckoutMessage(2), MULTI_COURSE_CHECKOUT_MESSAGE);
  assert.equal(
    MULTI_COURSE_CHECKOUT_MESSAGE,
    "Сейчас можно оплатить только один товар за раз. Оставьте в корзине один товар, чтобы продолжить.",
  );
});

test("wires the restriction to every route that can advance checkout", () => {
  const checkoutFlowSource = readFileSync(new URL("CheckoutFlow.tsx", import.meta.url), "utf-8");

  assert.match(checkoutFlowSource, /multiCourseCheckoutMessage\(cartCourses\.length\)/);
  assert.match(
    checkoutFlowSource,
    /checkoutError \|\| cartBlockerMessage \|\|/,
  );
  assert.equal(
    checkoutFlowSource.match(/disabled=\{Boolean\(cartBlockerMessage\)\}/g)?.length,
    3,
  );
  assert.match(checkoutFlowSource, /setCheckoutError\(MULTI_COURSE_CHECKOUT_MESSAGE\);\s+setPhase\("cart"\);/);
});

test("requires checkout phone verification before payment actions", () => {
  const checkoutFlowSource = readFileSync(new URL("CheckoutFlow.tsx", import.meta.url), "utf-8");

  assert.match(checkoutFlowSource, /data-ui="checkout\.phoneVerification"/);
  assert.match(checkoutFlowSource, /startCheckoutPhoneVerification/);
  assert.match(checkoutFlowSource, /checkoutPhoneVerified/);
  assert.match(checkoutFlowSource, /!checkoutPhoneVerified/);
  assert.match(checkoutFlowSource, /phone-verification\/status/);
});
