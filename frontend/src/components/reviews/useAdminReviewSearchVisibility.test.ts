import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";
import ts from "typescript";
import type { ClientAccountProfile } from "../../lib/clientAccount";

const admin: ClientAccountProfile = { id: 1, name: "Администратор", email: "admin@test.test", phone: null, avatar_url: null, role: "admin_full" };

async function harness() {
  let visible: unknown = false;
  let cleanup: (() => void) | undefined;
  let resolve!: (profile: ClientAccountProfile | null) => void;
  const pending = new Promise<ClientAccountProfile | null>((done) => { resolve = done; });
  const listeners = new Map<string, (event: unknown) => void>();
  const source = await readFile(new URL("./useAdminReviewSearchVisibility.ts", import.meta.url), "utf8");
  const output = ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.CommonJS } }).outputText;
  const sandbox = { exports: {} as typeof import("./useAdminReviewSearchVisibility") };
  const requireStub = (specifier: string): unknown => {
    if (specifier === "react") return {
      useState: () => [visible, (next: unknown) => { visible = next; }],
      useEffect: (effect: () => (() => void)) => { if (!cleanup) cleanup = effect(); },
    };
    if (specifier === "@/lib/clientAccount") return {
      CLIENT_ACCOUNT_CLEARED_EVENT: "clear",
      CLIENT_ACCOUNT_PROFILE_UPDATED_EVENT: "update",
      isClientAccountProfile: (value: unknown) => typeof value === "object" && value !== null && "id" in value,
      loadClientAccountProfile: () => pending,
    };
    throw new Error(specifier);
  };
  Function("require", "module", "exports", "window", output)(requireStub, sandbox, sandbox.exports, {
    addEventListener: (type: string, listener: (event: unknown) => void) => listeners.set(type, listener),
    removeEventListener: (type: string) => listeners.delete(type),
  });
  return {
    ...sandbox.exports, resolve,
    emit: (type: string, detail?: unknown) => listeners.get(type)?.({ detail }),
    unmount: () => cleanup?.(),
  };
}

test("only confirmed administrators outside impersonation can see the tool", async () => {
  const ui = await harness();
  for (const role of ["student", "teacher", "manager"]) assert.equal(ui.canSearchReviewsAsAdmin({ ...admin, role }), false);
  assert.equal(ui.canSearchReviewsAsAdmin(null), false);
  for (const role of ["admin_full", "admin_limited"]) assert.equal(ui.canSearchReviewsAsAdmin({ ...admin, role }), true);
  for (const status of ["active", "expired"] as const) {
    assert.equal(ui.canSearchReviewsAsAdmin({ ...admin, impersonation: { status, read_only: true, actor: null, subject: null, started_at: null, expires_at: null, return_path: "/admin" } }), false);
  }
  assert.equal(ui.useAdminReviewSearchVisibility(), false);
  ui.resolve(admin);
  await Promise.resolve();
  assert.equal(ui.useAdminReviewSearchVisibility(), true);
  ui.emit("clear");
  assert.equal(ui.useAdminReviewSearchVisibility(), false);
});

test("late auth response cannot restore a tool hidden by logout or profile update", async () => {
  for (const event of ["clear", "update"]) {
    const ui = await harness();
    ui.useAdminReviewSearchVisibility();
    ui.emit(event, { ...admin, role: "student" });
    ui.resolve(admin);
    await Promise.resolve();
    assert.equal(ui.useAdminReviewSearchVisibility(), false);
    ui.emit("update", admin);
    assert.equal(ui.useAdminReviewSearchVisibility(), true);
    ui.emit("update", null);
    assert.equal(ui.useAdminReviewSearchVisibility(), false);
    ui.unmount();
  }
});
