export type LessonAssessmentGradingMode = "automatic" | "manual";

type QuestionWithGradingMode = {
  readonly id: string;
  readonly gradingMode: LessonAssessmentGradingMode;
};

type QuestionRangeNavigationItem = {
  readonly questionIds: ReadonlyArray<string>;
};

export function lessonAssessmentVisibleQuestionNumberById(
  navigationItems: ReadonlyArray<QuestionRangeNavigationItem>,
): ReadonlyMap<string, number> {
  return new Map(
    navigationItems.flatMap((item, index) => item.questionIds.map((questionId) => [questionId, index + 1] as const)),
  );
}

function formatQuestionNumberRange(start: number, end: number): string {
  return start === end ? `${start}` : `${start}–${end}`;
}

export type LessonAssessmentQuestionRange = {
  readonly label: string;
  readonly questionCount: number;
};

export type LessonAssessmentWeightedPoints = {
  readonly earned: number;
  readonly available: number;
};

export function lessonAssessmentWeightedPoints(
  scoreBps: number,
  weightBps: number,
): LessonAssessmentWeightedPoints {
  return {
    earned: Math.floor((scoreBps * weightBps) / 1_000_000),
    available: Math.floor(weightBps / 100),
  };
}

export function lessonAssessmentQuestionRange(
  questions: ReadonlyArray<QuestionWithGradingMode>,
  navigationItems: ReadonlyArray<QuestionRangeNavigationItem>,
  gradingMode: LessonAssessmentGradingMode,
): LessonAssessmentQuestionRange {
  const gradingModeByQuestionId = new Map(
    questions.map((question) => [question.id, question.gradingMode]),
  );
  const ranges: Array<string> = [];
  let rangeStart: number | null = null;
  let rangeEnd: number | null = null;
  let questionCount = 0;

  for (const [index, item] of navigationItems.entries()) {
    const itemMatchesGradingMode = item.questionIds.every(
      (questionId) => gradingModeByQuestionId.get(questionId) === gradingMode,
    );
    if (!itemMatchesGradingMode) {
      continue;
    }

    const questionNumber = index + 1;
    questionCount += 1;

    if (rangeStart === null || rangeEnd === null) {
      rangeStart = questionNumber;
      rangeEnd = questionNumber;
      continue;
    }

    if (questionNumber === rangeEnd + 1) {
      rangeEnd = questionNumber;
      continue;
    }

    ranges.push(formatQuestionNumberRange(rangeStart, rangeEnd));
    rangeStart = questionNumber;
    rangeEnd = questionNumber;
  }

  if (rangeStart === null || rangeEnd === null) {
    return { label: "Задания", questionCount: 0 };
  }

  ranges.push(formatQuestionNumberRange(rangeStart, rangeEnd));

  const noun = questionCount === 1 ? "Задание" : "Задания";
  return { label: `${noun} ${ranges.join(", ")}`, questionCount };
}
