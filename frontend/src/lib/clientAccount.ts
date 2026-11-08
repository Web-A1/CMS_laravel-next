import type { ImpersonationContext } from "./impersonationContract";

export type ClientAccountProfile = {
  readonly id: number;
  readonly name?: string | null;
  readonly email?: string | null;
  readonly phone?: string | null;
  readonly avatar_url?: string | null;
  readonly role?: string | null;
  readonly phone_verified?: boolean;
  readonly email_verified?: boolean;
  readonly must_verify_phone?: boolean;
  readonly must_verify_email?: boolean;
  readonly impersonation?: ImpersonationContext | null;
};

type ClientFetch = typeof fetch;

export type ClientAccountResult =
  | { readonly status: "authenticated"; readonly profile: ClientAccountProfile }
  | { readonly status: "unauthenticated" }
  | { readonly status: "unavailable" };

type ClientAccountDependencies = {
  readonly fetchAccount?: ClientFetch;
};

type LogoutDependencies = {
  readonly fetchAuth?: ClientFetch;
  readonly cookie?: string;
};

type LogoutEndpoint = "logout" | "idle-logout";
export type ClientAccountClearReason = LogoutEndpoint | "profile-401";

export const CLIENT_ACCOUNT_CLEARED_EVENT = "cms:client-account-cleared";
export const CLIENT_ACCOUNT_PROFILE_UPDATED_EVENT = "cms:client-account-profile-updated";
export const BEFORE_ACCOUNT_LOGOUT_EVENT = "cms:before-account-logout";

const accountSessionMarkerKey = "cms.account.authenticated";
const nullableStringFields = ["name", "email", "phone", "avatar_url", "role"] as const;
const optionalBooleanFields = [
  "phone_verified",
  "email_verified",
  "must_verify_phone",
  "must_verify_email",
] as const;

function isImpersonationContext(value: unknown): value is ImpersonationContext {
  if (typeof value !== "object" || value === null || Array.isArray(value)) return false;

  const context = value as Record<string, unknown>;
  const status = context.status;
  const identity = (candidate: unknown): boolean => {
    if (candidate === null) return true;
    if (typeof candidate !== "object" || candidate === null || Array.isArray(candidate)) return false;
    const item = candidate as Record<string, unknown>;
    return Object.keys(item).length === 3
      && Number.isSafeInteger(item.id)
      && (item.id as number) > 0
      && typeof item.name === "string"
      && typeof item.role === "string";
  };

  return Object.keys(context).length === 7
    && (status === "active" || status === "expired" || status === "inactive" || status === "stopped")
    && typeof context.read_only === "boolean"
    && identity(context.actor)
    && identity(context.subject)
    && (context.started_at === null || typeof context.started_at === "string")
    && (context.expires_at === null || typeof context.expires_at === "string")
    && typeof context.return_path === "string"
    && context.return_path.length > 0
    && context.return_path.length <= 512
    && (context.return_path === "/admin" || context.return_path.startsWith("/admin/"));
}

export function clientAccountApiBaseUrl(): string {
  return (process.env.NEXT_PUBLIC_API_BASE_URL ?? "/backend").replace(/\/+$/, "");
}

function browserLocalStorage(): Storage | null {
  if (typeof window === "undefined") {
    return null;
  }

  try {
    return window.localStorage;
  } catch {
    return null;
  }
}

export function markClientAccountAuthenticated(): void {
  const storage = browserLocalStorage();
  if (!storage) return;

  try {
    storage.setItem(accountSessionMarkerKey, "1");
  } catch {
    // localStorage availability must not affect authentication itself.
  }
}

export function clearClientAccountAuthenticated(reason: ClientAccountClearReason = "logout"): void {
  const storage = browserLocalStorage();
  if (storage) {
    try {
      storage.removeItem(accountSessionMarkerKey);
    } catch {
      // localStorage availability must not affect logout itself.
    }
  }

  if (typeof window !== "undefined" && typeof window.dispatchEvent === "function") {
    window.dispatchEvent(new CustomEvent(CLIENT_ACCOUNT_CLEARED_EVENT, {
      detail: { reason },
    }));
  }
}

export function hasClientAccountAuthenticatedMarker(): boolean {
  const storage = browserLocalStorage();
  if (!storage) return false;

  try {
    return storage.getItem(accountSessionMarkerKey) === "1";
  } catch {
    return false;
  }
}

export function isClientAccountProfile(value: unknown): value is ClientAccountProfile {
  if (typeof value !== "object" || value === null || Array.isArray(value)) {
    return false;
  }

  const profile = value as Record<string, unknown>;

  return (
    Number.isInteger(profile.id) &&
    (profile.id as number) > 0 &&
    nullableStringFields.every((field) =>
      profile[field] === undefined || profile[field] === null || typeof profile[field] === "string",
    ) &&
    optionalBooleanFields.every((field) => profile[field] === undefined || typeof profile[field] === "boolean")
    && (profile.impersonation === undefined || profile.impersonation === null || isImpersonationContext(profile.impersonation))
  );
}

export function notifyClientAccountProfileUpdated(profile: ClientAccountProfile): void {
  if (
    typeof window !== "undefined" &&
    typeof window.dispatchEvent === "function" &&
    isClientAccountProfile(profile)
  ) {
    window.dispatchEvent(new CustomEvent(CLIENT_ACCOUNT_PROFILE_UPDATED_EVENT, { detail: profile }));
  }
}

export function resolveClientAccountName(profile: ClientAccountProfile): string {
  return profile.name?.trim() || profile.email?.trim() || profile.phone?.trim() || "Профиль";
}

// Navigation visibility only; every admin page/API still enforces backend permissions.
export function canOpenAdminWorkspace(profile: ClientAccountProfile | null): boolean {
  return profile?.role !== undefined && profile.role !== null
    && ["admin_full", "admin_limited", "manager", "teacher", "polina"].includes(profile.role);
}

export function resolveClientAccountHref(profile: ClientAccountProfile | null): string {
  if (!canOpenAdminWorkspace(profile)) return "/lk";
  return profile?.role === "polina" ? "/admin/articles" : "/admin/profile";
}

export function requestClientAccountLogout(): boolean {
  return window.dispatchEvent(new Event(BEFORE_ACCOUNT_LOGOUT_EVENT, { cancelable: true }));
}

export function resolveClientAccountAvatarUrl(profile: ClientAccountProfile): string {
  return profile.avatar_url?.trim() ?? "";
}

export async function loadClientAccountProfile(
  apiBaseUrl = clientAccountApiBaseUrl(),
  dependencies: ClientAccountDependencies = {},
): Promise<ClientAccountProfile | null> {
  const result = await loadClientAccountProfileResult(apiBaseUrl, dependencies);
  if (result.status === "unauthenticated") clearClientAccountAuthenticated("profile-401");
  return result.status === "authenticated" ? result.profile : null;
}

export async function loadClientAccountProfileResult(
  apiBaseUrl = clientAccountApiBaseUrl(),
  dependencies: ClientAccountDependencies = {},
): Promise<ClientAccountResult> {
  try {
    const response = await (dependencies.fetchAccount ?? fetch)(`${apiBaseUrl}/api/v1/auth/me`, {
      credentials: "include",
      cache: "no-store",
      headers: { Accept: "application/json" },
    });

    if (!response.ok) {
      if (response.status === 401) {
        return { status: "unauthenticated" };
      }

      return { status: "unavailable" };
    }

    const payload: unknown = await response.json().catch(() => null);
    if (!isClientAccountProfile(payload)) {
      return { status: "unavailable" };
    }

    markClientAccountAuthenticated();
    return { status: "authenticated", profile: payload };
  } catch {
    return { status: "unavailable" };
  }
}

export function readXsrfToken(cookie: string): string {
  const match = cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
  if (!match?.[1]) {
    return "";
  }

  try {
    return decodeURIComponent(match[1]);
  } catch {
    return "";
  }
}

function browserCookie(): string {
  return typeof document === "undefined" ? "" : document.cookie;
}

export async function logoutClientAccount(
  apiBaseUrl = clientAccountApiBaseUrl(),
  dependencies: LogoutDependencies = {},
): Promise<void> {
  await logoutClientAccountAtEndpoint(apiBaseUrl, "logout", dependencies);
}

export async function logoutClientAccountAfterIdleTimeout(
  apiBaseUrl = clientAccountApiBaseUrl(),
  dependencies: LogoutDependencies = {},
): Promise<void> {
  await logoutClientAccountAtEndpoint(apiBaseUrl, "idle-logout", dependencies);
}

async function logoutClientAccountAtEndpoint(
  apiBaseUrl: string,
  endpoint: LogoutEndpoint,
  dependencies: LogoutDependencies,
): Promise<void> {
  const normalizedApiBaseUrl = apiBaseUrl.replace(/\/+$/, "");
  const fetchAuth = dependencies.fetchAuth ?? fetch;
  const csrfResponse = await fetchAuth(`${normalizedApiBaseUrl}/sanctum/csrf-cookie`, {
    method: "GET",
    credentials: "include",
    headers: { Accept: "application/json" },
  });

  if (!csrfResponse.ok) {
    throw new Error("csrf request failed");
  }

  const xsrf = readXsrfToken(dependencies.cookie ?? browserCookie());
  const headers: Record<string, string> = { Accept: "application/json" };
  if (xsrf !== "") {
    headers["X-XSRF-TOKEN"] = xsrf;
  }

  const logoutResponse = await fetchAuth(`${normalizedApiBaseUrl}/api/v1/auth/${endpoint}`, {
    method: "POST",
    credentials: "include",
    headers,
  });

  if (!logoutResponse.ok) {
    throw new Error("logout request failed");
  }

  clearClientAccountAuthenticated(endpoint);
}
