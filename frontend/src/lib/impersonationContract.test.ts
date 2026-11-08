import assert from "node:assert/strict";
import test from "node:test";

const modulePath = "./impersonationContract.ts";
const { parseImpersonationContext } = await import(modulePath);

const active = {
  status: "active",
  read_only: true,
  actor: { id: 1, name: "Администратор", role: "admin_full" },
  subject: { id: 2, name: "Ученик", role: "user_no_purchase" },
  started_at: "2026-09-10T10:00:00.000Z",
  expires_at: "2026-09-10T10:30:00.000Z",
  return_path: "/admin/users?q=student",
} as const;

test("accepts the fixed active impersonation contract", () => {
  assert.deepEqual(parseImpersonationContext(active), active);
});

test("rejects malformed, external and sensitive impersonation payloads", () => {
  assert.equal(parseImpersonationContext({ ...active, return_path: "https://evil.test" }), null);
  assert.equal(parseImpersonationContext({ ...active, actor: { id: 1, name: "Admin", role: "admin_full", password: "secret" } }), null);
  assert.equal(parseImpersonationContext({ ...active, status: "unknown" }), null);
  assert.equal(parseImpersonationContext({ ...active, subject: { id: 0, name: "Ученик", role: "user_no_purchase" } }), null);
});
