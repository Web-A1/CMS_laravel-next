import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";
import ts from "typescript";

const clientPath = "./clientAccount.ts";
const account: typeof import("./clientAccount") = await import(clientPath);

test("session expiry never disables draft protection; only confirmed successful logout does", async () => {
  let cleanup: (() => void) | undefined;
  const source = await readFile(new URL("./useUnsavedChanges.ts", import.meta.url), "utf8");
  const output = ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText;
  const sandbox = { exports: {} as Record<string, unknown> };
  Function("require", "module", "exports", output)((name: string) => {
    if (name === "react") return { useEffect: (effect: () => (() => void) | undefined) => { cleanup = effect(); } };
    if (name === "./clientAccount") return account;
    throw new Error(name);
  }, sandbox, sandbox.exports);
  const { useUnsavedChanges } = sandbox.exports as typeof import("./useUnsavedChanges");
  const { requestInternalNavigation } = sandbox.exports as typeof import("./useUnsavedChanges");
  const globalScope = globalThis as Record<string, unknown>;
  const previousWindow = globalScope.window;
  const previousDocument = globalScope.document;
  const target = new EventTarget();
  let confirm = false;
  globalScope.window = Object.assign(target, { confirm: () => confirm });
  globalScope.document = new EventTarget();
  const warns = () => { const event = new Event("beforeunload", { cancelable: true }); target.dispatchEvent(event); return event.defaultPrevented; };
  const clear = (reason: string) => target.dispatchEvent(new CustomEvent(account.CLIENT_ACCOUNT_CLEARED_EVENT, { detail: { reason } }));
  try {
    useUnsavedChanges(true);
    assert.equal(warns(), true);
    clear("profile-401");
    assert.equal(warns(), true);
    clear("idle-logout");
    assert.equal(warns(), true);
    clear("logout");
    assert.equal(warns(), true);
    assert.equal(account.requestClientAccountLogout(), false);
    assert.equal(requestInternalNavigation(), false);
    assert.equal(warns(), true);
    confirm = true;
    assert.equal(account.requestClientAccountLogout(), true);
    assert.equal(requestInternalNavigation(), true);
    assert.equal(warns(), true, "failed or pending logout must not disable the warning");
    clear("profile-401");
    assert.equal(warns(), true);
    clear("logout");
    assert.equal(warns(), false);
    cleanup?.();
    useUnsavedChanges(true);
    assert.equal(warns(), true);
    cleanup?.();
    assert.equal(warns(), false, "unmounted editors remove all listeners");
  } finally {
    cleanup?.();
    if (previousWindow === undefined) delete globalScope.window; else globalScope.window = previousWindow;
    if (previousDocument === undefined) delete globalScope.document; else globalScope.document = previousDocument;
  }
});
