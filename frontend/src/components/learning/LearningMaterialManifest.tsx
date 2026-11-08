import type { LearningLessonManifestMaterial } from "@/lib/learningApi";

type LearningMaterialManifestProps = {
  readonly materials: ReadonlyArray<LearningLessonManifestMaterial>;
};

const MATERIAL_KIND_LABELS: Readonly<Record<LearningLessonManifestMaterial["kind"], string>> = {
  video: "Видео",
  pdf: "PDF",
  notes: "Конспект",
  file: "Файл",
};

export default function LearningMaterialManifest({ materials }: LearningMaterialManifestProps) {
  return (
    <section
      className="grid gap-2"
      aria-labelledby="lk-learning-materials-title"
      data-ui="lk.learning.lessonMaterials"
      data-state={materials.length === 0 ? "empty" : "ready"}
    >
      <h2
        id="lk-learning-materials-title"
        className="sr-only"
      >
        Материалы
      </h2>
      {materials.length === 0 ? (
        <p className="mt-2 text-sm leading-6 ui-text-secondary" data-ui="lk.learning.lessonMaterials.empty">
          К этому уроку пока не прикреплены отдельные материалы. Если они появятся, вы увидите их здесь.
        </p>
      ) : (
        <ol
          className="grid gap-2"
          data-ui="lk.learning.lessonMaterials.list"
        >
          {materials.map((material, index) => (
            <li
              key={`${material.kind}-${material.title}-${index}`}
              className="flex min-w-0 flex-col gap-1 rounded-[var(--radius-inner)] bg-[var(--color-surface-subtle)] p-3 text-sm leading-6 ui-text-secondary sm:flex-row sm:items-center sm:justify-between"
              data-ui="lk.learning.lessonMaterials.row"
              data-kind={material.kind}
            >
              <span className="min-w-0 break-words font-semibold ui-text-primary">{material.title}</span>
              <span className="ui-status-pill">
                {MATERIAL_KIND_LABELS[material.kind]}
              </span>
            </li>
          ))}
        </ol>
      )}
    </section>
  );
}
