import { readXsrfToken } from "./clientAccount";

export type ActivationCredential = { activation_id: string; capability: string };
export type ActivationView = {
  activation_id: string;
  step: "email" | "password" | "phone" | "complete";
  status: "pending";
  expires_at: string;
  verification_id: string | null;
  phone_status: "pending" | "confirmed" | "failed" | "expired" | null;
  call_number: string | null;
  phone: string | null;
};
export class ActivationError extends Error {
  constructor(message: string, public readonly status: number) { super(message); }
}
const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;
function record(value: unknown): value is Record<string, unknown> {
  return typeof value === "object" && value !== null && !Array.isArray(value);
}
export function parseActivationCredential(value: unknown): ActivationCredential {
  if (!record(value) || typeof value.activation_id !== "string" || !UUID.test(value.activation_id)
    || typeof value.capability !== "string" || !/^[A-Za-z0-9]{64}$/.test(value.capability)) {
    throw new Error("Некорректное подтверждение профиля");
  }
  return { activation_id: value.activation_id, capability: value.capability };
}
export function parseActivationView(value: unknown): ActivationView {
  if (!record(value) || typeof value.activation_id !== "string" || !UUID.test(value.activation_id)
    || value.status !== "pending" || !["email", "password", "phone", "complete"].includes(String(value.step))
    || typeof value.expires_at !== "string" || !Number.isFinite(Date.parse(value.expires_at))
    || !(value.verification_id === null || (typeof value.verification_id === "string" && UUID.test(value.verification_id)))
    || !(value.phone_status === null || ["pending", "confirmed", "failed", "expired"].includes(String(value.phone_status)))
    || !(value.call_number === null || (typeof value.call_number === "string" && /^\+?[\d ()-]{5,30}$/.test(value.call_number)))
    || !(value.phone === null || (typeof value.phone === "string" && /^\+[1-9]\d{7,14}$/.test(value.phone)))) {
    throw new Error("Не удалось прочитать состояние подтверждения");
  }
  return {
    activation_id: value.activation_id, status: "pending", step: value.step as ActivationView["step"],
    expires_at: value.expires_at, verification_id: value.verification_id as string | null,
    phone_status: value.phone_status as ActivationView["phone_status"], call_number: value.call_number as string | null,
    phone: value.phone as string | null,
  };
}
export async function activationRequest(base: string, action: string, body: Record<string, unknown>): Promise<unknown> {
  const csrf = await fetch(`${base}/sanctum/csrf-cookie`, { credentials: "include", headers: { Accept: "application/json" } });
  if (!csrf.ok) throw new ActivationError("Не удалось начать подтверждение. Повторите попытку", csrf.status);
  const xsrf = readXsrfToken(document.cookie);
  const response = await fetch(`${base}/api/v1/auth/legacy-activation/${action}`, {
    method: "POST", credentials: "include",
    headers: { "Content-Type": "application/json", Accept: "application/json", ...(xsrf ? { "X-XSRF-TOKEN": xsrf } : {}) },
    body: JSON.stringify(body),
  });
  const payload: unknown = await response.json();
  if (!response.ok) {
    const error = record(payload) && record(payload.error) ? payload.error : null;
    const message = error && typeof error.message === "string" ? error.message
      : "Не удалось подтвердить профиль. Проверьте данные и повторите попытку";
    throw new ActivationError(message, response.status);
  }
  return payload;
}
export function isActivationCompleted(value: unknown): boolean {
  return record(value) && value.status === "completed" && value.step === "done" && value.authenticated === true && value.next_step === "lk";
}
