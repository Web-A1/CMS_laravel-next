import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

import ts from "typescript";

type ClientAccountModule = typeof import("./clientAccount");

async function loadClientAccountModule(): Promise<ClientAccountModule> {
  const source = await readFile(new URL("./clientAccount.ts", import.meta.url), "utf8");
  const output = ts.transpileModule(source, {
    compilerOptions: {
      module: ts.ModuleKind.CommonJS,
      target: ts.ScriptTarget.ES2022,
    },
  }).outputText;
  const sandboxModule = { exports: {} as Record<string, unknown> };

  Function("module", "exports", output)(sandboxModule, sandboxModule.exports);
  return sandboxModule.exports as ClientAccountModule;
}

const clientAccount = await loadClientAccountModule();

test("header refresh distinguishes expired sessions from temporary failures", async () => {
  for (const status of [401, 500]) {
    const result = await clientAccount.loadClientAccountProfileResult("https://backend.test", {
      fetchAccount: async () => new Response("{}", { status }),
    });
    assert.deepEqual(result, { status: status === 401 ? "unauthenticated" : "unavailable" });
  }
  assert.deepEqual(await clientAccount.loadClientAccountProfileResult("https://backend.test", {
    fetchAccount: async () => { throw new TypeError("offline"); },
  }), { status: "unavailable" });
});

test("shows admin navigation only for a validated staff role, never for learners or unknown roles", () => {
  for (const role of ["admin_full", "admin_limited", "teacher", "manager", "polina"]) {
    const profile = { id: 1, role };
    assert.equal(clientAccount.isClientAccountProfile(profile), true);
    assert.equal(clientAccount.canOpenAdminWorkspace(profile), true);
  }
  for (const role of ["user_no_purchase", "user_with_purchase", "guest", "admin", "", null, undefined]) {
    assert.equal(clientAccount.canOpenAdminWorkspace({ id: 1, role }), false);
  }
  assert.equal(clientAccount.canOpenAdminWorkspace(null), false);
  assert.equal(clientAccount.isClientAccountProfile({ id: 1, role: ["admin_full"] }), false);
});

test("sends Polina to articles and preserves existing account destinations without an email", () => {
  assert.equal(clientAccount.resolveClientAccountHref({ id: 1, name: "polina", email: null, role: "polina" }), "/admin/articles");
  for (const role of ["admin_full", "admin_limited", "teacher", "manager"]) {
    assert.equal(clientAccount.resolveClientAccountHref({ id: 1, role }), "/admin/profile");
  }
  for (const role of ["user_no_purchase", "user_with_purchase", "unknown"]) {
    assert.equal(clientAccount.resolveClientAccountHref({ id: 1, role }), "/lk");
  }
  assert.equal(clientAccount.resolveClientAccountHref(null), "/lk");
});

test("unsaved editors can cancel logout before the session request", () => {
  const globalScope = globalThis as Record<string, unknown>;
  const previousWindow = globalScope.window;
  const target = new EventTarget();
  globalScope.window = target;
  const cancel = (event: Event) => event.preventDefault();
  try {
    assert.equal(clientAccount.requestClientAccountLogout(), true);
    target.addEventListener(clientAccount.BEFORE_ACCOUNT_LOGOUT_EVENT, cancel);
    assert.equal(clientAccount.requestClientAccountLogout(), false);
    target.removeEventListener(clientAccount.BEFORE_ACCOUNT_LOGOUT_EVENT, cancel);
    assert.equal(clientAccount.requestClientAccountLogout(), true);
  } finally {
    if (previousWindow === undefined) delete globalScope.window;
    else globalScope.window = previousWindow;
  }
});

test("profile updates publish only validated account data", () => {
  const events: CustomEvent[] = [];
  const globalScope = globalThis as Record<string, unknown>;
  const previousWindow = globalScope.window;
  globalScope.window = {
    dispatchEvent: (event: CustomEvent) => {
      events.push(event);
      return true;
    },
  };

  try {
    clientAccount.notifyClientAccountProfileUpdated({ id: 7, name: "Новое имя", role: "admin_full" });
    clientAccount.notifyClientAccountProfileUpdated({ id: 0, name: "Подмена" });
  } finally {
    if (previousWindow === undefined) delete globalScope.window;
    else globalScope.window = previousWindow;
  }

  assert.equal(events.length, 1);
  assert.equal(events[0]?.type, clientAccount.CLIENT_ACCOUNT_PROFILE_UPDATED_EVENT);
  assert.deepEqual(events[0]?.detail, { id: 7, name: "Новое имя", role: "admin_full" });
});

test("loads authenticated client profile and preserves optional avatar url", async () => {
  const profile = {
    id: 7,
    name: "Мария",
    email: "maria@example.test",
    avatar_url: "https://cdn.example.test/avatar.webp",
    phone_verified: true,
  } as const;

  const result = await clientAccount.loadClientAccountProfile("https://backend.test", {
    fetchAccount: async () =>
      new Response(JSON.stringify(profile), {
        status: 200,
        headers: { "content-type": "application/json" },
      }),
  });

  assert.deepEqual(result, profile);
  assert.equal(result ? clientAccount.resolveClientAccountName(result) : "", "Мария");
  assert.equal(result ? clientAccount.resolveClientAccountAvatarUrl(result) : "", profile.avatar_url);
});

test("marks authenticated state after a valid profile response", async () => {
  const storage = new Map<string, string>();
  const globalScope = globalThis as Record<string, unknown>;
  const previousWindow = globalScope.window;
  globalScope.window = {
    localStorage: {
      getItem: (key: string) => storage.get(key) ?? null,
      removeItem: (key: string) => {
        storage.delete(key);
      },
      setItem: (key: string, value: string) => {
        storage.set(key, value);
      },
    },
  };

  try {
    await clientAccount.loadClientAccountProfile("https://backend.test", {
      fetchAccount: async () =>
        new Response(JSON.stringify({ id: 7, name: "Мария" }), {
          status: 200,
          headers: { "content-type": "application/json" },
        }),
    });

    assert.equal(clientAccount.hasClientAccountAuthenticatedMarker(), true);
  } finally {
    if (previousWindow === undefined) {
      delete globalScope.window;
    } else {
      globalScope.window = previousWindow;
    }
  }
});

test("treats unauthenticated and malformed account responses as missing profile", async () => {
  const unauthenticated = await clientAccount.loadClientAccountProfile("https://backend.test", {
    fetchAccount: async () =>
      new Response(JSON.stringify({ error: { code: "UNAUTHENTICATED" } }), {
        status: 401,
        headers: { "content-type": "application/json" },
      }),
  });

  const malformed = await clientAccount.loadClientAccountProfile("https://backend.test", {
    fetchAccount: async () =>
      new Response(JSON.stringify({ id: "7", name: "Мария" }), {
        status: 200,
        headers: { "content-type": "application/json" },
      }),
  });

  assert.equal(unauthenticated, null);
  assert.equal(malformed, null);
});

test("logout requests csrf first and sends decoded xsrf token to logout endpoint", async () => {
  const calls: Array<{ readonly url: string; readonly init?: RequestInit }> = [];

  await clientAccount.logoutClientAccount("https://backend.test/", {
    cookie: "theme=light; XSRF-TOKEN=token%201",
    fetchAuth: async (input, init) => {
      calls.push({ url: String(input), init });
      return new Response("{}", { status: 200 });
    },
  });

  assert.equal(calls.length, 2);
  assert.equal(calls[0]?.url, "https://backend.test/sanctum/csrf-cookie");
  assert.equal(calls[0]?.init?.method, "GET");
  assert.equal(calls[1]?.url, "https://backend.test/api/v1/auth/logout");
  assert.equal(calls[1]?.init?.method, "POST");
  assert.deepEqual(calls[1]?.init?.headers, {
    Accept: "application/json",
    "X-XSRF-TOKEN": "token 1",
  });
});

test("idle logout uses the dedicated idle logout endpoint", async () => {
  const calls: Array<{ readonly url: string; readonly init?: RequestInit }> = [];

  await clientAccount.logoutClientAccountAfterIdleTimeout("https://backend.test", {
    fetchAuth: async (input, init) => {
      calls.push({ url: String(input), init });
      return new Response("{}", { status: 200 });
    },
  });

  assert.equal(calls[1]?.url, "https://backend.test/api/v1/auth/idle-logout");
  assert.equal(calls[1]?.init?.method, "POST");
});

test("profile 401, logout and idle logout dispatch identity-free registry clear events", async () => {
  const events: Array<{ readonly type: string; readonly detail: unknown }> = [];
  const globalScope = globalThis as Record<string, unknown>;
  const previousWindow = globalScope.window;
  globalScope.window = {
    dispatchEvent: (event: CustomEvent) => {
      events.push({ type: event.type, detail: event.detail });
      return true;
    },
  };

  try {
    await clientAccount.loadClientAccountProfile("https://backend.test", {
      fetchAccount: async () => new Response("{}", { status: 401 }),
    });
    const fetchAuth = async () => new Response("{}", { status: 200 });
    await clientAccount.logoutClientAccount("https://backend.test", { fetchAuth });
    await clientAccount.logoutClientAccountAfterIdleTimeout("https://backend.test", { fetchAuth });
  } finally {
    if (previousWindow === undefined) {
      delete globalScope.window;
    } else {
      globalScope.window = previousWindow;
    }
  }

  assert.deepEqual(events, [
    { type: clientAccount.CLIENT_ACCOUNT_CLEARED_EVENT, detail: { reason: "profile-401" } },
    { type: clientAccount.CLIENT_ACCOUNT_CLEARED_EVENT, detail: { reason: "logout" } },
    { type: clientAccount.CLIENT_ACCOUNT_CLEARED_EVENT, detail: { reason: "idle-logout" } },
  ]);
  assert.equal(JSON.stringify(events).includes("userId"), false);
  assert.equal(JSON.stringify(events).includes("email"), false);
});

test("logout fails before redirect path when csrf or logout request fails", async () => {
  await assert.rejects(
    () =>
      clientAccount.logoutClientAccount("https://backend.test", {
        fetchAuth: async () => new Response("{}", { status: 500 }),
      }),
    /csrf request failed/,
  );

  await assert.rejects(
    () =>
      clientAccount.logoutClientAccount("https://backend.test", {
        fetchAuth: async (input) =>
          String(input).endsWith("/sanctum/csrf-cookie")
            ? new Response("{}", { status: 200 })
            : new Response("{}", { status: 500 }),
      }),
    /logout request failed/,
  );
});
