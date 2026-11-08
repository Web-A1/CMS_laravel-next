import type { Metadata } from "next";
import { notFound } from "next/navigation";

import { LessonAssessmentResultPreview } from "@/components/learning/LessonAssessmentResultPreview";
import { lessonAssessmentResultPreviewScenario } from "@/components/learning/lessonAssessmentResultPreviewScenarios";

export const metadata: Metadata = {
  title: "Предпросмотр результатов экзамена",
};

type LessonAssessmentResultPreviewPageProps = {
  readonly searchParams: Promise<Readonly<Record<string, string | ReadonlyArray<string> | undefined>>>;
};

export default async function LessonAssessmentResultPreviewPage({
  searchParams,
}: LessonAssessmentResultPreviewPageProps) {
  if (process.env.NODE_ENV !== "development") {
    notFound();
  }

  const parameters = await searchParams;
  const scenarioParameter = parameters.scenario;
  const scenario = lessonAssessmentResultPreviewScenario(
    typeof scenarioParameter === "string" ? scenarioParameter : undefined,
  );

  return (
    <main className="site-shell">
      <LessonAssessmentResultPreview scenario={scenario} />
    </main>
  );
}
