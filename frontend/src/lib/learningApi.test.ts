import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

import ts from "typescript";

type LearningApiModule = Pick<
  typeof import("./learningApi"),
  | "learningCourseDestination"
  | "learningAccessNoticeVisible"
  | "parseLearningAccessPayload"
  | "parseLearningCoursePayload"
  | "parseLearningLessonPayload"
  | "parseLearningSubjectPayload"
>;

async function loadLearningApiModule(): Promise<LearningApiModule> {
  const source = await readFile(new URL("./learningApi.ts", import.meta.url), "utf8");
  const output = ts.transpileModule(source, {
    compilerOptions: {
      esModuleInterop: true,
      module: ts.ModuleKind.CommonJS,
      target: ts.ScriptTarget.ES2022,
    },
  }).outputText;
  const sandboxModule = { exports: {} as Record<string, unknown> };
  const requireStub = (specifier: string): unknown => {
    if (specifier === "server-only") {
      return {};
    }
    if (specifier === "react") {
      return { cache: <Args extends ReadonlyArray<unknown>, Result>(loader: (...args: Args) => Result) => loader };
    }
    if (specifier === "@/lib/accountAuth") {
      return { accountBackendRequest: async () => ({ headers: {}, resolvedApiBaseUrl: "http://backend.test" }) };
    }
    if (specifier === "@/lib/apiErrors") {
      return { rethrowNextDynamicServerError: () => undefined };
    }

    throw new Error(`Unexpected module in learningApi test: ${specifier}`);
  };

  Function("require", "module", "exports", output)(requireStub, sandboxModule, sandboxModule.exports);
  return sandboxModule.exports as LearningApiModule;
}

const learningApi = await loadLearningApiModule();

const subject = {
  id: 1,
  slug: "anatomiya",
  title: "Анатомия",
  sortOrder: 10,
  canonicalPath: "/lk/learning/anatomiya",
  access: "none",
  requiredLessonsCount: 0,
  ownedLessonsCount: 0,
  sellableCoursesCount: 0,
  boughtCoursesCount: 1,
  freeLessonAvailable: false,
} as const;

const course = {
  id: 2,
  itemId: "course:anatomiya:osteologiya",
  slug: "osteologiya",
  title: "Остеология",
  shortTitle: null,
  sortOrder: 20,
  canonicalPath: "/lk/learning/anatomiya/osteologiya",
  publicPath: "/subjects/anatomiya/osteologiya",
  access: "none",
  requiredLessonsCount: 1,
  ownedLessonsCount: 0,
  completedLessonsCount: 0,
  freeLessonAvailable: false,
} as const;

const lesson = {
  id: 3,
  slug: "bones",
  title: "Кости",
  sortOrder: 30,
  canonicalPath: "/lk/learning/anatomiya/osteologiya/bones",
  access: "none",
  availability: "locked",
  testAvailable: false,
  testPassed: false,
  testProgress: null,
} as const;

const inProgressTestProgress = {
  status: "in_progress",
  answeredCount: 12,
  correctCount: 9,
  totalCount: 48,
  score: null,
  mastered: false,
  completedAt: null,
  retakeAvailable: false,
  retakeInProgress: false,
} as const;

const notStartedTestProgress = {
  status: "not_started",
  answeredCount: 0,
  correctCount: 0,
  totalCount: 48,
  score: null,
  mastered: false,
  completedAt: null,
  retakeAvailable: true,
  retakeInProgress: false,
} as const;

const passedAutomaticGate = {
  passed: true,
  overall: {
    numerator: 4,
    denominator: 5,
    percentBps: 8000,
    requiredBps: 8000,
    passed: true,
  },
  sections: [{
    sectionId: "main",
    numerator: 4,
    denominator: 5,
    percentBps: 8000,
    requiredBps: 6000,
    passed: true,
  }],
  correctCount: 40,
} as const;

const passedTestProgress = {
  status: "passed",
  answeredCount: 48,
  correctCount: 40,
  totalCount: 48,
  score: {
    checked: true,
    passed: true,
    mastered: false,
    overall: {
      numerator: 8000,
      denominator: 10000,
      percentBps: 8000,
      requiredBps: 8000,
      passed: true,
    },
    components: {
      automaticBps: 8000,
      manualBps: 8000,
      automaticWeightBps: 7000,
      manualWeightBps: 3000,
    },
  },
  mastered: false,
  automaticGate: passedAutomaticGate,
  completedAt: "2026-08-19T12:34:56.000000Z",
  retakeAvailable: true,
  retakeInProgress: false,
} as const;

const failedAutomaticGate = {
  passed: false,
  overall: {
    numerator: 1,
    denominator: 2,
    percentBps: 5000,
    requiredBps: 8000,
    passed: false,
  },
  sections: [{
    sectionId: "main",
    numerator: 1,
    denominator: 2,
    percentBps: 5000,
    requiredBps: 6000,
    passed: false,
  }],
  correctCount: 24,
} as const;

const failedTestProgress = {
  status: "failed",
  answeredCount: 48,
  correctCount: 24,
  totalCount: 48,
  score: null,
  mastered: false,
  automaticGate: failedAutomaticGate,
  completedAt: null,
  retakeAvailable: true,
  retakeInProgress: false,
} as const;

const pendingReviewTestProgress = {
  status: "pending_review",
  answeredCount: 48,
  correctCount: 41,
  totalCount: 48,
  score: null,
  mastered: false,
  automaticGate: {
    passed: true,
    overall: {
      numerator: 1,
      denominator: 1,
      percentBps: 10000,
      requiredBps: 8000,
      passed: true,
    },
    sections: [{
      sectionId: "main",
      numerator: 1,
      denominator: 1,
      percentBps: 10000,
      requiredBps: 6000,
      passed: true,
    }],
    correctCount: 41,
  },
  completedAt: null,
  retakeAvailable: false,
  retakeInProgress: false,
} as const;

const canonicalCourse = {
  id: course.id,
  itemId: course.itemId,
  slug: course.slug,
  title: course.title,
  shortTitle: course.shortTitle,
  sortOrder: course.sortOrder,
  canonicalPath: course.canonicalPath,
  publicPath: course.publicPath,
  freeLessonAvailable: course.freeLessonAvailable,
} as const;

const canonicalSubject = {
  id: subject.id,
  slug: subject.slug,
  title: subject.title,
  canonicalPath: subject.canonicalPath,
} as const;

test("accepts exact learning DTOs, including an active empty subject and an entitled unsellable course", () => {
  assert.deepEqual(
    learningApi.parseLearningAccessPayload({ data: { subjects: [subject], empty: false } }),
    { subjects: [subject], empty: false },
  );
  assert.deepEqual(
    learningApi.parseLearningSubjectPayload({
      data: {
        subject: {
          id: subject.id,
          slug: subject.slug,
          title: subject.title,
          canonicalPath: subject.canonicalPath,
        },
        access: "none",
        requiredLessonsCount: 1,
        ownedLessonsCount: 0,
        courses: [course],
        empty: false,
      },
    }),
    {
      subject: {
        id: subject.id,
        slug: subject.slug,
        title: subject.title,
        canonicalPath: subject.canonicalPath,
      },
      access: "none",
      requiredLessonsCount: 1,
      ownedLessonsCount: 0,
      courses: [course],
      empty: false,
    },
  );
  assert.equal(
    learningApi.parseLearningCoursePayload({
      data: {
        subject: {
          id: subject.id,
          slug: subject.slug,
          title: subject.title,
          canonicalPath: subject.canonicalPath,
        },
        course: canonicalCourse,
        access: "none",
        requiredLessonsCount: 1,
        ownedLessonsCount: 0,
        completedLessonsCount: 0,
        lessons: [lesson],
        empty: false,
      },
    })?.lessons[0]?.availability,
    "locked",
  );
  assert.deepEqual(
    learningApi.parseLearningCoursePayload({
      data: {
        subject: {
          id: subject.id,
          slug: subject.slug,
          title: subject.title,
          canonicalPath: subject.canonicalPath,
        },
        course: canonicalCourse,
        access: "full",
        requiredLessonsCount: 1,
        ownedLessonsCount: 1,
        completedLessonsCount: 1,
        lessons: [
          {
            ...lesson,
            access: "full",
            availability: "entitled",
            testAvailable: true,
            testPassed: true,
            testProgress: passedTestProgress,
            videoProgressPercent: 64,
            videoWatchedSeconds: 742,
          },
        ],
        empty: false,
      },
    })?.lessons[0],
    {
      ...lesson,
      access: "full",
      availability: "entitled",
      testAvailable: true,
      testPassed: true,
      testProgress: passedTestProgress,
      videoProgressPercent: 64,
      videoWatchedSeconds: 742,
    },
  );

  assert.deepEqual(
    learningApi.parseLearningLessonPayload({
      data: {
        id: lesson.id,
        slug: lesson.slug,
        title: lesson.title,
        sortOrder: lesson.sortOrder,
        access: "full",
        canonicalPath: lesson.canonicalPath,
        subject: canonicalSubject,
        course: canonicalCourse,
        content: { status: "metadata_only" },
        testAvailable: true,
        testPassed: false,
        testProgress: inProgressTestProgress,
      },
    }),
    {
      id: lesson.id,
      slug: lesson.slug,
      title: lesson.title,
      sortOrder: lesson.sortOrder,
      access: "full",
      canonicalPath: lesson.canonicalPath,
      subject: canonicalSubject,
      course: canonicalCourse,
      content: { status: "metadata_only" },
      testAvailable: true,
      testPassed: false,
      testProgress: inProgressTestProgress,
    },
  );

  assert.equal(
    learningApi.parseLearningLessonPayload({
      data: {
        id: lesson.id,
        slug: lesson.slug,
        title: lesson.title,
        sortOrder: lesson.sortOrder,
        access: "full",
        canonicalPath: lesson.canonicalPath,
        subject: canonicalSubject,
        course: canonicalCourse,
        content: { status: "metadata_only" },
        testAvailable: true,
        testPassed: false,
        testProgress: notStartedTestProgress,
      },
    })?.testProgress?.status,
    "not_started",
  );

  assert.equal(
    learningApi.parseLearningLessonPayload({
      data: {
        id: lesson.id,
        slug: lesson.slug,
        title: lesson.title,
        sortOrder: lesson.sortOrder,
        access: "full",
        canonicalPath: lesson.canonicalPath,
        subject: canonicalSubject,
        course: canonicalCourse,
        content: { status: "metadata_only" },
        testAvailable: true,
        testPassed: false,
        testProgress: failedTestProgress,
      },
    })?.testProgress?.status,
    "failed",
  );

  assert.equal(
    learningApi.parseLearningCoursePayload({
      data: {
        subject: canonicalSubject,
        course: canonicalCourse,
        access: "full",
        requiredLessonsCount: 1,
        ownedLessonsCount: 1,
        completedLessonsCount: 0,
        lessons: [{
          ...lesson,
          access: "full",
          availability: "entitled",
          testAvailable: true,
          testPassed: false,
          testProgress: pendingReviewTestProgress,
        }],
        empty: false,
      },
    })?.lessons[0]?.testProgress?.status,
    "pending_review",
  );
});

test("rejects missing, malformed, reserved, wrong-parent and protected navigation fields", () => {
  const indexPayload = { data: { subjects: [subject], empty: false } };
  const subjectPayload = {
    data: {
      subject: { id: 1, slug: "anatomiya", title: "Анатомия", canonicalPath: "/lk/learning/anatomiya" },
      access: "none",
      requiredLessonsCount: 1,
      ownedLessonsCount: 0,
      courses: [course],
      empty: false,
    },
  };
  const subjectPayloadSubject = subjectPayload.data.subject;
  const coursePayload = {
    data: {
      subject: subjectPayload.data.subject,
      course: canonicalCourse,
      access: "none",
      requiredLessonsCount: 1,
      ownedLessonsCount: 0,
      completedLessonsCount: 0,
      lessons: [lesson],
      empty: false,
    },
  };

  const invalidIndexSubjects = [
    { ...subject, sortOrder: undefined },
    { ...subject, sortOrder: -1 },
    { ...subject, slug: "lessons", canonicalPath: "/lk/learning/lessons" },
    { ...subject, canonicalPath: "/lk/learning/other" },
    { ...subject, protectedBody: "secret" },
  ];
  for (const invalidSubject of invalidIndexSubjects) {
    assert.equal(
      learningApi.parseLearningAccessPayload({ data: { subjects: [invalidSubject], empty: false } }),
      null,
    );
  }

  const invalidCourses = [
    { ...course, freeLessonAvailable: undefined },
    { ...course, sortOrder: "20" },
    { ...course, publicPath: undefined },
    { ...course, publicPath: "/subjects/other/osteologiya" },
    { ...course, canonicalPath: "/lk/learning/other/osteologiya" },
    { ...course, mediaId: "protected" },
  ];
  for (const invalidCourse of invalidCourses) {
    assert.equal(
      learningApi.parseLearningSubjectPayload({
        ...subjectPayload,
        data: { ...subjectPayload.data, courses: [invalidCourse] },
      }),
      null,
    );
  }

  const invalidCanonicalCourses = [
    { ...canonicalCourse, sortOrder: undefined },
    { ...canonicalCourse, publicPath: "/subjects/other/osteologiya" },
    { ...canonicalCourse, freeLessonAvailable: undefined },
  ];
  for (const invalidCanonicalCourse of invalidCanonicalCourses) {
    assert.equal(
      learningApi.parseLearningCoursePayload({
        ...coursePayload,
        data: { ...coursePayload.data, course: invalidCanonicalCourse },
      }),
      null,
    );
  }

  const invalidLessons = [
    { ...lesson, availability: undefined },
    { ...lesson, availability: "unknown" },
    { ...lesson, access: "full", availability: "locked" },
    { ...lesson, access: "none", availability: "entitled" },
    { ...lesson, canonicalPath: "/lk/learning/anatomiya/other/bones" },
    { ...lesson, testAvailable: undefined },
    { ...lesson, testPassed: "yes" },
    { ...lesson, testProgress: undefined },
    { ...lesson, testAvailable: true, testProgress: null },
    { ...lesson, testPassed: true, testProgress: null },
    {
      ...lesson,
      testAvailable: true,
      testProgress: { ...inProgressTestProgress, answeredCount: 49 },
    },
    {
      ...lesson,
      testAvailable: true,
      testProgress: { ...inProgressTestProgress, correctCount: 13 },
    },
    {
      ...lesson,
      testAvailable: true,
      testProgress: { ...inProgressTestProgress, completedAt: passedTestProgress.completedAt },
    },
    {
      ...lesson,
      testAvailable: true,
      testProgress: { ...failedTestProgress, answeredCount: 47 },
    },
    {
      ...lesson,
      testAvailable: true,
      testProgress: { ...failedTestProgress, automaticGate: passedAutomaticGate },
    },
    {
      ...lesson,
      testAvailable: true,
      testPassed: true,
      testProgress: failedTestProgress,
    },
    {
      ...lesson,
      testAvailable: true,
      testProgress: { ...failedTestProgress, retakeInProgress: true },
    },
    {
      ...lesson,
      testAvailable: true,
      testProgress: { ...pendingReviewTestProgress, automaticGate: failedAutomaticGate },
    },
    {
      ...lesson,
      testAvailable: true,
      testProgress: { ...pendingReviewTestProgress, automaticGate: undefined },
    },
    {
      ...lesson,
      testAvailable: true,
      testPassed: true,
      testProgress: { ...passedTestProgress, completedAt: "2026-02-30T12:34:56Z" },
    },
    {
      ...lesson,
      testAvailable: true,
      testPassed: true,
      testProgress: { ...passedTestProgress, completedAt: "2026-08-19T12:34:56" },
    },
    {
      ...lesson,
      testAvailable: true,
      testPassed: true,
      testProgress: { ...passedTestProgress, retakeAvailable: false, retakeInProgress: true },
    },
    { ...lesson, videoProgressPercent: -1 },
    { ...lesson, videoProgressPercent: 101 },
    { ...lesson, videoWatchedSeconds: -1 },
    { ...lesson, body: "protected" },
  ];
  for (const invalidLesson of invalidLessons) {
    assert.equal(
      learningApi.parseLearningCoursePayload({
        ...coursePayload,
        data: { ...coursePayload.data, lessons: [invalidLesson] },
      }),
      null,
    );
  }

  const invalidCourseCounts = [
    { ...coursePayload.data, completedLessonsCount: undefined },
    { ...coursePayload.data, completedLessonsCount: 2 },
    {
      ...coursePayload.data,
      completedLessonsCount: 0,
      lessons: [{ ...lesson, testAvailable: true, testPassed: true, testProgress: passedTestProgress }],
    },
  ];
  for (const invalidData of invalidCourseCounts) {
    assert.equal(learningApi.parseLearningCoursePayload({ data: invalidData }), null);
  }

  const lessonDetail = {
    id: lesson.id,
    slug: lesson.slug,
    title: lesson.title,
    sortOrder: lesson.sortOrder,
    access: "full",
    canonicalPath: lesson.canonicalPath,
    subject: subjectPayloadSubject,
    course: canonicalCourse,
    content: { status: "metadata_only" },
    testAvailable: true,
    testPassed: false,
    testProgress: inProgressTestProgress,
  } as const;
  for (const invalidLessonDetail of [
    { ...lessonDetail, testAvailable: undefined },
    { ...lessonDetail, testPassed: undefined },
    { ...lessonDetail, testProgress: undefined },
    { ...lessonDetail, testProgress: { ...inProgressTestProgress, totalCount: 0 } },
    { ...lessonDetail, answerKey: "hidden" },
  ]) {
    assert.equal(learningApi.parseLearningLessonPayload({ data: invalidLessonDetail }), null);
  }

  assert.deepEqual(indexPayload.data.subjects, [subject]);
});

test("resolves all course destinations from validated backend paths", () => {
  assert.equal(learningApi.learningCourseDestination({ ...course, access: "full" }), course.canonicalPath);
  assert.equal(learningApi.learningCourseDestination({ ...course, access: "partial" }), course.canonicalPath);
  assert.equal(
    learningApi.learningCourseDestination({ ...course, freeLessonAvailable: true }),
    course.canonicalPath,
  );
  assert.equal(
    learningApi.learningCourseDestination(course),
    "/subjects/anatomiya/osteologiya?notice=learning-access-required",
  );
});

test("shows the public notice only for one exact whitelisted query value", () => {
  assert.equal(learningApi.learningAccessNoticeVisible("learning-access-required"), true);
  assert.equal(learningApi.learningAccessNoticeVisible(undefined), false);
  assert.equal(learningApi.learningAccessNoticeVisible("other"), false);
  assert.equal(learningApi.learningAccessNoticeVisible(["learning-access-required", "learning-access-required"]), false);
});
