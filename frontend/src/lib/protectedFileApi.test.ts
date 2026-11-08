import assert from "node:assert/strict";
import test from "node:test";

const modulePath = "./protectedFileApi.ts";
const protectedFileApi: typeof import("./protectedFileApi") = await import(modulePath);

const delivery = {
  deliveryId: "123e4567-e89b-42d3-a456-426614174000",
  status: "ready",
  filename: "lecture.pdf",
  canRequestAccess: true,
} as const;
const route = { subjectSlug: "anatomy", courseSlug: "bones", lessonSlug: "intro" } as const;

test("guide and lesson owners have distinct encoded protected route paths", () => {
  assert.equal(protectedFileApi.protectedFileOwnerPath({ kind: "guide", guideSlug: "legacy-file-100" }), "/api/v1/learning/guides/legacy-file-100");
  assert.equal(protectedFileApi.protectedFileOwnerPath({ kind: "guide", guideSlug: "a/b" }), "/api/v1/learning/guides/a%2Fb");
  assert.equal(protectedFileApi.protectedFileOwnerPath({ kind: "lesson", ...route }), protectedFileApi.protectedFileOwnerPath(route));
  assert.notEqual(protectedFileApi.protectedFileOwnerPath({ kind: "guide", guideSlug: "intro" }), protectedFileApi.protectedFileOwnerPath(route));
});

test("guide delivery uses the same private no-store protocol and access acknowledgement", async () => {
  const restore = installBrowserGlobals([
    new Response(null, { status: 204 }),
    new Response(JSON.stringify({ data: delivery }), { status: 200 }),
    new Response(null, { status: 204 }),
    errorResponse("GUIDE_ACCESS_DENIED"),
  ]);
  const requests: Array<{ path: string; init?: RequestInit }> = [];
  const responseFetch = globalThis.fetch;
  globalThis.fetch = async (input, init) => {
    requests.push({ path: String(input), init });
    return responseFetch(input, init);
  };
  try {
    const guideRoute = { kind: "guide", guideSlug: "legacy-file-100" } as const;
    assert.equal((await protectedFileApi.requestProtectedFileDelivery("/backend", guideRoute, 7)).status, "delivery");
    assert.equal((await protectedFileApi.grantProtectedFileAccess("/backend", guideRoute, delivery)).status, "denied");
    assert.equal(requests[1]?.path, "/backend/api/v1/learning/guides/legacy-file-100/file-deliveries");
    assert.equal(requests[1]?.init?.cache, "no-store");
    assert.equal(requests[1]?.init?.credentials, "include");
    assert.deepEqual(JSON.parse(String(requests[1]?.init?.body)), { materialId: 7 });
    assert.equal(requests[3]?.path, `/backend/api/v1/learning/guides/legacy-file-100/file-deliveries/${delivery.deliveryId}/access-grants`);
    assert.deepEqual(JSON.parse(String(requests[3]?.init?.body)), { confidentialityAcknowledged: true });
  } finally { restore(); }
});
const trustedBrowserErrorCodes = [
  "TRUSTED_BROWSER_REQUIRED",
  "TRUSTED_BROWSER_SUPPORT_REQUIRED",
  "TRUSTED_BROWSER_RISK_LOCKED",
] as const;

function errorResponse(code: string, status = 403): Response {
  return new Response(JSON.stringify({ error: { code, message: "blocked" } }), {
    status,
    headers: { "content-type": "application/json" },
  });
}

function installBrowserGlobals(responses: ReadonlyArray<Response>): () => void {
  const originalFetch = globalThis.fetch;
  const originalWindow = Object.getOwnPropertyDescriptor(globalThis, "window");
  const originalDocument = Object.getOwnPropertyDescriptor(globalThis, "document");
  let responseIndex = 0;

  Object.defineProperty(globalThis, "window", {
    configurable: true,
    value: { location: { origin: "https://school.example" } },
  });
  Object.defineProperty(globalThis, "document", {
    configurable: true,
    value: { cookie: "" },
  });
  globalThis.fetch = async () => responses[responseIndex++] ?? new Response(null, { status: 500 });

  return () => {
    globalThis.fetch = originalFetch;
    if (originalWindow) Object.defineProperty(globalThis, "window", originalWindow);
    else delete (globalThis as { window?: unknown }).window;
    if (originalDocument) Object.defineProperty(globalThis, "document", originalDocument);
    else delete (globalThis as { document?: unknown }).document;
  };
}

test("denied and missing deliveries never become successful", async () => {
  for (const status of [401, 403, 404]) {
    const restore = installBrowserGlobals([
      new Response(null, { status: 204 }),
      new Response(JSON.stringify({ error: { code: "DENIED", message: "no" } }), {
        status,
        headers: { "content-type": "application/json" },
      }),
    ]);
    try {
      const result = await protectedFileApi.requestProtectedFileDelivery("/backend", route, 1);
      assert.notEqual(result.status, "delivery");
    } finally {
      restore();
    }
  }
});

for (const code of trustedBrowserErrorCodes) {
  test(`preserves ${code} across every protected PDF request`, async () => {
    const restore = installBrowserGlobals([
      new Response(null, { status: 204 }),
      errorResponse(code),
      new Response(null, { status: 204 }),
      errorResponse(code),
      errorResponse(code),
      new Response(null, { status: 204 }),
      errorResponse(code),
    ]);
    const expected = { status: "trusted_browser_recovery", code } as const;

    try {
      assert.deepEqual(await protectedFileApi.requestProtectedFileDelivery("/backend", route, 1), expected);
      assert.deepEqual(await protectedFileApi.prewarmProtectedFileDeliveries("/backend", route), expected);
      assert.deepEqual(
        await protectedFileApi.loadProtectedFileDelivery("/backend", route, delivery.deliveryId),
        expected,
      );
      assert.deepEqual(await protectedFileApi.grantProtectedFileAccess("/backend", route, delivery), expected);
    } finally {
      restore();
    }
  });
}

test("keeps authentication and unrelated forbidden errors as ordinary denials", async () => {
  for (const { code, status } of [
    { code: "TRUSTED_BROWSER_REQUIRED", status: 401 },
    { code: "DENIED", status: 403 },
  ] as const) {
    const restore = installBrowserGlobals([
      new Response(null, { status: 204 }),
      errorResponse(code, status),
    ]);

    try {
      assert.deepEqual(await protectedFileApi.requestProtectedFileDelivery("/backend", route, 1), {
        status: "denied",
      });
    } finally {
      restore();
    }
  }
});

test("rejects malformed delivery payloads", async () => {
  const restore = installBrowserGlobals([
    new Response(null, { status: 204 }),
    new Response(JSON.stringify({ data: { ...delivery, canRequestAccess: false } }), {
      status: 202,
      headers: { "content-type": "application/json" },
    }),
  ]);
  try {
    assert.deepEqual(await protectedFileApi.requestProtectedFileDelivery("/backend", route, 1), {
      status: "unavailable",
    });
  } finally {
    restore();
  }
});

test("prewarm returns reusable deliveries without download URLs", async () => {
  const pendingDelivery = {
    materialId: 11,
    deliveryId: "123e4567-e89b-42d3-a456-426614174001",
    status: "pending",
    filename: "lecture-notes.pdf",
    canRequestAccess: false,
  } as const;
  const readyDelivery = {
    materialId: 12,
    ...delivery,
  } as const;
  const restore = installBrowserGlobals([
    new Response(null, { status: 204 }),
    new Response(JSON.stringify({ data: { deliveries: [pendingDelivery, readyDelivery] } }), {
      status: 202,
      headers: { "content-type": "application/json" },
    }),
  ]);

  try {
    assert.deepEqual(await protectedFileApi.prewarmProtectedFileDeliveries("/backend", route), {
      status: "deliveries",
      data: [pendingDelivery, readyDelivery],
    });
  } finally {
    restore();
  }
});

test("prewarm rejects payloads that include temporary access data", async () => {
  const restore = installBrowserGlobals([
    new Response(null, { status: 204 }),
    new Response(
      JSON.stringify({
        data: {
          deliveries: [
            {
              materialId: 12,
              ...delivery,
              downloadUrl: "/files/lecture.pdf",
            },
          ],
        },
      }),
      {
        status: 200,
        headers: { "content-type": "application/json" },
      },
    ),
  ]);

  try {
    assert.deepEqual(await protectedFileApi.prewarmProtectedFileDeliveries("/backend", route), {
      status: "unavailable",
    });
  } finally {
    restore();
  }
});

test("expired and unsafe access URLs never start a download", async () => {
  const unsafePayloads = [
    { downloadUrl: "javascript:alert(1)", expiresAt: new Date(Date.now() + 60_000).toISOString(), filename: delivery.filename },
    { downloadUrl: "//evil.example/file.pdf", expiresAt: new Date(Date.now() + 60_000).toISOString(), filename: delivery.filename },
    { downloadUrl: "/files/lecture.pdf", expiresAt: new Date(Date.now() - 1_000).toISOString(), filename: delivery.filename },
    { downloadUrl: "/files/other.pdf", expiresAt: new Date(Date.now() + 60_000).toISOString(), filename: "other.pdf" },
  ];

  for (const data of unsafePayloads) {
    const restore = installBrowserGlobals([
      new Response(null, { status: 204 }),
      new Response(JSON.stringify({ data }), {
        status: 200,
        headers: { "content-type": "application/json" },
      }),
    ]);
    try {
      const result = await protectedFileApi.grantProtectedFileAccess("/backend", route, delivery);
      assert.notEqual(result.status, "ready");
      assert.equal("downloadUrl" in result, false);
    } finally {
      restore();
    }
  }
});

test("not-ready remains distinct from denied and success", async () => {
  const restore = installBrowserGlobals([
    new Response(null, { status: 204 }),
    new Response(JSON.stringify({ error: { code: "LEARNING_FILE_NOT_READY", message: "wait" } }), {
      status: 409,
      headers: { "content-type": "application/json" },
    }),
  ]);
  try {
    assert.deepEqual(await protectedFileApi.grantProtectedFileAccess("/backend", route, delivery), {
      status: "not_ready",
    });
  } finally {
    restore();
  }
});
