import { LearningLessonNavigation } from "@/components/learning/LearningPathUi";
import { accountApiBaseUrl } from "@/lib/accountAuth";
import { loadLearningCourse } from "@/lib/learningApi";

type LessonSidebarProps = {
  readonly params: Promise<{ subjectSlug: string; courseSlug: string; lessonSlug: string }>;
};

export default async function LessonSidebar({ params }: LessonSidebarProps) {
  const { subjectSlug, courseSlug, lessonSlug } = await params;
  const path = `/lk/learning/${encodeURIComponent(subjectSlug)}/${encodeURIComponent(courseSlug)}/${encodeURIComponent(lessonSlug)}`;
  const state = await loadLearningCourse(accountApiBaseUrl(), path, subjectSlug, courseSlug);

  if (state.status !== "ready" || !state.data.lessons.some((lesson) => lesson.slug === lessonSlug)) {
    return null;
  }

  return (
    <LearningLessonNavigation
      activeSlug={lessonSlug}
      lessons={state.data.lessons}
      navigationScope={`lessons:${subjectSlug}:${courseSlug}`}
      variant="sidebar"
    />
  );
}
