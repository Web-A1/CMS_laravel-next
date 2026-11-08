"use client";

import { useEffect, useMemo, useState } from "react";

import type {
  LessonAssessmentCallout,
  LessonAssessmentRoute,
} from "@/lib/lessonAssessmentContract";
import { loadLessonAssessmentDevelopmentCalibrationTerms } from "@/lib/lessonAssessmentApi";

import {
  alignDiagramCalibrationLabelToTarget,
  diagramCalibrationSourceSignature,
  diagramCalibrationStorageKey,
  moveDiagramCalibrationTarget,
  restoreDiagramCalibrationSnapshot,
  serializeDiagramCalibrationSnapshot,
  setDiagramCalibrationTargetMarker,
  type DiagramCalibrationMarker,
} from "./lessonAssessmentDiagramCalibration";
import { diagramCalloutTargetId } from "./lessonAssessmentDiagramGeometry";

type UseLessonAssessmentDiagramCalibrationInput = {
  readonly apiBaseUrl: string;
  readonly enabled: boolean;
  readonly height: number;
  readonly questionId: string;
  readonly route: LessonAssessmentRoute;
  readonly sourceCallouts: ReadonlyArray<LessonAssessmentCallout>;
  readonly width: number;
};

type DiagramCalibrationState = {
  readonly callouts: ReadonlyArray<LessonAssessmentCallout>;
  readonly fixedAt: string | null;
  readonly questionId: string;
  readonly selectedTargetId: string;
};

function firstTargetId(callouts: ReadonlyArray<LessonAssessmentCallout>): string {
  const first = callouts[0];
  return first === undefined ? "" : diagramCalloutTargetId(first);
}

function createState(
  questionId: string,
  callouts: ReadonlyArray<LessonAssessmentCallout>,
  fixedAt: string | null = null,
): DiagramCalibrationState {
  return {
    questionId,
    callouts,
    fixedAt,
    selectedTargetId: firstTargetId(callouts),
  };
}

export function useLessonAssessmentDiagramCalibration({
  apiBaseUrl,
  enabled,
  height,
  questionId,
  route,
  sourceCallouts,
  width,
}: UseLessonAssessmentDiagramCalibrationInput) {
  const [state, setState] = useState<DiagramCalibrationState>(() =>
    createState(questionId, sourceCallouts),
  );
  const [hydratedQuestionId, setHydratedQuestionId] = useState<string | null>(null);
  const [isOpen, setIsOpen] = useState(false);
  const [isExportOpen, setIsExportOpen] = useState(false);
  const [step, setStep] = useState<1 | 5 | 20>(5);
  const [answerTermsState, setAnswerTermsState] = useState<{
    readonly calibrationKey: string;
    readonly terms: Readonly<Record<string, string>>;
  }>(() => ({
    calibrationKey: `${route.subjectSlug}/${route.courseSlug}/${route.lessonSlug}/${questionId}`,
    terms: {},
  }));
  const calibrationKey = `${route.subjectSlug}/${route.courseSlug}/${route.lessonSlug}/${questionId}`;
  const sourceSignature = diagramCalibrationSourceSignature(sourceCallouts);
  const storageKey = diagramCalibrationStorageKey(route, questionId);
  const activeState = state.questionId === questionId ? state : createState(questionId, sourceCallouts);

  useEffect(() => {
    if (!enabled) {
      return;
    }

    const timer = window.setTimeout(() => {
      let restored: ReturnType<typeof restoreDiagramCalibrationSnapshot> = null;
      try {
        const serialized = window.localStorage.getItem(storageKey);
        restored = serialized === null
          ? null
          : restoreDiagramCalibrationSnapshot(serialized, questionId, sourceCallouts);
      } catch {
        restored = null;
      }

      setState(
        createState(
          questionId,
          restored?.callouts ?? sourceCallouts,
          restored?.fixedAt ?? null,
        ),
      );
      setHydratedQuestionId(questionId);
      setIsExportOpen(false);
    }, 0);

    return () => window.clearTimeout(timer);
  }, [enabled, questionId, sourceCallouts, sourceSignature, storageKey]);

  useEffect(() => {
    if (!enabled || !isOpen) {
      return;
    }

    const controller = new AbortController();
    void loadLessonAssessmentDevelopmentCalibrationTerms(
      apiBaseUrl,
      {
        subjectSlug: route.subjectSlug,
        courseSlug: route.courseSlug,
        lessonSlug: route.lessonSlug,
      },
      questionId,
      { signal: controller.signal },
    ).then((result) => {
      if (!controller.signal.aborted && result.status === "ready") {
        setAnswerTermsState({ calibrationKey, terms: result.terms });
      }
    });

    return () => controller.abort();
  }, [
    apiBaseUrl,
    calibrationKey,
    enabled,
    isOpen,
    questionId,
    route.courseSlug,
    route.lessonSlug,
    route.subjectSlug,
  ]);

  useEffect(() => {
    if (!enabled || hydratedQuestionId !== questionId || activeState.questionId !== questionId) {
      return;
    }

    try {
      window.localStorage.setItem(
        storageKey,
        serializeDiagramCalibrationSnapshot({
          fixedAt: activeState.fixedAt,
          questionId,
          sourceCallouts,
          workingCallouts: activeState.callouts,
        }),
      );
    } catch {
      // Calibration remains usable in memory when browser storage is unavailable.
    }
  }, [
    activeState.callouts,
    activeState.fixedAt,
    activeState.questionId,
    enabled,
    hydratedQuestionId,
    questionId,
    sourceCallouts,
    sourceSignature,
    storageKey,
  ]);

  const exportText = useMemo(
    () =>
      serializeDiagramCalibrationSnapshot({
        fixedAt: activeState.fixedAt,
        questionId,
        sourceCallouts,
        workingCallouts: activeState.callouts,
      }),
    [activeState.callouts, activeState.fixedAt, questionId, sourceCallouts],
  );
  const hasChanges =
    diagramCalibrationSourceSignature(activeState.callouts) !== sourceSignature;

  function updateCallouts(
    update: (
      current: ReadonlyArray<LessonAssessmentCallout>,
    ) => ReadonlyArray<LessonAssessmentCallout>,
  ): void {
    setState((current) => {
      const scoped = current.questionId === questionId ? current : createState(questionId, sourceCallouts);
      const callouts = update(scoped.callouts);
      return callouts === scoped.callouts ? scoped : { ...scoped, callouts, fixedAt: null };
    });
  }

  return {
    alignField: () => {
      updateCallouts((current) =>
        alignDiagramCalibrationLabelToTarget(current, activeState.selectedTargetId),
      );
    },
    answerTerms:
      answerTermsState.calibrationKey === calibrationKey ? answerTermsState.terms : {},
    callouts: activeState.callouts,
    exportText,
    fixedAt: activeState.fixedAt,
    hasChanges,
    isExportOpen,
    isOpen,
    selectedTargetId: activeState.selectedTargetId,
    step,
    closeExport: () => setIsExportOpen(false),
    fix: () => {
      setState((current) => ({
        ...(current.questionId === questionId ? current : createState(questionId, sourceCallouts)),
        fixedAt: new Date().toISOString(),
      }));
    },
    move: (x: number, y: number) => {
      updateCallouts((current) =>
        moveDiagramCalibrationTarget(
          current,
          activeState.selectedTargetId,
          { x: x * step, y: y * step },
          { width, height },
        ),
      );
    },
    reset: () => {
      try {
        window.localStorage.removeItem(storageKey);
      } catch {
        // Reset still applies to in-memory calibration state.
      }
      setState(createState(questionId, sourceCallouts));
      setIsExportOpen(false);
    },
    selectPart: (partId: string) => {
      setState((current) => {
        const scoped = current.questionId === questionId ? current : createState(questionId, sourceCallouts);
        const selectedCallout = scoped.callouts.find(
          (callout) =>
            callout.partId === partId &&
            diagramCalloutTargetId(callout) === scoped.selectedTargetId,
        ) ?? scoped.callouts.find((callout) => callout.partId === partId);
        return selectedCallout === undefined
          ? scoped
          : { ...scoped, selectedTargetId: diagramCalloutTargetId(selectedCallout) };
      });
    },
    selectTarget: (targetId: string) => {
      setState((current) => {
        const scoped = current.questionId === questionId ? current : createState(questionId, sourceCallouts);
        return scoped.callouts.some((callout) => diagramCalloutTargetId(callout) === targetId)
          ? { ...scoped, selectedTargetId: targetId }
          : scoped;
      });
    },
    setIsOpen,
    setMarker: (targetMarker: DiagramCalibrationMarker) => {
      updateCallouts((current) =>
        setDiagramCalibrationTargetMarker(current, activeState.selectedTargetId, targetMarker),
      );
    },
    setStep,
    toggleExport: () => setIsExportOpen((current) => !current),
  };
}
