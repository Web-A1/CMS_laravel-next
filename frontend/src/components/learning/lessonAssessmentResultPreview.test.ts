import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

const scenariosModulePath = "./lessonAssessmentResultPreviewScenarios.ts";
const scenariosModule: typeof import("./lessonAssessmentResultPreviewScenarios") = await import(
  scenariosModulePath
);
const {
  LESSON_ASSESSMENT_RESULT_PREVIEW_SCENARIOS,
  lessonAssessmentResultPreviewScenario,
} = scenariosModule;

const pageUrl = new URL(
  "../../app/dev/lesson-assessment-results/page.tsx",
  import.meta.url,
);
const previewUrl = new URL("./LessonAssessmentResultPreview.tsx", import.meta.url);
const resultUrl = new URL("./LessonAssessmentResult.tsx", import.meta.url);
const scenariosUrl = new URL("./lessonAssessmentResultPreviewScenarios.ts", import.meta.url);
const stylesUrl = new URL("./lesson-assessment.module.css", import.meta.url);

test("result preview covers every agreed scoring outcome with internally consistent flags", () => {
  assert.deepEqual(
    LESSON_ASSESSMENT_RESULT_PREVIEW_SCENARIOS.map((scenario) => scenario.id),
    [
      "failed-all",
      "failed-overall",
      "failed-section",
      "passed-baseline",
      "passed-high",
      "mastered",
    ],
  );

  for (const scenario of LESSON_ASSESSMENT_RESULT_PREVIEW_SCENARIOS) {
    const overallPassed = scenario.score.overall.percentBps >= scenario.score.overall.requiredBps;
    const mastered = scenario.score.overall.percentBps === 10000;

    assert.equal(scenario.score.checked, true, scenario.id);
    assert.equal(scenario.score.overall.passed, overallPassed, scenario.id);
    assert.equal(scenario.score.mastered, mastered, scenario.id);
  }
});

test("result preview includes exact threshold and mastery boundaries", () => {
  const failedOverall = lessonAssessmentResultPreviewScenario("failed-overall");
  const failedSection = lessonAssessmentResultPreviewScenario("failed-section");
  const baseline = lessonAssessmentResultPreviewScenario("passed-baseline");
  const high = lessonAssessmentResultPreviewScenario("passed-high");
  const mastered = lessonAssessmentResultPreviewScenario("mastered");

  assert.equal(failedOverall.score.overall.percentBps, 7999);
  assert.equal(failedOverall.score.passed, false);
  assert.equal(failedSection.score.overall.percentBps, 8500);
  assert.equal(failedSection.score.overall.passed, true);
  assert.equal(failedSection.score.components.manualBps, 5900);
  assert.equal(failedSection.score.passed, false);
  assert.equal(baseline.score.overall.percentBps, 8000);
  assert.equal(baseline.score.passed, true);
  assert.equal(baseline.score.mastered, false);
  assert.equal(high.score.overall.percentBps, 9999);
  assert.equal(high.score.passed, true);
  assert.equal(high.score.mastered, false);
  assert.equal(mastered.score.overall.percentBps, 10000);
  assert.equal(mastered.score.passed, true);
  assert.equal(mastered.score.mastered, true);
});

test("unknown preview scenario falls back to the first safe failure state", () => {
  assert.equal(lessonAssessmentResultPreviewScenario("unknown").id, "failed-all");
  assert.equal(lessonAssessmentResultPreviewScenario(undefined).id, "failed-all");
});

test("preview route is development-only and does not depend on assessment API mutations", async () => {
  const [pageSource, previewSource, resultSource, scenariosSource, stylesSource] = await Promise.all([
    readFile(pageUrl, "utf8"),
    readFile(previewUrl, "utf8"),
    readFile(resultUrl, "utf8"),
    readFile(scenariosUrl, "utf8"),
    readFile(stylesUrl, "utf8"),
  ]);

  assert.match(pageSource, /process\.env\.NODE_ENV !== "development"/);
  assert.match(pageSource, /notFound\(\)/);
  assert.doesNotMatch(`${pageSource}\n${previewSource}`, /lessonAssessmentApi/);
  assert.match(pageSource, /Предпросмотр результатов экзамена/);
  assert.match(previewSource, /LessonAssessmentResult/);
  assert.match(previewSource, /Посмотреть ответы/);
  assert.match(previewSource, /Сдать экзамен ещё раз/);
  assert.match(previewSource, /Результаты экзамена/);
  assert.match(previewSource, /content-shell page-gutter/);
  assert.match(previewSource, /data-ui="dev\.lessonAssessmentResult\.scenarioSelect"/);
  assert.match(previewSource, /className="hidden flex-wrap gap-2 lg:flex"/);
  assert.match(previewSource, /не отправляет запросы и не изменяет попытки или прогресс/);
  assert.match(previewSource, /scoringPolicy: LESSON_ASSESSMENT_RESULT_PREVIEW_SCORING_POLICY/);
  assert.doesNotMatch(previewSource, /scenario\.description/);
  assert.doesNotMatch(previewSource, /result-preview-heading/);
  assert.doesNotMatch(previewSource, /DEV_PREVIEW_SCORING_POLICY_FALLBACK/);
  assert.match(resultSource, /dataState=\{state\}/);
  assert.match(resultSource, /GeneratedImage/);
  assert.match(resultSource, /RESULT_IMAGES\[visualState\]/);
  assert.match(resultSource, /lesson-assessment-result-failed-1024x1024\.avif/);
  assert.match(resultSource, /lesson-assessment-result-passed-1024x1024\.avif/);
  assert.match(resultSource, /lesson-assessment-result-mastered-1024x1024\.avif/);
  assert.match(resultSource, /data-ui="lk\.learning\.assessment\.resultImage"/);
  assert.match(resultSource, /loading="eager"/);
  assert.match(resultSource, /fetchPriority="high"/);
  assert.match(resultSource, /percentBps=\{score\.overall\.percentBps\}/);
  assert.match(resultSource, /scorePassed=\{score\.passed\}/);
  assert.doesNotMatch(resultSource, /scorePassed=\{score\.overall\.passed\}/);
  assert.doesNotMatch(resultSource, /DEV_PREVIEW_SCORING_POLICY_FALLBACK/);
  assert.doesNotMatch(resultSource, /assessment\.scoringPolicy\s*\?\?/);
  assert.match(resultSource, /scorePercentValue\(percentBps\)/);
  assert.match(resultSource, /lessonAssessmentWeightedPoints/);
  assert.match(resultSource, /automaticContribution/);
  assert.match(resultSource, /Вклад автоматической части в итоговый результат/);
  assert.match(resultSource, /Ниже — результаты тестирования/);
  assert.match(resultSource, /resultIntroduction/);
  assert.match(resultSource, /automaticGateSectionRequiredBps/);
  assert.match(resultSource, /finalRequiredBps/);
  assert.match(resultSource, /manualReview\.weight/);
  assert.match(resultSource, /По проверенным ручным ответам набрано/);
  assert.match(resultSource, /earnedManualPointTenths/);
  assert.match(resultSource, /return Math\.floor\(percentBps \/ 100\)/);
  assert.match(resultSource, /return `\$\{scorePercentValue\(percentBps\)\}%`/);
  assert.match(resultSource, /assessmentResultPercentSign/);
  assert.doesNotMatch(resultSource, /percentBps % 100/);
  assert.doesNotMatch(resultSource, /80,00 %|60,00 %/);
  assert.doesNotMatch(resultSource, /Экзамен завершён/);
  assert.doesNotMatch(scenariosSource, /\d+,\d+ %/);
  assert.match(scenariosSource, /export const LESSON_ASSESSMENT_RESULT_PREVIEW_SCORING_POLICY/);
  assert.match(resultSource, /Экзамен не сдан — попробуйте ещё раз/);
  assert.match(resultSource, /Экзамен сдан — продолжайте в том же духе/);
  assert.match(resultSource, /Все задания выполнены верно — отличный результат/);
  assert.doesNotMatch(resultSource, /Что помешало сдать экзамен|failureReasons|showFailureReasons/);
  assert.match(resultSource, /lessonAssessmentQuestionRange/);
  assert.match(resultSource, /"проверено" : "проверены"/);
  assert.match(resultSource, /автоматически/);
  assert.match(resultSource, /Ручная часть/);
  assert.match(resultSource, /Ручная проверка/);
  assert.match(resultSource, /"передано" : "переданы"/);
  assert.match(resultSource, /на ручную проверку/);
  assert.match(resultSource, /Преподаватель проверил:/);
  assert.match(resultSource, /className=\{styles\.assessmentResultHeadline\}/);
  assert.match(resultSource, /className=\{`\$\{styles\.assessmentResultTitle\} text-xl font-bold/);
  assert.match(resultSource, /className=\{`\$\{styles\.assessmentResultScore\} \$\{styles\.assessmentResultBrandText\} font-bold/);
  assert.match(resultSource, /scorePassed \? styles\.assessmentResultBrandMetric/);
  assert.match(stylesSource, /\.assessmentResultBrandText \{[\s\S]*?color: var\(--color-brand-hover\);/);
  assert.match(stylesSource, /\.assessmentResultBrandMetric \{[\s\S]*?color: var\(--color-brand\);/);
  assert.match(resultSource, /после проверки появится окончательный результат экзамена/);
  assert.match(resultSource, /LessonAssessmentAutomaticScoreSummary/);
});

test("automatic failure result uses shared layout and policy-driven requirements", async () => {
  const [componentSource, resultSource] = await Promise.all([
    readFile(new URL("./LessonAssessment.tsx", import.meta.url), "utf8"),
    readFile(resultUrl, "utf8"),
  ]);
  const automaticFailureBranch = componentSource.slice(
    componentSource.indexOf('if (state.attempt.status === "failed" && state.attempt.failureReason === "automatic_threshold_not_met" && !reviewMode)'),
    componentSource.indexOf('if (submittedAttempt && !reviewMode && "score" in state.attempt)'),
  );

  assert.match(resultSource, /export function LessonAssessmentAutomaticFailureResult/);
  assert.match(resultSource, /dataState="automatic_failed"/);
  assert.match(resultSource, /dataUi="lk\.learning\.assessment\.automaticFailed"/);
  assert.match(resultSource, /scorePassed=\{false\}/);
  assert.match(resultSource, /visualState="failed"/);
  assert.match(resultSource, /titleLead="Автоматические задания не прошли проверку"/);
  assert.match(resultSource, /const overallRequired = formattedScorePercent\(automaticGate\.overall\.requiredBps\)/);
  assert.match(resultSource, /const sectionRequiredBps = automaticGate\.sections\.length > 0/);
  assert.match(resultSource, /const sectionRequired = sectionRequiredBps === null \? null : formattedScorePercent\(sectionRequiredBps\)/);
  assert.match(resultSource, /const requirements = sectionRequired === null/);
  assert.doesNotMatch(resultSource, /Math\.min\(\.\.\.\[\]\)|Infinity%/);
  assert.match(automaticFailureBranch, /<LessonAssessmentAutomaticFailureResult/);
  assert.doesNotMatch(automaticFailureBranch, /<section className="grid gap-4"/);
});
