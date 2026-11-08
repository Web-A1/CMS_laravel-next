import assert from "node:assert/strict";
import test from "node:test";

const modulePath = "./kinescopePlaybackContract.ts";
const contract: typeof import("./kinescopePlaybackContract") = await import(modulePath);

const now = Date.parse("2026-07-30T12:00:00.000Z");
const token = "sensitive-playback-token";
const readyPayload = {
  data: {
    embedUrl: `https://kinescope.io/embed/video-1?drmauthtoken=${token}`,
    expiresAt: "2026-07-30T12:05:00.000Z",
  },
} as const;
const readyPayloadWithVideoWatermark = {
  data: {
    ...readyPayload.data,
    embedUrl: `https://kinescope.io/embed/video-1?drmauthtoken=${token}&watermark=CMS-2345-ABCD`,
  },
} as const;

test("accepts the exact protected playback payload", () => {
  assert.deepEqual(contract.parsePlaybackResponse(200, readyPayload, now), {
    status: "ready",
    data: readyPayload.data,
  });
});

test("accepts the protected playback payload with a video watermark", () => {
  assert.deepEqual(contract.parsePlaybackResponse(200, readyPayloadWithVideoWatermark, now), {
    status: "ready",
    data: readyPayloadWithVideoWatermark.data,
  });
});

test("classifies authorization and provider configuration responses", () => {
  assert.deepEqual(contract.parsePlaybackResponse(401, null, now), { status: "denied" });
  assert.deepEqual(
    contract.parsePlaybackResponse(
      403,
      { error: { code: "LEARNING_ACCESS_DENIED", message: "denied" } },
      now,
    ),
    { status: "denied" },
  );
  assert.deepEqual(
    contract.parsePlaybackResponse(
      503,
      { error: { code: "LEARNING_VIDEO_PROVIDER_NOT_CONFIGURED", message: "unavailable" } },
      now,
    ),
    { status: "provider_not_configured" },
  );
  for (const status of [403, 404, 500, 502]) {
    assert.deepEqual(contract.parsePlaybackResponse(status, null, now), { status: "unavailable" });
  }
});

test("rejects malformed, expired and unsafe playback payloads", () => {
  const invalidPayloads: ReadonlyArray<unknown> = [
    null,
    { data: null },
    { data: { embedUrl: readyPayload.data.embedUrl } },
    { data: { ...readyPayload.data, expiresAt: "2026-07-30T11:59:59.000Z" } },
    { data: { ...readyPayload.data, embedUrl: "javascript:alert(1)" } },
    { data: { ...readyPayload.data, embedUrl: `https://evil.example/embed/video?drmauthtoken=${token}` } },
    { data: { ...readyPayload.data, embedUrl: "https://kinescope.io/embed/video" } },
    { data: { ...readyPayload.data, embedUrl: `https://kinescope.io/embed/video?drmauthtoken=${token}&foo=bar` } },
    {
      data: {
        ...readyPayload.data,
        embedUrl: `https://kinescope.io/embed/video?drmauthtoken=${token}&drmauthtoken=${token}`,
      },
    },
    { data: { ...readyPayload.data, embedUrl: `https://kinescope.io/embed/video?drmauthtoken=${token}&watermark=bad` } },
    {
      data: {
        ...readyPayload.data,
        embedUrl: `https://kinescope.io/embed/video?drmauthtoken=${token}&watermark=CMS-2345-ABCD&watermark=CMS-2345-ABCD`,
      },
    },
    { data: { ...readyPayload.data, locator: "private-video" } },
  ];

  for (const payload of invalidPayloads) {
    assert.deepEqual(contract.parsePlaybackResponse(200, payload, now), { status: "unavailable" });
  }
});

test("negative states create no iframe URL and do not expose tokens in error text", () => {
  const negativeResults = [
    contract.parsePlaybackResponse(403, { error: { code: "PROVIDER_FORBIDDEN", message: token } }, now),
    contract.parsePlaybackResponse(500, readyPayload, now),
    contract.parsePlaybackResponse(200, { data: { embedUrl: token, expiresAt: "invalid" } }, now),
  ];

  for (const result of negativeResults) {
    assert.notEqual(result.status, "ready");
    if (result.status !== "ready") {
      assert.doesNotMatch(contract.playbackErrorText(result), new RegExp(token));
      assert.equal("data" in result, false);
    }
  }
});
