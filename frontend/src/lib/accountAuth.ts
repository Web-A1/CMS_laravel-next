import { headers } from "next/headers";

import { backendApiBaseUrl } from "@/lib/apiBaseUrl";
import type { ImpersonationContext } from "@/lib/impersonationContract";

export type AccountProfile = {
  readonly id: number;
  readonly name?: string | null;
  readonly email?: string | null;
  readonly phone?: string | null;
  readonly avatar_url?: string | null;
  readonly phone_verified?: boolean;
  readonly email_verified?: boolean;
  readonly must_verify_phone?: boolean;
  readonly must_verify_email?: boolean;
  readonly role?: string | null;
  readonly impersonation?: ImpersonationContext | null;
};

export type AccountAuthResult =
  | { readonly status: "authenticated"; readonly profile: AccountProfile }
  | { readonly status: "unauthenticated" }
  | { readonly status: "unavailable" };

export type AccountBackendRequest = {
  readonly resolvedApiBaseUrl: string;
  readonly headers: Record<string, string>;
};

type AccountAuthDependencies = {
  readonly buildRequest?: typeof accountBackendRequest;
  readonly fetchAccount?: typeof fetch;
  readonly timeoutMs?: number;
};

export function accountApiBaseUrl(): string {
  return (process.env.NEXT_PUBLIC_API_BASE_URL ?? "/backend").replace(/\/+$/, "");
}

function frontendOriginFromHeaders(requestHeaders: Headers): string {
  const host = requestHeaders.get("x-forwarded-host") ?? requestHeaders.get("host");
  const proto = requestHeaders.get("x-forwarded-proto") ?? "https";
  if (!host || /[\s\\/@?#,]/.test(host) || (proto !== "http" && proto !== "https")) {
    return "";
  }

  try {
    return new URL(`${proto}://${host}`).origin;
  } catch {
    return "";
  }
}

function isAccountProfile(value: unknown): value is AccountProfile {
  if (typeof value !== "object" || value === null || Array.isArray(value)) {
    return false;
  }

  const profile = value as Record<string, unknown>;
  const nullableStrings = ["name", "email", "phone", "avatar_url", "role"] as const;
  const optionalBooleans = [
    "phone_verified",
    "email_verified",
    "must_verify_phone",
    "must_verify_email",
  ] as const;

  const isImpersonationContext = (candidate: unknown): candidate is ImpersonationContext => {
    if (typeof candidate !== "object" || candidate === null || Array.isArray(candidate)) return false;
    const context = candidate as Record<string, unknown>;
    const identity = (item: unknown): boolean => {
      if (item === null) return true;
      if (typeof item !== "object" || item === null || Array.isArray(item)) return false;
      const record = item as Record<string, unknown>;
      return Object.keys(record).length === 3
        && Number.isSafeInteger(record.id)
        && (record.id as number) > 0
        && typeof record.name === "string"
        && typeof record.role === "string";
    };

    return Object.keys(context).length === 7
      && (context.status === "active" || context.status === "expired" || context.status === "inactive" || context.status === "stopped")
      && typeof context.read_only === "boolean"
      && identity(context.actor)
      && identity(context.subject)
      && (context.started_at === null || typeof context.started_at === "string")
      && (context.expires_at === null || typeof context.expires_at === "string")
      && typeof context.return_path === "string"
      && context.return_path.length > 0
      && context.return_path.length <= 512
      && (context.return_path === "/admin" || context.return_path.startsWith("/admin/"));
  };

  return (
    Number.isInteger(profile.id) &&
    (profile.id as number) > 0 &&
    nullableStrings.every((field) =>
      profile[field] === undefined || profile[field] === null || typeof profile[field] === "string",
    ) &&
    optionalBooleans.every((field) => profile[field] === undefined || typeof profile[field] === "boolean")
    && (profile.impersonation === undefined || profile.impersonation === null
      || isImpersonationContext(profile.impersonation))
  );
}

export function classifyAccountResponse(status: number, payload: unknown): AccountAuthResult {
  if (status === 401) {
    return { status: "unauthenticated" };
  }
  if (status >= 200 && status < 300 && isAccountProfile(payload)) {
    return { status: "authenticated", profile: payload };
  }

  return { status: "unavailable" };
}

export async function loadAccountProfile(
  apiBaseUrl: string,
  refererPath: string,
  dependencies: AccountAuthDependencies = {},
): Promise<AccountAuthResult> {
  try {
    const response = await fetchAccountBackend(apiBaseUrl, "/api/v1/auth/me", refererPath, dependencies);

    const payload: unknown = await response.json().catch(() => null);
    return classifyAccountResponse(response.status, payload);
  } catch {
    return { status: "unavailable" };
  }
}

export async function fetchAccountBackend(
  apiBaseUrl: string,
  path: string,
  refererPath: string,
  dependencies: AccountAuthDependencies = {},
): Promise<Response> {
  const accountRequest = await (dependencies.buildRequest ?? accountBackendRequest)(apiBaseUrl, refererPath);
  return (dependencies.fetchAccount ?? fetch)(`${accountRequest.resolvedApiBaseUrl}${path}`, {
    headers: accountRequest.headers,
    cache: "no-store",
    // A redirect must never replay the session cookie to another target.
    redirect: "manual",
    signal: AbortSignal.timeout(dependencies.timeoutMs ?? 10_000),
  });
}

export async function loadHeaderAccountProfile(
  dependencies: AccountAuthDependencies = {},
): Promise<AccountAuthResult> {
  const request = await (dependencies.buildRequest ?? accountBackendRequest)(accountApiBaseUrl(), "/");
  const sessionCookieName = process.env.BACKEND_SESSION_COOKIE ?? "cms-session";
  const hasSession = (request.headers.Cookie ?? "").split(";").some((cookie) => {
    const separator = cookie.indexOf("=");
    return separator > 0 && cookie.slice(0, separator).trim() === sessionCookieName
      && cookie.slice(separator + 1).trim() !== "";
  });
  if (!hasSession) return { status: "unauthenticated" };

  const result = await loadAccountProfile(accountApiBaseUrl(), "/", {
    ...dependencies,
    buildRequest: async () => request,
    timeoutMs: 1_000,
  });
  if (result.status !== "authenticated") return result;

  // Only serialize the identity used by the header, not the entire account response.
  const profile = result.profile;
  return {
    status: "authenticated",
    profile: {
      id: profile.id,
      name: profile.name?.trim() || profile.email?.trim() || profile.phone?.trim() || "Профиль",
      avatar_url: profile.avatar_url,
      role: profile.role,
      impersonation: profile.impersonation,
    },
  };
}

export async function accountBackendRequest(_apiBaseUrl: string, refererPath: string): Promise<AccountBackendRequest> {
  const requestHeaders = await headers();
  const cookieHeader = requestHeaders.get("cookie") ?? "";
  // Request host headers may describe Sanctum metadata, never the upstream.
  const resolvedApiBaseUrl = backendApiBaseUrl();
  const frontendOrigin = frontendOriginFromHeaders(requestHeaders);

  return {
    resolvedApiBaseUrl,
    headers: {
      Accept: "application/json",
      Cookie: cookieHeader,
      ...(frontendOrigin !== ""
        ? {
            Origin: frontendOrigin,
            Referer: `${frontendOrigin}${refererPath}`,
          }
        : {}),
    },
  };
}
