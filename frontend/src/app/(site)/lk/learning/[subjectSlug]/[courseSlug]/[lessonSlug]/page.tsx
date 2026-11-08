import type { Metadata } from "next";
import Link from "next/link";
import { notFound } from "next/navigation";

import { ButtonRouteLink } from "@/components/button/Button";
import KinescopeVideoPlayer from "@/components/learning/KinescopeVideoPlayer";
import { LearningRouteAnnouncer } from "@/components/learning/LearningPathUi";
import { LessonNavArrowIcon } from "@/components/learning/LessonNavArrowIcon";
import ProtectedFileDeliveryPanel from "@/components/learning/ProtectedFileDeliveryPanel";
import { accountApiBaseUrl, loadAccountProfile } from "@/lib/accountAuth";
import { loadLearningCourse, loadLearningLesson, loadLearningLessonMedia } from "@/lib/learningApi";
import type { LearningCourseLessonNavigation, LearningLessonData, LessonBlock } from "@/lib/learningApi";

import styles from "./lesson-page.module.css";

type LearningLessonPageProps = {
  readonly params: Promise<{ subjectSlug: string; courseSlug: string; lessonSlug: string }>;
};

export const metadata: Metadata = {
  title: "Урок",
  description: "Канонический закрытый учебный материал Christmedschool в личном кабинете.",
  robots: {
    index: false,
    follow: false,
  },
};

const learningPlainLinkClass = "text-sm font-semibold leading-6 ui-text-tertiary underline-offset-4 ui-text-emphasis-hover hover:underline";
const lessonHeadingClass = "text-2xl font-bold leading-tight ui-text-primary outline-none sm:text-3xl";
const lessonNavLinkClass =
  "group inline-flex min-h-8 items-center gap-1 rounded-full text-xs font-medium leading-5 ui-text-muted-ink transition-colors ui-text-emphasis-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--color-brand)]";
const lessonNavDisabledClass = "inline-flex min-h-8 items-center gap-1 rounded-full text-xs font-medium leading-5 ui-text-faint";
const lessonWorkspacePanelClass = "rounded-[var(--radius-surface)] bg-white p-4";

function renderLessonBlock(block: LessonBlock, index: number) {
  switch (block.type) {
    case "callout":
      return (
        <aside key={index} className="border-l-2 border-[var(--color-border-strong)] pl-4 text-sm leading-6 ui-text-tertiary">
          {block.text}
        </aside>
      );
    case "divider":
      return <hr key={index} className="border-[var(--color-border-subtle)]" />;
    case "heading": {
      const className = "scroll-mt-24 text-xl font-semibold leading-tight ui-text-primary";
      if (block.level === 4) {
        return (
          <h4 key={index} id={block.id} className={className}>
            {block.text}
          </h4>
        );
      }

      if (block.level === 3) {
        return (
          <h3 key={index} id={block.id} className={className}>
            {block.text}
          </h3>
        );
      }

      return (
        <h2 key={index} id={block.id} className={className}>
          {block.text}
        </h2>
      );
    }
    case "list":
      return (
        <ul key={index} className="list-disc space-y-2 pl-5 text-sm leading-6 ui-text-tertiary">
          {block.items.map((item, itemIndex) => (
            <li key={`${index}-${itemIndex}`}>{item}</li>
          ))}
        </ul>
      );
    case "paragraph":
      return (
        <p key={index} className="text-sm leading-7 ui-text-tertiary">
          {block.text}
        </p>
      );
    case "quote":
      return (
        <blockquote key={index} className="border-l-2 border-[var(--color-border-strong)] pl-4 text-sm leading-7 ui-text-tertiary">
          {block.text}
        </blockquote>
      );
  }
}

function mediaUnavailableMessage(status: "denied" | "not_found" | "unavailable"): string {
  if (status === "denied") {
    return "Доступ к материалам урока не подтверждён.";
  }

  if (status === "not_found") {
    return "Материалы урока не найдены.";
  }

  return "Материалы урока временно не загрузились. Попробуйте обновить страницу позже.";
}

function TelegramMinimalIcon() {
  return (
    <svg
      className={`${styles.telegramIcon} h-9 w-9 translate-y-1 transition-colors`}
      viewBox="0 0 128 128"
      aria-hidden="true"
      focusable="false"
    >
      <path
        d="M28.97 63.324C47.627 55.196 60.068 49.837 66.293 47.248 84.067 39.855 87.76 38.571 90.167 38.528c.529-.009 1.713.122 2.48.745.647.525.826 1.235.911 1.733.085.498.191 1.633.107 2.519-.963 10.12-5.131 34.678-7.251 46.013-.897 4.796-2.664 6.404-4.374 6.562-3.717.342-6.539-2.456-10.139-4.816-5.633-3.693-8.814-5.993-14.282-9.596-6.319-4.164-2.222-6.452 1.379-10.191.943-.979 17.32-15.874 17.637-17.225.04-.169.076-.799-.298-1.132-.374-.334-.926-.22-1.325-.129-.565.128-9.572 6.082-27.021 17.861-2.556 1.755-4.87 2.609-6.943 2.564-2.285-.049-6.681-1.292-9.949-2.354-4.009-1.303-7.194-1.992-6.916-4.205.145-1.152 1.741-2.32 4.787-3.503Z"
        fill="currentColor"
      />
    </svg>
  );
}

function LessonTeacherChatLink() {
  return (
    <a
      href="#"
      className={`${styles.telegramLink} inline-flex w-fit max-w-2xl items-center rounded-[var(--radius-control)] bg-[var(--color-surface-subtle)] px-4 py-2 text-sm font-medium leading-6 ui-text-secondary transition-colors ui-text-primary-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--color-brand)]`}
      aria-label="Написать вопрос преподавателю в Telegram"
      data-ui="lk.learning.lessonTeacherChat.telegramLink"
    >
      <span className="inline-flex -translate-y-1.5 items-end gap-1.5">
        <span>Вы можете написать вопрос преподавателю в чате Telegram</span>
        <TelegramMinimalIcon />
      </span>
    </a>
  );
}

function formattedTestCompletionDate(timestamp: string): string {
  return new Intl.DateTimeFormat("ru-RU", {
    dateStyle: "long",
    timeZone: "Europe/Moscow",
  }).format(new Date(timestamp));
}

function LessonTestPanel({
  lesson,
  readOnly = false,
}: {
  readonly lesson: LearningLessonData;
  readonly readOnly?: boolean;
}) {
  if (!lesson.testAvailable || lesson.testProgress === null) {
    return null;
  }

  if (readOnly) {
    return (
      <section className={lessonWorkspacePanelClass} data-ui="lk.learning.lessonAssessmentDisabled" data-state="read-only">
        <h2 className="text-base font-semibold leading-tight ui-text-primary">Тест отключён</h2>
        <p className="mt-2 text-sm leading-6 ui-text-secondary">Изменение учебного прогресса недоступно в режиме просмотра.</p>
      </section>
    );
  }

  const passed = lesson.testProgress.status === "passed";
  const pendingReview = lesson.testProgress.status === "pending_review";
  const actionLabel = lesson.testProgress.status === "in_progress"
    ? "Продолжить экзамен"
    : lesson.testProgress.status === "failed"
      ? "Посмотреть результат"
      : pendingReview
        ? "Смотреть результат"
        : "Начать экзамен";

  if (passed) {
    const completedAt = lesson.testProgress.completedAt;
    const passedActionLabel = lesson.testProgress.retakeInProgress
      ? "Продолжить повторное прохождение"
      : "Посмотреть результат";

    return completedAt === null ? null : (
      <section
        className="grid content-start gap-4"
        aria-labelledby="lk-learning-lesson-test-title"
        data-ui="lk.learning.lessonTest"
        data-state="passed"
      >
        <div className="grid gap-3">
          <h2 id="lk-learning-lesson-test-title" className="text-xl font-semibold leading-tight ui-text-emphasis md:text-2xl">
            Проверить знания
          </h2>
          <p className="max-w-sm text-sm font-medium leading-6 ui-text-secondary">
            Результат сохранён. Откройте экзамен, чтобы посмотреть ответы и итоговую оценку.
          </p>
        </div>
        <div className="grid gap-2" data-ui="lk.learning.lessonTest.status" role="status">
          <p className="ui-text-brand text-sm font-semibold">Экзамен сдан</p>
          <p className="ui-text-secondary text-sm">
            Завершён <time dateTime={completedAt}>{formattedTestCompletionDate(completedAt)}</time>
          </p>
        </div>
        <ButtonRouteLink
          href={`${lesson.canonicalPath}/exam`}
          data-ui="lk.learning.lessonTest.resultButton"
        >
          {passedActionLabel}
        </ButtonRouteLink>
      </section>
    );
  }

  return (
    <section
      className="grid content-start gap-4"
      aria-labelledby="lk-learning-lesson-test-title"
      data-ui="lk.learning.lessonTest"
      data-state={lesson.testProgress.status}
    >
      <div className="grid gap-3">
        <h2 id="lk-learning-lesson-test-title" className="text-xl font-semibold leading-tight ui-text-emphasis md:text-2xl">
          Проверить знания
        </h2>
        <p className="max-w-sm text-sm font-medium leading-6 ui-text-secondary">
          {pendingReview
            ? "Ответы на проверке у преподавателя. Предварительный результат уже доступен."
            : "Пройдите экзамен по уроку. Результат сохранится и обновит прогресс курса."}
        </p>
      </div>
      <ButtonRouteLink
        className="w-fit" fullWidth={false}
        href={`${lesson.canonicalPath}/exam`}
        data-ui="lk.learning.lessonTest.startButton"
      >
        {actionLabel}
      </ButtonRouteLink>
    </section>
  );
}

type LessonNavigationProps = {
  readonly nextLesson: LearningCourseLessonNavigation | null;
  readonly nextLessonLabel: string;
  readonly previousLesson: LearningCourseLessonNavigation | null;
  readonly previousLessonLabel: string;
};

function LessonNavigation({
  nextLesson,
  nextLessonLabel,
  previousLesson,
  previousLessonLabel,
}: LessonNavigationProps) {
  return (
    <nav
      className={`${styles.lessonNav} flex items-center justify-between gap-4`}
      aria-label="Навигация по урокам"
      data-ui="lk.learning.lessonWorkspace.navigation"
    >
      {previousLesson && previousLesson.availability !== "locked" ? (
        <Link
          href={previousLesson.canonicalPath}
          className={`${styles.lessonNavItem} ${lessonNavLinkClass} justify-self-start`}
          aria-label={previousLessonLabel}
          title={previousLessonLabel}
          data-ui="lk.learning.lessonMaterial.previousLessonLink"
        >
          <LessonNavArrowIcon direction="previous" variant="chevron" />
          <span className={styles.lessonNavLabel}>Предыдущий урок</span>
        </Link>
      ) : (
        <span
          className={`${styles.lessonNavItem} ${lessonNavDisabledClass} justify-self-start`}
          aria-disabled="true"
          aria-label={previousLessonLabel}
          title={previousLessonLabel}
          data-ui="lk.learning.lessonMaterial.previousLessonDisabled"
        >
          <LessonNavArrowIcon direction="previous" variant="chevron" />
          <span className={styles.lessonNavLabel}>Предыдущий урок</span>
        </span>
      )}
      {nextLesson && nextLesson.availability !== "locked" ? (
        <Link
          href={nextLesson.canonicalPath}
          className={`${styles.lessonNavItem} ${styles.lessonNavItemNext} ${lessonNavLinkClass} justify-self-end`}
          aria-label={nextLessonLabel}
          title={nextLessonLabel}
          data-ui="lk.learning.lessonMaterial.nextLessonLink"
        >
          <span className={styles.lessonNavLabel}>Следующий урок</span>
          <LessonNavArrowIcon direction="next" variant="chevron" />
        </Link>
      ) : (
        <span
          className={`${styles.lessonNavItem} ${styles.lessonNavItemNext} ${lessonNavDisabledClass} justify-self-end`}
          aria-disabled="true"
          aria-label={nextLessonLabel}
          title={nextLessonLabel}
          data-ui="lk.learning.lessonMaterial.nextLessonDisabled"
        >
          <span className={styles.lessonNavLabel}>Следующий урок</span>
          <LessonNavArrowIcon direction="next" variant="chevron" />
        </span>
      )}
    </nav>
  );
}

export default async function LearningLessonPage({ params }: LearningLessonPageProps) {
  const { subjectSlug, courseSlug, lessonSlug } = await params;
  const lessonPath = `/lk/learning/${encodeURIComponent(subjectSlug)}/${encodeURIComponent(courseSlug)}/${encodeURIComponent(lessonSlug)}`;
  const apiBaseUrl = accountApiBaseUrl();
  const account = await loadAccountProfile(apiBaseUrl, lessonPath);
  const isImpersonating = account.status === "authenticated" && account.profile.impersonation?.status === "active";
  const lessonState = await loadLearningLesson(apiBaseUrl, lessonPath, subjectSlug, courseSlug, lessonSlug);

  if (lessonState.status === "not_found") {
    notFound();
  }

  const lessonMediaState =
    lessonState.status === "ready" && !isImpersonating
      ? await loadLearningLessonMedia(apiBaseUrl, lessonPath, subjectSlug, courseSlug, lessonSlug)
      : null;
  const courseState =
    lessonState.status === "ready"
      ? await loadLearningCourse(apiBaseUrl, lessonPath, subjectSlug, courseSlug)
      : null;
  const courseLessons = courseState?.status === "ready" ? courseState.data.lessons : [];
  const currentLessonIndex =
    lessonState.status === "ready"
      ? courseLessons.findIndex((lesson) => lesson.slug === lessonState.data.slug)
      : -1;
  const nextLesson =
    currentLessonIndex >= 0 && currentLessonIndex < courseLessons.length - 1
      ? courseLessons[currentLessonIndex + 1]
      : null;
  const previousLesson = currentLessonIndex > 0 ? courseLessons[currentLessonIndex - 1] : null;
  const lessonData = lessonState.status === "ready" ? lessonState.data : null;
  const hasLessonTest =
    lessonState.status === "ready" &&
    lessonState.data.testAvailable &&
    lessonState.data.testProgress !== null;
  const lessonTitle = lessonData?.title ?? "Обучение";
  const previousLessonLabel = previousLesson ? previousLesson.title : "Предыдущий урок недоступен";
  const nextLessonLabel = nextLesson ? nextLesson.title : "Следующий урок недоступен";

  return (
    <main className="grid min-w-0 gap-5" data-ui="lk.learning.lessonPage">
      <div className="mx-auto grid w-full min-w-0 gap-5">
        {lessonState.status === "ready" ? (
          <article className="grid min-w-0 gap-5" data-ui="lk.learning.lessonMaterial" data-state="ready">
            <header
              className="grid justify-items-start"
              data-ui="lk.learning.lessonMaterial.header"
            >
              <h1
                id="lk-learning-lesson-title"
                tabIndex={-1}
                className={`${lessonHeadingClass} min-w-0 text-left`}
              >
                {lessonTitle}
              </h1>
              {isImpersonating ? (
                <p className="mt-3 max-w-2xl text-sm leading-6 ui-text-secondary" role="status" data-ui="lk.learning.lessonReadOnlyNotice">
                  Режим просмотра пользователя: видео, PDF и изменение учебного прогресса отключены.
                </p>
              ) : null}
            </header>
            <LearningRouteAnnouncer headingId="lk-learning-lesson-title" title={lessonTitle} />

            <section
              className="grid min-w-0 gap-8 md:gap-10"
              data-ui="lk.learning.lessonWorkspace"
            >
              <div className="grid min-w-0 gap-5" data-ui="lk.learning.lessonWorkspace.videoNavStack">
                <div
                  className={`${styles.lessonVideoViewport} min-w-0`}
                  data-ui="lk.learning.lessonWorkspace.video"
                >
                  {isImpersonating ? (
                    <section className={lessonWorkspacePanelClass} data-ui="lk.learning.lessonMediaDisabled" data-state="read-only">
                      <h2 className="text-base font-semibold leading-tight ui-text-primary">Материалы отключены</h2>
                      <p className="mt-2 text-sm leading-6 ui-text-secondary">Видео и PDF недоступны в режиме просмотра пользователя.</p>
                    </section>
                  ) : lessonMediaState?.status === "ready" ? (
                    <KinescopeVideoPlayer
                      apiBaseUrl={apiBaseUrl}
                      subjectSlug={subjectSlug}
                      courseSlug={courseSlug}
                      lessonSlug={lessonSlug}
                      initialState={lessonMediaState}
                    />
                  ) : lessonMediaState ? (
                    <section
                      className={lessonWorkspacePanelClass}
                      data-ui="lk.learning.lessonVideosUnavailable"
                      data-state={lessonMediaState.status}
                    >
                      <h2 className="sr-only">Видео урока</h2>
                      <p className="mt-2 text-sm leading-6 ui-text-secondary">{mediaUnavailableMessage(lessonMediaState.status)}</p>
                    </section>
                  ) : null}
                </div>
                <LessonNavigation
                  nextLesson={nextLesson}
                  nextLessonLabel={nextLessonLabel}
                  previousLesson={previousLesson}
                  previousLessonLabel={previousLessonLabel}
                />
              </div>

              <aside
                className="grid min-w-0 gap-7 md:gap-8"
                aria-label="Материалы и действия урока"
                data-ui="lk.learning.lessonWorkspace.sidePanel"
              >
                <div
                  className={hasLessonTest
                    ? "grid min-w-0 gap-6 lg:grid-cols-[minmax(0,2.75fr)_1px_minmax(19rem,0.75fr)] lg:items-stretch lg:gap-8"
                    : "grid min-w-0 gap-6"}
                  data-ui="lk.learning.lessonWorkspace.actionGrid"
                >
                  <div className="grid min-w-0 gap-6" data-ui="lk.learning.lessonWorkspace.support">
                    {isImpersonating ? (
                      <section className={lessonWorkspacePanelClass} data-ui="lk.learning.lessonFilesDisabled" data-state="read-only">
                        <h2 className="text-base font-semibold leading-tight ui-text-primary">Файлы отключены</h2>
                        <p className="mt-2 text-sm leading-6 ui-text-secondary">Защищённые PDF недоступны в режиме просмотра пользователя.</p>
                      </section>
                    ) : lessonMediaState?.status === "ready" ? (
                      <ProtectedFileDeliveryPanel
                        apiBaseUrl={apiBaseUrl}
                        subjectSlug={subjectSlug}
                        courseSlug={courseSlug}
                        lessonSlug={lessonSlug}
                        files={lessonMediaState.files}
                      />
                    ) : lessonMediaState ? (
                      <section
                        className={lessonWorkspacePanelClass}
                        data-ui="lk.learning.lessonMaterialsUnavailable"
                        data-state={lessonMediaState.status}
                      >
                        <h2 className="sr-only">Материалы</h2>
                        <p className="mt-2 text-sm leading-6 ui-text-secondary">{mediaUnavailableMessage(lessonMediaState.status)}</p>
                      </section>
                    ) : null}
                  </div>
                  {hasLessonTest ? (
                    <>
                      <div
                        className="hidden w-px self-stretch bg-[var(--color-border-subtle)] lg:block"
                        aria-hidden="true"
                        data-ui="lk.learning.lessonWorkspace.divider"
                      />
                      <LessonTestPanel lesson={lessonState.data} readOnly={isImpersonating} />
                    </>
                  ) : null}
                </div>
                <div className="pt-2 md:pt-3" data-ui="lk.learning.lessonTeacherChat">
                  <LessonTeacherChatLink />
                </div>
              </aside>
            </section>

            {lessonState.data.content.status === "published" && lessonState.data.content.body.blocks.length > 0 ? (
              <section className="grid max-w-3xl gap-5 border-t border-[var(--color-border-subtle)] pt-5" data-ui="lk.learning.lessonMaterial.body">
                {lessonState.data.content.body.blocks.map(renderLessonBlock)}
              </section>
            ) : null}
          </article>
        ) : lessonState.status === "denied" ? (
          <>
            <h1 id="lk-learning-lesson-title" tabIndex={-1} className={lessonHeadingClass}>
              {lessonTitle}
            </h1>
            <LearningRouteAnnouncer headingId="lk-learning-lesson-title" title={lessonTitle} />
            <section className="grid gap-3 border-t border-[var(--color-border-subtle)] py-5" data-ui="lk.learning.lessonDenied" data-state="denied">
              <h2 className="text-xl font-semibold leading-tight ui-text-primary">Доступ к уроку не подтверждён</h2>
              <p className="max-w-2xl text-sm leading-6 ui-text-secondary">
                Название, видео, файлы и текст урока не раскрываются без действующего доступа.
              </p>
              <div className="flex flex-wrap gap-4">
                <Link href="/lk/learning" className={learningPlainLinkClass} data-ui="lk.learning.lessonDenied.learningLink">
                  К обучению
                </Link>
                <Link href="/subjects" className={learningPlainLinkClass} data-ui="lk.learning.lessonDenied.subjectsLink">
                  Выбрать курс
                </Link>
              </div>
            </section>
          </>
        ) : (
          <>
            <h1 id="lk-learning-lesson-title" tabIndex={-1} className={lessonHeadingClass}>
              {lessonTitle}
            </h1>
            <LearningRouteAnnouncer headingId="lk-learning-lesson-title" title={lessonTitle} />
            <section className="grid gap-3 border-t border-[var(--color-border-subtle)] py-5" data-ui="lk.learning.lessonError" data-state="unavailable">
              <h2 className="text-xl font-semibold leading-tight ui-text-primary">Не удалось загрузить урок</h2>
              <p className="max-w-2xl text-sm leading-6 ui-text-secondary">
                Ошибка загрузки не означает отказ в доступе. Повторите позже или вернитесь к списку обучения.
              </p>
              <div className="flex flex-wrap gap-4">
                <Link href="/lk/learning" className={learningPlainLinkClass} data-ui="lk.learning.lessonError.learningLink">
                  К обучению
                </Link>
                <Link href="/lk" className={learningPlainLinkClass} data-ui="lk.learning.lessonError.dashboardLink">
                  В кабинет
                </Link>
              </div>
            </section>
          </>
        )}
      </div>
    </main>
  );
}
