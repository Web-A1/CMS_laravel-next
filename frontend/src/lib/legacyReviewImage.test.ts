import test from "node:test";
import assert from "node:assert/strict";
// @ts-expect-error Node's native TypeScript test runner requires the source extension.
import { serveLegacyReviewImage } from "./legacyReviewImage.ts";

test("legacy image serves ready S3 bytes for old Next Image URLs without forwarding credentials", async () => {
  const original = globalThis.fetch;
  try {
    globalThis.fetch = async (input, init) => {
      const url = new URL(String(input));
      if (url.origin === "https://media.school.test") {
        assert.equal(init?.headers, undefined);
        assert.equal(init?.redirect, "error");
        return new Response("ready-webp-bytes", { headers: { "Content-Type": "image/webp", "Set-Cookie": "discard=1" } });
      }
      assert.equal(url.origin, "https://api.school.test");
      assert.equal(url.searchParams.get("path"), "/images/reviews/telegram/photo@date.webp");
      assert.equal(init?.redirect, "manual");
      assert.equal(init?.headers, undefined);
      return new Response(null, { status: 301, headers: { Location: "https://media.school.test/ready.webp", "Set-Cookie": "private=discard" } });
    };
    const response = await serveLegacyReviewImage("/images/reviews/telegram/photo@date.webp", "https://api.school.test");
    assert.equal(response.status, 200);
    assert.equal(await response.text(), "ready-webp-bytes");
    assert.equal(response.headers.get("content-type"), "image/webp");
    assert.equal(response.headers.get("set-cookie"), null);
  } finally {
    globalThis.fetch = original;
  }
});

test("legacy image rejects traversal, unknown files and unsafe redirects without caching errors", async () => {
  const original = globalThis.fetch;
  try {
    globalThis.fetch = async () => { throw new Error("must not be fetched"); };
    assert.equal((await serveLegacyReviewImage("/images/reviews/../private.webp", "https://api.school.test")).status, 404);
    globalThis.fetch = async () => new Response(null, { status: 404 });
    const missing = await serveLegacyReviewImage("/storage/reviews/missing.webp", "https://api.school.test");
    assert.equal(missing.status, 404);
    assert.equal(missing.headers.get("cache-control"), "no-store");
    globalThis.fetch = async () => new Response(null, { status: 301, headers: { Location: "javascript:alert(1)" } });
    assert.equal((await serveLegacyReviewImage("/storage/reviews/test.webp", "https://api.school.test")).status, 502);
  } finally {
    globalThis.fetch = original;
  }
});

test("legacy HEAD has no body and refuses non-image storage responses", async () => {
  const original = globalThis.fetch;
  try {
    let type = "image/webp";
    globalThis.fetch = async (input, init) => {
      if (new URL(String(input)).pathname === "/review-images/legacy") {
        return new Response(null, { status: 301, headers: { Location: "https://media.school.test/ready.webp" } });
      }
      assert.equal(init?.method, "HEAD");
      return new Response(null, { headers: { "Content-Type": type } });
    };
    const response = await serveLegacyReviewImage("/storage/reviews/test.webp", "https://api.school.test", "HEAD");
    assert.equal(response.status, 200);
    assert.equal(await response.text(), "");
    type = "text/html";
    assert.equal((await serveLegacyReviewImage("/storage/reviews/test.webp", "https://api.school.test", "HEAD")).status, 502);
  } finally {
    globalThis.fetch = original;
  }
});
