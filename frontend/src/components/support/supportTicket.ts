export const SUPPORT_MAX_IMAGES = 5;

// Do not prompt for permission merely to choose which control to show.
export async function clipboardHasImage(clipboard: Pick<Clipboard, "read"> | undefined, permissions: Pick<Permissions, "query"> | undefined): Promise<boolean> {
  if (!clipboard?.read || !permissions?.query) return false;
  try {
    const permission = await permissions.query({ name: "clipboard-read" as PermissionName });
    if (permission.state !== "granted") return false;
    return (await clipboard.read()).some((item) => item.types.some((type) => type.startsWith("image/")));
  } catch { return false; }
}
export const SUPPORT_MAX_IMAGE_BYTES = 10 * 1024 * 1024;
export const SUPPORT_IMAGE_ACCEPT = ".jpg,.jpeg,.png,.webp,.avif,.heic,.heif,image/jpeg,image/png,image/webp,image/avif,image/heic,image/heif";

export type SupportDraft = { email: string; description: string; images: readonly File[]; voice?: File };
export type SupportFields = Record<string, string[]>;
export type SupportResult =
  | { ok: true; number: string; warnings: readonly { index: number; message: string }[] }
  | { ok: false; message: string; fields: SupportFields };

export function imageSelectionError(file: Pick<File, "name" | "type" | "size">): string | null {
  if (file.size > SUPPORT_MAX_IMAGE_BYTES) return "Размер файла превышает 10 МиБ.";
  const allowedMime = /^image\/(jpeg|png|webp|avif|heic|heif)$/i.test(file.type);
  const allowedExtension = /\.(jpe?g|png|webp|avif|heic|heif)$/i.test(file.name);
  if (!allowedMime && !(file.type === "" && allowedExtension)) return "Выберите фотографию или скриншот: JPEG, PNG, WebP, AVIF или HEIC/HEIF. Видео не принимается.";
  return null;
}

export function validateSupportDraft(draft: SupportDraft): SupportFields {
  const fields: SupportFields = {};
  if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(draft.email.trim()) || draft.email.trim().length > 254) fields.email = ["Укажите корректный email для ответа."];
  if (!draft.description.trim() && !draft.voice) fields.description = ["Опишите, с чем нужна помощь, или запишите голосовое сообщение."];
  else if (draft.description.length > 20000) fields.description = ["Описание должно содержать не более 20 000 символов."];
  return fields;
}

// A retry keeps its key. Any edit invalidates the attempt, even if later undone.
export function supportAttemptKey(previous: string | null): string {
  return previous ?? crypto.randomUUID();
}

function record(value: unknown): value is Record<string, unknown> {
  return typeof value === "object" && value !== null;
}

export async function submitSupportTicket(baseUrl: string, draft: SupportDraft, key: string, website = "", request: typeof fetch = fetch): Promise<SupportResult> {
  const body = new FormData();
  body.set("email", draft.email.trim());
  body.set("description", draft.description);
  body.set("website", website);
  draft.images.forEach((file) => body.append("images[]", file));
  if (draft.voice) body.set("voice", draft.voice);
  try {
    const response = await request(`${baseUrl}/api/v1/support-tickets`, {
      method: "POST", body, headers: { Accept: "application/json", "Idempotency-Key": key }, cache: "no-store", credentials: "omit",
    });
    const payload: unknown = await response.json().catch(() => null);
    if (response.status === 201 && record(payload) && record(payload.data) && typeof payload.data.number === "string") {
      const warnings = Array.isArray(payload.data.warnings) ? payload.data.warnings.flatMap((warning: unknown) =>
        record(warning) && typeof warning.index === "number" && typeof warning.message === "string" ? [{ index: warning.index, message: warning.message }] : []) : [];
      return { ok: true, number: payload.data.number, warnings };
    }
    let message = "Не удалось отправить обращение. Попробуйте ещё раз: текст и файлы сохранены.";
    if (response.status === 422) message = "Проверьте поля формы. Обращение пока не отправлено.";
    if (response.status === 409) message = "Не удалось подтвердить отправку. Повторите попытку. Если ошибка сохраняется, измените текст обращения.";
    if (response.status === 413) message = "Общий размер вложений превышает лимит отправки. Уменьшите размер или число файлов и попробуйте снова.";
    if (response.status === 429) {
      const retry = response.headers.get("Retry-After") ?? (record(payload) && typeof payload.retry_after === "number" ? String(payload.retry_after) : null);
      const seconds = retry && /^\d+$/.test(retry) ? Number(retry) : null;
      message = seconds !== null ? `Слишком много обращений. Повторите попытку через ${Math.max(1, Math.ceil(seconds / 60))} мин. Текст и файлы сохранены.` : "Слишком много обращений. Попробуйте позже. Текст и файлы сохранены.";
    }
    const fields: SupportFields = {};
    if (record(payload) && record(payload.error) && record(payload.error.fields)) {
      for (const [name, errors] of Object.entries(payload.error.fields)) {
        if (Array.isArray(errors)) fields[name] = errors.filter((error): error is string => typeof error === "string");
      }
    }
    return { ok: false, message, fields };
  } catch {
    return { ok: false, message: "Не удалось связаться с сервером. Проверьте соединение и повторите отправку. Текст и файлы сохранены.", fields: {} };
  }
}
