import type { ReactNode } from "react";

import GeneratedImage, { type StaticGeneratedImageAsset } from "@/components/content/GeneratedImage";
import type {
  LessonAssessment,
  LessonAssessmentAutomaticGate,
  LessonAssessmentScoringPolicy,
  LessonAssessmentScore,
} from "@/lib/lessonAssessmentContract";

import styles from "./lesson-assessment.module.css";
import {
  lessonAssessmentQuestionRange,
  lessonAssessmentVisibleQuestionNumberById,
  lessonAssessmentWeightedPoints,
} from "./lessonAssessmentResultModel";
import { lessonAssessmentNavigationItems } from "./lessonAssessmentSentenceCompletionModel";

type LessonAssessmentResultContext = Pick<LessonAssessment, "questions" | "scoringPolicy" | "sections">;
type LessonAssessmentReviewContext = LessonAssessmentResultContext & Pick<LessonAssessment, "scoringPolicy">;
type LessonAssessmentResultState = "failed" | "mastered" | "passed";

const RESULT_IMAGES: Readonly<Record<LessonAssessmentResultState, StaticGeneratedImageAsset>> = {
  failed: {
    avifSrc: "/images/v2/lesson-assessment/results/lesson-assessment-result-failed-1024x1024.avif",
    webpSrc: "/images/v2/lesson-assessment/results/lesson-assessment-result-failed-1024x1024.webp",
    width: 1024,
    height: 1024,
  },
  passed: {
    avifSrc: "/images/v2/lesson-assessment/results/lesson-assessment-result-passed-1024x1024.avif",
    webpSrc: "/images/v2/lesson-assessment/results/lesson-assessment-result-passed-1024x1024.webp",
    width: 1024,
    height: 1024,
  },
  mastered: {
    avifSrc: "/images/v2/lesson-assessment/results/lesson-assessment-result-mastered-1024x1024.avif",
    webpSrc: "/images/v2/lesson-assessment/results/lesson-assessment-result-mastered-1024x1024.webp",
    width: 1024,
    height: 1024,
  },
};

function scorePercentValue(percentBps: number): number {
  return Math.floor(percentBps / 100);
}

function formattedScorePercent(percentBps: number): string {
  return `${scorePercentValue(percentBps)}%`;
}

function scoringPolicyThresholds(scoringPolicy: LessonAssessmentScoringPolicy) {
  return {
    finalRequired: scorePercentValue(scoringPolicy.finalRequiredBps),
    finalRequiredPercent: formattedScorePercent(scoringPolicy.finalRequiredBps),
    automaticRequired: formattedScorePercent(scoringPolicy.automaticGateOverallRequiredBps),
    automaticSectionRequired: formattedScorePercent(scoringPolicy.automaticGateSectionRequiredBps),
    manualRequired: formattedScorePercent(scoringPolicy.manualRequiredBps),
  };
}

function LessonAssessmentResultLayout({
  actions,
  ariaLive,
  dataState,
  dataUi,
  details,
  feedback,
  footerContent,
  followup,
  intro,
  metric,
  percentBps,
  requirements,
  scorePassed,
  titleLead,
  titlePrompt,
  visualState,
}: {
  readonly actions: ReactNode;
  readonly ariaLive?: "polite";
  readonly dataState: string;
  readonly dataUi: string;
  readonly details: ReactNode;
  readonly feedback?: ReactNode;
  readonly footerContent?: ReactNode;
  readonly followup?: ReactNode;
  readonly intro?: ReactNode;
  readonly metric?: {
    readonly ariaLabel: string;
    readonly dataUi: string;
    readonly suffix: string;
    readonly value: number;
  };
  readonly percentBps: number;
  readonly requirements?: ReactNode;
  readonly scorePassed: boolean;
  readonly titleLead: string;
  readonly titlePrompt?: string;
  readonly visualState: LessonAssessmentResultState;
}) {
  const displayedMetric = metric ?? {
    ariaLabel: `Общий результат: ${formattedScorePercent(percentBps)}`,
    dataUi: "lk.learning.assessment.overallScore",
    suffix: "%",
    value: scorePercentValue(percentBps),
  };

  return (
    <section
      className="grid w-full max-w-6xl overflow-hidden bg-white"
      aria-live={ariaLive}
      data-ui={dataUi}
      data-state={dataState}
    >
      <div className={styles.assessmentResultMain}>
        {intro ? (
          <p
            className={`${styles.assessmentResultIntro} ui-text-secondary mb-6 px-6 pt-8 text-sm leading-6 sm:px-8 lg:mb-8 lg:px-0 lg:pt-0`}
            data-ui="lk.learning.assessment.resultIntroduction"
          >
            {intro}
          </p>
        ) : null}

        <header className={`${styles.assessmentResultHero} px-6 ${intro ? "pt-0" : "pt-8"} sm:px-8 lg:px-0 lg:pt-0`}>
          <div className={styles.assessmentResultHeadline}>
            <h2 className={`${styles.assessmentResultTitle} text-xl font-bold leading-tight ui-text-emphasis sm:text-2xl`}>
              <span className="block">{titleLead}{titlePrompt ? " —" : ""}</span>
              {titlePrompt ? <span className="block">{titlePrompt}</span> : null}
            </h2>
            <p
              className={`${styles.assessmentResultScore} ${scorePassed ? styles.assessmentResultBrandText : "ui-text-danger"} font-bold tracking-tight tabular-nums`}
              aria-label={displayedMetric.ariaLabel}
              data-ui={displayedMetric.dataUi}
            >
              <span className={`${scorePassed ? styles.assessmentResultBrandMetric : ""} text-5xl leading-none sm:text-6xl`}>
                {displayedMetric.value}
              </span>
              <span
                className={`${styles.assessmentResultPercentSign} ${metric ? "text-sm font-medium sm:text-base" : "text-xl sm:text-2xl"} leading-tight`}
              >
                {displayedMetric.suffix}
              </span>
            </p>
          </div>
          {requirements ? (
            <p className={`${styles.assessmentResultRequirements} ui-text-secondary`}>
              {requirements}
            </p>
          ) : null}
        </header>

        <div className={`${styles.assessmentResultScores} mt-6 px-6 sm:px-8 lg:pl-0 lg:pr-0`}>
          {details}
        </div>

        {followup}

        <footer
          className={`${styles.assessmentResultActions} mt-6 grid gap-4 px-6 pb-8 sm:px-8 lg:mt-10 lg:pl-0 lg:pr-12 lg:pb-0`}
        >
          {actions ? <div className="flex flex-wrap justify-start gap-3">{actions}</div> : null}
          {feedback}
          {footerContent}
        </footer>

        <div
          className={styles.assessmentResultPerson}
          aria-hidden="true"
          data-ui="lk.learning.assessment.resultImage"
        >
          <GeneratedImage
            image={RESULT_IMAGES[visualState]}
            alt=""
            className={styles.assessmentResultImage}
            loading="eager"
            decoding="async"
            fetchPriority="high"
          />
        </div>
      </div>
    </section>
  );
}

function LessonAssessmentAutomaticScoreSummary({
  assessment,
  automaticGate,
}: {
  readonly assessment: LessonAssessmentResultContext;
  readonly automaticGate: LessonAssessmentAutomaticGate;
}) {
  const sectionTitles = new Map(assessment.sections.map((section) => [section.id, section.title]));

  return (
    <div className="grid gap-4" data-ui="lk.learning.assessment.automaticScoreSummary">
      <dl className={`${styles.assessmentResultAutomaticScores} grid text-sm`}>
        {automaticGate.sections.map((section) => (
          <div
            key={section.sectionId}
            className={styles.assessmentResultAutomaticScoreItem}
          >
            <dt className="ui-text-secondary inline leading-5">{sectionTitles.get(section.sectionId) ?? section.sectionId}</dt>
            <dd
              className={`${section.passed ? styles.assessmentResultBrandText : "ui-text-danger"} ml-2 inline font-semibold tabular-nums`}
              data-state={section.passed ? "passed" : "failed"}
            >
              {formattedScorePercent(section.percentBps)}
              {!section.passed ? <span className="sr-only"> — ниже порога</span> : null}
            </dd>
          </div>
        ))}
      </dl>
    </div>
  );
}

export function LessonAssessmentScoreSummary({
  score,
  showOverall = true,
}: {
  readonly assessment: LessonAssessmentResultContext;
  readonly score: LessonAssessmentScore;
  readonly showOverall?: boolean;
}) {
  return (
    <div className="grid gap-4" data-ui="lk.learning.assessment.scoreSummary">
      {showOverall ? (
        <p
          className={`${score.overall.passed ? styles.assessmentResultBrandText : "ui-text-danger"} text-lg font-bold`}
          data-ui="lk.learning.assessment.overallScore"
        >
          Общий результат: {formattedScorePercent(score.overall.percentBps)}
          {!score.overall.passed ? <span className="sr-only"> — ниже порога</span> : null}
        </p>
      ) : null}
      <dl className={styles.assessmentResultScoreGrid}>
        <div className={styles.assessmentResultScoreItem}><dt className="ui-text-secondary">Автоматическая часть</dt><dd className="font-semibold tabular-nums">{formattedScorePercent(score.components.automaticBps)}</dd></div>
        <div className={styles.assessmentResultScoreItem}><dt className="ui-text-secondary">Ручная часть</dt><dd className="font-semibold tabular-nums">{formattedScorePercent(score.components.manualBps)}</dd></div>
      </dl>
    </div>
  );
}

export function LessonAssessmentReviewResult({
  actions,
  assessment,
  automaticGate,
  feedback,
  finalScore,
  manualProgressBps,
  reviewedManualQuestionCount,
}: {
  readonly actions: ReactNode;
  readonly assessment: LessonAssessmentReviewContext;
  readonly automaticGate: LessonAssessmentAutomaticGate;
  readonly feedback?: ReactNode;
  readonly finalScore?: LessonAssessmentScore;
  readonly manualProgressBps: number;
  readonly reviewedManualQuestionCount: number;
}) {
  const navigationItems = lessonAssessmentNavigationItems(assessment.questions);
  const automaticQuestions = lessonAssessmentQuestionRange(
    assessment.questions,
    navigationItems,
    "automatic",
  );
  const manualQuestions = lessonAssessmentQuestionRange(
    assessment.questions,
    navigationItems,
    "manual",
  );
  const automaticPoints = lessonAssessmentWeightedPoints(
    automaticGate.overall.percentBps,
    assessment.scoringPolicy.automaticWeightBps,
  );
  const manualPoints = Math.floor(assessment.scoringPolicy.manualWeightBps / 100);
  const earnedManualPointTenths = Math.round(
    (manualProgressBps * assessment.scoringPolicy.manualWeightBps) / 100_000,
  );
  const earnedManualPoints = earnedManualPointTenths % 10 === 0
    ? String(earnedManualPointTenths / 10)
    : `${Math.floor(earnedManualPointTenths / 10)},${earnedManualPointTenths % 10}`;
  const finalRequired = scorePercentValue(assessment.scoringPolicy.finalRequiredBps);
  const automaticRequired = scorePercentValue(
    assessment.scoringPolicy.automaticGateOverallRequiredBps,
  );
  const automaticSectionRequired = scorePercentValue(
    assessment.scoringPolicy.automaticGateSectionRequiredBps,
  );
  const manualRequired = scorePercentValue(assessment.scoringPolicy.manualRequiredBps);
  const reviewComplete = finalScore?.passed === true;
  const intro = reviewComplete
    ? `Ниже — результаты тестирования. Система автоматически проверила ${automaticQuestions.label.toLocaleLowerCase("ru-RU")}, а преподаватель проверил ${manualQuestions.label.toLocaleLowerCase("ru-RU")}. Экзамен сдан: ${formattedScorePercent(finalScore.overall.percentBps)}.`
    : `Ниже — результаты тестирования. Они складываются из двух частей. Система автоматически проверяет ${automaticQuestions.label.toLocaleLowerCase("ru-RU")}, и ${automaticQuestions.questionCount === 1 ? "его результат уже показан" : "их результаты уже показаны"} на экране. Преподаватель индивидуально проверит ${manualQuestions.label.toLocaleLowerCase("ru-RU")}. Чтобы сдать экзамен, нужно набрать не менее ${finalRequired} баллов. Для автоматической части требуется не менее ${automaticRequired}% в целом и ${automaticSectionRequired}% в каждом автоматическом разделе; для ручной части — не менее ${manualRequired}%.`;
  const resultState = reviewComplete
    ? finalScore.mastered ? "mastered" : "passed"
    : "pending_review";

  return (
    <LessonAssessmentResultLayout
      actions={actions}
      ariaLive="polite"
      dataState={resultState}
      dataUi={reviewComplete ? "lk.learning.assessment" : "lk.learning.assessment.pendingReview"}
      details={(
        <LessonAssessmentAutomaticScoreSummary
          assessment={assessment}
          automaticGate={automaticGate}
        />
      )}
      feedback={feedback}
      intro={intro}
      followup={(
        <section
          className={`${styles.assessmentResultManual} mx-6 mt-6 grid gap-3 border-t border-[var(--color-border-subtle)] pt-5 sm:mx-8 lg:mx-0`}
          aria-labelledby="lesson-assessment-manual-review-title"
          data-ui="lk.learning.assessment.manualReview"
          data-state={reviewComplete ? "reviewed" : "pending"}
        >
          <div className={styles.assessmentResultHeadline}>
            <h3
              id="lesson-assessment-manual-review-title"
              className={`${styles.assessmentResultTitle} text-xl font-bold leading-tight ui-text-emphasis sm:text-2xl`}
            >
              {reviewComplete
                ? `${manualQuestions.label} ${manualQuestions.questionCount === 1 ? "проверено" : "проверены"} преподавателем`
                : `${manualQuestions.label} ${manualQuestions.questionCount === 1 ? "передано" : "переданы"} на ручную проверку`}
            </h3>
            <p
              className={`${styles.assessmentResultScore} ${styles.assessmentResultBrandText} font-bold tracking-tight tabular-nums`}
              aria-label={`По проверенным ручным ответам набрано ${earnedManualPoints} из ${manualPoints} баллов`}
              data-ui="lk.learning.assessment.manualReview.weight"
            >
              <span className={`${styles.assessmentResultBrandMetric} text-5xl leading-none sm:text-6xl`}>
                {earnedManualPoints}
              </span>
              <span className={`${styles.assessmentResultPercentSign} text-sm font-medium leading-tight sm:text-base`}>
                из {manualPoints}
              </span>
            </p>
          </div>
          <p
            className="ui-text-secondary max-w-2xl text-sm leading-6"
            data-ui="lk.learning.assessment.manualReview.progress"
          >
            <span className={styles.assessmentResultBrandText}>
              Преподаватель проверил: {reviewedManualQuestionCount} из {assessment.scoringPolicy.manualQuestionCount}.
            </span>{" "}
            {reviewComplete
              ? `Все развёрнутые ответы сохранены. Экзамен сдан: ${formattedScorePercent(finalScore.overall.percentBps)}.`
              : "Все развёрнутые ответы сохранены; после проверки появится окончательный результат экзамена."}
          </p>
        </section>
      )}
      metric={{
        ariaLabel: `Вклад автоматической части в итоговый результат: ${automaticPoints.earned} из ${automaticPoints.available} баллов`,
        dataUi: "lk.learning.assessment.automaticContribution",
        suffix: `из ${automaticPoints.available}`,
        value: automaticPoints.earned,
      }}
      percentBps={automaticGate.overall.percentBps}
      scorePassed={automaticGate.passed}
      titleLead={`${automaticQuestions.label} ${automaticQuestions.questionCount === 1 ? "проверено" : "проверены"} автоматически`}
      visualState={reviewComplete && finalScore.mastered ? "mastered" : "passed"}
    />
  );
}

export function LessonAssessmentAutomaticFailureResult({
  actions,
  assessment,
  automaticGate,
  feedback,
}: {
  readonly actions: ReactNode;
  readonly assessment: LessonAssessmentResultContext;
  readonly automaticGate: LessonAssessmentAutomaticGate;
  readonly feedback?: ReactNode;
}) {
  const overallRequired = formattedScorePercent(automaticGate.overall.requiredBps);
  const sectionRequiredBps = automaticGate.sections.length > 0
    ? Math.min(...automaticGate.sections.map((section) => section.requiredBps))
    : null;
  const sectionRequired = sectionRequiredBps === null ? null : formattedScorePercent(sectionRequiredBps);
  const requirements = sectionRequired === null
    ? `Автоматическая часть не достигла порога проверки: нужно не менее ${overallRequired} в целом. Для пересдачи нужно пройти экзамен целиком.`
    : `Автоматическая часть не достигла порогов проверки: нужно не менее ${overallRequired} в целом и ${sectionRequired} в каждом автоматическом разделе. Для пересдачи нужно пройти экзамен целиком.`;

  return (
    <LessonAssessmentResultLayout
      actions={actions}
      ariaLive="polite"
      dataState="automatic_failed"
      dataUi="lk.learning.assessment.automaticFailed"
      details={(
        <LessonAssessmentAutomaticScoreSummary
          assessment={assessment}
          automaticGate={automaticGate}
        />
      )}
      feedback={feedback}
      percentBps={automaticGate.overall.percentBps}
      requirements={requirements}
      scorePassed={false}
      titleLead="Автоматические задания не прошли проверку"
      visualState="failed"
    />
  );
}

export function LessonAssessmentResult({
  actions,
  assessment,
  feedback,
  score,
  manualResults,
  failureReason,
}: {
  readonly actions: ReactNode;
  readonly assessment: LessonAssessmentResultContext;
  readonly feedback?: ReactNode;
  readonly score: LessonAssessmentScore;
  readonly manualResults?: ReadonlyArray<{ readonly questionId: string; readonly scoreBps: number; readonly comment: string | null }>;
  readonly failureReason?: "manual_threshold_not_met" | "combined_threshold_not_met" | null;
}) {
  const state: LessonAssessmentResultState = score.mastered ? "mastered" : score.passed ? "passed" : "failed";
  const title = score.mastered
    ? "Все задания выполнены верно — отличный результат"
    : score.passed
      ? "Экзамен сдан — продолжайте в том же духе"
      : "Экзамен не сдан — попробуйте ещё раз";
  const [titleLead, titlePrompt] = title.split(" — ");
  const visibleQuestionNumberById = lessonAssessmentVisibleQuestionNumberById(
    lessonAssessmentNavigationItems(assessment.questions),
  );
  const { finalRequiredPercent, automaticRequired, automaticSectionRequired, manualRequired } = scoringPolicyThresholds(
    assessment.scoringPolicy,
  );

  return (
    <LessonAssessmentResultLayout
      actions={actions}
      dataState={state}
      dataUi="lk.learning.assessment"
      details={(
        <LessonAssessmentScoreSummary
          assessment={assessment}
          score={score}
          showOverall={false}
        />
      )}
      feedback={feedback}
      footerContent={manualResults && manualResults.length > 0 ? <dl className="grid gap-3"><dt className="text-sm font-semibold ui-text-emphasis">Ручная проверка</dt>{manualResults.map((result) => {
            const question = assessment.questions.find((question) => question.id === result.questionId);
            const questionNumber = visibleQuestionNumberById.get(result.questionId);
            return <div key={result.questionId} className="grid gap-1"><dt className="text-sm font-medium ui-text-emphasis">{questionNumber === undefined ? "Задание" : `Задание ${questionNumber}`}{question ? `: ${question.prompt}` : ""}</dt><dd className="ui-text-secondary text-sm">Оценка: {formattedScorePercent(result.scoreBps)}</dd>{result.comment ? <dd className="ui-text-secondary text-sm">Комментарий: {result.comment}</dd> : null}</div>;
          })}</dl> : null}
      percentBps={score.overall.percentBps}
      requirements={failureReason === "manual_threshold_not_met" ? `Ручная часть не достигла ${manualRequired}. Для пересдачи нужно пройти экзамен целиком.` : failureReason === "combined_threshold_not_met" ? `Итоговый результат не достиг ${finalRequiredPercent}. Для пересдачи нужно пройти экзамен целиком.` : `Автоматическая часть уже пройдена: не менее ${automaticRequired} в ней и ${automaticSectionRequired} в каждом её разделе. Для итогового прохождения нужны минимум ${manualRequired} ручной части и ${finalRequiredPercent} общего результата.`}
      scorePassed={score.passed}
      titleLead={titleLead ?? title}
      titlePrompt={titlePrompt ?? ""}
      visualState={state}
    />
  );
}
