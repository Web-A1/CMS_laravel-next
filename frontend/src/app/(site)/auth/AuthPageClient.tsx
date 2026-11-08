"use client";

import "@/components/auth/auth.css";
import Link from "next/link";
import { Suspense, useEffect, useMemo } from "react";
import { useRouter, useSearchParams } from "next/navigation";
import LoginModeForm from "@/components/auth/LoginModeForm";
import LegacyActivationModeForm from "@/components/auth/LegacyActivationModeForm";
import RegisterModeForm from "@/components/auth/RegisterModeForm";
import ForgotModeForm from "@/components/auth/ForgotModeForm";
import ResetModeForm from "@/components/auth/ResetModeForm";
import VerifyPhoneModeForm from "@/components/auth/VerifyPhoneModeForm";
import VerifyProfileMode from "@/components/auth/VerifyProfileMode";
import { normalizeAuthNextPath, replaceWithFreshDocument, resolveLoginDestination } from "@/lib/authNavigation";
import { canOpenAdminWorkspace, loadClientAccountProfile } from "@/lib/clientAccount";
import { AuthMode, isEmailReadyForVerification, useAuthFlows } from "./useAuthFlows";

function AuthPageContent() {
  const router = useRouter();
  const searchParams = useSearchParams();
  const apiBaseUrl = (process.env.NEXT_PUBLIC_API_BASE_URL ?? "/backend").replace(/\/+$/, "");

  const mode = useMemo<AuthMode>(() => {
    const value = searchParams.get("mode");
    if (value === "legacy-activation") return "legacy-activation";
    if (value === "register") return "register";
    if (value === "forgot") return "forgot";
    if (value === "reset") return "reset";
    if (value === "verify-phone") return "verify-phone";
    if (value === "verify") return "verify";

    return "login";
  }, [searchParams]);

  const passwordResetSucceeded = searchParams.get("password-reset") === "success";
  const resetTokenFromUrl = searchParams.get("token")?.trim() ?? "";
  const resetEmailFromUrl = searchParams.get("email")?.trim() ?? "";
  const nextPath = normalizeAuthNextPath(searchParams.get("next"));
  const checkoutAuthHint =
    nextPath === "/lk/cart"
      ? "Чтобы продолжить оформление покупки, войдите в аккаунт. Если аккаунта еще нет, зарегистрируйтесь"
      : "";
  const hasResetTokenFromUrl = Boolean(resetTokenFromUrl);
  const hasResetEmailFromUrl = Boolean(resetEmailFromUrl);

  const auth = useAuthFlows({
    mode,
    passwordResetSucceeded,
    resetTokenFromUrl,
    resetEmailFromUrl,
    apiBaseUrl,
    nextPath,
  });

  useEffect(() => {
    if (mode !== "login") {
      return;
    }

    let isCancelled = false;

    (async () => {
      try {
        const payload = await loadClientAccountProfile(apiBaseUrl);
        if (isCancelled || payload === null) {
          return;
        }

        if (payload.must_verify_phone === false || payload.phone_verified) {
          replaceWithFreshDocument(
            resolveLoginDestination(canOpenAdminWorkspace(payload) ? "admin" : "lk", nextPath),
          );
          return;
        }

        const nextQuery = `&next=${encodeURIComponent(nextPath)}`;
        router.replace(`/auth?mode=verify-phone${nextQuery}`);
      } catch {
        // Оставляем пользователя на странице входа, если сетевой запрос не удался.
      }
    })();

    return () => {
      isCancelled = true;
    };
  }, [apiBaseUrl, mode, nextPath, router]);

  useEffect(() => {
    const viewport = window.visualViewport;
    if (!viewport) {
      return;
    }

    let previousHeight = viewport.height;
    let firstFrame = 0;
    let secondFrame = 0;

    const restoreAuthScrollPosition = () => {
      window.scrollTo(0, 0);
      document.documentElement.scrollTop = 0;
      document.body.scrollTop = 0;
    };

    const handleViewportResize = () => {
      const heightDelta = viewport.height - previousHeight;
      previousHeight = viewport.height;

      if (heightDelta < 80 || window.scrollY <= 0) {
        return;
      }

      firstFrame = window.requestAnimationFrame(() => {
        secondFrame = window.requestAnimationFrame(restoreAuthScrollPosition);
      });
    };

    viewport.addEventListener("resize", handleViewportResize);
    return () => {
      viewport.removeEventListener("resize", handleViewportResize);
      window.cancelAnimationFrame(firstFrame);
      window.cancelAnimationFrame(secondFrame);
    };
  }, []);

  return (
    <main className="bg-white ui-text-primary" data-ui="auth.screen">
      <section className="auth-shell content-shell page-content page-gutter relative z-0 mx-auto w-full" data-ui="auth.content">
        <div className="auth-card" data-ui="auth.card" data-state={mode}>
          {mode === "legacy-activation" ? <LegacyActivationModeForm apiBaseUrl={apiBaseUrl} nextPath={nextPath} /> : null}
          {mode === "login" ? (
            <LoginModeForm
              login={auth.login}
              password={auth.password}
              showLoginPassword={auth.showLoginPassword}
              stateLoading={auth.state !== "idle"}
              passwordError={auth.passwordError}
              commonError={auth.commonError}
              commonMessage={auth.commonMessage}
              onLoginChange={auth.setLogin}
              onPasswordChange={auth.setPassword}
              onTogglePassword={() => auth.setShowLoginPassword((current) => !current)}
              onSubmit={auth.onLoginSubmit}
              onAutoSubmit={auth.onLoginAutofillSubmit}
              contextHint={checkoutAuthHint}
            />
          ) : null}

          {mode === "register" ? (
            <RegisterModeForm
              registerEmail={auth.registerEmail}
              registerPassword={auth.registerPassword}
              registerPrivacyAccepted={auth.registerPrivacyAccepted}
              registerOfferAccepted={auth.registerOfferAccepted}
              registerMarketingAccepted={auth.registerMarketingAccepted}
              emailCode={auth.emailCode}
              emailVerified={auth.emailVerified}
              emailCodeSent={auth.emailCodeSent}
              emailSendingCode={auth.emailSendingCode}
              emailResendSeconds={auth.emailResendSeconds}
              emailServiceError={auth.emailServiceError}
              emailOccupied={auth.emailOccupied}
              showRegisterPassword={auth.showRegisterPassword}
              stateLoading={auth.state !== "idle"}
              stateRedirecting={auth.state === "redirecting"}
              registerFieldErrors={auth.registerFieldErrors}
              passwordError={auth.passwordError}
              commonError={auth.commonError}
              emailCodeInputRef={auth.emailCodeInputRef}
              emailSendActionRef={auth.emailSendActionRef}
              isEmailReadyForVerification={isEmailReadyForVerification}
              onSubmit={auth.onRegisterSubmit}
              onRegisterEmailChange={(value) => {
                auth.setRegisterEmail(value);
              }}
              onRegisterPasswordChange={(value) => {
                auth.setRegisterPassword(value);
              }}
              onRegisterPrivacyAcceptedChange={(value) => {
                auth.setRegisterPrivacyAccepted(value);
              }}
              onRegisterOfferAcceptedChange={(value) => {
                auth.setRegisterOfferAccepted(value);
              }}
              onRegisterMarketingAcceptedChange={(value) => {
                auth.setRegisterMarketingAccepted(value);
              }}
              onEmailCodeChange={(code) => {
                auth.setEmailCode(code);
                auth.setEmailServiceError("");
              }}
              onTogglePassword={() => auth.setShowRegisterPassword((current) => !current)}
              onEmailStart={() => void auth.onEmailVerificationStart()}
              onEmailConfirmAuto={(code) => {
                void auth.onEmailVerificationConfirm(undefined, code);
              }}
              resetEmailVerificationState={() => {
                auth.setEmailVerified(false);
                auth.setEmailCodeSent(false);
                auth.setEmailVerificationToken("");
                auth.setEmailServiceError("");
                auth.setEmailOccupied(false);
              }}
            />
          ) : null}

          {mode === "forgot" ? (
            <ForgotModeForm
              forgotEmail={auth.forgotEmail}
              stateLoading={auth.state === "loading"}
              resendSeconds={auth.forgotResendSeconds}
              commonError={auth.commonError}
              commonMessage={auth.commonMessage}
              onEmailChange={auth.setForgotEmail}
              onSubmit={auth.onForgotSubmit}
            />
          ) : null}

          {mode === "reset" ? (
            <ResetModeForm
              hasResetTokenFromUrl={hasResetTokenFromUrl}
              hasResetEmailFromUrl={hasResetEmailFromUrl}
              resetToken={auth.resetToken}
              resetEmail={auth.resetEmail}
              resetPassword={auth.resetPassword}
              showResetPassword={auth.showResetPassword}
              stateLoading={auth.state === "loading"}
              passwordError={auth.passwordError}
              commonError={auth.commonError}
              commonMessage={auth.commonMessage}
              onResetTokenChange={auth.setResetToken}
              onResetEmailChange={auth.setResetEmail}
              onResetPasswordChange={auth.setResetPassword}
              onTogglePassword={() => auth.setShowResetPassword((current) => !current)}
              onSubmit={auth.onResetSubmit}
            />
          ) : null}

          {mode === "verify-phone" ? (
            <VerifyPhoneModeForm
              verifyPhone={auth.verifyPhone}
              stateLoading={auth.state === "loading"}
              phoneVerificationPending={auth.phoneVerificationPending}
              phoneCallNumber={auth.phoneCallNumber}
              phoneCallHref={auth.phoneCallHref}
              commonError={auth.commonError}
              commonMessage={auth.commonMessage}
              onVerifyPhoneChange={auth.setVerifyPhone}
              onSubmit={auth.onVerifyPhoneStart}
            />
          ) : null}

          {mode === "verify" ? (
            <VerifyProfileMode
              emailVerified={auth.emailVerified}
              phoneVerified={auth.phoneVerified}
              emailCode={auth.emailCode}
              verifyPhone={auth.verifyPhone}
              stateLoading={auth.state === "loading"}
              phoneVerificationPending={auth.phoneVerificationPending}
              phoneCallNumber={auth.phoneCallNumber}
              phoneCallHref={auth.phoneCallHref}
              onEmailCodeChange={auth.setEmailCode}
              onVerifyPhoneChange={auth.setVerifyPhone}
              onEmailStart={() => void auth.onEmailVerificationStart()}
              onEmailConfirm={(event) => void auth.onEmailVerificationConfirm(event)}
              onPhoneStart={auth.onVerifyPhoneStart}
            />
          ) : null}

          {mode === "login" ? (
            <div className="auth-mode-links mt-5 flex items-center justify-center gap-2 px-1 text-sm font-medium" data-ui="auth.modeLinks">
              <span className="ui-text-secondary">Нет аккаунта?</span>
              <Link
                href="/auth?mode=register"
                onClick={auth.resetAuthFeedback}
                className="link-text auth-mode-link"
                data-ui="auth.modeLinks.register"
              >
                Зарегистрироваться
              </Link>
            </div>
          ) : null}

          {mode === "register" && !auth.emailCodeSent && !auth.emailVerified ? (
            <div className="auth-mode-links mt-5 flex items-center justify-center gap-2 px-1 text-sm font-medium" data-ui="auth.modeLinks">
              <span className="ui-text-secondary">Уже зарегистрированы?</span>
              <Link
                href="/auth"
                onClick={auth.resetAuthFeedback}
                className="link-text auth-mode-link"
                data-ui="auth.modeLinks.login"
              >
                Войти
              </Link>
            </div>
          ) : null}

          {mode === "forgot" ? (
            <div className="auth-mode-links mt-5 flex items-center justify-center gap-2 px-1 text-sm font-medium" data-ui="auth.modeLinks">
              <span className="ui-text-secondary">Вспомнили пароль?</span>
              <Link
                href="/auth"
                onClick={auth.resetAuthFeedback}
                className="link-text auth-mode-link"
                data-ui="auth.modeLinks.login"
              >
                Войти
              </Link>
            </div>
          ) : null}
        </div>
      </section>
    </main>
  );
}

export default function AuthPage() {
  return (
    <Suspense fallback={null}>
      <AuthPageContent />
    </Suspense>
  );
}
