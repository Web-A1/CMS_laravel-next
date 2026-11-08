import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";
import ts from "typescript";

type ImpersonationApiModule = typeof import("./impersonationApi");

async function loadModule(): Promise<ImpersonationApiModule> {
  const [apiSource, contractSource] = await Promise.all([
    readFile(new URL("./impersonationApi.ts", import.meta.url), "utf8"),
    readFile(new URL("./impersonationContract.ts", import.meta.url), "utf8"),
  ]);
  const contractOutput = ts.transpileModule(contractSource, {
    compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
  }).outputText;
  const contractModule = { exports: {} as Record<string, unknown> };
  Function("module", "exports", contractOutput)(contractModule, contractModule.exports);
  const apiOutput = ts.transpileModule(apiSource, {
    compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
  }).outputText;
  const apiModule = { exports: {} as Record<string, unknown> };
  const requireStub = (specifier: string): unknown => {
    if (specifier === "./clientAccount") {
      return {
        clientAccountApiBaseUrl: () => "/backend",
        readXsrfToken: (cookie: string) => decodeURIComponent(cookie.split("XSRF-TOKEN=")[1]?.split(";")[0] ?? ""),
      };
    }
    if (specifier === "./impersonationContract") {
      return contractModule.exports;
    }
    throw new Error(`Unexpected module in impersonation API test: ${specifier}`);
  };
  Function("module", "exports", "require", apiOutput)(apiModule, apiModule.exports, requireStub);
  return apiModule.exports as ImpersonationApiModule;
}

const {
  ImpersonationApiError,
  loadImpersonationContext,
  startImpersonation,
  stopImpersonation,
} = await loadModule();

const context = {
  status: "active",
  read_only: true,
  actor: { id: 1, name: "Администратор", role: "admin_full" },
  subject: { id: 2, name: "Ученик", role: "user_no_purchase" },
  started_at: "2026-09-10T10:00:00.000Z",
  expires_at: "2026-09-10T10:30:00.000Z",
  return_path: "/admin/users",
};

test("starts and stops through csrf-protected POST requests", async () => {
  const calls: Array<{ readonly url: string; readonly init?: RequestInit }> = [];
  const fetcher = async (input: RequestInfo | URL, init?: RequestInit): Promise<Response> => {
    calls.push({ url: String(input), init });
    if (String(input).endsWith("/sanctum/csrf-cookie")) return new Response("{}", { status: 200 });
    return new Response(JSON.stringify(context), { status: 200, headers: { "content-type": "application/json" } });
  };

  await startImpersonation(2, "/admin/users", "https://backend.test/", { fetcher, cookie: "XSRF-TOKEN=token%201" });
  await stopImpersonation("https://backend.test", { fetcher, cookie: "XSRF-TOKEN=token%201" });

  assert.equal(calls[0]?.url, "https://backend.test/sanctum/csrf-cookie");
  assert.equal(calls[1]?.url, "https://backend.test/api/v1/admin/users/2/impersonation");
  assert.equal(calls[1]?.init?.method, "POST");
  assert.deepEqual(calls[1]?.init?.headers, {
    Accept: "application/json",
    "Content-Type": "application/json",
    "X-XSRF-TOKEN": "token 1",
  });
  assert.equal(calls[2]?.url, "https://backend.test/sanctum/csrf-cookie");
  assert.equal(calls[3]?.url, "https://backend.test/api/v1/impersonation/stop");
});

test("loads only validated context and exposes backend errors", async () => {
  const loaded = await loadImpersonationContext("https://backend.test", {
    fetcher: async () => new Response(JSON.stringify(context), { status: 200 }),
  });
  assert.deepEqual(loaded, context);

  await assert.rejects(
    () => startImpersonation(2, "/admin/users", "https://backend.test", {
      fetcher: async (input) => String(input).endsWith("/sanctum/csrf-cookie")
        ? new Response("{}", { status: 200 })
        : new Response(JSON.stringify({ error: { message: "Нет доступа." } }), { status: 403 }),
      cookie: "XSRF-TOKEN=token",
    }),
    (error: unknown) => error instanceof ImpersonationApiError && error.status === 403 && error.message === "Нет доступа.",
  );
});
