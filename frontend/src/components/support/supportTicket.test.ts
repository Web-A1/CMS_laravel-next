import assert from "node:assert/strict";
import test from "node:test";
import { readFile } from "node:fs/promises";
import ts from "typescript";

const source = await readFile(new URL("./supportTicket.ts", import.meta.url), "utf8");
const output = ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.CommonJS } }).outputText;
const sandboxModule = { exports: {} as typeof import("./supportTicket") };
Function("module", "exports", output)(sandboxModule, sandboxModule.exports);
const { imageSelectionError, submitSupportTicket, supportAttemptKey, validateSupportDraft, SUPPORT_MAX_IMAGE_BYTES } = sandboxModule.exports;

const screenshot = new File(["fixture"], "screen.png", { type: "image/png" });
const draft = { email: " guest@example.test ", description: "Не открывается урок", images: [screenshot] };

test("voice-only permits empty text but requires email; mixed multipart preserves one voice plus five images", async () => {
  const voice = new File(["voice bytes"], "voice.m4a", { type: "audio/mp4" });
  assert.deepEqual(validateSupportDraft({ ...draft, description: "", voice }), {});
  assert.ok(validateSupportDraft({ ...draft, email: "", description: "", voice }).email);
  assert.ok(validateSupportDraft({ ...draft, description: "", images: [screenshot] }).description);
  const mixed = { ...draft, voice, images: Array.from({ length: 5 }, () => screenshot) };
  const key = supportAttemptKey(null);
  let calls = 0;
  const request: typeof fetch = async (_, init) => {
    calls++;
    assert.equal(new Headers(init?.headers).get("Idempotency-Key"), key);
    assert.ok(init?.body instanceof FormData);
    assert.equal(init.body.getAll("voice").length, 1);
    assert.equal(init.body.getAll("images[]").length, 5);
    assert.equal(init.body.has("duration"), false);
    assert.equal(init.body.has("type"), false);
    const attachment = init.body.get("voice") as File;
    assert.equal(attachment.type, "audio/mp4");
    assert.equal(await attachment.text(), "voice bytes");
    return Response.json({ error: { fields: { voice: ["Запись не принята"] } } }, { status: 422 });
  };
  const failed = await submitSupportTicket("/backend", mixed, key, "", request);
  assert.equal(failed.ok, false);
  if (!failed.ok) assert.deepEqual(failed.fields.voice, ["Запись не принята"]);
  await submitSupportTicket("/backend", mixed, key, "", request);
  assert.equal(calls, 2);
  assert.equal(mixed.voice, voice);
});

test("clipboard detection reads only after permission is granted", async () => {
  let reads = 0;
  let types = ["image/png"];
  const clipboard = { read: async (): Promise<ClipboardItem[]> => { reads++; return [{ types, presentationStyle: "unspecified", getType: async () => new Blob() }]; } };
  const permission = (state: PermissionState) => ({ query: async () => ({ state } as PermissionStatus) });
  const detect = sandboxModule.exports.clipboardHasImage;
  for (const state of ["prompt", "denied"] as const) assert.equal(await detect(clipboard, permission(state)), false);
  assert.equal(reads, 0);
  assert.equal(await detect(clipboard, permission("granted")), true);
  types = ["text/plain"];
  assert.equal(await detect(clipboard, permission("granted")), false);
  assert.equal(await detect(undefined, permission("granted")), false);
  assert.equal(await detect(clipboard, { query: async () => { throw new Error("unsupported"); } }), false);
  assert.equal(await detect({ read: async () => { throw new Error("denied"); } }, permission("granted")), false);
});

test("optional screenshots allow supported images and reject video, SVG, GIF and oversized files", () => {
  assert.equal(imageSelectionError(screenshot), null);
  assert.equal(imageSelectionError({ name: "phone.heic", type: "", size: SUPPORT_MAX_IMAGE_BYTES }), null);
  for (const type of ["video/mp4", "image/svg+xml", "image/gif"]) assert.ok(imageSelectionError({ name: "screen.png", type, size: 10 }));
  assert.ok(imageSelectionError({ name: "large.png", type: "image/png", size: SUPPORT_MAX_IMAGE_BYTES + 1 }));
  assert.deepEqual(validateSupportDraft({ ...draft, images: [] }), {});
  assert.deepEqual(Object.keys(validateSupportDraft({ email: "bad", description: " ", images: [] })), ["email", "description"]);
  assert.ok(validateSupportDraft({ ...draft, description: "x".repeat(20001) }).description);
});

test("unchanged retry retains key and edited attempt gets a new key", () => {
  const key = supportAttemptKey(null);
  assert.equal(supportAttemptKey(key), key);
  assert.notEqual(supportAttemptKey(null), key);
});

test("multipart sends only public fields, original image bytes and stable idempotency key", async () => {
  const key = supportAttemptKey(null);
  const request: typeof fetch = async (url, init) => {
    assert.equal(url, "/backend/api/v1/support-tickets");
    assert.equal(init?.cache, "no-store");
    assert.equal(init?.credentials, "omit");
    const headers = new Headers(init?.headers);
    assert.equal(headers.get("Idempotency-Key"), key);
    assert.equal(headers.has("Content-Type"), false);
    assert.ok(init?.body instanceof FormData);
    assert.deepEqual([...init.body.keys()], ["email", "description", "website", "images[]"]);
    assert.equal(init.body.get("email"), "guest@example.test");
    assert.equal(await (init.body.get("images[]") as File).text(), "fixture");
    return Response.json({ data: { number: "SUP-ABCDEFGHIJKLMNOPQRST", warnings: [{ index: 0, code: "IMAGE_REJECTED", message: "Файл не принят" }] } }, { status: 201 });
  };
  const result = await submitSupportTicket("/backend", draft, key, "", request);
  assert.equal(result.ok, true);
  if (result.ok) assert.equal(result.warnings[0]?.message, "Файл не принят");
});

test("validation, conflict, large body, throttling, server and network failures preserve caller draft for retry", async () => {
  for (const status of [422, 409, 413, 429, 500]) {
    const key = supportAttemptKey(null);
    let calls = 0;
    const request: typeof fetch = async (_, init) => {
      calls++;
      assert.equal(new Headers(init?.headers).get("Idempotency-Key"), key);
      return Response.json({ error: { fields: { email: ["Укажите email"] } }, retry_after: 120 }, { status });
    };
    const result = await submitSupportTicket("/backend", draft, key, "", request);
    assert.equal(result.ok, false);
    if (!result.ok) {
      assert.deepEqual(result.fields.email, ["Укажите email"]);
      if (status === 429) assert.match(result.message, /2 мин/);
    }
    await submitSupportTicket("/backend", draft, key, "", request);
    assert.equal(calls, 2);
    assert.equal(draft.description, "Не открывается урок");
    assert.equal(draft.images[0], screenshot);
  }
  const offline: typeof fetch = async () => { throw new TypeError("offline"); };
  assert.equal((await submitSupportTicket("/backend", draft, supportAttemptKey(null), "", offline)).ok, false);
  const invalidResponse: typeof fetch = async () => new Response("upstream unavailable", { status: 502 });
  assert.equal((await submitSupportTicket("/backend", draft, supportAttemptKey(null), "", invalidResponse)).ok, false);
});
