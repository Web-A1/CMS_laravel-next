import assert from "node:assert/strict";
import test from "node:test";

import type { LessonAssessmentCallout, LessonAssessmentRoute } from "@/lib/lessonAssessmentContract";

const calibrationModulePath = "./lessonAssessmentDiagramCalibration.ts";
const calibration: typeof import("./lessonAssessmentDiagramCalibration") = await import(calibrationModulePath);

const route: LessonAssessmentRoute = {
  subjectSlug: "anatomiya",
  courseSlug: "artrologiya",
  lessonSlug: "lesson",
};

const sourceCallouts: ReadonlyArray<LessonAssessmentCallout> = [
  {
    targetId: "p01-1",
    partId: "p01",
    x: 0.4,
    y: 0.5,
    labelX: 0.2,
    labelY: 0.5,
    targetMarker: "point",
  },
  {
    targetId: "p01-2",
    partId: "p01",
    x: 0.6,
    y: 0.7,
    labelX: 0.2,
    labelY: 0.5,
  },
];

test("moves one selected diagram target in image pixels and keeps the rest immutable", () => {
  const moved = calibration.moveDiagramCalibrationTarget(
    sourceCallouts,
    "p01-2",
    { x: 20, y: -10 },
    { width: 1000, height: 500 },
  );

  assert.notEqual(moved, sourceCallouts);
  assert.equal(moved[0], sourceCallouts[0]);
  assert.deepEqual(moved[1], {
    ...sourceCallouts[1],
    x: 0.62,
    y: 0.68,
  });
  assert.deepEqual(sourceCallouts[1], {
    targetId: "p01-2",
    partId: "p01",
    x: 0.6,
    y: 0.7,
    labelX: 0.2,
    labelY: 0.5,
  });
});

test("clamps moved targets to the image bounds", () => {
  const moved = calibration.moveDiagramCalibrationTarget(
    sourceCallouts,
    "p01-1",
    { x: -500, y: 500 },
    { width: 100, height: 100 },
  );

  assert.equal(moved[0]?.x, 0);
  assert.equal(moved[0]?.y, 1);
});

test("changes only the selected target marker", () => {
  const changed = calibration.setDiagramCalibrationTargetMarker(sourceCallouts, "p01-2", "arrow");

  assert.equal(changed[0], sourceCallouts[0]);
  assert.deepEqual(changed[1], {
    ...sourceCallouts[1],
    targetMarker: "arrow",
  });
});

test("aligns the shared field and leader horizontally to the selected target", () => {
  const aligned = calibration.alignDiagramCalibrationLabelToTarget(sourceCallouts, "p01-2");

  assert.notEqual(aligned, sourceCallouts);
  assert.deepEqual(aligned[0], {
    ...sourceCallouts[0],
    labelY: 0.7,
    leaderStyle: "straight",
  });
  assert.equal(aligned[1], sourceCallouts[1]);
  assert.equal(aligned[0]?.x, sourceCallouts[0]?.x);
  assert.equal(aligned[1]?.y, sourceCallouts[1]?.y);
});

test("restores a matching calibration snapshot and rejects stale source geometry or label layout", () => {
  const working = calibration.alignDiagramCalibrationLabelToTarget(
    calibration.setDiagramCalibrationTargetMarker(
      calibration.moveDiagramCalibrationTarget(
        sourceCallouts,
        "p01-2",
        { x: 5, y: 5 },
        { width: 100, height: 100 },
      ),
      "p01-2",
      "arrow",
    ),
    "p01-2",
  );
  const serialized = calibration.serializeDiagramCalibrationSnapshot({
    fixedAt: "2026-08-25T12:00:00.000Z",
    questionId: "diagram-03",
    sourceCallouts,
    workingCallouts: working,
  });

  const restored = calibration.restoreDiagramCalibrationSnapshot(
    serialized,
    "diagram-03",
    sourceCallouts,
  );
  assert.deepEqual(restored, {
    callouts: working,
    fixedAt: "2026-08-25T12:00:00.000Z",
  });

  const changedSource = sourceCallouts.map((callout, index) =>
    index === 0 ? { ...callout, x: callout.x + 0.01 } : callout,
  );
  assert.equal(
    calibration.restoreDiagramCalibrationSnapshot(serialized, "diagram-03", changedSource),
    null,
  );

  const changedLabelLayout = sourceCallouts.map((callout, index) =>
    index === 0 ? { ...callout, labelY: 0.45 } : callout,
  );
  assert.equal(
    calibration.restoreDiagramCalibrationSnapshot(serialized, "diagram-03", changedLabelLayout),
    null,
  );
});

test("uses a lesson-and-question scoped storage key", () => {
  assert.equal(
    calibration.diagramCalibrationStorageKey(route, "diagram-03"),
    "cms:lesson-assessment:diagram-calibration:v1:anatomiya/artrologiya/lesson/diagram-03",
  );
});
