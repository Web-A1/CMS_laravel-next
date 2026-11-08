import { FormEvent, RefObject, useEffect, useRef, useState } from "react";
import Button from "../button/Button";
import { FloatingInput, PasswordInput, VerifiedCheckIcon } from "./AuthUi";
import LegalDocumentLink from "@/components/documents/LegalDocumentLink";

type RegisterFieldErrors = {
  email?: string;
  password?: string;
  agreements?: string;
};

type Props = {
  registerEmail: string;
  registerPassword: string;
  registerPrivacyAccepted: boolean;
  registerOfferAccepted: boolean;
  registerMarketingAccepted: boolean;
  emailCode: string;
  emailVerified: boolean;
  emailCodeSent: boolean;
  emailSendingCode: boolean;
  emailResendSeconds: number;
  emailServiceError: string;
  emailOccupied: boolean;
  showRegisterPassword: boolean;
  stateLoading: boolean;
  stateRedirecting: boolean;
  registerFieldErrors: RegisterFieldErrors;
  passwordError: string;
  commonError: string;
  emailCodeInputRef: RefObject<HTMLInputElement | null>;
  emailSendActionRef: RefObject<HTMLButtonElement | null>;
  isEmailReadyForVerification: (value: string) => boolean;
  onSubmit: (event: FormEvent<HTMLFormElement>) => void;
  onRegisterEmailChange: (v: string) => void;
  onRegisterPasswordChange: (v: string) => void;
  onRegisterPrivacyAcceptedChange: (v: boolean) => void;
  onRegisterOfferAcceptedChange: (v: boolean) => void;
  onRegisterMarketingAcceptedChange: (v: boolean) => void;
  onEmailCodeChange: (v: string) => void;
  onTogglePassword: () => void;
  onEmailStart: () => void;
  onEmailConfirmAuto: (code: string) => void;
  resetEmailVerificationState: () => void;
};

const registerErrorIds = {
  email: "auth-register-email-error",
  emailService: "auth-register-email-service-error",
  password: "auth-register-password-error",
  agreements: "auth-register-agreements-error",
  common: "auth-register-common-error",
} as const;

function describedBy(...ids: Array<string | false>): string | undefined {
  const activeIds = ids.filter(Boolean);
  return activeIds.length > 0 ? activeIds.join(" ") : undefined;
}

export function firstInvalidRegistrationFieldId(
  errors: RegisterFieldErrors,
  passwordError: string,
): string | null {
  if (errors.email) return "auth-register-email";
  if (passwordError || errors.password) return "auth-register-password";
  if (errors.agreements) return "auth-register-privacy";
  return null;
}

export default function RegisterModeForm(props: Props) {
  const {
    registerEmail,
    registerPassword,
    registerPrivacyAccepted,
    registerOfferAccepted,
    registerMarketingAccepted,
    emailCode,
    emailVerified,
    emailCodeSent,
    emailSendingCode,
    emailResendSeconds,
    emailServiceError,
    emailOccupied,
    showRegisterPassword,
    stateLoading,
    stateRedirecting,
    registerFieldErrors,
    passwordError,
    commonError,
    emailCodeInputRef,
    emailSendActionRef,
    isEmailReadyForVerification,
    onSubmit,
    onRegisterEmailChange,
    onRegisterPasswordChange,
    onRegisterPrivacyAcceptedChange,
    onRegisterOfferAcceptedChange,
    onRegisterMarketingAcceptedChange,
    onEmailCodeChange,
    onTogglePassword,
    onEmailStart,
    onEmailConfirmAuto,
    resetEmailVerificationState,
  } = props;

  const {
    agreements: agreementsError,
    email: emailError,
    password: passwordFieldError,
  } = registerFieldErrors;
  const [validationFocusRequest, setValidationFocusRequest] = useState(0);
  const completedValidationFocusRequestRef = useRef(0);

  useEffect(() => {
    if (
      validationFocusRequest === 0 ||
      completedValidationFocusRequestRef.current === validationFocusRequest
    ) {
      return;
    }

    const fieldId = firstInvalidRegistrationFieldId(
      {
        agreements: agreementsError,
        email: emailError,
        password: passwordFieldError,
      },
      passwordError,
    );
    if (fieldId) {
      document.getElementById(fieldId)?.focus();
      completedValidationFocusRequestRef.current = validationFocusRequest;
    }
  }, [
    validationFocusRequest,
    passwordError,
    agreementsError,
    emailError,
    passwordFieldError,
  ]);

  function handleSubmit(event: FormEvent<HTMLFormElement>): void {
    setValidationFocusRequest((current) => current + 1);
    onSubmit(event);
  }

  const registrationTitle = emailVerified
    ? "Придумайте пароль"
    : emailCodeSent
      ? "Проверьте почту"
      : "Создайте аккаунт";
  const registrationSubtitle = emailVerified
    ? "Создайте пароль и примите условия, чтобы завершить регистрацию"
    : emailCodeSent
      ? "Введите 6-значный код из письма, чтобы продолжить"
      : "Подтвердите e-mail, затем задайте пароль";

  return (
    <form className="mx-auto flex w-full flex-col gap-6" onSubmit={handleSubmit} noValidate data-ui="auth.register.form">
      <div className="flex flex-col gap-3 text-center">
        <h1 className="auth-title">{registrationTitle}</h1>
        <p className="auth-subtitle">{registrationSubtitle}</p>
      </div>

      <div className="flex flex-col gap-2" data-ui="auth.register.emailField">
        <FloatingInput
          id="auth-register-email"
          data-ui="auth.register.emailInput"
          label="E-mail"
          type="email"
          autoComplete="email"
          value={registerEmail}
          aria-invalid={Boolean(registerFieldErrors.email || emailServiceError)}
          aria-describedby={describedBy(
            Boolean(registerFieldErrors.email) && registerErrorIds.email,
            Boolean(emailServiceError) && registerErrorIds.emailService,
          )}
          rightSlot={emailVerified ? <VerifiedCheckIcon /> : undefined}
          onChange={(event) => {
            onRegisterEmailChange(event.target.value);
            resetEmailVerificationState();
          }}
          onKeyDown={(event) => {
            if (event.key === "Enter" && isEmailReadyForVerification(registerEmail) && !emailCodeSent && !emailVerified) {
              event.preventDefault();
              emailSendActionRef.current?.focus();
            }
          }}
        />
        {emailCodeSent && !emailVerified ? (
          <div className="mt-1 flex flex-col gap-2" data-ui="auth.register.emailCodePanel">
            <div className="flex flex-col gap-3" data-ui="auth.register.emailCodePanelContent">
              <div className="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1 text-xs font-normal leading-5 ui-text-muted-ink sm:text-sm" data-ui="auth.register.emailCodeStatus">
                {emailSendingCode ? (
                  <span className="whitespace-nowrap pl-5">отправляем...</span>
                ) : stateLoading ? (
                  <span className="whitespace-nowrap pl-5">проверяем...</span>
                ) : emailResendSeconds > 0 ? (
                  <span className="break-words pl-5">Код отправлен · повторить через 00:{String(emailResendSeconds).padStart(2, "0")}</span>
                ) : (
                  <Button
                    type="button"
                    data-ui="auth.register.emailResendButton"
                    label="Отправить код повторно"
                    variant="primary"
                    size="sm"
                    fullWidth={false}
                    className="w-full"
                    onClick={onEmailStart}
                    disabled={stateLoading || emailSendingCode} loading={stateLoading || emailSendingCode}
                    title="отправить повторно"
                  />
                )}
              </div>
              <FloatingInput
                id="auth-register-email-code"
                data-ui="auth.register.emailCodeInput"
                ref={emailCodeInputRef}
                label="Код из email"
                type="text"
                autoComplete="one-time-code"
                inputMode="numeric"
                pattern="[0-9]*"
                maxLength={6}
                value={emailCode}
                onChange={(event) => {
                  const nextCode = event.target.value.replace(/\D/g, "").slice(0, 6);
                  onEmailCodeChange(nextCode);
                  if (nextCode.length === 6 && !emailVerified && !stateLoading) {
                    onEmailConfirmAuto(nextCode);
                  }
                }}
              />
              {stateLoading ? (
              <p role="status" className="pl-5 text-sm leading-5 ui-text-muted-ink">Проверяем код…</p>
              ) : null}
            </div>
            {emailServiceError ? <p id={registerErrorIds.emailService} className="pl-5 text-sm font-medium ui-text-danger" data-ui="auth.register.emailServiceError">{emailServiceError}</p> : null}
          </div>
        ) : null}
        {isEmailReadyForVerification(registerEmail) && !emailVerified && !emailCodeSent && !emailOccupied ? (
          <div className="mt-1 flex flex-col gap-2" data-ui="auth.register.emailStartPanel">
            <div className="flex min-w-0 items-center">
              <Button
                type="button"
                data-ui="auth.register.emailSendButton"
                label="Получить код на email"
                variant="secondary"
                disabled={stateLoading} loading={stateLoading}
                onClick={onEmailStart}
                ref={emailSendActionRef}
                className="w-full sm:w-auto"
                title="отправить код"
              />
            </div>
            <p className="pl-5 text-xs leading-5 ui-text-muted-ink sm:text-sm">На email придёт 6-значный код подтверждения</p>
          </div>
        ) : null}
        {!emailCodeSent && emailServiceError ? <p id={registerErrorIds.emailService} className="pl-5 text-sm font-medium ui-text-danger" data-ui="auth.register.emailServiceError">{emailServiceError}</p> : null}
        {registerFieldErrors.email ? <p id={registerErrorIds.email} className="pl-5 text-sm font-medium ui-text-danger" data-ui="auth.register.emailError">{registerFieldErrors.email}</p> : null}
      </div>

      {emailVerified ? (
        <>
          <div className="flex flex-col gap-2" data-ui="auth.register.passwordField">
            <PasswordInput
              id="auth-register-password"
              data-ui="auth.register.passwordInput"
              label="Придумайте пароль"
              shown={showRegisterPassword}
              toggleDataUi="auth.register.passwordToggle"
              autoComplete="new-password"
              value={registerPassword}
              aria-invalid={Boolean(passwordError || registerFieldErrors.password)}
              aria-describedby={passwordError || registerFieldErrors.password ? registerErrorIds.password : undefined}
              onChange={(event) => onRegisterPasswordChange(event.target.value)}
              onToggleShown={onTogglePassword}
            />
            <p className="pl-5 text-xs leading-5 ui-text-muted-ink sm:text-sm">Минимум 8 символов</p>
            {passwordError || registerFieldErrors.password ? (
              <p id={registerErrorIds.password} className="pl-5 text-sm font-medium ui-text-danger" data-ui="auth.register.passwordError">{passwordError || registerFieldErrors.password}</p>
            ) : null}
            {commonError ? <p id={registerErrorIds.common} className="pl-5 text-left text-sm font-medium ui-text-danger" role="alert" data-ui="auth.register.commonError">{commonError}</p> : null}
          </div>

          <fieldset className="-mt-2 flex flex-col gap-3" data-ui="auth.register.agreements" aria-describedby={registerFieldErrors.agreements ? registerErrorIds.agreements : undefined}>
            <legend className="sr-only">Обязательные согласия</legend>
            <label className="relative flex items-start pl-5 text-sm leading-5 ui-text-secondary">
              <input
                id="auth-register-privacy"
                type="checkbox"
                data-ui="auth.register.privacyCheckbox"
                className="auth-checkbox absolute -left-6 top-0 h-6 w-6"
                checked={registerPrivacyAccepted}
                aria-invalid={Boolean(registerFieldErrors.agreements)}
                onChange={(event) => onRegisterPrivacyAcceptedChange(event.target.checked)}
              />
              <span className="block w-full break-words">
                Я ознакомлен(а) с{" "}
                <LegalDocumentLink
                  className="link-text"
                  documentId="privacy"
                  dataUi="auth.register.document.privacy"
                >
                  политикой обработки персональных данных
                </LegalDocumentLink>{" "}
                и даю согласие на обработку персональных данных в соответствии с ее условиями
              </span>
            </label>
            <label className="relative flex items-start pl-5 text-sm leading-5 ui-text-secondary">
              <input
                id="auth-register-offer"
                type="checkbox"
                data-ui="auth.register.offerCheckbox"
                className="auth-checkbox absolute -left-6 top-0 h-6 w-6"
                checked={registerOfferAccepted}
                aria-invalid={Boolean(registerFieldErrors.agreements)}
                onChange={(event) => onRegisterOfferAcceptedChange(event.target.checked)}
              />
              <span className="block w-full break-words">
                Я принимаю{" "}
                <LegalDocumentLink
                  className="link-text"
                  documentId="offer"
                  dataUi="auth.register.document.offer"
                >
                  договор оферты
                </LegalDocumentLink>
              </span>
            </label>
            <label className="relative flex items-start gap-3 pt-2 pl-5 text-sm leading-5 ui-text-secondary">
              <input
                type="checkbox"
                data-ui="auth.register.marketingCheckbox"
                className="auth-checkbox absolute -left-6 top-2 h-6 w-6"
                checked={registerMarketingAccepted}
                onChange={(event) => onRegisterMarketingAcceptedChange(event.target.checked)}
              />
              <span className="block w-full break-words">
                Я даю согласие на получение информационных, рекламных и маркетинговых сообщений
              </span>
            </label>
            {registerFieldErrors.agreements ? <p id={registerErrorIds.agreements} className="pl-5 text-sm font-medium ui-text-danger" data-ui="auth.register.agreementsError">{registerFieldErrors.agreements}</p> : null}
          </fieldset>

          <Button
            type="submit"
            data-ui="auth.register.submitButton"
            disabled={stateLoading}
            label={stateRedirecting ? "Переходим в личный кабинет..." : "Создать аккаунт"}
            loading={stateRedirecting}
          />
        </>
      ) : null}
    </form>
  );
}
