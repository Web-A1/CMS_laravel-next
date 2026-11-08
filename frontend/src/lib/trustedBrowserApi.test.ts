import assert from "node:assert/strict";
import test from "node:test";

import type { TrustedBrowserState } from "./trustedBrowserApi";

const modulePath = "./trustedBrowserApi.ts";
const trustedBrowserApi: typeof import("./trustedBrowserApi") = await import(modulePath);

const apiBaseUrl = "https://backend.example.test";
const xsrfCookie = "session=one; XSRF-TOKEN=csrf%20token";
const challengeId = "123e4567-e89b-42d3-a456-426614174000";

function jsonResponse(payload: unknown, status = 200): Response {
  return new Response(JSON.stringify(payload), {
    status,
    headers: { "content-type": "application/json" },
  });
}

test("parses every server-owned trusted-browser state", async () => {
  const states: ReadonlyArray<TrustedBrowserState> = [
    "trusted",
    "unknown_can_confirm",
    "unknown_support_required",
    "revoked_current",
    "risk_step_up_required",
  ];

  for (const state of states) {
    const result = await trustedBrowserApi.loadTrustedBrowserStatus(apiBaseUrl, {
      fetcher: async () => jsonResponse({ state }),
    });

    assert.deepEqual(result, { state });
  }
});

test("accepts exactly three stable, validated slot rows", async () => {
  const result = await trustedBrowserApi.loadTrustedBrowserSlots(apiBaseUrl, {
    fetcher: async () => jsonResponse({
      slots: [
        {
          slot: 1,
          status: "active",
          browser_label: "Chrome 126",
          os_label: "macOS",
          device_label: "Компьютер",
          is_current: true,
        },
        {
          slot: 2,
          status: "revoked_locked",
          browser_label: "Safari 18",
          os_label: "iOS",
          device_label: "Телефон",
          is_current: false,
        },
        {
          slot: 3,
          status: "empty",
          browser_label: null,
          os_label: null,
          device_label: null,
          is_current: false,
        },
      ],
    }),
  });

  assert.deepEqual(result.map((slot) => [slot.slotNumber, slot.status]), [
    [1, "active"],
    [2, "revoked_locked"],
    [3, "empty"],
  ]);
  assert.equal(result[0]?.browserLabel, "Chrome 126");
  assert.equal(result[0]?.isCurrent, true);
});

test("rejects malformed state and slot payloads instead of asserting types", async () => {
  await assert.rejects(
    trustedBrowserApi.loadTrustedBrowserStatus(apiBaseUrl, {
      fetcher: async () => jsonResponse({ state: "browser_probably_trusted" }),
    }),
    (error: unknown) =>
      error instanceof trustedBrowserApi.TrustedBrowserApiError &&
      error.code === "TRUSTED_BROWSER_INVALID_RESPONSE",
  );

  await assert.rejects(
    trustedBrowserApi.loadTrustedBrowserSlots(apiBaseUrl, {
      fetcher: async () => jsonResponse({
        slots: [
          {
            slot: 1,
            status: "active",
            browser_label: "Chrome\nInjected",
            os_label: "macOS",
            device_label: "Компьютер",
            is_current: true,
          },
        ],
      }),
    }),
    (error: unknown) =>
      error instanceof trustedBrowserApi.TrustedBrowserApiError &&
      error.code === "TRUSTED_BROWSER_INVALID_RESPONSE",
  );
});

test("starts challenges through Sanctum CSRF and sends the typed request body", async () => {
  const calls: Array<{ readonly url: string; readonly init?: RequestInit }> = [];
  const responses = [
    new Response(null, { status: 204 }),
    jsonResponse({
      challenge_id: challengeId,
      expires_at: "2026-08-14T15:00:00.000Z",
      retry_after: 60,
    }, 202),
  ];

  const challenge = await trustedBrowserApi.createTrustedBrowserChallenge(
    { purpose: "revoke", slotNumber: 2, password: "correct horse battery staple" },
    apiBaseUrl,
    {
      cookie: xsrfCookie,
      fetcher: async (input, init) => {
        calls.push({ url: String(input), init });
        return responses[calls.length - 1] ?? new Response(null, { status: 500 });
      },
    },
  );

  assert.equal(challenge.challengeId, challengeId);
  assert.equal(challenge.resendAfterSeconds, 60);
  assert.equal(calls[0]?.url, `${apiBaseUrl}/sanctum/csrf-cookie`);
  assert.equal(calls[0]?.init?.credentials, "include");
  assert.equal(calls[1]?.url, `${apiBaseUrl}/api/v1/auth/device-trust/challenges`);
  assert.equal(calls[1]?.init?.method, "POST");
  assert.equal(calls[1]?.init?.credentials, "include");
  assert.equal(new Headers(calls[1]?.init?.headers).get("X-XSRF-TOKEN"), "csrf token");
  assert.deepEqual(JSON.parse(String(calls[1]?.init?.body)), {
    purpose: "revoke",
    slot_number: 2,
    password: "correct horse battery staple",
  });
});

test("fails closed when the XSRF cookie is missing", async () => {
  await assert.rejects(
    trustedBrowserApi.createTrustedBrowserChallenge(
      { purpose: "enroll" },
      apiBaseUrl,
      {
        cookie: "",
        fetcher: async () => new Response(null, { status: 204 }),
      },
    ),
    (error: unknown) =>
      error instanceof trustedBrowserApi.TrustedBrowserApiError &&
      error.code === "TRUSTED_BROWSER_CSRF_TOKEN_MISSING",
  );
});

test("verifies OTP and directly revokes a slot with CSRF on every POST", async () => {
  const calls: Array<{ readonly url: string; readonly init?: RequestInit }> = [];
  const fetcher: typeof fetch = async (input, init) => {
    const url = String(input);
    calls.push({ url, init });
    if (url.endsWith("/sanctum/csrf-cookie")) {
      return new Response(null, { status: 204 });
    }
    if (url.endsWith("/verify")) {
      return jsonResponse({
        status: "verified",
        purpose: "revoke",
        slot: 2,
        slot_status: "revoked_locked",
      });
    }

    return jsonResponse({ status: "revoked", slot: 3, slot_status: "revoked_locked" });
  };

  await trustedBrowserApi.verifyTrustedBrowserChallenge(challengeId, "123456", apiBaseUrl, {
    cookie: xsrfCookie,
    fetcher,
  });
  await trustedBrowserApi.revokeTrustedBrowser(3, apiBaseUrl, {
    cookie: xsrfCookie,
    fetcher,
  });

  assert.deepEqual(calls.map((call) => call.url), [
    `${apiBaseUrl}/sanctum/csrf-cookie`,
    `${apiBaseUrl}/api/v1/auth/device-trust/challenges/${challengeId}/verify`,
    `${apiBaseUrl}/sanctum/csrf-cookie`,
    `${apiBaseUrl}/api/v1/auth/trusted-browsers/3/revoke`,
  ]);

  const mutationCalls = calls.filter((call) => call.init?.method === "POST");
  assert.equal(mutationCalls.length, 2);
  for (const call of mutationCalls) {
    assert.equal(call.init?.credentials, "include");
    assert.equal(new Headers(call.init?.headers).get("X-XSRF-TOKEN"), "csrf token");
  }
  assert.deepEqual(JSON.parse(String(mutationCalls[0]?.init?.body)), { code: "123456" });
  assert.deepEqual(JSON.parse(String(mutationCalls[1]?.init?.body)), {});
});

test("preserves stable backend error codes", async () => {
  await assert.rejects(
    trustedBrowserApi.loadTrustedBrowserStatus(apiBaseUrl, {
      fetcher: async () => jsonResponse({
        error: {
          code: "DEVICE_LIMIT_SUPPORT_REQUIRED",
          message: "Обратитесь в поддержку.",
        },
      }, 409),
    }),
    (error: unknown) =>
      error instanceof trustedBrowserApi.TrustedBrowserApiError &&
      error.code === "DEVICE_LIMIT_SUPPORT_REQUIRED" &&
      error.status === 409,
  );
});

test("maps only the three trusted-browser protected denial codes", () => {
  for (const code of [
    "TRUSTED_BROWSER_REQUIRED",
    "TRUSTED_BROWSER_SUPPORT_REQUIRED",
    "TRUSTED_BROWSER_RISK_LOCKED",
  ]) {
    assert.equal(
      trustedBrowserApi.trustedBrowserProtectedErrorCode({ error: { code, message: "denied" } }),
      code,
    );
  }

  assert.equal(
    trustedBrowserApi.trustedBrowserProtectedErrorCode({
      error: { code: "LEARNING_ACCESS_DENIED", message: "denied" },
    }),
    null,
  );
  assert.equal(trustedBrowserApi.trustedBrowserProtectedErrorCode({ error: { code: 403 } }), null);
});
