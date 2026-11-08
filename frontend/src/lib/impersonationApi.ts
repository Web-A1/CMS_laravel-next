import { clientAccountApiBaseUrl, readXsrfToken } from "./clientAccount";
import { parseImpersonationContext, type ImpersonationContext } from "./impersonationContract";

type Fetcher = typeof fetch;

type ImpersonationDependencies = {
  readonly fetcher?: Fetcher;
  readonly cookie?: string;
};

export class ImpersonationApiError extends Error {
  readonly status: number;

  constructor(message: string, status = 0) {
    super(message);
    this.name = "ImpersonationApiError";
    this.status = status;
  }
}

function browserCookie(): string {
  return typeof document === "undefined" ? "" : document.cookie;
}

function errorMessage(payload: unknown, fallback: string): string {
  if (typeof payload !== "object" || payload === null || Array.isArray(payload)) {
    return fallback;
  }

  const error = (payload as Record<string, unknown>).error;
  if (typeof error !== "object" || error === null || Array.isArray(error)) {
    return fallback;
  }

  const message = (error as Record<string, unknown>).message;
  return typeof message === "string" && message.trim() !== "" ? message : fallback;
}

async function readJson(response: Response): Promise<unknown> {
  return response.json().catch(() => null);
}

async function csrfToken(base: string, dependencies: ImpersonationDependencies): Promise<string> {
  const fetcher = dependencies.fetcher ?? fetch;
  const response = await fetcher(`${base}/sanctum/csrf-cookie`, {
    method: "GET",
    credentials: "include",
    cache: "no-store",
    headers: { Accept: "application/json" },
  });
  if (!response.ok) {
    throw new ImpersonationApiError("Не удалось подготовить сессию. Обновите страницу и повторите.", response.status);
  }

  const token = readXsrfToken(dependencies.cookie ?? browserCookie());
  if (token === "") {
    throw new ImpersonationApiError("Сессия недоступна. Войдите в админку снова.", 419);
  }

  return token;
}

async function mutate(
  base: string,
  path: string,
  body: unknown,
  fallback: string,
  dependencies: ImpersonationDependencies,
): Promise<ImpersonationContext> {
  const fetcher = dependencies.fetcher ?? fetch;
  const token = await csrfToken(base, dependencies);
  const response = await fetcher(`${base}${path}`, {
    method: "POST",
    credentials: "include",
    cache: "no-store",
    headers: {
      Accept: "application/json",
      "Content-Type": "application/json",
      "X-XSRF-TOKEN": token,
    },
    body: JSON.stringify(body),
  });
  const payload = await readJson(response);
  if (!response.ok) {
    throw new ImpersonationApiError(errorMessage(payload, fallback), response.status);
  }

  const parsed = parseImpersonationContext(payload);
  if (!parsed) {
    throw new ImpersonationApiError("Ответ сервера не распознан. Обновите страницу.", response.status);
  }

  return parsed;
}

export async function loadImpersonationContext(
  apiBaseUrl = clientAccountApiBaseUrl(),
  dependencies: ImpersonationDependencies = {},
): Promise<ImpersonationContext | null> {
  try {
    const base = apiBaseUrl.replace(/\/+$/, "");
    const response = await (dependencies.fetcher ?? fetch)(`${base}/api/v1/impersonation`, {
      method: "GET",
      credentials: "include",
      cache: "no-store",
      headers: { Accept: "application/json" },
    });
    if (!response.ok) return null;

    return parseImpersonationContext(await readJson(response));
  } catch {
    return null;
  }
}

export async function startImpersonation(
  userId: number,
  returnPath: string,
  apiBaseUrl = clientAccountApiBaseUrl(),
  dependencies: ImpersonationDependencies = {},
): Promise<ImpersonationContext> {
  if (!Number.isSafeInteger(userId) || userId < 1) {
    throw new ImpersonationApiError("Пользователь не найден.", 422);
  }

  const base = apiBaseUrl.replace(/\/+$/, "");
  return mutate(
    base,
    `/api/v1/admin/users/${encodeURIComponent(String(userId))}/impersonation`,
    { return_path: returnPath },
    "Не удалось открыть просмотр пользователя.",
    dependencies,
  );
}

export async function stopImpersonation(
  apiBaseUrl = clientAccountApiBaseUrl(),
  dependencies: ImpersonationDependencies = {},
): Promise<ImpersonationContext> {
  const base = apiBaseUrl.replace(/\/+$/, "");
  return mutate(
    base,
    "/api/v1/impersonation/stop",
    {},
    "Не удалось вернуться в админку.",
    dependencies,
  );
}
