import assert from "node:assert/strict";
import test from "node:test";

import type {
  LessonAssessmentAutomaticGate,
  LessonAssessmentAttempt,
  LessonAssessmentData,
  LessonAssessmentQuestion,
  LessonAssessmentResponse,
} from "../../lib/lessonAssessmentContract";

const modulePath = "./lessonAssessmentState.ts";
const stateMachine: typeof import("./lessonAssessmentState") = await import(modulePath);

function assessmentData(): LessonAssessmentData {
  return {
    assessment: {
      id: 7,
      contentVersion: 1,
      scoringPolicy: { schemaVersion: 2, contentVersion: 1, mode: "hybrid", automaticWeightBps: 7000, manualWeightBps: 3000, automaticGateOverallRequiredBps: 8000, automaticGateSectionRequiredBps: 6000, manualRequiredBps: 6000, finalRequiredBps: 8000, automaticQuestionCount: 4, manualQuestionCount: 1 },
      title: "Соединения костей",
      totalCount: 5,
      retakeAvailable: true,
      sections: [
        { id: "questions", title: "Вопросы" },
        { id: "diagrams", title: "Схемы" },
      ],
      questions: [
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
        {
          id: "multiple-01",
          sectionId: "questions",
          gradingMode: "automatic",
          type: "multiple_choice",
          prompt: "Несколько ответов",
          options: [
            { id: "o1", label: "Первый" },
            { id: "o2", label: "Второй" },
          ],
          orderMatters: false,
        },
        {
          id: "slot-01",
          sectionId: "questions",
          gradingMode: "automatic",
          type: "slot_choice",
          prompt: "Заполните части",
          options: [
            { id: "o1", label: "Первый" },
            { id: "o2", label: "Второй" },
          ],
          parts: [
            { id: "p1", prompt: "Часть 1", optionIds: ["o1", "o2"] },
            { id: "p2", prompt: "Часть 2", optionIds: ["o1", "o2"] },
          ],
        },
        {
          id: "ordered-01",
          sectionId: "questions",
          gradingMode: "automatic",
          type: "ordered_choice",
          prompt: "Расположите по порядку",
          options: [
            { id: "o1", label: "Первый" },
            { id: "o2", label: "Второй" },
          ],
        },
        {
          id: "diagram-01",
          sectionId: "diagrams",
          gradingMode: "manual",
          type: "diagram_labels",
          prompt: "Подпишите схему",
          options: [
            { id: "o1", label: "Название" },
            { id: "o2", label: "Часть" },
          ],
          parts: [
            { id: "name", prompt: "Название", optionIds: ["o1"] },
            { id: "p1", prompt: "Метка 1", optionIds: ["o2"] },
          ],
          assetPath: "/api/v1/learning/subjects/anatomiya/courses/artrologiya/lessons/lesson-01/assessment/assets/diagram-01",
          width: 640,
          height: 480,
          callouts: [{ partId: "p1", x: 0.5, y: 0.4 }],
        },
      ],
    },
    attempt: {
      id: 19,
      status: "in_progress",
      revision: 3,
      submissionCount: 0,
      answeredCount: 0,
      correctCount: 0,
      lastQuestionId: "single-01",
      submittedAt: null,
      finalizedAt: null,
      passedAt: null,
      failureReason: null,
      pendingManualQuestionCount: 0,
      reviewedManualQuestionCount: 0,
      reviewedManualQuestionIds: [],
      responses: [],
      lockedQuestionIds: [],
    },
  };
}

function submittedResponses(): ReadonlyArray<{
  readonly questionId: string;
  readonly response: LessonAssessmentResponse;
}> {
  return [
    { questionId: "single-01", response: { selectedOptionId: "o1" } },
    { questionId: "multiple-01", response: { selectedOptionIds: ["o1"] } },
    { questionId: "slot-01", response: { selections: { p1: "o1", p2: "o2" } } },
    { questionId: "ordered-01", response: { selectedOptionIds: ["o1", "o2"] } },
    { questionId: "diagram-01", response: { selections: { name: "o1", p1: "o2" } } },
  ];
}

function automaticGate(passed: boolean): LessonAssessmentAutomaticGate {
  const correctCount = passed ? 4 : 3;
  const percentBps = passed ? 10000 : 7500;

  return {
    passed,
    overall: {
      numerator: correctCount,
      denominator: 4,
      percentBps,
      requiredBps: 8000,
      passed,
    },
    sections: [{
      sectionId: "questions",
      numerator: correctCount,
      denominator: 4,
      percentBps,
      requiredBps: 6000,
      passed: true,
    }],
    correctCount,
    questionResults: ["single-01", "multiple-01", "slot-01", "ordered-01"].map(
      (questionId, index) => ({
        questionId,
        correct: passed || index < 3,
        earnedParts: passed || index < 3 ? 1 : 0,
        maxParts: 1,
        incorrectPartIds: [],
      }),
    ),
  };
}

function submittedAttempt(status: "failed" | "pending_review"): LessonAssessmentAttempt {
  const passedAutomaticGate = status === "pending_review";

  return {
    id: 19,
    status,
    revision: 4,
    submissionCount: 1,
    answeredCount: 5,
    correctCount: passedAutomaticGate ? 4 : 3,
    lastQuestionId: "single-01",
    submittedAt: "2026-08-19T12:00:00.000Z",
    finalizedAt: null,
    passedAt: null,
    failureReason: passedAutomaticGate ? null : "automatic_threshold_not_met",
    pendingManualQuestionCount: passedAutomaticGate ? 1 : 0,
    reviewedManualQuestionCount: 0,
    reviewedManualQuestionIds: [],
    ...(passedAutomaticGate ? { manualProgressBps: 0 } : {}),
    automaticGate: automaticGate(passedAutomaticGate),
    responses: submittedResponses(),
    lockedQuestionIds: ["single-01", "multiple-01", "slot-01", "ordered-01", "diagram-01"],
  } as LessonAssessmentAttempt;
}

function finalizedPassedAttempt(): LessonAssessmentAttempt {
  const pending = submittedAttempt("pending_review");
  if (pending.status !== "pending_review") {
    throw new Error("Expected a pending-review attempt fixture");
  }

  return {
    ...pending,
    status: "passed",
    revision: 5,
    finalizedAt: "2026-08-19T13:00:00.000Z",
    passedAt: "2026-08-19T13:00:00.000Z",
    pendingManualQuestionCount: 0,
    reviewedManualQuestionCount: 1,
    reviewedManualQuestionIds: ["diagram-01"],
    score: {
      checked: true,
      passed: true,
      mastered: true,
      overall: { numerator: 10000, denominator: 10000, percentBps: 10000, requiredBps: 8000, passed: true },
      components: { automaticBps: 10000, manualBps: 10000, automaticWeightBps: 7000, manualWeightBps: 3000 },
    },
    manualResults: [{ questionId: "diagram-01", scoreBps: 10000, comment: null }],
  };
}

test("setAnswer updates responses immutably and marks the question dirty", () => {
  const initial = stateMachine.createLessonAssessmentState(assessmentData());
  const response = { selectedOptionId: "o1" } as const;
  const next = stateMachine.setAnswer(initial, "single-01", response);

  assert.notStrictEqual(next, initial);
  assert.deepEqual(initial.responses, {});
  assert.deepEqual(next.responses["single-01"], response);
  assert.deepEqual(next.dirtyQuestionIds, ["single-01"]);
  assert.equal(next.currentQuestionId, "single-01");
  assert.equal(next.editVersion, 1);
});

test("typed fields and long text are complete only when every literal answer is non-empty", () => {
  const textQuestion: LessonAssessmentQuestion = {
    id: "fill-01",
    sectionId: "questions",
    gradingMode: "automatic",
    type: "text_fields",
    prompt: "Заполните строки",
    parts: [
      { id: "p01", prompt: "Строка 1" },
      { id: "p02", prompt: "Строка 2" },
    ],
  };
  const longQuestion: LessonAssessmentQuestion = {
    id: "expanded-01",
    sectionId: "questions",
    gradingMode: "automatic",
    type: "long_text",
    prompt: "Ответьте развёрнуто",
    rows: 5,
  };

  assert.equal(
    stateMachine.isLessonAssessmentResponseComplete(textQuestion, { texts: { p01: "Ответ", p02: " " } }),
    false,
  );
  assert.equal(
    stateMachine.isLessonAssessmentResponseComplete(textQuestion, { texts: { p01: "Ответ", p02: "Термин" } }),
    true,
  );
  assert.equal(stateMachine.isLessonAssessmentResponseComplete(longQuestion, { text: "  " }), false);
  assert.equal(stateMachine.isLessonAssessmentResponseComplete(longQuestion, { text: "Полный ответ" }), true);
});

test("confirmation advances to the next unanswered visible question", () => {
  const initial = stateMachine.createLessonAssessmentState(assessmentData());
  const answeredCurrent = stateMachine.setAnswer(initial, "single-01", { selectedOptionId: "o1" });

  assert.equal(stateMachine.nextIncompleteVisibleQuestionId(answeredCurrent), "multiple-01");

  const allAnswered = [
    ["multiple-01", { selectedOptionIds: ["o1"] }],
    ["slot-01", { selections: { p1: "o1", p2: "o2" } }],
    ["ordered-01", { selectedOptionIds: ["o1", "o2"] }],
    ["diagram-01", { selections: { name: "o1", p1: "o2" } }],
  ].reduce(
    (state, [questionId, response]) =>
      stateMachine.setAnswer(state, questionId as string, response as LessonAssessmentResponse),
    answeredCurrent,
  );

  assert.equal(stateMachine.nextIncompleteVisibleQuestionId(allAnswered), null);
});

test("applySavedAttempt clears submitted dirty answers but preserves a newer local edit", () => {
  const initial = stateMachine.createLessonAssessmentState(assessmentData());
  const submittedResponse = { selectedOptionId: "o1" } as const;
  const submitted = stateMachine.setAnswer(initial, "single-01", submittedResponse);
  const newer = stateMachine.setAnswer(submitted, "single-01", { selectedOptionId: "o2" });
  const savedAttempt = {
    ...assessmentData().attempt!,
    revision: 4,
    answeredCount: 1,
    responses: [{ questionId: "single-01", response: submittedResponse }],
  };

  const saved = stateMachine.applySavedAttempt(
    submitted,
    { assessment: assessmentData().assessment, attempt: savedAttempt },
    stateMachine.createLessonAssessmentSaveSnapshot(submitted, ["single-01"]),
  );
  const preserved = stateMachine.applySavedAttempt(
    newer,
    { assessment: assessmentData().assessment, attempt: savedAttempt },
    stateMachine.createLessonAssessmentSaveSnapshot(submitted, ["single-01"]),
  );

  assert.deepEqual(saved.dirtyQuestionIds, []);
  assert.equal(saved.saveStatus, "saved");
  assert.deepEqual(preserved.responses["single-01"], { selectedOptionId: "o2" });
  assert.deepEqual(preserved.dirtyQuestionIds, ["single-01"]);
});

test("save errors preserve the local response for retry", () => {
  const dirty = stateMachine.setAnswer(
    stateMachine.createLessonAssessmentState(assessmentData()),
    "single-01",
    { selectedOptionId: "o1" },
  );
  const failed = stateMachine.applySaveError(dirty);

  assert.equal(failed.saveStatus, "error");
  assert.deepEqual(failed.responses, dirty.responses);
  assert.deepEqual(failed.dirtyQuestionIds, ["single-01"]);
  assert.deepEqual(failed.questionEditVersions, dirty.questionEditVersions);
  assert.equal(failed.currentQuestionId, "single-01");
});

test("conflict reload replaces local state and announces the server refresh", () => {
  const dirty = stateMachine.setAnswer(
    stateMachine.createLessonAssessmentState(assessmentData()),
    "single-01",
    { selectedOptionId: "o1" },
  );
  const serverData = assessmentData();
  const refreshed = stateMachine.applyConflictReload(dirty, {
    ...serverData,
    attempt: {
      ...serverData.attempt!,
      revision: 8,
      lastQuestionId: "multiple-01",
      responses: [{ questionId: "multiple-01", response: { selectedOptionIds: ["o2"] } }],
    },
  }, stateMachine.createLessonAssessmentSaveSnapshot(dirty, ["single-01"]));

  assert.equal(refreshed.attempt?.revision, 8);
  assert.deepEqual(refreshed.responses, { "multiple-01": { selectedOptionIds: ["o2"] } });
  assert.deepEqual(refreshed.dirtyQuestionIds, []);
  assert.equal(refreshed.notice, "Состояние обновлено из другой вкладки");
  assert.equal(refreshed.currentQuestionId, "multiple-01");
});

test("automatic failure makes every submitted answer available for read-only review", () => {
  const data = assessmentData();

  const checked = stateMachine.applySubmittedAttempt(
    stateMachine.createLessonAssessmentState(data),
    { assessment: data.assessment, attempt: submittedAttempt("failed") },
  );

  assert.deepEqual(stateMachine.visibleQuestionIds(checked), [
    "single-01",
    "multiple-01",
    "slot-01",
    "ordered-01",
    "diagram-01",
  ]);
  assert.equal(checked.currentQuestionId, "single-01");
  assert.strictEqual(
    stateMachine.setAnswer(checked, "multiple-01", { selectedOptionIds: ["o2"] }),
    checked,
  );
  assert.equal(stateMachine.questionHasAssessmentError(checked, "ordered-01"), true);
  assert.equal(stateMachine.assessmentProgressLabel(checked), "Отвечено 5 из 5");
});

test("restores the last question only while it remains visible", () => {
  const data = assessmentData();
  const restored = stateMachine.createLessonAssessmentState({
    ...data,
    attempt: { ...data.attempt!, lastQuestionId: "ordered-01" },
  });
  assert.equal(restored.currentQuestionId, "ordered-01");

  const afterSubmission = stateMachine.createLessonAssessmentState({
    ...data,
    attempt: submittedAttempt("pending_review"),
  });
  assert.equal(afterSubmission.currentQuestionId, "single-01");
});

test("uses a visible requested question as the initial current question", () => {
  const data = assessmentData();

  const requested = stateMachine.createLessonAssessmentState(data, "diagram-01");
  assert.equal(requested.currentQuestionId, "diagram-01");

  const missing = stateMachine.createLessonAssessmentState(data, "missing-question");
  assert.equal(missing.currentQuestionId, "single-01");
});

test("locked and passed attempts keep controls immutable", () => {
  const data = assessmentData();
  const lockedState = stateMachine.createLessonAssessmentState({ ...data, attempt: submittedAttempt("pending_review") });
  assert.strictEqual(
    stateMachine.setAnswer(lockedState, "single-01", { selectedOptionId: "o2" }),
    lockedState,
  );

  const passedState = stateMachine.createLessonAssessmentState({ ...data, attempt: finalizedPassedAttempt() });
  assert.strictEqual(
    stateMachine.setAnswer(passedState, "multiple-01", { selectedOptionIds: ["o1"] }),
    passedState,
  );
});

test("progress counts complete local responses before the first submission", () => {
  const initial = stateMachine.createLessonAssessmentState(assessmentData());
  const single = stateMachine.setAnswer(initial, "single-01", { selectedOptionId: "o1" });
  const incompleteSlot = stateMachine.setAnswer(single, "slot-01", { selections: { p1: "o1" } });

  assert.equal(stateMachine.assessmentProgressLabel(incompleteSlot), "Отвечено 1 из 5");
});

test("ordered choice is complete only after every option has a unique position", () => {
  const question = {
    id: "ordered-01",
    sectionId: "questions",
    gradingMode: "automatic",
    type: "ordered_choice",
    prompt: "Расположите по порядку",
    options: [
      { id: "o1", label: "Первый" },
      { id: "o2", label: "Второй" },
      { id: "o3", label: "Третий" },
    ],
  } satisfies LessonAssessmentQuestion;

  assert.equal(
    stateMachine.isLessonAssessmentResponseComplete(question, { selectedOptionIds: ["o1", "o2"] }),
    false,
  );
  assert.equal(
    stateMachine.isLessonAssessmentResponseComplete(question, { selectedOptionIds: ["o1", "o2", "o3"] }),
    true,
  );
});

test("conflict reload preserves only answers edited after the sent snapshot", () => {
  const initial = stateMachine.createLessonAssessmentState(assessmentData());
  const editA = stateMachine.setAnswer(initial, "single-01", { selectedOptionId: "o1" });
  const sentSnapshot = stateMachine.createLessonAssessmentSaveSnapshot(editA, ["single-01"]);
  const editB = stateMachine.setAnswer(editA, "single-01", { selectedOptionId: "o2" });
  const serverData = assessmentData();

  const merged = stateMachine.applyConflictReload(
    editB,
    {
      ...serverData,
      attempt: {
        ...serverData.attempt!,
        revision: 9,
        responses: [{ questionId: "multiple-01", response: { selectedOptionIds: ["o1"] } }],
      },
    },
    sentSnapshot,
  );

  assert.equal(merged.attempt?.revision, 9);
  assert.deepEqual(merged.responses, {
    "multiple-01": { selectedOptionIds: ["o1"] },
    "single-01": { selectedOptionId: "o2" },
  });
  assert.deepEqual(merged.dirtyQuestionIds, ["single-01"]);
  assert.equal(merged.notice, "Состояние обновлено из другой вкладки; новые изменения ещё не сохранены");
});

test("terminal conflict reload discards local edits and keeps authoritative reviewed answers", () => {
  const initial = stateMachine.createLessonAssessmentState(assessmentData());
  const edited = stateMachine.setAnswer(initial, "single-01", { selectedOptionId: "o1" });
  const sentSnapshot = stateMachine.createLessonAssessmentSaveSnapshot(edited, ["single-01"]);
  const editedAgain = stateMachine.setAnswer(edited, "single-01", { selectedOptionId: "o2" });
  const serverData = assessmentData();

  const merged = stateMachine.applyConflictReload(
    editedAgain,
    {
      ...serverData,
      attempt: { ...submittedAttempt("failed"), revision: 9 },
    },
    sentSnapshot,
  );

  assert.deepEqual(merged.responses, {
    "single-01": { selectedOptionId: "o1" },
    "multiple-01": { selectedOptionIds: ["o1"] },
    "slot-01": { selections: { p1: "o1", p2: "o2" } },
    "ordered-01": { selectedOptionIds: ["o1", "o2"] },
    "diagram-01": { selections: { name: "o1", p1: "o2" } },
  });
  assert.deepEqual(merged.dirtyQuestionIds, []);
  assert.deepEqual(merged.questionEditVersions, {});
  assert.equal(merged.positionDirty, false);
  assert.equal(merged.notice, "Состояние обновлено из другой вкладки");
});

test("submission recovery accepts a terminal attempt returned by reload", () => {
  const initial = stateMachine.createLessonAssessmentState(assessmentData());
  const snapshot = stateMachine.createLessonAssessmentSaveSnapshot(initial, ["single-01"]);
  const synchronized = stateMachine.applyConflictReload(
    stateMachine.applySubmissionError(initial),
    { ...assessmentData(), attempt: submittedAttempt("pending_review") },
    snapshot,
  );

  assert.equal(synchronized.attempt?.status, "pending_review");
  assert.equal(synchronized.submissionStatus, "idle");
});

test("navigation-only changes produce an empty-response save and restore the latest position", () => {
  const initial = stateMachine.createLessonAssessmentState(assessmentData());
  const navigated = stateMachine.selectLessonAssessmentQuestion(initial, "multiple-01");
  const snapshot = stateMachine.createLessonAssessmentSaveSnapshot(navigated, ["multiple-01"]);

  assert.equal(navigated.positionDirty, true);
  assert.equal(snapshot.lastQuestionId, "multiple-01");
  assert.deepEqual(snapshot.responses, []);

  const savedData = assessmentData();
  const saved = stateMachine.applySavedAttempt(
    navigated,
    {
      ...savedData,
      attempt: {
        ...savedData.attempt!,
        revision: 4,
        lastQuestionId: "multiple-01",
      },
    },
    snapshot,
  );
  assert.equal(saved.positionDirty, false);
  assert.equal(saved.currentQuestionId, "multiple-01");

  const reloaded = stateMachine.createLessonAssessmentState({
    ...savedData,
    attempt: { ...savedData.attempt!, lastQuestionId: "multiple-01" },
  });
  assert.equal(reloaded.currentQuestionId, "multiple-01");
});

test("conflict reload preserves navigation performed after the sent snapshot", () => {
  const initial = stateMachine.createLessonAssessmentState(assessmentData());
  const firstNavigation = stateMachine.selectLessonAssessmentQuestion(initial, "multiple-01");
  const sentSnapshot = stateMachine.createLessonAssessmentSaveSnapshot(firstNavigation, ["multiple-01"]);
  const latestNavigation = stateMachine.selectLessonAssessmentQuestion(firstNavigation, "ordered-01");
  const serverData = assessmentData();

  const merged = stateMachine.applyConflictReload(
    latestNavigation,
    {
      ...serverData,
      attempt: { ...serverData.attempt!, revision: 7, lastQuestionId: "slot-01" },
    },
    sentSnapshot,
  );

  assert.equal(merged.currentQuestionId, "ordered-01");
  assert.equal(merged.positionDirty, true);
});

test("discard restores only the requested question ids from the confirmed snapshot", () => {
  const data = assessmentData();
  const initial = stateMachine.createLessonAssessmentState({
    ...data,
    attempt: {
      ...data.attempt!,
      responses: [
        { questionId: "single-01", response: { selectedOptionId: "o1" } },
        { questionId: "multiple-01", response: { selectedOptionIds: ["o2"] } },
      ],
    },
  });
  const editedSingle = stateMachine.setAnswer(initial, "single-01", { selectedOptionId: "o2" });
  const editedBoth = stateMachine.setAnswer(editedSingle, "multiple-01", { selectedOptionIds: ["o1"] });

  const discarded = stateMachine.discardLessonAssessmentChanges(editedBoth, ["single-01"]);

  assert.deepEqual(discarded.responses["single-01"], { selectedOptionId: "o1" });
  assert.deepEqual(discarded.responses["multiple-01"], { selectedOptionIds: ["o1"] });
  assert.deepEqual(discarded.dirtyQuestionIds, ["multiple-01"]);
  assert.deepEqual(discarded.questionEditVersions, {
    "multiple-01": discarded.questionEditVersions["multiple-01"],
  });
  assert.equal(discarded.saveStatus, "idle");
  assert.equal(discarded.notice, null);
});

test("save snapshot includes only dirty responses from the active navigation item", () => {
  const initial = stateMachine.createLessonAssessmentState(assessmentData());
  const editedSingle = stateMachine.setAnswer(initial, "single-01", { selectedOptionId: "o1" });
  const editedMultiple = stateMachine.setAnswer(editedSingle, "multiple-01", { selectedOptionIds: ["o2"] });
  const editedSlot = stateMachine.setAnswer(editedMultiple, "slot-01", {
    selections: { p1: "o1", p2: "o2" },
  });

  const snapshot = stateMachine.createLessonAssessmentSaveSnapshot(editedSlot, [
    "single-01",
    "slot-01",
  ]);

  assert.equal(snapshot.lastQuestionId, "slot-01");
  assert.deepEqual(
    snapshot.responses.map(({ questionId }) => questionId),
    ["single-01", "slot-01"],
  );
  assert.deepEqual(snapshot.responses.at(1)?.response, {
    selections: { p1: "o1", p2: "o2" },
  });
});
