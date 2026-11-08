"use client";

import Link from "next/link";
import { useEffect, useId, useRef } from "react";
import type { CSSProperties, ReactNode } from "react";

import Button, { ButtonRouteLink } from "@/components/button/Button";
import SubjectCard from "@/components/subject/SubjectCard";
import PillStrip from "@/components/content/PillStrip";
import { SurfaceCard } from "@/components/content/SurfaceCard";

import { distinctCourseShortTitle } from "./learningCourseCopy";
import {
  courseBackgroundImage,
  courseCardImageForAccess,
  courseCardVisualFromHref,
} from "./learningCourseCardVisuals";
import { learningSubjectVisualsBySlug } from "./learningSubjectVisuals";
import type { LearningCoursePresentation } from "./learningSubjectPresentation";
import type { LearningCourseLesson } from "@/lib/learningApi";

export type LearningPathAccess = "full" | "partial" | "none";

export const learningCourseCardDataUi = {
  available: "lk.learning.courseCard",
  locked: "lk.learning.courseCard.locked",
} as const;

type LearningTrailItem = {
  readonly label: string;
  readonly href?: string;
};

type LearningAction = {
  readonly dataUi: string;
  readonly href: string;
  readonly label: string;
  readonly variant?: "primary" | "secondary";
};

type LearningMetric = {
  readonly label: string;
  readonly value: string;
};

type LearningPathHeroProps = {
  readonly actions?: ReadonlyArray<LearningAction>;
  readonly children?: ReactNode;
  readonly dataUi: string;
  readonly description: string;
  readonly eyebrow: string;
  readonly metrics?: ReadonlyArray<LearningMetric>;
  readonly status?: ReactNode;
  readonly title: string;
  readonly trail: ReadonlyArray<LearningTrailItem>;
};

type LearningStatePanelProps = {
  readonly actions: ReadonlyArray<LearningAction>;
  readonly children?: ReactNode;
  readonly dataState: string;
  readonly dataUi: string;
  readonly description: string;
  readonly eyebrow: string;
  readonly title: string;
};

type LearningCoverageProps = {
  readonly dataUi: string;
  readonly owned: number;
  readonly required: number;
};

type LearningStatusPillProps = {
  readonly access: LearningPathAccess;
  readonly dataUi?: string;
  readonly label?: string;
};

type LearningCardProps = {
  readonly access: LearningPathAccess;
  readonly actions: ReadonlyArray<LearningAction>;
  readonly children?: ReactNode;
  readonly dataUi: string;
  readonly eyebrow?: string;
  readonly owned: number;
  readonly required: number;
  readonly title: string;
};

type LearningSubjectCardProps = {
  readonly access: LearningPathAccess;
  readonly boughtCoursesCount: number;
  readonly dataUi: string;
  readonly freeLessonAvailable: boolean;
  readonly href: string;
  readonly ownedLessonsCount: number;
  readonly preloadIllustration?: boolean;
  readonly sellableCoursesCount: number;
  readonly slug: string;
  readonly title: string;
};

type LearningSubjectRailItem = {
  readonly canonicalPath: string;
  readonly id: number;
  readonly slug: string;
  readonly title: string;
};

type LearningSubjectRailProps = {
  readonly activeSlug: string;
  readonly dataUi: string;
  readonly subjects: ReadonlyArray<LearningSubjectRailItem>;
};

type LearningCourseCardProps = {
  readonly access: LearningPathAccess;
  readonly dataUi: (typeof learningCourseCardDataUi)[keyof typeof learningCourseCardDataUi];
  readonly href: string;
  readonly locked?: boolean;
  readonly presentation: LearningCoursePresentation;
  readonly shortTitle: string | null;
  readonly title: string;
};

export type LearningSidebarItem = {
  readonly id: number;
  readonly href: string;
  readonly slug: string;
  readonly title: string;
  readonly locked?: boolean;
};

type LearningSidebarProps = {
  readonly activeSlug: string;
  readonly ariaLabel: string;
  readonly items: ReadonlyArray<LearningSidebarItem>;
  readonly navigationScope: string;
};

type LearningLessonNavigationProps = {
  readonly activeSlug?: string;
  readonly lessons: ReadonlyArray<LearningCourseLesson>;
} & (
  | { readonly navigationScope?: never; readonly variant: "list" }
  | { readonly navigationScope: string; readonly variant: "sidebar" }
);

const nestedLearningStripClassName =
  "lk-learning-sidebar-strip";

const nestedLearningPillClassName =
  "ui-pill-control lk-learning-sidebar-pill";

type LearningSubjectCardVisualState = "free" | "partial";

type LearningSubjectVisualState = {
  readonly progressTrackClassName: string;
};

type LearningSubjectVisualTone = {
  readonly illustrationClassName: string;
};

type LessonStudyState = {
  readonly testLabel: string;
  readonly videoLabel: string;
  readonly videoPercent: number;
};

const freeLessonAvailabilityText = "Доступно: бесплатный урок";
const subjectProgressGradient =
  "linear-gradient(90deg, var(--color-brand) 0%, var(--color-brand) 76%, #bdeca8 100%)";
const subjectDefaultVisualStates = {
  free: {
    progressTrackClassName: "bg-zinc-900/7",
  },
  partial: {
    progressTrackClassName: "bg-zinc-900/12",
  },
} as const satisfies Readonly<Record<LearningSubjectCardVisualState, LearningSubjectVisualState>>;
const subjectVisualTones = {
  free: {
    illustrationClassName: "grayscale saturate-50 opacity-70",
  },
  partial: {
    illustrationClassName: "",
  },
} as const satisfies Readonly<Record<LearningSubjectCardVisualState, LearningSubjectVisualTone>>;
const learningCourseDescriptionsBySlug: Readonly<Record<string, string>> = {
  artrologiya: "Строение суставов, связок и соединений костей на примере верхней конечности, нижней конечности, туловища и черепа.",
};

const learningCourseDescriptionPlaceholder = "Тут будет краткое описание курса ...";

function decodedPathSegment(segment: string): string {
  try {
    return decodeURIComponent(segment);
  } catch {
    return segment;
  }
}

function learningPathSlugsFromHref(href: string): { readonly subjectSlug: string | null; readonly courseSlug: string | null } {
  const learningPathMatch = href.match(/^\/lk\/learning\/([^/?#]+)(?:\/([^/?#]+))?/);

  return {
    subjectSlug: learningPathMatch?.[1] ? decodedPathSegment(learningPathMatch[1]) : null,
    courseSlug: learningPathMatch?.[2] ? decodedPathSegment(learningPathMatch[2]) : null,
  };
}

function courseDescriptionFromHref(href: string): string {
  const { courseSlug } = learningPathSlugsFromHref(href);

  return courseSlug ? learningCourseDescriptionsBySlug[courseSlug] ?? learningCourseDescriptionPlaceholder : learningCourseDescriptionPlaceholder;
}

export function accessLabel(access: LearningPathAccess): string {
  if (access === "full") {
    return "Полный доступ";
  }

  if (access === "partial") {
    return "Частичный доступ";
  }

  return "Доступов пока нет";
}

export function accessHint(access: LearningPathAccess): string {
  if (access === "full") {
    return "Открыт весь доступный состав.";
  }

  if (access === "partial") {
    return "Открыта часть уроков. Закрытые уроки не раскрываются без права доступа.";
  }

  return "Покупка или выдача доступа пока не подтверждена.";
}

export function lockedCount(owned: number, required: number): number {
  return Math.max(0, required - owned);
}

function lessonAvailabilityLabel(availability: LearningCourseLesson["availability"]): string {
  if (availability === "free_preview") {
    return "Бесплатный урок";
  }

  if (availability === "entitled") {
    return "Доступен";
  }

  return "Закрыт";
}

function normalizedLessonVideoProgress(lesson: LearningCourseLesson): number {
  return Math.round(Math.min(100, Math.max(0, lesson.videoProgressPercent ?? 0)));
}

function minuteLabel(value: number): string {
  const lastTwoDigits = value % 100;

  if (lastTwoDigits >= 11 && lastTwoDigits <= 14) {
    return "минут";
  }

  const lastDigit = value % 10;

  if (lastDigit === 1) {
    return "минуту";
  }

  if (lastDigit >= 2 && lastDigit <= 4) {
    return "минуты";
  }

  return "минут";
}

function watchedVideoDurationLabel(seconds: number): string {
  const minutes = Math.floor(seconds / 60);

  return minutes > 0 ? `${minutes} ${minuteLabel(minutes)}` : "меньше минуты";
}

function lessonVideoLabel(lesson: LearningCourseLesson, videoPercent: number): string {
  if (lesson.availability === "locked") {
    return "Видео: недоступно";
  }

  if (lesson.videoWatchedSeconds !== null && lesson.videoWatchedSeconds !== undefined && lesson.videoWatchedSeconds > 0) {
    return `Видео: просмотрено ${watchedVideoDurationLabel(lesson.videoWatchedSeconds)}`;
  }

  return videoPercent > 0 ? `Видео: просмотрено ${videoPercent}%` : "Видео: не начато";
}

function lessonStudyState(lesson: LearningCourseLesson): LessonStudyState {
  if (lesson.availability === "locked") {
    return {
      testLabel: "Экзамен недоступен",
      videoLabel: "Видео: недоступно",
      videoPercent: 0,
    };
  }

  const videoPercent = normalizedLessonVideoProgress(lesson);
  const testLabel = (() => {
    if (!lesson.testAvailable || lesson.testProgress === null) {
      return "Экзамен пока не назначен";
    }

    if (lesson.testProgress.status === "not_started") {
      return "Экзамен не начат";
    }

    if (lesson.testProgress.status === "in_progress") {
      return `Экзамен: ${lesson.testProgress.answeredCount} из ${lesson.testProgress.totalCount}`;
    }

    if (lesson.testProgress.status === "failed") {
      return "Экзамен не сдан";
    }

    if (lesson.testProgress.status === "pending_review") {
      return "Экзамен ожидает проверки";
    }

    return "Экзамен сдан";
  })();

  return {
    testLabel,
    videoLabel: lessonVideoLabel(lesson, videoPercent),
    videoPercent,
  };
}

function renderActions(actions: ReadonlyArray<LearningAction>, dataUi: string) {
  if (actions.length === 0) {
    return null;
  }

  return (
    <div className="flex flex-col gap-3 sm:flex-row sm:items-center" data-ui={`${dataUi}.actions`}>
      {actions.map((action) => (
        <ButtonRouteLink key={`${action.href}-${action.label}`} href={action.href} className="w-full sm:w-fit" fullWidth={false} variant={action.variant} data-ui={action.dataUi}>
          {action.label}
        </ButtonRouteLink>
      ))}
    </div>
  );
}

function subjectHasVerifiedAccess(
  access: LearningPathAccess,
  boughtCoursesCount: number,
  ownedLessonsCount: number,
): boolean {
  return access !== "none" || boughtCoursesCount > 0 || ownedLessonsCount > 0;
}

function subjectCardVisualState(
  access: LearningPathAccess,
  boughtCoursesCount: number,
  ownedLessonsCount: number,
): LearningSubjectCardVisualState {
  return subjectHasVerifiedAccess(access, boughtCoursesCount, ownedLessonsCount) ? "partial" : "free";
}

function subjectAvailabilityText(
  access: LearningPathAccess,
  boughtCoursesCount: number,
  sellableCoursesCount: number,
  ownedLessonsCount: number,
  freeLessonAvailable: boolean,
): string {
  if (boughtCoursesCount > 0) {
    return sellableCoursesCount >= boughtCoursesCount
      ? `Доступно: ${boughtCoursesCount} из ${sellableCoursesCount} курсов`
      : `Доступно курсов: ${boughtCoursesCount}`;
  }

  if (access !== "none" || ownedLessonsCount > 0) {
    return "Доступно: частично открыт доступ";
  }

  if (!freeLessonAvailable) {
    return `Доступно: ${boughtCoursesCount} из ${sellableCoursesCount} курсов`;
  }

  return freeLessonAvailabilityText;
}

function renderSubjectAvailability(availability: string): ReactNode {
  if (availability !== freeLessonAvailabilityText) {
    return availability;
  }

  return (
    <>
      Доступно:{" "}
      <span className="ui-learning-card-free-accent font-semibold">бесплатный урок</span>
    </>
  );
}

export function LearningTrail({ items }: { readonly items: ReadonlyArray<LearningTrailItem> }) {
  return (
    <nav aria-label="Путь обучения" data-ui="lk.learning.pathTrail">
      <ol className="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs font-medium leading-5 ui-text-muted-ink">
        {items.map((item, index) => (
          <li key={`${item.label}-${index}`} className="inline-flex min-w-0 items-center gap-2">
            {index > 0 ? <span aria-hidden="true">/</span> : null}
            {item.href ? (
              <Link href={item.href} className="min-w-0 truncate underline-offset-4 ui-text-soft-ink-hover hover:underline">
                {item.label}
              </Link>
            ) : (
              <span className="min-w-0 truncate ui-text-tertiary">{item.label}</span>
            )}
          </li>
        ))}
      </ol>
    </nav>
  );
}

export function LearningStatusPill({
  access,
  dataUi = "lk.learning.statusPill",
  label,
}: LearningStatusPillProps) {
  return (
    <span
      className="ui-status-pill"
      data-ui={dataUi}
      data-access={access}
    >
      {label ?? accessLabel(access)}
    </span>
  );
}

export function LearningSubjectRail({
  activeSlug,
  dataUi,
  subjects,
}: LearningSubjectRailProps) {
  if (subjects.length === 0) {
    return null;
  }

  return (
    <nav
      className="min-w-0 w-full lg:sticky lg:top-24 lg:self-start lg:pr-3"
      aria-label="Предметы обучения"
      data-ui={dataUi}
    >
      <PillStrip
        ariaLabel="Предметы обучения"
        className={nestedLearningStripClassName}
      >
        {subjects.map((subject) => {
          const isActive = subject.slug === activeSlug;

          return (
            <Link
              key={subject.id}
              className={nestedLearningPillClassName}
              href={subject.canonicalPath}
              aria-current={isActive ? "page" : undefined}
              data-ui={`${dataUi}.item`}
              data-state={isActive ? "active" : "idle"}
            >
              <span className="ui-pill-control__label">{subject.title}</span>
            </Link>
          );
        })}
      </PillStrip>
    </nav>
  );
}

export function LearningCourseCard({
  access,
  dataUi,
  href,
  locked = false,
  presentation,
  shortTitle,
  title,
}: LearningCourseCardProps) {
  if (presentation.kind === "contract-drift") {
    return null;
  }

  const description = distinctCourseShortTitle(title, shortTitle) ?? courseDescriptionFromHref(href);
  const courseVisual = courseCardVisualFromHref(href);
  const selectedCourseImage = courseVisual ? courseCardImageForAccess(courseVisual, access) : null;
  const mutedCourseState = access === "none";
  const showCourseProgress = presentation.kind === "coverage" && !mutedCourseState;
  const selectedCourseImageStyle =
    courseVisual && selectedCourseImage
      ? {
          ...courseVisual.style,
          backgroundImage: courseBackgroundImage(selectedCourseImage),
        }
      : null;
  const titleClassName = mutedCourseState
    ? "ui-card-title min-w-0 text-balance !text-zinc-900/55"
    : "ui-card-title min-w-0 text-balance";
  const accessPillClassName = mutedCourseState
    ? "shrink-0 rounded-full bg-white/55 px-2.5 py-1 text-xs font-medium leading-4 text-zinc-500/75 ring-1 ring-inset ring-black/5 tabular-nums"
    : "shrink-0 rounded-full bg-white/70 px-2.5 py-1 text-xs font-medium leading-4 ui-text-secondary ring-1 ring-inset ring-black/5 tabular-nums";
  const summaryClassName = mutedCourseState
    ? "ui-card-text line-clamp-2 max-w-2xl self-center text-pretty !text-sm !font-normal !leading-5 !text-zinc-500/80"
    : "ui-card-text line-clamp-2 max-w-2xl self-center text-pretty !text-sm !font-normal !leading-5 !ui-text-secondary";
  const metaClassName = mutedCourseState
    ? "ui-meta-text !text-zinc-500/75"
    : "ui-meta-text !text-zinc-700";
  const metaValueClassName = mutedCourseState
    ? "ui-meta-text tabular-nums !font-semibold !text-zinc-500/80"
    : "ui-meta-text tabular-nums !font-semibold !ui-text-secondary";
  const lessonCountClassName = mutedCourseState
    ? "ui-meta-text !text-zinc-500/75"
    : "ui-meta-text !ui-text-secondary";

  return (
    <Link
      className="ui-learning-card-link group block min-w-0"
      href={href}
      aria-label={locked ? `Открыть публичную страницу закрытого курса «${title}»` : `Открыть курс «${title}»`}
      data-ui={`${dataUi}.link`}
      data-state={presentation.kind}
    >
      <SurfaceCard
        className="ui-learning-card min-h-[10rem] overflow-hidden !p-0 lg:min-h-[9rem]"
        data-ui={dataUi}
        data-access={access}
        data-locked={locked ? "true" : undefined}
      >
        <div className="grid w-full sm:grid-cols-[minmax(0,1fr)_10rem] sm:items-stretch lg:grid-cols-[minmax(0,1fr)_12rem]">
          <div className="grid min-w-0 grid-rows-[auto_minmax(0,1fr)_auto] gap-4 p-5 lg:px-5 lg:py-4">
            <div className="flex min-w-0 flex-col items-start justify-start gap-2 sm:flex-row sm:items-start sm:justify-between sm:gap-3">
              <h3 className={titleClassName} data-ui={`${dataUi}.title`}>
                {title}
              </h3>

              {locked ? <span className="sr-only">Курс закрыт</span> : null}

              {presentation.accessibilityLabel ? (
                <p className={accessPillClassName} data-ui={`${dataUi}.access`}>
                  {presentation.accessibilityLabel}
                </p>
              ) : null}
            </div>

            <p className={summaryClassName} data-ui={`${dataUi}.summary`}>
              {description}
            </p>

            <div className="mt-auto grid w-full gap-2">
              {showCourseProgress ? (
                <div className="grid w-full gap-2" data-ui={`${dataUi}.studyProgress`}>
                  <div className="flex items-center justify-between gap-3">
                    <p className={metaClassName}>{presentation.studyProgressLabel}</p>
                    <p className={metaValueClassName} aria-label={`Прогресс обучения ${presentation.studyProgressValueLabel}`}>
                      {presentation.studyProgressValueLabel}
                    </p>
                  </div>
                  <div
                    className="h-1.5 overflow-hidden rounded-full bg-white/80 ring-1 ring-inset ring-black/5"
                    role="progressbar"
                    aria-label={presentation.studyProgressLabel}
                    aria-valuemin={0}
                    aria-valuemax={100}
                    aria-valuenow={Math.round(presentation.studyProgressRatio * 100)}
                    aria-valuetext={presentation.studyProgressValueLabel}
                  >
                    <div
                      className="h-full rounded-full bg-[var(--color-brand)] transition-[width] duration-150 motion-reduce:transition-none"
                      style={{ width: `${Math.round(presentation.studyProgressRatio * 100)}%` }}
                      aria-hidden="true"
                    />
                  </div>
                </div>
              ) : presentation.kind !== "coverage" && !mutedCourseState ? (
                <p className={lessonCountClassName} data-ui={`${dataUi}.lessonCount`}>
                  {presentation.lessonsLabel}
                </p>
              ) : null}
            </div>
          </div>

          <div
            className="pointer-events-none relative hidden overflow-hidden sm:block sm:before:absolute sm:before:bottom-6 sm:before:left-0 sm:before:top-6 sm:before:w-px sm:before:bg-zinc-300/80"
            aria-hidden="true"
            data-ui={`${dataUi}.illustrationPlaceholder`}
            data-visual={selectedCourseImageStyle ? "image" : "empty"}
          >
            {selectedCourseImageStyle ? (
              <span
                className="absolute block bg-contain bg-center bg-no-repeat"
                style={selectedCourseImageStyle}
              />
            ) : null}
          </div>
        </div>
      </SurfaceCard>
    </Link>
  );
}

export function LearningSidebar({ activeSlug, ariaLabel, items, navigationScope }: LearningSidebarProps) {
  if (items.length === 0) {
    return null;
  }

  return (
    <nav
      className="min-w-0 w-full lg:sticky lg:top-24 lg:self-start lg:pr-3"
      aria-label={ariaLabel}
      data-learning-navigation-scope={navigationScope}
      data-ui="lk.learning.sidebar"
    >
      <PillStrip ariaLabel={ariaLabel} className={nestedLearningStripClassName}>
        {items.map((item) => {
          const isActive = item.slug === activeSlug;
          const dataUi = isActive ? "lk.learning.sidebar.item.active" : "lk.learning.sidebar.item";

          return (
            <Link
              key={item.id}
              className={nestedLearningPillClassName}
              href={item.href}
              aria-current={isActive ? "page" : undefined}
              aria-label={item.locked ? `${item.title}. Курс закрыт` : undefined}
              data-active={isActive ? "true" : undefined}
              data-locked={item.locked ? "true" : undefined}
              data-state={isActive ? "active" : "idle"}
              data-ui={dataUi}
            >
              <span className="ui-pill-control__label">{item.title}</span>
              {item.locked ? <span className="sr-only"> — курс закрыт</span> : null}
            </Link>
          );
        })}
      </PillStrip>
    </nav>
  );
}

type LockedLessonDialogProps = {
  readonly active?: boolean;
  readonly lessonTitle: string;
  readonly triggerClassName: string;
  readonly triggerDataUi: string;
  readonly triggerLabel: ReactNode;
};

function LockedLessonDialog({
  active = false,
  lessonTitle,
  triggerClassName,
  triggerDataUi,
  triggerLabel,
}: LockedLessonDialogProps) {
  const dialogRef = useRef<HTMLDialogElement>(null);
  const triggerRef = useRef<HTMLButtonElement>(null);
  const titleId = useId();
  const descriptionId = useId();

  function openDialog(): void {
    dialogRef.current?.showModal();
  }

  function closeDialog(): void {
    dialogRef.current?.close();
  }

  function returnFocus(): void {
    triggerRef.current?.focus();
  }

  return (
    <>
      <button
        ref={triggerRef}
        type="button"
        className={triggerClassName}
        onClick={openDialog}
        aria-label={`${lessonTitle}. Урок закрыт`}
        aria-current={active ? "page" : undefined}
        data-active={active ? "true" : undefined}
        data-locked="true"
        data-state={active ? "active" : "idle"}
        data-ui={triggerDataUi}
      >
        {triggerLabel}
        <span className="sr-only"> — урок закрыт</span>
      </button>
      <dialog
        ref={dialogRef}
        className="m-auto w-[min(28rem,calc(100%-2rem))] rounded-[var(--radius-surface)] border border-[var(--color-border-subtle)] bg-white p-0 ui-text-primary shadow-xl backdrop:bg-zinc-950/40"
        aria-labelledby={titleId}
        aria-describedby={descriptionId}
        onClose={returnFocus}
        data-ui="lk.learning.lesson.lockedDialog"
      >
        <div className="grid gap-4 p-5">
          <h2 id={titleId} className="text-xl font-semibold leading-tight">
            Урок закрыт
          </h2>
          <p id={descriptionId} className="text-sm leading-6 ui-text-secondary">
            Урок «{lessonTitle}» откроется после покупки родительского курса.
          </p>
          <Button
            type="button"
            className="w-full sm:w-fit" fullWidth={false} variant="secondary"
            onClick={closeDialog}
            data-ui="lk.learning.lesson.lockedDialog.close"
          >
            Закрыть
          </Button>
        </div>
      </dialog>
    </>
  );
}

export function LearningLessonNavigation({
  activeSlug,
  lessons,
  navigationScope,
  variant,
}: LearningLessonNavigationProps) {
  const sidebar = variant === "sidebar";

  if (sidebar) {
    return (
      <nav
        className="min-w-0 w-full lg:sticky lg:top-24 lg:self-start lg:pr-3"
        aria-label="Уроки курса"
        data-learning-navigation-scope={navigationScope}
        data-ui="lk.learning.sidebar"
      >
        <PillStrip ariaLabel="Уроки курса" className={nestedLearningStripClassName}>
          {lessons.map((lesson) => {
            const active = lesson.slug === activeSlug;
            const dataUi = active ? "lk.learning.sidebar.item.active" : "lk.learning.sidebar.item";
            const label = <span className="ui-pill-control__label">{lesson.title}</span>;

            return lesson.availability === "locked" ? (
              <LockedLessonDialog
                key={lesson.id}
                active={active}
                lessonTitle={lesson.title}
                triggerClassName={nestedLearningPillClassName}
                triggerDataUi={dataUi}
                triggerLabel={label}
              />
            ) : (
              <Link
                key={lesson.id}
                className={nestedLearningPillClassName}
                href={lesson.canonicalPath}
                aria-current={active ? "page" : undefined}
                data-active={active ? "true" : undefined}
                data-state={active ? "active" : "idle"}
                data-ui={dataUi}
              >
                {label}
              </Link>
            );
          })}
        </PillStrip>
      </nav>
    );
  }

  const itemClassName =
    "ui-learning-card-link block w-full min-w-0 border-0 bg-transparent p-0 text-left";

  const list = (
    <ol className="grid gap-3">
      {lessons.map((lesson, index) => {
        const active = lesson.slug === activeSlug;
        const locked = lesson.availability === "locked";
        const statusLabel = lessonAvailabilityLabel(lesson.availability);
        const studyState = lessonStudyState(lesson);
        const progressFillStyle = {
          background: locked ? "transparent" : subjectProgressGradient,
          width: `${studyState.videoPercent}%`,
        } satisfies CSSProperties;
        const titleClassName = locked
          ? "block min-w-0 truncate text-base font-semibold leading-6 text-zinc-900/55"
          : "block min-w-0 truncate text-base font-semibold leading-6 ui-text-primary";
        const statusClassName = locked
          ? "shrink-0 rounded-full bg-white/55 px-2.5 py-1 text-xs font-medium leading-4 text-zinc-500/75 ring-1 ring-inset ring-black/5"
          : "shrink-0 rounded-full bg-white/70 px-2.5 py-1 text-xs font-medium leading-4 ui-text-secondary ring-1 ring-inset ring-black/5";
        const studyTextClassName = locked ? "text-zinc-500/70" : "ui-text-secondary";
        const testStatusClassName = locked
          ? "w-fit rounded-full bg-white/45 px-2.5 py-1 text-xs font-medium leading-4 text-zinc-500/70 ring-1 ring-inset ring-black/5"
          : lesson.testPassed
            ? "w-fit rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-semibold leading-4 text-emerald-800 ring-1 ring-inset ring-emerald-200/70"
            : "w-fit rounded-full bg-white/70 px-2.5 py-1 text-xs font-medium leading-4 ui-text-secondary ring-1 ring-inset ring-black/5";
        const label = (
          <span className="ui-learning-card grid min-h-28 w-full min-w-0 gap-4 overflow-hidden px-5 py-4">
            <span className="grid min-w-0 gap-3 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-start">
              <span className={titleClassName}>
                <span className="font-medium ui-text-muted-ink">Урок {index + 1}: </span>
                {lesson.title}
              </span>
              <span className="flex flex-wrap items-center gap-2 sm:justify-end">
                <span className={statusClassName}>{statusLabel}</span>
                <span className={testStatusClassName} data-ui="lk.learning.course.lesson.testStatus">
                  {studyState.testLabel}
                </span>
              </span>
            </span>
            <span className="grid gap-2" data-ui="lk.learning.course.lesson.videoProgress">
              <span className={`min-w-0 truncate text-xs font-medium leading-4 ${studyTextClassName}`}>{studyState.videoLabel}</span>
              <span
                className="block h-1.5 overflow-hidden rounded-full bg-zinc-900/8"
                role="progressbar"
                aria-label="Прогресс просмотра видео"
                aria-valuemin={0}
                aria-valuemax={100}
                aria-valuenow={studyState.videoPercent}
                aria-valuetext={studyState.videoLabel}
              >
                <span className="block h-full rounded-full" style={progressFillStyle} />
              </span>
            </span>
          </span>
        );

        return (
          <li key={lesson.id}>
            {locked ? (
              <LockedLessonDialog
                active={active}
                lessonTitle={lesson.title}
                triggerClassName={itemClassName}
                triggerDataUi="lk.learning.course.lesson"
                triggerLabel={label}
              />
            ) : (
              <Link
                className={itemClassName}
                href={lesson.canonicalPath}
                aria-current={active ? "page" : undefined}
                data-active={active ? "true" : undefined}
                data-state={active ? "active" : "idle"}
                data-ui="lk.learning.course.lesson"
              >
                {label}
              </Link>
            )}
          </li>
        );
      })}
    </ol>
  );

  return list;
}

export function LearningRouteAnnouncer({ headingId, title }: { readonly headingId: string; readonly title: string }) {
  useEffect(() => {
    document.getElementById(headingId)?.focus();
  }, [headingId]);

  return (
    <p className="sr-only" aria-live="polite" aria-atomic="true" data-ui="lk.learning.routeAnnouncement">
      Открыта страница: {title}
    </p>
  );
}

export function LearningCoverage({ dataUi, owned, required }: LearningCoverageProps) {
  const safeRequired = Math.max(0, required);
  const safeOwned = Math.min(Math.max(0, owned), safeRequired);
  const percentage = safeRequired === 0 ? 0 : Math.round((safeOwned / safeRequired) * 100);
  const closed = lockedCount(safeOwned, safeRequired);
  const coverageText =
    safeRequired === 0
      ? "На этом уровне пока нет открытого состава для отображения."
      : closed === 0
        ? "Открыт весь состав на этом уровне."
        : `Закрыто уроков: ${closed}. Они появятся после подтверждения доступа.`;

  return (
    <div className="grid gap-2" data-ui={dataUi}>
      <div className="flex items-center justify-between gap-3 text-sm leading-6">
        <span className="font-semibold ui-text-primary">Покрытие доступа</span>
        <span className="tabular-nums ui-text-secondary">
          {safeOwned} из {safeRequired}
        </span>
      </div>
      <div className="h-2 overflow-hidden rounded-full ui-surface-neutral" aria-hidden="true">
        <div className="h-full rounded-full bg-[var(--color-brand)]" style={{ width: `${percentage}%` }} />
      </div>
      <p className="text-sm leading-6 ui-text-secondary">{coverageText}</p>
    </div>
  );
}

export function LearningPathHero({
  actions = [],
  children,
  dataUi,
  description,
  eyebrow,
  metrics = [],
  status,
  title,
  trail,
}: LearningPathHeroProps) {
  return (
    <section
      className="grid gap-5 rounded-[var(--radius-surface)] bg-[var(--color-surface-subtle)] p-5 sm:grid-cols-[minmax(0,1fr)_minmax(16rem,0.48fr)] sm:items-start sm:gap-6"
      aria-labelledby={`${dataUi}.title`}
      data-ui={dataUi}
    >
      <div className="grid gap-4">
        <LearningTrail items={trail} />
        <div className="grid gap-2">
          <p className="text-sm font-semibold leading-6 ui-text-muted-ink">{eyebrow}</p>
          <h2 id={`${dataUi}.title`} className="text-balance text-3xl font-bold leading-tight ui-text-primary">
            {title}
          </h2>
          <p className="max-w-2xl text-pretty text-sm leading-6 ui-text-secondary">{description}</p>
        </div>
        {status ? <div data-ui={`${dataUi}.status`}>{status}</div> : null}
        {renderActions(actions, dataUi)}
      </div>

      {metrics.length > 0 || children ? (
        <aside className="grid gap-4 rounded-[var(--radius-inner)] bg-white p-4" data-ui={`${dataUi}.aside`}>
          {metrics.length > 0 ? (
            <dl className="grid grid-cols-2 gap-3">
              {metrics.map((metric) => (
                <div key={metric.label} className="grid gap-1">
                  <dt className="text-xs font-medium leading-5 ui-text-muted-ink">{metric.label}</dt>
                  <dd className="text-sm font-semibold leading-6 ui-text-primary">{metric.value}</dd>
                </div>
              ))}
            </dl>
          ) : null}
          {children}
        </aside>
      ) : null}
    </section>
  );
}

export function LearningStatePanel({
  actions,
  children,
  dataState,
  dataUi,
  description,
  eyebrow,
  title,
}: LearningStatePanelProps) {
  return (
    <section
      className="grid gap-5 rounded-[var(--radius-surface)] bg-[var(--color-surface-subtle)] p-5"
      aria-labelledby={`${dataUi}.title`}
      data-ui={dataUi}
      data-state={dataState}
    >
      <div className="grid gap-2">
        <p className="text-sm font-semibold leading-6 ui-text-muted-ink">{eyebrow}</p>
        <h2 id={`${dataUi}.title`} className="text-balance text-3xl font-bold leading-tight ui-text-primary">
          {title}
        </h2>
        <p className="max-w-2xl text-pretty text-sm leading-6 ui-text-secondary">{description}</p>
      </div>
      {children}
      {renderActions(actions, dataUi)}
    </section>
  );
}

export function LearningHierarchyCard({
  access,
  actions,
  children,
  dataUi,
  eyebrow,
  owned,
  required,
  title,
}: LearningCardProps) {
  return (
    <article className="ui-learning-card grid gap-4 p-5" data-ui={dataUi} data-access={access}>
      <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div className="min-w-0">
          {eyebrow ? <p className="text-sm leading-6 ui-text-muted-ink">{eyebrow}</p> : null}
          <h2 className="text-balance text-xl font-bold leading-tight ui-text-primary">{title}</h2>
        </div>
        <LearningStatusPill access={access} dataUi={`${dataUi}.access`} />
      </div>
      {children}
      <LearningCoverage dataUi={`${dataUi}.coverage`} owned={owned} required={required} />
      {renderActions(actions, dataUi)}
    </article>
  );
}

export function LearningSubjectCard({
  access,
  boughtCoursesCount,
  dataUi,
  freeLessonAvailable,
  href,
  ownedLessonsCount,
  preloadIllustration = false,
  sellableCoursesCount,
  slug,
  title,
}: LearningSubjectCardProps) {
  const safeSellableCoursesCount = Math.max(0, sellableCoursesCount);
  const safeBoughtCoursesCount = Math.max(0, boughtCoursesCount);
  const progressMaximum = Math.max(safeSellableCoursesCount, safeBoughtCoursesCount, 1);
  const progress = Math.round((safeBoughtCoursesCount / progressMaximum) * 100);
  const safeOwnedLessonsCount = Math.max(0, ownedLessonsCount);
  const availability = subjectAvailabilityText(
    access,
    safeBoughtCoursesCount,
    safeSellableCoursesCount,
    safeOwnedLessonsCount,
    freeLessonAvailable,
  );
  const progressLabel =
    safeSellableCoursesCount >= safeBoughtCoursesCount
      ? `Куплено ${safeBoughtCoursesCount} из ${safeSellableCoursesCount} курсов по предмету ${title}`
      : `Доступно курсов по предмету ${title}: ${safeBoughtCoursesCount}`;
  const visual = learningSubjectVisualsBySlug[slug];
  const visualStateId = subjectCardVisualState(access, safeBoughtCoursesCount, safeOwnedLessonsCount);
  const visualTone = subjectVisualTones[visualStateId];
  const showSubjectProgress = visualStateId === "partial";

  const visualState = subjectDefaultVisualStates[visualStateId];
  const stateImage = visual?.illustration.kind === "image" ? visual.illustration.imageByState?.[visualStateId] : undefined;
  const progressFillStyle: CSSProperties =
    progress > 0
      ? {
          background: subjectProgressGradient,
          clipPath: `inset(0 ${100 - progress}% 0 0)`,
        }
      : { width: "0%" };

  return (
    <SubjectCard
      href={href}
      title={title}
      subjectSlug={slug}
      illustration={visual?.illustration ?? null}
      image={stateImage}
      artworkClassName={stateImage ? "" : visualTone.illustrationClassName}
      preloadIllustration={preloadIllustration}
      dataUi={dataUi}
      data-variant={visualStateId}
      data-access={access}
      aria-label={`Открыть предмет ${title}`}
      footer={<>
        <span className="grid min-w-0 gap-1">
          <span
            className="text-sm leading-5"
            data-ui={`${dataUi}.availability`}
          >
            {renderSubjectAvailability(availability)}
          </span>
        </span>

        {showSubjectProgress ? (
          <span className="grid w-full gap-1" data-ui={`${dataUi}.progressBar`}>
            <span className="sr-only">{progressLabel}</span>
            <span
              className={`h-1.5 overflow-hidden rounded-full ${visualState.progressTrackClassName}`}
              role="progressbar"
              aria-label={progressLabel}
              aria-valuemin={0}
              aria-valuemax={progressMaximum}
              aria-valuenow={safeBoughtCoursesCount}
            >
              <span
                className="block h-full w-full rounded-full transition-[clip-path,width] motion-reduce:transition-none"
                style={progressFillStyle}
                aria-hidden="true"
              />
            </span>
          </span>
        ) : null}
      </>}
    />
  );
}
