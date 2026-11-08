import assert from "node:assert/strict";
import test from "node:test";

import type { LessonAssessmentQuestion, TextFieldsQuestion } from "../../lib/lessonAssessmentContract";

const modulePath = "./lessonAssessmentSentenceCompletionModel.ts";
const sentenceCompletion: typeof import("./lessonAssessmentSentenceCompletionModel") = await import(modulePath);

function textQuestion(id: string, prompt: string, partIds: ReadonlyArray<string>): TextFieldsQuestion {
  return {
    id,
    sectionId: "fill-blanks",
    gradingMode: "automatic",
    type: "text_fields",
    prompt,
    parts: partIds.map((partId, index) => ({ id: partId, prompt: `Пропуск ${index + 1}` })),
  };
}

test("maps sentence gaps to text parts from left to right", () => {
  const question = textQuestion(
    "fill-04",
    "Движение возможно в _____ и _____ суставах.",
    ["p1", "p2"],
  );

  assert.deepEqual(sentenceCompletion.sentenceCompletionSegments(question), [
    { kind: "text", value: "Движение возможно в " },
    { kind: "blank", part: { id: "p1", prompt: "Пропуск 1" } },
    { kind: "text", value: " и " },
    { kind: "blank", part: { id: "p2", prompt: "Пропуск 2" } },
    { kind: "text", value: " суставах." },
  ]);
});

test("rejects a sentence when the number of gaps and answer parts differs", () => {
  const question = textQuestion("fill-01", "Суставы относятся к _____ соединениям.", ["p1", "p2"]);

  assert.equal(sentenceCompletion.sentenceCompletionSegments(question), null);
});

test("collapses fill-blank questions into one navigation item without changing other questions", () => {
  const questions: ReadonlyArray<LessonAssessmentQuestion> = [
    {
      id: "fundamentals-11",
      sectionId: "fundamentals",
      gradingMode: "automatic",
      type: "single_choice",
      prompt: "Обычный вопрос",
      options: [{ id: "o1", label: "Ответ" }],
    },
    textQuestion("fill-01", "Первое _____ предложение.", ["p1"]),
    textQuestion("fill-02", "Второе _____ предложение.", ["p1"]),
    {
      id: "diagram-01",
      sectionId: "diagrams",
      gradingMode: "automatic",
      type: "diagram_text",
      prompt: "Схема",
      parts: [{ id: "p1", prompt: "Подпись" }],
      assetPath: "/diagram.png",
      width: 100,
      height: 100,
      callouts: [{ partId: "p1", x: 50, y: 50 }],
    },
  ];

  assert.deepEqual(sentenceCompletion.lessonAssessmentNavigationItems(questions), [
    {
      id: "fundamentals-11",
      prompt: "Обычный вопрос",
      questionIds: ["fundamentals-11"],
    },
    {
      id: "fill-01",
      prompt: "Дополните предложение",
      questionIds: ["fill-01", "fill-02"],
    },
    {
      id: "diagram-01",
      prompt: "Схема",
      questionIds: ["diagram-01"],
    },
  ]);
});
