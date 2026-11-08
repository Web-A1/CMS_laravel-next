export type TrustedBrowserState =
  | "trusted"
  | "unknown_can_confirm"
  | "unknown_support_required"
  | "revoked_current"
  | "risk_step_up_required";

export type TrustedBrowserSlotStatus = "empty" | "active" | "revoked_locked" | "risk_locked" | "released";

export type TrustedBrowserSlotNumber = 1 | 2 | 3;

export type TrustedBrowserStatus = {
  readonly state: TrustedBrowserState;
};

export type TrustedBrowserSlot = {
  readonly slotNumber: TrustedBrowserSlotNumber;
  readonly status: TrustedBrowserSlotStatus;
  readonly browserLabel: string | null;
  readonly osLabel: string | null;
  readonly deviceLabel: string | null;
  readonly isCurrent: boolean;
};

export type TrustedBrowserChallengePurpose = "enroll" | "revoke";

export type TrustedBrowserChallenge = {
  readonly challengeId: string;
  readonly expiresAt: string;
  readonly resendAfterSeconds: number;
};

export type TrustedBrowserProtectedErrorCode =
  | "TRUSTED_BROWSER_REQUIRED"
  | "TRUSTED_BROWSER_SUPPORT_REQUIRED"
  | "TRUSTED_BROWSER_RISK_LOCKED";

export type TrustedBrowserRequestOptions = {
  readonly cookie?: string;
  readonly fetcher?: typeof fetch;
  readonly signal?: AbortSignal;
};

export type CreateTrustedBrowserChallengeInput =
  | { readonly purpose: "enroll" }
  | {
      readonly purpose: "revoke";
      readonly slotNumber: TrustedBrowserSlotNumber;
      readonly password: string;
    };

type JsonRecord = Record<string, unknown>;

const DEFAULT_RESEND_AFTER_SECONDS = 60;
const MAX_RESEND_AFTER_SECONDS = 3_600;
const OPAQUE_ID_PATTERN = /^[A-Za-z0-9_-]{8,128}$/;
const ERROR_CODE_PATTERN = /^[A-Z][A-Z0-9_]{2,79}$/;

export class TrustedBrowserApiError extends Error {
  readonly code: string;
  readonly status: number | null;

  constructor(message: string, code: string, status: number | null = null) {
    super(message);
    this.name = "TrustedBrowserApiError";
    this.code = code;
    this.status = status;
  }
}

export function trustedBrowserApiBaseUrl(): string {
  return (process.env.NEXT_PUBLIC_API_BASE_URL ?? "/backend").replace(/\/+$/, "");
}

function isRecord(value: unknown): value is JsonRecord {
  return typeof value === "object" && value !== null && !Array.isArray(value);
}

function responseDataRecord(value: unknown): JsonRecord | null {
  if (!isRecord(value)) {
    return null;
  }

  return isRecord(value.data) ? value.data : value;
}

function isTrustedBrowserState(value: unknown): value is TrustedBrowserState {
  return (
    value === "trusted" ||
    value === "unknown_can_confirm" ||
    value === "unknown_support_required" ||
    value === "revoked_current" ||
    value === "risk_step_up_required"
  );
}

function isTrustedBrowserSlotStatus(value: unknown): value is TrustedBrowserSlotStatus {
  return (
    value === "empty" ||
    value === "active" ||
    value === "revoked_locked" ||
    value === "risk_locked" ||
    value === "released"
  );
}

function isTrustedBrowserSlotNumber(value: unknown): value is TrustedBrowserSlotNumber {
  return value === 1 || value === 2 || value === 3;
}

function isSafeDisplayLabel(value: unknown): value is string | null {
  return (
    value === null ||
    (typeof value === "string" &&
      value.length > 0 &&
      value.length <= 80 &&
      value.trim() === value &&
      !/[\u0000-\u001F\u007F]/.test(value))
  );
}

function parseStatusPayload(value: unknown): TrustedBrowserStatus | null {
  const data = responseDataRecord(value);
  if (!data || !isTrustedBrowserState(data.state)) {
    return null;
  }

  return { state: data.state };
}

function parseSlot(value: unknown): TrustedBrowserSlot | null {
  if (
    !isRecord(value) ||
    !isTrustedBrowserSlotNumber(value.slot) ||
    !isTrustedBrowserSlotStatus(value.status) ||
    !isSafeDisplayLabel(value.browser_label) ||
    !isSafeDisplayLabel(value.os_label) ||
    !isSafeDisplayLabel(value.device_label) ||
    typeof value.is_current !== "boolean"
  ) {
    return null;
  }

  return {
    slotNumber: value.slot,
    status: value.status,
    browserLabel: value.browser_label,
    osLabel: value.os_label,
    deviceLabel: value.device_label,
    isCurrent: value.is_current,
  };
}

function isChallengeVerificationPayload(value: unknown): boolean {
  const data = responseDataRecord(value);
  if (
    !data ||
    data.status !== "verified" ||
    !isTrustedBrowserSlotNumber(data.slot) ||
    (data.purpose !== "enroll" && data.purpose !== "revoke")
  ) {
    return false;
  }

  return data.purpose === "enroll"
    ? data.state === "trusted"
    : data.slot_status === "revoked_locked";
}

function isRevokePayload(value: unknown): boolean {
  const data = responseDataRecord(value);

  return Boolean(
    data &&
    data.status === "revoked" &&
    isTrustedBrowserSlotNumber(data.slot) &&
    data.slot_status === "revoked_locked",
  );
}

function parseSlotsPayload(value: unknown): ReadonlyArray<TrustedBrowserSlot> | null {
  const data = responseDataRecord(value);
  if (!data || !Array.isArray(data.slots) || data.slots.length !== 3) {
    return null;
  }

  const slots: Array<TrustedBrowserSlot> = [];
  for (const rawSlot of data.slots) {
    const slot = parseSlot(rawSlot);
    if (!slot) {
      return null;
    }

    slots.push(slot);
  }

  if (!slots.every((slot, index) => slot.slotNumber === index + 1)) {
    return null;
  }

  return slots;
}

function parseChallengePayload(value: unknown): TrustedBrowserChallenge | null {
  const data = responseDataRecord(value);
  if (
    !data ||
    typeof data.challenge_id !== "string" ||
    !OPAQUE_ID_PATTERN.test(data.challenge_id) ||
    typeof data.expires_at !== "string" ||
    !Number.isFinite(Date.parse(data.expires_at))
  ) {
    return null;
  }

  const resendAfterSeconds = data.retry_after ?? DEFAULT_RESEND_AFTER_SECONDS;
  if (
    typeof resendAfterSeconds !== "number" ||
    !Number.isInteger(resendAfterSeconds) ||
    resendAfterSeconds < 0 ||
    resendAfterSeconds > MAX_RESEND_AFTER_SECONDS
  ) {
    return null;
  }

  return {
    challengeId: data.challenge_id,
    expiresAt: data.expires_at,
    resendAfterSeconds,
  };
}

function browserCookie(): string {
  return typeof document === "undefined" ? "" : document.cookie;
}

export function readTrustedBrowserXsrfToken(cookie: string): string {
  const match = cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/);
  if (!match?.[1]) {
    return "";
  }

  try {
    return decodeURIComponent(match[1]);
  } catch {
    return "";
  }
}

function trustedBrowserErrorFromPayload(status: number, value: unknown): TrustedBrowserApiError {
  if (
    isRecord(value) &&
    isRecord(value.error) &&
    typeof value.error.code === "string" &&
    ERROR_CODE_PATTERN.test(value.error.code) &&
    typeof value.error.message === "string" &&
    value.error.message.trim() !== "" &&
    value.error.message.length <= 500
  ) {
    return new TrustedBrowserApiError(value.error.message, value.error.code, status);
  }

  return new TrustedBrowserApiError(
    "Не удалось выполнить запрос. Попробуйте ещё раз.",
    "TRUSTED_BROWSER_REQUEST_FAILED",
    status,
  );
}

export function trustedBrowserProtectedErrorCode(value: unknown): TrustedBrowserProtectedErrorCode | null {
  if (!isRecord(value) || !isRecord(value.error)) {
    return null;
  }

  const code = value.error.code;
  if (
    code === "TRUSTED_BROWSER_REQUIRED" ||
    code === "TRUSTED_BROWSER_SUPPORT_REQUIRED" ||
    code === "TRUSTED_BROWSER_RISK_LOCKED"
  ) {
    return code;
  }

  return null;
}

function normalizeApiBaseUrl(apiBaseUrl: string): string {
  return apiBaseUrl.replace(/\/+$/, "");
}

async function responsePayload(response: Response): Promise<unknown> {
  return response.status === 204 ? null : response.json().catch(() => null);
}

function requestFetcher(options: TrustedBrowserRequestOptions): typeof fetch {
  return options.fetcher ?? fetch;
}

async function csrfHeaders(
  apiBaseUrl: string,
  options: TrustedBrowserRequestOptions,
): Promise<Record<string, string>> {
  const response = await requestFetcher(options)(`${apiBaseUrl}/sanctum/csrf-cookie`, {
    method: "GET",
    credentials: "include",
    signal: options.signal,
    headers: { Accept: "application/json" },
  });

  if (!response.ok) {
    throw new TrustedBrowserApiError(
      "Не удалось подготовить защищённый запрос.",
      "TRUSTED_BROWSER_CSRF_FAILED",
      response.status,
    );
  }

  const xsrfToken = readTrustedBrowserXsrfToken(options.cookie ?? browserCookie());
  if (xsrfToken === "") {
    throw new TrustedBrowserApiError(
      "Не удалось подготовить защищённый запрос.",
      "TRUSTED_BROWSER_CSRF_TOKEN_MISSING",
    );
  }

  return { "X-XSRF-TOKEN": xsrfToken };
}

async function getJson(
  apiBaseUrl: string,
  path: string,
  options: TrustedBrowserRequestOptions,
): Promise<unknown> {
  const response = await requestFetcher(options)(`${apiBaseUrl}${path}`, {
    method: "GET",
    credentials: "include",
    signal: options.signal,
    headers: { Accept: "application/json" },
  });
  const payload = await responsePayload(response);

  if (!response.ok) {
    throw trustedBrowserErrorFromPayload(response.status, payload);
  }

  return payload;
}

async function postJson(
  apiBaseUrl: string,
  path: string,
  body: JsonRecord,
  options: TrustedBrowserRequestOptions,
): Promise<unknown> {
  const xsrfHeaders = await csrfHeaders(apiBaseUrl, options);
  const response = await requestFetcher(options)(`${apiBaseUrl}${path}`, {
    method: "POST",
    credentials: "include",
    signal: options.signal,
    headers: {
      Accept: "application/json",
      "Content-Type": "application/json",
      ...xsrfHeaders,
    },
    body: JSON.stringify(body),
  });
  const payload = await responsePayload(response);

  if (!response.ok) {
    throw trustedBrowserErrorFromPayload(response.status, payload);
  }

  return payload;
}

function invalidResponseError(): TrustedBrowserApiError {
  return new TrustedBrowserApiError(
    "Сервер вернул неподдерживаемый ответ.",
    "TRUSTED_BROWSER_INVALID_RESPONSE",
  );
}

async function withStableNetworkError<T>(request: () => Promise<T>, options: TrustedBrowserRequestOptions): Promise<T> {
  try {
    return await request();
  } catch (error) {
    if (error instanceof TrustedBrowserApiError || options.signal?.aborted) {
      throw error;
    }

    throw new TrustedBrowserApiError(
      "Не удалось связаться с сервером. Попробуйте ещё раз.",
      "TRUSTED_BROWSER_NETWORK_ERROR",
    );
  }
}

export async function loadTrustedBrowserStatus(
  apiBaseUrl = trustedBrowserApiBaseUrl(),
  options: TrustedBrowserRequestOptions = {},
): Promise<TrustedBrowserStatus> {
  const normalizedBaseUrl = normalizeApiBaseUrl(apiBaseUrl);

  return withStableNetworkError(async () => {
    const payload = await getJson(normalizedBaseUrl, "/api/v1/auth/device-trust/status", options);
    const status = parseStatusPayload(payload);
    if (!status) {
      throw invalidResponseError();
    }

    return status;
  }, options);
}

export async function loadTrustedBrowserSlots(
  apiBaseUrl = trustedBrowserApiBaseUrl(),
  options: TrustedBrowserRequestOptions = {},
): Promise<ReadonlyArray<TrustedBrowserSlot>> {
  const normalizedBaseUrl = normalizeApiBaseUrl(apiBaseUrl);

  return withStableNetworkError(async () => {
    const payload = await getJson(normalizedBaseUrl, "/api/v1/auth/trusted-browsers", options);
    const slots = parseSlotsPayload(payload);
    if (!slots) {
      throw invalidResponseError();
    }

    return slots;
  }, options);
}

export async function createTrustedBrowserChallenge(
  input: CreateTrustedBrowserChallengeInput,
  apiBaseUrl = trustedBrowserApiBaseUrl(),
  options: TrustedBrowserRequestOptions = {},
): Promise<TrustedBrowserChallenge> {
  const normalizedBaseUrl = normalizeApiBaseUrl(apiBaseUrl);
  const body: JsonRecord = input.purpose === "enroll"
    ? { purpose: input.purpose }
    : { purpose: input.purpose, slot_number: input.slotNumber, password: input.password };

  return withStableNetworkError(async () => {
    const payload = await postJson(normalizedBaseUrl, "/api/v1/auth/device-trust/challenges", body, options);
    const challenge = parseChallengePayload(payload);
    if (!challenge) {
      throw invalidResponseError();
    }

    return challenge;
  }, options);
}

export async function verifyTrustedBrowserChallenge(
  challengeId: string,
  code: string,
  apiBaseUrl = trustedBrowserApiBaseUrl(),
  options: TrustedBrowserRequestOptions = {},
): Promise<void> {
  if (!OPAQUE_ID_PATTERN.test(challengeId) || !/^\d{6}$/.test(code)) {
    throw new TrustedBrowserApiError(
      "Проверьте шестизначный код и попробуйте ещё раз.",
      "TRUSTED_BROWSER_CHALLENGE_INVALID",
    );
  }

  const normalizedBaseUrl = normalizeApiBaseUrl(apiBaseUrl);
  await withStableNetworkError(async () => {
    const payload = await postJson(
      normalizedBaseUrl,
      `/api/v1/auth/device-trust/challenges/${encodeURIComponent(challengeId)}/verify`,
      { code },
      options,
    );
    if (!isChallengeVerificationPayload(payload)) {
      throw invalidResponseError();
    }
  }, options);
}

export async function revokeTrustedBrowser(
  slotNumber: TrustedBrowserSlotNumber,
  apiBaseUrl = trustedBrowserApiBaseUrl(),
  options: TrustedBrowserRequestOptions = {},
): Promise<void> {
  const normalizedBaseUrl = normalizeApiBaseUrl(apiBaseUrl);
  await withStableNetworkError(async () => {
    const payload = await postJson(
      normalizedBaseUrl,
      `/api/v1/auth/trusted-browsers/${slotNumber}/revoke`,
      {},
      options,
    );
    if (!isRevokePayload(payload)) {
      throw invalidResponseError();
    }
  }, options);
}
