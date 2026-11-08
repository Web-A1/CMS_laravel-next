import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";
import ts from "typescript";

const source = await readFile(new URL("./legacyActivationApi.ts", import.meta.url), "utf8");
const output = ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText;
const sandbox = { exports: {} as Record<string, unknown> };
Function("module", "exports", "require", output)(sandbox, sandbox.exports, () => ({ readXsrfToken: () => "fixture-xsrf" }));
const api = sandbox.exports as typeof import("./legacyActivationApi");
const credential = { activation_id: "12345678-1234-1234-1234-123456789012", capability: "a".repeat(64) };
const view = { activation_id: credential.activation_id, step: "email", status: "pending", expires_at: "2026-10-14T12:00:00Z", verification_id: null, phone_status: null, call_number: null, phone: null };

test("resumable credential keeps only opaque capability and id, rejects malformed storage", () => {
  assert.deepEqual(api.parseActivationCredential({ ...credential, password: "secret", code: "123456" }), credential);
  for (const invalid of [null, {}, { ...credential, capability: "short" }, { ...credential, activation_id: "user:1" }]) {
    assert.throws(() => api.parseActivationCredential(invalid));
  }
});
test("server state requires known step, expiry and safe provider call number", () => {
  assert.deepEqual(api.parseActivationView(view), view);
  for (const changes of [{ step: "done" }, { status: "authenticated" }, { expires_at: "bad" }, { phone_status: "paid" }, { call_number: "javascript:alert(1)" }, { verification_id: "foreign" }, { phone: "user:1" }]) {
    assert.throws(() => api.parseActivationView({ ...view, ...changes }));
  }
  assert.equal(api.isActivationCompleted({ status: "completed", step: "done", authenticated: true, next_step: "lk" }), true);
  assert.equal(api.isActivationCompleted({ ...view, authenticated: true }), false);
});
test("capability travels in POST body, csrf and cookies, never URL or bearer authorization", async () => {
  const previousFetch = globalThis.fetch;
  const scope = globalThis as Record<string, unknown>;
  const previousDocument = scope.document;
  const calls: Array<{ url: string; init?: RequestInit }> = [];
  scope.document = { cookie: "XSRF-TOKEN=fixture" };
  globalThis.fetch = async (input, init) => {
    calls.push({ url: String(input), init });
    return new Response(JSON.stringify(view), { status: 200 });
  };
  try {
    assert.deepEqual(await api.activationRequest("/backend", "status", credential), view);
    assert.equal(calls[0]?.url, "/backend/sanctum/csrf-cookie");
    assert.equal(calls[1]?.url, "/backend/api/v1/auth/legacy-activation/status");
    assert.equal(calls[1]?.init?.credentials, "include");
    assert.equal(calls[1]?.init?.method, "POST");
    assert.deepEqual(JSON.parse(String(calls[1]?.init?.body)), credential);
    const headers = new Headers(calls[1]?.init?.headers);
    assert.equal(headers.get("Authorization"), null);
    assert.equal(headers.get("X-XSRF-TOKEN"), "fixture-xsrf");
  } finally {
    globalThis.fetch = previousFetch;
    if (previousDocument === undefined) delete scope.document; else scope.document = previousDocument;
  }
});
