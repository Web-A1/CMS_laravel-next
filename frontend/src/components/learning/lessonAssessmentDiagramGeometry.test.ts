import assert from "node:assert/strict";
import test from "node:test";

import type { LessonAssessmentCallout } from "@/lib/lessonAssessmentContract";

const geometryModulePath = "./lessonAssessmentDiagramGeometry.ts";
const geometry: typeof import("./lessonAssessmentDiagramGeometry") = await import(geometryModulePath);

const route = {
  subjectSlug: "anatomiya",
  courseSlug: "artrologiya",
  lessonSlug: "soedineniya-kostej-tulovishcha-i-cherepa",
} as const;

test("normalizes the two oversized diagrams without changing other lessons", () => {
  assert.equal(geometry.lessonAssessmentDiagramStageScale(route, "diagram-01"), 5 / 6);
  assert.equal(geometry.lessonAssessmentDiagramStageScale(route, "diagram-02"), 1);
  assert.equal(geometry.lessonAssessmentDiagramStageScale(route, "diagram-03"), 19 / 21);
  assert.equal(
    geometry.lessonAssessmentDiagramStageScale(
      { ...route, lessonSlug: "another-lesson" },
      "diagram-01",
    ),
    1,
  );
});

test("renders direct and elbow leaders from the field edge without a shelf", () => {
  assert.equal(
    geometry.diagramLeaderPath({
      labelX: 700,
      labelY: 400,
      style: "straight",
      targetX: 500,
      targetY: 400,
    }),
    "M 700 400 L 500 400",
  );
  assert.equal(
    geometry.diagramLeaderPath({
      labelX: 700,
      labelY: 400,
      style: "elbow",
      targetX: 500,
      targetY: 520,
    }),
    "M 700 400 H 644 L 500 520",
  );
  assert.equal(
    geometry.diagramLeaderPath({
      labelX: 700,
      labelY: 400,
      style: "elbow",
      targetX: 520,
      targetY: 560,
    }),
    "M 700 400 H 649.6 L 520 560",
  );
});

test("numbers repeated targets under one diagram term", () => {
  const callouts: ReadonlyArray<LessonAssessmentCallout> = [
    { targetId: "p01-1", partId: "p01", x: 0.4, y: 0.4 },
    { targetId: "p01-2", partId: "p01", x: 0.5, y: 0.5 },
    { partId: "p02", x: 0.6, y: 0.6 },
  ];

  assert.equal(geometry.diagramCalloutTargetId(callouts[0]!), "p01-1");
  assert.equal(geometry.diagramPointLabel(callouts, callouts[0]!), "1.1");
  assert.equal(geometry.diagramPointLabel(callouts, callouts[1]!), "1.2");
  assert.equal(geometry.diagramPointLabel(callouts, callouts[2]!), "2");
});

test("keeps an unspecified inline field compact without moving its anchor", () => {
  assert.equal(
    geometry.diagramInlineLabelWidth({
      partId: "p01",
      x: 0.3,
      y: 0.4,
      labelX: 0.55474,
      labelY: 0.114,
    }),
    0.28,
  );
  assert.ok(
    Math.abs(geometry.diagramInlineLabelWidth({
      partId: "p01",
      x: 0.3,
      y: 0.4,
      labelX: 0.55474,
      labelY: 0.114,
    }, 5 / 6) - 0.336) < Number.EPSILON,
  );
  assert.ok(
    Math.abs(geometry.diagramInlineLabelWidth({
      partId: "p02",
      x: 0.3,
      y: 0.4,
      labelX: 0.9,
      labelY: 0.2,
    }) - 0.1) < Number.EPSILON,
  );
  assert.equal(
    geometry.diagramInlineLabelWidth({
      partId: "p03",
      x: 0.3,
      y: 0.4,
      labelX: 0.6,
      labelY: 0.3,
      labelSide: "left",
      labelWidth: 0.24,
    }),
    0.24,
  );
  assert.equal(
    geometry.diagramInlineLabelAvailableWidth({
      partId: "p03",
      x: 0.3,
      y: 0.4,
      labelX: 0.36,
      labelY: 0.3,
      labelSide: "left",
    }),
    0.36,
  );
  assert.equal(
    geometry.diagramInlineLabelAvailableWidth({
      partId: "p03",
      x: 0.3,
      y: 0.4,
      labelX: 0.72,
      labelY: 0.3,
      labelSide: "right",
    }),
    0.28,
  );
  const bottomCallout: LessonAssessmentCallout = {
    partId: "p04",
    x: 0.5,
    y: 0.4,
    labelX: 0.5,
    labelY: 0.72,
    labelSide: "bottom",
    labelWidth: 0.2,
  };
  assert.equal(geometry.diagramInlineLabelWidth(bottomCallout), 0.2);
  assert.equal(geometry.diagramInlineLabelAvailableWidth(bottomCallout), 1);
  assert.equal(geometry.diagramInlineLabelLeft(bottomCallout, 0.2), 0.4);
  const topCallout: LessonAssessmentCallout = {
    ...bottomCallout,
    partId: "p05",
    labelSide: "top",
  };
  assert.equal(geometry.diagramInlineLabelWidth(topCallout), 0.2);
  assert.equal(geometry.diagramInlineLabelAvailableWidth(topCallout), 1);
  assert.equal(geometry.diagramInlineLabelLeft(topCallout, 0.2), 0.4);
  assert.equal(
    geometry.diagramInlineLabelAvailableWidth({
      partId: "p06",
      x: 0.3,
      y: 0.4,
    }),
    0,
  );
});

test("keeps the canonical diagram field width at both canvas edges", () => {
  assert.ok(
    Math.abs(geometry.diagramInlineLabelWidth({
      partId: "p01",
      x: 0.3,
      y: 0.4,
      labelX: 0.22,
      labelY: 0.5,
      labelSide: "left",
      labelWidth: 0.22,
    }) - 0.22) < Number.EPSILON,
  );
  assert.ok(
    Math.abs(geometry.diagramInlineLabelWidth({
      partId: "p02",
      x: 0.7,
      y: 0.4,
      labelX: 0.78,
      labelY: 0.5,
      labelSide: "right",
      labelWidth: 0.22,
    }) - 0.22) < Number.EPSILON,
  );
});
