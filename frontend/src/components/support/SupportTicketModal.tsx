"use client";

import { useEffect, useRef, useState, type ClipboardEvent, type FormEvent, type ReactNode } from "react";
import { createPortal } from "react-dom";
import { clipboardHasImage } from "./supportTicket";
import { createSupportVoice, EMPTY_VOICE, voiceBusy } from "./supportVoice";
import SupportVoicePlayer from "./SupportVoicePlayer";
import Button from "@/components/button/Button";
import CloseButton from "@/components/button/CloseButton";
import { FormError } from "@/components/form/FormError";
import { FormStatus } from "@/components/form/FormStatus";
import { browserApiBaseUrl } from "@/lib/apiBaseUrl";
import { imageSelectionError, submitSupportTicket, supportAttemptKey, validateSupportDraft, SUPPORT_IMAGE_ACCEPT, SUPPORT_MAX_IMAGES, type SupportFields, type SupportResult } from "./supportTicket";

type SelectedImage = { id: string; file: File; url: string };
const inputClass = "ui-input w-full rounded-[var(--radius-control)] border px-3 py-2 text-base placeholder:text-sm outline-none";

function SupportDialog({ children, onClose }: { children: ReactNode; onClose: () => void }) {
  const dialogRef = useRef<HTMLDialogElement>(null);
  useEffect(() => {
    const dialog = dialogRef.current;
    const previousFocus = document.activeElement;
    const previousOverflow = document.body.style.overflow;
    document.body.style.overflow = "hidden";
    dialog?.showModal();
    return () => {
      dialog?.close();
      document.body.style.overflow = previousOverflow;
      if (previousFocus instanceof HTMLElement && previousFocus.isConnected) previousFocus.focus({ preventScroll: true });
    };
  }, []);
  return createPortal(
    <dialog ref={dialogRef} aria-labelledby="support-title" aria-describedby="support-intro" data-ui="support.modal" data-lenis-prevent
      className="ui-text-primary m-auto max-h-[calc(100dvh-2rem)] w-[calc(100%-2rem)] max-w-[30rem] overflow-y-auto overscroll-contain rounded-[var(--radius-surface)] border border-[var(--color-border-subtle)] bg-[var(--color-surface)] p-5 shadow-[var(--shadow-modal)] backdrop:bg-[var(--color-modal-overlay)] sm:p-6"
      onCancel={(event) => { event.preventDefault(); onClose(); }}
      onClick={(event) => { if (event.target === event.currentTarget) { const bounds = event.currentTarget.getBoundingClientRect(); if (event.clientX < bounds.left || event.clientX > bounds.right || event.clientY < bounds.top || event.clientY > bounds.bottom) onClose(); } }}>
      <div className="mb-1.5 flex items-center justify-between gap-3">
        <h2 id="support-title" className="text-xl font-bold">Написать в поддержку</h2>
        <CloseButton onClick={onClose} aria-label="Закрыть форму поддержки" data-ui="support.close" />
      </div>
      {children}
    </dialog>, document.body,
  );
}

export default function SupportTicketModal({ triggerDataUi = "home.faq.supportButton", triggerVariant = "button" }: { readonly triggerDataUi?: string; readonly triggerVariant?: "button" | "text" }) {
  const [open, setOpen] = useState(false);
  const [email, setEmail] = useState("");
  const [description, setDescription] = useState("");
  const [images, setImages] = useState<SelectedImage[]>([]);
  const [selectionErrors, setSelectionErrors] = useState<string[]>([]);
  const [fields, setFields] = useState<SupportFields>({});
  const [result, setResult] = useState<SupportResult | null>(null);
  const [pending, setPending] = useState(false);
  const [dragging, setDragging] = useState(false);
  const [readingClipboard, setReadingClipboard] = useState(false);
  const [clipboardImageAvailable, setClipboardImageAvailable] = useState(false);
  const [voice, setVoice] = useState(EMPTY_VOICE);
  const voiceSession = useRef<ReturnType<typeof createSupportVoice> | null>(null);
  if (voiceSession.current === null) { voiceSession.current = createSupportVoice(setVoice); }
  const selectedImages = useRef<SelectedImage[]>([]);
  const clipboardRequest = useRef(0);
  const clipboardBusy = useRef(false);
  const attempt = useRef<string | null>(null);
  const submitting = useRef(false);
  const urls = useRef(new Set<string>());
  const website = useRef<HTMLInputElement>(null);
  const formRef = useRef<HTMLFormElement>(null);
  const successCloseRef = useRef<HTMLButtonElement>(null);
  const imageInputRef = useRef<HTMLInputElement>(null);
  const imageErrors = [...selectionErrors, ...Object.entries(fields)
    .filter(([key]) => key === "images" || key.startsWith("images."))
    .flatMap(([, errors]) => errors)];
  useEffect(() => {
    if (open && result?.ok) successCloseRef.current?.focus();
  }, [open, result?.ok]);

  useEffect(() => {
    const currentUrls = urls.current;
    const request = clipboardRequest;
    return () => { request.current++; voiceSession.current?.clear(false); currentUrls.forEach((url) => URL.revokeObjectURL(url)); currentUrls.clear(); };
  }, []);

  useEffect(() => {
    if (!open || result?.ok) return;
    let active = true;
    let checking = false;
    async function checkClipboard() {
      if (checking || document.hidden || !document.hasFocus()) return;
      checking = true;
      const available = await clipboardHasImage(navigator.clipboard, navigator.permissions);
      if (active) setClipboardImageAvailable(available);
      checking = false;
    }
    void checkClipboard();
    window.addEventListener("focus", checkClipboard);
    const timer = window.setInterval(checkClipboard, 1500);
    return () => { active = false; window.clearInterval(timer); window.removeEventListener("focus", checkClipboard); };
  }, [open, result?.ok]);

  function updateImages(next: SelectedImage[]) {
    selectedImages.current = next;
    setImages(next);
  }

  function close() {
    if (voiceSession.current?.getState().phase !== "idle") { voiceSession.current?.clear(); attempt.current = null; setFields({}); setResult(null); }
    clipboardRequest.current++;
    clipboardBusy.current = false;
    setReadingClipboard(false);
    setClipboardImageAvailable(false);
    setDragging(false);
    setOpen(false);
  }

  function edited() {
    attempt.current = null;
    setFields({});
    setResult(null);
  }

  function selectFiles(files: FileList | readonly File[] | null) {
    if (!files || submitting.current) return;
    const added: SelectedImage[] = [];
    const errors: string[] = [];
    for (const file of Array.from(files)) {
      const error = imageSelectionError(file);
      if (error) { errors.push(`${file.name}: ${error}`); continue; }
      if (selectedImages.current.length + added.length >= SUPPORT_MAX_IMAGES) { errors.push(`${file.name}: можно прикрепить не более пяти изображений.`); continue; }
      const url = URL.createObjectURL(file);
      urls.current.add(url);
      added.push({ id: crypto.randomUUID(), file, url });
    }
    if (added.length) { edited(); updateImages([...selectedImages.current, ...added]); }
    setSelectionErrors(errors);
  }

  function pasteImage(event: ClipboardEvent<HTMLTextAreaElement>) {
    const files = Array.from(event.clipboardData.files).filter((file) => file.type.startsWith("image/"));
    if (!files.length) return;
    event.preventDefault();
    selectFiles(files);
  }

  async function pasteFromClipboard() {
    if (submitting.current || clipboardBusy.current) return;
    const fallback = "Не удалось прочитать буфер. Вставьте скриншот в поле сообщения через Ctrl+V (Cmd+V на Mac) или выберите файл.";
    if (!navigator.clipboard?.read) { setSelectionErrors([fallback]); return; }
    const request = ++clipboardRequest.current;
    clipboardBusy.current = true;
    setReadingClipboard(true);
    setSelectionErrors([]);
    try {
      const items = await navigator.clipboard.read();
      const files: File[] = [];
      for (const item of items) {
        const type = item.types.find((type) => type.startsWith("image/"));
        if (!type) continue;
        const blob = await item.getType(type);
        const extension = type === "image/jpeg" ? "jpg" : type.split("/")[1];
        files.push(new File([blob], `screenshot-${files.length + 1}.${extension}`, { type }));
      }
      if (request !== clipboardRequest.current) return;
      if (files.length) selectFiles(files);
      else setSelectionErrors(["В буфере нет изображения. Скопируйте скриншот и попробуйте ещё раз."]);
    } catch {
      if (request === clipboardRequest.current) setSelectionErrors([fallback]);
    } finally {
      if (request === clipboardRequest.current) {
        clipboardBusy.current = false;
        setReadingClipboard(false);
      }
    }
  }

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (submitting.current || clipboardBusy.current || voiceBusy(voiceSession.current?.getState() ?? EMPTY_VOICE)) return;
    const draft = { email, description, images: images.map((image) => image.file), voice: voiceSession.current?.getState().file };
    const invalid = validateSupportDraft(draft);
    setFields(invalid);
    if (Object.keys(invalid).length) {
      setResult({ ok: false, message: "Заполните обязательные поля.", fields: invalid });
      requestAnimationFrame(() => formRef.current?.querySelector<HTMLElement>('[aria-invalid="true"]')?.focus());
      return;
    }
    submitting.current = true;
    setPending(true);
    setResult(null);
    attempt.current = supportAttemptKey(attempt.current);
    const response = await submitSupportTicket(browserApiBaseUrl(), draft, attempt.current, website.current?.value);
    setResult(response);
    setFields(response.ok ? {} : response.fields);
    setPending(false);
    submitting.current = false;
    if (response.ok) {
      voiceSession.current?.clear();
      urls.current.forEach((url) => URL.revokeObjectURL(url));
      urls.current.clear();
      updateImages([]);
      setEmail("");
      setDescription("");
      setSelectionErrors([]);
      attempt.current = null;
    } else requestAnimationFrame(() => formRef.current?.querySelector<HTMLElement>('[aria-invalid="true"]')?.focus());
  }

  return <>
    {triggerVariant === "text" ? (
      <button type="button" className="ui-text-link ui-text-link--muted ui-text-link--interactive min-h-5 text-sm" data-ui={triggerDataUi} onClick={() => setOpen(true)}>Написать в поддержку</button>
    ) : (
      <Button type="button" fullWidth={false} data-ui={triggerDataUi} onClick={() => setOpen(true)}>Написать в поддержку</Button>
    )}
    {open ? <SupportDialog onClose={close}>
      <p id="support-intro" className="mb-6 text-sm ui-text-secondary">Ответ поступит на указанную электронную почту</p>
      {result?.ok ? <div className="space-y-4" data-ui="support.success">
        <FormStatus inset={false} tone="success">Обращение {result.number} принято. Продолжить переписку можно по email.</FormStatus>
        {result.warnings.map((warning, index) => <FormStatus inset={false} key={`${warning.index}-${index}`}>Вложение {warning.index + 1}: {warning.message}</FormStatus>)}
        <Button ref={successCloseRef} type="button" fullWidth={false} onClick={close} data-ui="support.success.close">Закрыть</Button>
      </div> : <form ref={formRef} noValidate onSubmit={submit} className="space-y-4" data-ui="support.form" aria-busy={pending}>
        <div className="space-y-1.5">
          <label htmlFor="support-email" className="sr-only">Email для ответа</label>
          <input id="support-email" name="email" type="email" autoComplete="email" inputMode="email" placeholder="Email для ответа" required maxLength={254} value={email} disabled={pending}
            className={`${inputClass} h-10`} data-ui="support.email" aria-invalid={Boolean(fields.email?.length)} aria-describedby={fields.email?.length ? "support-email-error" : undefined}
            onChange={(event) => { edited(); setEmail(event.target.value); }} />
          <FormError id="support-email-error" inset={false}>{fields.email?.join(" ")}</FormError>
        </div>
        <div className="space-y-1.5">
          <label htmlFor="support-description" className="sr-only">С чем нужна помощь?</label>
          <div className="relative" data-ui="support.description.wrapper">
          <textarea id="support-description" name="description" placeholder="С чем нужна помощь?" rows={3} required={!voice.file} maxLength={20000} value={description} disabled={pending}
            onPaste={pasteImage}
            className={`${inputClass} block h-26 min-h-26 resize-y pb-12`} data-ui="support.description" aria-invalid={Boolean(fields.description?.length)} aria-describedby={fields.description?.length ? "support-description-error" : undefined}
            onChange={(event) => { edited(); setDescription(event.target.value); }} />
          {!voice.file ? <div className="absolute right-3 bottom-2 flex max-w-[calc(100%-1.5rem)] items-center gap-1.5" data-ui="support.voice.controls">
            {voiceBusy(voice) ? <button type="button" data-ui="support.voice.cancel" aria-label="Отменить запись" className="inline-flex size-7 shrink-0 items-center justify-center rounded-full ui-text-secondary hover:bg-[var(--color-surface-neutral)] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--color-brand-ink)]" onClick={() => { edited(); voiceSession.current?.clear(); }}>
              <svg className="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" aria-hidden="true"><path d="m6 6 12 12M6 18 18 6" /></svg>
            </button> : null}
            <div className="flex h-9 items-center gap-2 rounded-full bg-[var(--color-surface-neutral)] px-1 ui-text-secondary" data-ui="support.voice.pill">
              {voiceBusy(voice) ? <>
                <span className="pl-2 text-xs tabular-nums" data-ui="support.voice.timer">{Math.floor(voice.seconds / 60)}:{String(voice.seconds % 60).padStart(2, "0")}</span>
                <span className="flex h-4 items-center gap-0.5" aria-hidden="true" data-ui="support.voice.activity">
                  {["h-1.5", "h-3", "h-4", "h-2", "h-3"].map((height, index) => <span key={height + index} className={`w-0.5 rounded-full bg-current ${height} ${voice.phase === "recording" ? "motion-safe:animate-pulse" : ""}`} style={{ animationDelay: `${index * 120}ms` }} />)}
                </span>
              </> : null}
              <button type="button" aria-label={voice.phase === "recording" ? "Остановить запись" : "Записать голосовое сообщение"} data-ui={voice.phase === "recording" ? "support.voice.stop" : "support.voice.record"} disabled={pending || voice.phase === "requesting" || voice.phase === "stopping"} aria-describedby={voice.error ? "support-voice-error" : undefined}
                className="inline-flex size-7 shrink-0 items-center justify-center rounded-full hover:bg-[var(--color-surface-pressed)] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--color-brand-ink)] disabled:opacity-50"
                onClick={() => { if (voice.phase === "recording") voiceSession.current?.stop(); else { edited(); void voiceSession.current?.start(); } }}>
                {voice.phase === "recording" ? <span className="size-3 rounded-sm bg-[var(--color-danger)]" aria-hidden="true" /> : <svg className="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" aria-hidden="true"><rect x="9" y="2" width="6" height="12" rx="3" /><path d="M5 10v2a7 7 0 0 0 14 0v-2M12 19v3m-4 0h8" /></svg>}
              </button>
            </div>
            <span role="status" className="sr-only" data-ui="support.voice.status">{voice.phase === "requesting" ? "Ожидаем доступ к микрофону…" : voice.phase === "stopping" ? "Завершаем запись…" : voice.phase === "recording" ? "Идёт запись. Максимум три минуты." : ""}</span>
          </div> : null}
          </div>
          <FormError id="support-description-error" inset={false}>{fields.description?.join(" ")}</FormError>
          <div data-ui="support.voice" className="space-y-2">
            {voice.file && voice.url ? <div className="flex items-start gap-1 rounded-[var(--radius-control)] bg-[var(--color-surface)] py-2" data-ui="support.voice.preview">
              <SupportVoicePlayer key={voice.url} url={voice.url} seconds={voice.seconds} disabled={pending} invalid={Boolean(fields.voice?.length)} describedBy={fields.voice?.length ? "support-voice-error" : undefined} />
              <Button type="button" variant="utility" size="sm" fullWidth={false} disabled={pending} data-contour="hover" data-ui="support.voice.remove" aria-label="Удалить голосовое сообщение" onClick={() => { edited(); voiceSession.current?.clear(); }}><span className="text-xs ui-text-danger">Удалить</span></Button>
            </div> : null}
            <FormError id="support-voice-error" inset={false} dataUi="support.voice.error">{[voice.error, ...(fields.voice ?? [])].filter(Boolean).join(" ")}</FormError>
          </div>
        </div>
        <section aria-label="Скриншоты" data-ui="support.dropzone" data-state={dragging ? "dragging" : "idle"}
          className="space-y-4"
          onDragOver={(event) => {
            if (!Array.from(event.dataTransfer.types).includes("Files")) return;
            event.preventDefault();
            event.dataTransfer.dropEffect = pending || images.length >= SUPPORT_MAX_IMAGES ? "none" : "copy";
            setDragging(!pending && images.length < SUPPORT_MAX_IMAGES);
          }}
          onDragLeave={(event) => { if (!(event.relatedTarget instanceof Node) || !event.currentTarget.contains(event.relatedTarget)) setDragging(false); }}
          onDrop={(event) => { event.preventDefault(); setDragging(false); selectFiles(event.dataTransfer.files); }}>
          <div className={`relative flex min-h-44 flex-col items-center justify-center rounded-[var(--radius-control)] border border-dashed px-4 py-6 text-center transition-colors ${dragging ? "border-[var(--color-brand)] bg-[var(--color-brand-surface)]" : "border-[var(--color-brand)]/70"}`}>
            <button type="button" className="absolute inset-0 cursor-pointer rounded-[var(--radius-control)] transition-colors hover:bg-[var(--color-brand-surface)] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--color-brand-ink)] disabled:cursor-default disabled:hover:bg-transparent" disabled={pending || images.length >= SUPPORT_MAX_IMAGES}
              data-ui="support.attach" aria-label="Прикрепить скриншот" aria-describedby={`support-images-help${imageErrors.length ? " support-images-error" : ""}`}
              onClick={() => imageInputRef.current?.click()}><span className="sr-only">Выбрать файл</span></button>
            <span className={`pointer-events-none relative mx-auto mb-4 size-12 ui-text-secondary ${clipboardImageAvailable ? "hidden" : "block"}`} aria-hidden="true">
              <svg className="size-12" viewBox="0 0 32 36" fill="none" stroke="var(--color-border-subtle)" strokeWidth="1.5" strokeLinejoin="round">
                <path d="M8 2h11l8 8v20a4 4 0 0 1-4 4H8a4 4 0 0 1-4-4V6a4 4 0 0 1 4-4Z" />
                <path d="M19 2v8h8" />
              </svg>
              <span className="ui-text-inverse absolute -right-1 bottom-0 flex size-6 items-center justify-center rounded-full bg-[var(--color-brand-deep)]">
                <svg className="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round">
                  <path d="M7 17a5 5 0 1 1 1-9.9A7 7 0 0 1 21 11a4 4 0 0 1-2 7M12 20V10m-4 4 4-4 4 4" />
                </svg>
              </span>
            </span>
            <div className={`relative mb-4 h-12 items-center justify-center ${clipboardImageAvailable ? "flex" : "hidden"}`}>
              <Button type="button" variant="ghost" size="sm" fullWidth={false} loading={readingClipboard} disabled={pending || images.length >= SUPPORT_MAX_IMAGES}
                data-ui="support.paste" aria-describedby={imageErrors.length ? "support-images-error" : "support-images-help"}
                onClick={pasteFromClipboard}>Вставить из буфера</Button>
            </div>
            <input ref={imageInputRef} id="support-images" name="images[]" type="file" accept={SUPPORT_IMAGE_ACCEPT} multiple hidden disabled={pending || images.length >= SUPPORT_MAX_IMAGES}
              aria-label="Прикрепить скриншот" data-ui="support.images" aria-invalid={Boolean(imageErrors.length)}
              onChange={(event) => { selectFiles(event.target.files); event.target.value = ""; }} />
            <p className="pointer-events-none relative text-xs ui-text-secondary">{dragging ? "Отпустите изображение здесь" : "Перетащите изображение или выберите файл"}</p>
          </div>
          <FormError id="support-images-error" inset={false} className="mt-1.5">{imageErrors.join(" ")}</FormError>
        {images.length ? <ul className="mt-3 space-y-3" data-ui="support.previews">{images.map((image) => <li key={image.id} className="flex items-center gap-3">
            {/* Local object URLs never leave the browser; HEIC has a named fallback. */}
            {/* eslint-disable-next-line @next/next/no-img-element */}
            <img src={image.url} alt={`Предпросмотр ${image.file.name}`} className="size-10 shrink-0 rounded-[var(--radius-inner)] object-contain" onError={(event) => { event.currentTarget.hidden = true; }} />
            <span className="min-w-0 flex-1 break-words text-sm">{image.file.name}</span>
            <Button type="button" variant="ghost" size="sm" fullWidth={false} disabled={pending} aria-label={`Удалить ${image.file.name}`} onClick={() => {
              edited(); URL.revokeObjectURL(image.url); urls.current.delete(image.url); updateImages(selectedImages.current.filter((selected) => selected.id !== image.id)); setSelectionErrors([]);
            }}>Удалить</Button>
          </li>)}</ul> : null}
        </section>
        <div hidden aria-hidden="true"><label htmlFor="support-website">Сайт</label><input ref={website} id="support-website" name="website" tabIndex={-1} autoComplete="off" /></div>
        <FormError inset={false} dataUi="support.error">{result && !result.ok ? result.message : null}</FormError>
        <FormStatus inset={false} tone="pending">{pending ? "Отправляем обращение…" : null}</FormStatus>
        <div className="flex items-center justify-between gap-3">
          <p id="support-images-help" className="flex min-w-0 items-start gap-1.5 text-xs leading-normal ui-text-secondary">
            <svg className="mt-0.5 size-3.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.6" aria-hidden="true">
              <circle cx="12" cy="12" r="9" /><path d="M12 11v6m0-10v2" />
            </svg>
            <span className="flex min-w-0 flex-col text-left">
              <span>JPG, PNG, WebP, AVIF, HEIC/HEIF</span>
              <span>До 5 файлов · по 10 МБ</span>
            </span>
          </p>
          <Button type="submit" fullWidth={false} className="shrink-0" loading={pending} disabled={readingClipboard || voiceBusy(voice)} data-ui="support.submit">Отправить</Button>
        </div>
      </form>}
    </SupportDialog> : null}
  </>;
}
