"use client";

import { FormEvent, useCallback, useEffect, useId, useRef, useState } from "react";
import { useRouter } from "next/navigation";

import Button from "@/components/button/Button";
import { FormError } from "@/components/form/FormError";
import { FormStatus } from "@/components/form/FormStatus";
import {
  createTrustedBrowserChallenge,
  loadTrustedBrowserSlots,
  loadTrustedBrowserStatus,
  revokeTrustedBrowser,
  TrustedBrowserApiError,
  verifyTrustedBrowserChallenge,
} from "@/lib/trustedBrowserApi";
import type {
  TrustedBrowserChallenge,
  TrustedBrowserSlot,
  TrustedBrowserSlotNumber,
  TrustedBrowserState,
  TrustedBrowserStatus,
} from "@/lib/trustedBrowserApi";

type TrustedBrowserPanelProps = {
  readonly apiBaseUrl: string;
};

type PanelState =
  | { readonly status: "loading" }
  | {
      readonly status: "ready";
      readonly trust: TrustedBrowserStatus;
      readonly slots: ReadonlyArray<TrustedBrowserSlot>;
    }
  | { readonly status: "error"; readonly message: string };

type ChallengeFlow =
  | {
      readonly purpose: "enroll";
      readonly challenge: TrustedBrowserChallenge;
    }
  | {
      readonly purpose: "revoke";
      readonly challenge: TrustedBrowserChallenge;
      readonly slotNumber: TrustedBrowserSlotNumber;
    };

type BusyAction =
  | "enroll_start"
  | "challenge_resend"
  | "challenge_verify"
  | "revoke_start"
  | "revoke_direct"
  | null;

type ActiveBusyAction = Exclude<BusyAction, null>;

const inputClassName =
  "ui-input h-12 w-full rounded-[var(--radius-control)] border px-4 text-base font-medium outline-none disabled:cursor-not-allowed disabled:opacity-60";

function trustedBrowserStateText(state: TrustedBrowserState): string {
  switch (state) {
    case "trusted":
      return "Этот браузер подтверждён и может открывать защищённые материалы.";
    case "unknown_can_confirm":
      return "Этот браузер ещё не подтверждён. Подтвердите его кодом из email.";
    case "unknown_support_required":
      return "Все три слота заняты. Чтобы подтвердить этот браузер, обратитесь в поддержку.";
    case "revoked_current":
      return "Доступ для этого браузера отключён. Его слот остаётся занятым до освобождения поддержкой.";
    case "risk_step_up_required":
      return "Доступ для этого браузера приостановлен. Для восстановления обратитесь в поддержку.";
  }
}

function slotStatusText(slot: TrustedBrowserSlot): string {
  switch (slot.status) {
    case "empty":
      return "Свободный слот";
    case "active":
      return "Доступ включён";
    case "revoked_locked":
      return "Доступ отключён, слот занят";
    case "risk_locked":
      return "Доступ приостановлен, слот занят";
    case "released":
      return "Слот освобождён";
  }
}

function slotDisplayText(slot: TrustedBrowserSlot): string {
  const labels = [slot.browserLabel, slot.osLabel, slot.deviceLabel].filter(
    (label): label is string => label !== null,
  );

  if (labels.length > 0) {
    return labels.join(" · ");
  }

  return slot.status === "empty" || slot.status === "released"
    ? "Браузер не назначен"
    : "Данные браузера недоступны";
}

function actionErrorMessage(error: unknown): string {
  if (error instanceof TrustedBrowserApiError) {
    return error.message;
  }

  return "Не удалось выполнить действие. Попробуйте ещё раз.";
}

export default function TrustedBrowserPanel({ apiBaseUrl }: TrustedBrowserPanelProps) {
  const router = useRouter();
  const [panel, setPanel] = useState<PanelState>({ status: "loading" });
  const [challengeFlow, setChallengeFlow] = useState<ChallengeFlow | null>(null);
  const [code, setCode] = useState("");
  const [password, setPassword] = useState("");
  const [showRevokePasswordFor, setShowRevokePasswordFor] = useState<TrustedBrowserSlotNumber | null>(null);
  const [confirmDirectRevokeFor, setConfirmDirectRevokeFor] = useState<TrustedBrowserSlotNumber | null>(null);
  const [resendSeconds, setResendSeconds] = useState(0);
  const [busyAction, setBusyAction] = useState<BusyAction>(null);
  const [actionError, setActionError] = useState("");
  const [actionStatus, setActionStatus] = useState("");
  const codeInputRef = useRef<HTMLInputElement>(null);
  const actionInFlightRef = useRef(false);
  const actionErrorId = useId();
  const activeChallengeId = challengeFlow?.challenge.challengeId;

  const fetchPanelData = useCallback(async (signal?: AbortSignal): Promise<Extract<PanelState, { status: "ready" }>> => {
    const [trust, slots] = await Promise.all([
      loadTrustedBrowserStatus(apiBaseUrl, { signal }),
      loadTrustedBrowserSlots(apiBaseUrl, { signal }),
    ]);

    return { status: "ready", trust, slots };
  }, [apiBaseUrl]);

  useEffect(() => {
    const controller = new AbortController();

    void fetchPanelData(controller.signal)
      .then((data) => {
        if (!controller.signal.aborted) {
          setPanel(data);
        }
      })
      .catch((error: unknown) => {
        if (!controller.signal.aborted) {
          setPanel({ status: "error", message: actionErrorMessage(error) });
        }
      });

    return () => controller.abort();
  }, [fetchPanelData]);

  useEffect(() => {
    if (resendSeconds <= 0) {
      return;
    }

    const timerId = window.setInterval(() => {
      setResendSeconds((seconds) => (seconds > 0 ? seconds - 1 : 0));
    }, 1_000);

    return () => window.clearInterval(timerId);
  }, [resendSeconds]);

  useEffect(() => {
    if (!activeChallengeId) {
      return;
    }

    const frameId = window.requestAnimationFrame(() => codeInputRef.current?.focus());
    return () => window.cancelAnimationFrame(frameId);
  }, [activeChallengeId]);

  async function reloadPanel(): Promise<void> {
    try {
      setPanel(await fetchPanelData());
    } catch {
      setActionStatus("Изменение применено, но список не обновился. Перезагрузите страницу.");
    }
  }

  async function retryPanelLoad(): Promise<void> {
    setPanel({ status: "loading" });

    try {
      setPanel(await fetchPanelData());
    } catch (error) {
      setPanel({ status: "error", message: actionErrorMessage(error) });
    }
  }

  function resetTransientState(): void {
    setChallengeFlow(null);
    setCode("");
    setPassword("");
    setShowRevokePasswordFor(null);
    setConfirmDirectRevokeFor(null);
    setResendSeconds(0);
    setActionError("");
  }

  function beginAsyncAction(action: ActiveBusyAction): boolean {
    if (actionInFlightRef.current) {
      return false;
    }

    actionInFlightRef.current = true;
    setBusyAction(action);

    return true;
  }

  function finishAsyncAction(): void {
    actionInFlightRef.current = false;
    setBusyAction(null);
  }

  async function startEnrollChallenge(): Promise<void> {
    if (challengeFlow !== null || !beginAsyncAction("enroll_start")) {
      return;
    }

    setActionError("");
    setActionStatus("");

    try {
      const challenge = await createTrustedBrowserChallenge({ purpose: "enroll" }, apiBaseUrl);
      setChallengeFlow({ purpose: "enroll", challenge });
      setCode("");
      setResendSeconds(challenge.resendAfterSeconds);
      setActionStatus("Код отправлен на подтверждённый email.");
    } catch (error) {
      setActionError(actionErrorMessage(error));
    } finally {
      finishAsyncAction();
    }
  }

  async function startRevokeChallenge(event: FormEvent<HTMLFormElement>): Promise<void> {
    event.preventDefault();
    if (showRevokePasswordFor === null) {
      return;
    }
    if (password === "") {
      setActionError("Введите текущий пароль.");
      return;
    }
    if (challengeFlow !== null || !beginAsyncAction("revoke_start")) {
      return;
    }

    setActionError("");
    setActionStatus("");

    try {
      const challenge = await createTrustedBrowserChallenge(
        { purpose: "revoke", slotNumber: showRevokePasswordFor, password },
        apiBaseUrl,
      );
      setChallengeFlow({ purpose: "revoke", slotNumber: showRevokePasswordFor, challenge });
      setCode("");
      setResendSeconds(challenge.resendAfterSeconds);
      setActionStatus("Пароль принят. Код отправлен на подтверждённый email.");
    } catch (error) {
      setActionError(actionErrorMessage(error));
    } finally {
      finishAsyncAction();
    }
  }

  async function resendChallenge(): Promise<void> {
    if (!challengeFlow || resendSeconds > 0 || !beginAsyncAction("challenge_resend")) {
      return;
    }

    setActionError("");
    setActionStatus("");

    try {
      const challenge = challengeFlow.purpose === "enroll"
        ? await createTrustedBrowserChallenge({ purpose: "enroll" }, apiBaseUrl)
        : await createTrustedBrowserChallenge(
            { purpose: "revoke", slotNumber: challengeFlow.slotNumber, password },
            apiBaseUrl,
          );

      setChallengeFlow(
        challengeFlow.purpose === "enroll"
          ? { purpose: "enroll", challenge }
          : { purpose: "revoke", slotNumber: challengeFlow.slotNumber, challenge },
      );
      setCode("");
      setResendSeconds(challenge.resendAfterSeconds);
      setActionStatus("Новый код отправлен на подтверждённый email.");
    } catch (error) {
      setActionError(actionErrorMessage(error));
    } finally {
      finishAsyncAction();
    }
  }

  async function verifyChallenge(event: FormEvent<HTMLFormElement>): Promise<void> {
    event.preventDefault();
    if (!challengeFlow) {
      return;
    }
    if (!/^\d{6}$/.test(code)) {
      setActionError("Введите шестизначный код из email.");
      return;
    }
    if (!beginAsyncAction("challenge_verify")) {
      return;
    }

    const completedPurpose = challengeFlow.purpose;
    setActionError("");
    setActionStatus("");

    try {
      await verifyTrustedBrowserChallenge(challengeFlow.challenge.challengeId, code, apiBaseUrl);
      resetTransientState();
      setActionStatus(
        completedPurpose === "enroll"
          ? "Браузер подтверждён."
          : "Доступ отключён. Слот остаётся занятым до освобождения поддержкой.",
      );
      await reloadPanel();
    } catch (error) {
      setActionError(actionErrorMessage(error));
    } finally {
      finishAsyncAction();
    }
  }

  async function revokeDirectly(slotNumber: TrustedBrowserSlotNumber): Promise<void> {
    if (panel.status !== "ready") {
      return;
    }
    if (!beginAsyncAction("revoke_direct")) {
      return;
    }

    const targetSlot = panel.slots.find((slot) => slot.slotNumber === slotNumber);
    setActionError("");
    setActionStatus("");

    try {
      await revokeTrustedBrowser(slotNumber, apiBaseUrl);
      setConfirmDirectRevokeFor(null);
      setActionStatus("Доступ отключён. Слот остаётся занятым до освобождения поддержкой.");

      if (targetSlot?.isCurrent) {
        router.push("/auth?mode=login");
        return;
      }

      await reloadPanel();
    } catch (error) {
      setActionError(actionErrorMessage(error));
    } finally {
      finishAsyncAction();
    }
  }

  function beginRevoke(slotNumber: TrustedBrowserSlotNumber): void {
    if (
      busyAction !== null ||
      challengeFlow !== null ||
      showRevokePasswordFor !== null ||
      confirmDirectRevokeFor !== null
    ) {
      return;
    }

    resetTransientState();
    setActionStatus("");

    if (panel.status === "ready" && panel.trust.state === "trusted") {
      setConfirmDirectRevokeFor(slotNumber);
      return;
    }

    setShowRevokePasswordFor(slotNumber);
  }

  if (panel.status === "loading") {
    return (
      <section
        id="trusted-browsers"
        className="min-w-0 scroll-mt-24 border-t border-[var(--color-border-subtle)] pt-5"
        aria-labelledby="trusted-browsers-title"
        aria-busy="true"
        data-ui="lk.trustedBrowsers"
        data-state="loading"
      >
        <h2 id="trusted-browsers-title" className="text-lg font-medium leading-tight">
          Доверенные браузеры
        </h2>
        <FormStatus className="mt-3 px-0" tone="pending" dataUi="lk.trustedBrowsers.status">
          Загружаем состояние браузеров.
        </FormStatus>
      </section>
    );
  }

  if (panel.status === "error") {
    return (
      <section
        id="trusted-browsers"
        className="min-w-0 scroll-mt-24 border-t border-[var(--color-border-subtle)] pt-5"
        aria-labelledby="trusted-browsers-title"
        data-ui="lk.trustedBrowsers"
        data-state="error"
      >
        <h2 id="trusted-browsers-title" className="text-lg font-medium leading-tight">
          Доверенные браузеры
        </h2>
        <FormError className="mt-3 px-0" dataUi="lk.trustedBrowsers.error">
          {panel.message}
        </FormError>
        <Button
          type="button"
          variant="secondary"
          fullWidth={false}
          className="mt-4 w-full sm:w-fit"
          onClick={() => void retryPanelLoad()}
          data-ui="lk.trustedBrowsers.retryButton"
        >
          Повторить
        </Button>
      </section>
    );
  }

  const challengeHeading = challengeFlow?.purpose === "revoke"
    ? `Отключить доступ для слота ${challengeFlow.slotNumber}`
    : "Подтвердить этот браузер";
  const challengeButtonLabel = challengeFlow?.purpose === "revoke" ? "Отключить доступ" : "Подтвердить браузер";
  const actionBusy = busyAction !== null;
  const flowOpen =
    challengeFlow !== null || showRevokePasswordFor !== null || confirmDirectRevokeFor !== null;

  return (
    <section
      id="trusted-browsers"
      className="min-w-0 scroll-mt-24 border-t border-[var(--color-border-subtle)] pt-5"
      aria-labelledby="trusted-browsers-title"
      data-ui="lk.trustedBrowsers"
      data-state={panel.trust.state}
    >
      <div className="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between sm:gap-5">
        <div className="min-w-0">
          <h2 id="trusted-browsers-title" className="text-lg font-medium leading-tight">
            Доверенные браузеры
          </h2>
          <p className="mt-2 max-w-3xl text-sm leading-5 ui-text-secondary" data-ui="lk.trustedBrowsers.currentState">
            {trustedBrowserStateText(panel.trust.state)}
          </p>
        </div>
        {panel.trust.state === "unknown_can_confirm" && !flowOpen ? (
          <Button
            type="button"
            fullWidth={false}
            className="w-full shrink-0 sm:w-fit"
            disabled={actionBusy || flowOpen}
            loading={busyAction === "enroll_start"}
            aria-busy={busyAction === "enroll_start" ? true : undefined}
            onClick={() => void startEnrollChallenge()}
            data-ui="lk.trustedBrowsers.confirmButton"
          >
            Подтвердить этот браузер
          </Button>
        ) : null}
      </div>

      <p className="mt-4 text-sm leading-5 ui-text-secondary">
        Отключение сразу прекращает доступ на выбранном браузере, но не освобождает его слот.
      </p>

      <ol
        className="mt-5 divide-y divide-[var(--color-border-subtle)] border-y border-[var(--color-border-subtle)]"
        data-ui="lk.trustedBrowsers.slotList"
      >
        {panel.slots.map((slot) => (
          <li
            key={slot.slotNumber}
            className="flex min-w-0 flex-col gap-2 py-3 sm:flex-row sm:items-center sm:justify-between sm:gap-4"
            data-ui="lk.trustedBrowsers.slot"
            data-slot={slot.slotNumber}
            data-status={slot.status}
          >
            <div className="min-w-0">
              <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
                <h3 className="text-sm font-medium ui-text-primary">Слот {slot.slotNumber}</h3>
                {slot.isCurrent ? (
                  <span className="rounded-full bg-[var(--color-brand-surface)] px-2 py-1 text-xs font-semibold ui-text-tertiary">
                    Этот браузер
                  </span>
                ) : null}
              </div>
              <p className="mt-1 break-words text-sm leading-5 ui-text-tertiary" data-ui="lk.trustedBrowsers.slot.device">
                {slotDisplayText(slot)}
              </p>
              <p className="text-sm leading-5 ui-text-muted-ink" data-ui="lk.trustedBrowsers.slot.status">
                {slotStatusText(slot)}
              </p>
            </div>
            {slot.status === "active" || slot.status === "risk_locked" ? (
              <Button
                type="button"
                variant="secondary"
                fullWidth={false}
                className="w-full shrink-0 sm:w-fit"
                disabled={actionBusy || flowOpen}
                onClick={() => beginRevoke(slot.slotNumber)}
                data-ui="lk.trustedBrowsers.slot.revokeButton"
              >
                Отключить доступ
              </Button>
            ) : null}
          </li>
        ))}
      </ol>

      {confirmDirectRevokeFor !== null ? (
        <div
          className="mt-5 rounded-[var(--radius-inner)] border border-[var(--color-border-subtle)] bg-[var(--color-surface-subtle)] p-4"
          data-ui="lk.trustedBrowsers.directRevokeConfirm"
        >
          <h3 className="text-base font-semibold ui-text-primary">Отключить доступ для слота {confirmDirectRevokeFor}?</h3>
          <p className="mt-2 text-sm leading-6 ui-text-secondary">
            Связанные с ним сеансы завершатся. Слот останется занятым до освобождения поддержкой.
          </p>
          <div className="mt-4 flex flex-col gap-3 sm:flex-row">
            <Button
              type="button"
              fullWidth={false}
              className="w-full sm:w-fit"
              disabled={actionBusy}
              loading={busyAction === "revoke_direct"}
              aria-busy={busyAction === "revoke_direct" ? true : undefined}
              onClick={() => void revokeDirectly(confirmDirectRevokeFor)}
              data-ui="lk.trustedBrowsers.directRevokeConfirm.submitButton"
            >
              Подтвердить отключение
            </Button>
            <Button
              type="button"
              variant="secondary"
              fullWidth={false}
              className="w-full sm:w-fit"
              disabled={actionBusy}
              onClick={resetTransientState}
            >
              Отмена
            </Button>
          </div>
        </div>
      ) : null}

      {showRevokePasswordFor !== null && challengeFlow === null ? (
        <form
          className="mt-5 rounded-[var(--radius-inner)] border border-[var(--color-border-subtle)] bg-[var(--color-surface-subtle)] p-4"
          onSubmit={startRevokeChallenge}
          data-ui="lk.trustedBrowsers.revokePasswordForm"
        >
          <h3 className="text-base font-semibold ui-text-primary">Отключить доступ для слота {showRevokePasswordFor}</h3>
          <p className="mt-2 text-sm leading-6 ui-text-secondary">
            В неизвестном браузере сначала подтвердите текущий пароль, затем код из email.
          </p>
          <div className="mt-4 grid gap-2">
            <label htmlFor="trusted-browser-revoke-password" className="text-sm font-medium ui-text-soft-ink">
              Текущий пароль
            </label>
            <input
              id="trusted-browser-revoke-password"
              type="password"
              autoComplete="current-password"
              className={inputClassName}
              value={password}
              disabled={actionBusy}
              aria-invalid={actionError ? true : undefined}
              aria-describedby={actionError ? actionErrorId : undefined}
              onChange={(event) => setPassword(event.target.value)}
              data-ui="lk.trustedBrowsers.revokePasswordInput"
            />
          </div>
          <div className="mt-4 flex flex-col gap-3 sm:flex-row">
            <Button
              type="submit"
              fullWidth={false}
              className="w-full sm:w-fit"
              disabled={actionBusy || password === ""}
              loading={busyAction === "revoke_start"}
              aria-busy={busyAction === "revoke_start" ? true : undefined}
              data-ui="lk.trustedBrowsers.revokePasswordSubmitButton"
            >
              Отправить код
            </Button>
            <Button
              type="button"
              variant="secondary"
              fullWidth={false}
              className="w-full sm:w-fit"
              disabled={actionBusy}
              onClick={resetTransientState}
            >
              Отмена
            </Button>
          </div>
        </form>
      ) : null}

      {challengeFlow ? (
        <form
          className="mt-5 rounded-[var(--radius-inner)] border border-[var(--color-border-subtle)] bg-[var(--color-surface-subtle)] p-4"
          onSubmit={verifyChallenge}
          data-ui={`lk.trustedBrowsers.${challengeFlow.purpose}CodeForm`}
        >
          <h3 className="text-base font-semibold ui-text-primary">{challengeHeading}</h3>
          <p className="mt-2 text-sm leading-6 ui-text-secondary">
            Введите шестизначный код из письма. Код действует 10 минут.
          </p>
          <div className="mt-4 grid gap-2">
            <label htmlFor="trusted-browser-email-code" className="text-sm font-medium ui-text-soft-ink">
              Код из email
            </label>
            <input
              ref={codeInputRef}
              id="trusted-browser-email-code"
              type="text"
              inputMode="numeric"
              autoComplete="one-time-code"
              pattern="[0-9]{6}"
              maxLength={6}
              className={inputClassName}
              value={code}
              disabled={actionBusy}
              aria-invalid={actionError ? true : undefined}
              aria-describedby={actionError ? actionErrorId : undefined}
              onChange={(event) => setCode(event.target.value.replace(/\D/g, "").slice(0, 6))}
              data-ui="lk.trustedBrowsers.emailCodeInput"
            />
          </div>
          <div className="mt-4 flex flex-col gap-3 sm:flex-row sm:flex-wrap">
            <Button
              type="submit"
              fullWidth={false}
              className="w-full sm:w-fit"
              disabled={actionBusy || code.length !== 6}
              loading={busyAction === "challenge_verify"}
              aria-busy={busyAction === "challenge_verify" ? true : undefined}
              data-ui="lk.trustedBrowsers.emailCodeSubmitButton"
            >
              {challengeButtonLabel}
            </Button>
            <Button
              type="button"
              variant="secondary"
              fullWidth={false}
              className="w-full sm:w-fit"
              disabled={actionBusy || resendSeconds > 0}
              loading={busyAction === "challenge_resend"}
              aria-busy={busyAction === "challenge_resend" ? true : undefined}
              onClick={() => void resendChallenge()}
              data-ui="lk.trustedBrowsers.emailCodeResendButton"
            >
              {resendSeconds > 0 ? `Отправить снова через ${resendSeconds} с` : "Отправить код ещё раз"}
            </Button>
            <Button
              type="button"
              variant="secondary"
              fullWidth={false}
              className="w-full sm:w-fit"
              disabled={actionBusy}
              onClick={resetTransientState}
            >
              Отмена
            </Button>
          </div>
        </form>
      ) : null}

      <FormStatus className="mt-4 px-0" dataUi="lk.trustedBrowsers.actionStatus" tone="success">
        {actionStatus}
      </FormStatus>
      <FormError id={actionErrorId} className="mt-4 px-0" dataUi="lk.trustedBrowsers.actionError">
        {actionError}
      </FormError>
    </section>
  );
}
