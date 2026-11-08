"use client";

import {
  useEffect,
  useMemo,
  useReducer,
  useRef,
  useState,
  useSyncExternalStore,
  type CSSProperties,
  type SyntheticEvent,
} from "react";
import { useSearchParams } from "next/navigation";

import Button from "@/components/button/Button";
import type {
  DiagramLabelsQuestion,
  DiagramTextQuestion,
  LessonAssessmentCallout,
  LessonAssessmentRoute,
} from "@/lib/lessonAssessmentContract";

import {
  createLessonAssessmentDiagramState,
  reduceLessonAssessmentDiagramState,
} from "./lessonAssessmentController";
import type {
  ProtectedDiagramAsset,
  ProtectedDiagramRegistry,
} from "./lessonAssessmentProtectedImageRegistry";
import {
  diagramCalloutTargetId,
  diagramInlineLabelAvailableWidth,
  diagramInlineLabelLeft,
  diagramInlineLabelWidth,
  diagramLeaderPath,
  diagramPointLabel,
  lessonAssessmentDiagramStageScale,
} from "./lessonAssessmentDiagramGeometry";
import { LessonAssessmentDiagramCalibrationPanel } from "./LessonAssessmentDiagramCalibrationPanel";
import styles from "./lesson-assessment.module.css";
import { LessonAssessmentTextInput } from "./LessonAssessmentTextInput";
import { useLessonAssessmentDiagramCalibration } from "./useLessonAssessmentDiagramCalibration";

type LessonAssessmentDiagramProps = {
  readonly acceptedSuggestionValueFor: (questionId: string, partId: string) => string | null;
  readonly apiBaseUrl: string;
  readonly assets: ReadonlyArray<ProtectedDiagramAsset>;
  readonly attemptId: number;
  readonly disabled: boolean;
  readonly incorrectPartIds: ReadonlyArray<string>;
  readonly onAvailabilityChange: (questionId: string, available: boolean) => void;
  readonly onChange: (partId: string, optionId: string) => void;
  readonly onSuggestionAcceptedChange: (questionId: string, partId: string, value: string | null) => void;
  readonly question: DiagramLabelsQuestion | DiagramTextQuestion;
  readonly reviewMode: boolean;
  readonly registry: ProtectedDiagramRegistry;
  readonly route: LessonAssessmentRoute;
  readonly values: Readonly<Record<string, string>>;
};

const selectClassName =
  "ui-input mt-2 h-11 w-full rounded-[var(--radius-control)] border px-3 text-sm outline-none focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--color-brand)] disabled:cursor-not-allowed disabled:bg-[var(--color-surface-raised)]";
const DEVELOPMENT_DIAGRAM_CALIBRATION_ENABLED = process.env.NODE_ENV !== "production";
const EDGE_LABEL_CLEARANCE = "0.5rem";
const DIAGRAM_NAME_PLACEHOLDER = "Что изображено в целом на схеме?";
const DIAGRAM_LATIN_NAME_PLACEHOLDER = "Что изображено в целом на схеме? (на латыни)";
const DIAGRAM_LABEL_PLACEHOLDER = "Что изображено тут?";
const DIAGRAM_LATIN_LABEL_PLACEHOLDER = "Термин на латыни";
const DIAGRAM_INLINE_LABEL_MIN_WIDTH = "14rem";
const DIAGRAM_INLINE_LABEL_MAX_WIDTH = "22rem";
const LATIN_DIAGRAM_NAME_QUESTION_IDS: ReadonlySet<string> = new Set([
  "diagram-04",
  "diagram-05",
  "diagram-06",
]);
const LATIN_DIAGRAM_QUESTION_IDS: ReadonlySet<string> = new Set([
  "diagram-02",
  "diagram-03",
  "diagram-04",
  "diagram-05",
  "diagram-06",
  "diagram-07",
  "diagram-08",
  "diagram-09",
]);
const DIAGRAM_STAGE_TRIM_BY_QUESTION: Readonly<
  Record<string, { readonly blockStart: number; readonly blockEnd: number }>
> = {
  "diagram-02": { blockStart: 0.052, blockEnd: 0.075 },
  "diagram-03": { blockStart: 0.066, blockEnd: 0.066 },
  "diagram-04": { blockStart: 0.127, blockEnd: 0.145 },
  "diagram-05": { blockStart: 0.073, blockEnd: 0.114 },
  "diagram-06": { blockStart: 0, blockEnd: 0.126 },
  "diagram-08": { blockStart: 0.098, blockEnd: 0.16 },
  "diagram-09": { blockStart: 0.194, blockEnd: 0.156 },
};

function groupCalloutsByPartId(
  callouts: ReadonlyArray<LessonAssessmentCallout>,
): ReadonlyMap<string, ReadonlyArray<LessonAssessmentCallout>> {
  const grouped = new Map<string, Array<LessonAssessmentCallout>>();
  for (const callout of callouts) {
    const partCallouts = grouped.get(callout.partId) ?? [];
    partCallouts.push(callout);
    grouped.set(callout.partId, partCallouts);
  }
  return grouped;
}

function inlineLabelCallout(
  callouts: ReadonlyArray<LessonAssessmentCallout> | undefined,
): LessonAssessmentCallout | undefined {
  return callouts?.find((callout) => callout.labelX !== undefined && callout.labelY !== undefined);
}

type DiagramLabelSide = NonNullable<LessonAssessmentCallout["labelSide"]>;

function diagramInlineLabelCssWidth(
  labelWidth: number,
  availableWidth: number,
): string {
  const baseWidth = `${labelWidth * 100}%`;
  const availableCssWidth = `${availableWidth * 100}%`;

  return `min(${availableCssWidth}, ${DIAGRAM_INLINE_LABEL_MAX_WIDTH}, max(${baseWidth}, ${DIAGRAM_INLINE_LABEL_MIN_WIDTH}))`;
}

function diagramInlineFieldStyle({
  fieldLeft,
  labelSide,
  labelWidth,
  labelY,
  width,
}: {
  readonly fieldLeft: number;
  readonly labelSide: DiagramLabelSide;
  readonly labelWidth: number;
  readonly labelY: number;
  readonly width: string;
}): CSSProperties {
  const top = `${labelY * 100}%`;

  if (labelSide === "left") {
    return {
      right: `${(1 - fieldLeft - labelWidth) * 100}%`,
      top,
      width,
    };
  }

  if (labelSide === "top" || labelSide === "bottom") {
    return {
      left: `${(fieldLeft + labelWidth / 2) * 100}%`,
      top,
      width,
    };
  }

  return {
    left: `${fieldLeft * 100}%`,
    top,
    width,
  };
}

export function LessonAssessmentDiagram({
  acceptedSuggestionValueFor,
  apiBaseUrl,
  assets,
  attemptId,
  disabled,
  incorrectPartIds,
  onAvailabilityChange,
  onChange,
  onSuggestionAcceptedChange,
  question,
  reviewMode,
  registry,
  route,
  values,
}: LessonAssessmentDiagramProps) {
  const searchParams = useSearchParams();
  const [imageState, dispatchImage] = useReducer(
    reduceLessonAssessmentDiagramState,
    question.id,
    createLessonAssessmentDiagramState,
  );
  const [loadedImage, setLoadedImage] = useState<{
    readonly generation: string;
    readonly revision: number;
    readonly objectUrl: string;
  } | null>(null);
  const loadGenerationRef = useRef(0);
  const currentRevisionRef = useRef(imageState.revision);
  const assetsRef = useRef(assets);
  const asset = useMemo<ProtectedDiagramAsset>(() => ({
    assetPath: question.assetPath,
    width: question.width,
    height: question.height,
  }), [question.assetPath, question.height, question.width]);
  const assetsFingerprint = useMemo(
    () => JSON.stringify(assets.map((candidate) => [candidate.assetPath, candidate.width, candidate.height])),
    [assets],
  );
  const registrySnapshot = useSyncExternalStore(
    registry.subscribe,
    registry.getSnapshot,
    registry.getSnapshot,
  );
  const registryInvalidated = registrySnapshot.startsWith("invalid:");
  const objectUrl = loadedImage?.revision === imageState.revision &&
      loadedImage.generation === registrySnapshot
    ? loadedImage.objectUrl
    : null;
  const effectiveImageStatus = registryInvalidated ? "error" : imageState.status;
  const atomicVisibilityClass = effectiveImageStatus === "ready" && objectUrl !== null
    ? styles.atomicDiagramVisible
    : styles.atomicDiagramHidden;

  useEffect(() => {
    assetsRef.current = assets;
  }, [assets]);

  useEffect(() => {
    currentRevisionRef.current = imageState.revision;
    const generation = loadGenerationRef.current + 1;
    loadGenerationRef.current = generation;
    let active = true;
    onAvailabilityChange(question.id, false);
    const request = imageState.revision === 0 ? registry.load(asset) : registry.retry(asset);
    registry.preloadAround(asset, assetsRef.current);
    void request.then((handle) => {
      if (active && loadGenerationRef.current === generation) {
        setLoadedImage({
          generation: handle.generation,
          revision: imageState.revision,
          objectUrl: handle.objectUrl,
        });
      }
    }).catch((error: unknown) => {
      if (
        active &&
        loadGenerationRef.current === generation &&
        !(error instanceof DOMException && error.name === "AbortError")
      ) {
        dispatchImage({ type: "failed", questionId: question.id, revision: imageState.revision });
        onAvailabilityChange(question.id, false);
      }
    });

    return () => {
      active = false;
    };
  }, [asset, assetsFingerprint, imageState.revision, onAvailabilityChange, question.id, registry]);

  useEffect(() => {
    if (!registryInvalidated) {
      return;
    }
    loadGenerationRef.current += 1;
    onAvailabilityChange(question.id, false);
  }, [onAvailabilityChange, question.id, registryInvalidated]);

  function handleImageLoaded(event: SyntheticEvent<HTMLImageElement>): void {
    const image = event.currentTarget;
    const generation = loadGenerationRef.current;
    void image.decode().catch(() => {
      if (!image.complete || image.naturalWidth === 0) {
        throw new Error("Protected diagram decode failed.");
      }
    }).then(() => {
      requestAnimationFrame(() => {
        if (loadGenerationRef.current !== generation || image.src !== objectUrl) {
          return;
        }
        dispatchImage({ type: "loaded", questionId: question.id, revision: imageState.revision });
        onAvailabilityChange(question.id, true);
      });
    }).catch(() => {
      if (loadGenerationRef.current === generation) {
        dispatchImage({ type: "failed", questionId: question.id, revision: imageState.revision });
        onAvailabilityChange(question.id, false);
      }
    });
  }
  const incorrectIds = new Set(incorrectPartIds);
  const namePart = question.parts.find((part) => part.id === "name");
  const diagramParts = question.parts.filter((part) => part.id !== "name");
  const diagramPartById = new Map(diagramParts.map((part) => [part.id, part] as const));
  const diagramCalibrationEnabled =
    DEVELOPMENT_DIAGRAM_CALIBRATION_ENABLED && !reviewMode && searchParams.get("calibrate") === "1";
  const calibration = useLessonAssessmentDiagramCalibration({
    apiBaseUrl,
    enabled: diagramCalibrationEnabled,
    height: question.height,
    questionId: question.id,
    route,
    sourceCallouts: question.callouts,
    width: question.width,
  });
  const sourceCallouts = diagramCalibrationEnabled
    ? calibration.callouts
    : question.callouts;
  const optionById = new Map(
    question.type === "diagram_labels" ? question.options.map((option) => [option.id, option] as const) : [],
  );
  const sourceCalloutsByPartId = groupCalloutsByPartId(sourceCallouts);
  const hasInlineNameLabel =
    namePart !== undefined && inlineLabelCallout(sourceCalloutsByPartId.get(namePart.id)) !== undefined;
  const inlineDiagramParts = hasInlineNameLabel ? question.parts : diagramParts;
  const inlineDiagramPartById = new Map(inlineDiagramParts.map((part) => [part.id, part] as const));
  const hasInlineLabels =
    question.type === "diagram_text" &&
    diagramParts.length > 0 &&
    diagramParts.every((part) => {
      return inlineLabelCallout(sourceCalloutsByPartId.get(part.id)) !== undefined;
    });
  const diagramStageScale = lessonAssessmentDiagramStageScale(route, question.id);
  const hasBottomEdgeLabel = inlineDiagramParts.some((part) => {
    const callout = inlineLabelCallout(sourceCalloutsByPartId.get(part.id));

    return callout?.labelSide === "bottom" && callout.labelY !== undefined && callout.labelY >= 0.9;
  });
  const hasTopEdgeLabel = inlineDiagramParts.some((part) => {
    const callout = inlineLabelCallout(sourceCalloutsByPartId.get(part.id));

    return callout?.labelSide === "top" && callout.labelY !== undefined && callout.labelY <= 0.1;
  });
  // Collapses only measured transparent canvas; the stage and every internal coordinate stay unchanged.
  const diagramStageTrim = DIAGRAM_STAGE_TRIM_BY_QUESTION[question.id] ?? {
    blockStart: 0,
    blockEnd: 0,
  };
  const diagramStageCroppedHeight = Math.max(
    1,
    question.height -
      (diagramStageTrim.blockStart + diagramStageTrim.blockEnd) * question.width,
  );
  const diagramStageShiftY = -(
    (diagramStageTrim.blockStart * question.width * 100) /
    question.height
  );
  const imageFrameStyle: CSSProperties & Record<
    | "--diagram-aspect"
    | "--diagram-crop-aspect"
    | "--diagram-stage-scale"
    | "--diagram-stage-shift-y"
    | "--diagram-stage-top-clearance"
    | "--diagram-stage-bottom-clearance",
    string
  > = {
    "--diagram-aspect": `${question.width} / ${question.height}`,
    "--diagram-crop-aspect": `${question.width} / ${diagramStageCroppedHeight}`,
    "--diagram-stage-scale": String(diagramStageScale),
    "--diagram-stage-shift-y": `${diagramStageShiftY}%`,
    "--diagram-stage-top-clearance": hasTopEdgeLabel ? EDGE_LABEL_CLEARANCE : "0rem",
    "--diagram-stage-bottom-clearance": hasBottomEdgeLabel ? EDGE_LABEL_CLEARANCE : "0rem",
  };

  function renderControl(
    part: DiagramLabelsQuestion["parts"][number] | DiagramTextQuestion["parts"][number],
    errorId: string | undefined,
    variant?: "box",
    fitContent = false,
  ) {
    const hasValue = (values[part.id] ?? "").trim() !== "";
    const reviewState = reviewMode && hasValue
      ? errorId === undefined ? "correct" : "incorrect"
      : undefined;
    const reviewStatusId = reviewState === undefined
      ? undefined
      : `assessment-${question.id}-${part.id}-review-status`;
    const describedBy = [errorId, reviewStatusId].filter(Boolean).join(" ") || undefined;
    if (question.type === "diagram_text") {
      const inlinePartPlaceholder = part.id === "name"
        ? LATIN_DIAGRAM_NAME_QUESTION_IDS.has(question.id)
          ? DIAGRAM_LATIN_NAME_PLACEHOLDER
          : DIAGRAM_NAME_PLACEHOLDER
        : LATIN_DIAGRAM_QUESTION_IDS.has(question.id)
          ? DIAGRAM_LATIN_LABEL_PLACEHOLDER
          : DIAGRAM_LABEL_PLACEHOLDER;

      return (
        <LessonAssessmentTextInput
          apiBaseUrl={apiBaseUrl}
          route={route}
          attemptId={attemptId}
          questionId={question.id}
          partId={part.id}
          value={values[part.id] ?? ""}
          acceptedSuggestionValue={acceptedSuggestionValueFor(question.id, part.id)}
          disabled={disabled}
          invalid={errorId !== undefined}
          fitContent={fitContent}
          describedBy={errorId}
          reviewState={reviewState}
          suggestionsEnabled={question.gradingMode === "automatic"}
          onSuggestionAcceptedChange={(value) =>
            onSuggestionAcceptedChange(question.id, part.id, value)
          }
          showGuide={!hasInlineLabels || (part.id === "name" && !hasInlineNameLabel)}
          placeholder={hasInlineLabels ? inlinePartPlaceholder : undefined}
          variant={variant}
          onChange={(value) => onChange(part.id, value)}
        />
      );
    }

    const optionIds = "optionIds" in part ? part.optionIds : [];
    return (
      <>
        <select
          id={`assessment-${question.id}-${part.id}`}
          value={values[part.id] ?? ""}
          disabled={disabled}
          aria-invalid={errorId === undefined ? undefined : true}
          aria-describedby={describedBy}
          className={`${selectClassName} ${reviewState === "correct" ? styles.reviewAnswerCorrect : reviewState === "incorrect" ? styles.reviewAnswerIncorrect : ""}`}
          data-review-state={reviewState}
          onChange={(event) => onChange(part.id, event.target.value)}
          data-ui={`lk.learning.assessment.question.${question.id}.part.${part.id}.select`}
        >
          <option value="">Выберите подпись</option>
          {optionIds.map((optionId) => {
            const option = optionById.get(optionId);
            return option === undefined ? null : (
              <option key={option.id} value={option.id}>
                {option.label}
              </option>
            );
          })}
        </select>
        {reviewStatusId ? (
          <span id={reviewStatusId} className="sr-only">
            {reviewState === "correct" ? "Верный ответ" : "Неверный ответ"}
          </span>
        ) : null}
      </>
    );
  }

  function renderImage() {
    return objectUrl === null ? null : (
      // eslint-disable-next-line @next/next/no-img-element -- protected runtime asset requires browser credentials
      <img
        key={imageState.revision}
        src={objectUrl}
        alt={`Схема к заданию: ${question.prompt}`}
        width={question.width}
        height={question.height}
        className={`${styles.image} ${atomicVisibilityClass}`}
        decoding="async"
        fetchPriority="high"
        onLoad={handleImageLoaded}
        onError={() => {
          if (currentRevisionRef.current !== imageState.revision) {
            return;
          }
          dispatchImage({ type: "failed", questionId: question.id, revision: imageState.revision });
          onAvailabilityChange(question.id, false);
        }}
      />
    );
  }

  function renderImageError() {
    return effectiveImageStatus === "error" ? (
      <div
        className="mt-3 grid gap-3"
        role="alert"
        data-ui={`lk.learning.assessment.question.${question.id}.diagram.error`}
      >
        <p className="ui-text-danger text-sm font-medium">Не удалось загрузить схему. Повторите загрузку.</p>
        <Button
          type="button"
          variant="secondary"
          fullWidth={false}
          disabled={disabled}
          onClick={() => {
            dispatchImage({ type: "retry", questionId: question.id });
            onAvailabilityChange(question.id, false);
          }}
          data-ui={`lk.learning.assessment.question.${question.id}.diagram.retryButton`}
        >
          Повторить загрузку
        </Button>
      </div>
    ) : null;
  }

  function renderImagePlaceholder() {
    return effectiveImageStatus === "ready" && objectUrl !== null ? null : (
      <div
        className={styles.diagramImagePlaceholder}
        role={effectiveImageStatus === "loading" ? "status" : undefined}
        aria-live="polite"
        data-ui={`lk.learning.assessment.question.${question.id}.diagram.placeholder`}
        data-state={effectiveImageStatus}
      >
        {effectiveImageStatus === "loading" ? "Загружаем схему" : "Схема недоступна"}
      </div>
    );
  }

  const namePartControl = namePart && !hasInlineNameLabel ? (
    <div
      className={[hasInlineLabels ? "max-w-full" : "", atomicVisibilityClass].filter(Boolean).join(" ")}
      data-state={effectiveImageStatus}
    >
      <label
        className={question.type === "diagram_text" ? "sr-only" : "block text-sm font-semibold ui-text-primary"}
        htmlFor={`assessment-${question.id}-${namePart.id}`}
      >
        {namePart.prompt}
      </label>
      {renderControl(
        namePart,
        incorrectIds.has(namePart.id) ? `assessment-${question.id}-${namePart.id}-error` : undefined,
        hasInlineLabels ? "box" : undefined,
        hasInlineLabels,
      )}
      {incorrectIds.has(namePart.id) ? (
        <p
          id={`assessment-${question.id}-${namePart.id}-error`}
          className={reviewMode || question.type === "diagram_text" ? "sr-only" : "ui-text-danger mt-2 text-sm font-medium"}
        >
          Ошибка в этой подписи
        </p>
      ) : null}
    </div>
  ) : null;

  if (hasInlineLabels) {
    return (
      <div
        className={styles.interactiveDiagramLayout}
        data-ui={`lk.learning.assessment.question.${question.id}.diagram`}
      >
        {namePartControl}

        {diagramCalibrationEnabled ? (
          <LessonAssessmentDiagramCalibrationPanel
            answerTerms={calibration.answerTerms}
            callouts={sourceCallouts}
            exportText={calibration.exportText}
            fixedAt={calibration.fixedAt}
            hasChanges={calibration.hasChanges}
            height={question.height}
            isExportOpen={calibration.isExportOpen}
            isOpen={calibration.isOpen}
            onAlignField={calibration.alignField}
            onFix={calibration.fix}
            onMove={calibration.move}
            onOpenChange={calibration.setIsOpen}
            onReset={calibration.reset}
            onSelectTarget={calibration.selectTarget}
            onSetMarker={calibration.setMarker}
            onStepChange={calibration.setStep}
            onToggleExport={calibration.toggleExport}
            parts={inlineDiagramParts}
            questionId={question.id}
            selectedTargetId={calibration.selectedTargetId}
            step={calibration.step}
            width={question.width}
          />
        ) : null}

        <div className="min-w-0">
          <div
            className={styles.interactiveDiagramViewport}
            role="region"
            aria-label="Интерактивная схема с полями для подписей"
            tabIndex={0}
          >
            <div
              className={styles.interactiveDiagramCrop}
              style={imageFrameStyle}
            >
              <div
                className={styles.interactiveDiagramStage}
                data-ui={`lk.learning.assessment.question.${question.id}.diagram.image`}
                data-state={effectiveImageStatus}
                aria-busy={effectiveImageStatus === "loading" ? true : undefined}
              >
                {renderImage()}
                {renderImagePlaceholder()}
                <svg
                  className={`${styles.interactiveDiagramLines} ${atomicVisibilityClass}`}
                  viewBox={`0 0 ${question.width} ${question.height}`}
                  preserveAspectRatio="none"
                  aria-hidden="true"
                >
                <defs>
                  <marker
                    id={`assessment-${question.id}-arrow`}
                    viewBox="0 0 12 12"
                    refX="11"
                    refY="6"
                    markerWidth="18"
                    markerHeight="18"
                    markerUnits="userSpaceOnUse"
                    orient="auto"
                  >
                    <path className={styles.interactiveDiagramArrow} d="M 1 1 L 11 6 L 1 11 Z" />
                  </marker>
                  <marker
                    id={`assessment-${question.id}-arrow-incorrect`}
                    viewBox="0 0 12 12"
                    refX="11"
                    refY="6"
                    markerWidth="18"
                    markerHeight="18"
                    markerUnits="userSpaceOnUse"
                    orient="auto"
                  >
                    <path className={styles.interactiveDiagramArrowIncorrect} d="M 1 1 L 11 6 L 1 11 Z" />
                  </marker>
                </defs>
                {sourceCallouts.map((callout) => {
                  const part = inlineDiagramPartById.get(callout.partId);
                  const labelCallout = inlineLabelCallout(sourceCalloutsByPartId.get(callout.partId));
                  if (
                    part === undefined ||
                    labelCallout?.labelX === undefined ||
                    labelCallout.labelY === undefined
                  ) {
                    return null;
                  }

                  const labelX = labelCallout.labelX * question.width;
                  const labelY = labelCallout.labelY * question.height;
                  const targetX = callout.x * question.width;
                  const targetY = callout.y * question.height;
                  const incorrect = incorrectIds.has(part.id);
                  const targetId = diagramCalloutTargetId(callout);
                  const path = diagramLeaderPath({
                    labelX,
                    labelY,
                    style: labelCallout.leaderStyle ?? "elbow",
                    targetX,
                    targetY,
                  });

                  return (
                    <g
                      key={targetId}
                      data-state={incorrect ? "incorrect" : "idle"}
                      data-target-id={targetId}
                      data-part-id={part.id}
                      data-leader-style={labelCallout.leaderStyle ?? "elbow"}
                      data-target-marker={callout.targetMarker ?? "point"}
                      data-x={callout.x}
                      data-y={callout.y}
                    >
                      <path className={styles.interactiveDiagramLineHalo} d={path} />
                      <path
                        className={styles.interactiveDiagramLine}
                        d={path}
                        markerEnd={callout.targetMarker === "arrow"
                          ? `url(#assessment-${question.id}-arrow${incorrect ? "-incorrect" : ""})`
                          : undefined}
                      />
                      {callout.targetMarker === "arrow" ? null : (
                        <circle
                          className={styles.interactiveDiagramPoint}
                          cx={targetX}
                          cy={targetY}
                          r="7"
                        />
                      )}
                      {calibration.isOpen && calibration.selectedTargetId === targetId ? (
                        <circle
                          className={styles.diagramCalibrationTargetSelection}
                          cx={targetX}
                          cy={targetY}
                          r="14"
                        />
                      ) : null}
                    </g>
                  );
                })}
                </svg>

                {inlineDiagramParts.map((part) => {
                  const callout = inlineLabelCallout(sourceCalloutsByPartId.get(part.id));
                  if (callout?.labelX === undefined || callout.labelY === undefined) {
                    return null;
                  }

                  const errorId = incorrectIds.has(part.id)
                    ? `assessment-${question.id}-${part.id}-error`
                    : undefined;
                  const labelSide = callout.labelSide ?? "right";
                  const labelWidth = diagramInlineLabelWidth(callout, diagramStageScale);
                  const availableLabelWidth = diagramInlineLabelAvailableWidth(callout);
                  const fieldLeft = diagramInlineLabelLeft(callout, labelWidth);
                  const fieldWidth = diagramInlineLabelCssWidth(
                    labelWidth,
                    availableLabelWidth,
                  );
                  const fieldStyle = diagramInlineFieldStyle({
                    fieldLeft,
                    labelSide,
                    labelWidth,
                    labelY: callout.labelY,
                    width: fieldWidth,
                  });

                  return (
                    <div
                      key={part.id}
                      className={`${styles.interactiveDiagramField} ${atomicVisibilityClass}`}
                      style={fieldStyle}
                      data-calibration-selected={
                        calibration.isOpen && sourceCalloutsByPartId.get(part.id)?.some(
                          (partCallout) =>
                            diagramCalloutTargetId(partCallout) === calibration.selectedTargetId,
                        )
                          ? true
                          : undefined
                      }
                      data-label-side={labelSide}
                      onFocusCapture={() => calibration.selectPart(part.id)}
                    >
                      <label className="sr-only" htmlFor={`assessment-${question.id}-${part.id}`}>
                        {part.prompt}
                      </label>
                      {renderControl(part, errorId, "box", true)}
                      {errorId ? (
                        <p id={errorId} className="sr-only">
                          Ошибка в этой подписи
                        </p>
                      ) : null}
                    </div>
                  );
                })}
              </div>
            </div>
          </div>
          {renderImageError()}
        </div>
      </div>
    );
  }

  return (
    <div className={styles.diagramLayout} data-ui={`lk.learning.assessment.question.${question.id}.diagram`}>
      <div>
        <div
          className={`${styles.imageFrame} overflow-hidden rounded-[var(--radius-surface)] border border-[var(--color-border-subtle)] bg-[var(--color-surface-subtle)]`}
          style={imageFrameStyle}
          data-ui={`lk.learning.assessment.question.${question.id}.diagram.image`}
          data-state={effectiveImageStatus}
          aria-busy={effectiveImageStatus === "loading" ? true : undefined}
        >
          {renderImage()}
          {renderImagePlaceholder()}
          {sourceCallouts.map((callout) => {
            const part = diagramPartById.get(callout.partId);
            if (part === undefined) {
              return null;
            }
            const incorrect = incorrectIds.has(part.id);
            const targetId = diagramCalloutTargetId(callout);
            return (
              <span
                key={targetId}
                className={`${styles.marker} ${atomicVisibilityClass} inline-flex h-7 min-w-7 items-center justify-center rounded-full border-2 border-white bg-[var(--color-brand-deep)] px-1 text-xs font-bold text-white shadow-sm`}
                style={{ left: `${callout.x * 100}%`, top: `${callout.y * 100}%` }}
                aria-hidden="true"
                data-state={incorrect ? "incorrect" : "idle"}
              >
                {diagramPointLabel(sourceCallouts, callout)}{incorrect ? "!" : ""}
              </span>
            );
          })}
        </div>

        {renderImageError()}
      </div>

      <div className={`grid content-start gap-3 ${atomicVisibilityClass}`} data-state={effectiveImageStatus}>
        {namePartControl}

        <ol className="grid list-none gap-3 p-0" aria-label="Подписи к нумерованным меткам схемы">
          {diagramParts.map((part, index) => {
            const errorId = incorrectIds.has(part.id)
              ? `assessment-${question.id}-${part.id}-error`
              : undefined;
            return (
              <li key={part.id} className="grid grid-cols-[2rem_1fr] items-end gap-3">
                <span
                  className="ui-text-brand mb-1 inline-flex h-7 min-w-7 items-center justify-center rounded-full bg-[var(--color-brand-surface)] px-1 text-xs font-bold"
                  aria-hidden="true"
                >
                  {index + 1}
                </span>
                <div>
                  <label className={question.type === "diagram_text" ? "sr-only" : "block text-sm font-semibold ui-text-primary"} htmlFor={`assessment-${question.id}-${part.id}`}>
                    {part.prompt}
                  </label>
                  {renderControl(part, errorId)}
                  {errorId ? (
                    <p id={errorId} className={reviewMode || question.type === "diagram_text" ? "sr-only" : "ui-text-danger mt-2 text-sm font-medium"}>
                      Ошибка в этой подписи
                    </p>
                  ) : null}
                </div>
              </li>
            );
          })}
        </ol>
      </div>
    </div>
  );
}
