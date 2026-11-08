"use client";

import type {
  LessonAssessmentQuestionResult,
  LessonAssessmentResponse,
  LessonAssessmentRoute,
  TextFieldsQuestion,
} from "@/lib/lessonAssessmentContract";

import { LessonAssessmentTextInput } from "./LessonAssessmentTextInput";
import {
  SENTENCE_COMPLETION_TITLE,
  sentenceCompletionSegments,
} from "./lessonAssessmentSentenceCompletionModel";

export type LessonAssessmentSentenceCompletionEntry = {
  readonly hasError: boolean;
  readonly incorrectPartIds: ReadonlyArray<string>;
  readonly locked: boolean;
  readonly question: TextFieldsQuestion;
  readonly reviewResult?: LessonAssessmentQuestionResult;
  readonly response?: LessonAssessmentResponse;
};

type LessonAssessmentSentenceCompletionProps = {
  readonly acceptedSuggestionValueFor: (questionId: string, partId: string) => string | null;
  readonly apiBaseUrl: string;
  readonly attemptId: number;
  readonly disabled: boolean;
  readonly entries: ReadonlyArray<LessonAssessmentSentenceCompletionEntry>;
  readonly onChange: (questionId: string, response: LessonAssessmentResponse) => void;
  readonly onSuggestionAcceptedChange: (questionId: string, partId: string, value: string | null) => void;
  readonly reviewMode: boolean;
  readonly route: LessonAssessmentRoute;
};

function selectedTexts(response: LessonAssessmentResponse | undefined): Readonly<Record<string, string>> {
  return response !== undefined && "texts" in response ? response.texts : {};
}

export function LessonAssessmentSentenceCompletion({
  acceptedSuggestionValueFor,
  apiBaseUrl,
  attemptId,
  disabled,
  entries,
  onChange,
  onSuggestionAcceptedChange,
  reviewMode,
  route,
}: LessonAssessmentSentenceCompletionProps) {
  return (
    <fieldset className="m-0 min-w-0 border-0 p-0" data-ui="lk.learning.assessment.sentenceCompletion">
      <legend className="p-0 text-xl font-bold leading-tight ui-text-emphasis sm:text-2xl">
        {SENTENCE_COMPLETION_TITLE}
      </legend>
      <ol className="mt-8 grid max-w-5xl list-none gap-3 p-0" aria-label={SENTENCE_COMPLETION_TITLE}>
        {entries.map(({ hasError, incorrectPartIds, locked, question, response, reviewResult }, questionIndex) => {
          const segments = sentenceCompletionSegments(question);
          const texts = selectedTexts(response);

          if (segments === null) {
            return null;
          }

          return (
            <li
              key={question.id}
              className="grid grid-cols-[1.5rem_minmax(0,1fr)] items-baseline gap-2"
              data-state={reviewResult?.correct === true ? "correct" : reviewResult ? "incorrect" : locked ? "locked" : hasError ? "incorrect" : "active"}
              data-ui={`lk.learning.assessment.sentenceCompletion.${question.id}`}
            >
              <span className="text-sm font-medium tabular-nums ui-text-muted-ink" aria-hidden="true">
                {questionIndex + 1}.
              </span>
              <div className="min-w-0 text-sm font-normal leading-6 ui-text-soft-ink">
                {segments.map((segment, segmentIndex) => {
                  if (segment.kind === "text") {
                    return <span key={`text-${segmentIndex}`}>{segment.value}</span>;
                  }

                  const errorId = incorrectPartIds.includes(segment.part.id)
                    ? `assessment-${question.id}-${segment.part.id}-error`
                    : undefined;
                  const reviewState = reviewMode && texts[segment.part.id]?.trim() !== ""
                    ? errorId === undefined ? "correct" : "incorrect"
                    : undefined;

                  return (
                    <span key={segment.part.id} className="inline">
                      <label className="sr-only" htmlFor={`assessment-${question.id}-${segment.part.id}`}>
                        Предложение {questionIndex + 1}, {segment.part.prompt.toLowerCase()}
                      </label>
                      <LessonAssessmentTextInput
                        apiBaseUrl={apiBaseUrl}
                        route={route}
                        attemptId={attemptId}
                        questionId={question.id}
                        partId={segment.part.id}
                        value={texts[segment.part.id] ?? ""}
                        acceptedSuggestionValue={acceptedSuggestionValueFor(question.id, segment.part.id)}
                        variant="inline"
                        disabled={disabled || locked}
                        invalid={errorId !== undefined}
                        describedBy={errorId}
                        reviewState={reviewState}
                        suggestionsEnabled={question.gradingMode === "automatic"}
                        onSuggestionAcceptedChange={(value) =>
                          onSuggestionAcceptedChange(question.id, segment.part.id, value)
                        }
                        onChange={(text) =>
                          onChange(question.id, { texts: { ...texts, [segment.part.id]: text } })
                        }
                      />
                      {errorId ? (
                        <span id={errorId} className="sr-only">
                          Ошибка в этой части ответа
                        </span>
                      ) : null}
                    </span>
                  );
                })}
              </div>
            </li>
          );
        })}
      </ol>
    </fieldset>
  );
}
