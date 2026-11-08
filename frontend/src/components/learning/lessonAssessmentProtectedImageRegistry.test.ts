import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

import ts from "typescript";

import type { ProtectedDiagramAsset } from "./lessonAssessmentProtectedImageRegistry.ts";

const CLIENT_ACCOUNT_CLEARED_EVENT = "cms:client-account-cleared";
type RegistryModule = typeof import("./lessonAssessmentProtectedImageRegistry");

async function loadRegistryModule(): Promise<RegistryModule> {
  const source = await readFile(new URL("./lessonAssessmentProtectedImageRegistry.ts", import.meta.url), "utf8");
  const output = ts.transpileModule(source, {
    compilerOptions: {
      esModuleInterop: true,
      module: ts.ModuleKind.CommonJS,
      target: ts.ScriptTarget.ES2022,
    },
  }).outputText;
  const sandboxModule = { exports: {} as Record<string, unknown> };
  const requireStub = (specifier: string): unknown => {
    if (specifier === "@/lib/clientAccount") {
      return { CLIENT_ACCOUNT_CLEARED_EVENT };
    }
    throw new Error(`Unexpected registry dependency: ${specifier}`);
  };
  Function("require", "module", "exports", output)(requireStub, sandboxModule, sandboxModule.exports);
  return sandboxModule.exports as RegistryModule;
}

const registryModule = await loadRegistryModule();

const asset = (id: number): ProtectedDiagramAsset => ({
  assetPath: `/api/v1/learning/subjects/anatomiya/courses/artrologiya/lessons/lesson/assessment/assets/diagram-0${id}`,
  width: 840,
  height: 630,
});

const flush = async (): Promise<void> => {
  await new Promise<void>((resolve) => setImmediate(resolve));
};

test("deduplicates protected fetches and revokes object URLs on retry, clear and dispose", async () => {
  const calls: Array<string> = [];
  const revoked: Array<string> = [];
  let objectUrlSequence = 0;
  const eventTarget = new EventTarget();
  const registry = registryModule.createProtectedDiagramRegistry({
    apiBaseUrl: "/backend",
    attemptId: 41,
    contentVersion: 10,
    dependencies: {
      eventTarget,
      fetchAsset: async (input) => {
        calls.push(String(input));
        return new Response(new Blob(["protected-image"]), { status: 200 });
      },
      createObjectURL: () => `blob:diagram-${++objectUrlSequence}`,
      revokeObjectURL: (url) => revoked.push(url),
    },
  });
  const snapshots = [registry.getSnapshot()];
  const unsubscribe = registry.subscribe(() => snapshots.push(registry.getSnapshot()));

  const [first, duplicate] = await Promise.all([registry.load(asset(1)), registry.load(asset(1))]);
  assert.equal(calls.length, 1);
  assert.equal(first.objectUrl, duplicate.objectUrl);
  assert.deepEqual(JSON.parse(first.generation).slice(-3), [0, 41, 10]);
  assert.deepEqual(JSON.parse(first.key), [
    0,
    41,
    10,
    asset(1).assetPath,
    asset(1).width,
    asset(1).height,
  ]);
  assert.match(calls[0] ?? "", /^\/backend\/api\/v1\/learning\//);

  const retried = await registry.retry(asset(1));
  assert.equal(calls.length, 2);
  assert.notEqual(retried.objectUrl, first.objectUrl);
  assert.deepEqual(revoked, [first.objectUrl]);

  eventTarget.dispatchEvent(new Event(CLIENT_ACCOUNT_CLEARED_EVENT));
  assert.deepEqual(revoked, [first.objectUrl, retried.objectUrl]);
  assert.deepEqual(JSON.parse(snapshots[0] ?? "").slice(-3), [0, 41, 10]);
  assert.deepEqual(JSON.parse((snapshots[1] ?? "").replace("invalid:", "")).slice(-3), [1, 41, 10]);
  await assert.rejects(() => registry.load(asset(1)), /registry is invalidated/);
  assert.equal(calls.length, 2);

  unsubscribe();
  registry.dispose();
  assert.equal(revoked.length, 2);
});

test("retry establishes the current request before preload and performs only one replacement GET", async () => {
  const calls: Array<string> = [];
  let objectUrlSequence = 0;
  const registry = registryModule.createProtectedDiagramRegistry({
    apiBaseUrl: "",
    attemptId: 45,
    contentVersion: 14,
    dependencies: {
      eventTarget: null,
      fetchAsset: async (input) => {
        calls.push(String(input));
        return new Response(new Blob(["image"]), { status: 200 });
      },
      createObjectURL: () => "blob:retry-" + ++objectUrlSequence,
      revokeObjectURL: () => undefined,
      scheduleIdle: () => 1,
      cancelIdle: () => undefined,
    },
  });

  await registry.load(asset(1));
  const retried = registry.retry(asset(1));
  registry.preloadAround(asset(1), [asset(1)]);
  await retried;
  await flush();

  assert.equal(calls.length, 2);
  registry.dispose();
});

test("a replacement registry cannot reveal a Blob handle from an older instance", async () => {
  const dependencies = {
    eventTarget: null,
    fetchAsset: async () => new Response(new Blob(["image"]), { status: 200 }),
    createObjectURL: () => "blob:instance",
    revokeObjectURL: () => undefined,
  };
  const firstRegistry = registryModule.createProtectedDiagramRegistry({
    apiBaseUrl: "",
    attemptId: 46,
    contentVersion: 15,
    dependencies,
  });
  const secondRegistry = registryModule.createProtectedDiagramRegistry({
    apiBaseUrl: "",
    attemptId: 46,
    contentVersion: 15,
    dependencies,
  });

  const firstHandle = await firstRegistry.load(asset(1));
  assert.notEqual(firstHandle.generation, secondRegistry.getSnapshot());

  firstRegistry.dispose();
  secondRegistry.dispose();
});

test("keeps at most nine protected diagram entries in tab memory", async () => {
  const revoked: Array<string> = [];
  let objectUrlSequence = 0;
  const registry = registryModule.createProtectedDiagramRegistry({
    apiBaseUrl: "",
    attemptId: 44,
    contentVersion: 13,
    dependencies: {
      eventTarget: null,
      fetchAsset: async () => new Response(new Blob(["image"]), { status: 200 }),
      createObjectURL: () => `blob:bounded-${++objectUrlSequence}`,
      revokeObjectURL: (url) => revoked.push(url),
    },
  });

  for (let id = 1; id <= 10; id++) {
    await registry.load(asset(id));
  }

  assert.deepEqual(revoked, ["blob:bounded-1"]);
  registry.dispose();
  assert.equal(revoked.length, 10);
});

test("loads current first, adjacent next and remaining assets sequentially in idle", async () => {
  const calls: Array<string> = [];
  const idleCallbacks: Array<() => void> = [];
  const registry = registryModule.createProtectedDiagramRegistry({
    apiBaseUrl: "",
    attemptId: 42,
    contentVersion: 11,
    dependencies: {
      eventTarget: null,
      fetchAsset: async (input) => {
        calls.push(String(input));
        return new Response(new Blob(["image"]), { status: 200 });
      },
      createObjectURL: () => `blob:${calls.length}`,
      revokeObjectURL: () => undefined,
      scheduleIdle: (callback) => {
        idleCallbacks.push(callback);
        return idleCallbacks.length;
      },
      cancelIdle: () => undefined,
    },
  });
  const assets = [asset(1), asset(2), asset(3), asset(4)];

  registry.preloadAround(assets[1], assets);
  for (let index = 0; index < 10 && idleCallbacks.length === 0; index++) {
    await flush();
  }

  assert.deepEqual(calls.map((url) => url.split("/").at(-1)), ["diagram-02", "diagram-01", "diagram-03"]);
  assert.equal(idleCallbacks.length, 1);
  idleCallbacks.shift()?.();
  await flush();
  assert.deepEqual(calls.map((url) => url.split("/").at(-1)), [
    "diagram-02",
    "diagram-01",
    "diagram-03",
    "diagram-04",
  ]);

  registry.dispose();
});

test("401 or 403 invalidates the whole attempt registry and reports access denial", async () => {
  const revoked: Array<string> = [];
  let denied = 0;
  const registry = registryModule.createProtectedDiagramRegistry({
    apiBaseUrl: "",
    attemptId: 43,
    contentVersion: 12,
    onAccessDenied: () => denied++,
    dependencies: {
      eventTarget: null,
      fetchAsset: async (input) => new Response(
        String(input).endsWith("diagram-02") ? "denied" : new Blob(["image"]),
        { status: String(input).endsWith("diagram-02") ? 403 : 200 },
      ),
      createObjectURL: () => "blob:authorized",
      revokeObjectURL: (url) => revoked.push(url),
    },
  });

  const current = await registry.load(asset(1));
  const snapshots = [registry.getSnapshot()];
  registry.subscribe(() => snapshots.push(registry.getSnapshot()));
  await assert.rejects(() => registry.load(asset(2)), /access denied \(403\)/);
  assert.equal(denied, 1);
  assert.deepEqual(revoked, ["blob:authorized"]);
  assert.deepEqual(JSON.parse(snapshots[0] ?? "").slice(-3), [0, 43, 12]);
  assert.deepEqual(JSON.parse((snapshots[1] ?? "").replace("invalid:", "")).slice(-3), [1, 43, 12]);
  assert.notEqual(current.generation, registry.getSnapshot());
  await assert.rejects(() => registry.load(asset(1)), /registry is invalidated/);

  registry.dispose();
});

test("registry source contains no persistent cache surface", async () => {
  const source = await readFile(new URL("./lessonAssessmentProtectedImageRegistry.ts", import.meta.url), "utf8");

  assert.doesNotMatch(source, /localStorage|sessionStorage|indexedDB|serviceWorker|caches\.open/i);
  assert.match(source, /credentials: "include"/);
  assert.match(source, /cache: "no-store"/);
  assert.match(source, /MAX_PROTECTED_DIAGRAM_ENTRIES\s*=\s*9/);
  assert.match(source, /revokeObjectURL/);
});
