import assert from "node:assert/strict";
import test from "node:test";

import type { LessonAssessmentQuestion } from "@/lib/lessonAssessmentContract";

const modulePath = "./lessonAssessmentSentenceCompletionModel.ts";
const sentenceCompletionModule: typeof import("./lessonAssessmentSentenceCompletionModel") =
  await import(modulePath);

function navigationQuestions(): ReadonlyArray<LessonAssessmentQuestion> {
  return [
    {
      id: "sentence-01",
      sectionId: sentenceCompletionModule.SENTENCE_COMPLETION_SECTION_ID,
      gradingMode: "automatic",
      type: "text_fields",
      prompt: "Связки ______ суставы ______.",
      parts: [
        { id: "part-01", prompt: "Первый пропуск" },
        { id: "part-02", prompt: "Второй пропуск" },
      ],
    },
    {
      id: "sentence-02",
      sectionId: sentenceCompletionModule.SENTENCE_COMPLETION_SECTION_ID,
      gradingMode: "automatic",
      type: "text_fields",
      prompt: "Капсула ______ движение.",
      parts: [{ id: "part-03", prompt: "Третий пропуск" }],
    },
    {
      id: "single-01",
      sectionId: "questions",
      gradingMode: "automatic",
      type: "single_choice",
      prompt: "Один ответ",
      options: [
        { id: "o1", label: "Первый" },
        { id: "o2", label: "Второй" },
      ],
    },
  ];
}

test("sentence-completion navigation groups all fill-blanks question ids into one item", () => {
  const items = sentenceCompletionModule.lessonAssessmentNavigationItems(navigationQuestions());

  assert.deepEqual(items, [
    {
      id: "sentence-01",
      prompt: sentenceCompletionModule.SENTENCE_COMPLETION_TITLE,
      questionIds: ["sentence-01", "sentence-02"],
    },
    {
      id: "single-01",
      prompt: "Один ответ",
      questionIds: ["single-01"],
    },
  ]);
});

test("returns all question ids for the selected navigation item", () => {
  const questions = navigationQuestions();

  assert.deepEqual(
    sentenceCompletionModule.questionIdsForNavigationItem(questions, "sentence-02"),
    ["sentence-01", "sentence-02"],
  );
  assert.deepEqual(
    sentenceCompletionModule.questionIdsForNavigationItem(questions, "single-01"),
    ["single-01"],
  );
  assert.deepEqual(sentenceCompletionModule.questionIdsForNavigationItem(questions, "missing"), []);
});

test("returns only sentence-completion questions from the selected navigation item", () => {
  const automaticQuestions = navigationQuestions();
  const manualFill: LessonAssessmentQuestion = {
    id: "sentence-manual",
    sectionId: sentenceCompletionModule.SENTENCE_COMPLETION_SECTION_ID,
    gradingMode: "manual",
    type: "text_fields",
    prompt: "Ручное предложение ______.",
    parts: [{ id: "manual-part", prompt: "Пропуск" }],
  };
  const questions = [...automaticQuestions, manualFill];

  assert.deepEqual(
    sentenceCompletionModule
      .sentenceCompletionQuestionsForNavigationItem(questions, "sentence-02")
      .map((question) => question.id),
    ["sentence-01", "sentence-02"],
  );
  assert.deepEqual(
    sentenceCompletionModule
      .sentenceCompletionQuestionsForNavigationItem(questions, "sentence-manual")
      .map((question) => question.id),
    ["sentence-manual"],
  );
});

test("groups fill-blanks questions by grading mode and keeps group order", () => {
  const automaticFill: LessonAssessmentQuestion = {
    id: "fill-auto",
    sectionId: sentenceCompletionModule.SENTENCE_COMPLETION_SECTION_ID,
    gradingMode: "automatic",
    type: "text_fields",
    prompt: "Первая группа",
    parts: [{ id: "part-auto", prompt: "Часть" }],
  };
  const manualFill: LessonAssessmentQuestion = {
    id: "fill-manual",
    sectionId: sentenceCompletionModule.SENTENCE_COMPLETION_SECTION_ID,
    gradingMode: "manual",
    type: "text_fields",
    prompt: "Вторая группа",
    parts: [{ id: "part-manual", prompt: "Часть" }],
  };
  const manualLongText: LessonAssessmentQuestion = {
    id: "manual-long",
    sectionId: "manual",
    gradingMode: "manual",
    type: "long_text",
    prompt: "Развёрнутый ответ",
    rows: 4,
  };

  assert.deepEqual(
    sentenceCompletionModule.lessonAssessmentNavigationItems([
      automaticFill,
      manualFill,
      manualLongText,
    ]).map((item) => item.questionIds),
    [["fill-auto"], ["fill-manual"], ["manual-long"]],
  );

  const navigationItems = sentenceCompletionModule.lessonAssessmentNavigationItems([
    automaticFill,
    manualFill,
    manualLongText,
  ]);

  const hasMixedModeItem = navigationItems.some(
    (item) => item.questionIds.includes("fill-auto") && item.questionIds.includes("fill-manual"),
  );

  assert.equal(hasMixedModeItem, false);
});
