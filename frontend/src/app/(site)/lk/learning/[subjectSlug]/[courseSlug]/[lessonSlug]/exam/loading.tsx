export default function LearningAssessmentLoading() {
  return (
    <section
      className="grid gap-4"
      aria-busy="true"
      aria-label="Загрузка экзамена"
      data-ui="lk.learning.assessmentLoading"
    >
      <div className="h-8 w-48 animate-pulse rounded-[var(--radius-control)] ui-surface-neutral motion-reduce:animate-none" />
      <div className="h-5 w-64 max-w-full animate-pulse rounded-[var(--radius-control)] ui-surface-neutral motion-reduce:animate-none" />
      <div className="h-48 animate-pulse rounded-[var(--radius-surface)] ui-surface-neutral motion-reduce:animate-none" />
    </section>
  );
}
