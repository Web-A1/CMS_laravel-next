import { FormEvent } from "react";
import Button from "../button/Button";
import { FormError } from "../form/FormError";
import { FormStatus } from "../form/FormStatus";
import { applyRuPhoneMask } from "../../lib/phone";
import { FloatingInput, PhoneCallPrompt } from "./AuthUi";

type Props = {
  verifyPhone: string;
  stateLoading: boolean;
  phoneVerificationPending: boolean;
  phoneCallNumber: string;
  phoneCallHref: string;
  commonError: string;
  commonMessage: string;
  onVerifyPhoneChange: (v: string) => void;
  onSubmit: (event: FormEvent<HTMLFormElement>) => void;
};

export default function VerifyPhoneModeForm({
  verifyPhone,
  stateLoading,
  phoneVerificationPending,
  phoneCallNumber,
  phoneCallHref,
  commonError,
  commonMessage,
  onVerifyPhoneChange,
  onSubmit,
}: Props) {
  const phoneErrorId = "auth-verify-phone-error";

  return (
    <form className="mx-auto flex w-full flex-col gap-6" onSubmit={onSubmit} data-ui="auth.verifyPhone.form">
      <div className="flex flex-col gap-2 text-center">
        <h1 className="auth-title">Подтвердите телефон</h1>
        <p className="auth-subtitle">Подтвердите номер звонком, чтобы завершить проверку профиля</p>
      </div>
      <div className="flex flex-col gap-2" data-ui="auth.verifyPhone.phoneField">
        <FloatingInput
          id="auth-verify-phone"
          data-ui="auth.verifyPhone.phoneInput"
          label="Телефон (опционально)"
          type="tel"
          autoComplete="tel"
          inputMode="tel"
          value={verifyPhone}
          onChange={(event) => onVerifyPhoneChange(applyRuPhoneMask(verifyPhone, event.target.value))}
          aria-invalid={commonError ? true : undefined}
          aria-describedby={commonError ? phoneErrorId : undefined}
        />
        <FormError id={phoneErrorId} dataUi="auth.verifyPhone.commonError">{commonError}</FormError>
        <FormStatus dataUi="auth.verifyPhone.commonMessage">{commonMessage}</FormStatus>
      </div>
      {phoneVerificationPending && !phoneCallNumber ? (
        <FormStatus dataUi="auth.verifyPhone.pendingStatus" tone="pending">Готовим номер для звонка. Проверяем статус...</FormStatus>
      ) : null}
      <PhoneCallPrompt callNumber={phoneCallNumber} callHref={phoneCallHref} dataUi="auth.verifyPhone.callPrompt" />
      <Button type="submit" disabled={stateLoading} label={phoneVerificationPending ? "Проверяем звонок" : "Подтвердите телефон"} data-ui="auth.verifyPhone.submitButton" />
    </form>
  );
}
