import type { LessonAssessmentTextPart } from "../../lib/lessonAssessmentContract";

export type LessonAssessmentTextPartGroup = {
  readonly label: string;
  readonly parts: ReadonlyArray<LessonAssessmentTextPart>;
};

const GROUPED_PART_PROMPT_PATTERN = /^(.+),\s*([1-9]\d*)$/u;

export function groupLessonAssessmentTextParts(
  parts: ReadonlyArray<LessonAssessmentTextPart>,
): ReadonlyArray<LessonAssessmentTextPartGroup> | null {
  const groups: Array<{ label: string; parts: Array<LessonAssessmentTextPart> }> = [];

  for (const part of parts) {
    const match = GROUPED_PART_PROMPT_PATTERN.exec(part.prompt);
    const label = match?.[1]?.trim();
    const position = Number(match?.[2]);

    if (label === undefined || label === "" || !Number.isSafeInteger(position)) {
      return null;
    }

    const currentGroup = groups.at(-1);
    if (currentGroup?.label === label) {
      if (position !== currentGroup.parts.length + 1) {
        return null;
      }
      currentGroup.parts.push(part);
      continue;
    }

    if (position !== 1) {
      return null;
    }
    groups.push({ label, parts: [part] });
  }

  return groups.length > 1 ? groups : null;
}
