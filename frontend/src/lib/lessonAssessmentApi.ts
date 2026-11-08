import {
  lessonAssessmentPath,
  parseLessonAssessmentPayload,
  parseLessonAssessmentSubmissionPayload,
  type LessonAssessmentData,
  type LessonAssessmentResponse,
  type LessonAssessmentRoute,
  type LessonAssessmentSaveInput,
} from "./lessonAssessmentContract";

export type LessonAssessmentBrowserOptions = {
  readonly cookie?: string;
  readonly fetcher?: typeof fetch;
  readonly signal?: AbortSignal;
};

export type LessonAssessmentMutationResult =
  | { readonly status: "ready"; readonly data: LessonAssessmentData }
  | { readonly status: "conflict" }
  | { readonly status: "denied" }
  | { readonly status: "aborted" }
  | { readonly status: "unavailable" };

export type LessonAssessmentSubmitResult =
  | { readonly status: "ready"; readonly data: LessonAssessmentData }
  | { readonly status: "conflict" }
  | { readonly status: "incomplete" }
  | { readonly status: "denied" }
  | { readonly status: "aborted" }
  | { readonly status: "unavailable" };

export type LessonAssessmentDevelopmentResetResult =
  | { readonly status: "ready" }
  | { readonly status: "denied" | "aborted" | "unavailable" };

export type LessonAssessmentDevelopmentCalibrationTermsResult =
  | { readonly status: "ready"; readonly terms: Readonly<Record<string, string>> }
  | { readonly status: "denied" | "aborted" | "unavailable" };

export type LessonAssessmentSuggestionResult =
  | { readonly status: "ready"; readonly suggestions: ReadonlyArray<string> }
  | { readonly status: "denied" | "aborted" | "unavailable" };

type JsonRecord = Record<string, unknown>;

type MutationResponse = {
  readonly response: Response;
  readonly payload: unknown;
};

const IDENTIFIER_PATTERN = /^[a-z0-9][a-z0-9-]{1,63}$/;
const MAX_SUGGESTION_RESULTS = 500;

function isRecord(value: unknown): value is JsonRecord {
  return typeof value === "object" && value !== null && !Array.isArray(value);
}

function hasExactKeys(record: JsonRecord, required: ReadonlyArray<string>): boolean {
  const allowed = new Set(required);
  const keys = Object.keys(record);

  return required.every((key) => Object.hasOwn(record, key)) && keys.every((key) => allowed.has(key));
}

function isIdentifier(value: unknown): value is string {
  return typeof value === "string" && IDENTIFIER_PATTERN.test(value);
}

function isNonNegativeInteger(value: unknown): value is number {
  return typeof value === "number" && Number.isSafeInteger(value) && value >= 0;
}

function isPositiveInteger(value: unknown): value is number {
  return typeof value === "number" && Number.isSafeInteger(value) && value > 0;
}

function uniqueStrings(values: ReadonlyArray<string>): boolean {
  return new Set(values).size === values.length;
}

function isResponse(value: unknown): value is LessonAssessmentResponse {
  if (!isRecord(value)) {
    return false;
  }
  if (hasExactKeys(value, ["selectedOptionId"])) {
    return isIdentifier(value.selectedOptionId);
  }
  if (hasExactKeys(value, ["selectedOptionIds"]) && Array.isArray(value.selectedOptionIds)) {
    const optionIds: Array<string> = [];
    for (const optionId of value.selectedOptionIds) {
      if (!isIdentifier(optionId)) {
        return false;
      }
      optionIds.push(optionId);
    }
    return uniqueStrings(optionIds);
  }
  if (hasExactKeys(value, ["text"])) {
    return typeof value.text === "string" && value.text.length <= 10_000;
  }
  if (hasExactKeys(value, ["texts"]) && isRecord(value.texts)) {
    return Object.entries(value.texts).every(
      ([partId, text]) => isIdentifier(partId) && typeof text === "string" && text.length <= 500,
    );
  }
  if (!hasExactKeys(value, ["selections"]) || !isRecord(value.selections)) {
    return false;
  }

  return Object.entries(value.selections).every(
    ([partId, optionId]) => isIdentifier(partId) && isIdentifier(optionId),
  );
}

function parseSuggestions(value: unknown): ReadonlyArray<string> | null {
  if (!isRecord(value) || !hasExactKeys(value, ["data"]) || !isRecord(value.data) ||
      !hasExactKeys(value.data, ["suggestions"]) || !Array.isArray(value.data.suggestions) ||
      value.data.suggestions.length > MAX_SUGGESTION_RESULTS) {
    return null;
  }

  const suggestions: Array<string> = [];
  for (const suggestion of value.data.suggestions) {
    if (typeof suggestion !== "string" || suggestion.trim() === "" || suggestion.length > 500) {
      return null;
    }
    suggestions.push(suggestion);
  }

  return uniqueStrings(suggestions) ? suggestions : null;
}

function isDevelopmentResetPayload(value: unknown): boolean {
  return isRecord(value) &&
    hasExactKeys(value, ["data"]) &&
    isRecord(value.data) &&
    hasExactKeys(value.data, ["status"]) &&
    value.data.status === "reset";
}

function parseDevelopmentCalibrationTerms(
  value: unknown,
  questionId: string,
): Readonly<Record<string, string>> | null {
  if (
    !isRecord(value) ||
    !hasExactKeys(value, ["data"]) ||
    !isRecord(value.data) ||
    !hasExactKeys(value.data, ["questionId", "terms"]) ||
    value.data.questionId !== questionId ||
    !isRecord(value.data.terms)
  ) {
    return null;
  }

  const terms: Record<string, string> = {};
  for (const [partId, term] of Object.entries(value.data.terms)) {
    if (!isIdentifier(partId) || typeof term !== "string" || term.trim() === "" || term.length > 500) {
      return null;
    }
    terms[partId] = term;
  }

  return Object.keys(terms).length > 0 ? terms : null;
}

function isSaveInput(value: unknown): value is LessonAssessmentSaveInput {
  if (
    !isRecord(value) ||
    (
      !hasExactKeys(value, ["revision", "lastQuestionId", "responses"]) &&
      !hasExactKeys(value, ["revision", "lastQuestionId", "responses", "lockQuestionIds"])
    ) ||
    !isNonNegativeInteger(value.revision) ||
    (value.lastQuestionId !== null && !isIdentifier(value.lastQuestionId)) ||
    !Array.isArray(value.responses)
  ) {
    return false;
  }

  const questionIds = new Set<string>();
  for (const entry of value.responses) {
    if (
      !isRecord(entry) ||
      !hasExactKeys(entry, ["questionId", "response"]) ||
      !isIdentifier(entry.questionId) ||
      questionIds.has(entry.questionId) ||
      !isResponse(entry.response)
    ) {
      return false;
    }
    questionIds.add(entry.questionId);
  }

  if (Object.hasOwn(value, "lockQuestionIds")) {
    if (!Array.isArray(value.lockQuestionIds)) {
      return false;
    }

    const lockQuestionIds: Array<string> = [];
    for (const questionId of value.lockQuestionIds) {
      if (!isIdentifier(questionId)) {
        return false;
      }
      lockQuestionIds.push(questionId);
    }

    if (!uniqueStrings(lockQuestionIds)) {
      return false;
    }
  }

  return true;
}

function normalizedApiBaseUrl(apiBaseUrl: string): string | null {
  if (apiBaseUrl.startsWith("/") && !apiBaseUrl.startsWith("//")) {
    if (apiBaseUrl.includes("?") || apiBaseUrl.includes("#")) {
      return null;
    }
    if (apiBaseUrl === "/") {
      return "";
    }

    const normalized = apiBaseUrl.replace(/\/+$/, "");
    const parsed = new URL(normalized, "https://api-base.invalid");
    return parsed.pathname === normalized ? normalized : null;
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

function browserCookie(): string {
  return typeof document === "undefined" ? "" : document.cookie;
}

export function readLessonAssessmentXsrfToken(cookie: string): string {
  const entry = cookie
    .split(";")
    .map((part) => part.trim())
    .find((part) => part.startsWith("XSRF-TOKEN="));
  if (entry === undefined) {
    return "";
  }

  const encodedToken = entry.slice("XSRF-TOKEN=".length);
  if (encodedToken === "") {
    return "";
  }

  try {
    const decodedToken = decodeURIComponent(encodedToken);
    return decodedToken.length <= 4_096 && !/[\u0000-\u001F\u007F]/.test(decodedToken) ? decodedToken : "";
  } catch {
    return "";
  }
}

function requestFetcher(options: LessonAssessmentBrowserOptions): typeof fetch {
  return options.fetcher ?? fetch;
}

async function mutationRequest(
  apiBaseUrl: string,
  path: string,
  method: "DELETE" | "PATCH" | "POST",
  body: JsonRecord,
  options: LessonAssessmentBrowserOptions,
): Promise<MutationResponse | null> {
  const fetcher = requestFetcher(options);
  const csrfResponse = await fetcher(`${apiBaseUrl}/sanctum/csrf-cookie`, {
    method: "GET",
    credentials: "include",
    signal: options.signal,
    headers: { Accept: "application/json" },
  });
  if (!csrfResponse.ok) {
    return csrfResponse.status === 401 || csrfResponse.status === 403
      ? { response: csrfResponse, payload: null }
      : null;
  }

  const xsrfToken = readLessonAssessmentXsrfToken(options.cookie ?? browserCookie());
  if (xsrfToken === "") {
    return null;
  }

  const response = await fetcher(`${apiBaseUrl}${path}`, {
    method,
    credentials: "include",
    signal: options.signal,
    headers: {
      Accept: "application/json",
      "Content-Type": "application/json",
      "X-XSRF-TOKEN": xsrfToken,
    },
    body: JSON.stringify(body),
  });
  const payload: unknown = await response.json().catch(() => null);

  return { response, payload };
}

function isAbortError(error: unknown): boolean {
  return isRecord(error) && error.name === "AbortError";
}

function commonFailureStatus(status: number): "conflict" | "denied" | "unavailable" {
  if (status === 409) {
    return "conflict";
  }
  if (status === 401 || status === 403) {
    return "denied";
  }

  return "unavailable";
}

function mutationPath(route: LessonAssessmentRoute): string | null {
  return lessonAssessmentPath(route);
}

async function startLessonAssessmentMutation(
  apiBaseUrl: string,
  route: LessonAssessmentRoute,
  body: JsonRecord,
  options: LessonAssessmentBrowserOptions = {},
): Promise<LessonAssessmentMutationResult> {
  const normalizedBaseUrl = normalizedApiBaseUrl(apiBaseUrl);
  const assessmentPath = mutationPath(route);
  if (normalizedBaseUrl === null || assessmentPath === null) {
    return { status: "unavailable" };
  }

  try {
    const result = await mutationRequest(normalizedBaseUrl, `${assessmentPath}/attempts`, "POST", body, options);
    if (result === null) {
      return { status: "unavailable" };
    }
    if (result.response.status === 200 || result.response.status === 201) {
      const data = parseLessonAssessmentPayload(result.payload, route);
      return data === null || data.attempt === null
        ? { status: "unavailable" }
        : { status: "ready", data };
    }

    return { status: commonFailureStatus(result.response.status) };
  } catch (error) {
    return isAbortError(error) ? { status: "aborted" } : { status: "unavailable" };
  }
}

export function startLessonAssessment(
  apiBaseUrl: string,
  route: LessonAssessmentRoute,
  options: LessonAssessmentBrowserOptions = {},
): Promise<LessonAssessmentMutationResult> {
  return startLessonAssessmentMutation(apiBaseUrl, route, {}, options);
}

export function retakeLessonAssessment(
  apiBaseUrl: string,
  route: LessonAssessmentRoute,
  options: LessonAssessmentBrowserOptions = {},
): Promise<LessonAssessmentMutationResult> {
  return startLessonAssessmentMutation(apiBaseUrl, route, { retake: true }, options);
}

export async function resetLessonAssessmentDevelopmentState(
  apiBaseUrl: string,
  route: LessonAssessmentRoute,
  options: LessonAssessmentBrowserOptions = {},
): Promise<LessonAssessmentDevelopmentResetResult> {
  const normalizedBaseUrl = normalizedApiBaseUrl(apiBaseUrl);
  const assessmentPath = mutationPath(route);
  if (normalizedBaseUrl === null || assessmentPath === null) {
    return { status: "unavailable" };
  }

  try {
    const result = await mutationRequest(
      normalizedBaseUrl,
      `${assessmentPath}/development-state`,
      "DELETE",
      {},
      options,
    );
    if (result === null) {
      return { status: "unavailable" };
    }
    if (result.response.status === 200) {
      return isDevelopmentResetPayload(result.payload)
        ? { status: "ready" }
        : { status: "unavailable" };
    }
    if (result.response.status === 401 || result.response.status === 403) {
      return { status: "denied" };
    }

    return { status: "unavailable" };
  } catch (error) {
    return isAbortError(error) ? { status: "aborted" } : { status: "unavailable" };
  }
}

export async function loadLessonAssessmentDevelopmentCalibrationTerms(
  apiBaseUrl: string,
  route: LessonAssessmentRoute,
  questionId: string,
  options: LessonAssessmentBrowserOptions = {},
): Promise<LessonAssessmentDevelopmentCalibrationTermsResult> {
  const normalizedBaseUrl = normalizedApiBaseUrl(apiBaseUrl);
  const assessmentPath = mutationPath(route);
  if (
    normalizedBaseUrl === null ||
    assessmentPath === null ||
    !/^diagram-\d{2}$/.test(questionId)
  ) {
    return { status: "unavailable" };
  }

  const parameters = new URLSearchParams({ questionId });
  try {
    const response = await requestFetcher(options)(
      `${normalizedBaseUrl}${assessmentPath}/development-calibration-key?${parameters.toString()}`,
      {
        method: "GET",
        credentials: "include",
        signal: options.signal,
        headers: { Accept: "application/json" },
      },
    );
    if (response.status === 401 || response.status === 403) {
      return { status: "denied" };
    }
    if (!response.ok) {
      return { status: "unavailable" };
    }

    const terms = parseDevelopmentCalibrationTerms(
      await response.json().catch(() => null),
      questionId,
    );
    return terms === null ? { status: "unavailable" } : { status: "ready", terms };
  } catch (error) {
    return isAbortError(error) ? { status: "aborted" } : { status: "unavailable" };
  }
}

export async function saveLessonAssessment(
  apiBaseUrl: string,
  route: LessonAssessmentRoute,
  attemptId: number,
  input: LessonAssessmentSaveInput,
  options: LessonAssessmentBrowserOptions = {},
): Promise<LessonAssessmentMutationResult> {
  const normalizedBaseUrl = normalizedApiBaseUrl(apiBaseUrl);
  const assessmentPath = mutationPath(route);
  if (
    normalizedBaseUrl === null ||
    assessmentPath === null ||
    !isPositiveInteger(attemptId) ||
    !isSaveInput(input)
  ) {
    return { status: "unavailable" };
  }

  try {
    const body: JsonRecord = {
      revision: input.revision,
      lastQuestionId: input.lastQuestionId,
      responses: input.responses,
    };
    if (input.lockQuestionIds !== undefined) {
      body.lockQuestionIds = input.lockQuestionIds;
    }

    const result = await mutationRequest(
      normalizedBaseUrl,
      `${assessmentPath}/attempts/${attemptId}`,
      "PATCH",
      body,
      options,
    );
    if (result === null) {
      return { status: "unavailable" };
    }
    if (result.response.status === 200) {
      const data = parseLessonAssessmentPayload(result.payload, route);
      return data === null ||
        data.attempt === null ||
        data.attempt.id !== attemptId ||
        data.attempt.revision !== input.revision + 1
        ? { status: "unavailable" }
        : { status: "ready", data };
    }

    return { status: commonFailureStatus(result.response.status) };
  } catch (error) {
    return isAbortError(error) ? { status: "aborted" } : { status: "unavailable" };
  }
}

export async function submitLessonAssessment(
  apiBaseUrl: string,
  route: LessonAssessmentRoute,
  attemptId: number,
  revision: number,
  options: LessonAssessmentBrowserOptions = {},
): Promise<LessonAssessmentSubmitResult> {
  const normalizedBaseUrl = normalizedApiBaseUrl(apiBaseUrl);
  const assessmentPath = mutationPath(route);
  if (
    normalizedBaseUrl === null ||
    assessmentPath === null ||
    !isPositiveInteger(attemptId) ||
    !isNonNegativeInteger(revision)
  ) {
    return { status: "unavailable" };
  }

  try {
    const result = await mutationRequest(
      normalizedBaseUrl,
      `${assessmentPath}/attempts/${attemptId}/submissions`,
      "POST",
      { revision },
      options,
    );
    if (result === null) {
      return { status: "unavailable" };
    }
    if (result.response.status === 200) {
      const data = parseLessonAssessmentSubmissionPayload(result.payload, route);
      return data === null ||
        data.attempt === null || data.attempt.id !== attemptId ||
        data.attempt.revision !== revision + 1
        ? { status: "unavailable" }
        : { status: "ready", data };
    }
    if (result.response.status === 422) {
      return { status: "incomplete" };
    }

    return { status: commonFailureStatus(result.response.status) };
  } catch (error) {
    return isAbortError(error) ? { status: "aborted" } : { status: "unavailable" };
  }
}

export async function suggestLessonAssessmentAnswer(
  apiBaseUrl: string,
  route: LessonAssessmentRoute,
  attemptId: number,
  questionId: string,
  partId: string,
  prefix: string,
  options: LessonAssessmentBrowserOptions = {},
): Promise<LessonAssessmentSuggestionResult> {
  const normalizedBaseUrl = normalizedApiBaseUrl(apiBaseUrl);
  const assessmentPath = mutationPath(route);
  if (
    normalizedBaseUrl === null ||
    assessmentPath === null ||
    !isPositiveInteger(attemptId) ||
    !isIdentifier(questionId) ||
    !isIdentifier(partId) ||
    [...prefix.trim()].length < 3 ||
    prefix.length > 500
  ) {
    return { status: "unavailable" };
  }

  const parameters = new URLSearchParams({ questionId, partId, prefix });
  try {
    const response = await requestFetcher(options)(
      `${normalizedBaseUrl}${assessmentPath}/attempts/${attemptId}/suggestions?${parameters.toString()}`,
      {
        method: "GET",
        credentials: "include",
        signal: options.signal,
        headers: { Accept: "application/json" },
      },
    );
    if (response.status === 401 || response.status === 403) {
      return { status: "denied" };
    }
    if (!response.ok) {
      return { status: "unavailable" };
    }
    const suggestions = parseSuggestions(await response.json().catch(() => null));
    return suggestions === null ? { status: "unavailable" } : { status: "ready", suggestions };
  } catch (error) {
    return isAbortError(error) ? { status: "aborted" } : { status: "unavailable" };
  }
}
