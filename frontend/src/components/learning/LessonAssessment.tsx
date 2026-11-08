"use client";

import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { usePathname, useRouter, useSearchParams } from "next/navigation";

import Button from "@/components/button/Button";
import {
  submitLessonAssessment,
  resetLessonAssessmentDevelopmentState,
  retakeLessonAssessment,
  saveLessonAssessment,
  startLessonAssessment,
} from "@/lib/lessonAssessmentApi";
import {
  lessonAssessmentPath,
  parseLessonAssessmentPayload,
  type LessonAssessmentData,
  type LessonAssessmentRoute,
} from "@/lib/lessonAssessmentContract";

import { LessonAssessmentQuestion } from "./LessonAssessmentQuestion";
import {
  LessonAssessmentSentenceCompletion,
  type LessonAssessmentSentenceCompletionEntry,
} from "./LessonAssessmentSentenceCompletion";
import {
  createLessonAssessmentSingleFlightRequestGate,
  createLessonAssessmentStartCoordinator,
  lessonAssessmentInteractionLocked,
  resolveLessonAssessmentAnswerAction,
} from "./lessonAssessmentController";
import {
  createProtectedDiagramRegistry,
  type ProtectedDiagramAsset,
} from "./lessonAssessmentProtectedImageRegistry";
import {
  isSentenceCompletionQuestion,
  lessonAssessmentNavigationItems,
  questionIdsForNavigationItem,
  sentenceCompletionQuestionsForNavigationItem,
  sentenceCompletionSegments,
} from "./lessonAssessmentSentenceCompletionModel";
import {
  LessonAssessmentAutomaticFailureResult,
  LessonAssessmentReviewResult,
  LessonAssessmentResult,
} from "./LessonAssessmentResult";
import {
  allVisibleQuestionsComplete,
  applySubmissionError,
  applySubmittedAttempt,
  applyConflictReload,
  applySaveError,
  applySavedAttempt,
  createLessonAssessmentSaveSnapshot,
  createLessonAssessmentState,
  discardLessonAssessmentChanges,
  hasPendingLessonAssessmentChanges,
  incorrectPartIdsForQuestion,
  isLessonAssessmentResponseComplete,
  markLessonAssessmentSubmitting,
  markLessonAssessmentSaving,
  questionHasAssessmentError,
  selectLessonAssessmentQuestion,
  setAnswer,
  visibleQuestionIds,
  type LessonAssessmentUiState,
} from "./lessonAssessmentState";

export type LessonAssessmentProps = {
  readonly apiBaseUrl: string;
  readonly initialData: LessonAssessmentData;
  readonly lessonPath: string;
  readonly requestedQuestionId: string | null;
  readonly route: LessonAssessmentRoute;
};

type StartStatus = "idle" | "starting" | "error" | "denied";
type DevelopmentResetStatus = "idle" | "resetting" | "error" | "denied";
type ReloadResult =
  | { readonly status: "ready"; readonly data: LessonAssessmentData }
  | { readonly status: "denied" | "aborted" | "unavailable" };

const DEVELOPMENT_RESET_ENABLED = process.env.NODE_ENV === "development";
const ANSWER_CONFIRMATION_PRIMARY_COPY =
  "Если вы уверены — нажмите «Ответить» ещё раз, и ответы на это задание будут зафиксированы системой. Это действие отменить невозможно.";
const ANSWER_CONFIRMATION_SECONDARY_COPY =
  "Если всё же вы хотите ещё подумать, нажмите «Сохранить». В этом случае ответы зафиксированы не будут, и вы сможете вернуться к этому вопросу ещё раз.";
const ANSWER_CONFIRMATION_STORAGE_PREFIX = "lesson-assessment:answer-confirmation";
const ANSWER_CONFIRMATION_STORAGE_VALUE = "shown";

function suggestionAcceptanceKey(questionId: string, partId: string): string {
  return `${questionId}\u0000${partId}`;
}

function answerConfirmationStorageKey(route: LessonAssessmentRoute): string {
  return `${ANSWER_CONFIRMATION_STORAGE_PREFIX}:${route.subjectSlug}:${route.courseSlug}:${route.lessonSlug}`;
}

function readAnswerConfirmationAcknowledged(storageKey: string): boolean {
  if (typeof window === "undefined") {
    return false;
  }

  try {
    return window.sessionStorage.getItem(storageKey) === ANSWER_CONFIRMATION_STORAGE_VALUE;
  } catch {
    return false;
  }
}

function writeAnswerConfirmationAcknowledged(storageKey: string): void {
  if (typeof window === "undefined") {
    return;
  }

  try {
    window.sessionStorage.setItem(storageKey, ANSWER_CONFIRMATION_STORAGE_VALUE);
  } catch {
    return;
  }
}

async function reloadLessonAssessment(
  apiBaseUrl: string,
  route: LessonAssessmentRoute,
  signal: AbortSignal,
): Promise<ReloadResult> {
  const path = lessonAssessmentPath(route);
  if (path === null) {
    return { status: "unavailable" };
  }

  try {
    const response = await fetch(`${apiBaseUrl.replace(/\/+$/, "")}${path}`, {
      method: "GET",
      credentials: "include",
      cache: "no-store",
      signal,
      headers: { Accept: "application/json" },
    });
    if (response.status === 401 || response.status === 403) {
      return { status: "denied" };
    }
    if (response.status !== 200) {
      return { status: "unavailable" };
    }

    const payload: unknown = await response.json().catch(() => null);
    const data = parseLessonAssessmentPayload(payload, route);
    return data === null ? { status: "unavailable" } : { status: "ready", data };
  } catch (error) {
    return error instanceof DOMException && error.name === "AbortError"
      ? { status: "aborted" }
      : { status: "unavailable" };
  }
}

export function LessonAssessment({
  apiBaseUrl,
  initialData,
  requestedQuestionId,
  route,
}: LessonAssessmentProps) {
  const pathname = usePathname();
  const router = useRouter();
  const searchParams = useSearchParams();
  const navigationQuestionId = searchParams.get("question") ?? requestedQuestionId;
  const confirmationStorageKey = answerConfirmationStorageKey(route);
  const [state, setState] = useState<LessonAssessmentUiState>(() =>
    createLessonAssessmentState(initialData, navigationQuestionId),
  );
  const [startStatus, setStartStatus] = useState<StartStatus>(
    initialData.attempt === null ? "starting" : "idle",
  );
  const [startRetry, setStartRetry] = useState(0);
  const [retakeStatus, setRetakeStatus] = useState<StartStatus>("idle");
  const [developmentResetStatus, setDevelopmentResetStatus] = useState<DevelopmentResetStatus>("idle");
  const [saveRequestInFlight, setSaveRequestInFlight] = useState(false);
  const [answerConfirmationAcknowledged, setAnswerConfirmationAcknowledged] = useState(() =>
    readAnswerConfirmationAcknowledged(confirmationStorageKey),
  );
  const [answerConfirmationVisible, setAnswerConfirmationVisible] = useState(false);
  const [acceptedSuggestionValues, setAcceptedSuggestionValues] = useState<Readonly<Record<string, string>>>({});
  const [unavailableDiagramQuestionIds, setUnavailableDiagramQuestionIds] = useState<ReadonlySet<string>>(
    () =>
      new Set(
        initialData.assessment.questions
          .filter((question) => question.type === "diagram_labels" || question.type === "diagram_text")
          .map((question) => question.id),
      ),
  );
  const [navigationBusy, setNavigationBusy] = useState(false);
  const stateRef = useRef(state);
  const mountedRef = useRef(true);
  const activeControllersRef = useRef(new Set<AbortController>());
  const answerButtonWrapperRef = useRef<HTMLDivElement | null>(null);
  const [startCoordinator] = useState(() =>
    createLessonAssessmentStartCoordinator<Awaited<ReturnType<typeof startLessonAssessment>>>(),
  );
  const [saveRequestGate] = useState(() => createLessonAssessmentSingleFlightRequestGate<boolean>());
  const stableRoute = useMemo<LessonAssessmentRoute>(
    () => ({
      subjectSlug: route.subjectSlug,
      courseSlug: route.courseSlug,
      lessonSlug: route.lessonSlug,
    }),
    [route.courseSlug, route.lessonSlug, route.subjectSlug],
  );

  const createRequestController = useCallback((): AbortController => {
    const controller = new AbortController();
    activeControllersRef.current.add(controller);
    return controller;
  }, []);

  const releaseRequestController = useCallback((controller: AbortController): void => {
    activeControllersRef.current.delete(controller);
  }, []);
  const acceptedSuggestionValueFor = useCallback(
    (questionId: string, partId: string): string | null =>
      acceptedSuggestionValues[suggestionAcceptanceKey(questionId, partId)] ?? null,
    [acceptedSuggestionValues],
  );
  const handleSuggestionAcceptedChange = useCallback((
    questionId: string,
    partId: string,
    value: string | null,
  ): void => {
    setAcceptedSuggestionValues((current) => {
      const key = suggestionAcceptanceKey(questionId, partId);
      if (value !== null && current[key] === value) {
        return current;
      }
      if (value === null && current[key] === undefined) {
        return current;
      }

      const next = { ...current };
      if (value === null) {
        delete next[key];
      } else {
        next[key] = value;
      }

      return next;
    });
  }, []);

  const updateState = useCallback(
    (transition: (current: LessonAssessmentUiState) => LessonAssessmentUiState): LessonAssessmentUiState => {
      const next = transition(stateRef.current);
      stateRef.current = next;
      if (mountedRef.current) {
        setState(next);
      }
      return next;
    },
    [],
  );
  const navigationQuestionsForState = useCallback((current: LessonAssessmentUiState) => {
    const visibleIds = new Set(visibleQuestionIds(current));
    return current.assessment.questions.filter((question) => visibleIds.has(question.id));
  }, []);
  const currentNavigationQuestionIdsForState = useCallback((current: LessonAssessmentUiState) => {
    return questionIdsForNavigationItem(
      navigationQuestionsForState(current),
      current.currentQuestionId,
    );
  }, [navigationQuestionsForState]);
  const nextVisibleNavigationQuestionIdForState = useCallback((current: LessonAssessmentUiState) => {
    const items = lessonAssessmentNavigationItems(navigationQuestionsForState(current));
    const currentQuestionId = current.currentQuestionId;
    if (currentQuestionId === null) {
      return null;
    }

    const currentIndex = items.findIndex((item) => item.questionIds.includes(currentQuestionId));
    if (currentIndex < 0 || currentIndex >= items.length - 1) {
      return null;
    }

    return items[currentIndex + 1]?.id ?? null;
  }, [navigationQuestionsForState]);
  const diagramAssets = useMemo<ReadonlyArray<ProtectedDiagramAsset>>(
    () => state.assessment.questions
      .filter((question) => question.type === "diagram_labels" || question.type === "diagram_text")
      .map((question) => ({
        assetPath: question.assetPath,
        width: question.width,
        height: question.height,
      })),
    [state.assessment.questions],
  );
  const assessmentAttemptId = state.attempt?.id ?? null;
  const assessmentAttemptStatus = state.attempt?.status ?? null;
  const submittedAttempt = assessmentAttemptStatus !== null && assessmentAttemptStatus !== "in_progress";
  const reviewMode = submittedAttempt && searchParams.get("review") === "answers";
  const protectedDiagramQuestionIdsFingerprint = JSON.stringify(
    state.assessment.questions
      .filter((question) => question.type === "diagram_labels" || question.type === "diagram_text")
      .map((question) => question.id),
  );
  const handleProtectedAssetAccessDenied = useCallback((): void => {
    const questionIds = JSON.parse(protectedDiagramQuestionIdsFingerprint) as Array<string>;
    setUnavailableDiagramQuestionIds(new Set(questionIds));
  }, [protectedDiagramQuestionIdsFingerprint]);
  const protectedImageRegistry = useMemo(
    () => assessmentAttemptId === null || (assessmentAttemptStatus !== "in_progress" && !reviewMode)
      ? null
      : createProtectedDiagramRegistry({
          apiBaseUrl,
          attemptId: assessmentAttemptId,
          contentVersion: state.assessment.contentVersion,
          onAccessDenied: handleProtectedAssetAccessDenied,
        }),
    [
      apiBaseUrl,
      assessmentAttemptId,
      assessmentAttemptStatus,
      handleProtectedAssetAccessDenied,
      state.assessment.contentVersion,
      reviewMode,
    ],
  );

  useEffect(() => () => protectedImageRegistry?.dispose(), [protectedImageRegistry]);

  useEffect(() => {
    mountedRef.current = true;
    return () => {
      mountedRef.current = false;
    };
  }, []);

  useEffect(() => {
    const controllers = activeControllersRef.current;
    return () => {
      for (const controller of controllers) {
        controller.abort();
      }
      controllers.clear();
    };
  }, []);

  useEffect(() => {
    if (!answerConfirmationVisible) {
      return;
    }

    function handleKeyDown(event: KeyboardEvent): void {
      if (event.key === "Escape") {
        event.preventDefault();
        setAnswerConfirmationVisible(false);
      }
    }

    function handleMouseDown(event: MouseEvent): void {
      const target = event.target;
      if (!(target instanceof Node)) {
        return;
      }

      if (answerButtonWrapperRef.current?.contains(target) === true) {
        return;
      }

      setAnswerConfirmationVisible(false);
    }

    document.addEventListener("keydown", handleKeyDown);
    document.addEventListener("mousedown", handleMouseDown);
    return () => {
      document.removeEventListener("keydown", handleKeyDown);
      document.removeEventListener("mousedown", handleMouseDown);
    };
  }, [answerConfirmationVisible]);

  useEffect(() => {
    if (initialData.attempt !== null) {
      return;
    }

    const lease = startCoordinator.acquire((signal) =>
      startLessonAssessment(apiBaseUrl, stableRoute, { signal }),
    );
    let active = true;
    void lease.promise.then((result) => {
      if (!active || lease.signal.aborted) {
        return;
      }
      if (result.status === "ready") {
        updateState(() => createLessonAssessmentState(result.data, navigationQuestionId));
        setStartStatus("idle");
        return;
      }
      setStartStatus(result.status === "denied" ? "denied" : "error");
    });

    return () => {
      active = false;
      lease.release();
    };
  }, [apiBaseUrl, initialData.attempt, navigationQuestionId, stableRoute, startCoordinator, startRetry, updateState]);

  const navigationOperationRef = useRef(false);

  const displayQuestion = useCallback(
    (questionId: string): boolean => {
      const next = updateState((current) => selectLessonAssessmentQuestion(current, questionId));
      if (next.currentQuestionId !== questionId) {
        return false;
      }

      const targetQuestion = next.assessment.questions.find((question) => question.id === questionId);
      if (targetQuestion?.type === "diagram_labels" || targetQuestion?.type === "diagram_text") {
        setUnavailableDiagramQuestionIds((current) => {
          if (current.has(questionId)) {
            return current;
          }
          return new Set(current).add(questionId);
        });
      }

      if (mountedRef.current) {
        setAnswerConfirmationVisible(false);
      }

      return true;
    },
    [updateState],
  );

  const navigateWithoutSaving = useCallback((questionId: string): boolean => {
    if (
      navigationOperationRef.current ||
      saveRequestGate.isRunning() ||
      saveRequestInFlight ||
      stateRef.current.currentQuestionId === questionId
    ) {
      return false;
    }

    navigationOperationRef.current = true;
    if (mountedRef.current) {
      setNavigationBusy(true);
    }
    try {
      const discarded = updateState((current) => {
        const currentQuestionIds = currentNavigationQuestionIdsForState(current);
        return currentQuestionIds.length === 0
          ? current
          : discardLessonAssessmentChanges(current, currentQuestionIds);
      });
      if (!displayQuestion(questionId)) {
        return false;
      }

      if (discarded.currentQuestionId === questionId) {
        return false;
      }

      return true;
    } finally {
      navigationOperationRef.current = false;
      if (mountedRef.current) {
        setNavigationBusy(false);
      }
    }
  }, [currentNavigationQuestionIdsForState, displayQuestion, saveRequestGate, saveRequestInFlight, updateState]);

  const performSave = useCallback(async (
    questionIds: ReadonlyArray<string>,
    options: {
      readonly force: boolean;
      readonly lock: boolean;
    },
  ): Promise<boolean> => {
    const result = await saveRequestGate.run(async () => {
      const current = stateRef.current;
      const hasDirtyResponses = questionIds.some((questionId) => current.dirtyQuestionIds.includes(questionId));
      if (!hasDirtyResponses && !current.positionDirty && !options.force) {
        return true;
      }
      if (
        current.attempt === null ||
        current.attempt.status !== "in_progress" ||
        current.submissionStatus === "submitting"
      ) {
        return false;
      }

      const sentSnapshot = createLessonAssessmentSaveSnapshot(current, questionIds);
      // A same-document React unmount must not abort an accepted PATCH.
      const controller = new AbortController();
      if (mountedRef.current) {
        setSaveRequestInFlight(true);
      }
      updateState(markLessonAssessmentSaving);
      try {
        const saveResult = await saveLessonAssessment(
          apiBaseUrl,
          stableRoute,
          current.attempt.id,
          {
            revision: sentSnapshot.revision,
            lastQuestionId: sentSnapshot.lastQuestionId,
            responses: sentSnapshot.responses,
            lockQuestionIds: options.lock ? questionIds : [],
          },
          { signal: controller.signal },
        );
        if (controller.signal.aborted) {
          return false;
        }
        if (saveResult.status === "ready") {
          updateState((latest) => applySavedAttempt(latest, saveResult.data, sentSnapshot));
          return true;
        }
        if (saveResult.status === "conflict") {
          const reloaded = await reloadLessonAssessment(apiBaseUrl, stableRoute, controller.signal);
          if (reloaded.status === "ready") {
            updateState((latest) => applyConflictReload(latest, reloaded.data, sentSnapshot));
            return false;
          }
        }
        if (!controller.signal.aborted) {
          updateState(applySaveError);
        }
        return false;
      } finally {
        if (mountedRef.current) {
          setSaveRequestInFlight(false);
        }
      }
    });

    return result ?? false;
  }, [apiBaseUrl, saveRequestGate, stableRoute, updateState]);

  const navigationEffectContextRef = useRef({
    navigateWithoutSaving,
    navigationQuestionsForState,
    pathname,
    router,
    searchParams,
  });

  useEffect(() => {
    navigationEffectContextRef.current = {
      navigateWithoutSaving,
      navigationQuestionsForState,
      pathname,
      router,
      searchParams,
    };
  }, [navigateWithoutSaving, navigationQuestionsForState, pathname, router, searchParams]);

  useEffect(() => {
    if (navigationQuestionId === null) {
      return;
    }

    const navigationContext = navigationEffectContextRef.current;
    const targetQuestionIds = questionIdsForNavigationItem(
      navigationContext.navigationQuestionsForState(stateRef.current),
      navigationQuestionId,
    );
    if (targetQuestionIds.length === 0) {
      const fallbackQuestionId = stateRef.current.currentQuestionId;
      if (fallbackQuestionId !== null) {
        const nextSearchParams = new URLSearchParams(navigationContext.searchParams.toString());
        nextSearchParams.set("question", fallbackQuestionId);
        navigationContext.router.replace(
          `${navigationContext.pathname}?${nextSearchParams.toString()}`,
          { scroll: false },
        );
      }
      return;
    }

    if (stateRef.current.currentQuestionId === navigationQuestionId) {
      return;
    }

    navigationContext.navigateWithoutSaving(navigationQuestionId);
  }, [navigationQuestionId]);

  const handleRetake = useCallback(async (): Promise<void> => {
    if (retakeStatus === "starting") {
      return;
    }

    const controller = createRequestController();
    setRetakeStatus("starting");
    try {
      const result = await retakeLessonAssessment(apiBaseUrl, stableRoute, { signal: controller.signal });
      if (controller.signal.aborted) {
        return;
      }
      if (result.status === "ready") {
        updateState(() => createLessonAssessmentState(result.data));
        setUnavailableDiagramQuestionIds(new Set(
          result.data.assessment.questions
            .filter((question) => question.type === "diagram_labels" || question.type === "diagram_text")
            .map((question) => question.id),
        ));
        router.replace(pathname, { scroll: false });
        router.refresh();
        setRetakeStatus("idle");
        return;
      }
      setRetakeStatus(result.status === "denied" ? "denied" : "error");
    } finally {
      releaseRequestController(controller);
    }
  }, [apiBaseUrl, createRequestController, pathname, releaseRequestController, retakeStatus, router, stableRoute, updateState]);

  const handleDevelopmentReset = useCallback(async (): Promise<void> => {
    if (!DEVELOPMENT_RESET_ENABLED || developmentResetStatus === "resetting" || navigationOperationRef.current) {
      return;
    }

    navigationOperationRef.current = true;
    setDevelopmentResetStatus("resetting");
    const controller = createRequestController();
    try {
      const result = await resetLessonAssessmentDevelopmentState(apiBaseUrl, stableRoute, {
        signal: controller.signal,
      });
      if (controller.signal.aborted) {
        return;
      }
      if (result.status === "ready") {
        window.location.reload();
        return;
      }
      setDevelopmentResetStatus(result.status === "denied" ? "denied" : "error");
    } finally {
      releaseRequestController(controller);
      navigationOperationRef.current = false;
    }
  }, [
    apiBaseUrl,
    createRequestController,
    developmentResetStatus,
    releaseRequestController,
    stableRoute,
  ]);

  const handleDiagramAvailabilityChange = useCallback(
    (questionId: string, available: boolean): void => {
      setUnavailableDiagramQuestionIds((current) => {
        if (available === !current.has(questionId)) {
          return current;
        }
        const next = new Set(current);
        if (available) {
          next.delete(questionId);
        } else {
          next.add(questionId);
        }
        return next;
      });
    },
    [],
  );

  const handleSubmit = useCallback(async (): Promise<void> => {
    if (navigationOperationRef.current || saveRequestGate.isRunning() || saveRequestInFlight) {
      return;
    }
    navigationOperationRef.current = true;
    setNavigationBusy(true);
    try {
      const currentQuestionIds = currentNavigationQuestionIdsForState(stateRef.current);
      if (currentQuestionIds.length > 0 && hasPendingLessonAssessmentChanges(stateRef.current)) {
        updateState((latest) => discardLessonAssessmentChanges(latest, currentQuestionIds));
      }
      const current = stateRef.current;

      if (!mountedRef.current) {
        return;
      }
      if (
        current.attempt === null ||
        current.attempt.status !== "in_progress" ||
        current.submissionStatus === "submitting" ||
        !allVisibleQuestionsComplete(current)
      ) {
        return;
      }

      const submissionSnapshot = createLessonAssessmentSaveSnapshot(current, currentQuestionIds);
      const controller = createRequestController();
      updateState(markLessonAssessmentSubmitting);
      try {
        const result = await submitLessonAssessment(
          apiBaseUrl,
          stableRoute,
          current.attempt.id,
          current.attempt.revision,
          { signal: controller.signal },
        );
        if (controller.signal.aborted) {
          return;
        }
        if (result.status === "ready") {
          updateState((latest) => applySubmittedAttempt(latest, result.data));
          router.replace(pathname);
          router.refresh();
          return;
        }
        if (result.status === "conflict" || result.status === "unavailable") {
          const reloaded = await reloadLessonAssessment(apiBaseUrl, stableRoute, controller.signal);
          if (reloaded.status === "ready") {
            const terminalAttempt = reloaded.data.attempt !== null &&
              reloaded.data.attempt.status !== "in_progress";
            updateState((latest) => {
              const synchronized = applyConflictReload(latest, reloaded.data, submissionSnapshot);
              return terminalAttempt ? synchronized : applySubmissionError(synchronized);
            });
            if (terminalAttempt) {
              router.replace(pathname);
              router.refresh();
            }
            return;
          }
        }
        updateState(applySubmissionError);
      } finally {
        releaseRequestController(controller);
      }
    } finally {
      navigationOperationRef.current = false;
      if (mountedRef.current) {
        setNavigationBusy(false);
      }
    }
  }, [
    apiBaseUrl,
    createRequestController,
    currentNavigationQuestionIdsForState,
    pathname,
    releaseRequestController,
    router,
    saveRequestGate,
    saveRequestInFlight,
    stableRoute,
    updateState,
  ]);

  const handleSave = useCallback(async (): Promise<void> => {
    const currentQuestionIds = currentNavigationQuestionIdsForState(stateRef.current);
    if (currentQuestionIds.length === 0) {
      return;
    }

    setAnswerConfirmationVisible(false);
    await performSave(currentQuestionIds, { force: false, lock: false });
  }, [currentNavigationQuestionIdsForState, performSave]);

  const commitAnswer = useCallback(async (): Promise<void> => {
    const current = stateRef.current;
    const currentQuestion = current.assessment.questions.find(
      (question) => question.id === current.currentQuestionId,
    );
    if (currentQuestion === undefined) {
      return;
    }

    const questionsToConfirm = isSentenceCompletionQuestion(currentQuestion)
      ? sentenceCompletionQuestionsForNavigationItem(
          navigationQuestionsForState(current),
          current.currentQuestionId,
        )
      : [currentQuestion];
    const responsesComplete = questionsToConfirm.every((question) =>
      isLessonAssessmentResponseComplete(question, current.responses[question.id]),
    );
    if (!responsesComplete) {
      return;
    }

    const currentQuestionIds = currentNavigationQuestionIdsForState(current);
    if (!(await performSave(currentQuestionIds, { force: true, lock: true })) || !mountedRef.current) {
      return;
    }

    const action = resolveLessonAssessmentAnswerAction({
      nextQuestionId: nextVisibleNavigationQuestionIdForState(stateRef.current),
    });
    if (action.type === "navigate") {
      if (navigateWithoutSaving(action.questionId)) {
        const nextSearchParams = new URLSearchParams(searchParams.toString());
        nextSearchParams.set("question", action.questionId);
        router.replace(`${pathname}?${nextSearchParams.toString()}`);
      }
      return;
    }

    await handleSubmit();
  }, [
    currentNavigationQuestionIdsForState,
    handleSubmit,
    navigateWithoutSaving,
    navigationQuestionsForState,
    nextVisibleNavigationQuestionIdForState,
    pathname,
    performSave,
    router,
    searchParams,
  ]);

  const handleAnswer = useCallback(async (): Promise<void> => {
    if (!answerConfirmationAcknowledged) {
      writeAnswerConfirmationAcknowledged(confirmationStorageKey);
      setAnswerConfirmationAcknowledged(true);
      setAnswerConfirmationVisible(true);
      return;
    }

    setAnswerConfirmationVisible(false);
    await commitAnswer();
  }, [answerConfirmationAcknowledged, commitAnswer, confirmationStorageKey]);

  const developmentResetLocked = developmentResetStatus !== "idle";
  const developmentResetButton = DEVELOPMENT_RESET_ENABLED ? (
    <Button
      type="button"
      variant="secondary"
      fullWidth={false}
      className="w-full sm:w-auto"
      disabled={developmentResetStatus === "resetting"}
      loading={developmentResetStatus === "resetting"}
      onClick={() => void handleDevelopmentReset()}
      data-ui="lk.learning.assessment.developmentResetButton"
    >
      {developmentResetStatus === "resetting" ? "Сбрасываем ответы" : "Сбросить все ответы"}
    </Button>
  ) : null;
  const developmentResetError = developmentResetStatus === "error" || developmentResetStatus === "denied" ? (
    <p className="ui-text-danger text-sm font-medium" role="alert" data-ui="lk.learning.assessment.developmentResetError">
      Не удалось сбросить ответы. Обновите страницу и попробуйте ещё раз.
    </p>
  ) : null;

  if (state.attempt === null) {
    return (
      <section className="grid gap-5" data-ui="lk.learning.assessment" data-state={startStatus}>
        {startStatus === "starting" ? (
          <p className="ui-text-secondary text-sm font-medium" role="status" aria-live="polite" data-ui="lk.learning.assessment.startStatus">
            Подготавливаем экзамен
          </p>
        ) : null}
        {startStatus === "error" || startStatus === "denied" ? (
          <div className="grid gap-3" role="alert" data-ui="lk.learning.assessment.startError">
            <p className="ui-text-danger text-sm font-medium">
              {startStatus === "denied" ? "Доступ к экзамену закрыт" : "Не удалось начать экзамен"}
            </p>
            <Button
              type="button"
              variant="secondary"
              fullWidth={false}
              onClick={() => {
                setStartStatus("starting");
                setStartRetry((retry) => retry + 1);
              }}
              data-ui="lk.learning.assessment.startRetryButton"
            >
              Повторить
            </Button>
          </div>
        ) : null}
      </section>
    );
  }

  if (state.attempt.status === "pending_review" && !reviewMode) {
    return (
      <LessonAssessmentReviewResult
        actions={developmentResetButton}
        assessment={state.assessment}
        automaticGate={state.attempt.automaticGate}
        feedback={developmentResetError}
        manualProgressBps={state.attempt.manualProgressBps}
        reviewedManualQuestionCount={state.attempt.reviewedManualQuestionCount}
      />
    );
  }

  const retakeButton = state.assessment.retakeAvailable ? (
    <Button
      type="button"
      variant="secondary"
      fullWidth={false}
      loading={retakeStatus === "starting"}
      disabled={retakeStatus === "starting" || developmentResetLocked}
      onClick={() => void handleRetake()}
      data-ui="lk.learning.assessment.retakeButton"
    >
      {retakeStatus === "starting" ? "Начинаем повторное прохождение" : "Сдать экзамен ещё раз"}
    </Button>
  ) : null;
  const retakeError = retakeStatus === "error" || retakeStatus === "denied" ? (
    <p
      className="ui-text-danger text-sm font-medium"
      role="alert"
      data-ui="lk.learning.assessment.retakeError"
    >
      Не удалось начать повторное прохождение. Попробуйте ещё раз.
    </p>
  ) : null;
  const resultActions = (
    <>
      {retakeButton}
      {developmentResetButton}
    </>
  );
  const resultFeedback = (
    <>
      {retakeError}
      {developmentResetError}
    </>
  );

  if (state.attempt.status === "passed" && !reviewMode) {
    return (
      <LessonAssessmentReviewResult
        actions={resultActions}
        assessment={state.assessment}
        automaticGate={state.attempt.automaticGate}
        feedback={resultFeedback}
        finalScore={state.attempt.score}
        manualProgressBps={state.attempt.score.components.manualBps}
        reviewedManualQuestionCount={state.attempt.reviewedManualQuestionCount}
      />
    );
  }

  if (state.attempt.status === "failed" && state.attempt.failureReason === "automatic_threshold_not_met" && !reviewMode) {
    return (
      <LessonAssessmentAutomaticFailureResult
        actions={resultActions}
        assessment={state.assessment}
        automaticGate={state.attempt.automaticGate}
        feedback={resultFeedback}
      />
    );
  }

  if (submittedAttempt && !reviewMode && "score" in state.attempt) {
    const score = state.attempt.score;

    return (
      <LessonAssessmentResult
        assessment={state.assessment}
        score={score}
        manualResults={state.attempt.manualResults}
        failureReason={state.attempt.failureReason}
        actions={resultActions}
        feedback={resultFeedback}
      />
    );
  }

  const activeAttempt = state.attempt;
  const currentQuestion = state.assessment.questions.find(
    (question) => question.id === state.currentQuestionId,
  );
  const sentenceCompletionQuestions = sentenceCompletionQuestionsForNavigationItem(
    navigationQuestionsForState(state),
    state.currentQuestionId,
  );
  const automaticQuestionResults = "automaticGate" in activeAttempt
    ? activeAttempt.automaticGate.questionResults
    : [];
  const manualReviewResult = currentQuestion?.gradingMode === "manual" && "manualResults" in activeAttempt
    ? activeAttempt.manualResults.find((result) => result.questionId === currentQuestion.id)
    : undefined;
  const sentenceCompletionEntries: ReadonlyArray<LessonAssessmentSentenceCompletionEntry> | null =
    currentQuestion !== undefined &&
    isSentenceCompletionQuestion(currentQuestion) &&
    sentenceCompletionQuestions.every((question) => sentenceCompletionSegments(question) !== null)
      ? sentenceCompletionQuestions.map((question) => ({
          question,
          response: state.responses[question.id],
          locked: activeAttempt.lockedQuestionIds.includes(question.id),
          reviewResult: question.gradingMode === "automatic"
            ? automaticQuestionResults.find((result) => result.questionId === question.id)
            : undefined,
          hasError: questionHasAssessmentError(state, question.id),
          incorrectPartIds: incorrectPartIdsForQuestion(state, question.id),
        }))
      : null;
  const currentResponseComplete = sentenceCompletionEntries !== null
    ? sentenceCompletionEntries.every(({ question, response }) =>
        isLessonAssessmentResponseComplete(question, response),
      )
    : currentQuestion !== undefined &&
      isLessonAssessmentResponseComplete(currentQuestion, state.responses[currentQuestion.id]);
  const assessmentComplete = allVisibleQuestionsComplete(state);
  const currentNavigationQuestionIds = currentNavigationQuestionIdsForState(state);
  const currentNavigationHasUnavailableDiagram = currentNavigationQuestionIds.some((questionId) =>
    unavailableDiagramQuestionIds.has(questionId),
  );
  const answerAction = resolveLessonAssessmentAnswerAction({
    nextQuestionId: nextVisibleNavigationQuestionIdForState(state),
  });
  const currentNavigationHasDirtyResponse = currentNavigationQuestionIds.some((questionId) =>
    state.dirtyQuestionIds.includes(questionId),
  );
  const currentNavigationLocked = currentNavigationQuestionIds.length > 0 &&
    currentNavigationQuestionIds.every((questionId) => state.attempt?.lockedQuestionIds.includes(questionId) === true);
  const explicitSaveInFlight = saveRequestInFlight || saveRequestGate.isRunning();
  const interactionLocked = lessonAssessmentInteractionLocked({
    explicitSaveRunning: explicitSaveInFlight,
    navigating: navigationBusy,
    submitting: state.submissionStatus === "submitting",
    resetting: developmentResetLocked,
  });
  const saveActionBusy = state.saveStatus === "saving" || explicitSaveInFlight;

  return (
    <section className="grid gap-4" data-ui="lk.learning.assessment" data-state={reviewMode ? "review" : "in-progress"}>
      {currentQuestion ? (
        <div
          className={`grid gap-8 ${
            currentQuestion.type === "diagram_labels" ||
            currentQuestion.type === "diagram_text" ||
            currentQuestion.type === "text_fields" ||
            currentQuestion.type === "single_choice" ||
            currentQuestion.type === "multiple_choice"
              ? "w-full"
              : currentQuestion.type === "long_text"
                ? "max-w-3xl"
                : "max-w-xl"
          }`}
          data-ui="lk.learning.assessment.currentQuestion"
        >
          {sentenceCompletionEntries !== null ? (
            <LessonAssessmentSentenceCompletion
              apiBaseUrl={apiBaseUrl}
              attemptId={state.attempt.id}
              route={stableRoute}
              entries={sentenceCompletionEntries}
              disabled={reviewMode || interactionLocked}
              reviewMode={reviewMode}
              acceptedSuggestionValueFor={acceptedSuggestionValueFor}
              onSuggestionAcceptedChange={handleSuggestionAcceptedChange}
              onChange={(questionId, response) => {
                updateState((current) => setAnswer(current, questionId, response));
              }}
            />
          ) : (
            <LessonAssessmentQuestion
              key={currentQuestion.id}
              apiBaseUrl={apiBaseUrl}
              attemptId={state.attempt.id}
              diagramAssets={diagramAssets}
              protectedImageRegistry={protectedImageRegistry ?? undefined}
              route={stableRoute}
              question={currentQuestion}
              response={state.responses[currentQuestion.id]}
              locked={state.attempt.lockedQuestionIds.includes(currentQuestion.id)}
              hasError={questionHasAssessmentError(state, currentQuestion.id)}
              incorrectPartIds={incorrectPartIdsForQuestion(state, currentQuestion.id)}
              disabled={interactionLocked}
              acceptedSuggestionValueFor={acceptedSuggestionValueFor}
              onSuggestionAcceptedChange={handleSuggestionAcceptedChange}
              manualReviewPending={reviewMode && state.attempt.status === "pending_review" && currentQuestion.gradingMode === "manual" && !state.attempt.reviewedManualQuestionIds.includes(currentQuestion.id)}
              manualReviewReviewed={reviewMode && state.attempt.status === "pending_review" && currentQuestion.gradingMode === "manual" && state.attempt.reviewedManualQuestionIds.includes(currentQuestion.id)}
              manualReviewResult={reviewMode ? manualReviewResult : undefined}
              reviewMode={reviewMode}
              reviewResult={currentQuestion.gradingMode === "automatic"
                ? automaticQuestionResults.find((result) => result.questionId === currentQuestion.id)
                : undefined}
              onDiagramAvailabilityChange={handleDiagramAvailabilityChange}
              onChange={(response) => {
                updateState((current) => setAnswer(current, currentQuestion.id, response));
              }}
            />
          )}
          {!reviewMode ? <div className="flex flex-wrap justify-start gap-3">
            <Button
              type="button"
              variant="secondary"
              fullWidth={false}
              className="w-full sm:w-auto"
              disabled={
                !currentNavigationHasDirtyResponse ||
                developmentResetLocked ||
                navigationBusy ||
                saveActionBusy ||
                state.submissionStatus === "submitting"
              }
              loading={saveActionBusy}
              aria-busy={saveActionBusy ? true : undefined}
              onClick={() => void handleSave()}
              data-ui="lk.learning.assessment.saveButton"
            >
              Сохранить
            </Button>
            <div ref={answerButtonWrapperRef} className="relative w-full sm:w-auto">
              <Button
                type="button"
                fullWidth={false}
                className="w-full sm:w-auto"
                disabled={
                  !currentResponseComplete ||
                  currentNavigationLocked ||
                  developmentResetLocked ||
                  navigationBusy ||
                  saveActionBusy ||
                  state.submissionStatus === "submitting" ||
                  currentNavigationHasUnavailableDiagram
                }
                loading={navigationBusy || saveActionBusy || state.submissionStatus === "submitting"}
                aria-busy={navigationBusy || saveActionBusy || state.submissionStatus === "submitting" ? true : undefined}
                aria-controls={answerConfirmationVisible ? "lesson-assessment-answer-confirmation" : undefined}
                aria-describedby={answerConfirmationVisible ? "lesson-assessment-answer-confirmation-copy" : undefined}
                aria-expanded={answerConfirmationVisible ? true : undefined}
                onClick={() => void handleAnswer()}
                data-ui="lk.learning.assessment.answerButton"
              >
                {answerAction.label}
              </Button>
              {!reviewMode && answerConfirmationVisible ? (
                <section
                  id="lesson-assessment-answer-confirmation"
                  className="absolute left-0 top-full z-40 mt-3 grid w-[min(28rem,calc(100vw-2rem))] gap-2 rounded-[var(--radius-card)] border border-[var(--color-border-subtle)] bg-white px-4 py-3 shadow-[0_16px_44px_rgb(24_28_32_/_0.12)] before:absolute before:-top-1.5 before:left-12 before:h-3 before:w-3 before:rotate-45 before:border-l before:border-t before:border-[var(--color-border-subtle)] before:bg-white before:content-[''] sm:left-1/2 sm:-translate-x-8"
                  role="status"
                  aria-live="polite"
                  data-ui="lk.learning.assessment.answerConfirmation"
                >
                  <div
                    id="lesson-assessment-answer-confirmation-copy"
                    className="grid gap-2"
                  >
                    <p className="text-sm leading-6 ui-text-tertiary">{ANSWER_CONFIRMATION_PRIMARY_COPY}</p>
                    <p className="text-xs leading-5 ui-text-muted-ink">{ANSWER_CONFIRMATION_SECONDARY_COPY}</p>
                  </div>
                </section>
              ) : null}
            </div>
            {developmentResetButton}
          </div> : null}
          {state.saveStatus === "error" ? (
            <p className="ui-text-danger text-sm font-medium" role="alert" data-ui="lk.learning.assessment.saveError">
              Не удалось сохранить ответы. Повторите попытку.
            </p>
          ) : null}
          {developmentResetError}
          {!reviewMode && assessmentComplete && currentNavigationHasUnavailableDiagram ? (
            <p className="ui-text-danger text-sm font-medium" role="alert" data-ui="lk.learning.assessment.diagramError">
              Не удалось загрузить одну или несколько схем.
            </p>
          ) : null}
          {state.submissionStatus === "error" ? (
            <div className="grid gap-3" role="alert">
              <p className="ui-text-danger text-sm font-medium" data-ui="lk.learning.assessment.submissionError">
                Не удалось отправить экзамен. Ответы сохранены. Повторите отправку.
              </p>
              {state.attempt.status === "in_progress" && assessmentComplete ? (
                <Button
                  type="button"
                  variant="secondary"
                  fullWidth={false}
                  disabled={navigationBusy || saveActionBusy}
                  onClick={() => void handleSubmit()}
                  data-ui="lk.learning.assessment.retrySubmitButton"
                >
                  Повторить отправку
                </Button>
              ) : null}
            </div>
          ) : null}
        </div>
      ) : null}
    </section>
  );
}

export default LessonAssessment;
