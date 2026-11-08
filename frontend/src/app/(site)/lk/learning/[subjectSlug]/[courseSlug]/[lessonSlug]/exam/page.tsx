import type { Metadata } from "next";
import Link from "next/link";
import { notFound } from "next/navigation";

import { LessonAssessment } from "@/components/learning/LessonAssessment";
import { LearningRouteAnnouncer } from "@/components/learning/LearningPathUi";
import { LessonNavArrowIcon } from "@/components/learning/LessonNavArrowIcon";
import styles from "@/components/learning/lesson-assessment.module.css";
import { accountApiBaseUrl } from "@/lib/accountAuth";
import type { LessonAssessmentRoute } from "@/lib/lessonAssessmentContract";
import { loadLessonAssessment } from "@/lib/lessonAssessmentServer";

type LearningAssessmentPageProps = {
  readonly params: Promise<{ subjectSlug: string; courseSlug: string; lessonSlug: string }>;
  readonly searchParams: Promise<{ question?: string | string[] }>;
};

export const metadata: Metadata = {
  title: "Экзамен по уроку",
  description: "Закрытая проверка знаний по учебному материалу Christmedschool.",
  robots: {
    index: false,
    follow: false,
  },
};

const learningPlainLinkClass =
  "w-fit text-sm font-semibold leading-6 ui-text-tertiary underline-offset-4 ui-text-emphasis-hover hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--color-brand)]";
const learningBackLinkClass =
  "group inline-flex min-h-8 shrink-0 items-center gap-2 text-xs font-medium leading-5 ui-text-muted-ink transition-colors ui-text-emphasis-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--color-brand)]";

export default async function LearningAssessmentPage({ params, searchParams }: LearningAssessmentPageProps) {
  const { subjectSlug, courseSlug, lessonSlug } = await params;
  const requestedQuestionId = (await searchParams).question;
  const lessonPath = `/lk/learning/${encodeURIComponent(subjectSlug)}/${encodeURIComponent(courseSlug)}/${encodeURIComponent(lessonSlug)}`;
  const route: LessonAssessmentRoute = { subjectSlug, courseSlug, lessonSlug };
  const apiBaseUrl = accountApiBaseUrl();
  const assessmentState = await loadLessonAssessment(apiBaseUrl, lessonPath, route);

  if (assessmentState.status === "not_found") {
    notFound();
  }

  const pageTitle = assessmentState.status === "ready"
    ? `Экзамен: ${assessmentState.data.assessment.title}`
    : "Экзамен";

  return (
    <main className={`${styles.assessmentPage} grid min-w-0 gap-3`} data-ui="lk.learning.assessmentPage">
      <header className="grid min-w-0">
        {assessmentState.status === "ready" ? (
          <div className="flex min-w-0 items-center justify-between gap-4">
            <Link href={lessonPath} className={learningBackLinkClass} data-ui="lk.learning.assessmentPage.lessonLink">
              <LessonNavArrowIcon direction="previous" className="-ml-1" />
              <span>К уроку</span>
            </Link>
            <p
              className="min-w-0 truncate text-right text-xs font-normal leading-5 ui-text-muted-ink"
              title={`Экзамен: ${assessmentState.data.assessment.title}`}
              data-ui="lk.learning.assessmentPage.context"
            >
              <span className="font-medium ui-text-tertiary">Экзамен</span>
              <span className="mx-2 ui-text-faint" aria-hidden="true">·</span>
              <span>{assessmentState.data.assessment.title}</span>
            </p>
          </div>
        ) : (
          <h1 id="lk-learning-assessment-title" tabIndex={-1} className="max-w-3xl text-xl font-semibold leading-snug ui-text-primary outline-none sm:text-2xl">
            {pageTitle}
          </h1>
        )}
        {assessmentState.status === "ready" ? (
          <h1 id="lk-learning-assessment-title" tabIndex={-1} className="sr-only">
            {pageTitle}
          </h1>
        ) : null}
        <LearningRouteAnnouncer headingId="lk-learning-assessment-title" title={pageTitle} />
      </header>

      {assessmentState.status === "ready" ? (
        <LessonAssessment
          apiBaseUrl={apiBaseUrl}
          initialData={assessmentState.data}
          lessonPath={lessonPath}
          requestedQuestionId={typeof requestedQuestionId === "string" ? requestedQuestionId : null}
          route={route}
        />
      ) : assessmentState.status === "denied" ? (
        <section
          className="grid gap-3 border-t border-[var(--color-border-subtle)] py-5"
          data-ui="lk.learning.assessmentDenied"
          data-state="denied"
        >
          <h2 className="text-xl font-semibold leading-tight ui-text-primary">Доступ к экзамену не подтверждён</h2>
          <p className="max-w-2xl text-sm leading-6 ui-text-secondary">
            Задания и ответы не раскрываются без действующего доступа к уроку.
          </p>
          <Link href="/lk/learning" className={learningPlainLinkClass} data-ui="lk.learning.assessmentDenied.learningLink">
            К обучению
          </Link>
        </section>
      ) : (
        <section
          className="grid gap-3 border-t border-[var(--color-border-subtle)] py-5"
          data-ui="lk.learning.assessmentError"
          data-state="unavailable"
        >
          <h2 className="text-xl font-semibold leading-tight ui-text-primary">Не удалось загрузить экзамен</h2>
          <p className="max-w-2xl text-sm leading-6 ui-text-secondary">
            Задания скрыты, потому что данные не прошли проверку. Обновите страницу позже.
          </p>
          <Link href={lessonPath} className={learningPlainLinkClass} data-ui="lk.learning.assessmentError.lessonLink">
            Вернуться к уроку
          </Link>
        </section>
      )}
    </main>
  );
}
