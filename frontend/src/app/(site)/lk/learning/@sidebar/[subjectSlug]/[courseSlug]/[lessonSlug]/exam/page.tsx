import { LessonAssessmentSidebar } from "@/components/learning/LessonAssessmentSidebar";
import { accountApiBaseUrl } from "@/lib/accountAuth";
import { loadLessonAssessment } from "@/lib/lessonAssessmentServer";

type LessonAssessmentSidebarPageProps = {
  readonly params: Promise<{ subjectSlug: string; courseSlug: string; lessonSlug: string }>;
  readonly searchParams: Promise<Readonly<Record<string, string | ReadonlyArray<string> | undefined>>>;
};

export default async function LessonAssessmentSidebarPage({
  params,
  searchParams,
}: LessonAssessmentSidebarPageProps) {
  const { subjectSlug, courseSlug, lessonSlug } = await params;
  const route = { subjectSlug, courseSlug, lessonSlug };
  const examPath = `/lk/learning/${encodeURIComponent(subjectSlug)}/${encodeURIComponent(courseSlug)}/${encodeURIComponent(lessonSlug)}/exam`;
  const state = await loadLessonAssessment(accountApiBaseUrl(), examPath, route);

  if (state.status !== "ready") {
    return null;
  }
  const parameters = await searchParams;
  const reviewParameter = parameters.review;
  const submittedAttempt = state.data.attempt !== null && state.data.attempt.status !== "in_progress";
  const reviewMode = submittedAttempt && reviewParameter === "answers";

  const questions = state.data.assessment.questions.map((question) => ({
    id: question.id,
    prompt: question.prompt,
    gradingMode: question.gradingMode,
    sectionId: question.sectionId,
    type: question.type,
  }));
  const questionIds = questions.map((question) => question.id);
  const lastQuestionId = state.data.attempt?.lastQuestionId ?? null;
  const initialQuestionId =
    lastQuestionId !== null && questionIds.includes(lastQuestionId) ? lastQuestionId : (questionIds[0] ?? null);

  return (
    <LessonAssessmentSidebar
      initialQuestionId={initialQuestionId}
      lockedQuestionIds={state.data.attempt?.lockedQuestionIds ?? []}
      questionResults={state.data.attempt !== null && "automaticGate" in state.data.attempt
        ? state.data.attempt.automaticGate.questionResults
        : []}
      questions={questions}
      reviewedManualQuestionIds={state.data.attempt?.reviewedManualQuestionIds ?? []}
      reviewMode={reviewMode}
      submittedAttempt={submittedAttempt}
    />
  );
}
