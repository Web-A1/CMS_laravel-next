import assert from "node:assert/strict";
import test from "node:test";

const modulePath = "../lib/siteIndexingPolicy.ts";
const robotsModule: typeof import("../lib/siteIndexingPolicy") = await import(modulePath);

test("fails closed by default and for false", () => {
  for (const value of [undefined, "", "false", "TRUE", "1"]) {
    assert.deepEqual(robotsModule.robotsPolicy(value), {
      rules: { userAgent: "*", disallow: "/" },
    });
  }
});

test("explicit public mode keeps protected routes disallowed", () => {
  const policy = robotsModule.robotsPolicy("true", "https://christmedschool.com");
  assert.deepEqual(policy, {
    rules: {
      userAgent: "*",
      allow: "/",
      disallow: ["/auth", "/account", "/lk", "/admin", "/backend"],
    },
  });
  assert.equal("sitemap" in policy, false);
});
