import assert from "node:assert/strict";
import { access, readFile } from "node:fs/promises";
import { fileURLToPath } from "node:url";
import test from "node:test";

const appRoot = fileURLToPath(new URL("../../app/(site)/lk/", import.meta.url));
const componentPath = new URL("./LearningPathUi.tsx", import.meta.url);
const sidebarFramePath = new URL("./LearningSidebarFrame.tsx", import.meta.url);
const learningLayoutStylesPath = new URL("./learning-layout.css", import.meta.url);
const learningLayoutPath = new URL("../../app/(site)/lk/learning/layout.tsx", import.meta.url);
const learningRootPagePath = new URL("../../app/(site)/lk/learning/page.tsx", import.meta.url);
const subjectPagePath = new URL("../../app/(site)/lk/learning/[subjectSlug]/page.tsx", import.meta.url);

const sidebarTransitionLoadingFiles = [
  "learning/[subjectSlug]/loading.tsx",
  "learning/[subjectSlug]/[courseSlug]/loading.tsx",
  "learning/[subjectSlug]/[courseSlug]/[lessonSlug]/loading.tsx",
] as const;

const expectedRouteFiles = [
  "layout.tsx",
  "default.tsx",
  "learning/layout.tsx",
  "learning/default.tsx",
  "learning/not-found.tsx",
  "learning/@sidebar/default.tsx",
  "learning/@sidebar/[subjectSlug]/page.tsx",
  "learning/@sidebar/[subjectSlug]/[courseSlug]/page.tsx",
  "learning/@sidebar/[subjectSlug]/[courseSlug]/[lessonSlug]/page.tsx",
  "learning/@sidebar/[subjectSlug]/[courseSlug]/[lessonSlug]/exam/page.tsx",
  "learning/[subjectSlug]/[courseSlug]/[lessonSlug]/exam/loading.tsx",
  "learning/[subjectSlug]/[courseSlug]/[lessonSlug]/exam/page.tsx",
];

const removedRouteFiles = [
  ...sidebarTransitionLoadingFiles,
  "learning/@sidebar/loading.tsx",
  "learning/[subjectSlug]/layout.tsx",
  "learning/[subjectSlug]/default.tsx",
  "learning/[subjectSlug]/@sidebar/default.tsx",
  "learning/[subjectSlug]/@sidebar/loading.tsx",
  "learning/[subjectSlug]/@sidebar/page.tsx",
  "learning/[subjectSlug]/@sidebar/[courseSlug]/page.tsx",
  "learning/[subjectSlug]/@sidebar/[courseSlug]/[lessonSlug]/page.tsx",
  "learning/[subjectSlug]/@sidebar/[courseSlug]/[lessonSlug]/exam/page.tsx",
  "learning/[subjectSlug]/@sidebar/[courseSlug]/[lessonSlug]/test/page.tsx",
  "learning/[subjectSlug]/[courseSlug]/[lessonSlug]/test/loading.tsx",
  "learning/[subjectSlug]/[courseSlug]/[lessonSlug]/test/page.tsx",
];

test("keeps sidebar-driven transitions out of compact route fallbacks while preserving exam loading", async () => {
  await Promise.all(
    sidebarTransitionLoadingFiles.map((relativePath) =>
      assert.rejects(access(`${appRoot}/${relativePath}`), { code: "ENOENT" }),
    ),
  );
  await access(`${appRoot}/learning/[subjectSlug]/[courseSlug]/[lessonSlug]/exam/loading.tsx`);
});

test("contains the lifted learning sidebar route files and loads assessment questions for the exam", async () => {
  await Promise.all(expectedRouteFiles.map((relativePath) => access(`${appRoot}/${relativePath}`)));
  await Promise.all(
    removedRouteFiles.map((relativePath) =>
      assert.rejects(access(`${appRoot}/${relativePath}`), { code: "ENOENT" }),
    ),
  );

  const mirroredTestSidebar = await readFile(
    `${appRoot}/learning/@sidebar/[subjectSlug]/[courseSlug]/[lessonSlug]/exam/page.tsx`,
    "utf8",
  );
  assert.match(mirroredTestSidebar, /loadLessonAssessment/);
  assert.match(mirroredTestSidebar, /LessonAssessmentSidebar/);
  assert.match(mirroredTestSidebar, /questions=\{questions\}/);
  assert.match(mirroredTestSidebar, /gradingMode: question\.gradingMode/);
  assert.match(
    mirroredTestSidebar,
    /questionResults=\{state\.data\.attempt !== null && "automaticGate" in state\.data\.attempt[\s\S]*state\.data\.attempt\.automaticGate\.questionResults/,
  );
  assert.match(mirroredTestSidebar, /reviewMode=\{reviewMode\}/);
  assert.match(mirroredTestSidebar, /submittedAttempt=\{submittedAttempt\}/);
  assert.match(mirroredTestSidebar, /state\.data\.attempt !== null && state\.data\.attempt\.status !== "in_progress"/);
  assert.match(mirroredTestSidebar, /const reviewMode = submittedAttempt && reviewParameter === "answers";/);
  assert.doesNotMatch(mirroredTestSidebar, /if \(submittedAttempt && !reviewMode\) \{\s*return null;/);
});

test("keeps learning not-found inside the stable layout without duplicating the page shell", async () => {
  const notFoundSource = await readFile(`${appRoot}/learning/not-found.tsx`, "utf8");

  assert.match(notFoundSource, /data-ui="notFound"/);
  assert.match(notFoundSource, /Страница не найдена/);
  assert.doesNotMatch(notFoundSource, /content-shell|page-content|page-gutter/);
});

test("keeps locked lessons in a native accessible dialog without a CTA or navigation side effect", async () => {
  const source = await readFile(componentPath, "utf8");
  const dialogStart = source.indexOf("function LockedLessonDialog");
  const dialogEnd = source.indexOf("export function LearningLessonNavigation", dialogStart);
  const dialogSource = source.slice(dialogStart, dialogEnd);

  assert.ok(dialogStart >= 0 && dialogEnd > dialogStart);
  assert.match(dialogSource, /<dialog/);
  assert.match(dialogSource, /showModal\(\)/);
  assert.match(dialogSource, /data-ui="lk\.learning\.lesson\.lockedDialog"/);
  assert.match(dialogSource, /после покупки родительского курса/i);
  assert.doesNotMatch(dialogSource, /<Link|href=|router|history|fetch\(|\/lk\/cart|купить|приобрести/i);
});

test("keeps stable navigation, active, locked-card and route-announcement markers", async () => {
  const [source, frameSource, layoutSource, rootPageSource, contentStyles, subjectSidebar, courseSidebar, lessonSidebar] =
    await Promise.all([
      readFile(componentPath, "utf8"),
      readFile(sidebarFramePath, "utf8"),
      readFile(learningLayoutPath, "utf8"),
      readFile(learningRootPagePath, "utf8"),
      readFile(learningLayoutStylesPath, "utf8"),
      readFile(`${appRoot}/learning/@sidebar/[subjectSlug]/page.tsx`, "utf8"),
      readFile(`${appRoot}/learning/@sidebar/[subjectSlug]/[courseSlug]/page.tsx`, "utf8"),
      readFile(`${appRoot}/learning/@sidebar/[subjectSlug]/[courseSlug]/[lessonSlug]/page.tsx`, "utf8"),
    ]);

  for (const marker of [
    "lk.learning.sidebar",
    "lk.learning.sidebar.item",
    "lk.learning.sidebar.item.active",
    "lk.learning.courseCard",
    "lk.learning.courseCard.locked",
  ]) {
    assert.match(source, new RegExp(marker.replaceAll(".", "\\.")));
  }
  assert.match(source, /aria-current=/);
  assert.match(source, /aria-live="polite"/);
  assert.match(source, /\.focus\(\)/);
  assert.doesNotMatch(source, /usePathname|useSearchParams/);
  assert.doesNotMatch(source, /data-\[state=active\]:order-first/);

  assert.match(layoutSource, /<LearningSidebarFrame>\{sidebar\}<\/LearningSidebarFrame>/);
  assert.match(layoutSource, /data-ui="lk\.learning\.layout"/);
  assert.match(frameSource, /^"use client";/);
  assert.match(frameSource, /useLayoutEffect/);
  assert.match(frameSource, /new MutationObserver\(bindCurrentNavigation\)/);
  assert.match(frameSource, /observer\.observe\(aside, \{ childList: true, subtree: true \}\)/);
  assert.match(frameSource, /new Map<string, SidebarScrollPosition>/);
  assert.match(frameSource, /data-learning-navigation-scope/);
  assert.match(frameSource, /addEventListener\("scroll", rememberPosition, \{ passive: true \}\)/);
  assert.match(frameSource, /scrollFrame\.scrollLeft = savedPosition\.left/);
  assert.doesNotMatch(frameSource, /usePathname|fetch\(|localStorage|sessionStorage/);

  assert.match(subjectSidebar, /navigationScope="subjects"/);
  assert.match(courseSidebar, /navigationScope=\{`courses:\$\{subjectSlug\}`\}/);
  assert.match(lessonSidebar, /navigationScope=\{`lessons:\$\{subjectSlug\}:\$\{courseSlug\}`\}/);
  assert.doesNotMatch(subjectSidebar, /notFound/);
  assert.doesNotMatch(courseSidebar, /notFound/);
  assert.doesNotMatch(lessonSidebar, /notFound/);
  assert.match(contentStyles, /\.lk-learning-sidebar-frame:has\(> \.lk-learning-sidebar-scroll-frame:empty\)/);
  assert.match(
    contentStyles,
    /\.lk-learning-detail-layout:has\(> \.lk-learning-sidebar-frame > \.lk-learning-sidebar-scroll-frame:empty\)/,
  );
  assert.doesNotMatch(rootPageSource, /content-shell|page-content|page-gutter/);
});

test("renders the exact five backend-owned lesson test states", async () => {
  const source = await readFile(componentPath, "utf8");

  assert.match(source, /data-ui="lk\.learning\.course\.lesson\.videoProgress"/);
  assert.match(source, /data-ui="lk\.learning\.course\.lesson\.testStatus"/);
  assert.match(source, /role="progressbar"/);
  assert.match(source, /Видео: не начато/);
  assert.match(source, /Экзамен пока не назначен/);
  assert.match(source, /Экзамен не начат/);
  assert.match(source, /Экзамен: \$\{lesson\.testProgress\.answeredCount\} из \$\{lesson\.testProgress\.totalCount\}/);
  assert.match(source, /Экзамен ожидает проверки/);
  assert.match(source, /Экзамен сдан/);
  assert.match(source, /Экзамен не сдан/);
  assert.match(source, /Экзамен недоступен/);
});

test("passes the backend-owned completion count into course status presentation", async () => {
  const source = await readFile(subjectPagePath, "utf8");

  assert.match(source, /learningCoursePresentation\(\{[\s\S]*?completedLessonsCount: course\.completedLessonsCount,/);
});
