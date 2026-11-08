import { FormEvent } from "react";
import Button from "../button/Button";
import { applyRuPhoneMask } from "../../lib/phone";
import { FloatingInput, PhoneCallPrompt } from "./AuthUi";

type Props = {
  emailVerified: boolean;
  phoneVerified: boolean;
  emailCode: string;
  verifyPhone: string;
  stateLoading: boolean;
  phoneVerificationPending: boolean;
  phoneCallNumber: string;
  phoneCallHref: string;
  onEmailCodeChange: (v: string) => void;
  onVerifyPhoneChange: (v: string) => void;
  onEmailStart: () => void;
  onEmailConfirm: (event: FormEvent<HTMLFormElement>) => void;
  onPhoneStart: (event: FormEvent<HTMLFormElement>) => void;
};

export default function VerifyProfileMode(props: Props) {
  const {
    emailVerified,
    phoneVerified,
    emailCode,
    verifyPhone,
    stateLoading,
    phoneVerificationPending,
    phoneCallNumber,
    phoneCallHref,
    onEmailCodeChange,
    onVerifyPhoneChange,
    onEmailStart,
    onEmailConfirm,
    onPhoneStart,
  } = props;

  return (
    <div className="mx-auto flex w-full flex-col gap-6" data-ui="auth.verifyProfile.form">
      <div className="flex flex-col gap-2 text-center">
        <h1 className="auth-title">Подтверждение профиля</h1>
        <p className="auth-subtitle">Шаг 1 из 2: email, шаг 2 из 2: телефон</p>
      </div>

      <section className="border-t border-[var(--color-border-subtle)] pt-5" data-ui="auth.verifyProfile.emailCard">
        <div className="mb-3 flex items-center justify-between gap-3">
          <div className="text-sm font-semibold">Email</div>
          <div className="text-xs" data-ui="auth.verifyProfile.emailStatus">{emailVerified ? "Подтвержден" : "Не подтвержден"}</div>
        </div>
        <Button variant="secondary"
          type="button"
          data-ui="auth.verifyProfile.emailSendButton"
          onClick={onEmailStart}
          disabled={stateLoading || emailVerified}
          className="mb-3"
        >
          Отправить код на email
        </Button>
        <form className="flex flex-col gap-3" onSubmit={onEmailConfirm} data-ui="auth.verifyProfile.emailForm">
          <div className="flex flex-col gap-2" data-ui="auth.verifyProfile.emailCodeField">
            <label htmlFor="auth-verify-email-code" className="sr-only">Код из email</label>
            <FloatingInput
              id="auth-verify-email-code"
              data-ui="auth.verifyProfile.emailCodeInput"
              label="Код из email"
              type="text"
              value={emailCode}
              onChange={(event) => onEmailCodeChange(event.target.value)}
              disabled={emailVerified}
            />
          </div>
          <Button type="submit" disabled={stateLoading || emailVerified} label="Подтвердить email" data-ui="auth.verifyProfile.emailSubmitButton" />
        </form>
      </section>

      <section className="border-t border-[var(--color-border-subtle)] pt-5" data-ui="auth.verifyProfile.phoneCard">
        <div className="mb-3 flex items-center justify-between gap-3">
          <div className="text-sm font-semibold">Телефон</div>
          <div className="text-xs" data-ui="auth.verifyProfile.phoneStatus">{phoneVerified ? "Подтвержден" : "Не подтвержден"}</div>
        </div>
        <form className="flex flex-col gap-3" onSubmit={onPhoneStart} data-ui="auth.verifyProfile.phoneForm">
          <div className="flex flex-col gap-2" data-ui="auth.verifyProfile.phoneField">
            <FloatingInput
              id="auth-verify-profile-phone"
              data-ui="auth.verifyProfile.phoneInput"
              label="Телефон (опционально)"
              type="tel"
              autoComplete="tel"
              inputMode="tel"
              value={verifyPhone}
              onChange={(event) => onVerifyPhoneChange(applyRuPhoneMask(verifyPhone, event.target.value))}
              disabled={stateLoading || !emailVerified || phoneVerified}
            />
          </div>
          {phoneVerificationPending && !phoneCallNumber ? (
            <p className="px-1 text-sm font-medium ui-text-secondary" data-ui="auth.verifyProfile.phonePendingStatus">Готовим номер для звонка. Проверяем статус...</p>
          ) : null}
          <PhoneCallPrompt callNumber={phoneCallNumber} callHref={phoneCallHref} dataUi="auth.verifyProfile.phoneCallPrompt" />
          <Button type="submit" disabled={stateLoading || !emailVerified || phoneVerified} label="Подтвердите телефон" data-ui="auth.verifyProfile.phoneSubmitButton" />
        </form>
      </section>
    </div>
  );
}
