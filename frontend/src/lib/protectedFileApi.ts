import type { TrustedBrowserProtectedErrorCode } from "./trustedBrowserApi";

export type ProtectedFileDeliveryStatus = "pending" | "processing" | "ready" | "failed";

export type ProtectedFileDelivery = {
  readonly deliveryId: string;
  readonly status: ProtectedFileDeliveryStatus;
  readonly filename: string;
  readonly canRequestAccess: boolean;
};

export type ProtectedFileWarmupDelivery = ProtectedFileDelivery & {
  readonly materialId: number;
};

export type ProtectedFileDeliveryResult =
  | { readonly status: "delivery"; readonly data: ProtectedFileDelivery }
  | { readonly status: "denied" }
  | { readonly status: "trusted_browser_recovery"; readonly code: TrustedBrowserProtectedErrorCode }
  | { readonly status: "aborted" }
  | { readonly status: "unavailable" };

export type ProtectedFileWarmupResult =
  | { readonly status: "deliveries"; readonly data: ReadonlyArray<ProtectedFileWarmupDelivery> }
  | { readonly status: "denied" }
  | { readonly status: "trusted_browser_recovery"; readonly code: TrustedBrowserProtectedErrorCode }
  | { readonly status: "aborted" }
  | { readonly status: "unavailable" };

export type ProtectedFileAccessResult =
  | { readonly status: "ready"; readonly downloadUrl: string }
  | { readonly status: "not_ready" }
  | { readonly status: "denied" }
  | { readonly status: "trusted_browser_recovery"; readonly code: TrustedBrowserProtectedErrorCode }
  | { readonly status: "aborted" }
  | { readonly status: "unavailable" };

type JsonRecord = Record<string, unknown>;

type LearningFileRoute = {
  readonly courseSlug: string;
  readonly lessonSlug: string;
  readonly subjectSlug: string;
};

export type ProtectedFileRoute = ({ readonly kind: "lesson" } & LearningFileRoute) | { readonly kind: "guide"; readonly guideSlug: string };
type CompatibleFileRoute = LearningFileRoute | ProtectedFileRoute;

const DELIVERY_ID_PATTERN = /^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/;
const MAX_ACCESS_TTL_WITH_CLOCK_SKEW_MS = 330_000;

function isRecord(value: unknown): value is JsonRecord {
  return typeof value === "object" && value !== null && !Array.isArray(value);
}

function hasExactKeys(record: JsonRecord, required: ReadonlyArray<string>): boolean {
  const allowed = new Set(required);
  const keys = Object.keys(record);

  return required.every((key) => Object.hasOwn(record, key)) && keys.every((key) => allowed.has(key));
}

function isSafePdfFilename(value: unknown): value is string {
  return (
    typeof value === "string" &&
    value.length > 0 &&
    value.length <= 255 &&
    !value.includes("/") &&
    !value.includes("\\") &&
    !/[\u0000-\u001F\u007F]/.test(value) &&
    value.toLowerCase().endsWith(".pdf")
  );
}

function isPositiveInteger(value: unknown): value is number {
  return typeof value === "number" && Number.isInteger(value) && value >= 1;
}

function isDeliveryPayload(value: unknown): value is { readonly data: ProtectedFileDelivery } {
  if (!isRecord(value) || !hasExactKeys(value, ["data"]) || !isRecord(value.data)) {
    return false;
  }

  const data = value.data;
  if (
    !hasExactKeys(data, ["deliveryId", "status", "filename", "canRequestAccess"]) ||
    typeof data.deliveryId !== "string" ||
    !DELIVERY_ID_PATTERN.test(data.deliveryId) ||
    (data.status !== "pending" && data.status !== "processing" && data.status !== "ready" && data.status !== "failed") ||
    !isSafePdfFilename(data.filename) ||
    typeof data.canRequestAccess !== "boolean"
  ) {
    return false;
  }

  return data.canRequestAccess === (data.status === "ready");
}

function isWarmupDeliveryPayload(value: unknown): value is { readonly data: { readonly deliveries: ReadonlyArray<ProtectedFileWarmupDelivery> } } {
  if (
    !isRecord(value) ||
    !hasExactKeys(value, ["data"]) ||
    !isRecord(value.data) ||
    !hasExactKeys(value.data, ["deliveries"]) ||
    !Array.isArray(value.data.deliveries)
  ) {
    return false;
  }

  return value.data.deliveries.every((delivery) => {
    if (!isRecord(delivery)) {
      return false;
    }

    return (
      hasExactKeys(delivery, ["materialId", "deliveryId", "status", "filename", "canRequestAccess"]) &&
      isPositiveInteger(delivery.materialId) &&
      typeof delivery.deliveryId === "string" &&
      DELIVERY_ID_PATTERN.test(delivery.deliveryId) &&
      (delivery.status === "pending" ||
        delivery.status === "processing" ||
        delivery.status === "ready" ||
        delivery.status === "failed") &&
      isSafePdfFilename(delivery.filename) &&
      typeof delivery.canRequestAccess === "boolean" &&
      delivery.canRequestAccess === (delivery.status === "ready")
    );
  });
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

function trustedBrowserProtectedErrorCode(value: unknown): TrustedBrowserProtectedErrorCode | null {
  const code = responseErrorCode(value);

  if (
    code === "TRUSTED_BROWSER_REQUIRED" ||
    code === "TRUSTED_BROWSER_SUPPORT_REQUIRED" ||
    code === "TRUSTED_BROWSER_RISK_LOCKED"
  ) {
    return code;
  }

  return null;
}

function normalizedApiBaseUrl(apiBaseUrl: string): string | null {
  if (apiBaseUrl.startsWith("/") && !apiBaseUrl.startsWith("//")) {
    if (apiBaseUrl.includes("?") || apiBaseUrl.includes("#")) {
      return null;
    }

    if (apiBaseUrl === "/") {
      return "";
    }

    const normalizedRelativeUrl = apiBaseUrl.replace(/\/+$/, "");
    const parsedRelativeUrl = new URL(normalizedRelativeUrl, "https://api-base.invalid");

    return parsedRelativeUrl.pathname === normalizedRelativeUrl ? normalizedRelativeUrl : null;
  }

  try {
    const parsed = new URL(apiBaseUrl);
    if (
      (parsed.protocol !== "http:" && parsed.protocol !== "https:") ||
      parsed.username !== "" ||
      parsed.password !== "" ||
      parsed.search !== "" ||
      parsed.hash !== "" ||
      (parsed.protocol === "http:" && process.env.NODE_ENV === "production")
    ) {
      return null;
    }

    return parsed.href.replace(/\/+$/, "");
  } catch {
    return null;
  }
}

export function protectedFileOwnerPath(route: CompatibleFileRoute): string {
  if ("kind" in route && route.kind === "guide") return `/api/v1/learning/guides/${encodeURIComponent(route.guideSlug)}`;
  return [
    "/api/v1/learning/subjects",
    encodeURIComponent(route.subjectSlug),
    "courses",
    encodeURIComponent(route.courseSlug),
    "lessons",
    encodeURIComponent(route.lessonSlug),
  ].join("/");
}

function readXsrfToken(): string {
  const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
  if (!match?.[1]) {
    return "";
  }

  try {
    return decodeURIComponent(match[1]);
  } catch {
    return "";
  }
}

async function ensureCsrfCookie(apiBaseUrl: string, signal?: AbortSignal): Promise<void> {
  await fetch(`${apiBaseUrl}/sanctum/csrf-cookie`, {
    method: "GET",
    credentials: "include",
    headers: { Accept: "application/json" },
    signal,
  });
}

async function requestJson(
  apiBaseUrl: string,
  path: string,
  options: {
    readonly body?: JsonRecord;
    readonly method: "GET" | "POST";
    readonly signal?: AbortSignal;
  },
): Promise<{ readonly response: Response; readonly payload: unknown }> {
  const headers: Record<string, string> = { Accept: "application/json" };
  let body: string | undefined;

  if (options.method === "POST") {
    await ensureCsrfCookie(apiBaseUrl, options.signal);
    headers["Content-Type"] = "application/json";
    const xsrfToken = readXsrfToken();
    if (xsrfToken !== "") {
      headers["X-XSRF-TOKEN"] = xsrfToken;
    }
    body = JSON.stringify(options.body ?? {});
  }

  const response = await fetch(`${apiBaseUrl}${path}`, {
    cache: "no-store",
    method: options.method,
    credentials: "include",
    headers,
    body,
    signal: options.signal,
  });

  return {
    response,
    payload: await response.json().catch(() => null),
  };
}

function deliveryResult(
  response: Response,
  payload: unknown,
  successStatuses: ReadonlyArray<number>,
): Exclude<ProtectedFileDeliveryResult, { readonly status: "aborted" }> {
  if (successStatuses.includes(response.status) && isDeliveryPayload(payload)) {
    return { status: "delivery", data: payload.data };
  }
  if (response.status === 401 || response.status === 403) {
    const trustedBrowserCode = response.status === 403 ? trustedBrowserProtectedErrorCode(payload) : null;
    if (trustedBrowserCode !== null) {
      return { status: "trusted_browser_recovery", code: trustedBrowserCode };
    }

    return { status: "denied" };
  }

  return { status: "unavailable" };
}

function validateDownloadUrl(rawUrl: string, apiBaseUrl: string): string | null {
  if (rawUrl === "" || rawUrl.trim() !== rawUrl || rawUrl.startsWith("//")) {
    return null;
  }

  try {
    const backendUrl = new URL(apiBaseUrl, window.location.origin);
    const isRelative = rawUrl.startsWith("/");
    const usesBrowserProxyPrefix = apiBaseUrl.startsWith("/");
    const browserRelativeUrl = usesBrowserProxyPrefix ? `${apiBaseUrl}${rawUrl}` : rawUrl;
    const downloadUrl = isRelative ? new URL(browserRelativeUrl, backendUrl.origin) : new URL(rawUrl);

    if (
      downloadUrl.hostname === "" ||
      downloadUrl.username !== "" ||
      downloadUrl.password !== "" ||
      downloadUrl.hash !== ""
    ) {
      return null;
    }

    if (isRelative) {
      if (downloadUrl.origin !== backendUrl.origin) {
        return null;
      }
      if (
        downloadUrl.protocol !== "https:" &&
        !(downloadUrl.protocol === "http:" && process.env.NODE_ENV !== "production")
      ) {
        return null;
      }

      const backendPathPrefix = backendUrl.pathname.replace(/\/+$/, "");
      if (
        usesBrowserProxyPrefix &&
        backendPathPrefix !== "" &&
        downloadUrl.pathname !== backendPathPrefix &&
        !downloadUrl.pathname.startsWith(`${backendPathPrefix}/`)
      ) {
        return null;
      }

      return downloadUrl.href;
    }
    if (downloadUrl.protocol === "https:") {
      return downloadUrl.href;
    }
    if (
      downloadUrl.protocol === "http:" &&
      process.env.NODE_ENV !== "production" &&
      downloadUrl.origin === backendUrl.origin
    ) {
      return downloadUrl.href;
    }

    return null;
  } catch {
    return null;
  }
}

function validatedAccessUrl(
  value: unknown,
  apiBaseUrl: string,
  expectedFilename: string,
  now: number,
): string | null {
  if (!isRecord(value) || !hasExactKeys(value, ["data"]) || !isRecord(value.data)) {
    return null;
  }

  const data = value.data;
  if (
    !hasExactKeys(data, ["downloadUrl", "expiresAt", "filename"]) ||
    typeof data.downloadUrl !== "string" ||
    typeof data.expiresAt !== "string" ||
    !isSafePdfFilename(data.filename) ||
    data.filename !== expectedFilename
  ) {
    return null;
  }

  const expiresAt = Date.parse(data.expiresAt);
  if (!Number.isFinite(expiresAt) || expiresAt <= now || expiresAt > now + MAX_ACCESS_TTL_WITH_CLOCK_SKEW_MS) {
    return null;
  }

  return validateDownloadUrl(data.downloadUrl, apiBaseUrl);
}

export async function requestProtectedFileDelivery(
  apiBaseUrl: string,
  route: CompatibleFileRoute,
  materialId: number,
  signal?: AbortSignal,
): Promise<ProtectedFileDeliveryResult> {
  const normalizedBaseUrl = normalizedApiBaseUrl(apiBaseUrl);
  if (normalizedBaseUrl === null || !Number.isInteger(materialId) || materialId < 1) {
    return { status: "unavailable" };
  }

  try {
    const { response, payload } = await requestJson(
      normalizedBaseUrl,
      `${protectedFileOwnerPath(route)}/file-deliveries`,
      { method: "POST", body: { materialId }, signal },
    );

    return deliveryResult(response, payload, [200, 202]);
  } catch {
    return signal?.aborted ? { status: "aborted" } : { status: "unavailable" };
  }
}

export async function prewarmProtectedFileDeliveries(
  apiBaseUrl: string,
  route: CompatibleFileRoute,
  signal?: AbortSignal,
): Promise<ProtectedFileWarmupResult> {
  const normalizedBaseUrl = normalizedApiBaseUrl(apiBaseUrl);
  if (normalizedBaseUrl === null) {
    return { status: "unavailable" };
  }

  try {
    const { response, payload } = await requestJson(
      normalizedBaseUrl,
      `${protectedFileOwnerPath(route)}/file-deliveries/prewarm`,
      { method: "POST", body: {}, signal },
    );

    if ((response.status === 200 || response.status === 202) && isWarmupDeliveryPayload(payload)) {
      return { status: "deliveries", data: payload.data.deliveries };
    }
    if (response.status === 401 || response.status === 403) {
      const trustedBrowserCode = response.status === 403 ? trustedBrowserProtectedErrorCode(payload) : null;
      if (trustedBrowserCode !== null) {
        return { status: "trusted_browser_recovery", code: trustedBrowserCode };
      }

      return { status: "denied" };
    }

    return { status: "unavailable" };
  } catch {
    return signal?.aborted ? { status: "aborted" } : { status: "unavailable" };
  }
}

export async function loadProtectedFileDelivery(
  apiBaseUrl: string,
  route: CompatibleFileRoute,
  deliveryId: string,
  signal?: AbortSignal,
): Promise<ProtectedFileDeliveryResult> {
  const normalizedBaseUrl = normalizedApiBaseUrl(apiBaseUrl);
  if (normalizedBaseUrl === null || !DELIVERY_ID_PATTERN.test(deliveryId)) {
    return { status: "unavailable" };
  }

  try {
    const { response, payload } = await requestJson(
      normalizedBaseUrl,
      `${protectedFileOwnerPath(route)}/file-deliveries/${encodeURIComponent(deliveryId)}`,
      { method: "GET", signal },
    );

    return deliveryResult(response, payload, [200]);
  } catch {
    return signal?.aborted ? { status: "aborted" } : { status: "unavailable" };
  }
}

export async function grantProtectedFileAccess(
  apiBaseUrl: string,
  route: CompatibleFileRoute,
  delivery: ProtectedFileDelivery,
  signal?: AbortSignal,
): Promise<ProtectedFileAccessResult> {
  const normalizedBaseUrl = normalizedApiBaseUrl(apiBaseUrl);
  if (normalizedBaseUrl === null || !DELIVERY_ID_PATTERN.test(delivery.deliveryId) || delivery.status !== "ready") {
    return { status: "unavailable" };
  }

  try {
    const { response, payload } = await requestJson(
      normalizedBaseUrl,
      `${protectedFileOwnerPath(route)}/file-deliveries/${encodeURIComponent(delivery.deliveryId)}/access-grants`,
      { method: "POST", body: { confidentialityAcknowledged: true }, signal },
    );

    if (response.status === 200) {
      const downloadUrl = validatedAccessUrl(payload, normalizedBaseUrl, delivery.filename, Date.now());

      return downloadUrl === null ? { status: "unavailable" } : { status: "ready", downloadUrl };
    }
    if (response.status === 401 || response.status === 403) {
      const trustedBrowserCode = response.status === 403 ? trustedBrowserProtectedErrorCode(payload) : null;
      if (trustedBrowserCode !== null) {
        return { status: "trusted_browser_recovery", code: trustedBrowserCode };
      }

      return { status: "denied" };
    }
    if (response.status === 409 && ["LEARNING_FILE_NOT_READY", "GUIDE_FILE_NOT_READY"].includes(responseErrorCode(payload) ?? "")) {
      return { status: "not_ready" };
    }

    return { status: "unavailable" };
  } catch {
    return signal?.aborted ? { status: "aborted" } : { status: "unavailable" };
  }
}
