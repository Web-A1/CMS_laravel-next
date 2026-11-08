import type {
  LessonAssessmentQuestion,
  TextFieldsQuestion,
} from "@/lib/lessonAssessmentContract";

export const SENTENCE_COMPLETION_SECTION_ID = "fill-blanks";
export const SENTENCE_COMPLETION_TITLE = "Дополните предложение";

export type LessonAssessmentNavigationQuestion = Pick<
  LessonAssessmentQuestion,
  "id" | "prompt" | "sectionId" | "type" | "gradingMode"
>;

export type LessonAssessmentNavigationItem = {
  readonly id: string;
  readonly prompt: string;
  readonly questionIds: ReadonlyArray<string>;
};

export type SentenceCompletionSegment =
  | { readonly kind: "text"; readonly value: string }
  | { readonly kind: "blank"; readonly part: TextFieldsQuestion["parts"][number] };

export function isSentenceCompletionQuestion(
  question: LessonAssessmentQuestion,
): question is TextFieldsQuestion {
  return question.type === "text_fields" && question.sectionId === SENTENCE_COMPLETION_SECTION_ID;
}

export function sentenceCompletionSegments(
  question: TextFieldsQuestion,
): ReadonlyArray<SentenceCompletionSegment> | null {
  const matches = [...question.prompt.matchAll(/_{3,}/gu)];
  if (matches.length !== question.parts.length || matches.length === 0) {
    return null;
  }

  const segments: Array<SentenceCompletionSegment> = [];
  let cursor = 0;
  for (const [index, match] of matches.entries()) {
    const matchIndex = match.index;
    const part = question.parts[index];
    if (matchIndex === undefined || part === undefined) {
      return null;
    }
    if (matchIndex > cursor) {
      segments.push({ kind: "text", value: question.prompt.slice(cursor, matchIndex) });
    }
    segments.push({ kind: "blank", part });
    cursor = matchIndex + match[0].length;
  }

  if (cursor < question.prompt.length) {
    segments.push({ kind: "text", value: question.prompt.slice(cursor) });
  }

  return segments;
}

export function lessonAssessmentNavigationItems(
  questions: ReadonlyArray<LessonAssessmentNavigationQuestion>,
): ReadonlyArray<LessonAssessmentNavigationItem> {
  const firstSentenceQuestionIdByMode = new Map<string, string>();
  const questionIdsByMode = new Map<string, Array<string>>();

  for (const question of questions) {
    if (question.type !== "text_fields" || question.sectionId !== SENTENCE_COMPLETION_SECTION_ID) {
      continue;
    }

    const modeQuestionIds = questionIdsByMode.get(question.gradingMode);
    if (modeQuestionIds === undefined) {
      firstSentenceQuestionIdByMode.set(question.gradingMode, question.id);
      questionIdsByMode.set(question.gradingMode, [question.id]);
      continue;
    }

    modeQuestionIds.push(question.id);
  }

  const groupedSentenceQuestionIds = new Set(
    Array.from(questionIdsByMode.values()).flatMap((questionIds) => questionIds),
  );

  return questions.flatMap((question) => {
    const isSentenceQuestion = groupedSentenceQuestionIds.has(question.id);
    if (isSentenceQuestion && question.id !== firstSentenceQuestionIdByMode.get(question.gradingMode)) {
      return [];
    }
    if (isSentenceQuestion) {
      return [{
        id: question.id,
        prompt: SENTENCE_COMPLETION_TITLE,
        questionIds: questionIdsByMode.get(question.gradingMode) ?? [question.id],
      }];
    }

    return [{
      id: question.id,
      prompt: question.prompt,
      questionIds: [question.id],
    }];
  });
}

export function questionIdsForNavigationItem(
  questions: ReadonlyArray<LessonAssessmentNavigationQuestion>,
  questionId: string | null,
): ReadonlyArray<string> {
  if (questionId === null) {
    return [];
  }

  return (
    lessonAssessmentNavigationItems(questions).find((item) => item.questionIds.includes(questionId))
      ?.questionIds ?? []
  );
}

export function sentenceCompletionQuestionsForNavigationItem(
  questions: ReadonlyArray<LessonAssessmentQuestion>,
  questionId: string | null,
): ReadonlyArray<TextFieldsQuestion> {
  const navigationQuestionIds = new Set(questionIdsForNavigationItem(questions, questionId));

  return questions.filter(
    (question): question is TextFieldsQuestion =>
      navigationQuestionIds.has(question.id) && isSentenceCompletionQuestion(question),
  );
}
