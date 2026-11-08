import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

import type { LessonAssessmentGradingMode } from "./lessonAssessmentResultModel";

const modulePath = "./lessonAssessmentResultModel.ts";
const lessonAssessmentResultModel: typeof import("./lessonAssessmentResultModel") = await import(modulePath);

const {
  lessonAssessmentQuestionRange,
  lessonAssessmentVisibleQuestionNumberById,
  lessonAssessmentWeightedPoints,
} = lessonAssessmentResultModel;
const previewUrl = new URL("./LessonAssessmentResultPreview.tsx", import.meta.url);
const resultUrl = new URL("./LessonAssessmentResult.tsx", import.meta.url);
const scenariosUrl = new URL("./lessonAssessmentResultPreviewScenarios.ts", import.meta.url);

function questions(...gradingModes: ReadonlyArray<LessonAssessmentGradingMode>) {
  return gradingModes.map((gradingMode, index) => ({
    id: `question-${index + 1}`,
    sectionId: `section-${index + 1}`,
    gradingMode,
    prompt: `Задание ${index + 1}`,
    type: "single_choice" as const,
  }));
}

function navigationItems(examQuestions: ReturnType<typeof questions>) {
  return examQuestions.map((question) => ({
    questionIds: [question.id],
  }));
}

test("builds ranges from the actual question order", () => {
  const examQuestions = questions("automatic", "automatic", "manual", "manual", "manual");

  const items = navigationItems(examQuestions);

  assert.deepEqual(lessonAssessmentQuestionRange(examQuestions, items, "automatic"), {
    label: "Задания 1–2",
    questionCount: 2,
  });
  assert.deepEqual(lessonAssessmentQuestionRange(examQuestions, items, "manual"), {
    label: "Задания 3–5",
    questionCount: 3,
  });
});

test("uses the singular form for one matching question", () => {
  const examQuestions = questions("automatic", "manual", "automatic");
  const items = navigationItems(examQuestions);

  assert.deepEqual(lessonAssessmentQuestionRange(examQuestions, items, "manual"), {
    label: "Задание 2",
    questionCount: 1,
  });
});

test("keeps non-consecutive question groups accurate", () => {
  const examQuestions = questions("automatic", "manual", "automatic", "automatic", "manual");
  const items = navigationItems(examQuestions);

  assert.deepEqual(lessonAssessmentQuestionRange(examQuestions, items, "automatic"), {
    label: "Задания 1, 3–4",
    questionCount: 3,
  });
  assert.deepEqual(lessonAssessmentQuestionRange(examQuestions, items, "manual"), {
    label: "Задания 2, 5",
    questionCount: 2,
  });
});

test("maps raw question ids to the visible navigation number", () => {
  const items = [
    { questionIds: ["automatic-01"] },
    { questionIds: ["fill-01", "fill-02", "fill-03"] },
    { questionIds: ["manual-01"] },
    { questionIds: ["manual-02"] },
  ];

  const numbersById = lessonAssessmentVisibleQuestionNumberById(items);

  assert.equal(numbersById.get("fill-03"), 2);
  assert.equal(numbersById.get("manual-01"), 3);
  assert.equal(numbersById.get("manual-02"), 4);
  assert.equal(numbersById.get("missing"), undefined);
});

test("preserves absolute visible numbering for later manual results", () => {
  const items = [
    ...Array.from({ length: 21 }, (_, index) => ({
      questionIds: [`automatic-${String(index + 1).padStart(2, "0")}`],
    })),
    ...Array.from({ length: 7 }, (_, index) => ({
      questionIds: [`manual-${String(index + 1).padStart(2, "0")}`],
    })),
  ];

  const numbersById = lessonAssessmentVisibleQuestionNumberById(items);

  assert.equal(numbersById.get("manual-01"), 22);
  assert.equal(numbersById.get("manual-07"), 28);
});

test("counts grouped sentence-completion questions as one visible assignment", () => {
  const examQuestions = [
    ...questions("automatic").map((question) => ({ ...question, id: "automatic-01" })),
    {
      id: "fill-01",
      sectionId: "fill-blanks",
      gradingMode: "automatic" as const,
      prompt: "Первое предложение",
      type: "text_fields" as const,
    },
    {
      id: "fill-02",
      sectionId: "fill-blanks",
      gradingMode: "automatic" as const,
      prompt: "Второе предложение",
      type: "text_fields" as const,
    },
    {
      id: "manual-01",
      sectionId: "manual",
      gradingMode: "manual" as const,
      prompt: "Развёрнутый ответ",
      type: "long_text" as const,
    },
  ];
  const items = [
    { questionIds: ["automatic-01"] },
    { questionIds: ["fill-01", "fill-02"] },
    { questionIds: ["manual-01"] },
  ];

  assert.deepEqual(lessonAssessmentQuestionRange(examQuestions, items, "automatic"), {
    label: "Задания 1–2",
    questionCount: 2,
  });
  assert.deepEqual(lessonAssessmentQuestionRange(examQuestions, items, "manual"), {
    label: "Задание 3",
    questionCount: 1,
  });
});

test("converts a component score into its conservative whole-point contribution", () => {
  assert.deepEqual(lessonAssessmentWeightedPoints(10_000, 7_000), {
    earned: 70,
    available: 70,
  });
  assert.deepEqual(lessonAssessmentWeightedPoints(9_900, 7_000), {
    earned: 69,
    available: 70,
  });
  assert.deepEqual(lessonAssessmentWeightedPoints(9_952, 7_000), {
    earned: 69,
    available: 70,
  });
  assert.deepEqual(lessonAssessmentWeightedPoints(8_000, 7_000), {
    earned: 56,
    available: 70,
  });
});

test("result threshold copy derives values from scoringPolicy variables without hard-coded domain constants", async () => {
  const [previewSource, resultSource, scenariosSource] = await Promise.all([
    readFile(previewUrl, "utf8"),
    readFile(resultUrl, "utf8"),
    readFile(scenariosUrl, "utf8"),
  ]);

  assert.match(resultSource, /const finalRequired = scorePercentValue\(assessment\.scoringPolicy\.finalRequiredBps\)/);
  assert.match(
    resultSource,
    /const automaticRequired = scorePercentValue\(\s*assessment\.scoringPolicy\.automaticGateOverallRequiredBps,\s*\)/,
  );
  assert.match(
    resultSource,
    /const automaticSectionRequired = scorePercentValue\(\s*assessment\.scoringPolicy\.automaticGateSectionRequiredBps,\s*\)/,
  );
  assert.match(resultSource, /const manualRequired = scorePercentValue\(assessment\.scoringPolicy\.manualRequiredBps\)/);
  assert.match(
    resultSource,
    /Чтобы сдать экзамен, нужно набрать не менее \$\{finalRequired\} баллов\. Для автоматической части требуется не менее \$\{automaticRequired\}% в целом и \$\{automaticSectionRequired\}% в каждом автоматическом разделе; для ручной части — не менее \$\{manualRequired\}%\./,
  );
  assert.match(
    resultSource,
    /Ручная часть не достигла \$\{manualRequired\}\. Для пересдачи нужно пройти экзамен целиком\./,
  );
  assert.match(
    resultSource,
    /Итоговый результат не достиг \$\{finalRequiredPercent\}\. Для пересдачи нужно пройти экзамен целиком\./,
  );
  assert.match(
    resultSource,
    /Автоматическая часть уже пройдена: не менее \$\{automaticRequired\} в ней и \$\{automaticSectionRequired\} в каждом её разделе\. Для итогового прохождения нужны минимум \$\{manualRequired\} ручной части и \$\{finalRequiredPercent\} общего результата\./,
  );
  assert.match(previewSource, /scoringPolicy: LESSON_ASSESSMENT_RESULT_PREVIEW_SCORING_POLICY/);
  assert.match(scenariosSource, /export const LESSON_ASSESSMENT_RESULT_PREVIEW_SCORING_POLICY/);
  assert.match(scenariosSource, /finalRequiredBps: 8000/);
  assert.match(scenariosSource, /automaticGateOverallRequiredBps: 8000/);
  assert.match(scenariosSource, /automaticGateSectionRequiredBps: 6000/);
  assert.match(scenariosSource, /manualRequiredBps: 6000/);
  assert.doesNotMatch(resultSource, /Ручная часть не достигла 60%|Итоговый результат не достиг 80%/);
  assert.doesNotMatch(
    resultSource,
    /Чтобы сдать экзамен, нужно набрать не менее 80 баллов\. Для автоматической части требуется не менее 80% в целом и 60% в каждом автоматическом разделе; для ручной части — не менее 60%\./,
  );
  assert.doesNotMatch(resultSource, /DEV_PREVIEW_SCORING_POLICY_FALLBACK/);
  assert.doesNotMatch(resultSource, /assessment\.scoringPolicy\s*\?\?/);
  assert.doesNotMatch(previewSource, /finalRequiredBps:\s*8000|automaticGateOverallRequiredBps:\s*8000|automaticGateSectionRequiredBps:\s*6000|manualRequiredBps:\s*6000/);
});
