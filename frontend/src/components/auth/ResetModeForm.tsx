import { FormEvent } from "react";
import Button from "../button/Button";
import { FormError } from "../form/FormError";
import { FormStatus } from "../form/FormStatus";
import { FloatingInput, PasswordInput } from "./AuthUi";

type Props = {
  hasResetTokenFromUrl: boolean;
  hasResetEmailFromUrl: boolean;
  resetToken: string;
  resetEmail: string;
  resetPassword: string;
  showResetPassword: boolean;
  stateLoading: boolean;
  passwordError: string;
  commonError: string;
  commonMessage: string;
  onResetTokenChange: (v: string) => void;
  onResetEmailChange: (v: string) => void;
  onResetPasswordChange: (v: string) => void;
  onTogglePassword: () => void;
  onSubmit: (event: FormEvent<HTMLFormElement>) => void;
};

export default function ResetModeForm(props: Props) {
  const {
    hasResetTokenFromUrl,
    hasResetEmailFromUrl,
    resetToken,
    resetEmail,
    resetPassword,
    showResetPassword,
    stateLoading,
    passwordError,
    commonError,
    commonMessage,
    onResetTokenChange,
    onResetEmailChange,
    onResetPasswordChange,
    onTogglePassword,
    onSubmit,
  } = props;
  const passwordErrorId = "auth-reset-password-error";

  return (
    <form className="mx-auto flex w-full flex-col gap-6" onSubmit={onSubmit} data-ui="auth.reset.form">
      <h1 className="auth-title text-center">Новый пароль</h1>
      {!hasResetTokenFromUrl ? (
        <div className="flex flex-col gap-2" data-ui="auth.reset.tokenField">
          <FloatingInput id="auth-reset-token" data-ui="auth.reset.tokenInput" label="Токен из письма" type="text" value={resetToken} onChange={(e) => onResetTokenChange(e.target.value)} />
        </div>
      ) : null}
      {!hasResetEmailFromUrl ? (
        <div className="flex flex-col gap-2" data-ui="auth.reset.emailField">
          <FloatingInput id="auth-reset-email" data-ui="auth.reset.emailInput" label="E-mail" type="email" autoComplete="email" value={resetEmail} onChange={(e) => onResetEmailChange(e.target.value)} />
        </div>
      ) : null}
      <div className="flex flex-col gap-2" data-ui="auth.reset.passwordField">
        <PasswordInput
          id="auth-reset-password"
          data-ui="auth.reset.passwordInput"
          label="Новый пароль"
          shown={showResetPassword}
          toggleDataUi="auth.reset.passwordToggle"
          autoComplete="new-password"
          value={resetPassword}
          onChange={(event) => onResetPasswordChange(event.target.value)}
          aria-invalid={passwordError ? true : undefined}
          aria-describedby={passwordError ? passwordErrorId : undefined}
          onToggleShown={onTogglePassword}
        />
        <FormError id={passwordErrorId} dataUi="auth.reset.passwordError">{passwordError}</FormError>
        <FormError dataUi="auth.reset.commonError">{commonError}</FormError>
        <FormStatus dataUi="auth.reset.commonMessage">{commonMessage}</FormStatus>
      </div>
      <Button type="submit" loading={stateLoading} disabled={stateLoading} label="Сохранить пароль" data-ui="auth.reset.submitButton" />
    </form>
  );
}
