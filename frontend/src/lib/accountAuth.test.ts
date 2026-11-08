import assert from "node:assert/strict";
import test from "node:test";

import ts from "typescript";
import { readFile } from "node:fs/promises";
import { createServer } from "node:http";

type AccountAuthModule = typeof import("./accountAuth");

async function loadAccountAuthModule(requestHeaders = new Headers()): Promise<AccountAuthModule> {
  const source = await readFile(new URL("./accountAuth.ts", import.meta.url), "utf8");
  const output = ts.transpileModule(source, {
    compilerOptions: {
      esModuleInterop: true,
      module: ts.ModuleKind.CommonJS,
      target: ts.ScriptTarget.ES2022,
    },
  }).outputText;
  const sandboxModule = { exports: {} as Record<string, unknown> };
  const requireStub = (specifier: string): unknown => {
    if (specifier === "next/headers") return { headers: async () => requestHeaders };
    if (specifier === "@/lib/apiBaseUrl") return { backendApiBaseUrl: () => "http://backend.test" };
    throw new Error(`Unexpected module in accountAuth test: ${specifier}`);
  };

  Function("require", "module", "exports", output)(requireStub, sandboxModule, sandboxModule.exports);
  return sandboxModule.exports as AccountAuthModule;
}

const accountAuth = await loadAccountAuthModule();
const profile = { id: 1, name: "User", email: "user@example.test", phone_verified: true } as const;

test("header skips backend without a nonempty session cookie, including XSRF-only requests", async () => {
  for (const cookie of ["", "XSRF-TOKEN=marker", "cms-session=", "other-cms-session=marker"]) {
    const result = await accountAuth.loadHeaderAccountProfile({
      buildRequest: async () => ({ resolvedApiBaseUrl: "http://backend.test", headers: { Cookie: cookie } }),
      fetchAccount: async () => { throw new Error("must not fetch for guests"); },
    });
    assert.deepEqual(result, { status: "unauthenticated" });
  }
});

test("header profiles are isolated per request and expose only header identity fields", async () => {
  const dependencies = (id: number) => ({
    buildRequest: async () => ({ resolvedApiBaseUrl: "http://backend.test", headers: { Cookie: `cms-session=${id}` } }),
    fetchAccount: async (_input: RequestInfo | URL, init?: RequestInit) => {
      assert.equal(init?.cache, "no-store");
      assert.equal(init?.redirect, "manual");
      assert.equal((init?.headers as Record<string, string>).Cookie, `cms-session=${id}`);
      return new Response(JSON.stringify({ ...profile, id, name: `User ${id}`, avatar_url: "/avatar.webp", manager_id: 99 }));
    },
  });
  const [first, second] = await Promise.all([
    accountAuth.loadHeaderAccountProfile(dependencies(1)),
    accountAuth.loadHeaderAccountProfile(dependencies(2)),
  ]);
  for (const [index, result] of [first, second].entries()) {
    assert.equal(result.status, "authenticated");
    if (result.status !== "authenticated") throw new Error("missing profile");
    assert.equal(result.profile.id, index + 1);
    assert.equal(result.profile.avatar_url, "/avatar.webp");
    assert.equal(result.profile.email, undefined);
    assert.equal("manager_id" in result.profile, false);
  }
  assert.deepEqual(accountAuth.classifyAccountResponse(200, { ...profile, avatar_url: 123 }), { status: "unavailable" });
});

test("header request budget also bounds a response whose body stalls", async (context) => {
  const server = createServer((_request, response) => {
    response.writeHead(200, { "content-type": "application/json" });
    response.write('{"id":');
  });
  await new Promise<void>((resolve) => server.listen(0, "127.0.0.1", resolve));
  context.after(() => { server.closeAllConnections(); server.close(); });
  const address = server.address();
  assert.ok(address && typeof address !== "string");
  const started = performance.now();
  const result = await accountAuth.loadHeaderAccountProfile({
    buildRequest: async () => ({ resolvedApiBaseUrl: `http://127.0.0.1:${address.port}`, headers: { Cookie: "cms-session=marker" } }),
  });
  assert.deepEqual(result, { status: "unavailable" });
  assert.ok(performance.now() - started < 2000, "header must not wait for the general ten-second account timeout");
});

test("classifies valid profile and confirmed 401 separately", () => {
  assert.deepEqual(accountAuth.classifyAccountResponse(200, profile), {
    status: "authenticated",
    profile,
  });
  assert.deepEqual(accountAuth.classifyAccountResponse(401, null), { status: "unauthenticated" });
});

test("classifies 5xx and malformed 200 as unavailable", () => {
  assert.deepEqual(accountAuth.classifyAccountResponse(500, null), { status: "unavailable" });
  assert.deepEqual(accountAuth.classifyAccountResponse(200, { id: "1" }), { status: "unavailable" });
  assert.deepEqual(accountAuth.classifyAccountResponse(200, { ...profile, phone_verified: "yes" }), {
    status: "unavailable",
  });
});

test("network failure and timeout belong to unavailable state", async () => {
  for (const errorName of ["TypeError", "TimeoutError"]) {
    const error = new Error("request failed");
    error.name = errorName;
    const result = await accountAuth.loadAccountProfile("/backend", "/lk", {
      buildRequest: async () => ({ resolvedApiBaseUrl: "https://backend.test", headers: {} }),
      fetchAccount: async () => {
        throw error;
      },
    });
    assert.deepEqual(result, { status: "unavailable" });
  }
});

test("loader keeps 401 distinct from backend failures", async () => {
  const dependencies = {
    buildRequest: async () => ({ resolvedApiBaseUrl: "https://backend.test", headers: {} }),
    fetchAccount: async () =>
      new Response(JSON.stringify({ error: { code: "UNAUTHENTICATED" } }), {
        status: 401,
        headers: { "content-type": "application/json" },
      }),
  } as const;

  assert.deepEqual(await accountAuth.loadAccountProfile("/backend", "/lk", dependencies), {
    status: "unauthenticated",
  });
});

test("SSR always selects the configured backend despite Host, forwarded headers or a caller URL", async () => {
  const requestHeaders = new Headers({
    host: "attacker.example:4567",
    "x-forwarded-host": "another-attacker.example:4568",
    "x-forwarded-proto": "http",
    "x-forwarded-for": "127.0.0.1",
    cookie: "ssrf-regression=marker",
  });
  const auth = await loadAccountAuthModule(requestHeaders);

  for (const callerBase of ["/backend", "http://attacker.example", "//attacker.example"]) {
    const request = await auth.accountBackendRequest(callerBase, "/admin");
    assert.equal(request.resolvedApiBaseUrl, "http://backend.test");
    assert.equal(request.headers.Cookie, "ssrf-regression=marker");
    assert.equal(request.headers.Origin, "http://another-attacker.example:4568");
    assert.equal(request.headers.Referer, "http://another-attacker.example:4568/admin");
    for (const name of Object.keys(request.headers)) {
      assert.doesNotMatch(name.toLowerCase(), /^(host|x-forwarded-)/);
    }
  }
});

test("invalid forwarded authority or protocol is omitted from Sanctum metadata", async () => {
  for (const [host, proto] of [
    ["user:password@attacker.example", "https"],
    ["attacker.example/path", "https"],
    ["attacker.example?next=1", "https"],
    ["attacker.example#fragment", "https"],
    ["attacker.example\\path", "https"],
    ["attacker.example:bad-port", "https"],
    ["frontend.example,attacker.example", "https"],
    ["attacker.example", "javascript"],
  ]) {
    const auth = await loadAccountAuthModule(new Headers({ "x-forwarded-host": host, "x-forwarded-proto": proto }));
    const request = await auth.accountBackendRequest("/backend", "/account");
    assert.equal(request.resolvedApiBaseUrl, "http://backend.test");
    assert.equal(request.headers.Origin, undefined);
    assert.equal(request.headers.Referer, undefined);
  }
});

test("local and staging frontend origins remain compatible with Sanctum", async () => {
  for (const [host, proto] of [["127.0.0.1:3000", "http"], ["staging.christmedschool.com", "https"]]) {
    const auth = await loadAccountAuthModule(new Headers({ host, "x-forwarded-proto": proto }));
    const request = await auth.accountBackendRequest("/backend", "/account");
    assert.equal(request.headers.Origin, `${proto}://${host}`);
    assert.equal(request.headers.Referer, `${proto}://${host}/account`);
  }
});

test("SSR auth and admin requests do not replay marker cookies through redirects", async (context) => {
  const requests: string[] = [];
  const server = createServer((request, response) => {
    requests.push(request.url ?? "");
    if (request.url === "/capture") {
      response.writeHead(200, { "content-type": "application/json" });
      response.end(JSON.stringify(profile));
      return;
    }
    assert.equal(request.headers.cookie, "ssrf-regression=marker");
    response.writeHead(302, { location: "/capture" });
    response.end();
  });
  await new Promise<void>((resolve) => server.listen(0, "127.0.0.1", resolve));
  context.after(() => new Promise<void>((resolve, reject) => server.close((error) => error ? reject(error) : resolve())));
  const address = server.address();
  assert.ok(address && typeof address !== "string");
  const dependencies = {
    buildRequest: async () => ({
      resolvedApiBaseUrl: `http://127.0.0.1:${address.port}`,
      headers: { Cookie: "ssrf-regression=marker" },
    }),
  };

  assert.deepEqual(await accountAuth.loadAccountProfile("/backend", "/account", dependencies), { status: "unavailable" });
  const response = await accountAuth.fetchAccountBackend("/backend", "/api/v1/admin/me", "/admin", dependencies);
  assert.equal(response.status, 302);
  assert.deepEqual(requests, ["/api/v1/auth/me", "/api/v1/admin/me"]);
});
