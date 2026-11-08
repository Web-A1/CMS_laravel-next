import { FormEvent } from "react";
import Button from "../button/Button";
import { FormError } from "../form/FormError";
import { FormStatus } from "../form/FormStatus";
import { FloatingInput } from "./AuthUi";

type Props = {
  forgotEmail: string;
  stateLoading: boolean;
  resendSeconds: number;
  commonError: string;
  commonMessage: string;
  onEmailChange: (v: string) => void;
  onSubmit: (event: FormEvent<HTMLFormElement>) => void;
};

export default function ForgotModeForm({
  forgotEmail,
  stateLoading,
  resendSeconds,
  commonError,
  commonMessage,
  onEmailChange,
  onSubmit,
}: Props) {
  const resendLabel =
    resendSeconds > 0
      ? `Отправить повторно через 00:${String(resendSeconds).padStart(2, "0")}`
      : "Отправить ссылку";
  const emailErrorId = "auth-forgot-email-error";

  return (
    <form className="mx-auto flex w-full flex-col gap-6" onSubmit={onSubmit} data-ui="auth.forgot.form">
      <div className="flex flex-col gap-2 text-center">
        <h1 className="auth-title">Сброс пароля</h1>
        <p className="auth-subtitle">Отправим ссылку для создания нового пароля</p>
      </div>
      <div className="flex flex-col gap-2" data-ui="auth.forgot.emailField">
        <FloatingInput
          id="auth-forgot-email"
          data-ui="auth.forgot.emailInput"
          label="E-mail"
          type="email"
          autoComplete="email"
          value={forgotEmail}
          onChange={(event) => onEmailChange(event.target.value)}
          aria-invalid={commonError ? true : undefined}
          aria-describedby={commonError ? emailErrorId : undefined}
        />
        <FormError id={emailErrorId} dataUi="auth.forgot.commonError">{commonError}</FormError>
        <FormStatus dataUi="auth.forgot.commonMessage">{commonMessage}</FormStatus>
      </div>
      <Button type="submit" loading={stateLoading} disabled={stateLoading || resendSeconds > 0} label={resendLabel} data-ui="auth.forgot.submitButton" />
    </form>
  );
}
