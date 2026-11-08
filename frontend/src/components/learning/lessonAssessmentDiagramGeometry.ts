import type {
  LessonAssessmentCallout,
  LessonAssessmentRoute,
} from "@/lib/lessonAssessmentContract";

type DiagramLeaderPathInput = {
  readonly labelX: number;
  readonly labelY: number;
  readonly style: "elbow" | "straight";
  readonly targetX: number;
  readonly targetY: number;
};

const DEFAULT_INLINE_LABEL_WIDTH = 0.28;
const INLINE_LABEL_EDGE = 1;
const NORMALIZED_DIAGRAM_STAGE_SCALES: Readonly<Record<string, number>> = {
  "anatomiya/artrologiya/soedineniya-kostej-tulovishcha-i-cherepa/diagram-01": 5 / 6,
  "anatomiya/artrologiya/soedineniya-kostej-tulovishcha-i-cherepa/diagram-03": 19 / 21,
};

export function lessonAssessmentDiagramStageScale(
  route: LessonAssessmentRoute,
  questionId: string,
): number {
  const key = `${route.subjectSlug}/${route.courseSlug}/${route.lessonSlug}/${questionId}`;
  return NORMALIZED_DIAGRAM_STAGE_SCALES[key] ?? 1;
}

export function diagramCalloutTargetId(callout: LessonAssessmentCallout): string {
  return callout.targetId ?? callout.partId;
}

export function diagramPointLabel(
  callouts: ReadonlyArray<LessonAssessmentCallout>,
  callout: LessonAssessmentCallout,
): string {
  const partIds = [...new Set(callouts.map((candidate) => candidate.partId))];
  const partCallouts = callouts.filter((candidate) => candidate.partId === callout.partId);
  const partNumber = partIds.indexOf(callout.partId) + 1;
  if (partCallouts.length <= 1) {
    return String(partNumber);
  }

  const targetNumber =
    partCallouts.findIndex(
      (candidate) => diagramCalloutTargetId(candidate) === diagramCalloutTargetId(callout),
    ) + 1;
  return `${partNumber}.${targetNumber}`;
}

export function diagramInlineLabelWidth(
  callout: LessonAssessmentCallout,
  stageScale = 1,
): number {
  if (callout.labelX === undefined) {
    return 0;
  }

  const preferredWidth = (callout.labelWidth ?? DEFAULT_INLINE_LABEL_WIDTH) / stageScale;
  const availableWidth = callout.labelSide === "left"
    ? callout.labelX - (1 - INLINE_LABEL_EDGE)
    : callout.labelSide === "top" || callout.labelSide === "bottom"
      ? 2 * Math.min(
          callout.labelX - (1 - INLINE_LABEL_EDGE),
          INLINE_LABEL_EDGE - callout.labelX,
        )
      : INLINE_LABEL_EDGE - callout.labelX;
  return Math.max(0, Math.min(preferredWidth, availableWidth));
}

export function diagramInlineLabelAvailableWidth(
  callout: LessonAssessmentCallout,
): number {
  if (callout.labelX === undefined) {
    return 0;
  }

  if (callout.labelSide === "left") {
    return Math.max(0, callout.labelX - (1 - INLINE_LABEL_EDGE));
  }

  if (callout.labelSide === "top" || callout.labelSide === "bottom") {
    return Math.max(
      0,
      2 * Math.min(
        callout.labelX - (1 - INLINE_LABEL_EDGE),
        INLINE_LABEL_EDGE - callout.labelX,
      ),
    );
  }

  return Math.max(0, INLINE_LABEL_EDGE - callout.labelX);
}

export function diagramInlineLabelLeft(
  callout: LessonAssessmentCallout,
  labelWidth: number,
): number {
  if (callout.labelX === undefined) {
    return 0;
  }
  if (callout.labelSide === "left") {
    return callout.labelX - labelWidth;
  }
  if (callout.labelSide === "top" || callout.labelSide === "bottom") {
    return callout.labelX - labelWidth / 2;
  }

  return callout.labelX;
}

export function diagramLeaderPath({
  labelX,
  labelY,
  style,
  targetX,
  targetY,
}: DiagramLeaderPathInput): string {
  if (style === "straight") {
    return `M ${labelX} ${labelY} L ${targetX} ${targetY}`;
  }

  const horizontal = Math.abs(labelY - targetY) < 0.5;
  if (horizontal) {
    return `M ${labelX} ${labelY} H ${targetX}`;
  }

  const bendX = labelX + (targetX - labelX) * 0.28;
  return `M ${labelX} ${labelY} H ${bendX} L ${targetX} ${targetY}`;
}
