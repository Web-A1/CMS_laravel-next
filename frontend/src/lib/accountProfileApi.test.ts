import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";
import ts from "typescript";

const accountPath = "./clientAccount.ts";
const account = await import(accountPath) as typeof import("./clientAccount");
const source = await readFile(new URL("./accountProfileApi.ts", import.meta.url), "utf8");
const output = ts.transpileModule(source, {
  compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
}).outputText;
const sandbox = { exports: {} as Record<string, unknown> };
Function("require", "module", "exports", output)((name: string) => {
  if (name === "./clientAccount") return account;
  throw new Error(name);
}, sandbox, sandbox.exports);
const { saveAccountProfileName } = sandbox.exports as typeof import("./accountProfileApi");
const profile = { id: 7, name: "Иван Иванов", email: "empty@test.test", phone: null,
  role: "user_no_purchase", phone_verified: true, email_verified: true, must_verify_phone: false, must_verify_email: false };

test("sends only name with CSRF and accepts a complete identity snapshot", async () => {
  const calls: RequestInit[] = [];
  const result = await saveAccountProfileName("Иван Иванов", {
    cookie: "XSRF-TOKEN=test-csrf",
    fetcher: async (input, init) => {
      calls.push(init ?? {});
      if (String(input).endsWith("/sanctum/csrf-cookie")) return new Response(null, { status: 204 });
      assert.ok(String(input).endsWith("/api/v1/me/profile"));
      return Response.json(profile);
    },
  });
  assert.deepEqual(result, { status: "ready", profile });
  assert.equal(calls[1].method, "PATCH");
  assert.equal(calls[1].credentials, "include");
  assert.equal(calls[1].redirect, "error");
  assert.equal(new Headers(calls[1].headers).get("X-XSRF-TOKEN"), "test-csrf");
  assert.deepEqual(JSON.parse(String(calls[1].body)), { name: "Иван Иванов" });
});

test("returns actionable validation/session/server errors and preserves field errors", async () => {
  for (const status of [401, 403, 419, 422, 429, 500]) {
    const result = await saveAccountProfileName("   ", {
      cookie: "XSRF-TOKEN=test-csrf",
      fetcher: async input => String(input).endsWith("/sanctum/csrf-cookie")
        ? new Response(null, { status: 204 })
        : Response.json({ errors: { name: ["Укажите имя."] } }, { status }),
    });
    assert.equal(result.status, "error");
    if (result.status === "error") {
      assert.ok(result.message.length > 0);
      assert.equal(result.nameError, status === 422 ? "Укажите имя." : "");
    }
  }
});

test("fails closed on network failure, missing CSRF and incomplete success payload", async () => {
  assert.equal((await saveAccountProfileName("Имя", { fetcher: async () => { throw new Error("offline"); } })).status, "error");
  let calls = 0;
  assert.equal((await saveAccountProfileName("Имя", { cookie: "", fetcher: async () => {
    calls += 1; return new Response(null, { status: 204 });
  } })).status, "error");
  assert.equal(calls, 1);
  const result = await saveAccountProfileName("Имя", {
    cookie: "XSRF-TOKEN=test-csrf",
    fetcher: async input => String(input).endsWith("/sanctum/csrf-cookie")
      ? new Response(null, { status: 204 }) : Response.json({ id: 7, name: "Имя" }),
  });
  assert.equal(result.status, "error");
});

test("rejects missing contact fields even when name, role and verification are complete", async () => {
  for (const field of ["email", "phone"] as const) {
    const incomplete: Partial<typeof profile> = { ...profile };
    delete incomplete[field];
    const result = await saveAccountProfileName("Имя", {
      cookie: "XSRF-TOKEN=test-csrf",
      fetcher: async input => String(input).endsWith("/sanctum/csrf-cookie")
        ? new Response(null, { status: 204 }) : Response.json(incomplete),
    });
    assert.equal(result.status, "error", `missing ${field} must not replace the client profile`);
  }
});
