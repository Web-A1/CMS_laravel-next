"use client";

import { useLayoutEffect, useRef } from "react";

import type {
  LessonAssessmentQuestion as LessonAssessmentQuestionData,
  LessonAssessmentQuestionResult,
  LessonAssessmentManualResult,
  LessonAssessmentResponse,
  LessonAssessmentRoute,
} from "@/lib/lessonAssessmentContract";

import { LessonAssessmentDiagram } from "./LessonAssessmentDiagram";
import type {
  ProtectedDiagramAsset,
  ProtectedDiagramRegistry,
} from "./lessonAssessmentProtectedImageRegistry";
import { LessonAssessmentTextInput } from "./LessonAssessmentTextInput";
import { assessmentQuestionDisplayPrompt } from "./assessmentQuestionNavigationLabel";
import { groupLessonAssessmentTextParts } from "./lessonAssessmentTextFieldGroups";
import styles from "./lesson-assessment.module.css";

type LessonAssessmentQuestionProps = {
  readonly acceptedSuggestionValueFor: (questionId: string, partId: string) => string | null;
  readonly apiBaseUrl: string;
  readonly attemptId: number;
  readonly disabled?: boolean;
  readonly diagramAssets?: ReadonlyArray<ProtectedDiagramAsset>;
  readonly hasError?: boolean;
  readonly incorrectPartIds?: ReadonlyArray<string>;
  readonly locked?: boolean;
  readonly manualReviewPending?: boolean;
  readonly manualReviewReviewed?: boolean;
  readonly manualReviewResult?: LessonAssessmentManualResult;
  readonly onChange: (response: LessonAssessmentResponse) => void;
  readonly onDiagramAvailabilityChange?: (questionId: string, available: boolean) => void;
  readonly onSuggestionAcceptedChange: (questionId: string, partId: string, value: string | null) => void;
  readonly protectedImageRegistry?: ProtectedDiagramRegistry;
  readonly question: LessonAssessmentQuestionData;
  readonly reviewMode?: boolean;
  readonly reviewResult?: LessonAssessmentQuestionResult;
  readonly response?: LessonAssessmentResponse;
  readonly route: LessonAssessmentRoute;
};

const selectClassName =
  "ui-input mt-2 h-11 w-full rounded-[var(--radius-control)] border px-3 text-sm outline-none focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--color-brand)] disabled:cursor-not-allowed disabled:bg-[var(--color-surface-raised)]";
const ignoreDiagramAvailability = (): void => undefined;

function fitLongTextAreaToContent(element: HTMLTextAreaElement): void {
  element.style.height = "0px";
  element.style.minHeight = "0px";
  element.style.overflowY = "hidden";
  element.style.resize = "none";

  const computedStyle = window.getComputedStyle(element);
  const borderBlockWidth =
    Number.parseFloat(computedStyle.borderTopWidth) +
    Number.parseFloat(computedStyle.borderBottomWidth);
  element.style.height = `${Math.ceil(element.scrollHeight + borderBlockWidth)}px`;
}

function resetLongTextAreaFit(element: HTMLTextAreaElement): void {
  element.style.removeProperty("height");
  element.style.removeProperty("min-height");
  element.style.removeProperty("overflow-y");
  element.style.removeProperty("resize");
}

function selectedOptionId(response: LessonAssessmentResponse | undefined): string {
  return response !== undefined && "selectedOptionId" in response ? response.selectedOptionId : "";
}

function selectedOptionIds(response: LessonAssessmentResponse | undefined): ReadonlyArray<string> {
  return response !== undefined && "selectedOptionIds" in response ? response.selectedOptionIds : [];
}

function selectedParts(response: LessonAssessmentResponse | undefined): Readonly<Record<string, string>> {
  return response !== undefined && "selections" in response ? response.selections : {};
}

function selectedTexts(response: LessonAssessmentResponse | undefined): Readonly<Record<string, string>> {
  return response !== undefined && "texts" in response ? response.texts : {};
}

function selectedLongText(response: LessonAssessmentResponse | undefined): string {
  return response !== undefined && "text" in response ? response.text : "";
}

function updateSelection(
  selections: Readonly<Record<string, string>>,
  partId: string,
  optionId: string,
): Readonly<Record<string, string>> {
  if (optionId !== "") {
    return { ...selections, [partId]: optionId };
  }

  return Object.fromEntries(Object.entries(selections).filter(([candidateId]) => candidateId !== partId));
}

export function LessonAssessmentQuestion({
  acceptedSuggestionValueFor,
  apiBaseUrl,
  attemptId,
  disabled = false,
  diagramAssets = [],
  hasError = false,
  incorrectPartIds = [],
  locked = false,
  manualReviewPending = false,
  manualReviewReviewed = false,
  manualReviewResult,
  onChange,
  onDiagramAvailabilityChange = ignoreDiagramAvailability,
  onSuggestionAcceptedChange,
  protectedImageRegistry,
  question,
  reviewMode = false,
  reviewResult,
  response,
  route,
}: LessonAssessmentQuestionProps) {
  const controlsDisabled = disabled || locked || reviewMode;
  const longTextAreaRef = useRef<HTMLTextAreaElement>(null);
  const longTextValue = question.type === "long_text" ? selectedLongText(response) : "";
  const longTextAutoFitEnabled = question.type === "long_text" && (locked || reviewMode) && longTextValue.trim() !== "";
  const questionErrorId = hasError && !reviewMode ? `assessment-${question.id}-error` : undefined;
  const dimDisabledFieldset = !locked && !reviewMode;
  const fieldsetClassName =
    `m-0 min-w-0 border-0 p-0 disabled:cursor-not-allowed ${dimDisabledFieldset ? "disabled:opacity-75" : ""}`;
  const answerBlockClassName = locked && !reviewMode ? "mt-5 grid gap-3" : "mt-8 grid gap-3";
  const answerControlsClassName = locked && !reviewMode ? "opacity-60" : undefined;
  const wholeQuestionReviewState = reviewResult?.correct === true ? "correct" : reviewResult ? "incorrect" : undefined;
  const manualReviewComment = manualReviewResult?.comment?.trim() || null;
  const reviewClassName = (state: "correct" | "incorrect" | undefined): string =>
    state === "correct" ? styles.reviewAnswerCorrect : state === "incorrect" ? styles.reviewAnswerIncorrect : "";

  useLayoutEffect(() => {
    const textArea = longTextAreaRef.current;
    if (textArea === null) {
      return;
    }

    if (!longTextAutoFitEnabled) {
      resetLongTextAreaFit(textArea);
      return;
    }

    fitLongTextAreaToContent(textArea);

    const handleViewportResize = (): void => {
      fitLongTextAreaToContent(textArea);
    };
    window.addEventListener("resize", handleViewportResize);

    return () => {
      window.removeEventListener("resize", handleViewportResize);
      resetLongTextAreaFit(textArea);
    };
  }, [longTextAutoFitEnabled, longTextValue]);

  function renderQuestionControls() {
    if (question.type === "single_choice") {
      const selectedId = selectedOptionId(response);
      return (
        <div className="grid w-fit gap-1">
          {question.options.map((option) => {
            const selected = selectedId === option.id;
            const optionReviewState = selected ? wholeQuestionReviewState : undefined;
            return (
            <label
              key={option.id}
              className={`flex min-h-9 w-fit items-center gap-2.5 rounded-lg px-2 ui-text-tertiary transition-colors ${reviewMode ? "cursor-default" : "cursor-pointer ui-text-emphasis-hover"} has-checked:text-zinc-950 ${reviewClassName(optionReviewState)}`}
              data-ui={`lk.learning.assessment.question.${question.id}.option.${option.id}`}
              data-state={selected ? "selected" : "idle"}
              data-review-state={optionReviewState}
            >
              <input
                type="radio"
                name={`assessment-${question.id}`}
                value={option.id}
                checked={selectedId === option.id}
                disabled={controlsDisabled}
                className="h-4 w-4 accent-[var(--color-brand)] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--color-brand)]"
                onChange={() => onChange({ selectedOptionId: option.id })}
                data-ui={`lk.learning.assessment.question.${question.id}.option.${option.id}.input`}
              />
              <span className="text-sm font-medium leading-6">{option.label}</span>
            </label>
            );
          })}
        </div>
      );
    }

    if (question.type === "multiple_choice") {
      const selectedIds = selectedOptionIds(response);
      const selectedSet = new Set(selectedIds);
      const compactOptions = question.options.every((option) => option.label.trim().length <= 40);
      return (
        <div className={compactOptions ? "grid gap-2 md:grid-cols-2" : "grid gap-2"}>
          {question.options.map((option) => {
            const selected = selectedSet.has(option.id);
            const optionReviewState = selected ? wholeQuestionReviewState : undefined;
            return (
            <label
              key={option.id}
              className={`flex min-h-12 items-start gap-3 rounded-[var(--radius-control)] border border-[var(--color-border-subtle)] px-4 py-3 ${reviewMode ? "cursor-default" : "cursor-pointer"} ${reviewClassName(optionReviewState)} ${optionReviewState === undefined ? "bg-white has-checked:border-[var(--color-brand)] has-checked:bg-[var(--color-brand-surface)]" : ""}`}
              data-ui={`lk.learning.assessment.question.${question.id}.option.${option.id}`}
              data-state={selected ? "selected" : "idle"}
              data-review-state={optionReviewState}
            >
              <input
                type="checkbox"
                value={option.id}
                checked={selectedSet.has(option.id)}
                disabled={controlsDisabled}
                className="mt-1 h-4 w-4 accent-[var(--color-brand)] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--color-brand)]"
                onChange={() => {
                  const nextSelected = new Set(selectedIds);
                  if (nextSelected.has(option.id)) {
                    nextSelected.delete(option.id);
                  } else {
                    nextSelected.add(option.id);
                  }
                  onChange({
                    selectedOptionIds: question.options
                      .map((candidate) => candidate.id)
                      .filter((optionId) => nextSelected.has(optionId)),
                  });
                }}
                data-ui={`lk.learning.assessment.question.${question.id}.option.${option.id}.input`}
              />
              <span className="text-sm leading-6 ui-text-soft-ink">{option.label}</span>
            </label>
            );
          })}
        </div>
      );
    }

    if (question.type === "ordered_choice") {
      const selectedIds = selectedOptionIds(response);
      return (
        <ol className="grid list-none gap-4 p-0" aria-label="Порядок вариантов">
          {question.options.map((_, index) => (
            <li key={`${question.id}-position-${index + 1}`} className="grid grid-cols-[2rem_1fr] items-start gap-3">
              <span className="ui-text-brand mt-6 text-center text-sm font-bold" aria-hidden="true">
                {index + 1}.
              </span>
              <div>
                <label className="block text-sm font-semibold ui-text-primary" htmlFor={`assessment-${question.id}-position-${index + 1}`}>
                  Позиция {index + 1}
                </label>
                <select
                  id={`assessment-${question.id}-position-${index + 1}`}
                  value={selectedIds[index] ?? ""}
                  disabled={controlsDisabled || index > selectedIds.length}
                  aria-invalid={hasError ? true : undefined}
                  aria-describedby={questionErrorId}
                  className={`${selectClassName} ${reviewClassName(selectedIds[index] === undefined ? undefined : wholeQuestionReviewState)}`}
                  data-review-state={selectedIds[index] === undefined ? undefined : wholeQuestionReviewState}
                  onChange={(event) => {
                    const value = event.target.value;
                    const next = selectedIds.slice(0, index);
                    if (value !== "") {
                      next[index] = value;
                    }
                    onChange({ selectedOptionIds: next });
                  }}
                  data-ui={`lk.learning.assessment.question.${question.id}.position.${index + 1}.select`}
                >
                  <option value="">Выберите вариант</option>
                  {question.options.map((option) => (
                    <option
                      key={option.id}
                      value={option.id}
                      disabled={selectedIds.some((selectedId, selectedIndex) => selectedIndex !== index && selectedId === option.id)}
                    >
                      {option.label}
                    </option>
                  ))}
                </select>
              </div>
            </li>
          ))}
        </ol>
      );
    }

    if (question.type === "slot_choice") {
      const selections = selectedParts(response);
      const optionById = new Map(question.options.map((option) => [option.id, option]));
      return (
        <div className="grid gap-4">
          {question.parts.map((part) => {
            const errorId = incorrectPartIds.includes(part.id)
              ? `assessment-${question.id}-${part.id}-error`
              : undefined;
            const partReviewState = reviewMode && selections[part.id] !== undefined
              ? errorId === undefined ? "correct" : "incorrect"
              : undefined;
            const reviewStatusId = partReviewState === undefined
              ? undefined
              : `assessment-${question.id}-${part.id}-review-status`;
            const describedBy = [errorId, reviewStatusId].filter(Boolean).join(" ") || undefined;
            return (
              <div key={part.id}>
                <label className="block text-sm font-semibold ui-text-primary" htmlFor={`assessment-${question.id}-${part.id}`}>
                  {part.prompt}
                </label>
                <select
                  id={`assessment-${question.id}-${part.id}`}
                  value={selections[part.id] ?? ""}
                  disabled={controlsDisabled}
                  aria-invalid={errorId === undefined ? undefined : true}
                  aria-describedby={describedBy}
                  className={`${selectClassName} ${reviewClassName(partReviewState)}`}
                  data-review-state={partReviewState}
                  onChange={(event) =>
                    onChange({ selections: updateSelection(selections, part.id, event.target.value) })
                  }
                  data-ui={`lk.learning.assessment.question.${question.id}.part.${part.id}.select`}
                >
                  <option value="">Выберите вариант</option>
                  {part.optionIds.map((optionId) => {
                    const option = optionById.get(optionId);
                    return option === undefined ? null : (
                      <option key={option.id} value={option.id}>
                        {option.label}
                      </option>
                    );
                  })}
                </select>
                {reviewStatusId ? (
                  <span id={reviewStatusId} className="sr-only">
                    {partReviewState === "correct" ? "Верный ответ" : "Неверный ответ"}
                  </span>
                ) : null}
                {errorId ? (
                  <p id={errorId} className={reviewMode ? "sr-only" : "ui-text-danger mt-2 text-sm font-medium"}>
                    Ошибка в этой части ответа
                  </p>
                ) : null}
              </div>
            );
          })}
        </div>
      );
    }

    if (question.type === "text_fields") {
      const texts = selectedTexts(response);
      const groupedParts = groupLessonAssessmentTextParts(question.parts);
      const groupMarkers = ["а)", "б)", "в)"] as const;

      const renderTextPart = (
        part: (typeof question.parts)[number],
        marker: string,
      ) => {
        const errorId = incorrectPartIds.includes(part.id)
          ? `assessment-${question.id}-${part.id}-error`
          : undefined;

        return (
          <li key={part.id} className="grid grid-cols-[1rem_minmax(0,1fr)] items-center gap-1">
            <span className="text-sm font-medium ui-text-muted-ink" aria-hidden="true">
              {marker}
            </span>
            <div>
              <label className="sr-only" htmlFor={`assessment-${question.id}-${part.id}`}>
                {part.prompt}
              </label>
              <LessonAssessmentTextInput
                apiBaseUrl={apiBaseUrl}
                route={route}
                attemptId={attemptId}
                questionId={question.id}
                partId={part.id}
                value={texts[part.id] ?? ""}
                acceptedSuggestionValue={acceptedSuggestionValueFor(question.id, part.id)}
                disabled={controlsDisabled}
                invalid={errorId !== undefined}
                describedBy={errorId}
                reviewState={reviewMode && texts[part.id]?.trim() !== ""
                  ? errorId === undefined ? "correct" : "incorrect"
                  : undefined}
                suggestionsEnabled={question.gradingMode === "automatic"}
                onSuggestionAcceptedChange={(value) =>
                  onSuggestionAcceptedChange(question.id, part.id, value)
                }
                onChange={(text) => onChange({ texts: { ...texts, [part.id]: text } })}
              />
              {errorId ? (
                <p id={errorId} className="sr-only">
                  Ошибка в этой части ответа
                </p>
              ) : null}
            </div>
          </li>
        );
      };

      if (groupedParts !== null) {
        return (
          <div
            className="grid max-w-4xl gap-x-6 gap-y-5 md:grid-cols-[minmax(0,0.9fr)_minmax(0,1.05fr)_minmax(0,1.2fr)]"
            role="group"
            aria-label="Группы классификации"
          >
            {groupedParts.map((group, groupIndex) => {
              const headingId = `assessment-${question.id}-group-${groupIndex + 1}`;
              const marker = groupMarkers[groupIndex] ?? `${groupIndex + 1}.`;

              return (
                <section
                  key={group.label}
                  className="grid min-w-0 content-start gap-2"
                  aria-labelledby={headingId}
                >
                  <h3
                    id={headingId}
                    className="flex items-baseline gap-1.5 whitespace-nowrap text-sm font-normal leading-5 ui-text-secondary"
                  >
                    <span className="shrink-0 ui-text-tertiary" aria-hidden="true">
                      {marker}
                    </span>
                    <span>{group.label}:</span>
                  </h3>
                  <ol className="grid list-none gap-1 p-0" aria-label={group.label}>
                    {group.parts.map((part, partIndex) => renderTextPart(part, `${partIndex + 1}.`))}
                  </ol>
                </section>
              );
            })}
          </div>
        );
      }

      return (
        <ol className="grid max-w-2xl list-none gap-1 p-0" aria-label="Строки ответа">
          {question.parts.map((part, index) => renderTextPart(part, `${index + 1}.`))}
        </ol>
      );
    }

    if (question.type === "long_text") {
      const errorId = incorrectPartIds.includes("answer") ? `assessment-${question.id}-answer-error` : undefined;
      return (
        <div>
          <label className="sr-only" htmlFor={`assessment-${question.id}-answer`}>
            Развёрнутый ответ
          </label>
          <textarea
            ref={longTextAreaRef}
            id={`assessment-${question.id}-answer`}
            value={longTextValue}
            rows={question.rows}
            maxLength={10000}
            disabled={controlsDisabled}
            aria-invalid={errorId === undefined ? undefined : true}
            aria-describedby={errorId}
            className={`ui-input ui-text-answer min-h-28 w-full resize-y rounded-[var(--radius-control)] border px-3 py-2 text-sm leading-6 outline-none focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--color-brand)] disabled:cursor-not-allowed disabled:opacity-100 ${reviewClassName(longTextValue.trim() === "" ? undefined : wholeQuestionReviewState)}`}
            data-review-state={longTextValue.trim() === "" ? undefined : wholeQuestionReviewState}
            placeholder="Введите развёрнутый ответ"
            onChange={(event) => onChange({ text: event.target.value })}
            data-ui={`lk.learning.assessment.question.${question.id}.answer.textarea`}
          />
          {errorId ? (
            <p id={errorId} className={reviewMode ? "sr-only" : "ui-text-danger mt-2 text-sm font-medium"}>
              В ответе не хватает обязательных смысловых элементов
            </p>
          ) : null}
        </div>
      );
    }

    const values = question.type === "diagram_text" ? selectedTexts(response) : selectedParts(response);
    if (protectedImageRegistry === undefined) {
      return null;
    }
    return (
      <LessonAssessmentDiagram
        acceptedSuggestionValueFor={acceptedSuggestionValueFor}
        apiBaseUrl={apiBaseUrl}
        route={route}
        attemptId={attemptId}
        assets={diagramAssets}
        registry={protectedImageRegistry}
        question={question}
        values={values}
        incorrectPartIds={incorrectPartIds}
        disabled={controlsDisabled}
        reviewMode={reviewMode}
        onAvailabilityChange={onDiagramAvailabilityChange}
        onSuggestionAcceptedChange={onSuggestionAcceptedChange}
        onChange={(partId, value) => onChange(
          question.type === "diagram_text"
            ? { texts: { ...values, [partId]: value } }
            : { selections: updateSelection(values, partId, value) },
        )}
      />
    );
  }

  return (
    <section
      className="min-w-0"
      data-ui={`lk.learning.assessment.question.${question.id}`}
      data-state={wholeQuestionReviewState ?? (manualReviewResult || manualReviewReviewed ? "reviewed" : manualReviewPending ? "pending-review" : locked ? "locked" : hasError ? "incorrect" : "active")}
    >
      <fieldset
        disabled={controlsDisabled}
        className={fieldsetClassName}
        aria-describedby={questionErrorId}
      >
        <legend className="p-0 text-xl font-bold leading-tight ui-text-emphasis sm:text-2xl">
          {assessmentQuestionDisplayPrompt(question.prompt)}
        </legend>
        <div className={answerBlockClassName}>
          {manualReviewResult ? (
            <div
              className="grid gap-2 border-l-2 border-[var(--color-brand)] pl-4"
              data-ui={`lk.learning.assessment.question.${question.id}.manualReviewResult`}
            >
              <p className="text-sm font-medium leading-5 ui-text-emphasis">
                Результат проверки:{" "}
                <span className="ui-text-brand font-semibold tabular-nums">
                  {Math.floor(manualReviewResult.scoreBps / 100)}%
                </span>
              </p>
              {manualReviewComment ? (
                <div className="grid gap-1" data-ui={`lk.learning.assessment.question.${question.id}.teacherComment`}>
                  <p className="text-sm font-medium leading-5 ui-text-emphasis">Комментарий преподавателя</p>
                  <p className="ui-text-secondary whitespace-pre-wrap text-sm leading-6">{manualReviewComment}</p>
                </div>
              ) : null}
            </div>
          ) : reviewResult ? (
            <p
              className="sr-only"
              data-ui={`lk.learning.assessment.question.${question.id}.reviewStatus`}
            >
              {reviewResult.correct ? "Верно" : "Неверно"}
            </p>
          ) : manualReviewReviewed ? (
            <p className="ui-text-brand text-sm font-normal leading-5" data-ui={`lk.learning.assessment.question.${question.id}.manualReviewStatus`}>
              Проверено преподавателем
            </p>
          ) : manualReviewPending ? (
            <p className="ui-text-secondary text-sm font-normal leading-5" data-ui={`lk.learning.assessment.question.${question.id}.manualReviewStatus`}>
              Ожидает ручной проверки
            </p>
          ) : locked ? (
            <p className="ui-text-brand text-sm font-normal leading-5" data-ui={`lk.learning.assessment.question.${question.id}.lockedStatus`}>
              Ответы зафиксированы
            </p>
          ) : null}
          <div className={answerControlsClassName}>
            {renderQuestionControls()}
          </div>
        </div>
      </fieldset>

      {questionErrorId ? (
        <p id={questionErrorId} className="ui-text-danger mt-4 text-sm font-semibold" role="alert" data-ui={`lk.learning.assessment.question.${question.id}.error`}>
          Ответ пока неверный. Проверьте отмеченные части и попробуйте снова.
        </p>
      ) : null}
    </section>
  );
}
