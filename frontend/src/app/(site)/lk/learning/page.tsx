import type { Metadata } from "next";
import {
  LearningRouteAnnouncer,
  LearningStatePanel,
  LearningSubjectCard,
} from "@/components/learning/LearningPathUi";
import { accountApiBaseUrl } from "@/lib/accountAuth";
import { loadLearningIndex } from "@/lib/learningApi";

export const metadata: Metadata = {
  title: "Обучение",
  description: "Раздел обучения Christmedschool с предметами, доступными по backend-подтверждению.",
  robots: {
    index: false,
    follow: false,
  },
};

export default async function LearningPage() {
  const apiBaseUrl = accountApiBaseUrl();
  const learningAccess = await loadLearningIndex(apiBaseUrl, "/lk/learning");

  return (
    <main className="w-full min-w-0" data-ui="lk.learning">
      <div className="mx-auto grid w-full gap-5">
        <h1 id="lk-learning-title" tabIndex={-1} className="text-2xl font-bold leading-tight ui-text-primary outline-none sm:text-3xl">
          Обучение
        </h1>
        <LearningRouteAnnouncer headingId="lk-learning-title" title="Обучение" />

        {learningAccess.status === "unavailable" ? (
          <LearningStatePanel
            actions={[
              { href: "/lk", label: "Вернуться в кабинет", dataUi: "lk.learning.error.dashboardLink" },
              { href: "/subjects", label: "Выбрать курс", dataUi: "lk.learning.error.subjectsLink", variant: "secondary" },
            ]}
            dataState="unavailable"
            dataUi="lk.learning.error"
            description="Мы не получили подтверждение доступов, поэтому не показываем учебные материалы. Попробуйте обновить страницу позже."
            eyebrow="Доступы временно не загрузились"
            title="Обучение сейчас недоступно"
          />
        ) : learningAccess.data.empty ? (
          <LearningStatePanel
            actions={[
              { href: "/subjects", label: "Выбрать курс", dataUi: "lk.learning.empty.subjectsLink" },
              { href: "/lk", label: "Вернуться в кабинет", dataUi: "lk.learning.empty.dashboardLink", variant: "secondary" },
            ]}
            dataState="empty"
            dataUi="lk.learning.empty"
            description="Когда в программе появятся активные предметы, они будут показаны здесь."
            eyebrow="Учебная программа"
            title="Предметов пока нет"
          >
            <div className="rounded-[var(--radius-inner)] bg-white p-4 text-sm leading-6 ui-text-secondary" data-ui="lk.learning.empty.stateHint">
              Доступ к материалам по-прежнему проверяет сервер при открытии курса и урока.
            </div>
          </LearningStatePanel>
        ) : (
          <>
            <div className="max-w-3xl text-pretty text-base leading-7 ui-text-secondary" data-ui="lk.learning.intro">
              Добро пожаловать на обучающую платформу, дорогой студент. Здесь собраны предметы, которые помогут спокойно двигаться по программе и возвращаться к материалам в удобном темпе. Выберите нужную карточку, чтобы перейти к урокам, доступным по вашему текущему доступу.
            </div>

            <section
              className="subject-grid"
              data-ui="lk.learning.subjectGrid"
              data-state="available"
              aria-label="Доступные предметы"
            >
              {learningAccess.data.subjects.map((subject, index) => (
                <LearningSubjectCard
                  key={subject.id}
                  access={subject.access}
                  boughtCoursesCount={subject.boughtCoursesCount}
                  dataUi="lk.learning.subjectCard"
                  freeLessonAvailable={subject.freeLessonAvailable}
                  href={subject.canonicalPath}
                  ownedLessonsCount={subject.ownedLessonsCount}
                  preloadIllustration={index < 3}
                  sellableCoursesCount={subject.sellableCoursesCount}
                  slug={subject.slug}
                  title={subject.title}
                />
              ))}
            </section>
          </>
        )}
      </div>
    </main>
  );
}
