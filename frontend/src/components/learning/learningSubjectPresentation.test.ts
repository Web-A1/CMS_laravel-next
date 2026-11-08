import assert from "node:assert/strict";
import test from "node:test";

const modulePath = "./learningSubjectPresentation.ts";
const presentationModule: typeof import("./learningSubjectPresentation") = await import(modulePath);
const {
  learningCoursePresentation,
  learningSubjectPresentation,
  lessonCountLabel,
} = presentationModule;

test("formats Russian lesson counts", () => {
  assert.equal(lessonCountLabel(1), "1 урок");
  assert.equal(lessonCountLabel(2), "2 урока");
  assert.equal(lessonCountLabel(5), "5 уроков");
  assert.equal(lessonCountLabel(11), "11 уроков");
  assert.equal(lessonCountLabel(21), "21 урок");
});

test("presents access coverage and study progress separately", () => {
  assert.deepEqual(
    learningCoursePresentation({
      access: "full",
      completedLessonsCount: 2,
      ownedLessonsCount: 6,
      requiredLessonsCount: 6,
    }),
    {
      kind: "coverage",
      accessibilityLabel: "Доступно уроков: 6 из 6",
      lessonsLabel: "6 уроков",
      studyProgressLabel: "Пройдено уроков: 2 из 6",
      studyProgressRatio: 2 / 6,
      studyProgressValueLabel: "33%",
    },
  );

  assert.deepEqual(
    learningCoursePresentation({ access: "partial", ownedLessonsCount: 4, requiredLessonsCount: 5 }),
    {
      kind: "coverage",
      accessibilityLabel: "Доступно уроков: 4 из 5",
      lessonsLabel: "5 уроков",
      studyProgressLabel: "Пройдено уроков: 0 из 5",
      studyProgressRatio: 0,
      studyProgressValueLabel: "0%",
    },
  );

  assert.deepEqual(
    learningCoursePresentation({
      access: "full",
      completedLessonsCount: 5,
      ownedLessonsCount: 5,
      requiredLessonsCount: 5,
    }),
    {
      kind: "coverage",
      accessibilityLabel: "Доступно уроков: 5 из 5",
      lessonsLabel: "5 уроков",
      studyProgressLabel: "Курс завершён",
      studyProgressRatio: 1,
      studyProgressValueLabel: "100%",
    },
  );
});

test("keeps zero-lessons separate from access coverage", () => {
  assert.deepEqual(
    learningCoursePresentation({ access: "none", ownedLessonsCount: 0, requiredLessonsCount: 0 }),
    {
      kind: "zero-lessons",
      accessibilityLabel: null,
      lessonsLabel: "В курсе нет уроков",
      studyProgressLabel: null,
      studyProgressRatio: null,
      studyProgressValueLabel: null,
    },
  );
});

test("fails closed when access and counts disagree", () => {
  const fixtures = [
    { access: "full" as const, ownedLessonsCount: 4, requiredLessonsCount: 5 },
    { access: "partial" as const, ownedLessonsCount: 0, requiredLessonsCount: 5 },
    { access: "none" as const, ownedLessonsCount: 1, requiredLessonsCount: 5 },
    { access: "full" as const, ownedLessonsCount: 6, requiredLessonsCount: 5 },
    { access: "full" as const, completedLessonsCount: 6, ownedLessonsCount: 5, requiredLessonsCount: 5 },
    { access: "none" as const, ownedLessonsCount: Number.NaN, requiredLessonsCount: 5 },
  ];

  for (const fixture of fixtures) {
    assert.equal(learningCoursePresentation(fixture).kind, "contract-drift");
  }
});

test("classifies subject-level empty and drift states", () => {
  assert.equal(
    learningSubjectPresentation({
      access: "none",
      coursesCount: 2,
      ownedLessonsCount: 0,
      requiredLessonsCount: 8,
    }),
    "courses",
  );
  assert.equal(
    learningSubjectPresentation({
      access: "partial",
      coursesCount: 5,
      ownedLessonsCount: 26,
      requiredLessonsCount: 27,
    }),
    "courses",
  );
  assert.equal(
    learningSubjectPresentation({
      access: "none",
      coursesCount: 0,
      ownedLessonsCount: 0,
      requiredLessonsCount: 0,
    }),
    "no-lessons",
  );
  assert.equal(
    learningSubjectPresentation({
      access: "none",
      coursesCount: 0,
      ownedLessonsCount: 0,
      requiredLessonsCount: 8,
    }),
    "no-confirmed-courses",
  );
  assert.equal(
    learningSubjectPresentation({
      access: "full",
      coursesCount: 0,
      ownedLessonsCount: 4,
      requiredLessonsCount: 4,
    }),
    "contract-drift",
  );

  const driftFixtures = [
    { access: "full" as const, coursesCount: 1, ownedLessonsCount: 6, requiredLessonsCount: 13 },
    { access: "none" as const, coursesCount: 1, ownedLessonsCount: 6, requiredLessonsCount: 13 },
    { access: "partial" as const, coursesCount: 1, ownedLessonsCount: 0, requiredLessonsCount: 13 },
    { access: "full" as const, coursesCount: 1, ownedLessonsCount: 0, requiredLessonsCount: 0 },
  ];

  for (const fixture of driftFixtures) {
    assert.equal(learningSubjectPresentation(fixture), "contract-drift");
  }
});

test("uses study-completion vocabulary only for study progress", () => {
  const serialized = JSON.stringify(
    learningCoursePresentation({ access: "partial", ownedLessonsCount: 6, requiredLessonsCount: 13 }),
  );

  assert.match(serialized, /Пройдено/);
  assert.doesNotMatch(serialized, /Полный доступ|Частичный доступ|Покрытие доступа/i);
});
