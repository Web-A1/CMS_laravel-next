import assert from "node:assert/strict";
import test from "node:test";

const modulePath = "./siteIndexingPolicy.ts";
const { isSiteIndexingEnabled, buildIndexingFlag, robotsPolicy }: typeof import("./siteIndexingPolicy") = await import(modulePath);

test("indexing requires an explicit flag and the exact HTTPS primary origin", () => {
  for (const flag of [undefined, "false", "TRUE", "1"]) assert.equal(isSiteIndexingEnabled(flag, "https://christmedschool.com"), false);
  for (const url of [undefined, "bad", "http://christmedschool.com", "http://localhost:3000", "https://staging.christmedschool.com", "https://christmedschool.com.evil.test", "https://christmedschool.com/path", "https://christmedschool.com?q=x", "https://demo@example.test"]) {
    assert.equal(isSiteIndexingEnabled("true", url), false);
    assert.deepEqual(robotsPolicy("true", url), { rules: { userAgent: "*", disallow: "/" } });
  }
  assert.equal(isSiteIndexingEnabled("true", "https://christmedschool.com"), true);
});

test("staging build freezes the closed flag even when both build settings request public indexing", () => {
  assert.equal(buildIndexingFlag("1", "true", "https://christmedschool.com"), "false");
  assert.equal(buildIndexingFlag(undefined, "true", "https://staging.christmedschool.com"), "false");
  assert.equal(buildIndexingFlag(undefined, undefined, undefined), "false");
  assert.equal(buildIndexingFlag(undefined, "true", "https://christmedschool.com"), "true");
});
