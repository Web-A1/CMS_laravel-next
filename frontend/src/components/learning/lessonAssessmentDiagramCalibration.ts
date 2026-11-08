import type {
  LessonAssessmentCallout,
  LessonAssessmentRoute,
} from "@/lib/lessonAssessmentContract";

export type DiagramCalibrationMarker = "point" | "arrow";

type DiagramCalibrationTargetSnapshot = {
  readonly labelY?: number;
  readonly leaderStyle?: "elbow" | "straight";
  readonly partId: string;
  readonly targetId: string;
  readonly targetMarker: DiagramCalibrationMarker;
  readonly x: number;
  readonly y: number;
};

type DiagramCalibrationSnapshot = {
  readonly fixedAt: string | null;
  readonly questionId: string;
  readonly sourceSignature: string;
  readonly targets: ReadonlyArray<DiagramCalibrationTargetSnapshot>;
  readonly version: 1;
};

export type RestoredDiagramCalibration = {
  readonly callouts: ReadonlyArray<LessonAssessmentCallout>;
  readonly fixedAt: string | null;
};

type DiagramCalibrationMovement = {
  readonly x: number;
  readonly y: number;
};

type DiagramDimensions = {
  readonly height: number;
  readonly width: number;
};

function clampUnit(value: number): number {
  return Number(Math.min(1, Math.max(0, value)).toFixed(6));
}

function calibrationTargetId(callout: LessonAssessmentCallout): string {
  return callout.targetId ?? callout.partId;
}

function calibrationTarget(callout: LessonAssessmentCallout): DiagramCalibrationTargetSnapshot {
  return {
    targetId: calibrationTargetId(callout),
    partId: callout.partId,
    x: callout.x,
    y: callout.y,
    targetMarker: callout.targetMarker ?? "point",
    ...(callout.labelY === undefined ? {} : { labelY: callout.labelY }),
    ...(callout.leaderStyle === undefined ? {} : { leaderStyle: callout.leaderStyle }),
  };
}

function calibrationSourceTarget(callout: LessonAssessmentCallout) {
  return {
    targetId: calibrationTargetId(callout),
    partId: callout.partId,
    x: callout.x,
    y: callout.y,
    targetMarker: callout.targetMarker ?? "point",
    labelX: callout.labelX ?? null,
    labelY: callout.labelY ?? null,
    labelSide: callout.labelSide ?? "right",
    labelWidth: callout.labelWidth ?? null,
    leaderStyle: callout.leaderStyle ?? "elbow",
  };
}

export function diagramCalibrationSourceSignature(
  callouts: ReadonlyArray<LessonAssessmentCallout>,
): string {
  return JSON.stringify(callouts.map(calibrationSourceTarget));
}

function isRecord(value: unknown): value is Readonly<Record<string, unknown>> {
  return typeof value === "object" && value !== null && !Array.isArray(value);
}

function isCalibrationTargetSnapshot(value: unknown): value is DiagramCalibrationTargetSnapshot {
  return (
    isRecord(value) &&
    typeof value.targetId === "string" &&
    typeof value.partId === "string" &&
    typeof value.x === "number" &&
    Number.isFinite(value.x) &&
    value.x >= 0 &&
    value.x <= 1 &&
    typeof value.y === "number" &&
    Number.isFinite(value.y) &&
    value.y >= 0 &&
    value.y <= 1 &&
    (value.targetMarker === "point" || value.targetMarker === "arrow") &&
    (value.labelY === undefined ||
      (typeof value.labelY === "number" &&
        Number.isFinite(value.labelY) &&
        value.labelY >= 0 &&
        value.labelY <= 1)) &&
    (value.leaderStyle === undefined ||
      value.leaderStyle === "elbow" ||
      value.leaderStyle === "straight")
  );
}

function parseDiagramCalibrationSnapshot(value: unknown): DiagramCalibrationSnapshot | null {
  if (
    !isRecord(value) ||
    value.version !== 1 ||
    typeof value.questionId !== "string" ||
    typeof value.sourceSignature !== "string" ||
    (value.fixedAt !== null && typeof value.fixedAt !== "string") ||
    !Array.isArray(value.targets) ||
    !value.targets.every(isCalibrationTargetSnapshot)
  ) {
    return null;
  }

  return {
    version: 1,
    questionId: value.questionId,
    sourceSignature: value.sourceSignature,
    fixedAt: value.fixedAt,
    targets: value.targets,
  };
}

export function diagramCalibrationStorageKey(
  route: LessonAssessmentRoute,
  questionId: string,
): string {
  return `cms:lesson-assessment:diagram-calibration:v1:${route.subjectSlug}/${route.courseSlug}/${route.lessonSlug}/${questionId}`;
}

export function moveDiagramCalibrationTarget(
  callouts: ReadonlyArray<LessonAssessmentCallout>,
  targetId: string,
  movement: DiagramCalibrationMovement,
  dimensions: DiagramDimensions,
): ReadonlyArray<LessonAssessmentCallout> {
  if (dimensions.width <= 0 || dimensions.height <= 0) {
    return callouts;
  }

  let changed = false;
  const next = callouts.map((callout) => {
    if (calibrationTargetId(callout) !== targetId) {
      return callout;
    }

    changed = true;
    return {
      ...callout,
      x: clampUnit(callout.x + movement.x / dimensions.width),
      y: clampUnit(callout.y + movement.y / dimensions.height),
    };
  });

  return changed ? next : callouts;
}

export function setDiagramCalibrationTargetMarker(
  callouts: ReadonlyArray<LessonAssessmentCallout>,
  targetId: string,
  targetMarker: DiagramCalibrationMarker,
): ReadonlyArray<LessonAssessmentCallout> {
  let changed = false;
  const next = callouts.map((callout) => {
    if (
      calibrationTargetId(callout) !== targetId ||
      (callout.targetMarker ?? "point") === targetMarker
    ) {
      return callout;
    }

    changed = true;
    return { ...callout, targetMarker };
  });

  return changed ? next : callouts;
}

export function alignDiagramCalibrationLabelToTarget(
  callouts: ReadonlyArray<LessonAssessmentCallout>,
  targetId: string,
): ReadonlyArray<LessonAssessmentCallout> {
  const selectedTarget = callouts.find(
    (callout) => calibrationTargetId(callout) === targetId,
  );
  if (selectedTarget === undefined) {
    return callouts;
  }

  const labelCalloutIndex = callouts.findIndex(
    (callout) =>
      callout.partId === selectedTarget.partId &&
      callout.labelX !== undefined &&
      callout.labelY !== undefined,
  );
  if (labelCalloutIndex < 0) {
    return callouts;
  }

  const labelCallout = callouts[labelCalloutIndex];
  if (
    labelCallout === undefined ||
    (labelCallout.labelY === selectedTarget.y && labelCallout.leaderStyle === "straight")
  ) {
    return callouts;
  }

  return callouts.map((callout, index) =>
    index === labelCalloutIndex
      ? { ...callout, labelY: selectedTarget.y, leaderStyle: "straight" }
      : callout,
  );
}

export function serializeDiagramCalibrationSnapshot({
  fixedAt,
  questionId,
  sourceCallouts,
  workingCallouts,
}: {
  readonly fixedAt: string | null;
  readonly questionId: string;
  readonly sourceCallouts: ReadonlyArray<LessonAssessmentCallout>;
  readonly workingCallouts: ReadonlyArray<LessonAssessmentCallout>;
}): string {
  const snapshot: DiagramCalibrationSnapshot = {
    version: 1,
    questionId,
    sourceSignature: diagramCalibrationSourceSignature(sourceCallouts),
    fixedAt,
    targets: workingCallouts.map(calibrationTarget),
  };

  return JSON.stringify(snapshot, null, 2);
}

export function restoreDiagramCalibrationSnapshot(
  serialized: string,
  questionId: string,
  sourceCallouts: ReadonlyArray<LessonAssessmentCallout>,
): RestoredDiagramCalibration | null {
  let parsed: unknown;
  try {
    parsed = JSON.parse(serialized) as unknown;
  } catch {
    return null;
  }

  const snapshot = parseDiagramCalibrationSnapshot(parsed);
  if (
    snapshot === null ||
    snapshot.questionId !== questionId ||
    snapshot.sourceSignature !== diagramCalibrationSourceSignature(sourceCallouts) ||
    snapshot.targets.length !== sourceCallouts.length
  ) {
    return null;
  }

  const targetById = new Map(snapshot.targets.map((target) => [target.targetId, target] as const));
  const callouts: Array<LessonAssessmentCallout> = [];
  for (const sourceCallout of sourceCallouts) {
    const target = targetById.get(calibrationTargetId(sourceCallout));
    if (target === undefined || target.partId !== sourceCallout.partId) {
      return null;
    }
    callouts.push({
      ...sourceCallout,
      x: target.x,
      y: target.y,
      targetMarker: target.targetMarker,
      ...(target.labelY === undefined ? {} : { labelY: target.labelY }),
      ...(target.leaderStyle === undefined ? {} : { leaderStyle: target.leaderStyle }),
    });
  }

  return { callouts, fixedAt: snapshot.fixedAt };
}
