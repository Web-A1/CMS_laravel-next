"use client";

import { useEffect, useRef } from "react";
import Link from "next/link";
import { usePathname, useRouter, useSearchParams } from "next/navigation";

import type { LessonAssessmentQuestionResult } from "@/lib/lessonAssessmentContract";

import { assessmentQuestionNavigationLabel } from "./assessmentQuestionNavigationLabel";
import {
  lessonAssessmentNavigationItems,
  type LessonAssessmentNavigationQuestion,
} from "./lessonAssessmentSentenceCompletionModel";
import styles from "./lesson-assessment.module.css";

type LessonAssessmentSidebarProps = {
  readonly initialQuestionId: string | null;
  readonly lockedQuestionIds?: ReadonlyArray<string>;
  readonly questions: ReadonlyArray<LessonAssessmentNavigationQuestion>;
  readonly questionResults?: ReadonlyArray<LessonAssessmentQuestionResult>;
  readonly reviewedManualQuestionIds?: ReadonlyArray<string>;
  readonly reviewMode?: boolean;
  readonly submittedAttempt?: boolean;
};

function buildHref(pathname: string, searchParams: URLSearchParams): string {
  const query = searchParams.toString();
  return query === "" ? pathname : `${pathname}?${query}`;
}

function questionHref(
  pathname: string,
  searchParams: URLSearchParams,
  questionId: string,
  submittedAttempt: boolean,
): string {
  const nextSearchParams = new URLSearchParams(searchParams);
  if (submittedAttempt) {
    nextSearchParams.set("review", "answers");
  }
  nextSearchParams.set("question", questionId);
  return buildHref(pathname, nextSearchParams);
}

function resultHref(pathname: string, searchParams: URLSearchParams): string {
  const nextSearchParams = new URLSearchParams(searchParams);
  nextSearchParams.delete("review");
  nextSearchParams.delete("question");
  return buildHref(pathname, nextSearchParams);
}

export function LessonAssessmentSidebar({
  initialQuestionId,
  lockedQuestionIds = [],
  questionResults = [],
  questions,
  reviewedManualQuestionIds = [],
  reviewMode = false,
  submittedAttempt = false,
}: LessonAssessmentSidebarProps) {
  const pathname = usePathname();
  const router = useRouter();
  const searchParams = useSearchParams();
  const selectedQuestionId = searchParams.get("question") ?? initialQuestionId;
  const navigationSearchParams = new URLSearchParams(searchParams.toString());
  const navigationItems = lessonAssessmentNavigationItems(questions);
  const navigationIndexById = new Map(navigationItems.map((item, index) => [item.id, index]));
  const lockedQuestionIdSet = new Set(lockedQuestionIds);
  const reviewedManualQuestionIdSet = new Set(reviewedManualQuestionIds);
  const resultsByQuestionId = new Map(questionResults.map((result) => [result.questionId, result]));
  const reviewState = (questionIds: ReadonlyArray<string>): "correct" | "incorrect" | "reviewed" | undefined => {
    const results = questionIds.map((questionId) => resultsByQuestionId.get(questionId));
    if (results.every((result) => result !== undefined)) {
      return results.every((result) => result.correct) ? "correct" : "incorrect";
    }
    return questionIds.length > 0 && questionIds.every((questionId) => reviewedManualQuestionIdSet.has(questionId))
      ? "reviewed"
      : undefined;
  };
  const completionState = (questionIds: ReadonlyArray<string>): "completed" | undefined =>
    questionIds.length > 0 && questionIds.every((questionId) => lockedQuestionIdSet.has(questionId))
      ? "completed"
      : undefined;
  const selectedNavigationId = reviewMode
    ? navigationItems.find((item) => selectedQuestionId !== null && item.questionIds.includes(selectedQuestionId))?.id ?? ""
    : submittedAttempt
      ? "__result__"
      : navigationItems.find((item) => selectedQuestionId !== null && item.questionIds.includes(selectedQuestionId))?.id ?? "";
  const questionListRef = useRef<HTMLOListElement>(null);
  const activeLinkRef = useRef<HTMLAnchorElement>(null);

  useEffect(() => {
    const list = questionListRef.current;
    const activeLink = activeLinkRef.current;
    if (list === null || activeLink === null) {
      return;
    }

    const listRect = list.getBoundingClientRect();
    const activeLinkRect = activeLink.getBoundingClientRect();
    const trailingItem = activeLink.parentElement?.nextElementSibling;
    const activeContextBottom = trailingItem instanceof HTMLElement
      ? trailingItem.getBoundingClientRect().bottom
      : activeLinkRect.bottom;
    const paddingBottom = Number.parseFloat(window.getComputedStyle(list).paddingBottom) || 0;
    const visibleBottom = listRect.bottom - paddingBottom;
    if (activeLinkRect.top < listRect.top) {
      list.scrollTop -= listRect.top - activeLinkRect.top;
    } else if (activeContextBottom > visibleBottom) {
      list.scrollTop += activeContextBottom - visibleBottom;
    }
  }, [selectedNavigationId]);

  if (questions.length === 0) {
    return null;
  }

  return (
    <nav
      className={`${styles.assessmentSidebar} lk-learning-assessment-sidebar grid min-w-0 gap-2`}
      aria-labelledby="lesson-assessment-navigation-title"
      data-ui="lk.learning.assessment.sidebar"
    >
      <h2
        id="lesson-assessment-navigation-title"
        className="sr-only lg:not-sr-only lg:text-sm lg:font-semibold lg:leading-5 lg:text-zinc-800"
      >
        Задания
      </h2>
      <div className={`${styles.assessmentSidebarViewport} grid gap-3`}>
        <label className="sr-only" htmlFor="lesson-assessment-mobile-navigation">
          Перейти к вопросу
        </label>
        <select
          id="lesson-assessment-mobile-navigation"
          value={selectedNavigationId}
          className="ui-input h-11 w-full rounded-[var(--radius-control)] border px-3 text-sm outline-none focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--color-brand)] lg:hidden"
          onChange={(event) => {
            router.replace(
              event.target.value === "__result__"
                ? resultHref(pathname, navigationSearchParams)
                : questionHref(pathname, navigationSearchParams, event.target.value, submittedAttempt),
              { scroll: false },
            );
          }}
          data-ui="lk.learning.assessment.sidebar.mobileSelect"
        >
          {navigationItems.map((item) => {
            const itemReviewState = reviewState(item.questionIds);
            const itemCompletionState = completionState(item.questionIds);
            const statusMarker = itemReviewState === "correct"
              ? "Верно: "
              : itemReviewState === "incorrect"
                ? "Неверно: "
                : itemReviewState === "reviewed"
                  ? "Проверено преподавателем: "
                : itemCompletionState === "completed"
                  ? "Зафиксировано: "
                  : "";
            const itemIndex = navigationIndexById.get(item.id) ?? 0;
            return (
              <option key={item.id} value={item.id}>
                {statusMarker}{itemIndex + 1}. {assessmentQuestionNavigationLabel(item.id, item.prompt)}
              </option>
            );
          })}
          {submittedAttempt ? <option value="__result__">Результаты экзамена</option> : null}
        </select>
        <ol
          ref={questionListRef}
          className={`${styles.questionList} hidden grid-cols-1 gap-1 overflow-y-auto overscroll-contain p-0 px-2 lg:grid`}
          data-ui="lk.learning.assessment.sidebar.questionList"
        >
          {navigationItems.map((item) => {
            const isActive = (reviewMode || !submittedAttempt) &&
              selectedQuestionId !== null &&
              item.questionIds.includes(selectedQuestionId);
            const itemReviewState = reviewState(item.questionIds);
            const itemCompletionState = completionState(item.questionIds);
            const itemIndex = navigationIndexById.get(item.id) ?? 0;
            return (
              <li key={item.id}>
                <Link
                  ref={isActive ? activeLinkRef : undefined}
                  href={questionHref(pathname, navigationSearchParams, item.id, submittedAttempt)}
                  scroll={false}
                  className={`${styles.questionLink} ui-pill-control lk-learning-sidebar-pill text-xs font-medium`}
                  aria-current={isActive ? "step" : undefined}
                  data-state={isActive ? "active" : "idle"}
                  data-completion-state={itemCompletionState}
                  data-review-state={itemReviewState}
                  data-ui={isActive ? "lk.learning.assessment.sidebar.question.active" : "lk.learning.assessment.sidebar.question"}
                >
                  <span className={styles.questionLinkContent}>
                    <span className={styles.questionLinkNumber} aria-hidden="true">
                      {itemIndex + 1}.
                    </span>
                    <span className={styles.questionLinkLabel}>
                      {assessmentQuestionNavigationLabel(item.id, item.prompt)}
                    </span>
                  </span>
                  {itemReviewState ? (
                    <span className="sr-only">
                      {itemReviewState === "correct"
                        ? "Верно"
                        : itemReviewState === "incorrect"
                          ? "Неверно"
                          : "Проверено преподавателем"}
                    </span>
                  ) : itemCompletionState === "completed" ? (
                    <span className="sr-only">Ответ зафиксирован</span>
                  ) : null}
                </Link>
              </li>
            );
          })}
          {submittedAttempt ? (
            <li className={styles.resultNavigationItem}>
              <Link
                ref={!reviewMode ? activeLinkRef : undefined}
                href={resultHref(pathname, navigationSearchParams)}
                scroll={false}
                className={`${styles.questionLink} ui-pill-control lk-learning-sidebar-pill text-xs font-medium !text-zinc-700 data-[state=active]:!text-zinc-950`}
                aria-current={!reviewMode ? "step" : undefined}
                data-state={!reviewMode ? "active" : "idle"}
                data-ui={!reviewMode ? "lk.learning.assessment.sidebar.result.active" : "lk.learning.assessment.sidebar.result"}
              >
                <span className="w-full px-2 text-left">Результаты экзамена</span>
              </Link>
            </li>
          ) : null}
        </ol>
      </div>
    </nav>
  );
}
