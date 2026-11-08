import type { Metadata } from "next";
import { notFound } from "next/navigation";

import {
  LearningCourseCard,
  LearningRouteAnnouncer,
  LearningStatePanel,
} from "@/components/learning/LearningPathUi";
import {
  learningCoursePresentation,
  learningSubjectPresentation,
} from "@/components/learning/learningSubjectPresentation";
import { accountApiBaseUrl } from "@/lib/accountAuth";
import { learningCourseDestination, loadLearningSubject } from "@/lib/learningApi";

type LearningSubjectPageProps = {
  readonly params: Promise<{ subjectSlug: string }>;
};

export const metadata: Metadata = {
  title: "Учебный раздел",
  description: "Канонический раздел обучения Christmedschool в личном кабинете.",
  robots: { index: false, follow: false },
};

export default async function LearningSubjectPage({ params }: LearningSubjectPageProps) {
  const { subjectSlug } = await params;
  const subjectPath = `/lk/learning/${encodeURIComponent(subjectSlug)}`;
  const subjectState = await loadLearningSubject(accountApiBaseUrl(), subjectPath, subjectSlug);

  if (subjectState.status === "not_found") {
    notFound();
  }

  const subjectData = subjectState.status === "ready" ? subjectState.data : null;
  const subjectTitle = subjectData?.subject.title ?? "Обучение";
  const subjectPresentation = subjectData
    ? learningSubjectPresentation({
        access: subjectData.access,
        coursesCount: subjectData.courses.length,
        ownedLessonsCount: subjectData.ownedLessonsCount,
        requiredLessonsCount: subjectData.requiredLessonsCount,
      })
    : null;
  const courses = subjectData
    ? subjectData.courses.map((course) => ({
        course,
        presentation: learningCoursePresentation({
          access: course.access,
          completedLessonsCount: course.completedLessonsCount,
          ownedLessonsCount: course.ownedLessonsCount,
          requiredLessonsCount: course.requiredLessonsCount,
        }),
      }))
    : [];
  const hasCourseContractDrift = courses.some(({ presentation }) => presentation.kind === "contract-drift");

  return (
    <main className="grid min-w-0 gap-5" data-ui="lk.learning.subjectPage">
      <h1 id="lk-learning-subject-title" tabIndex={-1} className="text-2xl font-bold leading-tight ui-text-primary outline-none sm:text-3xl">
        {subjectTitle}
      </h1>
      <LearningRouteAnnouncer headingId="lk-learning-subject-title" title={subjectTitle} />

      {subjectData ? (
        <p className="max-w-2xl text-pretty text-sm leading-6 ui-text-secondary sm:text-base" data-ui="lk.learning.subjectPage.intro">
          Выберите курс, чтобы открыть список уроков или публичную страницу курса.
        </p>
      ) : null}

      {subjectState.status === "unavailable" ? (
        <LearningStatePanel
          actions={[{ href: "/lk/learning", label: "К обучению", dataUi: "lk.learning.subjectError.learningLink" }]}
          dataState="unavailable"
          dataUi="lk.learning.subjectError"
          description="Данные предмета не прошли серверную проверку, поэтому список курсов скрыт."
          eyebrow="Данные временно недоступны"
          title="Не удалось загрузить предмет"
        />
      ) : subjectPresentation === "contract-drift" || hasCourseContractDrift ? (
        <LearningStatePanel
          actions={[{ href: "/lk/learning", label: "К обучению", dataUi: "lk.learning.subjectDrift.learningLink" }]}
          dataState="contract-drift"
          dataUi="lk.learning.subjectDrift"
          description="Данные предмета не прошли строгую проверку. Список скрыт, чтобы не показать неверный доступ."
          eyebrow="Данные не совпали"
          title="Не удалось проверить состав предмета"
        />
      ) : subjectPresentation === "no-lessons" || subjectPresentation === "no-confirmed-courses" ? (
        <LearningStatePanel
          actions={[{ href: "/lk/learning", label: "К обучению", dataUi: "lk.learning.subjectEmpty.learningLink" }]}
          dataState="empty"
          dataUi="lk.learning.subjectEmpty"
          description="Для этого предмета пока нет активных курсов."
          eyebrow={subjectState.data.subject.title}
          title="Курсов пока нет"
        />
      ) : (
        <section className="grid content-start items-start" aria-labelledby="learning-subject-courses" data-ui="lk.learning.subjectCourses">
          <h2 id="learning-subject-courses" className="sr-only">
            Курсы
          </h2>
          <div className="grid grid-cols-1 content-start items-start gap-3" data-ui="lk.learning.subjectCourses.grid" data-state="ready">
            {courses.map(({ course, presentation }) => {
              const locked = course.access === "none" && !course.freeLessonAvailable;

              return (
                <LearningCourseCard
                  key={course.id}
                  access={course.access}
                  dataUi={locked ? "lk.learning.courseCard.locked" : "lk.learning.courseCard"}
                  href={learningCourseDestination(course)}
                  locked={locked}
                  presentation={presentation}
                  shortTitle={course.shortTitle}
                  title={course.title}
                />
              );
            })}
          </div>
        </section>
      )}
    </main>
  );
}
