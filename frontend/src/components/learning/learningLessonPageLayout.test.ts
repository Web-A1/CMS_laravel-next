import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

const lessonPagePath = new URL("../../app/(site)/lk/learning/[subjectSlug]/[courseSlug]/[lessonSlug]/page.tsx", import.meta.url);
const assessmentPagePath = new URL("../../app/(site)/lk/learning/[subjectSlug]/[courseSlug]/[lessonSlug]/exam/page.tsx", import.meta.url);
const assessmentLoadingPath = new URL("../../app/(site)/lk/learning/[subjectSlug]/[courseSlug]/[lessonSlug]/exam/loading.tsx", import.meta.url);
const assessmentStylesPath = new URL("./lesson-assessment.module.css", import.meta.url);
const lessonPageCssPath = new URL("../../app/(site)/lk/learning/[subjectSlug]/[courseSlug]/[lessonSlug]/lesson-page.module.css", import.meta.url);
const lessonNavArrowPath = new URL("./LessonNavArrowIcon.tsx", import.meta.url);
const protectedFilePanelPath = new URL("./ProtectedFileDeliveryPanel.tsx", import.meta.url);

test("keeps the lesson page as a single-window learning workspace", async () => {
  const [source, lessonNavArrowSource] = await Promise.all([
    readFile(lessonPagePath, "utf8"),
    readFile(lessonNavArrowPath, "utf8"),
  ]);

  for (const marker of [
    "lk.learning.lessonWorkspace",
    "lk.learning.lessonWorkspace.videoNavStack",
    "lk.learning.lessonWorkspace.video",
    "lk.learning.lessonWorkspace.navigation",
    "lk.learning.lessonWorkspace.sidePanel",
    "lk.learning.lessonWorkspace.actionGrid",
    "lk.learning.lessonWorkspace.support",
    "lk.learning.lessonWorkspace.divider",
    "lk.learning.lessonTeacherChat",
    "lk.learning.lessonTeacherChat.telegramLink",
    "lk.learning.lessonTest",
    "lk.learning.lessonTest.startButton",
    "lk.learning.lessonMaterial.header",
    "lk.learning.lessonMaterial.previousLessonLink",
    "lk.learning.lessonMaterial.nextLessonLink",
  ]) {
    assert.match(source, new RegExp(marker.replaceAll(".", "\\.")));
  }

  const headerBlock = source.match(/<header[\s\S]*?<\/header>/)?.[0] ?? "";
  assert.match(headerBlock, /lk-learning-lesson-title/);
  assert.match(headerBlock, /className="grid justify-items-start"/);
  assert.match(headerBlock, /className=\{`\$\{lessonHeadingClass\} min-w-0 text-left`\}/);
  assert.doesNotMatch(headerBlock, /LessonNavArrowIcon/);
  assert.doesNotMatch(headerBlock, /grid-cols-\[4rem_minmax\(0,1fr\)_4rem\]/);
  assert.match(source, /data-ui="lk\.learning\.lessonWorkspace\.videoNavStack"[\s\S]*?data-ui="lk\.learning\.lessonWorkspace\.video"[\s\S]*?<LessonNavigation/);
  assert.match(source, /aria-label="Навигация по урокам"/);
  assert.match(source, /className=\{`\$\{styles\.lessonNav\} flex items-center justify-between gap-4`\}/);
  assert.match(source, /gap-1 rounded-full text-xs font-medium leading-5 ui-text-muted-ink/);
  assert.match(source, /gap-1 rounded-full text-xs font-medium leading-5 ui-text-faint/);
  assert.match(source, /className=\{styles\.lessonNavLabel\}>Предыдущий урок/);
  assert.match(source, /className=\{styles\.lessonNavLabel\}>Следующий урок/);
  assert.match(source, /Вы можете написать вопрос преподавателю в чате Telegram</);
  assert.match(source, /TelegramMinimalIcon/);
  assert.match(source, /import styles from "\.\/lesson-page\.module\.css";/);
  assert.match(source, /className=\{`\$\{styles\.telegramIcon\} h-9 w-9 translate-y-1 transition-colors`\}/);
  assert.doesNotMatch(source, /<circle cx="64" cy="64" r="64"/);
  assert.match(source, /Проверить знания/);
  assert.match(source, /Начать экзамен/);
  assert.match(source, /Продолжить экзамен/);
  assert.match(source, /Посмотреть результат/);
  assert.match(source, /Смотреть результат/);
  assert.match(source, /Ответы на проверке у преподавателя\. Предварительный результат уже доступен\./);
  assert.match(source, /Экзамен сдан/);
  assert.match(source, /lesson\.testProgress\.retakeInProgress/);
  assert.match(source, /"Продолжить повторное прохождение"/);
  assert.match(source, /"Посмотреть результат"/);
  assert.match(source, /Результат сохранён\. Откройте экзамен, чтобы посмотреть ответы и итоговую оценку\./);
  assert.match(source, /data-ui="lk\.learning\.lessonTest\.resultButton"/);
  assert.doesNotMatch(source, /LessonAssessmentRetakeButton/);
  assert.doesNotMatch(source, /retakeLessonAssessment/);
  assert.match(source, /<time dateTime=\{completedAt\}/);
  assert.match(source, /Результат сохранится и обновит прогресс курса/);
  assert.match(source, /import \{ ButtonRouteLink \} from "@\/components\/button\/Button"/);
  assert.doesNotMatch(source, /className="w-full sm:w-fit"/);
  const testPanelStart = source.indexOf("function LessonTestPanel");
  const testPanelEnd = source.indexOf("type LessonNavigationProps", testPanelStart);
  const testPanelSource = source.slice(testPanelStart, testPanelEnd);
  assert.ok(testPanelStart >= 0 && testPanelEnd > testPanelStart);
  assert.match(testPanelSource, /<ButtonRouteLink[\s\S]*?className="w-fit" fullWidth=\{false\}/);
  assert.match(testPanelSource, /data-ui="lk\.learning\.lessonTest\.resultButton"/);
  assert.doesNotMatch(testPanelSource, /className="button__content"/);
  assert.match(testPanelSource, /`\$\{lesson\.canonicalPath\}\/exam`/);
  assert.doesNotMatch(testPanelSource, /ButtonLink|<a/);
  assert.doesNotMatch(testPanelSource, /\/test/);
  assert.doesNotMatch(testPanelSource, /href="#"/);
  assert.match(source, /className="grid min-w-0 gap-5" data-ui="lk\.learning\.lessonMaterial"/);
  assert.match(source, /className="grid min-w-0 gap-8 md:gap-10"/);
  assert.match(source, /className="mx-auto grid w-full min-w-0 gap-5"/);
  assert.match(source, /className="grid min-w-0 gap-5" data-ui="lk\.learning\.lessonWorkspace\.videoNavStack"/);
  assert.match(source, /className=\{`\$\{styles\.lessonVideoViewport\} min-w-0`\}/);
  assert.doesNotMatch(source, /videoFrameClassName/);
  assert.match(source, /className="grid min-w-0 gap-7 md:gap-8"/);
  assert.match(source, /lg:grid-cols-\[minmax\(0,2\.75fr\)_1px_minmax\(19rem,0\.75fr\)\]/);
  assert.match(source, /lg:items-stretch/);
  assert.match(source, /className="hidden w-px self-stretch bg-\[var\(--color-border-subtle\)\] lg:block"/);
  assert.match(source, /className="grid content-start gap-4"/);
  assert.match(source, /className="pt-2 md:pt-3"/);
  assert.match(source, /className=\{`\$\{styles\.telegramLink\} inline-flex w-fit max-w-2xl items-center rounded-\[var\(--radius-control\)\] bg-\[var\(--color-surface-subtle\)\] px-4 py-2 text-sm/);
  assert.match(source, /className="inline-flex -translate-y-1\.5 items-end gap-1\.5"/);
  assert.doesNotMatch(source, /className="border-t border-\[var\(--color-border-subtle\)\] pt-4 md:pt-5"/);
  assert.doesNotMatch(source, /md:border-l/);
  assert.match(source, /previousLessonLabel/);
  assert.match(source, /nextLessonLabel/);
  assert.match(source, /const previousLessonLabel = previousLesson \? previousLesson\.title : "Предыдущий урок недоступен"/);
  assert.match(source, /const nextLessonLabel = nextLesson \? nextLesson\.title : "Следующий урок недоступен"/);
  assert.doesNotMatch(source, /Предыдущий урок: \$\{previousLesson\.title\}/);
  assert.doesNotMatch(source, /Следующий урок: \$\{nextLesson\.title\}/);
  assert.match(source, />Предыдущий урок</);
  assert.match(source, />Следующий урок</);
  assert.match(source, /LessonNavArrowIcon/);
  assert.match(lessonNavArrowSource, /M5 12H59/);
  assert.match(lessonNavArrowSource, /strokeWidth="1\.25"/);
  assert.match(lessonNavArrowSource, /13 8 5 12 13 16/);
  assert.match(lessonNavArrowSource, /51 8 59 12 51 16/);
  assert.match(source, /direction="previous"/);
  assert.match(source, /direction="next"/);
  assert.match(source, /variant="chevron"/);
  assert.match(lessonNavArrowSource, /h-6 w-10/);
  assert.match(lessonNavArrowSource, /sm:w-14/);
  assert.doesNotMatch(source, /grid-cols-\[4rem_minmax\(0,1fr\)_4rem\]/);
  assert.match(source, /data-ui="lk\.learning\.lessonWorkspace\.video"/);
  assert.doesNotMatch(source, /← Предыдущий/);
  assert.doesNotMatch(source, /Следующий →/);
  assert.doesNotMatch(source, /hover:bg-\[var\(--color-surface-subtle\)\]/);
  assert.match(lessonNavArrowSource, /group-hover:-translate-x-0\.5/);
  assert.match(lessonNavArrowSource, /group-hover:translate-x-0\.5/);
  assert.doesNotMatch(source, /group-hover:-?translate-y/);
  assert.doesNotMatch(source, /lg:sticky/);
  assert.doesNotMatch(source, /LearningMaterialManifest/);
  assert.doesNotMatch(source, /sm:grid-cols-\[minmax\(0,1fr\)_auto\]/);
  assert.doesNotMatch(source, /Текст урока готовится/);
  assert.doesNotMatch(source, /data-ui="lk\.learning\.lessonMaterial\.courseLink"/);
});

test("removes the test track when the strict lesson state has no assessment", async () => {
  const source = await readFile(lessonPagePath, "utf8");
  const actionGridStart = source.indexOf('data-ui="lk.learning.lessonWorkspace.actionGrid"');
  const actionGridEnd = source.indexOf('data-ui="lk.learning.lessonTeacherChat"', actionGridStart);
  const actionGridSource = source.slice(actionGridStart, actionGridEnd);

  assert.ok(actionGridStart >= 0 && actionGridEnd > actionGridStart);
  assert.match(
    source,
    /const hasLessonTest =\s*lessonState\.status === "ready" &&\s*lessonState\.data\.testAvailable &&\s*lessonState\.data\.testProgress !== null;/,
  );
  assert.match(
    source,
    /className=\{hasLessonTest\s*\? "grid min-w-0 gap-6 lg:grid-cols-\[minmax\(0,2\.75fr\)_1px_minmax\(19rem,0\.75fr\)\] lg:items-stretch lg:gap-8"\s*: "grid min-w-0 gap-6"\}/,
  );
  assert.match(
    actionGridSource,
    /\{hasLessonTest \? \([\s\S]*?data-ui="lk\.learning\.lessonWorkspace\.divider"[\s\S]*?<LessonTestPanel[\s\S]*?lesson=\{lessonState\.data\}[\s\S]*?\/>[\s\S]*?\) : null\}/,
  );
});

test("connects the protected assessment route to the strict server loader", async () => {
  const [source, loadingSource, lessonNavArrowSource, assessmentStylesSource] = await Promise.all([
    readFile(assessmentPagePath, "utf8"),
    readFile(assessmentLoadingPath, "utf8"),
    readFile(lessonNavArrowPath, "utf8"),
    readFile(assessmentStylesPath, "utf8"),
  ]);

  assert.match(source, /export const metadata: Metadata = \{[\s\S]*?robots: \{[\s\S]*?index: false,[\s\S]*?follow: false/);
  assert.match(source, /const route: LessonAssessmentRoute = \{ subjectSlug, courseSlug, lessonSlug \}/);
  assert.match(source, /loadLessonAssessment\(apiBaseUrl, lessonPath, route\)/);
  assert.match(source, /assessmentState\.status === "not_found"[\s\S]*?notFound\(\)/);
  assert.doesNotMatch(source, /assessmentState\.status === "denied"[\s\S]{0,80}notFound\(\)/);
  assert.doesNotMatch(source, /assessmentState\.status === "unavailable"[\s\S]{0,80}notFound\(\)/);
  assert.match(source, /<LessonAssessment[\s\S]*?apiBaseUrl=\{apiBaseUrl\}[\s\S]*?initialData=\{assessmentState\.data\}[\s\S]*?lessonPath=\{lessonPath\}[\s\S]*?route=\{route\}/);
  assert.match(source, /data-ui="lk\.learning\.assessmentPage"/);
  assert.match(source, /Экзамен/);
  assert.match(source, /LessonNavArrowIcon/);
  assert.match(source, /LessonNavArrowIcon direction="previous" className="-ml-1"/);
  assert.match(source, /className="sr-only"/);
  assert.match(source, /import styles from "@\/components\/learning\/lesson-assessment\.module\.css"/);
  assert.match(source, /<main className=\{`\$\{styles\.assessmentPage\} grid min-w-0 gap-3`\}/);
  assert.match(
    assessmentStylesSource,
    /--assessment-viewport-offset:[\s\S]*?var\(--header-visible-bottom\)[\s\S]*?var\(--breadcrumbs-gap\)[\s\S]*?var\(--breadcrumbs-line-height\)/,
  );
  assert.match(assessmentStylesSource, /--assessment-footer-reveal:\s*4rem/);
  assert.match(
    assessmentStylesSource,
    /\.assessmentPage \{[\s\S]*?min-height:\s*calc\(\s*100dvh - var\(--assessment-viewport-offset\) - var\(--page-content-bottom\) -\s*var\(--assessment-footer-reveal\)\s*\);[\s\S]*?align-content:\s*start;/,
  );
  assert.doesNotMatch(assessmentStylesSource, /body\s*\{[\s\S]*?overflow:\s*hidden/);
  assert.doesNotMatch(source, /justify-between gap-4 border-b/);
  assert.doesNotMatch(source, /justify-between gap-4 pb-3/);
  assert.match(source, /`Экзамен: \$\{assessmentState\.data\.assessment\.title\}`/);
  assert.match(lessonNavArrowSource, /M5 12H59/);
  assert.match(source, /data-ui="lk\.learning\.assessmentDenied"[\s\S]*?data-state="denied"/);
  assert.match(source, /data-ui="lk\.learning\.assessmentError"[\s\S]*?data-state="unavailable"/);
  assert.match(source, /Доступ к экзамену не подтверждён/);
  assert.match(source, /Не удалось загрузить экзамен/);
  assert.match(source, /Вернуться к уроку/);
  assert.doesNotMatch(source, /answerKey|answer_key|correctOptionIds/);
  assert.match(loadingSource, /aria-busy="true"/);
  assert.match(loadingSource, /data-ui="lk\.learning\.assessmentLoading"/);
});

test("keeps lesson video full-bleed only on mobile screens", async () => {
  const source = await readFile(lessonPageCssPath, "utf8");

  assert.match(source, /\.lessonVideoViewport \{[\s\S]*?width: 100dvw;[\s\S]*?margin-inline: calc\(var\(--page-gutter\) \* -1\);/);
  assert.match(source, /@media \(min-width: 448px\) and \(max-width: 639\.98px\) \{[\s\S]*?margin-inline: calc\(50% - 50dvw\);/);
  assert.match(source, /@media \(min-width: 640px\) \{[\s\S]*?\.lessonVideoViewport \{[\s\S]*?margin-inline: 0;/);
  assert.doesNotMatch(source, /\.lessonVideoFrame|border-radius|aspect-ratio|padding-top|backdrop-filter|filter\s*:|overflow\s*:/);
});

test("keeps lesson navigation from widening narrow mobile screens", async () => {
  const source = await readFile(lessonPageCssPath, "utf8");

  assert.match(source, /\.lessonNav \{[\s\S]*?min-width: 0;/);
  assert.match(source, /\.lessonNavItem \{[\s\S]*?min-width: 0;/);
  assert.match(source, /\.lessonNavLabel \{[\s\S]*?white-space: nowrap;/);
  assert.match(source, /@media \(max-width: 359\.98px\) \{[\s\S]*?\.lessonNavItem \{[\s\S]*?flex: 1 1 0;[\s\S]*?\.lessonNavLabel \{[\s\S]*?max-width: 4\.5rem;[\s\S]*?white-space: normal;/);
});

test("renders protected lesson files as document rows with a download capsule", async () => {
  const source = await readFile(protectedFilePanelPath, "utf8");

  assert.match(source, /Скачать обучающие материалы/);
  assert.match(source, /<Button variant="secondary" size="sm" fullWidth=\{false\}/);
  assert.match(source, /data-ui="lk\.learning\.lessonFiles\.list"/);
  assert.match(source, /<ul className="grid gap-3"/);
  assert.match(source, /items-end gap-x-3/);
  assert.match(source, /border-dashed ui-border-control/);
  assert.match(source, /mb-1 hidden h-px/);
  assert.match(source, /const actionLabel =/);
  assert.match(source, /"Скачать"/);
  assert.match(source, /"Повторить"/);
  assert.match(source, /state\.status === "accessing" \? "Скачивание\.\.\."/);
  assert.match(source, /showActionButton/);
  assert.match(source, /state\.status === "ready" \|\|\s*state\.status === "accessing"/);
  assert.match(source, /trailingStatusMessage/);
  assert.match(source, /data-ui="lk\.learning\.lessonFiles\.empty"/);
  assert.match(source, /PDF пока не прикреплены/);
  assert.match(source, /data-ui=\{actionDataUi\}/);
  assert.doesNotMatch(source, /downloadStarted/);
  assert.doesNotMatch(source, /Скачивание началось/);
  assert.doesNotMatch(source, /Скачано успешно/);
  assert.doesNotMatch(source, /if \(files\.length === 0\) \{\s*return null;\s*\}/);
  assert.doesNotMatch(source, /PDF готовится в фоне при открытии урока/);
  assert.doesNotMatch(source, /Нажмите «Скачать PDF»/);
});
