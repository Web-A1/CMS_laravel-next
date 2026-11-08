const TRUSTED_AUTH_ORIGIN = "https://auth-navigation.invalid";
const UNSAFE_PATH_CHARACTERS = /[\u0000-\u001F\u007F\\]/;
const ENCODED_BACKSLASH = /%5c/i;

export const AUTH_RETURN_TO_HEADER = "x-cms-auth-return-to";

export type AuthRedirectMode = "login" | "verify-phone";
export type LoginNextStep = "admin" | "lk" | "verify_phone";

function isSafeRootRelativePath(path: string): boolean {
  return path.startsWith("/") && !path.startsWith("//");
}

export function normalizeAuthNextPath(rawValue: string | null | undefined, fallback = "/lk"): string {
  if (
    typeof rawValue !== "string" ||
    rawValue === "" ||
    rawValue.trim() !== rawValue ||
    !isSafeRootRelativePath(rawValue) ||
    rawValue.startsWith("//") ||
    UNSAFE_PATH_CHARACTERS.test(rawValue) ||
    ENCODED_BACKSLASH.test(rawValue)
  ) {
    return fallback;
  }

  try {
    const url = new URL(rawValue, TRUSTED_AUTH_ORIGIN);
    if (url.origin !== TRUSTED_AUTH_ORIGIN) {
      return fallback;
    }

    const normalizedPath = `${url.pathname}${url.search}${url.hash}`;
    return isSafeRootRelativePath(normalizedPath) ? normalizedPath : fallback;
  } catch {
    return fallback;
  }
}

export function buildLkAuthReturnPath(pathname: string, searchParams: URLSearchParams): string {
  const forwardedSearchParams = new URLSearchParams(searchParams);
  forwardedSearchParams.delete("_rsc");

  const query = forwardedSearchParams.toString();
  const normalizedPath = normalizeAuthNextPath(`${pathname}${query === "" ? "" : `?${query}`}`);
  const normalizedUrl = new URL(normalizedPath, TRUSTED_AUTH_ORIGIN);

  return normalizedUrl.pathname === "/lk" || normalizedUrl.pathname.startsWith("/lk/")
    ? normalizedPath
    : "/lk";
}

export function normalizeAdminAuthNextPath(rawValue: string | null | undefined): string {
  const normalizedPath = normalizeAuthNextPath(rawValue, "/admin");
  const normalizedUrl = new URL(normalizedPath, TRUSTED_AUTH_ORIGIN);

  return normalizedUrl.pathname === "/admin" || normalizedUrl.pathname.startsWith("/admin/")
    ? normalizedPath
    : "/admin";
}

export function buildAdminAuthReturnPath(pathname: string, searchParams: URLSearchParams): string {
  const forwardedSearchParams = new URLSearchParams(searchParams);
  forwardedSearchParams.delete("_rsc");

  const query = forwardedSearchParams.toString();
  return normalizeAdminAuthNextPath(`${pathname}${query === "" ? "" : `?${query}`}`);
}

export function buildLkAuthRequestHeaders(
  incomingHeaders: Headers,
  pathname: string,
  searchParams: URLSearchParams,
): Headers {
  const requestHeaders = new Headers(incomingHeaders);
  requestHeaders.set(AUTH_RETURN_TO_HEADER, buildLkAuthReturnPath(pathname, searchParams));

  return requestHeaders;
}

export function buildProtectedAuthRequestHeaders(
  incomingHeaders: Headers,
  pathname: string,
  searchParams: URLSearchParams,
): Headers {
  const requestHeaders = new Headers(incomingHeaders);
  const returnPath = pathname === "/admin" || pathname.startsWith("/admin/")
    ? buildAdminAuthReturnPath(pathname, searchParams)
    : buildLkAuthReturnPath(pathname, searchParams);
  requestHeaders.set(AUTH_RETURN_TO_HEADER, returnPath);

  return requestHeaders;
}

export function buildAuthPageHref(mode: AuthRedirectMode, nextPath: string): string {
  const searchParams = new URLSearchParams({
    mode,
    next: normalizeAuthNextPath(nextPath),
  });

  return `/auth?${searchParams.toString()}`;
}

export function resolvePhoneVerificationReturnPath(mode: string, nextPath: string): string | null {
  if (mode !== "verify" && mode !== "verify-phone") {
    return null;
  }

  return normalizeAuthNextPath(nextPath);
}

export function resolveLoginDestination(nextStep: LoginNextStep, nextPath: string): string {
  const normalizedNextPath = normalizeAuthNextPath(nextPath);

  if (nextStep !== "admin") {
    if (normalizedNextPath === "/lk" || normalizedNextPath === "/account") {
      return "/lk/learning";
    }

    return normalizedNextPath;
  }

  if (normalizedNextPath === "/admin") {
    return "/admin/users";
  }

  return normalizedNextPath.startsWith("/admin/") ? normalizedNextPath : "/admin/users";
}

export function replaceWithFreshDocument(path: string): void {
  // Auth updates cookies that server components read; a document navigation avoids stale App Router state.
  window.location.replace(path);
}
