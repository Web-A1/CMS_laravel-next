export function apiUnavailableError(apiName: string, reason: string): Error {
  return new Error(`${apiName} unavailable: ${reason}`);
}

export function rethrowNextDynamicServerError(error: unknown): void {
  if (
    typeof error === "object" &&
    error !== null &&
    "digest" in error &&
    (error as { readonly digest?: unknown }).digest === "DYNAMIC_SERVER_USAGE"
  ) {
    throw error;
  }
}
