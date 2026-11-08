import { FormEvent } from "react";

export type SubmitState = "idle" | "loading" | "redirecting";

export type ModeCommonProps = {
  loading: boolean;
  passwordError: string;
  commonError: string;
  commonMessage: string;
};

export type LoginModeProps = ModeCommonProps & {
  login: string;
  password: string;
  showLoginPassword: boolean;
  setShowLoginPassword: (updater: (current: boolean) => boolean) => void;
  onLoginChange: (v: string) => void;
  onPasswordChange: (v: string) => void;
  onSubmit: (event: FormEvent<HTMLFormElement>) => void;
};
