import "server-only";

import { cache } from "react";

import { accountBackendRequest } from "@/lib/accountAuth";
import { rethrowNextDynamicServerError } from "@/lib/apiErrors";

export type LearningAccessLevel = "full" | "partial";
export type LearningHierarchyAccessLevel = LearningAccessLevel | "none";

export type LearningSubject = {
  readonly id: number;
  readonly slug: string;
  readonly title: string;
};

export type LearningCanonicalSubject = LearningSubject & {
  readonly canonicalPath: string;
};

export type LearningIndexSubject = LearningCanonicalSubject & {
  readonly sortOrder: number;
  readonly access: LearningHierarchyAccessLevel;
  readonly requiredLessonsCount: number;
  readonly ownedLessonsCount: number;
  readonly sellableCoursesCount: number;
  readonly boughtCoursesCount: number;
  readonly freeLessonAvailable: boolean;
};

export type LearningSubjectCourse = {
  readonly id: number;
  readonly itemId: `course:${string}:${string}`;
  readonly slug: string;
  readonly title: string;
  readonly shortTitle: string | null;
  readonly sortOrder: number;
  readonly publicPath: string;
  readonly access: LearningHierarchyAccessLevel;
  readonly requiredLessonsCount: number;
  readonly ownedLessonsCount: number;
  readonly completedLessonsCount: number;
  readonly freeLessonAvailable: boolean;
  readonly canonicalPath: string;
};

export type LearningCanonicalCourse = {
  readonly id: number;
  readonly itemId: string;
  readonly slug: string;
  readonly title: string;
  readonly shortTitle: string | null;
  readonly sortOrder: number;
  readonly canonicalPath: string;
  readonly publicPath: string;
  readonly freeLessonAvailable: boolean;
};

export type LearningTestProgress = {
  readonly status: "not_started" | "in_progress" | "failed" | "pending_review" | "passed";
  readonly answeredCount: number;
  readonly correctCount: number;
  readonly totalCount: number;
  readonly score: LearningTestScore | null;
  readonly mastered: boolean;
  readonly automaticGate?: LearningTestAutomaticGate;
  readonly completedAt: string | null;
  readonly retakeAvailable: boolean;
  readonly retakeInProgress: boolean;
};

export type LearningTestScoreRatio = {
  readonly numerator: number;
  readonly denominator: number;
  readonly percentBps: number;
  readonly requiredBps: 8000 | 6000;
  readonly passed: boolean;
};

export type LearningTestScore = {
  readonly checked: true;
  readonly passed: boolean;
  readonly mastered: boolean;
  readonly overall: LearningTestScoreRatio & { readonly requiredBps: 8000 };
  readonly components: {
    readonly automaticBps: number;
    readonly manualBps: number;
    readonly automaticWeightBps: 7000;
    readonly manualWeightBps: 3000;
  };
};

export type LearningTestAutomaticGate = {
  readonly passed: boolean;
  readonly overall: LearningTestScoreRatio & { readonly requiredBps: 8000 };
  readonly sections: ReadonlyArray<LearningTestScoreRatio & {
    readonly sectionId: string;
    readonly requiredBps: 6000;
  }>;
  readonly correctCount: number;
};

export type LearningCourseLessonNavigation = {
  readonly id: number;
  readonly slug: string;
  readonly title: string;
  readonly sortOrder: number;
  readonly access: "none" | "full";
  readonly availability: "locked" | "free_preview" | "entitled";
  readonly canonicalPath: string;
  readonly testAvailable: boolean;
  readonly testPassed: boolean;
  readonly testProgress: LearningTestProgress | null;
  readonly videoProgressPercent?: number | null;
  readonly videoWatchedSeconds?: number | null;
};

export type LearningCourseLesson = LearningCourseLessonNavigation;

export type LessonBlock =
  | {
      readonly type: "callout";
      readonly text: string;
      readonly tone?: string;
    }
  | {
      readonly type: "divider";
    }
  | {
      readonly type: "heading";
      readonly text: string;
      readonly level: 2 | 3 | 4;
      readonly id?: string;
    }
  | {
      readonly type: "list";
      readonly items: ReadonlyArray<string>;
    }
  | {
      readonly type: "paragraph";
      readonly text: string;
    }
  | {
      readonly type: "quote";
      readonly text: string;
    };

export type LearningLessonContent =
  | {
      readonly status: "metadata_only";
    }
  | {
      readonly status: "published";
      readonly format: "lesson_blocks_v1";
      readonly version: number;
      readonly publishedAt: string | null;
      readonly body: {
        readonly blocks: ReadonlyArray<LessonBlock>;
        readonly toc: ReadonlyArray<{
          readonly id: string;
          readonly title: string;
        }>;
      };
    };

export type LearningLessonData = {
  readonly id: number;
  readonly slug: string;
  readonly title: string;
  readonly sortOrder: number;
  readonly access: "full";
  readonly canonicalPath: string;
  readonly subject: LearningCanonicalSubject;
  readonly course: LearningCanonicalCourse;
  readonly content: LearningLessonContent;
  readonly testAvailable: boolean;
  readonly testPassed: boolean;
  readonly testProgress: LearningTestProgress | null;
};

export type LearningLessonManifestMaterial = {
  readonly kind: "video" | "pdf" | "notes" | "file";
  readonly title: string;
};

export type LearningVideoMaterial = {
  readonly materialId: number;
  readonly resumePositionSeconds: number | null;
  readonly title: string;
};

export type LearningFileMaterial = {
  readonly materialId: number;
  readonly title: string;
};

export type LearningAccessData = {
  readonly subjects: ReadonlyArray<LearningIndexSubject>;
  readonly empty: boolean;
};

export type LearningSubjectData = {
  readonly subject: LearningCanonicalSubject;
  readonly access: LearningHierarchyAccessLevel;
  readonly requiredLessonsCount: number;
  readonly ownedLessonsCount: number;
  readonly courses: ReadonlyArray<LearningSubjectCourse>;
  readonly empty: boolean;
};

export type LearningCourseData = {
  readonly subject: LearningCanonicalSubject;
  readonly course: LearningCanonicalCourse;
  readonly access: LearningHierarchyAccessLevel;
  readonly requiredLessonsCount: number;
  readonly ownedLessonsCount: number;
  readonly completedLessonsCount: number;
  readonly lessons: ReadonlyArray<LearningCourseLesson>;
  readonly empty: boolean;
};

export type LearningAccessState =
  | { readonly status: "ready"; readonly data: LearningAccessData }
  | { readonly status: "unavailable" };

export type LearningLessonState =
  | { readonly status: "ready"; readonly data: LearningLessonData }
  | { readonly status: "denied" }
  | { readonly status: "not_found" }
  | { readonly status: "unavailable" };

export type LearningSubjectState =
  | { readonly status: "ready"; readonly data: LearningSubjectData }
  | { readonly status: "not_found" }
  | { readonly status: "unavailable" };

export type LearningCourseState =
  | { readonly status: "ready"; readonly data: LearningCourseData }
  | { readonly status: "not_found" }
  | { readonly status: "unavailable" };

export type LearningLessonMediaState =
  | {
      readonly status: "ready";
      readonly materials: ReadonlyArray<LearningLessonManifestMaterial>;
      readonly videos: ReadonlyArray<LearningVideoMaterial>;
      readonly files: ReadonlyArray<LearningFileMaterial>;
    }
  | { readonly status: "denied" }
  | { readonly status: "not_found" }
  | { readonly status: "unavailable" };

const LEARNING_ACCESS_REQUIRED_NOTICE = "learning-access-required";

export function learningCourseDestination(
  course: Pick<LearningSubjectCourse, "access" | "canonicalPath" | "freeLessonAvailable" | "publicPath">,
): string {
  if (course.access !== "none" || course.freeLessonAvailable) {
    return course.canonicalPath;
  }

  return `${course.publicPath}?notice=${LEARNING_ACCESS_REQUIRED_NOTICE}`;
}

export function learningAccessNoticeVisible(value: string | ReadonlyArray<string> | undefined): boolean {
  return value === LEARNING_ACCESS_REQUIRED_NOTICE;
}

type JsonRecord = Record<string, unknown>;

const RESERVED_LEARNING_SUBJECT_SLUGS = new Set(["lessons", "favorites"]);
const BACKEND_TIMESTAMP_PATTERN =
  /^([1-9][0-9]{3})-(0[1-9]|1[0-2])-(0[1-9]|[12][0-9]|3[01])T([01][0-9]|2[0-3]):([0-5][0-9]):([0-5][0-9])(?:\.([0-9]{1,6}))?(?:Z|[+-](?:0[0-9]|1[0-4]):[0-5][0-9])$/;
const TEST_SECTION_ID_PATTERN = /^[a-z0-9][a-z0-9-]{1,63}$/;

const SUBJECT_NOT_FOUND = {
  code: "LEARNING_SUBJECT_NOT_FOUND",
  message: "Учебный раздел не найден.",
};

const COURSE_NOT_FOUND = {
  code: "LEARNING_COURSE_NOT_FOUND",
  message: "Учебный курс не найден.",
};

const LESSON_NOT_FOUND = {
  code: "LEARNING_LESSON_NOT_FOUND",
  message: "Урок не найден.",
};

const LESSON_ACCESS_DENIED = {
  code: "LEARNING_ACCESS_DENIED",
  message: "У пользователя нет доступа к этому уроку.",
};

async function loadLearningIndexUncached(apiBaseUrl: string, refererPath: string): Promise<LearningAccessState> {
  try {
    const accountRequest = await accountBackendRequest(apiBaseUrl, refererPath);
    const response = await fetch(`${accountRequest.resolvedApiBaseUrl}/api/v1/learning`, {
      headers: accountRequest.headers,
      cache: "no-store",
    });

    if (!response.ok) {
      return { status: "unavailable" };
    }

    const data = parseLearningAccessPayload(await response.json());
    if (!data) {
      return { status: "unavailable" };
    }

    return { status: "ready", data };
  } catch (error) {
    rethrowNextDynamicServerError(error);

    return { status: "unavailable" };
  }
}

export const loadLearningIndex = cache(loadLearningIndexUncached);
export const loadLearningAccess = loadLearningIndex;

async function loadLearningSubjectUncached(
  apiBaseUrl: string,
  refererPath: string,
  subjectSlug: string,
): Promise<LearningSubjectState> {
  try {
    const accountRequest = await accountBackendRequest(apiBaseUrl, refererPath);
    const response = await fetch(
      `${accountRequest.resolvedApiBaseUrl}/api/v1/learning/subjects/${encodeURIComponent(subjectSlug)}`,
      {
        headers: accountRequest.headers,
        cache: "no-store",
      },
    );

    if (response.status === 404) {
      const payload: unknown = await response.json();
      return isExactErrorPayload(payload, SUBJECT_NOT_FOUND) ? { status: "not_found" } : { status: "unavailable" };
    }

    if (!response.ok) {
      return { status: "unavailable" };
    }

    const data = parseLearningSubjectPayload(await response.json());
    if (!data || data.subject.slug !== subjectSlug) {
      return { status: "unavailable" };
    }

    return { status: "ready", data };
  } catch (error) {
    rethrowNextDynamicServerError(error);

    return { status: "unavailable" };
  }
}

export const loadLearningSubject = cache(loadLearningSubjectUncached);

async function loadLearningCourseUncached(
  apiBaseUrl: string,
  refererPath: string,
  subjectSlug: string,
  courseSlug: string,
): Promise<LearningCourseState> {
  try {
    const accountRequest = await accountBackendRequest(apiBaseUrl, refererPath);
    const response = await fetch(
      `${accountRequest.resolvedApiBaseUrl}/api/v1/learning/subjects/${encodeURIComponent(subjectSlug)}/courses/${encodeURIComponent(courseSlug)}`,
      {
        headers: accountRequest.headers,
        cache: "no-store",
      },
    );

    if (response.status === 404) {
      const payload: unknown = await response.json();
      return isExactErrorPayload(payload, COURSE_NOT_FOUND) ? { status: "not_found" } : { status: "unavailable" };
    }

    if (!response.ok) {
      return { status: "unavailable" };
    }

    const data = parseLearningCoursePayload(await response.json());
    if (!data || data.subject.slug !== subjectSlug || data.course.slug !== courseSlug) {
      return { status: "unavailable" };
    }

    return { status: "ready", data };
  } catch (error) {
    rethrowNextDynamicServerError(error);

    return { status: "unavailable" };
  }
}

export const loadLearningCourse = cache(loadLearningCourseUncached);

async function loadLearningLessonUncached(
  apiBaseUrl: string,
  refererPath: string,
  subjectSlug: string,
  courseSlug: string,
  lessonSlug: string,
): Promise<LearningLessonState> {
  try {
    const accountRequest = await accountBackendRequest(apiBaseUrl, refererPath);
    const response = await fetch(
      `${accountRequest.resolvedApiBaseUrl}/api/v1/learning/subjects/${encodeURIComponent(subjectSlug)}/courses/${encodeURIComponent(courseSlug)}/lessons/${encodeURIComponent(lessonSlug)}`,
      {
        headers: accountRequest.headers,
        cache: "no-store",
      },
    );

    if (response.status === 403) {
      const payload: unknown = await response.json();
      return isExactErrorPayload(payload, LESSON_ACCESS_DENIED) ? { status: "denied" } : { status: "unavailable" };
    }

    if (response.status === 404) {
      const payload: unknown = await response.json();
      return isExactErrorPayload(payload, LESSON_NOT_FOUND) ? { status: "not_found" } : { status: "unavailable" };
    }

    if (!response.ok) {
      return { status: "unavailable" };
    }

    const payload: unknown = await response.json();
    if (
      !isLearningLessonPayload(payload) ||
      payload.data.subject.slug !== subjectSlug ||
      payload.data.course.slug !== courseSlug ||
      payload.data.slug !== lessonSlug
    ) {
      return { status: "unavailable" };
    }

    return { status: "ready", data: payload.data };
  } catch (error) {
    rethrowNextDynamicServerError(error);

    return { status: "unavailable" };
  }
}

export const loadLearningLesson = cache(loadLearningLessonUncached);

export async function loadLearningLessonMedia(
  apiBaseUrl: string,
  refererPath: string,
  subjectSlug: string,
  courseSlug: string,
  lessonSlug: string,
): Promise<LearningLessonMediaState> {
  try {
    const accountRequest = await accountBackendRequest(apiBaseUrl, refererPath);
    const lessonBasePath = `${accountRequest.resolvedApiBaseUrl}/api/v1/learning/subjects/${encodeURIComponent(subjectSlug)}/courses/${encodeURIComponent(courseSlug)}/lessons/${encodeURIComponent(lessonSlug)}`;
    const [manifestResponse, videosResponse, filesResponse] = await Promise.all([
      fetch(`${lessonBasePath}/materials`, {
        headers: accountRequest.headers,
        cache: "no-store",
      }),
      fetch(`${lessonBasePath}/video-materials`, {
        headers: accountRequest.headers,
        cache: "no-store",
      }),
      fetch(`${lessonBasePath}/file-materials`, {
        headers: accountRequest.headers,
        cache: "no-store",
      }),
    ]);

    if (manifestResponse.status === 403 || videosResponse.status === 403 || filesResponse.status === 403) {
      const payloads: ReadonlyArray<unknown> = await Promise.all([
        manifestResponse.json().catch(() => null),
        videosResponse.json().catch(() => null),
        filesResponse.json().catch(() => null),
      ]);

      return payloads.some((payload) => isExactErrorPayload(payload, LESSON_ACCESS_DENIED))
        ? { status: "denied" }
        : { status: "unavailable" };
    }

    if (manifestResponse.status === 404 || videosResponse.status === 404 || filesResponse.status === 404) {
      const payloads: ReadonlyArray<unknown> = await Promise.all([
        manifestResponse.json().catch(() => null),
        videosResponse.json().catch(() => null),
        filesResponse.json().catch(() => null),
      ]);

      return payloads.some((payload) => isExactErrorPayload(payload, LESSON_NOT_FOUND))
        ? { status: "not_found" }
        : { status: "unavailable" };
    }

    if (!manifestResponse.ok || !videosResponse.ok || !filesResponse.ok) {
      return { status: "unavailable" };
    }

    const manifestPayload: unknown = await manifestResponse.json();
    const videosPayload: unknown = await videosResponse.json();
    const filesPayload: unknown = await filesResponse.json();
    if (
      !isLearningManifestPayload(manifestPayload) ||
      !isLearningVideoMaterialsPayload(videosPayload) ||
      !isLearningFileMaterialsPayload(filesPayload)
    ) {
      return { status: "unavailable" };
    }

    const manifestVideos = manifestPayload.data.materials.filter((material) => material.kind === "video");
    const manifestFiles = manifestPayload.data.materials.filter((material) => material.kind === "pdf");
    const videos = videosPayload.data.videos;
    const files = filesPayload.data.files;
    const videoSelectionMatchesManifest =
      manifestVideos.length === videos.length &&
      manifestVideos.every((material, index) => material.title === videos[index]?.title);
    const fileSelectionMatchesManifest =
      manifestFiles.length === files.length &&
      manifestFiles.every((material, index) => material.title === files[index]?.title);

    return videoSelectionMatchesManifest && fileSelectionMatchesManifest
      ? { status: "ready", materials: manifestPayload.data.materials, videos, files }
      : { status: "unavailable" };
  } catch (error) {
    rethrowNextDynamicServerError(error);

    return { status: "unavailable" };
  }
}

function isRecord(value: unknown): value is JsonRecord {
  return typeof value === "object" && value !== null && !Array.isArray(value);
}

function hasExactKeys(record: JsonRecord, required: ReadonlyArray<string>, optional: ReadonlyArray<string> = []): boolean {
  const allowed = new Set([...required, ...optional]);
  const keys = Object.keys(record);

  return required.every((key) => Object.hasOwn(record, key)) && keys.every((key) => allowed.has(key));
}

function isInteger(value: unknown): value is number {
  return typeof value === "number" && Number.isSafeInteger(value);
}

function isPositiveInteger(value: unknown): value is number {
  return isInteger(value) && value > 0;
}

function isNonNegativeInteger(value: unknown): value is number {
  return isInteger(value) && value >= 0;
}

function isNullableNonNegativeNumber(value: unknown): value is number | null {
  return value === null || (typeof value === "number" && Number.isFinite(value) && value >= 0);
}

function isNullableProgressPercent(value: unknown): value is number | null {
  return value === null || (typeof value === "number" && Number.isFinite(value) && value >= 0 && value <= 100);
}

function isNonEmptyString(value: unknown): value is string {
  return typeof value === "string" && value.trim().length > 0;
}

function isIdentifier(value: unknown): value is string {
  return typeof value === "string" && TEST_SECTION_ID_PATTERN.test(value);
}

function isPathSegment(value: unknown): value is string {
  return isNonEmptyString(value) && value !== "." && value !== ".." && !/[/?#]/.test(value);
}

function isNullableNonEmptyString(value: unknown): value is string | null {
  return value === null || isNonEmptyString(value);
}

function isBackendTimestamp(value: unknown): value is string {
  if (typeof value !== "string") {
    return false;
  }

  const match = BACKEND_TIMESTAMP_PATTERN.exec(value);
  if (match === null || /[+-]14:(?!00$)/.test(value) || !Number.isFinite(Date.parse(value))) {
    return false;
  }

  const year = Number(match[1]);
  const month = Number(match[2]);
  const day = Number(match[3]);
  const calendarDate = new Date(Date.UTC(year, month - 1, day));

  return (
    calendarDate.getUTCFullYear() === year &&
    calendarDate.getUTCMonth() === month - 1 &&
    calendarDate.getUTCDate() === day
  );
}

function greatestCommonDivisor(left: number, right: number): number {
  let currentLeft = left;
  let currentRight = right;
  while (currentRight !== 0) {
    [currentLeft, currentRight] = [currentRight, currentLeft % currentRight];
  }

  return Math.abs(currentLeft);
}

function parseLearningTestScoreRatio(value: unknown, requiredBps: 8000 | 6000): LearningTestScoreRatio | null {
  if (
    !isRecord(value) ||
    !hasExactKeys(value, ["numerator", "denominator", "percentBps", "requiredBps", "passed"]) ||
    !isNonNegativeInteger(value.numerator) ||
    !isPositiveInteger(value.denominator) ||
    value.numerator > value.denominator ||
    !isNonNegativeInteger(value.percentBps) ||
    value.percentBps > 10000 ||
    value.requiredBps !== requiredBps ||
    typeof value.passed !== "boolean"
  ) {
    return null;
  }

  const canonical = value.numerator === 0
    ? value.denominator === 1
    : greatestCommonDivisor(value.numerator, value.denominator) === 1;
  const expectedPercentBps = Number(
    (BigInt(value.numerator) * BigInt(20000) + BigInt(value.denominator)) /
      (BigInt(value.denominator) * BigInt(2)),
  );
  const expectedPassed = BigInt(value.numerator) * BigInt(10000) >=
    BigInt(value.denominator) * BigInt(requiredBps);
  if (!canonical || value.percentBps !== expectedPercentBps || value.passed !== expectedPassed) {
    return null;
  }

  return {
    numerator: value.numerator,
    denominator: value.denominator,
    percentBps: value.percentBps,
    requiredBps,
    passed: value.passed,
  };
}

function parseLearningTestAutomaticGate(value: unknown): LearningTestAutomaticGate | null {
  if (
    !isRecord(value) ||
    !hasExactKeys(value, ["passed", "overall", "sections", "correctCount"]) ||
    typeof value.passed !== "boolean" ||
    !isNonNegativeInteger(value.correctCount) ||
    !Array.isArray(value.sections)
  ) {
    return null;
  }

  const overall = parseLearningTestScoreRatio(value.overall, 8000);
  if (overall === null) {
    return null;
  }
  const sections = [];
  const seenSectionIds = new Set<string>();
  for (const rawSection of value.sections) {
    if (
      !isRecord(rawSection) ||
      !hasExactKeys(rawSection, ["sectionId", "numerator", "denominator", "percentBps", "requiredBps", "passed"]) ||
      !isIdentifier(rawSection.sectionId) ||
      seenSectionIds.has(rawSection.sectionId)
    ) {
      return null;
    }
    const ratio = parseLearningTestScoreRatio({
      numerator: rawSection.numerator,
      denominator: rawSection.denominator,
      percentBps: rawSection.percentBps,
      requiredBps: rawSection.requiredBps,
      passed: rawSection.passed,
    }, 6000);
    if (ratio === null) {
      return null;
    }
    seenSectionIds.add(rawSection.sectionId);
    sections.push({ sectionId: rawSection.sectionId, ...ratio, requiredBps: 6000 as const });
  }

  const passed = overall.passed && sections.every((section) => section.passed);
  if (sections.length === 0 || value.passed !== passed) {
    return null;
  }

  return {
    passed,
    overall: { ...overall, requiredBps: 8000 },
    sections,
    correctCount: value.correctCount,
  };
}

function parseLearningFinalScoreOverall(value: unknown): LearningTestScoreRatio | null {
  if (
    !isRecord(value) ||
    !hasExactKeys(value, ["numerator", "denominator", "percentBps", "requiredBps", "passed"]) ||
    !isNonNegativeInteger(value.numerator) ||
    value.numerator > 10000 ||
    value.denominator !== 10000 ||
    value.percentBps !== value.numerator ||
    value.requiredBps !== 8000 ||
    typeof value.passed !== "boolean" ||
    value.passed !== (value.numerator >= 8000)
  ) {
    return null;
  }

  return {
    numerator: value.numerator,
    denominator: 10000,
    percentBps: value.percentBps,
    requiredBps: 8000,
    passed: value.passed,
  };
}

function roundedRatio(numerator: number, denominator: number): number {
  return Math.floor((numerator + Math.floor(denominator / 2)) / denominator);
}

function parseLearningTestScore(value: unknown): LearningTestScore | null {
  if (
    !isRecord(value) ||
    !hasExactKeys(value, ["checked", "passed", "mastered", "overall", "components"]) ||
    value.checked !== true ||
    typeof value.passed !== "boolean" ||
    typeof value.mastered !== "boolean" ||
    !isRecord(value.components)
  ) {
    return null;
  }

  const overall = parseLearningFinalScoreOverall(value.overall);
  const components = value.components;
  if (
    overall === null ||
    !hasExactKeys(components, ["automaticBps", "manualBps", "automaticWeightBps", "manualWeightBps"]) ||
    !isNonNegativeInteger(components.automaticBps) ||
    components.automaticBps > 10000 ||
    !isNonNegativeInteger(components.manualBps) ||
    components.manualBps > 10000 ||
    components.automaticWeightBps !== 7000 ||
    components.manualWeightBps !== 3000
  ) {
    return null;
  }

  const expectedPercentBps = roundedRatio(
    components.automaticBps * components.automaticWeightBps +
      components.manualBps * components.manualWeightBps,
    10000,
  );
  const passed = components.manualBps >= 6000 && expectedPercentBps >= 8000;
  const mastered = expectedPercentBps === 10000;
  if (
    overall.percentBps !== expectedPercentBps ||
    value.passed !== passed ||
    value.mastered !== mastered
  ) {
    return null;
  }

  return {
    checked: true,
    passed,
    mastered,
    overall: { ...overall, requiredBps: 8000 },
    components: {
      automaticBps: components.automaticBps,
      manualBps: components.manualBps,
      automaticWeightBps: 7000,
      manualWeightBps: 3000,
    },
  };
}

function isLearningTestContract(value: JsonRecord): boolean {
  if (typeof value.testAvailable !== "boolean" || typeof value.testPassed !== "boolean") {
    return false;
  }

  if (!value.testAvailable) {
    return value.testPassed === false && value.testProgress === null;
  }

  const progress = value.testProgress;
  if (
    !isRecord(progress) ||
    !hasExactKeys(
      progress,
      [
        "status",
        "answeredCount",
        "correctCount",
        "totalCount",
        "score",
        "mastered",
        "completedAt",
        "retakeAvailable",
        "retakeInProgress",
      ],
      ["automaticGate"],
    ) ||
    !isNonNegativeInteger(progress.answeredCount) ||
    !isNonNegativeInteger(progress.correctCount) ||
    !isPositiveInteger(progress.totalCount) ||
    progress.correctCount > progress.answeredCount ||
    progress.answeredCount > progress.totalCount ||
    typeof progress.mastered !== "boolean" ||
    typeof progress.retakeAvailable !== "boolean" ||
    typeof progress.retakeInProgress !== "boolean"
  ) {
    return false;
  }
  const score = progress.score === null ? null : parseLearningTestScore(progress.score);
  if (progress.score !== null && score === null) {
    return false;
  }
  const automaticGate = Object.hasOwn(progress, "automaticGate")
    ? parseLearningTestAutomaticGate(progress.automaticGate)
    : null;
  if (Object.hasOwn(progress, "automaticGate") && automaticGate === null) {
    return false;
  }

  if (progress.status === "not_started") {
    return (
      value.testPassed === false &&
      progress.answeredCount === 0 &&
      progress.correctCount === 0 &&
      score === null &&
      automaticGate === null &&
      progress.mastered === false &&
      progress.completedAt === null &&
      progress.retakeAvailable === true &&
      progress.retakeInProgress === false
    );
  }

  if (progress.status === "in_progress") {
    return (
      value.testPassed === false &&
      score === null &&
      automaticGate === null &&
      progress.mastered === false &&
      progress.completedAt === null &&
      progress.retakeAvailable === false &&
      progress.retakeInProgress === false
    );
  }

  if (progress.status === "failed") {
    return (
      value.testPassed === false &&
      progress.answeredCount === progress.totalCount &&
      automaticGate !== null &&
      automaticGate.correctCount === progress.correctCount &&
      (automaticGate.passed
        ? score !== null &&
          score.passed === false &&
          score.mastered === false &&
          score.components.automaticBps === automaticGate.overall.percentBps
        : score === null) &&
      progress.mastered === false &&
      progress.completedAt === null &&
      progress.retakeInProgress === false
    );
  }

  if (progress.status === "pending_review") {
    return (
      value.testPassed === false &&
      progress.answeredCount === progress.totalCount &&
      score === null &&
      automaticGate !== null &&
      automaticGate.passed === true &&
      automaticGate.correctCount === progress.correctCount &&
      progress.mastered === false &&
      progress.completedAt === null &&
      progress.retakeAvailable === false &&
      progress.retakeInProgress === false
    );
  }

  return (
    progress.status === "passed" &&
    value.testPassed === true &&
    progress.answeredCount === progress.totalCount &&
    score !== null &&
    score.passed === true &&
    automaticGate !== null &&
    automaticGate.passed === true &&
    automaticGate.correctCount === progress.correctCount &&
    score.components.automaticBps === automaticGate.overall.percentBps &&
    progress.mastered === score.mastered &&
    isBackendTimestamp(progress.completedAt) &&
    (!progress.retakeInProgress || progress.retakeAvailable)
  );
}

function isLearningAccessLevel(value: unknown): value is LearningAccessLevel {
  return value === "full" || value === "partial";
}

function isLearningHierarchyAccessLevel(value: unknown): value is LearningHierarchyAccessLevel {
  return isLearningAccessLevel(value) || value === "none";
}

function accessMatchesCounts(
  access: LearningHierarchyAccessLevel,
  ownedLessonsCount: number,
  requiredLessonsCount: number,
): boolean {
  if (requiredLessonsCount === 0) {
    return access === "none" && ownedLessonsCount === 0;
  }

  if (access === "full") {
    return ownedLessonsCount === requiredLessonsCount;
  }

  if (access === "partial") {
    return ownedLessonsCount > 0 && ownedLessonsCount < requiredLessonsCount;
  }

  return ownedLessonsCount === 0;
}

function canonicalLearningPath(...segments: ReadonlyArray<string>): string {
  return `/lk/learning/${segments.join("/")}`;
}

function canonicalCourseItemId(subjectSlug: string, courseSlug: string): string {
  return `course:${subjectSlug}:${courseSlug}`;
}

function publicCoursePath(subjectSlug: string, courseSlug: string): string {
  return `/subjects/${subjectSlug}/${courseSlug}`;
}

function isCanonicalSubject(value: unknown): value is LearningCanonicalSubject {
  return (
    isRecord(value) &&
    hasExactKeys(value, ["id", "slug", "title", "canonicalPath"]) &&
    isPositiveInteger(value.id) &&
    isPathSegment(value.slug) &&
    isNonEmptyString(value.title) &&
    value.canonicalPath === canonicalLearningPath(value.slug)
  );
}

function isLearningIndexSubject(value: unknown): value is LearningIndexSubject {
  return (
    isRecord(value) &&
    hasExactKeys(value, [
      "id",
      "slug",
      "title",
      "sortOrder",
      "access",
      "requiredLessonsCount",
      "ownedLessonsCount",
      "sellableCoursesCount",
      "boughtCoursesCount",
      "freeLessonAvailable",
      "canonicalPath",
    ]) &&
    isPositiveInteger(value.id) &&
    isPathSegment(value.slug) &&
    !RESERVED_LEARNING_SUBJECT_SLUGS.has(value.slug) &&
    isNonEmptyString(value.title) &&
    isNonNegativeInteger(value.sortOrder) &&
    isLearningHierarchyAccessLevel(value.access) &&
    isNonNegativeInteger(value.requiredLessonsCount) &&
    isNonNegativeInteger(value.ownedLessonsCount) &&
    value.ownedLessonsCount <= value.requiredLessonsCount &&
    isNonNegativeInteger(value.sellableCoursesCount) &&
    isNonNegativeInteger(value.boughtCoursesCount) &&
    typeof value.freeLessonAvailable === "boolean" &&
    accessMatchesCounts(value.access, value.ownedLessonsCount, value.requiredLessonsCount) &&
    value.canonicalPath === canonicalLearningPath(value.slug)
  );
}

function isSubjectCourse(value: unknown, subjectSlug: string): value is LearningSubjectCourse {
  return (
    isRecord(value) &&
    hasExactKeys(value, [
      "id",
      "itemId",
      "slug",
      "title",
      "shortTitle",
      "sortOrder",
      "publicPath",
      "access",
      "requiredLessonsCount",
      "ownedLessonsCount",
      "completedLessonsCount",
      "freeLessonAvailable",
      "canonicalPath",
    ]) &&
    isPositiveInteger(value.id) &&
    isNonEmptyString(value.itemId) &&
    isPathSegment(value.slug) &&
    value.itemId === canonicalCourseItemId(subjectSlug, value.slug) &&
    isNonEmptyString(value.title) &&
    isNullableNonEmptyString(value.shortTitle) &&
    isNonNegativeInteger(value.sortOrder) &&
    value.publicPath === publicCoursePath(subjectSlug, value.slug) &&
    isLearningHierarchyAccessLevel(value.access) &&
    isNonNegativeInteger(value.requiredLessonsCount) &&
    isNonNegativeInteger(value.ownedLessonsCount) &&
    value.ownedLessonsCount <= value.requiredLessonsCount &&
    isNonNegativeInteger(value.completedLessonsCount) &&
    value.completedLessonsCount <= value.requiredLessonsCount &&
    accessMatchesCounts(value.access, value.ownedLessonsCount, value.requiredLessonsCount) &&
    typeof value.freeLessonAvailable === "boolean" &&
    value.canonicalPath === canonicalLearningPath(subjectSlug, value.slug)
  );
}

function isCanonicalCourse(value: unknown, subjectSlug: string): value is LearningCanonicalCourse {
  return (
    isRecord(value) &&
    hasExactKeys(value, [
      "id",
      "itemId",
      "slug",
      "title",
      "shortTitle",
      "sortOrder",
      "canonicalPath",
      "publicPath",
      "freeLessonAvailable",
    ]) &&
    isPositiveInteger(value.id) &&
    isNonEmptyString(value.itemId) &&
    isPathSegment(value.slug) &&
    value.itemId === canonicalCourseItemId(subjectSlug, value.slug) &&
    isNonEmptyString(value.title) &&
    isNullableNonEmptyString(value.shortTitle) &&
    isNonNegativeInteger(value.sortOrder) &&
    value.canonicalPath === canonicalLearningPath(subjectSlug, value.slug) &&
    value.publicPath === publicCoursePath(subjectSlug, value.slug) &&
    typeof value.freeLessonAvailable === "boolean"
  );
}

function isCourseLesson(value: unknown, subjectSlug: string, courseSlug: string): value is LearningCourseLesson {
  return (
    isRecord(value) &&
    hasExactKeys(
      value,
      [
        "id",
        "slug",
        "title",
        "sortOrder",
        "access",
        "availability",
        "canonicalPath",
        "testAvailable",
        "testPassed",
        "testProgress",
      ],
      ["videoProgressPercent", "videoWatchedSeconds"],
    ) &&
    isPositiveInteger(value.id) &&
    isPathSegment(value.slug) &&
    isNonEmptyString(value.title) &&
    isNonNegativeInteger(value.sortOrder) &&
    ((value.access === "none" && value.availability === "locked") ||
      (value.access === "full" &&
        (value.availability === "free_preview" || value.availability === "entitled"))) &&
    value.canonicalPath === canonicalLearningPath(subjectSlug, courseSlug, value.slug) &&
    isLearningTestContract(value) &&
    (!Object.hasOwn(value, "videoProgressPercent") || isNullableProgressPercent(value.videoProgressPercent)) &&
    (!Object.hasOwn(value, "videoWatchedSeconds") || isNullableNonNegativeNumber(value.videoWatchedSeconds))
  );
}

function isLessonBlock(value: unknown): value is LessonBlock {
  if (!isRecord(value) || !isNonEmptyString(value.type)) {
    return false;
  }

  switch (value.type) {
    case "callout":
      return (
        hasExactKeys(value, ["type", "text"], ["tone"]) &&
        isNonEmptyString(value.text) &&
        (!Object.hasOwn(value, "tone") || isNonEmptyString(value.tone))
      );
    case "divider":
      return hasExactKeys(value, ["type"]);
    case "heading":
      return (
        hasExactKeys(value, ["type", "text", "level"], ["id"]) &&
        isNonEmptyString(value.text) &&
        (value.level === 2 || value.level === 3 || value.level === 4) &&
        (!Object.hasOwn(value, "id") || isNonEmptyString(value.id))
      );
    case "list":
      return (
        hasExactKeys(value, ["type", "items"]) &&
        Array.isArray(value.items) &&
        value.items.every(isNonEmptyString)
      );
    case "paragraph":
    case "quote":
      return hasExactKeys(value, ["type", "text"]) && isNonEmptyString(value.text);
    default:
      return false;
  }
}

function isTocItem(value: unknown): value is { readonly id: string; readonly title: string } {
  return (
    isRecord(value) &&
    hasExactKeys(value, ["id", "title"]) &&
    isNonEmptyString(value.id) &&
    isNonEmptyString(value.title)
  );
}

function isSupportedLessonContent(value: unknown): value is LearningLessonContent {
  if (!isRecord(value) || !isNonEmptyString(value.status)) {
    return false;
  }

  if (value.status === "metadata_only") {
    return hasExactKeys(value, ["status"]);
  }

  if (
    value.status !== "published" ||
    !hasExactKeys(value, ["status", "format", "version", "publishedAt", "body"]) ||
    value.format !== "lesson_blocks_v1" ||
    !isInteger(value.version) ||
    !isNullableNonEmptyString(value.publishedAt) ||
    !isRecord(value.body) ||
    !hasExactKeys(value.body, ["blocks", "toc"]) ||
    !Array.isArray(value.body.blocks) ||
    value.body.blocks.length === 0 ||
    !value.body.blocks.every(isLessonBlock) ||
    !Array.isArray(value.body.toc) ||
    !value.body.toc.every(isTocItem)
  ) {
    return false;
  }

  return true;
}

function isLearningLessonData(value: unknown): value is LearningLessonData {
  if (
    !isRecord(value) ||
    !hasExactKeys(value, [
      "id",
      "slug",
      "title",
      "sortOrder",
      "access",
      "canonicalPath",
      "subject",
      "course",
      "content",
      "testAvailable",
      "testPassed",
      "testProgress",
    ]) ||
    !isPositiveInteger(value.id) ||
    !isPathSegment(value.slug) ||
    !isNonEmptyString(value.title) ||
    !isNonNegativeInteger(value.sortOrder) ||
    value.access !== "full" ||
    !isCanonicalSubject(value.subject) ||
    !isCanonicalCourse(value.course, value.subject.slug) ||
    value.canonicalPath !== canonicalLearningPath(value.subject.slug, value.course.slug, value.slug) ||
    !isSupportedLessonContent(value.content) ||
    !isLearningTestContract(value)
  ) {
    return false;
  }

  return true;
}

function isLearningAccessPayload(value: unknown): value is { readonly data: LearningAccessData } {
  if (!isRecord(value) || !hasExactKeys(value, ["data"]) || !isRecord(value.data)) {
    return false;
  }

  const data = value.data;

  return (
    hasExactKeys(data, ["subjects", "empty"]) &&
    Array.isArray(data.subjects) &&
    data.subjects.every(isLearningIndexSubject) &&
    typeof data.empty === "boolean" &&
    data.empty === (data.subjects.length === 0)
  );
}

function isLearningSubjectPayload(value: unknown): value is { readonly data: LearningSubjectData } {
  if (!isRecord(value) || !hasExactKeys(value, ["data"]) || !isRecord(value.data)) {
    return false;
  }

  const data = value.data;
  if (!hasExactKeys(data, ["subject", "access", "requiredLessonsCount", "ownedLessonsCount", "courses", "empty"])) {
    return false;
  }

  const subject = data.subject;
  if (!isCanonicalSubject(subject)) {
    return false;
  }

  if (
    !isLearningHierarchyAccessLevel(data.access) ||
    !isNonNegativeInteger(data.requiredLessonsCount) ||
    !isNonNegativeInteger(data.ownedLessonsCount) ||
    data.ownedLessonsCount > data.requiredLessonsCount ||
    !accessMatchesCounts(data.access, data.ownedLessonsCount, data.requiredLessonsCount) ||
    !Array.isArray(data.courses) ||
    !data.courses.every((course) => isSubjectCourse(course, subject.slug)) ||
    typeof data.empty !== "boolean" ||
    data.empty !== (data.courses.length === 0)
  ) {
    return false;
  }

  return true;
}

function isLearningCoursePayload(value: unknown): value is { readonly data: LearningCourseData } {
  if (!isRecord(value) || !hasExactKeys(value, ["data"]) || !isRecord(value.data)) {
    return false;
  }

  const data = value.data;
  if (!hasExactKeys(data, ["subject", "course", "access", "requiredLessonsCount", "ownedLessonsCount", "completedLessonsCount", "lessons", "empty"])) {
    return false;
  }

  const subject = data.subject;
  const course = data.course;
  if (!isCanonicalSubject(subject) || !isCanonicalCourse(course, subject.slug)) {
    return false;
  }

  if (
    !isLearningHierarchyAccessLevel(data.access) ||
    !isNonNegativeInteger(data.requiredLessonsCount) ||
    !isNonNegativeInteger(data.ownedLessonsCount) ||
    data.ownedLessonsCount > data.requiredLessonsCount ||
    !isNonNegativeInteger(data.completedLessonsCount) ||
    data.completedLessonsCount > data.requiredLessonsCount ||
    !accessMatchesCounts(data.access, data.ownedLessonsCount, data.requiredLessonsCount) ||
    !Array.isArray(data.lessons) ||
    !data.lessons.every((lesson) => isCourseLesson(lesson, subject.slug, course.slug)) ||
    data.completedLessonsCount !== data.lessons.filter((lesson) => lesson.testPassed === true).length ||
    typeof data.empty !== "boolean" ||
    data.empty !== (data.lessons.length === 0)
  ) {
    return false;
  }

  return true;
}

function isLearningLessonPayload(value: unknown): value is { readonly data: LearningLessonData } {
  return isRecord(value) && hasExactKeys(value, ["data"]) && isLearningLessonData(value.data);
}

export function parseLearningAccessPayload(value: unknown): LearningAccessData | null {
  return isLearningAccessPayload(value) ? value.data : null;
}

export function parseLearningSubjectPayload(value: unknown): LearningSubjectData | null {
  return isLearningSubjectPayload(value) ? value.data : null;
}

export function parseLearningCoursePayload(value: unknown): LearningCourseData | null {
  return isLearningCoursePayload(value) ? value.data : null;
}

export function parseLearningLessonPayload(value: unknown): LearningLessonData | null {
  return isLearningLessonPayload(value) ? value.data : null;
}

function isLearningManifestPayload(
  value: unknown,
): value is { readonly data: { readonly materials: ReadonlyArray<LearningLessonManifestMaterial> } } {
  if (!isRecord(value) || !hasExactKeys(value, ["data"]) || !isRecord(value.data) || !hasExactKeys(value.data, ["materials"])) {
    return false;
  }

  return Array.isArray(value.data.materials) && value.data.materials.every((material) => {
    return (
      isRecord(material) &&
      hasExactKeys(material, ["kind", "title"]) &&
      (material.kind === "video" || material.kind === "pdf" || material.kind === "notes" || material.kind === "file") &&
      isNonEmptyString(material.title)
    );
  });
}

function isLearningVideoMaterialsPayload(
  value: unknown,
): value is { readonly data: { readonly videos: ReadonlyArray<LearningVideoMaterial> } } {
  if (!isRecord(value) || !hasExactKeys(value, ["data"]) || !isRecord(value.data) || !hasExactKeys(value.data, ["videos"])) {
    return false;
  }

  return isVideoMaterialSelection(value.data.videos);
}

function isLearningFileMaterialsPayload(
  value: unknown,
): value is { readonly data: { readonly files: ReadonlyArray<LearningFileMaterial> } } {
  if (!isRecord(value) || !hasExactKeys(value, ["data"]) || !isRecord(value.data) || !hasExactKeys(value.data, ["files"])) {
    return false;
  }

  return isFileMaterialSelection(value.data.files);
}

function isVideoMaterialSelection(value: unknown): value is ReadonlyArray<LearningVideoMaterial> {
  if (!Array.isArray(value)) {
    return false;
  }

  const materialIds = new Set<number>();

  return value.every((material) => {
    if (
      !isRecord(material) ||
      !hasExactKeys(material, ["materialId", "resumePositionSeconds", "title"]) ||
      !isPositiveInteger(material.materialId) ||
      !isNullableNonNegativeNumber(material.resumePositionSeconds) ||
      !isNonEmptyString(material.title) ||
      materialIds.has(material.materialId)
    ) {
      return false;
    }

    materialIds.add(material.materialId);

    return true;
  });
}

function isFileMaterialSelection(value: unknown): value is ReadonlyArray<LearningFileMaterial> {
  if (!Array.isArray(value)) {
    return false;
  }

  const materialIds = new Set<number>();

  return value.every((material) => {
    if (
      !isRecord(material) ||
      !hasExactKeys(material, ["materialId", "title"]) ||
      !isPositiveInteger(material.materialId) ||
      !isNonEmptyString(material.title) ||
      materialIds.has(material.materialId)
    ) {
      return false;
    }

    materialIds.add(material.materialId);

    return true;
  });
}

function isExactErrorPayload(
  value: unknown,
  expected: { readonly code: string; readonly message: string },
): boolean {
  return (
    isRecord(value) &&
    hasExactKeys(value, ["error"]) &&
    isRecord(value.error) &&
    hasExactKeys(value.error, ["code", "message"]) &&
    value.error.code === expected.code &&
    value.error.message === expected.message
  );
}
