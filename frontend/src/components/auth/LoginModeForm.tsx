import { AnimationEvent, FormEvent, useEffect, useRef, useState } from "react";
import Button from "../button/Button";
import { FormError } from "../form/FormError";
import { FormStatus } from "../form/FormStatus";
import { FloatingInput, PasswordInput } from "./AuthUi";
import Link from "next/link";

type Props = {
  login: string;
  password: string;
  showLoginPassword: boolean;
  stateLoading: boolean;
  passwordError: string;
  commonError: string;
  commonMessage: string;
  onLoginChange: (v: string) => void;
  onPasswordChange: (v: string) => void;
  onTogglePassword: () => void;
  onSubmit: (event: FormEvent<HTMLFormElement>) => void;
  onAutoSubmit: () => void;
  contextHint?: string;
};

export default function LoginModeForm({
  login,
  password,
  showLoginPassword,
  stateLoading,
  passwordError,
  commonError,
  commonMessage,
  onLoginChange,
  onPasswordChange,
  onTogglePassword,
  onSubmit,
  onAutoSubmit,
  contextHint,
}: Props) {
  const [autofillDetected, setAutofillDetected] = useState(false);
  const lastAutoSubmitSignatureRef = useRef("");

  useEffect(() => {
    if (!autofillDetected || stateLoading) {
      return;
    }

    const loginReady = login.trim() !== "";
    const passwordReady = password !== "";
    if (!loginReady || !passwordReady) {
      return;
    }

    const signature = `${login}::${password}`;
    if (lastAutoSubmitSignatureRef.current === signature) {
      return;
    }

    lastAutoSubmitSignatureRef.current = signature;
    onAutoSubmit();
    setAutofillDetected(false);
  }, [autofillDetected, login, onAutoSubmit, password, stateLoading]);

  function onAutofillAnimation(event: AnimationEvent<HTMLInputElement>): void {
    if (event.animationName === "auth-autofill-start") {
      setAutofillDetected(true);
    }
  }

  const passwordErrorId = "auth-login-password-error";

  return (
    <form className="mx-auto flex w-full flex-col gap-6" onSubmit={onSubmit} data-ui="auth.login.form">
      {contextHint ? (
        <p className="text-center text-sm font-medium leading-6 ui-text-secondary" data-ui="auth.login.contextHint">
          {contextHint}
        </p>
      ) : null}
      <div className="flex flex-col gap-2 text-center">
        <h1 className="auth-title">Вход в профиль</h1>
        <p className="auth-subtitle">Введите логин, e-mail или телефон и пароль</p>
      </div>

      <div className="flex flex-col gap-2" data-ui="auth.login.loginField">
        <FloatingInput
          id="auth-login"
          data-ui="auth.login.loginInput"
          label="Логин, e-mail или телефон"
          type="text"
          autoComplete="username"
          value={login}
          onChange={(event) => onLoginChange(event.target.value)}
          onAnimationStart={onAutofillAnimation}
        />
      </div>

      <div className="flex flex-col gap-2" data-ui="auth.login.passwordField">
        <PasswordInput
          id="auth-password"
          data-ui="auth.login.passwordInput"
          label="Пароль"
          shown={showLoginPassword}
          toggleDataUi="auth.login.passwordToggle"
          autoComplete="current-password"
          value={password}
          onChange={(event) => onPasswordChange(event.target.value)}
          onAnimationStart={onAutofillAnimation}
          aria-invalid={passwordError ? true : undefined}
          aria-describedby={passwordError ? passwordErrorId : undefined}
          onToggleShown={onTogglePassword}
        />
        <FormError id={passwordErrorId} dataUi="auth.login.passwordError">{passwordError}</FormError>
        <FormError dataUi="auth.login.commonError">{commonError}</FormError>
        <FormStatus dataUi="auth.login.commonMessage" tone="success">{commonMessage}</FormStatus>
        <Link
          href="/auth?mode=forgot"
          className="link-text self-start px-1 text-sm font-medium"
          data-ui="auth.login.forgotLink"
        >
          Забыли пароль?
        </Link>
      </div>

      <Button
        type="submit"
        className="auth-login-submit-button"
        disabled={stateLoading}
        loading={stateLoading}
        label="Войти"
        data-ui="auth.login.submitButton"
      />
    </form>
  );
}
