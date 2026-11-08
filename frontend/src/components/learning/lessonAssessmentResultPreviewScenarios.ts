import type {
  LessonAssessmentScore,
  LessonAssessmentScoringPolicy,
  LessonAssessmentSection,
} from "@/lib/lessonAssessmentContract";

export const LESSON_ASSESSMENT_RESULT_PREVIEW_SECTIONS: ReadonlyArray<LessonAssessmentSection> = [
  { id: "fundamentals", title: "Ответьте на вопросы" },
  { id: "fill-blanks", title: "Дополните предложение" },
  { id: "diagrams", title: "Подпишите схемы" },
  { id: "expanded", title: "Развёрнутые вопросы" },
];

export const LESSON_ASSESSMENT_RESULT_PREVIEW_SCORING_POLICY: LessonAssessmentScoringPolicy = {
  schemaVersion: 2,
  contentVersion: 1,
  mode: "hybrid",
  automaticWeightBps: 7000,
  manualWeightBps: 3000,
  automaticGateOverallRequiredBps: 8000,
  automaticGateSectionRequiredBps: 6000,
  manualRequiredBps: 6000,
  finalRequiredBps: 8000,
  automaticQuestionCount: 0,
  manualQuestionCount: 0,
};

export type LessonAssessmentResultPreviewScenarioId =
  | "failed-all"
  | "failed-overall"
  | "failed-section"
  | "passed-baseline"
  | "passed-high"
  | "mastered";

export type LessonAssessmentResultPreviewScenario = {
  readonly description: string;
  readonly id: LessonAssessmentResultPreviewScenarioId;
  readonly label: string;
  readonly score: LessonAssessmentScore;
};

const DEFAULT_SCENARIO_ID: LessonAssessmentResultPreviewScenarioId = "failed-all";

function score({
  overallPercentBps,
  automaticBps = overallPercentBps,
  manualBps = overallPercentBps,
  overallPassed = overallPercentBps >= 8000,
  passed = overallPassed,
  mastered = overallPercentBps === 10000,
}: {
  readonly overallPercentBps: number;
  readonly automaticBps?: number;
  readonly manualBps?: number;
  readonly overallPassed?: boolean;
  readonly passed?: boolean;
  readonly mastered?: boolean;
}): LessonAssessmentScore {

  return {
    checked: true,
    passed,
    mastered,
    overall: {
      numerator: overallPercentBps,
      denominator: 10000,
      percentBps: overallPercentBps,
      requiredBps: 8000,
      passed: overallPassed,
    },
    components: { automaticBps, manualBps, automaticWeightBps: 7000, manualWeightBps: 3000 },
  };
}

export const LESSON_ASSESSMENT_RESULT_PREVIEW_SCENARIOS: ReadonlyArray<LessonAssessmentResultPreviewScenario> = [
  {
    id: "failed-all",
    label: "Не пройден: всё ниже порога",
    description: "Общий результат и каждый раздел ниже требуемого порога.",
    score: score({ overallPercentBps: 208 }),
  },
  {
    id: "failed-overall",
    label: "Не пройден: только общий результат",
    description: "Все разделы пройдены, но общий результат ниже 80 %.",
    score: score({ overallPercentBps: 7999 }),
  },
  {
    id: "failed-section",
    label: "Не пройден: один раздел",
    description: "Общий результат достиг порога, но один раздел набрал меньше 60 %.",
    score: score({
      overallPercentBps: 8500,
      manualBps: 5900,
      passed: false,
    }),
  },
  {
    id: "passed-baseline",
    label: "Пройден: ровно 80 %",
    description: "Минимальный успешный результат без полного освоения.",
    score: score({ overallPercentBps: 8000 }),
  },
  {
    id: "passed-high",
    label: "Пройден: 99 %",
    description: "Высокий результат, который ещё не является полным освоением.",
    score: score({ overallPercentBps: 9999 }),
  },
  {
    id: "mastered",
    label: "Полное освоение: 100 %",
    description: "Все оцениваемые части всех вопросов выполнены правильно.",
    score: score({ overallPercentBps: 10000 }),
  },
];

export function lessonAssessmentResultPreviewScenario(
  scenarioId: string | undefined,
): LessonAssessmentResultPreviewScenario {
  const scenario = LESSON_ASSESSMENT_RESULT_PREVIEW_SCENARIOS.find((item) => item.id === scenarioId)
    ?? LESSON_ASSESSMENT_RESULT_PREVIEW_SCENARIOS.find((item) => item.id === DEFAULT_SCENARIO_ID);

  if (scenario === undefined) {
    throw new Error("Lesson assessment result preview scenarios are not configured.");
  }

  return scenario;
}
