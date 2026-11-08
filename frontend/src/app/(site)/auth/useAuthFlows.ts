import { FormEvent, useEffect, useMemo, useRef, useState } from "react";
import { useRouter } from "next/navigation";
import {
  replaceWithFreshDocument,
  resolveLoginDestination,
  resolvePhoneVerificationReturnPath,
  type LoginNextStep,
} from "@/lib/authNavigation";
import { markClientAccountAuthenticated } from "@/lib/clientAccount";
import { isCompleteRuPhone } from "@/lib/phone";

export type SubmitState = "idle" | "loading" | "redirecting";
export type AuthMode = "login" | "register" | "forgot" | "reset" | "verify-phone" | "verify" | "legacy-activation";
export type RegisterFieldErrors = {
  email?: string;
  password?: string;
  agreements?: string;
};

type LoginResponse = {
  status: "ok";
  next_step: LoginNextStep;
};

type VerifyStartResponse = {
  status: "pending";
  verification_id: string;
  status_token?: string;
  call_number?: string | null;
  expires_at?: string;
};

type VerifyStatusResponse = {
  status: "pending" | "confirmed" | "failed";
  verification_token?: string | null;
  call_number?: string | null;
  expires_at?: string;
};

type ApiError = {
  message?: string;
  errors?: Record<string, string[]>;
  error?: {
    code?: string;
    message?: string;
  };
};
type ParsedApiError = {
  message: string;
  code?: string;
};

const EMAIL_RESEND_COOLDOWN_SECONDS = 60;
const FORGOT_RESEND_COOLDOWN_SECONDS = 60;
const PHONE_STATUS_POLL_DELAYS_MS = [1000, 1500, 2000, 2500, 3000, 4000, 5000, 6000, 8000, 10000];

function createPhoneCallHref(callNumber?: string | null): string {
  if (!callNumber) {
    return "";
  }

  let normalized = callNumber.replace(/[^\d+]/g, "");
  if (!normalized) {
    return "";
  }
  if (!normalized.startsWith("+")) {
    const digitsOnly = normalized.replace(/\D/g, "");
    if (digitsOnly.length === 11 && digitsOnly.startsWith("8")) {
      normalized = `+7${digitsOnly.slice(1)}`;
    } else if (digitsOnly.length === 11 && digitsOnly.startsWith("7")) {
      normalized = `+${digitsOnly}`;
    } else if (digitsOnly.length === 10) {
      normalized = `+7${digitsOnly}`;
    } else {
      normalized = `+${digitsOnly}`;
    }
  }

  return `tel:${normalized}`;
}

function normalizeLogin(value: string): string {
  return value.trim();
}

export function isEmailReadyForVerification(value: string): boolean {
  return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value.trim());
}

export function isPhoneReadyForVerification(value: string): boolean {
  return isCompleteRuPhone(value);
}

function translateApiErrorMessage(message: string): string {
  const normalized = message.trim();
  const lower = normalized.toLowerCase();

  if (
    lower.includes("invalid credentials") ||
    lower.includes("provided credentials") ||
    lower.includes("неверный логин или пароль")
  ) {
    return "Неверный логин или пароль";
  }

  if (lower.includes("password") || lower.includes("парол")) {
    if (lower.includes("required")) return "Введите пароль";
    if (lower.includes("at least") && lower.includes("8")) return "Пароль должен содержать минимум 8 символов";
    if (lower.includes("confirmation") || lower.includes("match")) return "Подтверждение пароля не совпадает";
    if (lower.includes("incorrect") || lower.includes("invalid") || lower.includes("wrong")) {
      return "Неверный пароль";
    }
    if (lower.includes("same as current") || lower.includes("cannot be reused")) {
      return "Новый пароль должен отличаться от текущего";
    }
    return normalized;
  }

  return normalized;
}

function isPasswordFieldErrorMessage(message: string): boolean {
  const lower = message.toLowerCase();
  if (!lower.includes("парол")) {
    return false;
  }

  if (lower.includes("логин") && lower.includes("парол")) {
    return false;
  }

  return true;
}

export function useAuthFlows(params: {
  mode: AuthMode;
  passwordResetSucceeded: boolean;
  resetTokenFromUrl: string;
  resetEmailFromUrl: string;
  apiBaseUrl: string;
  nextPath: string;
}) {
  const { mode, passwordResetSucceeded, resetTokenFromUrl, resetEmailFromUrl, apiBaseUrl, nextPath } = params;
  const router = useRouter();

  const [login, setLogin] = useState("");
  const [password, setPassword] = useState("");
  const [registerEmail, setRegisterEmail] = useState("");
  const [registerPassword, setRegisterPassword] = useState("");
  const [registerPrivacyAccepted, setRegisterPrivacyAccepted] = useState(false);
  const [registerOfferAccepted, setRegisterOfferAccepted] = useState(false);
  const [registerMarketingAccepted, setRegisterMarketingAccepted] = useState(false);
  const [registerValidationActive, setRegisterValidationActive] = useState(false);
  const [forgotEmail, setForgotEmail] = useState("");
  const [resetToken, setResetToken] = useState("");
  const [resetEmail, setResetEmail] = useState("");
  const [resetPassword, setResetPassword] = useState("");
  const [showLoginPassword, setShowLoginPassword] = useState(false);
  const [showRegisterPassword, setShowRegisterPassword] = useState(false);
  const [showResetPassword, setShowResetPassword] = useState(false);
  const [verifyPhone, setVerifyPhone] = useState("");
  const [emailCode, setEmailCode] = useState("");
  const [emailVerified, setEmailVerified] = useState(false);
  const [phoneVerified, setPhoneVerified] = useState(false);
  const [emailCodeSent, setEmailCodeSent] = useState(false);
  const [phoneVerificationPending, setPhoneVerificationPending] = useState(false);
  const [emailVerificationToken, setEmailVerificationToken] = useState("");
  const [state, setState] = useState<SubmitState>("idle");
  const [error, setError] = useState("");
  const [emailServiceError, setEmailServiceError] = useState("");
  const [phoneServiceError, setPhoneServiceError] = useState("");
  const [phoneCallNumber, setPhoneCallNumber] = useState("");
  const [emailOccupied, setEmailOccupied] = useState(false);
  const [phoneOccupied, setPhoneOccupied] = useState(false);
  const [message, setMessage] = useState("");
  const [emailResendSeconds, setEmailResendSeconds] = useState(0);
  const [forgotResendSeconds, setForgotResendSeconds] = useState(0);
  const [emailSendingCode, setEmailSendingCode] = useState(false);
  const emailCodeInputRef = useRef<HTMLInputElement | null>(null);
  const emailSendActionRef = useRef<HTMLButtonElement | null>(null);

  const emailVerificationPrerequisitesReady = isEmailReadyForVerification(registerEmail);
  const passwordError = error && isPasswordFieldErrorMessage(error) ? error : "";
  const commonError = passwordError ? "" : error;
  const commonMessage = useMemo(
    () =>
      passwordResetSucceeded
        ? "Пароль обновлен. Теперь войдите с новым паролем"
        : mode !== "register" && mode !== "login" && message
          ? message
          : "",
    [message, mode, passwordResetSucceeded],
  );
  const phoneCallHref = useMemo(() => createPhoneCallHref(phoneCallNumber), [phoneCallNumber]);
  const registrationReady =
    isEmailReadyForVerification(registerEmail) &&
    Boolean(registerPassword) &&
    emailVerified &&
    registerPrivacyAccepted &&
    registerOfferAccepted;

  function getRegistrationValidationErrors(): RegisterFieldErrors {
    const errors: RegisterFieldErrors = {};

    if (!registerEmail.trim()) {
      errors.email = "Введите e-mail";
    } else if (!isEmailReadyForVerification(registerEmail)) {
      errors.email = "Введите корректный e-mail";
    } else if (!emailVerified) {
      errors.email = emailCodeSent ? "Введите код из email для подтверждения" : "Подтвердите e-mail";
    }

    if (!registerPassword) {
      errors.password = "Введите пароль";
    }

    if (!registerPrivacyAccepted || !registerOfferAccepted) {
      errors.agreements = "Отметьте обязательные согласия галочками";
    }

    return errors;
  }

  const registerFieldErrors = registerValidationActive ? getRegistrationValidationErrors() : {};

  useEffect(() => {
    if (emailResendSeconds <= 0) {
      return;
    }
    const timerId = window.setInterval(() => {
      setEmailResendSeconds((seconds) => (seconds > 0 ? seconds - 1 : 0));
    }, 1000);

    return () => window.clearInterval(timerId);
  }, [emailResendSeconds]);

  useEffect(() => {
    if (forgotResendSeconds <= 0) {
      return;
    }
    const timerId = window.setInterval(() => {
      setForgotResendSeconds((seconds) => (seconds > 0 ? seconds - 1 : 0));
    }, 1000);

    return () => window.clearInterval(timerId);
  }, [forgotResendSeconds]);

  async function parseApiErrorDetails(response: Response): Promise<ParsedApiError> {
    const payload = (await response.json().catch(() => null)) as ApiError | null;
    if (!payload) {
      return { message: "Ошибка запроса. Попробуйте снова" };
    }
    if (payload.error?.message) {
      return {
        message: translateApiErrorMessage(payload.error.message),
        code: payload.error.code,
      };
    }
    if (payload.message) {
      return { message: translateApiErrorMessage(payload.message) };
    }
    const firstFieldError = payload.errors ? Object.values(payload.errors)[0]?.[0] : null;
      return { message: firstFieldError ? translateApiErrorMessage(firstFieldError) : "Ошибка запроса. Попробуйте снова" };
  }

  async function parseApiError(response: Response): Promise<string> {
    const parsed = await parseApiErrorDetails(response);

    return parsed.message;
  }

  async function ensureCsrfCookie(): Promise<void> {
    await fetch(`${apiBaseUrl}/sanctum/csrf-cookie`, {
      method: "GET",
      credentials: "include",
      headers: { Accept: "application/json" },
    });
  }

  function getXsrfTokenFromCookie(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
    if (!match?.[1]) {
      return "";
    }

    try {
      return decodeURIComponent(match[1]);
    } catch {
      return "";
    }
  }

  function withCsrfHeaders(headers: Record<string, string>): Record<string, string> {
    const token = getXsrfTokenFromCookie();
    if (!token) {
      return headers;
    }

    return {
      ...headers,
      "X-XSRF-TOKEN": token,
    };
  }

  async function onLoginSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    await submitLogin();
  }

  async function submitLogin(): Promise<void> {
    setError("");
    setMessage("");

    const normalizedLogin = normalizeLogin(login);
    if (!normalizedLogin) {
      setError("Введите логин, e-mail или телефон");
      return;
    }
    if (!password) {
      setError("Введите пароль");
      return;
    }

    setState("loading");
    let shouldResetState = true;
    try {
      await ensureCsrfCookie();
      const response = await fetch(`${apiBaseUrl}/api/v1/auth/login`, {
        method: "POST",
        credentials: "include",
        headers: withCsrfHeaders({ "Content-Type": "application/json", Accept: "application/json" }),
        body: JSON.stringify({ login: normalizedLogin, password }),
      });

      if (!response.ok) {
        const failure = await parseApiErrorDetails(response);
        if (failure.code === "AUTH_ACCOUNT_MIGRATION_HELD") {
          setPassword("");
          router.replace(`/auth?mode=legacy-activation&next=${encodeURIComponent(nextPath)}`);
          return;
        }
        setError(failure.message);
        return;
      }

      const payload = (await response.json()) as LoginResponse;
      const nextQuery = `&next=${encodeURIComponent(nextPath)}`;

      if (payload.next_step === "verify_phone") {
        router.replace(`/auth?mode=verify-phone${nextQuery}`);
        return;
      }

      const destination = resolveLoginDestination(payload.next_step, nextPath);
      setMessage(payload.next_step === "admin" ? "Переходим в админку..." : "Переходим в личный кабинет...");
      setState("redirecting");
      shouldResetState = false;
      markClientAccountAuthenticated();
      replaceWithFreshDocument(destination);
    } catch {
      setError("Не удалось войти. Попробуйте снова");
    } finally {
      if (shouldResetState) {
        setState("idle");
      }
    }
  }

  function onLoginAutofillSubmit(): void {
    if (state !== "idle") {
      return;
    }
    void submitLogin();
  }

  async function onForgotSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setError("");
    setMessage("");

    if (!forgotEmail.trim()) {
      setError("Введите e-mail");
      return;
    }
    if (forgotResendSeconds > 0) {
      return;
    }

    // Мгновенный фидбек пользователю до ответа сервера.
    setMessage("Проверяем адрес и отправляем письмо...");
    setState("loading");
    try {
      await ensureCsrfCookie();
      const response = await fetch(`${apiBaseUrl}/api/v1/auth/password/forgot`, {
        method: "POST",
        credentials: "include",
        headers: withCsrfHeaders({ "Content-Type": "application/json", Accept: "application/json" }),
        body: JSON.stringify({ email: forgotEmail.trim() }),
      });

      if (!response.ok) {
        setError(await parseApiError(response));
        return;
      }

      setMessage("Письмо для сброса пароля отправлено. Проверьте почту");
      setForgotResendSeconds(FORGOT_RESEND_COOLDOWN_SECONDS);
    } catch {
      setError("Не удалось отправить письмо. Попробуйте снова");
      setMessage("");
    } finally {
      setState("idle");
    }
  }

  async function onRegisterSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setError("");
    setMessage("");
    setRegisterValidationActive(true);

    const validationErrors = getRegistrationValidationErrors();
    if (Object.keys(validationErrors).length > 0) {
      return;
    }
    if (!emailVerificationToken) {
      setMessage("Подтверждение email устарело. Повторите подтверждение email");
      return;
    }

    setState("loading");
    try {
      await ensureCsrfCookie();
      const response = await fetch(`${apiBaseUrl}/api/v1/auth/register`, {
        method: "POST",
        credentials: "include",
        headers: withCsrfHeaders({ "Content-Type": "application/json", Accept: "application/json" }),
        body: JSON.stringify({
          email: registerEmail.trim(),
          password: registerPassword,
          email_verification_token: emailVerificationToken,
          privacy_policy_accepted: registerPrivacyAccepted,
          offer_accepted: registerOfferAccepted,
          marketing_accepted: registerMarketingAccepted,
        }),
      });
      if (!response.ok) {
        setError(await parseApiError(response));
        setState("idle");
        return;
      }

      await response.text();
      setRegisterValidationActive(false);
      setMessage("Переходим в личный кабинет...");
      setState("redirecting");
      markClientAccountAuthenticated();
      replaceWithFreshDocument("/lk");
    } catch {
      setError("Не удалось создать аккаунт. Попробуйте снова");
      setState("idle");
    }
  }

  async function onResetSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setError("");
    setMessage("");

    const token = resetToken.trim() || resetTokenFromUrl;
    const email = (resetEmail.trim() || resetEmailFromUrl).toLowerCase();
    if (!token) {
      setError("Откройте ссылку из письма, чтобы продолжить сброс пароля");
      return;
    }
    if (!email) {
      setError("Введите e-mail, на который запрашивали сброс");
      return;
    }
    if (!resetPassword) {
      setError("Введите новый пароль");
      return;
    }

    setState("loading");
    try {
      await ensureCsrfCookie();
      const response = await fetch(`${apiBaseUrl}/api/v1/auth/password/reset`, {
        method: "POST",
        credentials: "include",
        headers: withCsrfHeaders({ "Content-Type": "application/json", Accept: "application/json" }),
        body: JSON.stringify({ email, token, password: resetPassword }),
      });

      if (!response.ok) {
        setError(await parseApiError(response));
        return;
      }

      window.location.replace("/auth?mode=login&password-reset=success");
    } catch {
      setError("Не удалось сохранить новый пароль. Попробуйте снова");
    } finally {
      setState("idle");
    }
  }

  async function onVerifyPhoneStart(event?: FormEvent<HTMLFormElement>) {
    event?.preventDefault();
    setError("");
    setPhoneServiceError("");
    setPhoneOccupied(false);
    setPhoneCallNumber("");
    setMessage("");

    setPhoneVerificationPending(true);
    setState("loading");
    try {
      await ensureCsrfCookie();
      const response = await fetch(`${apiBaseUrl}/api/v1/auth/phone-verification/start`, {
        method: "POST",
        credentials: "include",
        headers: withCsrfHeaders({
          "Content-Type": "application/json",
          Accept: "application/json",
        }),
        body: JSON.stringify({ phone: verifyPhone.trim() || undefined }),
      });

      if (!response.ok) {
        setPhoneVerificationPending(false);
        setPhoneServiceError(await parseApiError(response));
        return;
      }

      const payload = (await response.json()) as VerifyStartResponse;
      let activeCallNumber = payload.call_number ?? null;
      setPhoneCallNumber(activeCallNumber ?? "");
      setMessage(
        payload.call_number
          ? `Позвоните на ${payload.call_number}. Проверяем статус...`
          : "Готовим номер для звонка. Проверяем статус...",
      );

      for (const delayMs of PHONE_STATUS_POLL_DELAYS_MS) {
        await new Promise((resolve) => setTimeout(resolve, delayMs));

        const statusResponse = await fetch(
          `${apiBaseUrl}/api/v1/auth/phone-verification/status?verification_id=${payload.verification_id}`,
          {
            credentials: "include",
            headers: {
              "Content-Type": "application/json",
              Accept: "application/json",
            },
          },
        );

        if (!statusResponse.ok) {
          setPhoneVerificationPending(false);
          setPhoneServiceError(await parseApiError(statusResponse));
          return;
        }

        const statusPayload = (await statusResponse.json()) as VerifyStatusResponse;
        if (statusPayload.call_number && statusPayload.call_number !== activeCallNumber) {
          activeCallNumber = statusPayload.call_number;
          setPhoneCallNumber(activeCallNumber);
          setMessage(`Позвоните на ${activeCallNumber}. Проверяем статус...`);
        }
        if (statusPayload.status === "confirmed") {
          setPhoneVerified(true);
          setPhoneVerificationPending(false);
          setPhoneCallNumber("");
          setMessage("Телефон подтвержден");
          const returnPath = resolvePhoneVerificationReturnPath(mode, nextPath);
          if (returnPath !== null) {
            markClientAccountAuthenticated();
            replaceWithFreshDocument(returnPath);
          }
          return;
        }

        if (statusPayload.status === "failed") {
          setPhoneVerificationPending(false);
          setPhoneCallNumber("");
        setPhoneServiceError("Подтверждение не выполнено. Повторите попытку");
          return;
        }
      }

      setPhoneVerificationPending(false);
      setMessage("Подтверждение еще в обработке. Повторите проверку через несколько секунд");
    } finally {
      setState("idle");
    }
  }

  async function onEmailVerificationStart(): Promise<void> {
    setError("");
    setEmailServiceError("");
    setEmailOccupied(false);
    setMessage("");
    if (!emailVerificationPrerequisitesReady) {
      setMessage("Введите корректный email");
      return;
    }

    setEmailSendingCode(true);
    setEmailCodeSent(true);
    setEmailResendSeconds(EMAIL_RESEND_COOLDOWN_SECONDS);
    setMessage("Отправляем код на email...");
    setState("loading");
    try {
      await ensureCsrfCookie();
      const response = await fetch(`${apiBaseUrl}/api/v1/auth/preverify/email/start`, {
        method: "POST",
        credentials: "include",
        headers: withCsrfHeaders({ "Content-Type": "application/json", Accept: "application/json" }),
        body: JSON.stringify({ email: registerEmail.trim() }),
      });
      if (!response.ok) {
        const parsed = await parseApiErrorDetails(response);
        setEmailCodeSent(false);
        setEmailResendSeconds(0);
        setEmailServiceError(parsed.message);
        setEmailOccupied(
          parsed.code === "AUTH_EMAIL_EXISTS" ||
            parsed.code === "AUTH_ACCOUNT_MIGRATION_HELD",
        );
        return;
      }
      await response.json().catch(() => null);
      setMessage("Код отправлен на email. Введите его ниже");
    } finally {
      setEmailSendingCode(false);
      setState("idle");
    }
  }

  async function onEmailVerificationConfirm(event?: FormEvent<HTMLFormElement>, codeOverride?: string): Promise<void> {
    event?.preventDefault();
    setError("");
    setEmailServiceError("");
    setMessage("");
    const code = (codeOverride ?? emailCode).trim();
    if (!code) {
        setEmailServiceError("Введите код из email");
      return;
    }
    if (code.length !== 6) {
        setEmailServiceError("Код должен содержать 6 цифр");
      return;
    }
    setState("loading");
    try {
      await ensureCsrfCookie();
      const response = await fetch(`${apiBaseUrl}/api/v1/auth/preverify/email/verify`, {
        method: "POST",
        credentials: "include",
        headers: withCsrfHeaders({ "Content-Type": "application/json", Accept: "application/json" }),
        body: JSON.stringify({ email: registerEmail.trim(), code }),
      });
      if (!response.ok) {
        setEmailServiceError(await parseApiError(response));
        return;
      }
      const payload = (await response.json()) as { verification_token?: string };
      if (!payload.verification_token) {
        setEmailServiceError("Не удалось получить токен подтверждения email");
        return;
      }
      setEmailVerificationToken(payload.verification_token);
      setEmailVerified(true);
      setEmailCodeSent(false);
      setEmailCode("");
        setMessage("Email подтвержден");
    } finally {
      setState("idle");
    }
  }

  function resetAuthFeedback() {
    setError("");
    setMessage("");
    setEmailServiceError("");
    setPhoneServiceError("");
    setPhoneCallNumber("");
  }

  return {
    login,
    password,
    registerEmail,
    registerPassword,
    registerPrivacyAccepted,
    registerOfferAccepted,
    registerMarketingAccepted,
    registerFieldErrors,
    forgotEmail,
    resetToken,
    resetEmail,
    resetPassword,
    showLoginPassword,
    showRegisterPassword,
    showResetPassword,
    verifyPhone,
    emailCode,
    emailVerified,
    phoneVerified,
    emailCodeSent,
    phoneVerificationPending,
    phoneCallNumber,
    phoneCallHref,
    state,
    emailServiceError,
    phoneServiceError,
    emailOccupied,
    phoneOccupied,
    emailResendSeconds,
    emailSendingCode,
    forgotResendSeconds,
    emailCodeInputRef,
    emailSendActionRef,
    passwordError,
    commonError,
    commonMessage,
    registrationReady,
    setLogin,
    setPassword,
    setRegisterEmail,
    setRegisterPassword,
    setRegisterPrivacyAccepted,
    setRegisterOfferAccepted,
    setRegisterMarketingAccepted,
    setForgotEmail,
    setResetToken,
    setResetEmail,
    setResetPassword,
    setShowLoginPassword,
    setShowRegisterPassword,
    setShowResetPassword,
    setVerifyPhone,
    setEmailCode,
    setEmailServiceError,
    setEmailOccupied,
    setEmailVerified,
    setEmailCodeSent,
    setEmailVerificationToken,
    setPhoneVerified,
    setPhoneVerificationPending,
    setPhoneServiceError,
    setPhoneCallNumber,
    setPhoneOccupied,
    onLoginSubmit,
    onLoginAutofillSubmit,
    onForgotSubmit,
    onRegisterSubmit,
    onResetSubmit,
    onVerifyPhoneStart,
    onEmailVerificationStart,
    onEmailVerificationConfirm,
    resetAuthFeedback,
  };
}
