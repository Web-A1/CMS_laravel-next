export type PlaybackData = {
  readonly embedUrl: string;
  readonly expiresAt: string;
};

export type PlaybackContractResult =
  | { readonly status: "ready"; readonly data: PlaybackData }
  | { readonly status: "denied" }
  | { readonly status: "provider_not_configured" }
  | { readonly status: "unavailable" };

type JsonRecord = Record<string, unknown>;

const ACCESS_DENIED_CODE = "LEARNING_ACCESS_DENIED";
const PROVIDER_NOT_CONFIGURED_CODE = "LEARNING_VIDEO_PROVIDER_NOT_CONFIGURED";
const VIDEO_WATERMARK_CODE_PATTERN = /^CMS-[23456789ABCDEFGHJKMNPQRSTUVWXYZ]{4}-[23456789ABCDEFGHJKMNPQRSTUVWXYZ]{4}$/;

function isRecord(value: unknown): value is JsonRecord {
  return typeof value === "object" && value !== null && !Array.isArray(value);
}

function hasExactKeys(record: JsonRecord, required: ReadonlyArray<string>): boolean {
  const allowed = new Set(required);
  const keys = Object.keys(record);

  return required.every((key) => Object.hasOwn(record, key)) && keys.every((key) => allowed.has(key));
}

function parsePlaybackData(value: unknown, now: number): PlaybackData | null {
  if (!isRecord(value) || !hasExactKeys(value, ["data"]) || !isRecord(value.data)) {
    return null;
  }

  const data = value.data;
  if (
    !hasExactKeys(data, ["embedUrl", "expiresAt"]) ||
    typeof data.embedUrl !== "string" ||
    typeof data.expiresAt !== "string"
  ) {
    return null;
  }

  const expiresAt = Date.parse(data.expiresAt);
  if (!Number.isFinite(expiresAt) || expiresAt <= now) {
    return null;
  }

  try {
    const embedUrl = new URL(data.embedUrl);
    if (
      embedUrl.protocol !== "https:" ||
      embedUrl.hostname !== "kinescope.io" ||
      !embedUrl.pathname.startsWith("/embed/") ||
      embedUrl.username !== "" ||
      embedUrl.password !== "" ||
      embedUrl.hash !== "" ||
      !isProtectedKinescopeQuery(embedUrl.searchParams)
    ) {
      return null;
    }
  } catch {
    return null;
  }

  return { embedUrl: data.embedUrl, expiresAt: data.expiresAt };
}

function responseErrorCode(value: unknown): string | null {
  if (
    !isRecord(value) ||
    !hasExactKeys(value, ["error"]) ||
    !isRecord(value.error) ||
    !hasExactKeys(value.error, ["code", "message"]) ||
    typeof value.error.code !== "string" ||
    typeof value.error.message !== "string"
  ) {
    return null;
  }

  return value.error.code;
}

function isProtectedKinescopeQuery(searchParams: URLSearchParams): boolean {
  const queryKeys = Array.from(searchParams.keys());
  const uniqueQueryKeys = new Set(queryKeys);
  if (uniqueQueryKeys.size !== queryKeys.length) {
    return false;
  }

  for (const key of uniqueQueryKeys) {
    if (key !== "drmauthtoken" && key !== "watermark") {
      return false;
    }
  }

  const token = searchParams.get("drmauthtoken");
  if (token === null || token === "") {
    return false;
  }

  const watermark = searchParams.get("watermark");
  return watermark === null || VIDEO_WATERMARK_CODE_PATTERN.test(watermark);
}

export function parsePlaybackResponse(status: number, payload: unknown, now = Date.now()): PlaybackContractResult {
  if (status >= 200 && status < 300) {
    const data = parsePlaybackData(payload, now);
    return data ? { status: "ready", data } : { status: "unavailable" };
  }

  const errorCode = responseErrorCode(payload);
  if (status === 401 || (status === 403 && errorCode === ACCESS_DENIED_CODE)) {
    return { status: "denied" };
  }
  if (status === 503 && errorCode === PROVIDER_NOT_CONFIGURED_CODE) {
    return { status: "provider_not_configured" };
  }

  return { status: "unavailable" };
}

export function playbackErrorText(result: Exclude<PlaybackContractResult, { readonly status: "ready" }>): string {
  switch (result.status) {
    case "denied":
      return "Доступ к видео не подтверждён.";
    case "provider_not_configured":
      return "Видеосервис пока не настроен.";
    case "unavailable":
      return "Видео временно недоступно. Попробуйте ещё раз.";
  }
}
