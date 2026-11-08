"use client";

import type { LessonAssessmentCallout } from "@/lib/lessonAssessmentContract";

import type { DiagramCalibrationMarker } from "./lessonAssessmentDiagramCalibration";
import { diagramCalloutTargetId } from "./lessonAssessmentDiagramGeometry";
import styles from "./lesson-assessment.module.css";

type CalibrationPart = {
  readonly id: string;
  readonly prompt: string;
};

type LessonAssessmentDiagramCalibrationPanelProps = {
  readonly answerTerms: Readonly<Record<string, string>>;
  readonly callouts: ReadonlyArray<LessonAssessmentCallout>;
  readonly exportText: string;
  readonly fixedAt: string | null;
  readonly hasChanges: boolean;
  readonly height: number;
  readonly isExportOpen: boolean;
  readonly isOpen: boolean;
  readonly onAlignField: () => void;
  readonly onFix: () => void;
  readonly onMove: (x: number, y: number) => void;
  readonly onOpenChange: (open: boolean) => void;
  readonly onReset: () => void;
  readonly onSelectTarget: (targetId: string) => void;
  readonly onSetMarker: (marker: DiagramCalibrationMarker) => void;
  readonly onStepChange: (step: 1 | 5 | 20) => void;
  readonly onToggleExport: () => void;
  readonly parts: ReadonlyArray<CalibrationPart>;
  readonly questionId: string;
  readonly selectedTargetId: string;
  readonly step: 1 | 5 | 20;
  readonly width: number;
};

const STEPS = [1, 5, 20] as const;

export function LessonAssessmentDiagramCalibrationPanel({
  answerTerms,
  callouts,
  exportText,
  fixedAt,
  hasChanges,
  height,
  isExportOpen,
  isOpen,
  onAlignField,
  onFix,
  onMove,
  onOpenChange,
  onReset,
  onSelectTarget,
  onSetMarker,
  onStepChange,
  onToggleExport,
  parts,
  questionId,
  selectedTargetId,
  step,
  width,
}: LessonAssessmentDiagramCalibrationPanelProps) {
  const partById = new Map(parts.map((part) => [part.id, part] as const));
  const partNumberById = new Map(
    parts
      .filter((part) => part.id !== "name")
      .map((part, index) => [part.id, index + 1] as const),
  );
  const partTargetCount = new Map<string, number>();
  const partTargetIndex = new Map<string, number>();
  for (const callout of callouts) {
    partTargetCount.set(callout.partId, (partTargetCount.get(callout.partId) ?? 0) + 1);
  }

  const selectedCallout = callouts.find(
    (callout) => diagramCalloutTargetId(callout) === selectedTargetId,
  ) ?? callouts[0];
  const selectedMarker = selectedCallout?.targetMarker ?? "point";
  const selectedX = selectedCallout === undefined ? 0 : Math.round(selectedCallout.x * width);
  const selectedY = selectedCallout === undefined ? 0 : Math.round(selectedCallout.y * height);
  const status = fixedAt !== null
    ? "Положение зафиксировано"
    : hasChanges
      ? "Изменено — зафиксируйте"
      : "Исходное положение";

  if (!isOpen) {
    return (
      <div className={styles.diagramCalibrationLauncher}>
        <button
          type="button"
          className={styles.diagramCalibrationLauncherButton}
          onClick={() => onOpenChange(true)}
          data-ui={`lk.learning.assessment.question.${questionId}.diagram.calibration.openButton`}
        >
          Калибровка указателей{hasChanges ? " · есть изменения" : ""}
        </button>
      </div>
    );
  }

  return (
    <section
      className={styles.diagramCalibrationPanel}
      aria-label="Калибровка указателей схемы"
      data-ui={`lk.learning.assessment.question.${questionId}.diagram.calibration`}
    >
      <div className={styles.diagramCalibrationHeader}>
        <div>
          <p className={styles.diagramCalibrationTitle}>Калибровка указателей</p>
          <p className={styles.diagramCalibrationStatus} data-state={fixedAt !== null ? "fixed" : "draft"}>
            {status}. Черновик сохраняется в этом браузере.
          </p>
        </div>
        <button
          type="button"
          className={styles.diagramCalibrationQuietButton}
          onClick={() => onOpenChange(false)}
        >
          Свернуть
        </button>
      </div>

      <div className={styles.diagramCalibrationControls}>
        <label className={styles.diagramCalibrationSelectLabel}>
          <span>Указатель</span>
          <select
            className={styles.diagramCalibrationSelect}
            value={selectedTargetId}
            onChange={(event) => onSelectTarget(event.target.value)}
            data-ui={`lk.learning.assessment.question.${questionId}.diagram.calibration.targetSelect`}
          >
            {callouts.map((callout) => {
              const targetIndex = (partTargetIndex.get(callout.partId) ?? 0) + 1;
              partTargetIndex.set(callout.partId, targetIndex);
              const targetCount = partTargetCount.get(callout.partId) ?? 1;
              const partNumber = partNumberById.get(callout.partId);
              const partLabel = callout.partId === "name"
                ? "Название схемы"
                : partNumber === undefined
                  ? partById.get(callout.partId)?.prompt ?? callout.partId
                  : `Подпись ${partNumber}`;
              const answerTerm = answerTerms[callout.partId];
              return (
                <option key={diagramCalloutTargetId(callout)} value={diagramCalloutTargetId(callout)}>
                  {partLabel}{answerTerm === undefined ? "" : ` — ${answerTerm}`}
                  {targetCount > 1 ? ` · цель ${targetIndex}/${targetCount}` : ""}
                </option>
              );
            })}
          </select>
        </label>

        <fieldset className={styles.diagramCalibrationGroup}>
          <legend>Вид</legend>
          <div className={styles.diagramCalibrationSegmented}>
            {(["point", "arrow"] as const).map((marker) => (
              <button
                key={marker}
                type="button"
                className={styles.diagramCalibrationControlButton}
                data-active={selectedMarker === marker ? true : undefined}
                aria-pressed={selectedMarker === marker}
                onClick={() => onSetMarker(marker)}
              >
                {marker === "point" ? "Точка" : "Стрелка"}
              </button>
            ))}
          </div>
        </fieldset>

        <fieldset className={styles.diagramCalibrationGroup}>
          <legend>Шаг</legend>
          <div className={styles.diagramCalibrationSegmented}>
            {STEPS.map((candidate) => (
              <button
                key={candidate}
                type="button"
                className={styles.diagramCalibrationControlButton}
                data-active={step === candidate ? true : undefined}
                aria-pressed={step === candidate}
                onClick={() => onStepChange(candidate)}
              >
                {candidate} px
              </button>
            ))}
          </div>
        </fieldset>

        <fieldset className={styles.diagramCalibrationGroup}>
          <legend>Сдвиг</legend>
          <div className={styles.diagramCalibrationSegmented}>
            <button type="button" className={styles.diagramCalibrationDirectionButton} aria-label="Сдвинуть влево" onClick={() => onMove(-1, 0)}>←</button>
            <button type="button" className={styles.diagramCalibrationDirectionButton} aria-label="Сдвинуть вверх" onClick={() => onMove(0, -1)}>↑</button>
            <button type="button" className={styles.diagramCalibrationDirectionButton} aria-label="Сдвинуть вниз" onClick={() => onMove(0, 1)}>↓</button>
            <button type="button" className={styles.diagramCalibrationDirectionButton} aria-label="Сдвинуть вправо" onClick={() => onMove(1, 0)}>→</button>
          </div>
        </fieldset>

        <output className={styles.diagramCalibrationCoordinates}>
          x {selectedX} px · y {selectedY} px
        </output>
      </div>

      <div className={styles.diagramCalibrationActions}>
        <button type="button" className={styles.diagramCalibrationQuietButton} onClick={onAlignField}>
          Выровнять поле по цели
        </button>
        <button type="button" className={styles.diagramCalibrationPrimaryButton} onClick={onFix}>
          Зафиксировать
        </button>
        <button type="button" className={styles.diagramCalibrationQuietButton} onClick={onToggleExport}>
          {isExportOpen ? "Скрыть экспорт" : "Экспортировать настройки"}
        </button>
        <button
          type="button"
          className={styles.diagramCalibrationQuietButton}
          onClick={() => {
            if (window.confirm("Сбросить все изменения указателей только на этой схеме?")) {
              onReset();
            }
          }}
        >
          Сбросить схему
        </button>
      </div>

      {isExportOpen ? (
        <textarea
          className={styles.diagramCalibrationExport}
          value={exportText}
          readOnly
          rows={8}
          aria-label="Настройки калибровки"
          onFocus={(event) => event.currentTarget.select()}
        />
      ) : null}
    </section>
  );
}
