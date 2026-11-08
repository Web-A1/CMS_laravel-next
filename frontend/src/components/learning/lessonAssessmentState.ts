import type {
  LessonAssessmentAttempt,
  LessonAssessmentData,
  LessonAssessmentQuestion,
  LessonAssessmentResponse,
  LessonAssessmentResponseEntry,
} from "../../lib/lessonAssessmentContract";

export type LessonAssessmentSaveStatus = "idle" | "saving" | "saved" | "error";
export type LessonAssessmentSubmissionStatus = "idle" | "submitting" | "error";

export type LessonAssessmentUiState = {
  readonly assessment: LessonAssessmentData["assessment"];
  readonly attempt: LessonAssessmentAttempt | null;
  readonly responses: Readonly<Record<string, LessonAssessmentResponse>>;
  readonly dirtyQuestionIds: ReadonlyArray<string>;
  readonly questionEditVersions: Readonly<Record<string, number>>;
  readonly currentQuestionId: string | null;
  readonly navigationVersion: number;
  readonly positionDirty: boolean;
  readonly saveStatus: LessonAssessmentSaveStatus;
  readonly submissionStatus: LessonAssessmentSubmissionStatus;
  readonly notice: string | null;
  readonly editVersion: number;
};

export type LessonAssessmentSaveSnapshot = {
  readonly revision: number;
  readonly lastQuestionId: string | null;
  readonly questionIds: ReadonlyArray<string>;
  readonly responses: ReadonlyArray<LessonAssessmentResponseEntry>;
  readonly editVersion: number;
  readonly navigationVersion: number;
};

function responseRecord(
  entries: ReadonlyArray<LessonAssessmentResponseEntry>,
): Readonly<Record<string, LessonAssessmentResponse>> {
  return Object.fromEntries(entries.map((entry) => [entry.questionId, entry.response]));
}

function responsesEqual(
  left: LessonAssessmentResponse | undefined,
  right: LessonAssessmentResponse | undefined,
): boolean {
  if (left === undefined || right === undefined) {
    return left === right;
  }
  if ("selectedOptionId" in left || "selectedOptionId" in right) {
    return "selectedOptionId" in left && "selectedOptionId" in right && left.selectedOptionId === right.selectedOptionId;
  }
  if ("selectedOptionIds" in left || "selectedOptionIds" in right) {
    return (
      "selectedOptionIds" in left &&
      "selectedOptionIds" in right &&
      left.selectedOptionIds.length === right.selectedOptionIds.length &&
      left.selectedOptionIds.every((optionId, index) => optionId === right.selectedOptionIds[index])
    );
  }
  if ("text" in left || "text" in right) {
    return "text" in left && "text" in right && left.text === right.text;
  }
  if ("texts" in left || "texts" in right) {
    if (!("texts" in left) || !("texts" in right)) {
      return false;
    }
    const leftEntries = Object.entries(left.texts).sort(([leftId], [rightId]) => leftId.localeCompare(rightId));
    const rightEntries = Object.entries(right.texts).sort(([leftId], [rightId]) => leftId.localeCompare(rightId));
    return (
      leftEntries.length === rightEntries.length &&
      leftEntries.every(
        ([partId, text], index) => partId === rightEntries[index]?.[0] && text === rightEntries[index]?.[1],
      )
    );
  }
  if (!("selections" in left) || !("selections" in right)) {
    return false;
  }

  const leftEntries = Object.entries(left.selections).sort(([leftId], [rightId]) => leftId.localeCompare(rightId));
  const rightEntries = Object.entries(right.selections).sort(([leftId], [rightId]) => leftId.localeCompare(rightId));
  return (
    leftEntries.length === rightEntries.length &&
    leftEntries.every(
      ([partId, optionId], index) =>
        partId === rightEntries[index]?.[0] && optionId === rightEntries[index]?.[1],
    )
  );
}

function responseMatchesQuestion(
  question: LessonAssessmentQuestion,
  response: LessonAssessmentResponse,
): boolean {
  if (question.type === "text_fields" || question.type === "diagram_text") {
    if (!("texts" in response)) {
      return false;
    }
    const partIds = new Set(question.parts.map((part) => part.id));
    return Object.entries(response.texts).every(
      ([partId, text]) => partIds.has(partId) && typeof text === "string" && text.length <= 500,
    );
  }
  if (question.type === "long_text") {
    return "text" in response && response.text.length <= 10000;
  }

  const optionIds = new Set(question.options.map((option) => option.id));
  if (question.type === "single_choice") {
    return "selectedOptionId" in response && optionIds.has(response.selectedOptionId);
  }
  if (question.type === "multiple_choice" || question.type === "ordered_choice") {
    return (
      "selectedOptionIds" in response &&
      new Set(response.selectedOptionIds).size === response.selectedOptionIds.length &&
      response.selectedOptionIds.every((optionId) => optionIds.has(optionId))
    );
  }
  if (!("selections" in response)) {
    return false;
  }

  const parts = new Map(question.parts.map((part) => [part.id, new Set(part.optionIds)]));
  return Object.entries(response.selections).every(
    ([partId, optionId]) => parts.get(partId)?.has(optionId) === true,
  );
}

export function isLessonAssessmentResponseComplete(
  question: LessonAssessmentQuestion,
  response: LessonAssessmentResponse | undefined,
): boolean {
  if (response === undefined || !responseMatchesQuestion(question, response)) {
    return false;
  }
  if (question.type === "single_choice") {
    return "selectedOptionId" in response;
  }
  if (question.type === "multiple_choice") {
    return "selectedOptionIds" in response && response.selectedOptionIds.length > 0;
  }
  if (question.type === "ordered_choice") {
    return "selectedOptionIds" in response && response.selectedOptionIds.length === question.options.length;
  }
  if (question.type === "text_fields" || question.type === "diagram_text") {
    return (
      "texts" in response &&
      question.parts.every((part) => typeof response.texts[part.id] === "string" && response.texts[part.id].trim() !== "")
    );
  }
  if (question.type === "long_text") {
    return "text" in response && response.text.trim() !== "";
  }
  return (
    "selections" in response &&
    question.parts.every((part) => typeof response.selections[part.id] === "string")
  );
}

function initialVisibleQuestionIds(data: LessonAssessmentData): ReadonlyArray<string> {
  if (data.attempt === null) {
    return [];
  }
  if (data.attempt.status !== "in_progress") {
    return data.assessment.questions.map((question) => question.id);
  }

  const lockedIds = new Set(data.attempt.lockedQuestionIds);
  return data.assessment.questions
    .filter((question) => data.attempt?.submissionCount === 0 || !lockedIds.has(question.id))
    .map((question) => question.id);
}

function initialCurrentQuestionId(
  data: LessonAssessmentData,
  requestedQuestionId: string | null,
): string | null {
  if (data.attempt === null) {
    return null;
  }

  const visibleIds = initialVisibleQuestionIds(data);
  if (requestedQuestionId !== null && visibleIds.includes(requestedQuestionId)) {
    return requestedQuestionId;
  }

  return data.attempt.lastQuestionId !== null && visibleIds.includes(data.attempt.lastQuestionId)
    ? data.attempt.lastQuestionId
    : (visibleIds[0] ?? null);
}

export function createLessonAssessmentState(
  data: LessonAssessmentData,
  requestedQuestionId: string | null = null,
): LessonAssessmentUiState {
  return {
    assessment: data.assessment,
    attempt: data.attempt,
    responses: responseRecord(data.attempt?.responses ?? []),
    dirtyQuestionIds: [],
    questionEditVersions: {},
    currentQuestionId: initialCurrentQuestionId(data, requestedQuestionId),
    navigationVersion: 0,
    positionDirty: false,
    saveStatus: "idle",
    submissionStatus: "idle",
    notice: null,
    editVersion: 0,
  };
}

export function visibleQuestionIds(state: LessonAssessmentUiState): ReadonlyArray<string> {
  if (state.attempt === null) {
    return [];
  }
  if (state.attempt.status !== "in_progress") {
    return state.assessment.questions.map((question) => question.id);
  }
  if (state.attempt.submissionCount === 0) {
    return state.assessment.questions.map((question) => question.id);
  }

  const lockedIds = new Set(state.attempt.lockedQuestionIds);
  return state.assessment.questions
    .filter((question) => !lockedIds.has(question.id))
    .map((question) => question.id);
}

export function selectLessonAssessmentQuestion(
  state: LessonAssessmentUiState,
  questionId: string,
): LessonAssessmentUiState {
  return visibleQuestionIds(state).includes(questionId) && state.currentQuestionId !== questionId
    ? {
        ...state,
        currentQuestionId: questionId,
        navigationVersion: state.navigationVersion + 1,
        positionDirty: state.attempt?.status === "in_progress",
        saveStatus: "idle",
        notice: null,
      }
    : state;
}

export function setAnswer(
  state: LessonAssessmentUiState,
  questionId: string,
  response: LessonAssessmentResponse,
): LessonAssessmentUiState {
  if (
    state.attempt === null ||
    state.attempt.status !== "in_progress" ||
    state.attempt.lockedQuestionIds.includes(questionId)
  ) {
    return state;
  }
  const question = state.assessment.questions.find((candidate) => candidate.id === questionId);
  if (question === undefined || !responseMatchesQuestion(question, response)) {
    return state;
  }
  const existingResponse = state.responses[questionId];
  if (responsesEqual(existingResponse, response) && state.currentQuestionId === questionId) {
    return state;
  }

  const nextEditVersion = state.editVersion + 1;
  return {
    ...state,
    responses: { ...state.responses, [questionId]: response },
    dirtyQuestionIds: state.dirtyQuestionIds.includes(questionId)
      ? state.dirtyQuestionIds
      : [...state.dirtyQuestionIds, questionId],
    questionEditVersions: { ...state.questionEditVersions, [questionId]: nextEditVersion },
    currentQuestionId: questionId,
    saveStatus: "idle",
    submissionStatus: "idle",
    notice: null,
    editVersion: nextEditVersion,
  };
}

export function markLessonAssessmentSaving(state: LessonAssessmentUiState): LessonAssessmentUiState {
  return { ...state, saveStatus: "saving" };
}

export function applySaveError(state: LessonAssessmentUiState): LessonAssessmentUiState {
  return { ...state, saveStatus: "error" };
}

export function dirtyLessonAssessmentResponses(
  state: LessonAssessmentUiState,
  questionIds: ReadonlyArray<string>,
): ReadonlyArray<LessonAssessmentResponseEntry> {
  const allowedQuestionIds = new Set(questionIds);
  return state.dirtyQuestionIds.flatMap((questionId) => {
    if (!allowedQuestionIds.has(questionId)) {
      return [];
    }
    const response = state.responses[questionId];
    return response === undefined ? [] : [{ questionId, response }];
  });
}

export function hasPendingLessonAssessmentChanges(state: LessonAssessmentUiState): boolean {
  return state.dirtyQuestionIds.length > 0 || state.positionDirty;
}

export function createLessonAssessmentSaveSnapshot(
  state: LessonAssessmentUiState,
  questionIds: ReadonlyArray<string>,
): LessonAssessmentSaveSnapshot {
  return {
    revision: state.attempt?.revision ?? 0,
    lastQuestionId: state.currentQuestionId,
    questionIds,
    responses: dirtyLessonAssessmentResponses(state, questionIds),
    editVersion: state.editVersion,
    navigationVersion: state.navigationVersion,
  };
}

export function discardLessonAssessmentChanges(
  state: LessonAssessmentUiState,
  questionIds: ReadonlyArray<string>,
): LessonAssessmentUiState {
  const discardedQuestionIds = new Set(questionIds);
  if (discardedQuestionIds.size === 0) {
    return state;
  }

  const responses = { ...state.responses };
  const savedResponses = responseRecord(state.attempt?.responses ?? []);
  for (const questionId of discardedQuestionIds) {
    const savedResponse = savedResponses[questionId];
    if (savedResponse === undefined) {
      delete responses[questionId];
      continue;
    }
    responses[questionId] = savedResponse;
  }

  return {
    ...state,
    responses,
    dirtyQuestionIds: state.dirtyQuestionIds.filter((questionId) => !discardedQuestionIds.has(questionId)),
    questionEditVersions: Object.fromEntries(
      Object.entries(state.questionEditVersions).filter(([questionId]) => !discardedQuestionIds.has(questionId)),
    ),
    saveStatus: "idle",
    notice: null,
  };
}

function mergeServerStateAfterSnapshot(
  state: LessonAssessmentUiState,
  data: LessonAssessmentData,
  sentSnapshot: LessonAssessmentSaveSnapshot,
): Pick<
  LessonAssessmentUiState,
  "responses" | "dirtyQuestionIds" | "questionEditVersions" | "currentQuestionId" | "positionDirty"
> {
  const submittedQuestionIds = new Set(sentSnapshot.questionIds);
  const dirtyQuestionIds = state.dirtyQuestionIds.filter(
    (questionId) =>
      !submittedQuestionIds.has(questionId) ||
      (state.questionEditVersions[questionId] ?? 0) > sentSnapshot.editVersion,
  );
  const responses = { ...responseRecord(data.attempt?.responses ?? []) };
  for (const questionId of dirtyQuestionIds) {
    const localResponse = state.responses[questionId];
    if (localResponse !== undefined) {
      responses[questionId] = localResponse;
    }
  }
  const questionEditVersions = Object.fromEntries(
    dirtyQuestionIds.map((questionId) => [questionId, state.questionEditVersions[questionId] ?? 0]),
  );

  const positionDirty = state.navigationVersion > sentSnapshot.navigationVersion;
  const serverCurrentQuestionId = initialCurrentQuestionId(data, null);
  const serverVisibleQuestionIds = visibleQuestionIds({
    ...state,
    assessment: data.assessment,
    attempt: data.attempt,
  });
  const localCurrentQuestionVisible =
    state.currentQuestionId !== null && serverVisibleQuestionIds.includes(state.currentQuestionId);

  return {
    responses,
    dirtyQuestionIds,
    questionEditVersions,
    currentQuestionId:
      positionDirty && localCurrentQuestionVisible ? state.currentQuestionId : serverCurrentQuestionId,
    positionDirty,
  };
}

export function applySavedAttempt(
  state: LessonAssessmentUiState,
  data: LessonAssessmentData,
  sentSnapshot: LessonAssessmentSaveSnapshot,
): LessonAssessmentUiState {
  if (data.attempt === null) {
    return applySaveError(state);
  }

  const merged = mergeServerStateAfterSnapshot(state, data, sentSnapshot);
  const hasNewerChanges = merged.dirtyQuestionIds.length > 0 || merged.positionDirty;

  return {
    ...state,
    assessment: data.assessment,
    attempt: data.attempt,
    ...merged,
    saveStatus: hasNewerChanges ? "idle" : "saved",
    notice: hasNewerChanges ? state.notice : null,
  };
}

export function applyConflictReload(
  state: LessonAssessmentUiState,
  data: LessonAssessmentData,
  sentSnapshot: LessonAssessmentSaveSnapshot,
): LessonAssessmentUiState {
  if (data.attempt !== null && data.attempt.status !== "in_progress") {
    return {
      ...createLessonAssessmentState(data),
      saveStatus: "saved",
      submissionStatus: "idle",
      notice: "Состояние обновлено из другой вкладки",
    };
  }

  const merged = mergeServerStateAfterSnapshot(state, data, sentSnapshot);
  const hasNewerChanges = merged.dirtyQuestionIds.length > 0 || merged.positionDirty;
  return {
    ...state,
    assessment: data.assessment,
    attempt: data.attempt,
    ...merged,
    saveStatus: hasNewerChanges ? "idle" : "saved",
    submissionStatus: "idle",
    notice: hasNewerChanges
      ? "Состояние обновлено из другой вкладки; новые изменения ещё не сохранены"
      : "Состояние обновлено из другой вкладки",
  };
}

export function markLessonAssessmentSubmitting(state: LessonAssessmentUiState): LessonAssessmentUiState {
  return { ...state, submissionStatus: "submitting", notice: null };
}

export function applySubmissionError(state: LessonAssessmentUiState): LessonAssessmentUiState {
  return { ...state, submissionStatus: "error" };
}

export function applySubmittedAttempt(
  state: LessonAssessmentUiState,
  data: LessonAssessmentData,
): LessonAssessmentUiState {
  if (data.attempt === null) {
    return state;
  }
  const responses = responseRecord(data.attempt.responses);
  const visibleIds = data.assessment.questions.map((question) => question.id);

  return {
    ...state,
    assessment: data.assessment,
    attempt: data.attempt,
    responses,
    dirtyQuestionIds: [],
    questionEditVersions: {},
    currentQuestionId:
      state.currentQuestionId !== null && visibleIds.includes(state.currentQuestionId)
        ? state.currentQuestionId
        : (visibleIds[0] ?? null),
    positionDirty: false,
    saveStatus: "saved",
    submissionStatus: "idle",
    notice: null,
  };
}

export function incorrectPartIdsForQuestion(
  state: LessonAssessmentUiState,
  questionId: string,
): ReadonlyArray<string> {
  const results = state.attempt !== null && "automaticGate" in state.attempt
    ? state.attempt.automaticGate.questionResults
    : undefined;
  return results?.find((result) => result.questionId === questionId && !result.correct)
    ?.incorrectPartIds ?? [];
}

export function questionHasAssessmentError(
  state: LessonAssessmentUiState,
  questionId: string,
): boolean {
  const results = state.attempt !== null && "automaticGate" in state.attempt
    ? state.attempt.automaticGate.questionResults
    : undefined;
  return results?.some((result) => result.questionId === questionId && !result.correct) ?? false;
}

export function allVisibleQuestionsComplete(state: LessonAssessmentUiState): boolean {
  const visibleIds = new Set(visibleQuestionIds(state));
  return (
    visibleIds.size > 0 &&
    state.assessment.questions.every(
      (question) =>
        !visibleIds.has(question.id) ||
        isLessonAssessmentResponseComplete(question, state.responses[question.id]),
    )
  );
}

export function nextIncompleteVisibleQuestionId(state: LessonAssessmentUiState): string | null {
  const visibleIds = visibleQuestionIds(state);
  const currentIndex = state.currentQuestionId === null ? -1 : visibleIds.indexOf(state.currentQuestionId);
  const orderedIds = currentIndex < 0
    ? visibleIds
    : [...visibleIds.slice(currentIndex + 1), ...visibleIds.slice(0, currentIndex + 1)];

  return orderedIds.find((questionId) => {
    const question = state.assessment.questions.find((candidate) => candidate.id === questionId);
    return question !== undefined && !isLessonAssessmentResponseComplete(question, state.responses[questionId]);
  }) ?? null;
}

export function assessmentProgressLabel(state: LessonAssessmentUiState): string {
  if (state.attempt !== null && "score" in state.attempt) {
    const percent = `${Math.floor(state.attempt.score.overall.percentBps / 100)},${String(
      state.attempt.score.overall.percentBps % 100,
    ).padStart(2, "0")}`;
    return `Результат ${percent} %`;
  }

  const answeredCount = state.assessment.questions.reduce(
    (count, question) =>
      count + (isLessonAssessmentResponseComplete(question, state.responses[question.id]) ? 1 : 0),
    0,
  );
  return `Отвечено ${answeredCount} из ${state.assessment.totalCount}`;
}
