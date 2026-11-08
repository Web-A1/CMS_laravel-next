import assert from "node:assert/strict";
import test from "node:test";

import type { LessonAssessmentTextPart } from "../../lib/lessonAssessmentContract";

const modulePath = "./lessonAssessmentTextFieldGroups.ts";
const grouping: typeof import("./lessonAssessmentTextFieldGroups") = await import(modulePath);

function classificationParts(): ReadonlyArray<LessonAssessmentTextPart> {
  return [
    { id: "p01", prompt: "По сложности строения, 1" },
    { id: "p02", prompt: "По сложности строения, 2" },
    { id: "p03", prompt: "По сложности строения, 3" },
    { id: "p04", prompt: "По сложности строения, 4" },
    { id: "p05", prompt: "По количеству осей вращения, 1" },
    { id: "p06", prompt: "По количеству осей вращения, 2" },
    { id: "p07", prompt: "По количеству осей вращения, 3" },
    { id: "p08", prompt: "По форме суставных поверхностей, 1" },
    { id: "p09", prompt: "По форме суставных поверхностей, 2" },
  ];
}

test("groups classification blanks by their literal PDF headings", () => {
  const groups = grouping.groupLessonAssessmentTextParts(classificationParts());

  assert.deepEqual(
    groups?.map((group) => ({
      label: group.label,
      partIds: group.parts.map((part) => part.id),
    })),
    [
      {
        label: "По сложности строения",
        partIds: ["p01", "p02", "p03", "p04"],
      },
      {
        label: "По количеству осей вращения",
        partIds: ["p05", "p06", "p07"],
      },
      {
        label: "По форме суставных поверхностей",
        partIds: ["p08", "p09"],
      },
    ],
  );
});

test("keeps ordinary numbered blanks in their existing flat presentation", () => {
  assert.equal(
    grouping.groupLessonAssessmentTextParts([
      { id: "p01", prompt: "Строка 1" },
      { id: "p02", prompt: "Строка 2" },
    ]),
    null,
  );
});

test("fails closed when a group does not start at one or skips a position", () => {
  assert.equal(
    grouping.groupLessonAssessmentTextParts([
      { id: "p01", prompt: "По сложности строения, 1" },
      { id: "p02", prompt: "По сложности строения, 3" },
      { id: "p03", prompt: "По форме суставных поверхностей, 1" },
    ]),
    null,
  );
});
