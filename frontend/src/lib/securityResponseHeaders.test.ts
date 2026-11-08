import assert from "node:assert/strict";
import test from "node:test";

const modulePath = "./securityResponseHeaders.ts";
const { securityResponseHeaders }: typeof import("./securityResponseHeaders") = await import(modulePath);

test("keeps the approved minimal security response headers exact", () => {
  assert.deepEqual(securityResponseHeaders, [
    {
      key: "Content-Security-Policy",
      value: "frame-ancestors 'self'; object-src 'none'; base-uri 'self'",
    },
    { key: "X-Frame-Options", value: "SAMEORIGIN" },
    { key: "X-Content-Type-Options", value: "nosniff" },
    { key: "Referrer-Policy", value: "strict-origin-when-cross-origin" },
    { key: "Permissions-Policy", value: "camera=(), microphone=(), geolocation=()" },
  ]);
});
