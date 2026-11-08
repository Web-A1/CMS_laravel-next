import { clientAccountApiBaseUrl, isClientAccountProfile, readXsrfToken, type ClientAccountProfile } from "./clientAccount";

export type AccountProfileSaveResult =
  | { readonly status: "ready"; readonly profile: ClientAccountProfile & { readonly name: string } }
  | { readonly status: "error"; readonly message: string; readonly nameError: string };

type SaveOptions = { readonly fetcher?: typeof fetch; readonly cookie?: string };

async function failure(response?: Response): Promise<AccountProfileSaveResult> {
  let message = "Не удалось сохранить имя. Попробуйте ещё раз.";
  let nameError = "";
  if (response?.status === 401 || response?.status === 419) message = "Сессия завершилась. Войдите снова.";
  if (response?.status === 403) message = "Изменение имени недоступно в этом режиме.";
  if (response?.status === 429) message = "Слишком много запросов. Подождите минуту и повторите действие.";
  if (response?.status === 422) {
    message = "Проверьте имя.";
    const payload: unknown = await response.json().catch(() => null);
    if (typeof payload === "object" && payload !== null && "errors" in payload) {
      const errors = payload.errors;
      if (typeof errors === "object" && errors !== null && "name" in errors
        && Array.isArray(errors.name) && errors.name.every(value => typeof value === "string")) {
        nameError = errors.name.join(" ");
      }
    }
  }
  return { status: "error", message, nameError };
}

export async function saveAccountProfileName(name: string, options: SaveOptions = {}): Promise<AccountProfileSaveResult> {
  const fetcher = options.fetcher ?? fetch;
  const apiBaseUrl = clientAccountApiBaseUrl();
  try {
    const csrfResponse = await fetcher(`${apiBaseUrl}/sanctum/csrf-cookie`, {
      credentials: "include", cache: "no-store", redirect: "error",
      headers: { Accept: "application/json" }, signal: AbortSignal.timeout(10_000),
    });
    if (!csrfResponse.ok) return failure(csrfResponse);
    const token = readXsrfToken(options.cookie ?? (typeof document === "undefined" ? "" : document.cookie));
    if (!token) return failure();
    const response = await fetcher(`${apiBaseUrl}/api/v1/me/profile`, {
      method: "PATCH", credentials: "include", cache: "no-store", redirect: "error",
      headers: { Accept: "application/json", "Content-Type": "application/json", "X-XSRF-TOKEN": token },
      body: JSON.stringify({ name }), signal: AbortSignal.timeout(10_000),
    });
    if (!response.ok) return failure(response);
    const profile: unknown = await response.json();
    if (!isClientAccountProfile(profile) || typeof profile.name !== "string"
      || (profile.email !== null && typeof profile.email !== "string")
      || (profile.phone !== null && typeof profile.phone !== "string")
      || typeof profile.role !== "string" || typeof profile.phone_verified !== "boolean"
      || typeof profile.email_verified !== "boolean" || typeof profile.must_verify_phone !== "boolean"
      || typeof profile.must_verify_email !== "boolean") return failure();
    return { status: "ready", profile: { ...profile, name: profile.name } };
  } catch {
    return failure();
  }
}
