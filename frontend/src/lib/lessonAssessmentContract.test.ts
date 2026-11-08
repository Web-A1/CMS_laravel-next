import assert from "node:assert/strict";
import test from "node:test";

const modulePath = "./lessonAssessmentContract.ts";
const contract: typeof import("./lessonAssessmentContract") = await import(modulePath);

const route = {
  subjectSlug: "anatomiya",
  courseSlug: "artrologiya",
  lessonSlug: "soedineniya-kostej-tulovishcha-i-cherepa",
} as const;

const assetPath = [
  "/api/v1/learning/subjects/anatomiya",
  "courses/artrologiya",
  "lessons/soedineniya-kostej-tulovishcha-i-cherepa",
  "assessment/assets/diagram-01",
].join("/");

type TestResponseEntry = {
  questionId: string;
  response: Record<string, unknown>;
};

type TestPayload = {
  data: {
    assessment: {
      id: number;
      contentVersion: number;
      scoringPolicy: Record<string, unknown>;
      title: string;
      totalCount: number;
      retakeAvailable: boolean;
      sections: Array<Record<string, unknown>>;
      questions: Array<Record<string, unknown>>;
    };
    attempt: {
      id: number;
      status: string;
      revision: number;
      submissionCount: number;
      answeredCount: number;
      correctCount: number;
      lastQuestionId: string | null;
      submittedAt: string | null;
      finalizedAt: string | null;
      passedAt: string | null;
      failureReason: string | null;
      pendingManualQuestionCount: number;
      reviewedManualQuestionCount: number;
      reviewedManualQuestionIds: Array<string>;
      manualProgressBps?: number;
      automaticGate?: Record<string, unknown>;
      score?: Record<string, unknown>;
      manualResults?: Array<Record<string, unknown>>;
      responses: Array<TestResponseEntry>;
      lockedQuestionIds: Array<string>;
    };
  };
};

function validPayload(): TestPayload {
  return {
    data: {
      assessment: {
        id: 7,
        contentVersion: 37,
        scoringPolicy: {
          schemaVersion: 2,
          contentVersion: 37,
          mode: "hybrid",
          automaticWeightBps: 7000,
          manualWeightBps: 3000,
          automaticGateOverallRequiredBps: 8000,
          automaticGateSectionRequiredBps: 6000,
          manualRequiredBps: 6000,
          finalRequiredBps: 8000,
          automaticQuestionCount: 5,
          manualQuestionCount: 3,
        },
        title: "Соединения костей",
        totalCount: 8,
        retakeAvailable: false,
        sections: [
          { id: "fundamentals", title: "Вопросы" },
          { id: "diagrams", title: "Схемы" },
        ],
        questions: [
          {
            id: "single-01",
            sectionId: "fundamentals",
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
            sectionId: "fundamentals",
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
            sectionId: "fundamentals",
            gradingMode: "automatic",
            type: "slot_choice",
            prompt: "Заполните пропуск",
            options: [
              { id: "o1", label: "Первый" },
              { id: "o2", label: "Второй" },
            ],
            parts: [{ id: "p1", prompt: "Пропуск", optionIds: ["o1", "o2"] }],
          },
          {
            id: "ordered-01",
            sectionId: "fundamentals",
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
            gradingMode: "automatic",
            type: "diagram_labels",
            prompt: "Подпишите схему",
            options: [
              { id: "o1", label: "Название" },
              { id: "o2", label: "Часть" },
            ],
            parts: [
              { id: "name", prompt: "Название", optionIds: ["o1"] },
              { id: "p01", prompt: "Стрелка", optionIds: ["o2"] },
            ],
            assetPath,
            width: 640,
            height: 480,
            callouts: [
              {
                partId: "p01",
                x: 0.5,
                y: 0.4,
                labelX: 0.7,
                labelY: 0.4,
                labelSide: "left",
                labelWidth: 0.24,
                leaderStyle: "straight",
                targetMarker: "arrow",
              },
            ],
          },
          {
            id: "text-01",
            sectionId: "fundamentals",
            gradingMode: "manual",
            type: "text_fields",
            prompt: "Заполните строки",
            parts: [
              { id: "p01", prompt: "Строка 1" },
              { id: "p02", prompt: "Строка 2" },
            ],
          },
          {
            id: "diagram-text-01",
            sectionId: "diagrams",
            gradingMode: "manual",
            type: "diagram_text",
            prompt: "Подпишите схему самостоятельно",
            parts: [
              { id: "name", prompt: "Название" },
              { id: "p01", prompt: "Стрелка" },
            ],
            assetPath,
            width: 640,
            height: 480,
            callouts: [{ partId: "p01", x: 0.5, y: 0.4 }],
          },
          {
            id: "long-01",
            sectionId: "fundamentals",
            gradingMode: "manual",
            type: "long_text",
            prompt: "Ответьте развёрнуто",
            rows: 5,
          },
        ],
      },
      attempt: {
        id: 19,
        status: "in_progress",
        revision: 3,
        submissionCount: 0,
        answeredCount: 8,
        correctCount: 0,
        lastQuestionId: "diagram-01",
        submittedAt: null,
        finalizedAt: null,
        passedAt: null,
        failureReason: null,
        pendingManualQuestionCount: 0,
        reviewedManualQuestionCount: 0,
        reviewedManualQuestionIds: [],
        responses: [
          { questionId: "single-01", response: { selectedOptionId: "o1" } },
          { questionId: "multiple-01", response: { selectedOptionIds: ["o1"] } },
          { questionId: "slot-01", response: { selections: { p1: "o2" } } },
          { questionId: "ordered-01", response: { selectedOptionIds: ["o1", "o2"] } },
          { questionId: "diagram-01", response: { selections: { name: "o1", p01: "o2" } } },
          { questionId: "text-01", response: { texts: { p01: "Первый", p02: "Второй" } } },
          { questionId: "diagram-text-01", response: { texts: { name: "Название", p01: "Часть" } } },
          { questionId: "long-01", response: { text: "Развёрнутый ответ" } },
        ],
        lockedQuestionIds: [],
      },
    },
  };
}

type AutomaticGateFixture = "failed" | "threshold" | "perfect";

function automaticQuestionResults(fixture: AutomaticGateFixture): Array<Record<string, unknown>> {
  const perfect = fixture === "perfect";
  const threshold = fixture === "threshold";

  return [
    { questionId: "single-01", correct: true, earnedParts: 1, maxParts: 1, incorrectPartIds: [] },
    { questionId: "multiple-01", correct: perfect || threshold, earnedParts: perfect || threshold ? 1 : 0, maxParts: 1, incorrectPartIds: [] },
    { questionId: "slot-01", correct: perfect || threshold, earnedParts: perfect || threshold ? 1 : 0, maxParts: 1, incorrectPartIds: perfect || threshold ? [] : ["p1"] },
    { questionId: "ordered-01", correct: perfect, earnedParts: perfect ? 1 : 0, maxParts: 1, incorrectPartIds: [] },
    { questionId: "diagram-01", correct: perfect || threshold, earnedParts: perfect || threshold ? 2 : 1, maxParts: 2, incorrectPartIds: perfect || threshold ? [] : ["p01"] },
  ];
}

function automaticGate(fixture: AutomaticGateFixture): Record<string, unknown> {
  if (fixture === "failed") {
    return {
      passed: false,
      overall: { numerator: 3, denominator: 10, percentBps: 3000, requiredBps: 8000, passed: false },
      sections: [
        { sectionId: "fundamentals", numerator: 1, denominator: 4, percentBps: 2500, requiredBps: 6000, passed: false },
        { sectionId: "diagrams", numerator: 1, denominator: 2, percentBps: 5000, requiredBps: 6000, passed: false },
      ],
      correctCount: 1,
      questionResults: automaticQuestionResults(fixture),
    };
  }

  if (fixture === "threshold") {
    return {
      passed: true,
      overall: { numerator: 4, denominator: 5, percentBps: 8000, requiredBps: 8000, passed: true },
      sections: [
        { sectionId: "fundamentals", numerator: 3, denominator: 4, percentBps: 7500, requiredBps: 6000, passed: true },
        { sectionId: "diagrams", numerator: 1, denominator: 1, percentBps: 10000, requiredBps: 6000, passed: true },
      ],
      correctCount: 4,
      questionResults: automaticQuestionResults(fixture),
    };
  }

  return {
    passed: true,
    overall: { numerator: 1, denominator: 1, percentBps: 10000, requiredBps: 8000, passed: true },
    sections: [
      { sectionId: "fundamentals", numerator: 1, denominator: 1, percentBps: 10000, requiredBps: 6000, passed: true },
      { sectionId: "diagrams", numerator: 1, denominator: 1, percentBps: 10000, requiredBps: 6000, passed: true },
    ],
    correctCount: 5,
    questionResults: automaticQuestionResults(fixture),
  };
}

function submittedAttempt(fixture: "automatic_failed" | "pending_review"): TestPayload {
  const payload = validPayload();
  const gateFixture = fixture === "automatic_failed" ? "failed" : "perfect";
  payload.data.assessment.retakeAvailable = fixture === "automatic_failed";
  payload.data.attempt = {
    ...payload.data.attempt,
    status: fixture === "automatic_failed" ? "failed" : "pending_review",
    revision: 4,
    submissionCount: 1,
    answeredCount: 8,
    correctCount: gateFixture === "failed" ? 1 : 5,
    lastQuestionId: "long-01",
    submittedAt: "2026-08-19T12:00:00.123456Z",
    finalizedAt: null,
    passedAt: null,
    failureReason: fixture === "automatic_failed" ? "automatic_threshold_not_met" : null,
    pendingManualQuestionCount: fixture === "automatic_failed" ? 0 : 3,
    reviewedManualQuestionCount: 0,
    ...(fixture === "pending_review" ? { manualProgressBps: 0 } : {}),
    automaticGate: automaticGate(gateFixture),
    responses: structuredClone(payload.data.attempt.responses),
    lockedQuestionIds: payload.data.assessment.questions.map((question) => String(question.id)),
  };

  return payload;
}

function finalizedPayload(
  automaticFixture: "threshold" | "perfect",
  manualScores: readonly [number, number, number],
): TestPayload {
  const payload = validPayload();
  const automaticBps = automaticFixture === "threshold" ? 8000 : 10000;
  const manualBps = Math.floor((manualScores.reduce((sum, value) => sum + value, 0) + 1) / 3);
  const finalBps = Math.floor((automaticBps * 7000 + manualBps * 3000 + 5000) / 10000);
  const passed = manualBps >= 6000 && finalBps >= 8000;
  const failureReason = passed
    ? null
    : manualBps < 6000
      ? "manual_threshold_not_met"
      : "combined_threshold_not_met";

  payload.data.assessment.retakeAvailable = true;
  payload.data.attempt = {
    ...payload.data.attempt,
    status: passed ? "passed" : "failed",
    revision: 5,
    submissionCount: 1,
    answeredCount: 8,
    correctCount: automaticFixture === "threshold" ? 4 : 5,
    lastQuestionId: "long-01",
    submittedAt: "2026-08-19T12:00:00.123456Z",
    finalizedAt: "2026-08-20T09:30:00.123456+03:00",
    passedAt: passed ? "2026-08-20T09:30:00.123456+03:00" : null,
    failureReason,
    pendingManualQuestionCount: 0,
    reviewedManualQuestionCount: 3,
    reviewedManualQuestionIds: ["text-01", "diagram-text-01", "long-01"],
    automaticGate: automaticGate(automaticFixture),
    score: {
      checked: true,
      passed,
      mastered: finalBps === 10000,
      overall: { numerator: finalBps, denominator: 10000, percentBps: finalBps, requiredBps: 8000, passed: finalBps >= 8000 },
      components: { automaticBps, manualBps, automaticWeightBps: 7000, manualWeightBps: 3000 },
    },
    manualResults: [
      { questionId: "text-01", scoreBps: manualScores[0], comment: "Комментарий к строкам" },
      { questionId: "diagram-text-01", scoreBps: manualScores[1], comment: null },
      { questionId: "long-01", scoreBps: manualScores[2], comment: "Комментарий к ответу" },
    ],
    responses: structuredClone(payload.data.attempt.responses),
    lockedQuestionIds: payload.data.assessment.questions.map((question) => String(question.id)),
  };

  return payload;
}

function submissionPayload(fixture: "automatic_failed" | "pending_review") {
  const payload = submittedAttempt(fixture);
  const automaticGate = payload.data.attempt.automaticGate;
  assert.ok(automaticGate);

  return {
    data: {
      assessment: structuredClone(payload.data.assessment),
      attempt: structuredClone(payload.data.attempt),
      submission: {
        number: payload.data.attempt.submissionCount,
        automaticGate: structuredClone(automaticGate),
      },
    },
  };
}

test("parses the exact assessment contract including typed answers", () => {
  const parsed = contract.parseLessonAssessmentPayload(validPayload(), route);

  assert.ok(parsed);
  assert.equal(parsed.assessment.questions.length, 8);
  assert.deepEqual(
    parsed.assessment.questions.map((question) => question.type),
    [
      "single_choice",
      "multiple_choice",
      "slot_choice",
      "ordered_choice",
      "diagram_labels",
      "text_fields",
      "diagram_text",
      "long_text",
    ],
  );
  const diagramQuestion = parsed.assessment.questions[4];
  assert.equal(diagramQuestion?.type, "diagram_labels");
  if (diagramQuestion?.type === "diagram_labels") {
    assert.deepEqual(diagramQuestion.callouts[0], {
      partId: "p01",
      x: 0.5,
      y: 0.4,
      labelX: 0.7,
      labelY: 0.4,
      labelSide: "left",
      labelWidth: 0.24,
      leaderStyle: "straight",
      targetMarker: "arrow",
    });
  }
  assert.equal(parsed.attempt?.revision, 3);
});

test("accepts reviewed manual question ids without exposing manual results before finalization", () => {
  const payload = submittedAttempt("pending_review");
  payload.data.attempt.pendingManualQuestionCount = 2;
  payload.data.attempt.reviewedManualQuestionCount = 1;
  payload.data.attempt.reviewedManualQuestionIds = ["text-01"];
  payload.data.attempt.manualProgressBps = 2667;

  const parsed = contract.parseLessonAssessmentPayload(payload, route);

  assert.ok(parsed);
  assert.equal(parsed.attempt?.status, "pending_review");
  assert.deepEqual(parsed.attempt?.reviewedManualQuestionIds, ["text-01"]);
  assert.ok(parsed.attempt !== null && !("manualResults" in parsed.attempt));
});

test("rejects inconsistent or automatic reviewed-question ids", () => {
  const inconsistent = submittedAttempt("pending_review");
  inconsistent.data.attempt.reviewedManualQuestionCount = 1;
  assert.equal(contract.parseLessonAssessmentPayload(inconsistent, route), null);

  const automatic = submittedAttempt("pending_review");
  automatic.data.attempt.pendingManualQuestionCount = 2;
  automatic.data.attempt.reviewedManualQuestionCount = 1;
  automatic.data.attempt.reviewedManualQuestionIds = ["single-01"];
  automatic.data.attempt.manualProgressBps = 2667;
  assert.equal(contract.parseLessonAssessmentPayload(automatic, route), null);

  const impossibleScore = submittedAttempt("pending_review");
  impossibleScore.data.attempt.pendingManualQuestionCount = 2;
  impossibleScore.data.attempt.reviewedManualQuestionCount = 1;
  impossibleScore.data.attempt.reviewedManualQuestionIds = ["text-01"];
  impossibleScore.data.attempt.manualProgressBps = 3334;
  assert.equal(contract.parseLessonAssessmentPayload(impossibleScore, route), null);
});

test("rejects slot-choice and diagram-label questions without graded parts", () => {
  for (const questionIndex of [2, 4]) {
    const payload = validPayload();
    payload.data.assessment.questions[questionIndex] = {
      ...payload.data.assessment.questions[questionIndex],
      parts: [],
    };

    assert.equal(contract.parseLessonAssessmentPayload(payload, route), null);
  }
});

test("accepts zero or one name callout and one or more uniquely identified targets for every other part", () => {
  const withoutName = validPayload();
  assert.ok(contract.parseLessonAssessmentPayload(withoutName, route));

  const withName = structuredClone(validPayload());
  if (
    typeof withName === "object" &&
    withName !== null &&
    "data" in withName &&
    typeof withName.data === "object" &&
    withName.data !== null &&
    "assessment" in withName.data &&
    typeof withName.data.assessment === "object" &&
    withName.data.assessment !== null &&
    "questions" in withName.data.assessment &&
    Array.isArray(withName.data.assessment.questions)
  ) {
    const diagram = withName.data.assessment.questions[4];
    if (typeof diagram === "object" && diagram !== null && "callouts" in diagram && Array.isArray(diagram.callouts)) {
      diagram.callouts.push({ partId: "name", x: 0.2, y: 0.3 });
    }
  }
  assert.ok(contract.parseLessonAssessmentPayload(withName, route));

  const duplicateName = structuredClone(withName);
  if (
    typeof duplicateName === "object" &&
    duplicateName !== null &&
    "data" in duplicateName &&
    typeof duplicateName.data === "object" &&
    duplicateName.data !== null &&
    "assessment" in duplicateName.data &&
    typeof duplicateName.data.assessment === "object" &&
    duplicateName.data.assessment !== null &&
    "questions" in duplicateName.data.assessment &&
    Array.isArray(duplicateName.data.assessment.questions)
  ) {
    const diagram = duplicateName.data.assessment.questions[4];
    if (typeof diagram === "object" && diagram !== null && "callouts" in diagram && Array.isArray(diagram.callouts)) {
      diagram.callouts.push({ partId: "name", x: 0.4, y: 0.5 });
    }
  }
  assert.equal(contract.parseLessonAssessmentPayload(duplicateName, route), null);

  const repeatedPartTargets = structuredClone(validPayload());
  if (
    typeof repeatedPartTargets === "object" &&
    repeatedPartTargets !== null &&
    "data" in repeatedPartTargets &&
    typeof repeatedPartTargets.data === "object" &&
    repeatedPartTargets.data !== null &&
    "assessment" in repeatedPartTargets.data &&
    typeof repeatedPartTargets.data.assessment === "object" &&
    repeatedPartTargets.data.assessment !== null &&
    "questions" in repeatedPartTargets.data.assessment &&
    Array.isArray(repeatedPartTargets.data.assessment.questions)
  ) {
    const diagram = repeatedPartTargets.data.assessment.questions[4];
    if (typeof diagram === "object" && diagram !== null && "callouts" in diagram) {
      diagram.callouts = [
        {
          targetId: "p01-1",
          partId: "p01",
          x: 0.5,
          y: 0.4,
          labelX: 0.7,
          labelY: 0.4,
          labelSide: "left",
          labelWidth: 0.24,
          leaderStyle: "elbow",
        },
        { targetId: "p01-2", partId: "p01", x: 0.45, y: 0.5 },
        { targetId: "p01-3", partId: "p01", x: 0.4, y: 0.6 },
      ];
    }
  }
  const parsedRepeatedPartTargets = contract.parseLessonAssessmentPayload(repeatedPartTargets, route);
  assert.ok(parsedRepeatedPartTargets);
  const parsedRepeatedQuestion = parsedRepeatedPartTargets.assessment.questions[4];
  assert.equal(parsedRepeatedQuestion?.type, "diagram_labels");
  if (parsedRepeatedQuestion?.type === "diagram_labels") {
    assert.deepEqual(
      parsedRepeatedQuestion.callouts.map((callout) => callout.targetId),
      ["p01-1", "p01-2", "p01-3"],
    );
  }

  const ambiguousRepeatedPart = structuredClone(repeatedPartTargets);
  if (
    typeof ambiguousRepeatedPart === "object" &&
    ambiguousRepeatedPart !== null &&
    "data" in ambiguousRepeatedPart &&
    typeof ambiguousRepeatedPart.data === "object" &&
    ambiguousRepeatedPart.data !== null &&
    "assessment" in ambiguousRepeatedPart.data &&
    typeof ambiguousRepeatedPart.data.assessment === "object" &&
    ambiguousRepeatedPart.data.assessment !== null &&
    "questions" in ambiguousRepeatedPart.data.assessment &&
    Array.isArray(ambiguousRepeatedPart.data.assessment.questions)
  ) {
    const diagram = ambiguousRepeatedPart.data.assessment.questions[4];
    if (typeof diagram === "object" && diagram !== null && "callouts" in diagram && Array.isArray(diagram.callouts)) {
      const repeated = diagram.callouts[1];
      if (typeof repeated === "object" && repeated !== null) {
        delete repeated.targetId;
      }
    }
  }
  assert.equal(contract.parseLessonAssessmentPayload(ambiguousRepeatedPart, route), null);

  const duplicateTargetId = structuredClone(repeatedPartTargets);
  if (
    typeof duplicateTargetId === "object" &&
    duplicateTargetId !== null &&
    "data" in duplicateTargetId &&
    typeof duplicateTargetId.data === "object" &&
    duplicateTargetId.data !== null &&
    "assessment" in duplicateTargetId.data &&
    typeof duplicateTargetId.data.assessment === "object" &&
    duplicateTargetId.data.assessment !== null &&
    "questions" in duplicateTargetId.data.assessment &&
    Array.isArray(duplicateTargetId.data.assessment.questions)
  ) {
    const diagram = duplicateTargetId.data.assessment.questions[4];
    if (typeof diagram === "object" && diagram !== null && "callouts" in diagram && Array.isArray(diagram.callouts)) {
      const repeated = diagram.callouts[1];
      if (typeof repeated === "object" && repeated !== null) {
        repeated.targetId = "p01-1";
      }
    }
  }
  assert.equal(contract.parseLessonAssessmentPayload(duplicateTargetId, route), null);
});

test("accepts a diagram field anchored below its target", () => {
  const payload = validPayload();
  const diagram = payload.data.assessment.questions[4];
  assert.ok(diagram && Array.isArray(diagram.callouts));
  diagram.callouts = [{
    partId: "p01",
    x: 0.5,
    y: 0.4,
    labelX: 0.5,
    labelY: 0.72,
    labelSide: "bottom",
    labelWidth: 0.24,
    leaderStyle: "straight",
  }];

  const parsed = contract.parseLessonAssessmentPayload(payload, route);
  assert.ok(parsed);
  const parsedDiagram = parsed.assessment.questions[4];
  assert.equal(parsedDiagram?.type, "diagram_labels");
  if (parsedDiagram?.type === "diagram_labels") {
    assert.equal(parsedDiagram.callouts[0]?.labelSide, "bottom");
  }
});

test("accepts a diagram made only of callout labels", () => {
  const payload = validPayload();
  const diagram = payload.data.assessment.questions[4];
  assert.ok(diagram && Array.isArray(diagram.parts));
  diagram.parts = diagram.parts.filter((part: { id?: unknown }) => part.id !== "name");
  payload.data.attempt.responses[4] = {
    questionId: "diagram-01",
    response: { selections: { p01: "o2" } },
  };
  const parsed = contract.parseLessonAssessmentPayload(payload, route);
  assert.ok(parsed);
  const parsedDiagram = parsed.assessment.questions[4];
  assert.equal(parsedDiagram?.type, "diagram_labels");
  if (parsedDiagram?.type === "diagram_labels") {
    assert.equal(parsedDiagram.parts.some((part) => part.id === "name"), false);
  }
});

test("accepts a centered diagram field anchored above its target", () => {
  const payload = validPayload();
  const diagram = payload.data.assessment.questions[4];
  assert.ok(diagram && Array.isArray(diagram.callouts));
  diagram.callouts = [{
    partId: "p01",
    x: 0.5,
    y: 0.4,
    labelX: 0.5,
    labelY: 0.18,
    labelSide: "top",
    labelWidth: 0.24,
    leaderStyle: "straight",
  }];

  const parsed = contract.parseLessonAssessmentPayload(payload, route);
  assert.ok(parsed);
  const parsedDiagram = parsed.assessment.questions[4];
  assert.equal(parsedDiagram?.type, "diagram_labels");
  if (parsedDiagram?.type === "diagram_labels") {
    assert.equal(parsedDiagram.callouts[0]?.labelSide, "top");
  }
});

test("rejects extra keys and forbidden metadata recursively", () => {
  const extraRoot = { data: { assessment: {}, attempt: null }, storage: "private" };
  assert.equal(contract.parseLessonAssessmentPayload(extraRoot, route), null);

  const nestedForbidden = validPayload();
  if (
    typeof nestedForbidden === "object" &&
    nestedForbidden !== null &&
    "data" in nestedForbidden &&
    typeof nestedForbidden.data === "object" &&
    nestedForbidden.data !== null &&
    "assessment" in nestedForbidden.data &&
    typeof nestedForbidden.data.assessment === "object" &&
    nestedForbidden.data.assessment !== null &&
    "questions" in nestedForbidden.data.assessment &&
    Array.isArray(nestedForbidden.data.assessment.questions)
  ) {
    nestedForbidden.data.assessment.questions[0] = {
      ...nestedForbidden.data.assessment.questions[0],
      author: { answerKey: "o1", disk: "private" },
    };
  }
  assert.equal(contract.parseLessonAssessmentPayload(nestedForbidden, route), null);
});

test("rejects duplicate IDs and duplicate or unknown references", () => {
  const duplicateQuestion = validPayload();
  if (
    typeof duplicateQuestion === "object" &&
    duplicateQuestion !== null &&
    "data" in duplicateQuestion &&
    typeof duplicateQuestion.data === "object" &&
    duplicateQuestion.data !== null &&
    "assessment" in duplicateQuestion.data &&
    typeof duplicateQuestion.data.assessment === "object" &&
    duplicateQuestion.data.assessment !== null &&
    "questions" in duplicateQuestion.data.assessment &&
    Array.isArray(duplicateQuestion.data.assessment.questions)
  ) {
    duplicateQuestion.data.assessment.questions[1] = {
      ...duplicateQuestion.data.assessment.questions[1],
      id: "single-01",
    };
  }
  assert.equal(contract.parseLessonAssessmentPayload(duplicateQuestion, route), null);

  const duplicateOptionRef = validPayload();
  if (
    typeof duplicateOptionRef === "object" &&
    duplicateOptionRef !== null &&
    "data" in duplicateOptionRef &&
    typeof duplicateOptionRef.data === "object" &&
    duplicateOptionRef.data !== null &&
    "assessment" in duplicateOptionRef.data &&
    typeof duplicateOptionRef.data.assessment === "object" &&
    duplicateOptionRef.data.assessment !== null &&
    "questions" in duplicateOptionRef.data.assessment &&
    Array.isArray(duplicateOptionRef.data.assessment.questions)
  ) {
    const slot = duplicateOptionRef.data.assessment.questions[2];
    if (typeof slot === "object" && slot !== null && "parts" in slot && Array.isArray(slot.parts)) {
      slot.parts[0] = { id: "p1", prompt: "Пропуск", optionIds: ["o1", "o1"] };
    }
  }
  assert.equal(contract.parseLessonAssessmentPayload(duplicateOptionRef, route), null);
});

test("rejects unknown question types and wrong response shapes", () => {
  const invalidTargetMarker = validPayload();
  if (
    typeof invalidTargetMarker === "object" &&
    invalidTargetMarker !== null &&
    "data" in invalidTargetMarker &&
    typeof invalidTargetMarker.data === "object" &&
    invalidTargetMarker.data !== null &&
    "assessment" in invalidTargetMarker.data &&
    typeof invalidTargetMarker.data.assessment === "object" &&
    invalidTargetMarker.data.assessment !== null &&
    "questions" in invalidTargetMarker.data.assessment &&
    Array.isArray(invalidTargetMarker.data.assessment.questions)
  ) {
    const diagram = invalidTargetMarker.data.assessment.questions[4];
    if (typeof diagram === "object" && diagram !== null && "callouts" in diagram && Array.isArray(diagram.callouts)) {
      const callout = diagram.callouts[0];
      if (typeof callout === "object" && callout !== null) {
        callout.targetMarker = "area";
      }
    }
  }
  assert.equal(contract.parseLessonAssessmentPayload(invalidTargetMarker, route), null);

  const unknownType = validPayload();
  if (
    typeof unknownType === "object" &&
    unknownType !== null &&
    "data" in unknownType &&
    typeof unknownType.data === "object" &&
    unknownType.data !== null &&
    "assessment" in unknownType.data &&
    typeof unknownType.data.assessment === "object" &&
    unknownType.data.assessment !== null &&
    "questions" in unknownType.data.assessment &&
    Array.isArray(unknownType.data.assessment.questions)
  ) {
    unknownType.data.assessment.questions[0] = {
      ...unknownType.data.assessment.questions[0],
      type: "free_text",
    };
  }
  assert.equal(contract.parseLessonAssessmentPayload(unknownType, route), null);

  const wrongResponse = validPayload();
  if (
    typeof wrongResponse === "object" &&
    wrongResponse !== null &&
    "data" in wrongResponse &&
    typeof wrongResponse.data === "object" &&
    wrongResponse.data !== null &&
    "attempt" in wrongResponse.data &&
    typeof wrongResponse.data.attempt === "object" &&
    wrongResponse.data.attempt !== null &&
    "responses" in wrongResponse.data.attempt &&
    Array.isArray(wrongResponse.data.attempt.responses)
  ) {
    wrongResponse.data.attempt.responses[0] = {
      questionId: "single-01",
      response: { selectedOptionIds: ["o1"] },
    };
  }
  assert.equal(contract.parseLessonAssessmentPayload(wrongResponse, route), null);
});

test("accepts only the canonical same-origin asset route for the exact lesson", () => {
  const invalidPaths = [
    `${assetPath}?download=1`,
    `${assetPath}#fragment`,
    assetPath.replace("/anatomiya/", "/other/"),
    assetPath.replace("/assets/diagram-01", "/assets/../diagram-01"),
    assetPath.replace("/assets/diagram-01", "/assets/%2e%2e%2fdiagram-01"),
    `https://evil.example${assetPath}`,
  ];

  for (const invalidPath of invalidPaths) {
    const payload = validPayload();
    if (
      typeof payload === "object" &&
      payload !== null &&
      "data" in payload &&
      typeof payload.data === "object" &&
      payload.data !== null &&
      "assessment" in payload.data &&
      typeof payload.data.assessment === "object" &&
      payload.data.assessment !== null &&
      "questions" in payload.data.assessment &&
      Array.isArray(payload.data.assessment.questions)
    ) {
      const diagram = payload.data.assessment.questions[4];
      if (typeof diagram === "object" && diagram !== null) {
        payload.data.assessment.questions[4] = { ...diagram, assetPath: invalidPath };
      }
    }
    assert.equal(contract.parseLessonAssessmentPayload(payload, route), null, invalidPath);
  }
});

test("parses only the exact submission envelope and binds it to the authoritative automatic gate", () => {
  const payload = submissionPayload("automatic_failed");

  assert.ok(contract.parseLessonAssessmentSubmissionPayload(payload, route));
  assert.equal(contract.parseLessonAssessmentPayload(payload, route), null);

  const wrongNumber = structuredClone(payload);
  wrongNumber.data.submission.number = 2;
  assert.equal(contract.parseLessonAssessmentSubmissionPayload(wrongNumber, route), null);

  const mismatchedGate = structuredClone(payload);
  mismatchedGate.data.submission.automaticGate.correctCount = 2;
  assert.equal(contract.parseLessonAssessmentSubmissionPayload(mismatchedGate, route), null);

  const extraField = structuredClone(payload);
  Object.assign(extraField.data.submission, { finalScore: 3000 });
  assert.equal(contract.parseLessonAssessmentSubmissionPayload(extraField, route), null);
});

test("rejects non-backend timestamps and accepts timezone-bearing ISO-8601 edge cases", () => {
  for (const submittedAt of [
    "0",
    "2026-08-19 12:00:00",
    "2026-08-19T12:00:00",
    "2026-02-30T12:00:00Z",
    "2026-08-19T12:00:00+14:30",
    "not-a-date",
  ]) {
    const payload = submittedAttempt("pending_review");
    payload.data.attempt.submittedAt = submittedAt;
    assert.equal(contract.parseLessonAssessmentPayload(payload, route), null, submittedAt);
  }

  for (const submittedAt of ["2026-08-19T12:00:00Z", "2026-08-19T15:00:00.123456+03:00"]) {
    const payload = submittedAttempt("pending_review");
    payload.data.attempt.submittedAt = submittedAt;
    assert.ok(contract.parseLessonAssessmentPayload(payload, route), submittedAt);
  }
});

test("enforces coherent attempt counts, responses and locked IDs", () => {
  const invalidAttempts = [
    { submissionCount: 1 },
    { answeredCount: 9 },
    { correctCount: 2 },
  ];

  for (const changes of invalidAttempts) {
    const payload = validPayload();
    payload.data.attempt = { ...payload.data.attempt, ...changes };
    assert.equal(contract.parseLessonAssessmentPayload(payload, route), null, JSON.stringify(changes));
  }

  const incompleteSavedResponse = validPayload();
  incompleteSavedResponse.data.attempt = {
    ...incompleteSavedResponse.data.attempt,
    answeredCount: 0,
    responses: [{ questionId: "single-01", response: { selectedOptionId: "o1" } }],
  };
  assert.ok(contract.parseLessonAssessmentPayload(incompleteSavedResponse, route));

  const incompleteOrderedDraft = validPayload();
  incompleteOrderedDraft.data.attempt = {
    ...incompleteOrderedDraft.data.attempt,
    answeredCount: 0,
    responses: [{ questionId: "ordered-01", response: { selectedOptionIds: ["o1"] } }],
  };
  assert.ok(contract.parseLessonAssessmentPayload(incompleteOrderedDraft, route));

  const lockedInProgressResponse = validPayload();
  lockedInProgressResponse.data.attempt = {
    ...lockedInProgressResponse.data.attempt,
    answeredCount: 1,
    responses: [{ questionId: "single-01", response: { selectedOptionId: "o1" } }],
    lockedQuestionIds: ["single-01"],
  };
  assert.ok(contract.parseLessonAssessmentPayload(lockedInProgressResponse, route));

  const lockedIncompleteInProgressResponse = validPayload();
  lockedIncompleteInProgressResponse.data.attempt = {
    ...lockedIncompleteInProgressResponse.data.attempt,
    answeredCount: 0,
    responses: [{ questionId: "ordered-01", response: { selectedOptionIds: ["o1"] } }],
    lockedQuestionIds: ["ordered-01"],
  };
  assert.equal(contract.parseLessonAssessmentPayload(lockedIncompleteInProgressResponse, route), null);

  const terminalMissingResponse = submittedAttempt("pending_review");
  terminalMissingResponse.data.attempt.responses = terminalMissingResponse.data.attempt.responses.filter(
    (entry) => entry.questionId !== "ordered-01",
  );
  assert.equal(contract.parseLessonAssessmentPayload(terminalMissingResponse, route), null);

  for (const incompleteResponse of [
    { questionId: "ordered-01", response: { selectedOptionIds: ["o1"] } },
    { questionId: "long-01", response: { text: "   " } },
  ]) {
    const terminalWithDraft = submittedAttempt("pending_review");
    terminalWithDraft.data.attempt.responses = terminalWithDraft.data.attempt.responses.map((entry) =>
      entry.questionId === incompleteResponse.questionId ? incompleteResponse : entry,
    );
    assert.equal(contract.parseLessonAssessmentPayload(terminalWithDraft, route), null);
  }
});

test("rejects historical submissions on a new in-progress retake attempt", () => {
  const payload = validPayload();
  payload.data.attempt.submissionCount = 1;

  assert.equal(contract.parseLessonAssessmentPayload(payload, route), null);
});

test("enforces exact failed, passed and in-progress lifecycle invariants", () => {
  assert.ok(contract.parseLessonAssessmentPayload(validPayload(), route));
  assert.ok(contract.parseLessonAssessmentPayload(submittedAttempt("automatic_failed"), route));
  assert.ok(contract.parseLessonAssessmentPayload(submittedAttempt("pending_review"), route));
  assert.ok(contract.parseLessonAssessmentPayload(finalizedPayload("perfect", [10000, 10000, 10000]), route));
  assert.ok(contract.parseLessonAssessmentPayload(finalizedPayload("perfect", [5000, 5000, 5000]), route));

  const inProgressWithPassedAt = validPayload();
  inProgressWithPassedAt.data.attempt.passedAt = "2026-08-19T12:00:00Z";
  assert.equal(contract.parseLessonAssessmentPayload(inProgressWithPassedAt, route), null);

  const automaticFailureWithFinalScore = submittedAttempt("automatic_failed");
  automaticFailureWithFinalScore.data.attempt.score = structuredClone(
    finalizedPayload("perfect", [10000, 10000, 10000]).data.attempt.score,
  );
  assert.equal(contract.parseLessonAssessmentPayload(automaticFailureWithFinalScore, route), null);

  const pendingWithWrongCounters = submittedAttempt("pending_review");
  pendingWithWrongCounters.data.attempt.pendingManualQuestionCount = 2;
  assert.equal(contract.parseLessonAssessmentPayload(pendingWithWrongCounters, route), null);

  const finalizedWithoutManualResults = finalizedPayload("perfect", [10000, 10000, 10000]);
  delete finalizedWithoutManualResults.data.attempt.manualResults;
  assert.equal(contract.parseLessonAssessmentPayload(finalizedWithoutManualResults, route), null);

  const terminalWithoutAllLocks = submittedAttempt("pending_review");
  terminalWithoutAllLocks.data.attempt.lockedQuestionIds.pop();
  assert.equal(contract.parseLessonAssessmentPayload(terminalWithoutAllLocks, route), null);
});

test("centralizes submission number, gate and attempt coherence", () => {
  const pending = submissionPayload("pending_review");
  assert.ok(contract.parseLessonAssessmentSubmissionPayload(pending, route));

  const wrongAttemptCount = structuredClone(pending);
  wrongAttemptCount.data.attempt.submissionCount = 2;
  assert.equal(contract.parseLessonAssessmentSubmissionPayload(wrongAttemptCount, route), null);

  const wrongCorrectCount = structuredClone(pending);
  wrongCorrectCount.data.attempt.correctCount = 0;
  assert.equal(contract.parseLessonAssessmentSubmissionPayload(wrongCorrectCount, route), null);

  const wrongQuestionResult = structuredClone(pending);
  const questionResults = wrongQuestionResult.data.submission.automaticGate.questionResults;
  assert.ok(Array.isArray(questionResults));
  questionResults[2] = {
    questionId: "slot-01",
    correct: true,
    earnedParts: 0,
    maxParts: 1,
    incorrectPartIds: [],
  };
  assert.equal(contract.parseLessonAssessmentSubmissionPayload(wrongQuestionResult, route), null);
});

test("rejects obsolete automatic-only score details on terminal attempts", () => {
  const payloadForScore = (score: Record<string, unknown>, passed: boolean) => {
    const payload = validPayload();
    payload.data.attempt = {
      ...payload.data.attempt,
      status: passed ? "passed" : "failed",
      passedAt: passed ? "2026-08-19T12:00:00Z" : null,
      score,
    };

    return payload;
  };
  const sections = (fundamentals: Record<string, unknown>, diagrams: Record<string, unknown>) => [
    { sectionId: "fundamentals", requiredBps: 6000, ...fundamentals },
    { sectionId: "diagrams", requiredBps: 6000, ...diagrams },
  ];
  const ratio = (numerator: number, denominator: number, percentBps: number, passed: boolean) => ({
    numerator,
    denominator,
    percentBps,
    passed,
  });

  const belowOverall = {
    checked: true,
    passed: false,
    mastered: false,
    overall: { requiredBps: 8000, ...ratio(7999, 10000, 7999, false) },
    sections: sections(ratio(1, 1, 10000, true), ratio(1, 1, 10000, true)),
  };
  assert.equal(contract.parseLessonAssessmentPayload(payloadForScore(belowOverall, false), route), null);

  const failingSection = {
    checked: true,
    passed: false,
    mastered: false,
    overall: { requiredBps: 8000, ...ratio(4, 5, 8000, true) },
    sections: sections(ratio(3, 5, 6000, true), ratio(5999, 10000, 5999, false)),
  };
  assert.equal(contract.parseLessonAssessmentPayload(payloadForScore(failingSection, false), route), null);

  const baselinePass = {
    checked: true,
    passed: true,
    mastered: false,
    overall: { requiredBps: 8000, ...ratio(4, 5, 8000, true) },
    sections: sections(ratio(3, 5, 6000, true), ratio(3, 5, 6000, true)),
  };
  assert.equal(contract.parseLessonAssessmentPayload(payloadForScore(baselinePass, true), route), null);

  const nearMastery = {
    checked: true,
    passed: true,
    mastered: false,
    overall: { requiredBps: 8000, ...ratio(9999, 10000, 9999, true) },
    sections: sections(ratio(1, 1, 10000, true), ratio(9999, 10000, 9999, true)),
  };
  assert.equal(contract.parseLessonAssessmentPayload(payloadForScore(nearMastery, true), route), null);

  const masteryPayload = payloadForScore({
    checked: true,
    passed: true,
    mastered: true,
    overall: { requiredBps: 8000, ...ratio(1, 1, 10000, true) },
    sections: sections(ratio(1, 1, 10000, true), ratio(1, 1, 10000, true)),
  }, true);
  assert.equal(contract.parseLessonAssessmentPayload(masteryPayload, route), null);

  const contradictoryPass = structuredClone(payloadForScore(failingSection, false));
  if (contradictoryPass.data.attempt.score !== undefined) {
    contradictoryPass.data.attempt.score.passed = true;
  }
  assert.equal(contract.parseLessonAssessmentPayload(contradictoryPass, route), null);

  const contradictoryMastery = structuredClone(payloadForScore(nearMastery, true));
  if (contradictoryMastery.data.attempt.score !== undefined) {
    contradictoryMastery.data.attempt.score.mastered = true;
  }
  assert.equal(contract.parseLessonAssessmentPayload(contradictoryMastery, route), null);
});
