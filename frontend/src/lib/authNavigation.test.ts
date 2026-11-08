import assert from "node:assert/strict";
import test from "node:test";

const modulePath = "./authNavigation.ts";
const authNavigation: typeof import("./authNavigation") = await import(modulePath);

test("keeps valid root-relative auth destinations", () => {
  for (const path of ["/lk", "/lk?tab=courses", "/subjects/anatomiya"]) {
    assert.equal(authNavigation.normalizeAuthNextPath(path), path);
  }
});

test("falls back for cross-origin, scheme and control-character destinations", () => {
  const invalidPaths = [
    null,
    "",
    "//evil.example",
    "/\\evil.example",
    "http://evil.example",
    "https://evil.example",
    "javascript:alert(1)",
    "\u0000/lk",
    "\n/lk",
    " /lk",
    "/%5Cevil.example",
    "/%2e%2e//evil.example",
    "/%2e//evil.example",
    "/a/..//evil.example",
  ];

  for (const path of invalidPaths) {
    assert.equal(authNavigation.normalizeAuthNextPath(path), "/lk");
  }
});

test("rejects encoded backslashes after URLSearchParams decoding", () => {
  const params = new URLSearchParams("next=%2F%5Cevil.example");
  assert.equal(authNavigation.normalizeAuthNextPath(params.get("next")), "/lk");
});

test("builds an LK return path with user query parameters", () => {
  const params = new URLSearchParams("tab=materials&filter=pdf");

  assert.equal(
    authNavigation.buildLkAuthReturnPath("/lk/learning/anatomiya/osteologiya", params),
    "/lk/learning/anatomiya/osteologiya?tab=materials&filter=pdf",
  );
});

test("removes Next.js internal query parameters from an LK return path", () => {
  const params = new URLSearchParams("tab=materials&_rsc=4x92a&filter=pdf");

  assert.equal(
    authNavigation.buildLkAuthReturnPath("/lk/learning/anatomiya", params),
    "/lk/learning/anatomiya?tab=materials&filter=pdf",
  );
});

test("limits captured return paths to the personal account", () => {
  assert.equal(authNavigation.buildLkAuthReturnPath("/articles", new URLSearchParams()), "/lk");
  assert.equal(authNavigation.buildLkAuthReturnPath("//evil.example", new URLSearchParams()), "/lk");
});

test("captures only safe admin return paths and removes Next.js internals", () => {
  assert.equal(
    authNavigation.buildAdminAuthReturnPath(
      "/admin/reviews/12",
      new URLSearchParams("filter=draft&_rsc=4x92a"),
    ),
    "/admin/reviews/12?filter=draft",
  );
  assert.equal(authNavigation.normalizeAdminAuthNextPath("/lk"), "/admin");
  assert.equal(authNavigation.normalizeAdminAuthNextPath("//evil.example"), "/admin");
});

test("writes the matching protected return path into a fresh request header set", () => {
  const incomingHeaders = new Headers({
    [authNavigation.AUTH_RETURN_TO_HEADER]: "https://evil.example",
  });
  const requestHeaders = authNavigation.buildProtectedAuthRequestHeaders(
    incomingHeaders,
    "/admin/profile",
    new URLSearchParams("section=identity&_rsc=4x92a"),
  );

  assert.equal(
    requestHeaders.get(authNavigation.AUTH_RETURN_TO_HEADER),
    "/admin/profile?section=identity",
  );
  assert.equal(incomingHeaders.get(authNavigation.AUTH_RETURN_TO_HEADER), "https://evil.example");
});

test("overwrites an untrusted return-path request header", () => {
  const incomingHeaders = new Headers({
    [authNavigation.AUTH_RETURN_TO_HEADER]: "https://evil.example",
  });
  const requestHeaders = authNavigation.buildLkAuthRequestHeaders(
    incomingHeaders,
    "/lk/learning/anatomiya",
    new URLSearchParams("tab=materials&_rsc=4x92a"),
  );

  assert.equal(
    requestHeaders.get(authNavigation.AUTH_RETURN_TO_HEADER),
    "/lk/learning/anatomiya?tab=materials",
  );
  assert.equal(incomingHeaders.get(authNavigation.AUTH_RETURN_TO_HEADER), "https://evil.example");
});

test("builds encoded auth links from normalized internal destinations", () => {
  assert.equal(
    authNavigation.buildAuthPageHref("login", "/lk/learning/anatomiya?tab=materials"),
    "/auth?mode=login&next=%2Flk%2Flearning%2Fanatomiya%3Ftab%3Dmaterials",
  );
  assert.equal(
    authNavigation.buildAuthPageHref("verify-phone", "https://evil.example"),
    "/auth?mode=verify-phone&next=%2Flk",
  );
});

test("returns to the safe destination after required phone verification", () => {
  const nextPath = "/lk/learning/anatomiya/osteologiya?tab=materials";

  assert.equal(authNavigation.resolvePhoneVerificationReturnPath("verify-phone", nextPath), nextPath);
  assert.equal(authNavigation.resolvePhoneVerificationReturnPath("verify", nextPath), nextPath);
  assert.equal(authNavigation.resolvePhoneVerificationReturnPath("login", nextPath), null);
  assert.equal(authNavigation.resolvePhoneVerificationReturnPath("verify-phone", "https://evil.example"), "/lk");
});

test("routes staff login to the admin workspace and preserves protected admin destinations", () => {
  assert.equal(authNavigation.resolveLoginDestination("admin", "/lk"), "/admin/users");
  assert.equal(authNavigation.resolveLoginDestination("admin", "/lk/cart"), "/admin/users");
  assert.equal(authNavigation.resolveLoginDestination("admin", "/admin"), "/admin/users");
  assert.equal(authNavigation.resolveLoginDestination("admin", "/admin/reviews/12"), "/admin/reviews/12");
  assert.equal(authNavigation.resolveLoginDestination("lk", "/lk"), "/lk/learning");
  assert.equal(authNavigation.resolveLoginDestination("lk", "/account"), "/lk/learning");
  assert.equal(authNavigation.resolveLoginDestination("lk", "/lk?tab=courses"), "/lk?tab=courses");
  assert.equal(authNavigation.resolveLoginDestination("lk", "/lk/cart"), "/lk/cart");
});
