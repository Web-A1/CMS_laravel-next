import type { Metadata } from "next";
import Link from "next/link";
import { notFound } from "next/navigation";

import { distinctCourseShortTitle } from "@/components/learning/learningCourseCopy";
import { LearningLessonNavigation, LearningRouteAnnouncer } from "@/components/learning/LearningPathUi";
import { accountApiBaseUrl } from "@/lib/accountAuth";
import { loadLearningCourse } from "@/lib/learningApi";

type LearningCoursePageProps = {
  readonly params: Promise<{ subjectSlug: string; courseSlug: string }>;
};

const learningPlainLinkClass =
  "text-sm font-semibold leading-6 ui-text-tertiary underline-offset-4 ui-text-emphasis-hover hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--color-brand)]";
const defaultCourseSummary = "Ниже собраны уроки курса. Откройте доступный урок, чтобы перейти к обучению.";

export const metadata: Metadata = {
  title: "Учебный курс",
  description: "Канонический курс Christmedschool в личном кабинете.",
  robots: { index: false, follow: false },
};

export default async function LearningCoursePage({ params }: LearningCoursePageProps) {
  const { subjectSlug, courseSlug } = await params;
  const coursePath = `/lk/learning/${encodeURIComponent(subjectSlug)}/${encodeURIComponent(courseSlug)}`;
  const courseState = await loadLearningCourse(accountApiBaseUrl(), coursePath, subjectSlug, courseSlug);

  if (courseState.status === "not_found") {
    notFound();
  }

  const courseTitle = courseState.status === "ready" ? courseState.data.course.title : "Обучение";
  const courseSummary =
    courseState.status === "ready"
      ? distinctCourseShortTitle(courseState.data.course.title, courseState.data.course.shortTitle) ?? defaultCourseSummary
      : null;

  return (
    <main className="grid min-w-0 gap-5" data-ui="lk.learning.coursePage">
      <h1 id="lk-learning-course-title" tabIndex={-1} className="text-2xl font-bold leading-tight ui-text-primary outline-none sm:text-3xl">
        {courseTitle}
      </h1>
      <LearningRouteAnnouncer headingId="lk-learning-course-title" title={courseTitle} />

      {courseState.status === "unavailable" ? (
        <section className="grid gap-3 border-t border-[var(--color-border-subtle)] py-5" data-ui="lk.learning.courseError" data-state="unavailable">
          <h2 className="text-xl font-semibold leading-tight ui-text-primary">Не удалось загрузить курс</h2>
          <p className="max-w-2xl text-sm leading-6 ui-text-secondary">
            Навигационные данные не прошли проверку, поэтому список уроков скрыт.
          </p>
          <Link href="/lk/learning" className={learningPlainLinkClass} data-ui="lk.learning.courseError.learningLink">
            К обучению
          </Link>
        </section>
      ) : courseState.data.empty ? (
        <section className="grid gap-3 border-t border-[var(--color-border-subtle)] py-5" data-ui="lk.learning.courseEmpty" data-state="empty">
          <p className="max-w-2xl text-sm leading-6 ui-text-secondary">В этом курсе пока нет активных уроков.</p>
          <Link href={courseState.data.subject.canonicalPath} className={learningPlainLinkClass} data-ui="lk.learning.courseEmpty.subjectLink">
            К предмету
          </Link>
        </section>
      ) : (
        <section className="grid gap-5" data-ui="lk.learning.course" data-state="ready">
          {courseSummary ? (
            <p className="max-w-2xl text-sm leading-6 ui-text-secondary" data-ui="lk.learning.course.summary">
              {courseSummary}
            </p>
          ) : null}

          <section className="grid gap-3" aria-labelledby="lk-learning-course-lessons-title" data-ui="lk.learning.course.lessons">
            <h2 id="lk-learning-course-lessons-title" className="sr-only">
              Уроки
            </h2>
            <LearningLessonNavigation lessons={courseState.data.lessons} variant="list" />
          </section>
        </section>
      )}
    </main>
  );
}
