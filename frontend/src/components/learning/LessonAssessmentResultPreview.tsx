"use client";

import { useRouter } from "next/navigation";

import { ButtonLink } from "@/components/button/Button";

import {
  LessonAssessmentResult,
} from "./LessonAssessmentResult";
import {
  LESSON_ASSESSMENT_RESULT_PREVIEW_SCENARIOS,
  LESSON_ASSESSMENT_RESULT_PREVIEW_SCORING_POLICY,
  LESSON_ASSESSMENT_RESULT_PREVIEW_SECTIONS,
  type LessonAssessmentResultPreviewScenario,
} from "./lessonAssessmentResultPreviewScenarios";

const PREVIEW_PATH = "/dev/lesson-assessment-results";

function previewActions(scenarioId: string) {
  return (
    <>
      <ButtonLink
        href={`${PREVIEW_PATH}?scenario=${scenarioId}&review=answers`}
        fullWidth={false}
        data-ui="dev.lessonAssessmentResult.reviewButton"
      >
        Посмотреть ответы
      </ButtonLink>
      <ButtonLink
        href={`${PREVIEW_PATH}?scenario=failed-all`}
        variant="secondary"
        fullWidth={false}
        data-ui="dev.lessonAssessmentResult.retakeButton"
      >
        Сдать экзамен ещё раз
      </ButtonLink>
      <ButtonLink
        href={PREVIEW_PATH}
        variant="secondary"
        fullWidth={false}
        data-ui="dev.lessonAssessmentResult.lessonLink"
      >
        Вернуться к уроку
      </ButtonLink>
    </>
  );
}

export function LessonAssessmentResultPreview({
  scenario,
}: {
  readonly scenario: LessonAssessmentResultPreviewScenario;
}) {
  const router = useRouter();
  const assessment = {
    scoringPolicy: LESSON_ASSESSMENT_RESULT_PREVIEW_SCORING_POLICY,
    sections: LESSON_ASSESSMENT_RESULT_PREVIEW_SECTIONS,
    questions: [],
  };

  return (
    <div className="page-content page-content--standalone">
      <div className="content-shell page-gutter grid gap-8" data-ui="dev.lessonAssessmentResult">
        <header className="grid gap-2">
          <p className="ui-text-secondary text-sm font-medium">Локальный QA-предпросмотр</p>
          <h1 className="text-3xl font-bold leading-tight ui-text-emphasis">Результаты экзамена</h1>
          <p className="ui-text-secondary max-w-3xl text-sm">
            Выберите состояние. Предпросмотр не отправляет запросы и не изменяет попытки или прогресс.
          </p>
        </header>

        <div className="grid gap-2 lg:hidden">
          <label className="text-sm font-semibold ui-text-emphasis" htmlFor="lesson-assessment-result-scenario">
            Сценарий результата
          </label>
          <select
            id="lesson-assessment-result-scenario"
            value={scenario.id}
            className="ui-input h-11 w-full rounded-[var(--radius-control)] border px-3 text-sm outline-none focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--color-brand)]"
            onChange={(event) => {
              router.replace(`${PREVIEW_PATH}?scenario=${event.target.value}`, { scroll: false });
            }}
            data-ui="dev.lessonAssessmentResult.scenarioSelect"
          >
            {LESSON_ASSESSMENT_RESULT_PREVIEW_SCENARIOS.map((item) => (
              <option key={item.id} value={item.id}>
                {item.label}
              </option>
            ))}
          </select>
        </div>

        <nav className="hidden flex-wrap gap-2 lg:flex" aria-label="Сценарии результата">
          {LESSON_ASSESSMENT_RESULT_PREVIEW_SCENARIOS.map((item) => (
            <ButtonLink
              key={item.id}
              href={`${PREVIEW_PATH}?scenario=${item.id}`}
              fullWidth={false}
              variant={item.id === scenario.id ? "primary" : "secondary"}
              aria-current={item.id === scenario.id ? "page" : undefined}
              data-ui="dev.lessonAssessmentResult.scenarioLink"
              data-state={item.id === scenario.id ? "active" : "idle"}
            >
              {item.label}
            </ButtonLink>
          ))}
        </nav>

        <section className="grid gap-4" aria-label={scenario.label}>
          <LessonAssessmentResult
            actions={previewActions(scenario.id)}
            assessment={assessment}
            score={scenario.score}
          />
        </section>

      </div>
    </div>
  );
}
