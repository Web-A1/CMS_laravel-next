import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

const source = await readFile(new URL("./RegisterModeForm.tsx", import.meta.url), "utf8");

test("registration fields expose stable error associations", () => {
  for (const field of ["email", "password", "agreements"]) {
    assert.match(source, new RegExp(`auth-register-${field}-error`));
  }
  assert.match(source, /aria-invalid=/);
  assert.match(source, /aria-describedby=/);
  assert.match(source, /role="alert"/);
});

test("registration keeps phone collection out of the initial form", () => {
  assert.doesNotMatch(source, /registerName|registerPhone|phone_verification_token/);
});

test("failed validation focuses the first invalid field without focus styling changes", () => {
  const expectedOrder = ["auth-register-email", "auth-register-password", "auth-register-privacy"];
  let previousIndex = -1;
  for (const fieldId of expectedOrder) {
    const index = source.indexOf(`return "${fieldId}"`);
    assert.ok(index > previousIndex, `${fieldId} must follow the validation order`);
    previousIndex = index;
  }
  assert.match(source, /document\.getElementById\(fieldId\)\?\.focus\(\)/);
  assert.match(source, /onSubmit=\{handleSubmit\}/);
  assert.match(source, /validationFocusRequest === 0/);
  assert.match(source, /completedValidationFocusRequestRef\.current === validationFocusRequest/);
  const focusHelper = source.slice(
    source.indexOf("export function firstInvalidRegistrationFieldId"),
    source.indexOf("export default function RegisterModeForm"),
  );
  assert.doesNotMatch(focusHelper, /focus-visible|outline|ring-/);
});
