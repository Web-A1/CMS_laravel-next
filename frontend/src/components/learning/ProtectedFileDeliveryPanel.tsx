"use client";

import Button from "@/components/button/Button";

import { useCallback, useEffect, useId, useMemo, useRef, useState } from "react";

import { FormError } from "@/components/form/FormError";
import { FormStatus } from "@/components/form/FormStatus";
import TextLink from "@/components/content/TextLink";
import type { LearningFileMaterial } from "@/lib/learningApi";
import {
  grantProtectedFileAccess,
  loadProtectedFileDelivery,
  prewarmProtectedFileDeliveries,
  requestProtectedFileDelivery,
  protectedFileOwnerPath,
  type ProtectedFileRoute,
} from "@/lib/protectedFileApi";
import type { ProtectedFileDelivery, ProtectedFileWarmupDelivery } from "@/lib/protectedFileApi";
import type { TrustedBrowserProtectedErrorCode } from "@/lib/trustedBrowserApi";

type ProtectedFileDeliveryPanelProps = {
  readonly apiBaseUrl: string;
  readonly route: ProtectedFileRoute;
  readonly files: ReadonlyArray<LearningFileMaterial>;
};

type FilePanelState =
  | { readonly status: "idle" }
  | { readonly status: "requesting" }
  | {
      readonly status: "pending" | "processing";
      readonly delivery: ProtectedFileDelivery;
      readonly downloadAfterReady: boolean;
      readonly pollingDeadlineAt: number;
    }
  | {
      readonly status: "verifying_retry";
      readonly delivery: ProtectedFileDelivery;
      readonly downloadAfterReady: boolean;
      readonly retryDeadlineAt: number;
    }
  | { readonly status: "ready"; readonly delivery: ProtectedFileDelivery }
  | { readonly status: "accessing"; readonly delivery: ProtectedFileDelivery }
  | { readonly status: "checking"; readonly delivery: ProtectedFileDelivery }
  | { readonly status: "failed"; readonly delivery: ProtectedFileDelivery }
  | { readonly status: "unavailable"; readonly delivery?: ProtectedFileDelivery }
  | { readonly status: "trusted_browser_recovery"; readonly code: TrustedBrowserProtectedErrorCode }
  | { readonly status: "denied" };

const POLLING_INTERVAL_MS = 3_000;
const POLLING_TIMEOUT_MS = 5 * 60_000;
const FAILED_RETRY_VERIFICATION_MS = 30_000;
const fileDownloadStatusClass = "px-0 text-right text-xs leading-5";
const FILE_CONFIDENTIALITY_STORAGE_PREFIX = "protected-file:confidentiality-warning";
const FILE_CONFIDENTIALITY_STORAGE_VALUE = "shown";

function fileConfidentialityStorageKey(route: ProtectedFileRoute): string {
  return `${FILE_CONFIDENTIALITY_STORAGE_PREFIX}:${route.kind}:${protectedFileOwnerPath(route)}`;
}

function readFileConfidentialityAcknowledged(storageKey: string): boolean {
  if (typeof window === "undefined") {
    return false;
  }

  try {
    return window.sessionStorage.getItem(storageKey) === FILE_CONFIDENTIALITY_STORAGE_VALUE;
  } catch {
    return false;
  }
}

function writeFileConfidentialityAcknowledged(storageKey: string): void {
  if (typeof window === "undefined") {
    return;
  }

  try {
    window.sessionStorage.setItem(storageKey, FILE_CONFIDENTIALITY_STORAGE_VALUE);
  } catch {
    return;
  }
}

function protectedFileStatusMessage(state: FilePanelState): string | null {
  switch (state.status) {
    case "requesting":
    case "checking":
      return "Готовим файл к скачиванию.";
    case "pending":
      return state.downloadAfterReady ? "Скачивание начнётся автоматически после подготовки." : "Файл готовится.";
    case "processing":
      return state.downloadAfterReady ? "Скачивание начнётся автоматически." : "Наносим персональную метку.";
    case "verifying_retry":
      return state.downloadAfterReady ? "Проверяем повторную подготовку." : "Проверяем файл.";
    case "ready":
      return null;
    case "accessing":
      return "Открываем скачивание.";
    case "idle":
    case "failed":
    case "unavailable":
    case "trusted_browser_recovery":
    case "denied":
      return null;
  }
}

function protectedFileErrorMessage(state: FilePanelState): string | null {
  switch (state.status) {
    case "failed":
      return "Не удалось подготовить файл. Попробуйте ещё раз.";
    case "unavailable":
      return "Файл временно недоступен. Попробуйте ещё раз.";
    case "denied":
      return "Доступ к файлу не подтверждён.";
    case "trusted_browser_recovery":
      return null;
    case "idle":
    case "requesting":
    case "pending":
    case "processing":
    case "verifying_retry":
    case "ready":
    case "accessing":
    case "checking":
      return null;
  }
}

function trustedBrowserFileRecoveryText(code: TrustedBrowserProtectedErrorCode): string {
  switch (code) {
    case "TRUSTED_BROWSER_REQUIRED":
      return "Подтвердите этот браузер, чтобы скачать файл.";
    case "TRUSTED_BROWSER_SUPPORT_REQUIRED":
      return "Все три слота заняты. Для доступа с этого браузера потребуется поддержка.";
    case "TRUSTED_BROWSER_RISK_LOCKED":
      return "Доступ с этого браузера приостановлен. Проверьте доверенные браузеры.";
  }
}

function ProtectedFileItem({
  apiBaseUrl,
  route,
  file,
  prewarmedDelivery,
}: Omit<ProtectedFileDeliveryPanelProps, "files"> & {
  readonly file: LearningFileMaterial;
  readonly prewarmedDelivery?: ProtectedFileWarmupDelivery;
}) {
  const confidentialityStorageKey = fileConfidentialityStorageKey(route);
  const [state, setState] = useState<FilePanelState>({ status: "idle" });
  const [acknowledgedConfidentialityStorageKey, setAcknowledgedConfidentialityStorageKey] = useState<string | null>(() =>
    readFileConfidentialityAcknowledged(confidentialityStorageKey) ? confidentialityStorageKey : null,
  );
  const [visibleConfidentialityStorageKey, setVisibleConfidentialityStorageKey] = useState<string | null>(null);
  const requestControllerRef = useRef<AbortController | null>(null);
  const retryButtonRef = useRef<HTMLButtonElement>(null);
  const previousStatusRef = useRef<FilePanelState["status"]>("idle");
  const actionButtonWrapperRef = useRef<HTMLDivElement>(null);
  const warningId = useId();
  const confidentialityAcknowledged = acknowledgedConfidentialityStorageKey === confidentialityStorageKey;
  const confidentialityWarningVisible = visibleConfidentialityStorageKey === confidentialityStorageKey;

  useEffect(() => {
    return () => requestControllerRef.current?.abort();
  }, []);

  useEffect(() => {
    const previousStatus = previousStatusRef.current;
    previousStatusRef.current = state.status;

    if (
      (state.status === "failed" || state.status === "unavailable" || state.status === "denied") &&
      previousStatus !== "idle" &&
      previousStatus !== state.status
    ) {
      retryButtonRef.current?.focus();
    }
  }, [state.status]);

  useEffect(() => {
    if (!confidentialityWarningVisible) {
      return;
    }

    function handleKeyDown(event: KeyboardEvent): void {
      if (event.key === "Escape") {
        event.preventDefault();
        setVisibleConfidentialityStorageKey(null);
      }
    }

    function handleMouseDown(event: MouseEvent): void {
      const target = event.target;
      if (!(target instanceof Node) || actionButtonWrapperRef.current?.contains(target) === true) {
        return;
      }

      setVisibleConfidentialityStorageKey(null);
    }

    document.addEventListener("keydown", handleKeyDown);
    document.addEventListener("mousedown", handleMouseDown);
    return () => {
      document.removeEventListener("keydown", handleKeyDown);
      document.removeEventListener("mousedown", handleMouseDown);
    };
  }, [confidentialityWarningVisible]);

  useEffect(() => {
    if (!prewarmedDelivery) {
      return;
    }

    const timer = window.setTimeout(() => setState((current) => {
      if (
        current.status === "accessing" ||
        current.status === "checking" ||
        current.status === "requesting" ||
        current.status === "denied" ||
        ((current.status === "pending" || current.status === "processing" || current.status === "verifying_retry") &&
          current.downloadAfterReady)
      ) {
        return current;
      }

      switch (prewarmedDelivery.status) {
        case "pending":
        case "processing":
          return {
            status: prewarmedDelivery.status,
            delivery: prewarmedDelivery,
            downloadAfterReady: false,
            pollingDeadlineAt: Date.now() + POLLING_TIMEOUT_MS,
          };
        case "ready":
          return { status: "ready", delivery: prewarmedDelivery };
        case "failed":
          return {
            status: "verifying_retry",
            delivery: prewarmedDelivery,
            downloadAfterReady: false,
            retryDeadlineAt: Date.now() + FAILED_RETRY_VERIFICATION_MS,
          };
      }
    }), 0);

    return () => window.clearTimeout(timer);
  }, [prewarmedDelivery]);

  const beginRequest = useCallback(function beginRequest(): AbortController {
    requestControllerRef.current?.abort();
    const controller = new AbortController();
    requestControllerRef.current = controller;

    return controller;
  }, []);

  const requestAccessForDelivery = useCallback(async function requestAccessForDelivery(
    delivery: ProtectedFileDelivery,
  ): Promise<void> {
    const controller = beginRequest();
    setState({ status: "accessing", delivery });

    const result = await grantProtectedFileAccess(
      apiBaseUrl,
      route,
      delivery,
      controller.signal,
    );
    if (requestControllerRef.current === controller) {
      requestControllerRef.current = null;
    }
    if (controller.signal.aborted || result.status === "aborted") {
      return;
    }
    if (result.status === "trusted_browser_recovery") {
      setState({ status: "trusted_browser_recovery", code: result.code });
      return;
    }
    if (result.status === "denied") {
      setState({ status: "denied" });
      return;
    }
    if (result.status === "not_ready") {
      setState({
        status: "pending",
        delivery: { ...delivery, status: "pending", canRequestAccess: false },
        downloadAfterReady: true,
        pollingDeadlineAt: Date.now() + POLLING_TIMEOUT_MS,
      });
      return;
    }
    if (result.status === "unavailable") {
      setState({ status: "unavailable", delivery });
      return;
    }

    setState({ status: "ready", delivery });
    // Это проверенная короткоживущая ссылка на attachment, а не внутренний маршрут Next.js.
    window.location.assign(result.downloadUrl);
  }, [apiBaseUrl, beginRequest, route]);

  useEffect(() => {
    if (state.status !== "pending" && state.status !== "processing" && state.status !== "verifying_retry") {
      return;
    }

    const now = Date.now();
    const deadlineAt = state.status === "verifying_retry" ? state.retryDeadlineAt : state.pollingDeadlineAt;
    const controller = new AbortController();
    let finished = false;

    const finishAtDeadline = () => {
      if (finished) {
        return;
      }

      finished = true;
      controller.abort();
      setState(
        state.status === "verifying_retry"
          ? { status: "failed", delivery: state.delivery }
          : { status: "unavailable", delivery: state.delivery },
      );
    };

    const deadlineTimer = window.setTimeout(finishAtDeadline, Math.max(0, deadlineAt - now));
    const timer = window.setTimeout(async () => {
      if (Date.now() >= deadlineAt) {
        finishAtDeadline();
        return;
      }

      const result = await loadProtectedFileDelivery(
        apiBaseUrl,
        route,
        state.delivery.deliveryId,
        controller.signal,
      );
      if (finished || controller.signal.aborted || result.status === "aborted") {
        return;
      }

      finished = true;
      if (result.status === "trusted_browser_recovery") {
        setState({ status: "trusted_browser_recovery", code: result.code });
        return;
      }
      if (result.status === "denied") {
        setState({ status: "denied" });
        return;
      }
      if (result.status === "unavailable") {
        setState({ status: "unavailable", delivery: state.delivery });
        return;
      }

      const delivery = result.data;
      if (delivery.status === "ready") {
        if (state.downloadAfterReady) {
          void requestAccessForDelivery(delivery);
          return;
        }

        setState({ status: "ready", delivery });
        return;
      }
      if (delivery.status === "failed") {
        setState(
          state.status === "verifying_retry" && Date.now() < state.retryDeadlineAt
            ? {
                status: "verifying_retry",
                delivery,
                downloadAfterReady: state.downloadAfterReady,
                retryDeadlineAt: state.retryDeadlineAt,
              }
            : { status: "failed", delivery },
        );
        return;
      }

      setState({
        status: delivery.status,
        delivery,
        downloadAfterReady: state.downloadAfterReady,
        pollingDeadlineAt: state.status === "verifying_retry" ? Date.now() + POLLING_TIMEOUT_MS : state.pollingDeadlineAt,
      });
    }, Math.max(0, Math.min(POLLING_INTERVAL_MS, deadlineAt - now)));

    return () => {
      finished = true;
      window.clearTimeout(timer);
      window.clearTimeout(deadlineTimer);
      controller.abort();
    };
  }, [apiBaseUrl, route, requestAccessForDelivery, state]);

  function applyDelivery(delivery: ProtectedFileDelivery, verifyFailed: boolean, downloadAfterReady: boolean): void {
    switch (delivery.status) {
      case "pending":
      case "processing":
        setState({
          status: delivery.status,
          delivery,
          downloadAfterReady,
          pollingDeadlineAt: Date.now() + POLLING_TIMEOUT_MS,
        });
        return;
      case "ready":
        if (downloadAfterReady) {
          void requestAccessForDelivery(delivery);
          return;
        }

        setState({ status: "ready", delivery });
        return;
      case "failed":
        setState(
          verifyFailed
            ? {
                status: "verifying_retry",
                delivery,
                downloadAfterReady,
                retryDeadlineAt: Date.now() + FAILED_RETRY_VERIFICATION_MS,
              }
            : { status: "failed", delivery },
        );
    }
  }

  async function requestDelivery(): Promise<void> {
    const controller = beginRequest();
    setState({ status: "requesting" });

    const result = await requestProtectedFileDelivery(
      apiBaseUrl,
      route,
      file.materialId,
      controller.signal,
    );
    if (requestControllerRef.current === controller) {
      requestControllerRef.current = null;
    }
    if (controller.signal.aborted || result.status === "aborted") {
      return;
    }
    if (result.status === "trusted_browser_recovery") {
      setState({ status: "trusted_browser_recovery", code: result.code });
      return;
    }
    if (result.status === "denied") {
      setState({ status: "denied" });
      return;
    }
    if (result.status === "unavailable") {
      setState({ status: "unavailable" });
      return;
    }

    applyDelivery(result.data, true, true);
  }

  async function checkDelivery(): Promise<void> {
    if (state.status !== "unavailable" || state.delivery === undefined) {
      await requestDelivery();
      return;
    }

    const delivery = state.delivery;
    const controller = beginRequest();
    setState({ status: "checking", delivery });

    const result = await loadProtectedFileDelivery(
      apiBaseUrl,
      route,
      delivery.deliveryId,
      controller.signal,
    );
    if (requestControllerRef.current === controller) {
      requestControllerRef.current = null;
    }
    if (controller.signal.aborted || result.status === "aborted") {
      return;
    }
    if (result.status === "trusted_browser_recovery") {
      setState({ status: "trusted_browser_recovery", code: result.code });
      return;
    }
    if (result.status === "denied") {
      setState({ status: "denied" });
      return;
    }
    if (result.status === "unavailable") {
      setState({ status: "unavailable", delivery });
      return;
    }

    applyDelivery(result.data, false, true);
  }

  async function requestAccess(): Promise<void> {
    if (state.status !== "ready") {
      return;
    }

    await requestAccessForDelivery(state.delivery);
  }

  function requestDownloadAfterReady(): void {
    setState((current) => {
      if (current.status !== "pending" && current.status !== "processing" && current.status !== "verifying_retry") {
        return current;
      }

      return { ...current, downloadAfterReady: true };
    });
  }

  function handleFileAction(): void {
    if (!confidentialityAcknowledged && !readFileConfidentialityAcknowledged(confidentialityStorageKey)) {
      writeFileConfidentialityAcknowledged(confidentialityStorageKey);
      setAcknowledgedConfidentialityStorageKey(confidentialityStorageKey);
      setVisibleConfidentialityStorageKey(confidentialityStorageKey);
      return;
    }

    if (!confidentialityAcknowledged) {
      setAcknowledgedConfidentialityStorageKey(confidentialityStorageKey);
    }
    setVisibleConfidentialityStorageKey(null);

    switch (state.status) {
      case "idle":
      case "failed":
      case "denied":
        void requestDelivery();
        return;
      case "pending":
      case "processing":
      case "verifying_retry":
        requestDownloadAfterReady();
        return;
      case "ready":
        void requestAccess();
        return;
      case "unavailable":
        void checkDelivery();
        return;
      case "trusted_browser_recovery":
      case "requesting":
      case "checking":
      case "accessing":
        return;
    }
  }

  const statusMessage = protectedFileStatusMessage(state);
  const errorMessage = protectedFileErrorMessage(state);
  const actionDisabled =
    state.status === "requesting" ||
    state.status === "checking" ||
    state.status === "accessing" ||
    ((state.status === "pending" || state.status === "processing" || state.status === "verifying_retry") &&
      state.downloadAfterReady);
  const shouldFocusRetry = state.status === "failed" || state.status === "unavailable" || state.status === "denied";
  const actionDataUi = state.status === "ready" || state.status === "accessing"
    ? "lk.learning.lessonFile.downloadButton"
    : "lk.learning.lessonFile.prepareButton";
  const isRetryState = state.status === "failed" || state.status === "unavailable" || state.status === "denied";
  const isPendingState = state.status === "pending" || state.status === "processing" || state.status === "verifying_retry";
  const showActionButton =
    state.status === "idle" ||
    isRetryState ||
    state.status === "ready" ||
    state.status === "accessing" ||
    (isPendingState && !state.downloadAfterReady);
  const actionLabel = state.status === "accessing" ? "Скачивание..." : isRetryState ? "Повторить" : "Скачать";
  const trailingStatusMessage = showActionButton ? null : statusMessage;

  return (
    <li
      className="grid min-w-0 gap-1"
      data-ui="lk.learning.lessonFile"
      data-state={state.status}
    >
      <div className="flex min-w-0 flex-wrap items-end gap-x-3 gap-y-2 sm:flex-nowrap">
        <span className="min-w-0 flex-[1_1_auto] truncate text-sm font-medium leading-6 ui-text-tertiary">
          {file.title}
        </span>
        <span
          className="mb-1 hidden h-px min-w-8 flex-[999_1_2rem] border-t border-dashed ui-border-control sm:block"
          aria-hidden="true"
        />
        <div ref={actionButtonWrapperRef} className="relative flex shrink-0 items-center justify-end text-right">
          {showActionButton ? (
            <Button variant="secondary" size="sm" fullWidth={false}
              ref={shouldFocusRetry ? retryButtonRef : undefined}
              type="button"
              loading={actionDisabled}
              onClick={handleFileAction}
              aria-busy={actionDisabled ? true : undefined}
              aria-controls={confidentialityWarningVisible ? warningId : undefined}
              aria-describedby={confidentialityWarningVisible ? `${warningId}-copy` : undefined}
              aria-expanded={confidentialityWarningVisible ? true : undefined}
              data-ui={actionDataUi}
            >
              {actionLabel}
            </Button>
          ) : (
            <FormStatus
              className={fileDownloadStatusClass}
              tone="pending"
              dataUi="lk.learning.lessonFile.status"
            >
              {trailingStatusMessage}
            </FormStatus>
          )}
          {confidentialityWarningVisible ? (
            <section
              id={warningId}
              className="absolute left-0 top-full z-40 mt-3 grid w-[min(28rem,calc(100vw-2rem))] gap-2 rounded-[var(--radius-card)] border border-[var(--color-border-subtle)] bg-white px-4 py-3 text-left shadow-[0_16px_44px_rgb(24_28_32_/_0.12)] before:absolute before:-top-1.5 before:left-8 before:h-3 before:w-3 before:rotate-45 before:border-l before:border-t before:border-[var(--color-border-subtle)] before:bg-white before:content-[''] sm:left-auto sm:right-0 sm:before:left-auto sm:before:right-8"
              role="status"
              aria-live="polite"
              data-ui="lk.learning.lessonFile.confidentialityWarning"
            >
              <div id={`${warningId}-copy`} className="grid gap-2">
                <p className="text-sm leading-6 ui-text-tertiary">
                  Файл предназначен только для личного обучения. Его нельзя распространять.
                </p>
                <p className="text-xs leading-5 ui-text-muted-ink">
                  Чтобы скачать файл, нажмите «Скачать» ещё раз.
                </p>
              </div>
            </section>
          ) : null}
        </div>
      </div>
      {state.status === "trusted_browser_recovery" ? (
        <div
          className="grid justify-items-end gap-1 text-right"
          data-ui="lk.learning.lessonFile.trustedBrowserRecovery"
        >
          <p className="text-xs leading-5 ui-text-secondary">{trustedBrowserFileRecoveryText(state.code)}</p>
          <TextLink href="/lk#trusted-browsers">Проверить доверенные браузеры</TextLink>
        </div>
      ) : null}
      {errorMessage ? (
        <FormError className={fileDownloadStatusClass} dataUi="lk.learning.lessonFile.error">
          {errorMessage}
        </FormError>
      ) : null}
    </li>
  );
}

function OwnedProtectedFileDeliveryPanel({
  apiBaseUrl,
  route,
  files,
}: ProtectedFileDeliveryPanelProps) {
  const [prewarmedDeliveries, setPrewarmedDeliveries] = useState<ReadonlyMap<number, ProtectedFileWarmupDelivery>>(
    () => new Map(),
  );
  const fileIdsKey = files.map((file) => file.materialId).join(",");

  useEffect(() => {
    if (files.length === 0) {
      return;
    }

    const controller = new AbortController();

    void prewarmProtectedFileDeliveries(
      apiBaseUrl,
      route,
      controller.signal,
    ).then((result) => {
      if (controller.signal.aborted || result.status !== "deliveries") {
        return;
      }

      setPrewarmedDeliveries(new Map(result.data.map((delivery) => [delivery.materialId, delivery])));
    });

    return () => controller.abort();
  }, [apiBaseUrl, route, fileIdsKey, files.length]);

  return (
    <section
      className="grid gap-4"
      aria-labelledby="lk-learning-files-title"
      data-ui="lk.learning.lessonFiles"
      data-state={files.length === 0 ? "empty" : "ready"}
    >
      <h2 id="lk-learning-files-title" className="text-2xl font-semibold leading-tight ui-text-emphasis">
        Скачать обучающие материалы
      </h2>
      {files.length === 0 ? (
        <p className="ui-meta-text" data-ui="lk.learning.lessonFiles.empty">
          PDF пока не прикреплены.
        </p>
      ) : (
        <ul className="grid gap-3" data-ui="lk.learning.lessonFiles.list">
          {files.map((file) => (
            <ProtectedFileItem
              key={file.materialId}
              apiBaseUrl={apiBaseUrl}
              route={route}
              file={file}
              prewarmedDelivery={prewarmedDeliveries.get(file.materialId)}
            />
          ))}
        </ul>
      )}
    </section>
  );
}

// Preserve the existing lesson caller while both owners share the same delivery UI.
export default function ProtectedFileDeliveryPanel(props: ProtectedFileDeliveryPanelProps | {
  readonly apiBaseUrl: string; readonly files: ReadonlyArray<LearningFileMaterial>;
  readonly subjectSlug: string; readonly courseSlug: string; readonly lessonSlug: string;
}) {
  const guideSlug = "route" in props && props.route.kind === "guide" ? props.route.guideSlug : null;
  const subjectSlug = "route" in props ? props.route.kind === "lesson" ? props.route.subjectSlug : "" : props.subjectSlug;
  const courseSlug = "route" in props ? props.route.kind === "lesson" ? props.route.courseSlug : "" : props.courseSlug;
  const lessonSlug = "route" in props ? props.route.kind === "lesson" ? props.route.lessonSlug : "" : props.lessonSlug;
  const route = useMemo<ProtectedFileRoute>(() => guideSlug !== null ? { kind: "guide", guideSlug } : { kind: "lesson", subjectSlug, courseSlug, lessonSlug }, [guideSlug, subjectSlug, courseSlug, lessonSlug]);
  return <OwnedProtectedFileDeliveryPanel key={protectedFileOwnerPath(route)} apiBaseUrl={props.apiBaseUrl} files={props.files} route={route} />;
}
