export type ImpersonationStatus = "active" | "expired" | "inactive" | "stopped";

export type ImpersonationIdentity = {
  readonly id: number;
  readonly name: string;
  readonly role: string;
};

export type ImpersonationContext = {
  readonly status: ImpersonationStatus;
  readonly read_only: boolean;
  readonly actor: ImpersonationIdentity | null;
  readonly subject: ImpersonationIdentity | null;
  readonly started_at: string | null;
  readonly expires_at: string | null;
  readonly return_path: string;
};

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === "object" && value !== null && !Array.isArray(value);
}

function isIdentity(value: unknown): value is ImpersonationIdentity {
  if (!isRecord(value)) return false;

  return Object.keys(value).length === 3
    && Object.hasOwn(value, "id")
    && Object.hasOwn(value, "name")
    && Object.hasOwn(value, "role")
    && Number.isSafeInteger(value.id)
    && (value.id as number) > 0
    && typeof value.name === "string"
    && typeof value.role === "string";
}

export function parseImpersonationContext(value: unknown): ImpersonationContext | null {
  if (!isRecord(value)) return null;

  const expectedKeys = ["status", "read_only", "actor", "subject", "started_at", "expires_at", "return_path"];
  if (Object.keys(value).length !== expectedKeys.length || !expectedKeys.every(key => Object.hasOwn(value, key))) {
    return null;
  }

  const status = value.status;
  const returnPath = value.return_path;
  if (
    (status !== "active" && status !== "expired" && status !== "inactive" && status !== "stopped")
    || typeof value.read_only !== "boolean"
    || (value.actor !== null && !isIdentity(value.actor))
    || (value.subject !== null && !isIdentity(value.subject))
    || (value.started_at !== null && typeof value.started_at !== "string")
    || (value.expires_at !== null && typeof value.expires_at !== "string")
    || typeof returnPath !== "string"
    || returnPath.length === 0
    || returnPath.length > 512
    || (returnPath !== "/admin" && !returnPath.startsWith("/admin/"))
  ) {
    return null;
  }

  return {
    status,
    read_only: value.read_only,
    actor: value.actor,
    subject: value.subject,
    started_at: value.started_at,
    expires_at: value.expires_at,
    return_path: returnPath,
  };
}
