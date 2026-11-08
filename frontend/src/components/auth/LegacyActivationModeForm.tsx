"use client";

import { FormEvent, useEffect, useRef, useState } from "react";
import Button from "../button/Button";
import { FormError } from "../form/FormError";
import { FormStatus } from "../form/FormStatus";
import { FloatingInput, PasswordInput } from "./AuthUi";
import { ActivationError, activationRequest, isActivationCompleted, parseActivationCredential, parseActivationView, type ActivationCredential, type ActivationView } from "@/lib/legacyActivationApi";
import { markClientAccountAuthenticated } from "@/lib/clientAccount";
import { replaceWithFreshDocument, resolveLoginDestination } from "@/lib/authNavigation";

const STORAGE_KEY = "legacy-profile-activation";

export default function LegacyActivationModeForm({ apiBaseUrl, nextPath }: { apiBaseUrl: string; nextPath: string }) {
  const [credential, setCredential] = useState<ActivationCredential | null>(null);
  const [view, setView] = useState<ActivationView | null>(null);
  const [email, setEmail] = useState("");
  const [code, setCode] = useState("");
  const [password, setPassword] = useState("");
  const [phone, setPhone] = useState("");
  const [shown, setShown] = useState(false);
  const [busy, setBusy] = useState(true);
  const [error, setError] = useState("");
  const mounted = useRef(false);
  const step = view?.step ?? "start";

  function clearCredential() {
    sessionStorage.removeItem(STORAGE_KEY);
    setCredential(null);
    setView(null);
    setCode("");
    setPassword("");
  }

  useEffect(() => {
    mounted.current = true;
    let cancelled = false;
    void (async () => {
      try {
        const saved = sessionStorage.getItem(STORAGE_KEY);
        if (!saved) return;
        const current = parseActivationCredential(JSON.parse(saved));
        const state = parseActivationView(await activationRequest(apiBaseUrl, "status", current));
        if (state.activation_id !== current.activation_id) throw new Error("Подтверждение устарело");
        if (!cancelled) { setCredential(current); setView(state); }
      } catch (cause) {
        if (!cancelled) {
          sessionStorage.removeItem(STORAGE_KEY);
          setError(cause instanceof Error ? cause.message : "Начните подтверждение заново");
        }
      } finally { if (!cancelled) setBusy(false); }
    })();
    return () => { cancelled = true; mounted.current = false; };
  }, [apiBaseUrl]);

  useEffect(() => {
    if (!credential || view?.phone_status !== "pending" || busy) return;
    let cancelled = false;
    const timeout = window.setTimeout(async () => {
      try {
        const state = parseActivationView(await activationRequest(apiBaseUrl, "status", credential));
        if (!cancelled && state.activation_id === credential.activation_id) setView(state);
      } catch (cause) {
        if (!cancelled) {
          setError(cause instanceof Error ? cause.message : "Не удалось проверить звонок");
          if (cause instanceof ActivationError && [401, 403].includes(cause.status)) clearCredential();
          else setView((current) => current ? { ...current } : current);
        }
      }
    }, 5000);
    return () => { cancelled = true; window.clearTimeout(timeout); };
  }, [apiBaseUrl, busy, credential, view]);

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (busy) return;
    setBusy(true); setError("");
    try {
      const action = step === "start" ? "email/start" : step === "email" ? "email/verify" : step === "password" ? "password" : step === "phone" ? "phone/start" : "complete";
      const fields = step === "start" ? { email: email.trim() } : step === "email" ? { code } : step === "password" ? { password } : step === "phone" ? { phone } : {};
      const payload = await activationRequest(apiBaseUrl, action, { ...credential, ...fields });
      if (!mounted.current) return;
      if (step === "complete") {
        if (!isActivationCompleted(payload)) throw new Error("Не удалось завершить подтверждение");
        clearCredential(); markClientAccountAuthenticated();
        replaceWithFreshDocument(resolveLoginDestination("lk", nextPath));
        return;
      }
      const state = parseActivationView(payload);
      if (step === "start") {
        const current = parseActivationCredential(payload);
        sessionStorage.setItem(STORAGE_KEY, JSON.stringify(current));
        setCredential(current);
      } else if (state.activation_id !== credential?.activation_id) {
        throw new Error("Подтверждение устарело");
      }
      setView(state); setCode(""); setPassword("");
    } catch (cause) {
      if (!mounted.current) return;
      setError(cause instanceof Error ? cause.message : "Не удалось подтвердить профиль");
      if (cause instanceof ActivationError && [401, 403].includes(cause.status)) clearCredential();
      else if (credential && cause instanceof ActivationError && cause.status === 409) {
        try {
          const state = parseActivationView(await activationRequest(apiBaseUrl, "status", credential));
          if (mounted.current && state.activation_id === credential.activation_id) setView(state);
        } catch { /* Ошибка основного шага остаётся видимой. */ }
      }
    } finally { if (mounted.current) setBusy(false); }
  }

  const callHref = view?.call_number ? `tel:${view.call_number.replace(/[^\d+]/g, "")}` : null;
  return (
    <form className="mx-auto flex w-full flex-col gap-6" onSubmit={submit} data-ui="auth.legacy.form" data-step={step}>
      <div className="flex flex-col gap-2 text-center">
        <h1 className="auth-title">Первый вход на новый сайт</h1>
        <p className="auth-subtitle">{step === "start" ? "Укажите e-mail вашего прежнего профиля" : step === "email" ? "Введите код из письма" : step === "password" ? "Создайте новый пароль для входа" : step === "phone" ? "Подтвердите ваш текущий номер телефона" : "Подтверждение выполнено. Завершите вход в профиль"}</p>
      </div>
      {step === "start" ? <FloatingInput id="legacy-email" label="E-mail прежнего профиля" type="email" autoComplete="email" value={email} onChange={(event) => setEmail(event.target.value)} required maxLength={254} data-ui="auth.legacy.email" /> : null}
      {step === "email" ? <FloatingInput id="legacy-code" label="Код из письма" autoComplete="one-time-code" inputMode="numeric" pattern="[0-9]{6}" maxLength={6} value={code} onChange={(event) => setCode(event.target.value)} required data-ui="auth.legacy.code" /> : null}
      {step === "password" ? <PasswordInput id="legacy-password" label="Новый пароль" autoComplete="new-password" minLength={8} maxLength={255} required shown={shown} onToggleShown={() => setShown(!shown)} toggleDataUi="auth.legacy.passwordToggle" value={password} onChange={(event) => setPassword(event.target.value)} data-ui="auth.legacy.password" /> : null}
      {step === "phone" ? <FloatingInput id="legacy-phone" label="Текущий телефон" type="tel" autoComplete="tel" maxLength={30} required value={view?.phone_status === "pending" ? view.phone ?? phone : phone} onChange={(event) => setPhone(event.target.value)} disabled={view?.phone_status === "pending"} data-ui="auth.legacy.phone" /> : null}
      {view?.phone_status === "pending" ? <FormStatus dataUi="auth.legacy.callStatus">{callHref ? <>Позвоните на <a className="link-text" href={callHref}>{view.call_number}</a> с указанного номера. Ждём подтверждения звонка</> : "Ждём номер для звонка"}</FormStatus> : null}
      {view?.phone_status === "expired" || view?.phone_status === "failed" ? <FormStatus dataUi="auth.legacy.retryStatus">Подтверждение звонка не завершено. Повторите попытку</FormStatus> : null}
      <FormError dataUi="auth.legacy.error">{error}</FormError>
      <Button type="submit" disabled={busy || view?.phone_status === "pending"} loading={busy} label={step === "start" ? "Получить код" : step === "phone" ? "Получить номер для звонка" : step === "complete" ? "Завершить вход" : "Продолжить"} data-ui="auth.legacy.submit" />
      {credential ? <Button variant="ghost" size="sm" fullWidth={false} type="button" className="self-center" disabled={busy} onClick={clearCredential} data-ui="auth.legacy.restart">Начать заново</Button> : null}
    </form>
  );
}
