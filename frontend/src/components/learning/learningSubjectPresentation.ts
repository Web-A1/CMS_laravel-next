export type LearningAccessValue = "full" | "partial" | "none";

type LearningCoursePresentationInput = {
  readonly access: LearningAccessValue;
  readonly completedLessonsCount?: number;
  readonly ownedLessonsCount: number;
  readonly requiredLessonsCount: number;
};

type LearningSubjectPresentationInput = LearningCoursePresentationInput & {
  readonly coursesCount: number;
};

export type LearningCoursePresentation =
  | {
      readonly kind: "coverage";
      readonly accessibilityLabel: string;
      readonly lessonsLabel: string;
      readonly studyProgressLabel: string;
      readonly studyProgressRatio: number;
      readonly studyProgressValueLabel: string;
    }
  | {
      readonly kind: "zero-lessons";
      readonly accessibilityLabel: null;
      readonly lessonsLabel: "В курсе нет уроков";
      readonly studyProgressLabel: null;
      readonly studyProgressRatio: null;
      readonly studyProgressValueLabel: null;
    }
  | {
      readonly kind: "contract-drift";
      readonly reason: string;
    };

export type LearningSubjectPresentation = "courses" | "no-lessons" | "no-confirmed-courses" | "contract-drift";

function isNonNegativeSafeInteger(value: number): boolean {
  return Number.isSafeInteger(value) && value >= 0;
}

export function lessonCountLabel(count: number): string {
  const absoluteCount = Math.abs(count);
  const lastTwoDigits = absoluteCount % 100;
  const lastDigit = absoluteCount % 10;
  const noun =
    lastTwoDigits >= 11 && lastTwoDigits <= 14
      ? "уроков"
      : lastDigit === 1
        ? "урок"
        : lastDigit >= 2 && lastDigit <= 4
          ? "урока"
          : "уроков";

  return `${count} ${noun}`;
}

export function learningCoursePresentation({
  access,
  completedLessonsCount = 0,
  ownedLessonsCount,
  requiredLessonsCount,
}: LearningCoursePresentationInput): LearningCoursePresentation {
  if (
    !isNonNegativeSafeInteger(completedLessonsCount) ||
    !isNonNegativeSafeInteger(ownedLessonsCount) ||
    !isNonNegativeSafeInteger(requiredLessonsCount) ||
    completedLessonsCount > requiredLessonsCount ||
    ownedLessonsCount > requiredLessonsCount
  ) {
    return { kind: "contract-drift", reason: "Некорректные значения покрытия доступа." };
  }

  if (requiredLessonsCount === 0) {
    return ownedLessonsCount === 0
      ? {
          kind: "zero-lessons",
          accessibilityLabel: null,
          lessonsLabel: "В курсе нет уроков",
          studyProgressLabel: null,
          studyProgressRatio: null,
          studyProgressValueLabel: null,
        }
      : { kind: "contract-drift", reason: "Покрытие не может превышать состав курса." };
  }

  const accessMatchesCounts =
    (access === "full" && ownedLessonsCount === requiredLessonsCount) ||
    (access === "partial" && ownedLessonsCount > 0 && ownedLessonsCount < requiredLessonsCount) ||
    (access === "none" && ownedLessonsCount === 0);

  if (!accessMatchesCounts) {
    return { kind: "contract-drift", reason: "Статус доступа не соответствует количеству доступных уроков." };
  }

  return {
    kind: "coverage",
    accessibilityLabel: `Доступно уроков: ${ownedLessonsCount} из ${requiredLessonsCount}`,
    lessonsLabel: lessonCountLabel(requiredLessonsCount),
    studyProgressLabel:
      completedLessonsCount === requiredLessonsCount
        ? "Курс завершён"
        : `Пройдено уроков: ${completedLessonsCount} из ${requiredLessonsCount}`,
    studyProgressRatio: completedLessonsCount / requiredLessonsCount,
    studyProgressValueLabel: `${Math.round((completedLessonsCount / requiredLessonsCount) * 100)}%`,
  };
}

export function learningSubjectPresentation({
  access,
  coursesCount,
  ownedLessonsCount,
  requiredLessonsCount,
}: LearningSubjectPresentationInput): LearningSubjectPresentation {
  if (
    !isNonNegativeSafeInteger(coursesCount) ||
    !isNonNegativeSafeInteger(ownedLessonsCount) ||
    !isNonNegativeSafeInteger(requiredLessonsCount) ||
    ownedLessonsCount > requiredLessonsCount
  ) {
    return "contract-drift";
  }

  const accessMatchesCounts =
    (requiredLessonsCount === 0 && access === "none" && ownedLessonsCount === 0) ||
    (access === "full" && ownedLessonsCount === requiredLessonsCount && requiredLessonsCount > 0) ||
    (access === "partial" && ownedLessonsCount > 0 && ownedLessonsCount < requiredLessonsCount) ||
    (access === "none" && ownedLessonsCount === 0);

  if (!accessMatchesCounts) {
    return "contract-drift";
  }

  if (coursesCount > 0) {
    return "courses";
  }

  if (requiredLessonsCount === 0 && ownedLessonsCount === 0) {
    return "no-lessons";
  }

  if (
    requiredLessonsCount > 0 &&
    ownedLessonsCount === 0 &&
    access === "none"
  ) {
    return "no-confirmed-courses";
  }

  return "contract-drift";
}
